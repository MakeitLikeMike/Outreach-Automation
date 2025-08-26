<?php
/**
 * Enhanced Error Handler with Retry Logic for GMass + IMAP System
 * Handles errors gracefully, implements retry logic, and provides error recovery
 */
require_once __DIR__ . '/../config/database.php';

class ErrorHandler {
    private $db;
    private $logFile;
    private $retryConfig = [];
    private $circuitBreakers = [];
    
    const ERROR_LEVEL_INFO = 'info';
    const ERROR_LEVEL_WARNING = 'warning';
    const ERROR_LEVEL_ERROR = 'error';
    const ERROR_LEVEL_CRITICAL = 'critical';
    
    const RETRY_STRATEGY_LINEAR = 'linear';
    const RETRY_STRATEGY_EXPONENTIAL = 'exponential';
    const RETRY_STRATEGY_FIBONACCI = 'fibonacci';
    
    public function __construct() {
        $this->db = new Database();
        $this->logFile = __DIR__ . '/../logs/error_handler.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $this->initializeRetryConfig();
        $this->createErrorLogTable();
        
        // Register as error and exception handler
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
    }
    
    /**
     * Execute operation with retry logic and error handling
     */
    public function executeWithRetry($operation, $operationType = 'default', $maxRetries = null, $context = []) {
        $config = $this->retryConfig[$operationType] ?? $this->retryConfig['default'];
        $maxRetries = $maxRetries ?? $config['max_retries'];
        
        $operationId = uniqid($operationType . '_', true);
        $this->logError(self::ERROR_LEVEL_INFO, "Starting operation: {$operationType}", $operationId, $context);
        
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxRetries) {
            try {
                // Check circuit breaker
                if ($this->isCircuitBreakerOpen($operationType)) {
                    throw new Exception("Circuit breaker is OPEN for operation type: {$operationType}");
                }
                
                $startTime = microtime(true);
                $result = $operation();
                $duration = microtime(true) - $startTime;
                
                // Success - reset circuit breaker and log
                $this->recordCircuitBreakerSuccess($operationType);
                $this->logError(self::ERROR_LEVEL_INFO, "Operation succeeded on attempt " . ($attempt + 1), $operationId, [
                    'duration' => round($duration, 3),
                    'attempts' => $attempt + 1
                ]);
                
                return $result;
                
            } catch (Exception $e) {
                $attempt++;
                $lastException = $e;
                
                // Record failure for circuit breaker
                $this->recordCircuitBreakerFailure($operationType);
                
                $this->logError(self::ERROR_LEVEL_WARNING, "Operation failed on attempt {$attempt}: " . $e->getMessage(), $operationId, [
                    'exception_type' => get_class($e),
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries
                ]);
                
                // Don't retry on certain types of errors
                if ($this->shouldNotRetry($e, $operationType)) {
                    $this->logError(self::ERROR_LEVEL_ERROR, "Operation marked as non-retryable", $operationId, [
                        'reason' => 'error_type_not_retryable',
                        'error_class' => get_class($e)
                    ]);
                    break;
                }
                
                // Wait before retry (except on last attempt)
                if ($attempt < $maxRetries) {
                    $delay = $this->calculateRetryDelay($attempt, $config['strategy'], $config['base_delay']);
                    $this->logError(self::ERROR_LEVEL_INFO, "Waiting {$delay}s before retry", $operationId);
                    
                    if ($delay > 0) {
                        sleep($delay);
                    }
                }
            }
        }
        
        // All retries exhausted
        $this->logError(self::ERROR_LEVEL_CRITICAL, "Operation failed after {$maxRetries} attempts", $operationId, [
            'final_error' => $lastException ? $lastException->getMessage() : 'unknown error'
        ]);
        
