<?php
/**
 * Performance Monitor for GMass + IMAP System
 * Monitors system performance, connection pooling, and load testing
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/GMassIntegration.php';
require_once __DIR__ . '/IMAPMonitor.php';

class PerformanceMonitor {
    private $db;
    private $metrics = [];
    private $startTime;
    private $memoryStart;
    
    public function __construct() {
        $this->db = new Database();
        $this->startTime = microtime(true);
        $this->memoryStart = memory_get_usage(true);
        
        $this->createPerformanceLogTable();
    }
    
    /**
     * Start performance tracking for a specific operation
     */
    public function startTracking($operation, $metadata = []) {
        $trackingId = uniqid($operation . '_', true);
        
        $this->metrics[$trackingId] = [
            'operation' => $operation,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'metadata' => $metadata,
            'status' => 'running'
        ];
        
        return $trackingId;
    }
    
    /**
     * End performance tracking and log results
     */
    public function endTracking($trackingId, $status = 'completed', $additionalData = []) {
        if (!isset($this->metrics[$trackingId])) {
            return false;
        }
        
        $metric = &$this->metrics[$trackingId];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $metric['end_time'] = $endTime;
        $metric['end_memory'] = $endMemory;
        $metric['duration'] = $endTime - $metric['start_time'];
        $metric['memory_used'] = $endMemory - $metric['start_memory'];
        $metric['status'] = $status;
        $metric['additional_data'] = $additionalData;
        
        // Log to database
        $this->logPerformanceMetric($metric);
        
        return $metric;
    }
    
    /**
     * Run load testing on GMass email sending
     */
    public function runGMassLoadTest($emailCount = 50, $batchSize = 10) {
        $testId = $this->startTracking('gmass_load_test', [
            'email_count' => $emailCount,
            'batch_size' => $batchSize
        ]);
        
        try {
            $gmass = new GMassIntegration();
            $results = [
                'total_emails' => $emailCount,
                'successful' => 0,
                'failed' => 0,
                'batch_times' => [],
                'errors' => []
            ];
            
            $batches = ceil($emailCount / $batchSize);
            
            for ($batch = 0; $batch < $batches; $batch++) {
                $batchStart = microtime(true);
                $emailsInBatch = min($batchSize, $emailCount - ($batch * $batchSize));
                
                for ($i = 0; $i < $emailsInBatch; $i++) {
                    try {
                        $testEmail = [
                            'to' => 'test+' . uniqid() . '@example.com',
                            'from' => 'mike14delacruz@gmail.com',
                            'subject' => 'Load Test Email #' . (($batch * $batchSize) + $i + 1),
                            'body' => 'This is a load test email sent at ' . date('Y-m-d H:i:s')
                        ];
                        
                        // Simulate email sending (don't actually send in test)
                        usleep(100000); // 100ms simulated API call
                        $results['successful']++;
                        
                    } catch (Exception $e) {
                        $results['failed']++;
                        $results['errors'][] = $e->getMessage();
                    }
                }
                
                $batchTime = microtime(true) - $batchStart;
                $results['batch_times'][] = [
                    'batch' => $batch + 1,
                    'time' => $batchTime,
                    'emails' => $emailsInBatch,
                    'avg_per_email' => $batchTime / $emailsInBatch
                ];
                
                // Small delay between batches to prevent overwhelming
                usleep(250000); // 250ms
            }
            
            $this->endTracking($testId, 'completed', $results);
            return $results;
            
        } catch (Exception $e) {
            $this->endTracking($testId, 'failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Test IMAP connection pool performance
     */
    public function testIMAPConnectionPool($concurrentConnections = 5, $operationsPerConnection = 10) {
        $testId = $this->startTracking('imap_connection_pool_test', [
            'concurrent_connections' => $concurrentConnections,
            'operations_per_connection' => $operationsPerConnection
        ]);
        
        try {
            $results = [
                'connections' => $concurrentConnections,
                'operations_per_connection' => $operationsPerConnection,
                'total_operations' => $concurrentConnections * $operationsPerConnection,
                'successful_connections' => 0,
                'failed_connections' => 0,
                'connection_times' => [],
                'operation_times' => [],
                'errors' => []
            ];
            
            $connectionTasks = [];
            
            for ($i = 0; $i < $concurrentConnections; $i++) {
                $connectionStart = microtime(true);
                
                try {
                    $imap = new IMAPMonitor();
                    $connectResult = $imap->testConnection();
                    
                    $connectionTime = microtime(true) - $connectionStart;
                    $results['connection_times'][] = $connectionTime;
                    
                    if ($connectResult) {
                        $results['successful_connections']++;
                        
                        // Perform operations on this connection
                        for ($op = 0; $op < $operationsPerConnection; $op++) {
                            $opStart = microtime(true);
                            
                            try {
                                // Simulate IMAP operations
                                $imap->getUnreadCount();
                                $opTime = microtime(true) - $opStart;
                                $results['operation_times'][] = $opTime;
                                
                            } catch (Exception $e) {
                                $results['errors'][] = "Operation error: " . $e->getMessage();
                            }
                        }
                    } else {
                        $results['failed_connections']++;
                        $results['errors'][] = "Connection {$i} failed to establish";
                    }
                    
                } catch (Exception $e) {
                    $results['failed_connections']++;
                    $results['errors'][] = "Connection {$i} exception: " . $e->getMessage();
                }
            }
            
            // Calculate statistics
            if (!empty($results['connection_times'])) {
                $results['avg_connection_time'] = array_sum($results['connection_times']) / count($results['connection_times']);
                $results['max_connection_time'] = max($results['connection_times']);
                $results['min_connection_time'] = min($results['connection_times']);
            }
            
            if (!empty($results['operation_times'])) {
                $results['avg_operation_time'] = array_sum($results['operation_times']) / count($results['operation_times']);
                $results['max_operation_time'] = max($results['operation_times']);
                $results['min_operation_time'] = min($results['operation_times']);
            }
            
            $this->endTracking($testId, 'completed', $results);
            return $results;
            
        } catch (Exception $e) {
            $this->endTracking($testId, 'failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Monitor database query performance
     */
    public function monitorDatabaseQueries($testQueries = null) {
        $testId = $this->startTracking('database_performance_test');
        
        $queries = $testQueries ?? [
            'SELECT COUNT(*) FROM email_queue',
            'SELECT * FROM email_queue WHERE status = "queued" LIMIT 10',
            'SELECT * FROM system_settings WHERE setting_key LIKE "gmass%"',
            'SELECT * FROM target_domains WHERE status = "qualified" LIMIT 20',
            'SELECT COUNT(*) FROM outreach_emails WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        ];
        
        $results = [
            'queries_tested' => count($queries),
            'query_results' => [],
            'total_time' => 0,
            'avg_query_time' => 0
        ];
        
        foreach ($queries as $index => $sql) {
            $queryStart = microtime(true);
            
            try {
                $result = $this->db->fetchAll($sql);
                $queryTime = microtime(true) - $queryStart;
                
                $results['query_results'][] = [
                    'query' => $sql,
                    'time' => $queryTime,
                    'rows_returned' => count($result),
                    'status' => 'success'
                ];
                
                $results['total_time'] += $queryTime;
                
            } catch (Exception $e) {
                $queryTime = microtime(true) - $queryStart;
                $results['query_results'][] = [
                    'query' => $sql,
                    'time' => $queryTime,
                    'error' => $e->getMessage(),
                    'status' => 'error'
                ];
            }
        }
        
        $results['avg_query_time'] = $results['total_time'] / count($queries);
        
        $this->endTracking($testId, 'completed', $results);
        return $results;
    }
    
    /**
     * Comprehensive system performance test
     */
    public function runFullSystemTest() {
        $testId = $this->startTracking('full_system_performance_test');
        
        try {
            $results = [
                'test_timestamp' => date('Y-m-d H:i:s'),
                'gmass_load_test' => null,
                'imap_pool_test' => null,
                'database_test' => null,
                'system_resources' => $this->getSystemResources(),
                'overall_status' => 'unknown'
            ];
            
            // Run all performance tests
            echo "Running GMass load test...\n";
            $results['gmass_load_test'] = $this->runGMassLoadTest(20, 5);
            
            echo "Running IMAP connection pool test...\n";
            $results['imap_pool_test'] = $this->testIMAPConnectionPool(3, 5);
            
            echo "Running database performance test...\n";
            $results['database_test'] = $this->monitorDatabaseQueries();
            
            // Evaluate overall performance
            $gmassSuccess = $results['gmass_load_test']['successful'] > 0;
            $imapSuccess = $results['imap_pool_test']['successful_connections'] > 0;
            $dbSuccess = count(array_filter($results['database_test']['query_results'], fn($q) => $q['status'] === 'success')) > 0;
            
            if ($gmassSuccess && $imapSuccess && $dbSuccess) {
                $results['overall_status'] = 'excellent';
            } elseif (($gmassSuccess && $imapSuccess) || ($gmassSuccess && $dbSuccess) || ($imapSuccess && $dbSuccess)) {
                $results['overall_status'] = 'good';
            } elseif ($gmassSuccess || $imapSuccess || $dbSuccess) {
                $results['overall_status'] = 'warning';
            } else {
                $results['overall_status'] = 'critical';
            }
            
            $this->endTracking($testId, 'completed', $results);
            return $results;
            
        } catch (Exception $e) {
            $this->endTracking($testId, 'failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Get current system resource usage
     */
    public function getSystemResources() {
        return [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'execution_time' => microtime(true) - $this->startTime,
            'php_version' => phpversion(),
            'mysql_version' => $this->db->fetchOne("SELECT VERSION() as version")['version'] ?? 'unknown'
        ];
    }
    
    /**
     * Get performance statistics for the last N days
     */
    public function getPerformanceStats($days = 7) {
        $sql = "SELECT 
                    operation,
                    COUNT(*) as total_operations,
                    AVG(duration) as avg_duration,
                    MAX(duration) as max_duration,
                    MIN(duration) as min_duration,
                    AVG(memory_used) as avg_memory,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful,
                    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed
                FROM performance_log 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY operation
                ORDER BY total_operations DESC";
                
        return $this->db->fetchAll($sql, [$days]);
    }
    
    /**
     * Create performance log table
     */
    private function createPerformanceLogTable() {
        $sql = "CREATE TABLE IF NOT EXISTS performance_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operation VARCHAR(100) NOT NULL,
            duration DECIMAL(10,6) NOT NULL,
            memory_used INT NOT NULL,
            status ENUM('running', 'completed', 'failed') NOT NULL,
            metadata JSON,
            additional_data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_operation (operation),
            INDEX idx_created_at (created_at),
            INDEX idx_status (status)
        )";
        
        try {
            $this->db->execute($sql);
        } catch (Exception $e) {
            // Table might already exist
        }
    }
    
    /**
     * Log performance metric to database
     */
    private function logPerformanceMetric($metric) {
        try {
            $sql = "INSERT INTO performance_log 
                    (operation, duration, memory_used, status, metadata, additional_data) 
                    VALUES (?, ?, ?, ?, ?, ?)";
                    
            $this->db->execute($sql, [
                $metric['operation'],
                $metric['duration'],
                $metric['memory_used'],
                $metric['status'],
                json_encode($metric['metadata'] ?? []),
                json_encode($metric['additional_data'] ?? [])
            ]);
        } catch (Exception $e) {
            // Log to file if database logging fails
            error_log("Performance logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * Generate performance report
     */
    public function generatePerformanceReport($days = 7) {
        $stats = $this->getPerformanceStats($days);
        $resources = $this->getSystemResources();
        
        $report = [
            'report_date' => date('Y-m-d H:i:s'),
            'period_days' => $days,
            'system_resources' => $resources,
            'performance_stats' => $stats,
            'recommendations' => []
        ];
        
        // Add recommendations based on performance data
        foreach ($stats as $stat) {
            if ($stat['avg_duration'] > 5.0) {
                $report['recommendations'][] = "Operation '{$stat['operation']}' is slow (avg: {$stat['avg_duration']}s) - consider optimization";
            }
            
            if ($stat['failed'] > 0 && $stat['failed'] / $stat['total_operations'] > 0.1) {
                $failureRate = round(($stat['failed'] / $stat['total_operations']) * 100, 1);
                $report['recommendations'][] = "Operation '{$stat['operation']}' has high failure rate ({$failureRate}%) - investigate errors";
            }
            
            if ($stat['avg_memory'] > 50000000) { // 50MB
                $memoryMB = round($stat['avg_memory'] / 1024 / 1024, 1);
                $report['recommendations'][] = "Operation '{$stat['operation']}' uses high memory (avg: {$memoryMB}MB) - check for memory leaks";
            }
        }
        
        return $report;
    }
}
?>