<?php
/**
 * Security Validator for GMass + IMAP System
 * Validates IMAP credentials, performs security checks, and monitors access
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SecureCredentialManager.php';
require_once __DIR__ . '/IMAPMonitor.php';

class SecurityValidator {
    private $db;
    private $credentialManager;
    private $logFile;
    private $securityRules = [];
    
    const SECURITY_LEVEL_LOW = 1;
    const SECURITY_LEVEL_MEDIUM = 2;
    const SECURITY_LEVEL_HIGH = 3;
    const SECURITY_LEVEL_CRITICAL = 4;
    
    const MAX_FAILED_ATTEMPTS = 5;
    const LOCKOUT_DURATION = 1800; // 30 minutes
    
    public function __construct() {
        $this->db = new Database();
        $this->credentialManager = new SecureCredentialManager();
        $this->logFile = __DIR__ . '/../logs/security_validation.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $this->initializeSecurityRules();
        $this->createSecurityTables();
    }
    
    /**
     * Comprehensive IMAP credentials validation
     */
    public function validateImapCredentials($email, $password, $host = 'imap.gmail.com', $port = 993) {
        $validationId = uniqid('imap_validation_', true);
        $this->logSecurityEvent('credential_validation_started', $validationId, [
            'email' => $this->maskEmail($email),
            'host' => $host,
            'port' => $port
        ]);
        
        $results = [
            'validation_id' => $validationId,
            'email' => $email,
            'is_valid' => false,
            'security_level' => self::SECURITY_LEVEL_LOW,
            'checks' => [],
            'warnings' => [],
            'errors' => [],
            'recommendations' => []
        ];
        
        try {
            // 1. Email Format Validation
            $emailCheck = $this->validateEmailFormat($email);
            $results['checks']['email_format'] = $emailCheck;
            
            if (!$emailCheck['passed']) {
                $results['errors'][] = $emailCheck['message'];
                return $results;
            }
            
            // 2. Gmail Domain Validation
            $domainCheck = $this->validateGmailDomain($email);
            $results['checks']['gmail_domain'] = $domainCheck;
            
            if (!$domainCheck['passed']) {
                $results['warnings'][] = $domainCheck['message'];
            }
            
            // 3. Password Strength Validation
            $passwordCheck = $this->validateImapPassword($password);
            $results['checks']['password_strength'] = $passwordCheck;
            
            if (!$passwordCheck['passed']) {
                $results['warnings'][] = $passwordCheck['message'];
            }
            
            // 4. Connection Test
            $connectionCheck = $this->testImapConnection($email, $password, $host, $port);
            $results['checks']['connection_test'] = $connectionCheck;
            
            if (!$connectionCheck['passed']) {
                $results['errors'][] = $connectionCheck['message'];
                return $results;
            }
            
            // 5. Account Security Analysis
            $securityCheck = $this->analyzeAccountSecurity($email, $password);
            $results['checks']['security_analysis'] = $securityCheck;
            
            // 6. Rate Limit and Abuse Detection
            $abuseCheck = $this->checkForAbuse($email);
            $results['checks']['abuse_detection'] = $abuseCheck;
            
            if (!$abuseCheck['passed']) {
                $results['errors'][] = $abuseCheck['message'];
                return $results;
            }
            
            // Calculate overall security level
            $results['security_level'] = $this->calculateSecurityLevel($results['checks']);
            $results['is_valid'] = true;
            
            // Generate recommendations
            $results['recommendations'] = $this->generateSecurityRecommendations($results);
            
            $this->logSecurityEvent('credential_validation_passed', $validationId, [
                'security_level' => $results['security_level'],
                'checks_passed' => count(array_filter($results['checks'], fn($c) => $c['passed']))
            ]);
            
        } catch (Exception $e) {
            $results['errors'][] = "Validation failed: " . $e->getMessage();
            $this->logSecurityEvent('credential_validation_failed', $validationId, [
                'error' => $e->getMessage()
            ]);
        }
        
        return $results;
    }
    
    /**
     * Validate email format
     */
    private function validateEmailFormat($email) {
        $result = [
            'passed' => false,
            'message' => '',
            'score' => 0
        ];
        
        if (empty($email)) {
            $result['message'] = 'Email address is required';
            return $result;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['message'] = 'Invalid email format';
            return $result;
        }
        
        if (strlen($email) > 254) {
            $result['message'] = 'Email address too long';
            return $result;
        }
        
        $result['passed'] = true;
        $result['score'] = 10;
        $result['message'] = 'Valid email format';
        return $result;
    }
    
    /**
     * Validate Gmail domain
     */
    private function validateGmailDomain($email) {
        $result = [
            'passed' => false,
            'message' => '',
            'score' => 0
        ];
        
        $domain = strtolower(substr(strrchr($email, "@"), 1));
        
        $validGmailDomains = ['gmail.com', 'googlemail.com'];
        
        if (in_array($domain, $validGmailDomains)) {
            $result['passed'] = true;
            $result['score'] = 10;
            $result['message'] = 'Valid Gmail domain';
        } else {
            $result['passed'] = false;
            $result['score'] = 0;
            $result['message'] = 'Only Gmail addresses are supported for IMAP';
        }
        
        return $result;
    }
    
    /**
     * Validate IMAP app password
     */
    private function validateImapPassword($password) {
        $result = [
            'passed' => false,
            'message' => '',
            'score' => 0
        ];
        
        if (empty($password)) {
            $result['message'] = 'IMAP password is required';
            return $result;
        }
        
        // Gmail app passwords are 16 characters
        if (strlen($password) === 16) {
            $result['score'] += 5;
        } else {
            $result['message'] = 'Gmail App Password should be 16 characters';
        }
        
        // Check for spaces (app passwords shouldn't have spaces)
        if (!preg_match('/\s/', $password)) {
            $result['score'] += 3;
        } else {
            $result['message'] = 'App password should not contain spaces';
        }
        
        // Check for valid characters (alphanumeric)
        if (preg_match('/^[a-zA-Z0-9]+$/', $password)) {
            $result['score'] += 2;
        } else {
            $result['message'] = 'App password contains invalid characters';
        }
        
        $result['passed'] = $result['score'] >= 8;
        if ($result['passed'] && empty($result['message'])) {
            $result['message'] = 'Valid Gmail App Password format';
        }
        
        return $result;
    }
    
    /**
     * Test IMAP connection with security monitoring
     */
    private function testImapConnection($email, $password, $host, $port) {
        $result = [
            'passed' => false,
            'message' => '',
            'score' => 0,
            'connection_time' => 0,
            'ssl_info' => []
        ];
        
        $startTime = microtime(true);
        
        try {
            $mailbox = "{{$host}:{$port}/imap/ssl/novalidate-cert}INBOX";
            $connection = imap_open($mailbox, $email, $password);
            
            if ($connection) {
                $result['connection_time'] = microtime(true) - $startTime;
                
                // Get mailbox info for additional validation
                $mailboxInfo = imap_mailboxmsginfo($connection);
                
                // Perform basic operations to ensure full access
                $folderList = imap_listmailbox($connection, "{{$host}:{$port}/imap/ssl/novalidate-cert}", "*");
                
                imap_close($connection);
                
                $result['passed'] = true;
                $result['score'] = 15;
                $result['message'] = 'IMAP connection successful';
                $result['mailbox_info'] = [
                    'total_messages' => $mailboxInfo->Nmsgs ?? 0,
                    'unread_messages' => $mailboxInfo->Unread ?? 0,
                    'recent_messages' => $mailboxInfo->Recent ?? 0
                ];
                
                // Check connection speed (security consideration)
                if ($result['connection_time'] > 10) {
                    $result['message'] .= ' (slow connection detected)';
                }
                
            } else {
                $result['message'] = 'IMAP connection failed: ' . (imap_last_error() ?: 'Unknown error');
            }
            
        } catch (Exception $e) {
            $result['message'] = 'IMAP connection error: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Analyze account security characteristics
     */
    private function analyzeAccountSecurity($email, $password) {
        $result = [
            'passed' => true,
            'message' => '',
            'score' => 0,
            'security_features' => [],
            'risk_factors' => []
        ];
        
        // Check if credentials were previously compromised
        $compromiseCheck = $this->checkCredentialHistory($email);
        if ($compromiseCheck['found_issues']) {
            $result['risk_factors'][] = 'Previous authentication issues detected';
            $result['score'] -= 5;
        }
        
        // Check account age and usage patterns
        $usagePattern = $this->analyzeUsagePattern($email);
        $result['security_features'] = array_merge($result['security_features'], $usagePattern['features']);
        $result['score'] += $usagePattern['score'];
        
        // Rate security level
        if ($result['score'] >= 8) {
            $result['message'] = 'High security account characteristics';
        } elseif ($result['score'] >= 5) {
            $result['message'] = 'Medium security account characteristics';
        } else {
            $result['message'] = 'Low security account characteristics';
            $result['risk_factors'][] = 'Account shows low security characteristics';
        }
        
        return $result;
    }
    
    /**
     * Check for abuse patterns and rate limiting
     */
    private function checkForAbuse($email) {
        $result = [
            'passed' => true,
            'message' => 'No abuse detected',
            'score' => 10,
            'attempts_count' => 0,
            'locked_until' => null
        ];
        
        $maskedEmail = $this->maskEmail($email);
        
        // Check recent failed attempts
        $recentAttempts = $this->db->fetchAll(
            "SELECT * FROM security_attempts 
             WHERE email = ? AND attempt_type = 'imap_auth' 
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) 
             ORDER BY created_at DESC",
            [$maskedEmail]
        );
        
        $failedAttempts = array_filter($recentAttempts, fn($a) => $a['success'] == 0);
        $result['attempts_count'] = count($failedAttempts);
        
        if (count($failedAttempts) >= self::MAX_FAILED_ATTEMPTS) {
            $lastFailure = $failedAttempts[0];
            $lockoutEnd = strtotime($lastFailure['created_at']) + self::LOCKOUT_DURATION;
            
            if (time() < $lockoutEnd) {
                $result['passed'] = false;
                $result['locked_until'] = date('Y-m-d H:i:s', $lockoutEnd);
                $result['message'] = "Account temporarily locked due to failed attempts. Unlocks at: " . $result['locked_until'];
                $result['score'] = 0;
            }
        }
        
        // Log this attempt
        $this->logSecurityAttempt($maskedEmail, 'imap_auth', $result['passed']);
        
        return $result;
    }
    
    /**
     * Calculate overall security level
     */
    private function calculateSecurityLevel($checks) {
        $totalScore = 0;
        $maxScore = 0;
        
        foreach ($checks as $check) {
            if (isset($check['score'])) {
                $totalScore += $check['score'];
                $maxScore += 15; // Assume max score per check is 15
            }
        }
        
        if ($maxScore === 0) return self::SECURITY_LEVEL_LOW;
        
        $percentage = ($totalScore / $maxScore) * 100;
        
        if ($percentage >= 90) return self::SECURITY_LEVEL_CRITICAL;
        if ($percentage >= 75) return self::SECURITY_LEVEL_HIGH;
        if ($percentage >= 50) return self::SECURITY_LEVEL_MEDIUM;
        
        return self::SECURITY_LEVEL_LOW;
    }
    
    /**
     * Generate security recommendations
     */
    private function generateSecurityRecommendations($validationResults) {
        $recommendations = [];
        
        if ($validationResults['security_level'] < self::SECURITY_LEVEL_HIGH) {
            $recommendations[] = "Consider using a dedicated email account for automation";
        }
        
        if (isset($validationResults['checks']['password_strength']) && 
            $validationResults['checks']['password_strength']['score'] < 8) {
            $recommendations[] = "Generate a new Gmail App Password for better security";
        }
        
        if (isset($validationResults['checks']['connection_test']['connection_time']) && 
            $validationResults['checks']['connection_test']['connection_time'] > 5) {
            $recommendations[] = "Slow connection detected - monitor for performance issues";
        }
        
        if (!empty($validationResults['warnings'])) {
            $recommendations[] = "Address security warnings to improve system safety";
        }
        
        // Always include best practices
        $recommendations[] = "Enable 2FA on your Google Account";
        $recommendations[] = "Regularly rotate your App Passwords";
        $recommendations[] = "Monitor system logs for suspicious activity";
        $recommendations[] = "Use encrypted credential storage (already implemented)";
        
        return $recommendations;
    }
    
    /**
     * Check credential history for past issues
     */
    private function checkCredentialHistory($email) {
        $maskedEmail = $this->maskEmail($email);
        
        $history = $this->db->fetchAll(
            "SELECT * FROM security_log 
             WHERE credential_key LIKE ? 
             AND event_type IN ('credential_test_failed', 'authentication_failed')
             AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
            ['%' . $maskedEmail . '%']
        );
        
        return [
            'found_issues' => !empty($history),
            'issue_count' => count($history),
            'recent_issues' => array_slice($history, 0, 5)
        ];
    }
    
    /**
     * Analyze usage patterns for security scoring
     */
    private function analyzeUsagePattern($email) {
        $maskedEmail = $this->maskEmail($email);
        
        $features = [];
        $score = 0;
        
        // Check for consistent usage
        $usageHistory = $this->db->fetchAll(
            "SELECT DATE(created_at) as usage_date, COUNT(*) as operations
             FROM security_log 
             WHERE credential_key LIKE ? 
             AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)",
            ['%' . $maskedEmail . '%']
        );
        
        if (count($usageHistory) > 0) {
            $features[] = 'Consistent usage pattern';
            $score += 3;
        }
        
        // Check for successful operations
        $successfulOps = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM security_log 
             WHERE credential_key LIKE ? 
             AND event_type NOT LIKE '%failed%'
             AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            ['%' . $maskedEmail . '%']
        );
        
        if ($successfulOps['count'] > 10) {
            $features[] = 'High successful operation rate';
            $score += 5;
        }
        
        return [
            'features' => $features,
            'score' => $score
        ];
    }
    
    /**
     * Log security attempt
     */
    private function logSecurityAttempt($email, $attemptType, $success) {
        try {
            $this->db->execute(
                "INSERT INTO security_attempts (email, attempt_type, success, ip_address, user_agent, created_at) 
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    $email,
                    $attemptType,
                    $success ? 1 : 0,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]
            );
        } catch (Exception $e) {
            $this->logSecurityEvent('log_attempt_failed', $email, ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get security validation report
     */
    public function getSecurityReport($days = 7) {
        $report = [
            'report_date' => date('Y-m-d H:i:s'),
            'period_days' => $days,
            'validation_attempts' => [],
            'security_events' => [],
            'failed_attempts' => [],
            'recommendations' => []
        ];
        
        // Get validation attempts
        $report['validation_attempts'] = $this->db->fetchAll(
            "SELECT attempt_type, success, COUNT(*) as count 
             FROM security_attempts 
             WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY attempt_type, success
             ORDER BY count DESC",
            [$days]
        );
        
        // Get security events
        $report['security_events'] = $this->db->fetchAll(
            "SELECT event_type, COUNT(*) as count,
                    MAX(created_at) as last_occurrence
             FROM security_log 
             WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY event_type
             ORDER BY count DESC
             LIMIT 10",
            [$days]
        );
        
        // Get failed attempts by IP
        $report['failed_attempts'] = $this->db->fetchAll(
            "SELECT ip_address, COUNT(*) as failed_count,
                    MAX(created_at) as last_attempt
             FROM security_attempts 
             WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY ip_address
             ORDER BY failed_count DESC
             LIMIT 10",
            [$days]
        );
        
        // Generate recommendations based on data
        if (!empty($report['failed_attempts'])) {
            $report['recommendations'][] = "Monitor IP addresses with multiple failed attempts";
        }
        
        $totalFailures = array_sum(array_column($report['validation_attempts'], 'count'));
        if ($totalFailures > 20) {
            $report['recommendations'][] = "High number of validation failures detected - investigate potential security issues";
        }
        
        return $report;
    }
    
    /**
     * Mask email for logging (privacy protection)
     */
    private function maskEmail($email) {
        if (empty($email) || strpos($email, '@') === false) {
            return 'invalid_email';
        }
        
        list($local, $domain) = explode('@', $email);
        $localLength = strlen($local);
        
        if ($localLength <= 2) {
            $maskedLocal = str_repeat('*', $localLength);
        } else {
            $maskedLocal = $local[0] . str_repeat('*', $localLength - 2) . $local[$localLength - 1];
        }
        
        return $maskedLocal . '@' . $domain;
    }
    
    /**
     * Initialize security rules
     */
    private function initializeSecurityRules() {
        $this->securityRules = [
            'max_failed_attempts' => self::MAX_FAILED_ATTEMPTS,
            'lockout_duration' => self::LOCKOUT_DURATION,
            'password_min_length' => 16,
            'required_email_domain' => ['gmail.com', 'googlemail.com'],
            'max_connection_time' => 10.0,
            'audit_retention_days' => 90
        ];
    }
    
    /**
     * Create security-related database tables
     */
    private function createSecurityTables() {
        $tables = [
            'security_attempts' => "CREATE TABLE IF NOT EXISTS security_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                attempt_type VARCHAR(50) NOT NULL,
                success TINYINT(1) NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_attempt_type (attempt_type),
                INDEX idx_created_at (created_at)
            )",
            'forwarded_leads' => "CREATE TABLE IF NOT EXISTS forwarded_leads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reply_id INT,
                forwarded_to VARCHAR(255),
                subject VARCHAR(255),
                body TEXT,
                gmass_message_id VARCHAR(255),
                status VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reply_id (reply_id),
                INDEX idx_status (status)
            )",
            'email_replies' => "CREATE TABLE IF NOT EXISTS email_replies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                original_email_id INT,
                reply_from VARCHAR(255),
                reply_subject VARCHAR(255),
                reply_body TEXT,
                classification VARCHAR(50),
                confidence_score DECIMAL(3,2),
                received_at TIMESTAMP,
                processed_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_original_email (original_email_id),
                INDEX idx_classification (classification)
            )"
        ];
        
        foreach ($tables as $tableName => $sql) {
            try {
                $this->db->execute($sql);
            } catch (Exception $e) {
                $this->logSecurityEvent('table_creation_failed', $tableName, ['error' => $e->getMessage()]);
            }
        }
    }
    
    /**
     * Log security events
     */
    private function logSecurityEvent($event, $identifier, $data = []) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [SECURITY] {$event} - {$identifier}";
        
        if (!empty($data)) {
            $logEntry .= " - " . json_encode($data);
        }
        
        $logEntry .= "\n";
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Also log to database if possible
        try {
            $this->db->execute(
                "INSERT INTO security_log (event_type, credential_key, details, ip_address, user_agent, created_at) 
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    $event,
                    $identifier,
                    json_encode($data),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]
            );
        } catch (Exception $e) {
            // Silently fail database logging - file logging is the fallback
        }
    }
}
?>