        // Throw the last exception
        if ($lastException) {
            throw $lastException;
        } else {
            throw new Exception("Operation failed after {$maxRetries} attempts");
        }
    }
    
    /**
     * Handle specific operation types with custom retry logic
     */
    public function handleGmassOperation($operation, $context = []) {
        return $this->executeWithRetry($operation, 'gmass_api', null, $context);
    }
    
    public function handleImapOperation($operation, $context = []) {
        return $this->executeWithRetry($operation, 'imap_connection', null, $context);
    }
    
    public function handleDatabaseOperation($operation, $context = []) {
        return $this->executeWithRetry($operation, 'database_query', null, $context);
    }
    
    /**
     * Handle PHP errors
     */
    public function handleError($severity, $message, $file, $line) {
        $errorLevel = $this->mapPhpErrorLevel($severity);
        $context = [
            'file' => $file,
            'line' => $line,
            'severity' => $severity,
            'php_error' => true
        ];
        
        $this->logError($errorLevel, $message, 'php_error_' . uniqid(), $context);
        
        // Don't stop execution for warnings and notices
        return !($severity & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR));
    }
    
    /**
     * Handle uncaught exceptions
     */
    public function handleException($exception) {
        $context = [
            'exception_type' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'stack_trace' => $exception->getTraceAsString()
        ];
        
        $this->logError(self::ERROR_LEVEL_CRITICAL, "Uncaught exception: " . $exception->getMessage(), 'uncaught_' . uniqid(), $context);
        
        // Try to send critical error notification
        $this->sendCriticalErrorNotification($exception);
    }
    
    /**
     * Calculate retry delay based on strategy
     */
    private function calculateRetryDelay($attempt, $strategy, $baseDelay) {
        switch ($strategy) {
            case self::RETRY_STRATEGY_LINEAR:
                return $baseDelay * $attempt;
                
            case self::RETRY_STRATEGY_EXPONENTIAL:
                return $baseDelay * (2 ** ($attempt - 1));
                
            case self::RETRY_STRATEGY_FIBONACCI:
                return $baseDelay * $this->fibonacci($attempt);
                
            default:
                return $baseDelay;
        }
    }
    
    /**
     * Fibonacci sequence for retry delays
     */
    private function fibonacci($n) {
        if ($n <= 1) return $n;
        return $this->fibonacci($n - 1) + $this->fibonacci($n - 2);
    }
    
    /**
     * Check if operation should not be retried
     */
    private function shouldNotRetry($exception, $operationType) {
        $nonRetryableErrors = [
            'gmass_api' => [
                'InvalidArgumentException',
                'AuthenticationException',
                'QuotaExceededException'
            ],
            'imap_connection' => [
                'InvalidArgumentException',
                'AuthenticationException'
            ],
            'database_query' => [
                'PDOException' // Some PDO exceptions shouldn't be retried
            ]
        ];
        
        $exceptionClass = get_class($exception);
        $nonRetryable = $nonRetryableErrors[$operationType] ?? [];
        
        // Check if this exception type should not be retried
        if (in_array($exceptionClass, $nonRetryable)) {
            return true;
        }
        
        // Check specific error messages that indicate non-retryable conditions
        $message = strtolower($exception->getMessage());
        $nonRetryableMessages = [
            'authentication failed',
            'invalid credentials',
            'permission denied',
            'quota exceeded',
            'rate limit exceeded permanently'
        ];
        
        foreach ($nonRetryableMessages as $nonRetryableMessage) {
            if (strpos($message, $nonRetryableMessage) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Circuit breaker implementation
     */
    private function isCircuitBreakerOpen($operationType) {
        if (!isset($this->circuitBreakers[$operationType])) {
            $this->circuitBreakers[$operationType] = [
                'failures' => 0,
                'last_failure' => 0,
                'state' => 'closed' // closed, open, half-open
            ];
        }
        
        $breaker = &$this->circuitBreakers[$operationType];
        $config = $this->retryConfig[$operationType] ?? $this->retryConfig['default'];
        
        if ($breaker['state'] === 'open') {
            // Check if we should try half-open
            if (time() - $breaker['last_failure'] > $config['circuit_breaker_timeout']) {
                $breaker['state'] = 'half-open';
                $this->logError(self::ERROR_LEVEL_INFO, "Circuit breaker moving to HALF-OPEN", $operationType);
                return false;
            }
            return true;
        }
        
        return false;
    }
    
    private function recordCircuitBreakerSuccess($operationType) {
        if (isset($this->circuitBreakers[$operationType])) {
            $this->circuitBreakers[$operationType]['failures'] = 0;
            $this->circuitBreakers[$operationType]['state'] = 'closed';
        }
    }
    
    private function recordCircuitBreakerFailure($operationType) {
        if (!isset($this->circuitBreakers[$operationType])) {
            $this->circuitBreakers[$operationType] = [
                'failures' => 0,
                'last_failure' => 0,
                'state' => 'closed'
            ];
        }
        
        $breaker = &$this->circuitBreakers[$operationType];
        $config = $this->retryConfig[$operationType] ?? $this->retryConfig['default'];
        
        $breaker['failures']++;
        $breaker['last_failure'] = time();
        
        if ($breaker['failures'] >= $config['circuit_breaker_threshold']) {
            $breaker['state'] = 'open';
            $this->logError(self::ERROR_LEVEL_WARNING, "Circuit breaker OPENED for operation type", $operationType, [
                'failures' => $breaker['failures'],
                'threshold' => $config['circuit_breaker_threshold']
            ]);
        }
    }
    
    /**
     * Get error statistics
     */
    public function getErrorStatistics($days = 7) {
        $stats = [
            'period_days' => $days,
            'total_errors' => 0,
            'errors_by_level' => [],
            'errors_by_operation' => [],
            'circuit_breaker_status' => $this->circuitBreakers,
            'recent_critical_errors' => []
        ];
        
        try {
            // Total errors
            $totalResult = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM error_log WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );
            $stats['total_errors'] = $totalResult['count'] ?? 0;
            
            // Errors by level
            $stats['errors_by_level'] = $this->db->fetchAll(
                "SELECT error_level, COUNT(*) as count 
                 FROM error_log 
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY error_level
                 ORDER BY count DESC",
                [$days]
            );
            
            // Errors by operation
            $stats['errors_by_operation'] = $this->db->fetchAll(
                "SELECT operation_id, COUNT(*) as count,
                        MAX(created_at) as last_occurrence
                 FROM error_log 
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY SUBSTRING_INDEX(operation_id, '_', 1)
                 ORDER BY count DESC
                 LIMIT 10",
                [$days]
            );
            
            // Recent critical errors
            $stats['recent_critical_errors'] = $this->db->fetchAll(
                "SELECT * FROM error_log 
                 WHERE error_level = 'critical' 
                 AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 ORDER BY created_at DESC 
                 LIMIT 5",
                [$days]
            );
            
        } catch (Exception $e) {
            $this->logError(self::ERROR_LEVEL_ERROR, "Failed to get error statistics: " . $e->getMessage(), 'stats_error');
        }
        
        return $stats;
    }
    
    /**
     * Initialize retry configuration for different operation types
     */
    private function initializeRetryConfig() {
        $this->retryConfig = [
            'default' => [
                'max_retries' => 3,
                'base_delay' => 1,
                'strategy' => self::RETRY_STRATEGY_EXPONENTIAL,
                'circuit_breaker_threshold' => 5,
                'circuit_breaker_timeout' => 300 // 5 minutes
            ],
            'gmass_api' => [
                'max_retries' => 5,
                'base_delay' => 2,
                'strategy' => self::RETRY_STRATEGY_EXPONENTIAL,
                'circuit_breaker_threshold' => 10,
                'circuit_breaker_timeout' => 600 // 10 minutes
            ],
            'imap_connection' => [
                'max_retries' => 3,
                'base_delay' => 3,
                'strategy' => self::RETRY_STRATEGY_LINEAR,
                'circuit_breaker_threshold' => 5,
                'circuit_breaker_timeout' => 300
            ],
            'database_query' => [
                'max_retries' => 2,
                'base_delay' => 1,
                'strategy' => self::RETRY_STRATEGY_LINEAR,
                'circuit_breaker_threshold' => 3,
                'circuit_breaker_timeout' => 180
            ]
        ];
    }
    
    /**
     * Map PHP error levels to our error levels
     */
    private function mapPhpErrorLevel($phpLevel) {
        switch ($phpLevel) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                return self::ERROR_LEVEL_CRITICAL;
                
            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                return self::ERROR_LEVEL_WARNING;
                
            case E_NOTICE:
            case E_USER_NOTICE:
            case E_STRICT:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return self::ERROR_LEVEL_INFO;
                
            default:
                return self::ERROR_LEVEL_ERROR;
        }
    }
    
    /**
     * Send critical error notification
     */
    private function sendCriticalErrorNotification($exception) {
        try {
            // This would integrate with your notification system
            // For now, just log it prominently
            $this->logError(self::ERROR_LEVEL_CRITICAL, "CRITICAL ERROR NOTIFICATION: " . $exception->getMessage(), 'notification', [
                'timestamp' => date('Y-m-d H:i:s'),
                'server' => $_SERVER['SERVER_NAME'] ?? 'unknown',
                'script' => $_SERVER['SCRIPT_NAME'] ?? 'unknown'
            ]);
            
        } catch (Exception $e) {
            // If notification fails, at least log that
            error_log("Failed to send critical error notification: " . $e->getMessage());
        }
    }
    
    /**
     * Create error log table
     */
    private function createErrorLogTable() {
        $sql = "CREATE TABLE IF NOT EXISTS error_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            error_level ENUM('info', 'warning', 'error', 'critical') NOT NULL,
            message TEXT NOT NULL,
            operation_id VARCHAR(255),
            context JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_error_level (error_level),
            INDEX idx_operation_id (operation_id),
            INDEX idx_created_at (created_at)
        )";
        
        try {
            $this->db->execute($sql);
        } catch (Exception $e) {
            // If we can't create the table, at least log to file
            error_log("Failed to create error_log table: " . $e->getMessage());
        }
    }
    
    /**
     * Log error to both file and database
     */
    private function logError($level, $message, $operationId = null, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        
        // File logging (always works)
        $logEntry = "[{$timestamp}] [{$level}] {$message}";
        if ($operationId) {
            $logEntry .= " [Op: {$operationId}]";
        }
        if (!empty($context)) {
            $logEntry .= " " . json_encode($context);
        }
        $logEntry .= "\n";
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Database logging (fallback to file if fails)
        try {
            $this->db->execute(
                "INSERT INTO error_log (error_level, message, operation_id, context) VALUES (?, ?, ?, ?)",
                [$level, $message, $operationId, json_encode($context)]
            );
        } catch (Exception $e) {
            // Database logging failed, already logged to file
        }
    }
    
    /**
     * Clean up old error logs
     */
    public function cleanupOldLogs($days = 30) {
        try {
            $deleted = $this->db->execute(
                "DELETE FROM error_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );
            
            $this->logError(self::ERROR_LEVEL_INFO, "Cleaned up {$deleted} old error log entries", 'cleanup');
            return $deleted;
            
        } catch (Exception $e) {
            $this->logError(self::ERROR_LEVEL_WARNING, "Failed to cleanup old logs: " . $e->getMessage(), 'cleanup_failed');
            return false;
        }
    }
}
?>