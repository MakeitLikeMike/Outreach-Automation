<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/GmailOAuth.php';

class SenderHealth {
    private $db;
    private $gmailOAuth;
    private $settings;
    
    // Health status thresholds
    const HEALTHY_SCORE = 80;
    const WARNING_SCORE = 60;
    const CRITICAL_SCORE = 40;
    
    public function __construct() {
        $this->db = new Database();
        $this->gmailOAuth = new GmailOAuth();
        $this->loadSettings();
    }
    
    private function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%health%' OR setting_key LIKE '%monitoring%'";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    public function checkAllSenders() {
        $senders = $this->gmailOAuth->getAllTokens();
        $results = [];
        
        foreach ($senders as $sender) {
            $health = $this->checkSenderHealth($sender['email']);
            $results[] = array_merge($sender, $health);
        }
        
        return $results;
    }
    
    public function checkSenderHealth($senderEmail) {
        $healthData = [
            'email' => $senderEmail,
            'status' => 'healthy',
            'health_score' => 100.0,
            'warning_flags' => [],
            'last_success_at' => null,
            'last_failure_at' => null,
            'consecutive_failures' => 0,
            'total_failures_today' => 0,
            'checks_performed' => []
        ];
        
        try {
            // Check 1: Gmail OAuth Token Validity
            $tokenCheck = $this->checkTokenHealth($senderEmail);
            $healthData['checks_performed'][] = 'token_validity';
            $healthData['health_score'] = min($healthData['health_score'], $tokenCheck['score']);
            if (!empty($tokenCheck['warnings'])) {
                $healthData['warning_flags'] = array_merge($healthData['warning_flags'], $tokenCheck['warnings']);
            }
            
            // Check 2: Rate Limit Status
            $rateLimitCheck = $this->checkRateLimitHealth($senderEmail);
            $healthData['checks_performed'][] = 'rate_limits';
            $healthData['health_score'] = min($healthData['health_score'], $rateLimitCheck['score']);
            if (!empty($rateLimitCheck['warnings'])) {
                $healthData['warning_flags'] = array_merge($healthData['warning_flags'], $rateLimitCheck['warnings']);
            }
            
            // Check 3: Recent Performance
            $performanceCheck = $this->checkPerformanceHealth($senderEmail);
            $healthData['checks_performed'][] = 'recent_performance';
            $healthData['health_score'] = min($healthData['health_score'], $performanceCheck['score']);
            if (!empty($performanceCheck['warnings'])) {
                $healthData['warning_flags'] = array_merge($healthData['warning_flags'], $performanceCheck['warnings']);
            }
            
            // Check 4: Gmail API Connectivity
            $connectivityCheck = $this->checkConnectivityHealth($senderEmail);
            $healthData['checks_performed'][] = 'gmail_connectivity';
            $healthData['health_score'] = min($healthData['health_score'], $connectivityCheck['score']);
            if (!empty($connectivityCheck['warnings'])) {
                $healthData['warning_flags'] = array_merge($healthData['warning_flags'], $connectivityCheck['warnings']);
            }
            
            // Determine overall status
            $healthData['status'] = $this->calculateHealthStatus($healthData['health_score'], $healthData['warning_flags']);
            
            // Get recent activity data
            $activityData = $this->getRecentActivity($senderEmail);
            $healthData = array_merge($healthData, $activityData);
            
            // Update database record
            $this->updateSenderHealthRecord($healthData);
            
        } catch (Exception $e) {
            $healthData['status'] = 'critical';
            $healthData['health_score'] = 0;
            $healthData['warning_flags'][] = 'health_check_failed';
            $healthData['last_failure_at'] = date('Y-m-d H:i:s');
            
            error_log("Sender health check failed for $senderEmail: " . $e->getMessage());
        }
        
        return $healthData;
    }
    
    private function checkTokenHealth($senderEmail) {
        $result = ['score' => 100, 'warnings' => []];
        
        try {
            $token = $this->gmailOAuth->getValidToken($senderEmail);
            
            if (!$token) {
                $result['score'] = 0;
                $result['warnings'][] = 'invalid_token';
                return $result;
            }
            
            // Check token expiry proximity
            $expiresAt = strtotime($token['expires_at']);
            $hoursUntilExpiry = ($expiresAt - time()) / 3600;
            
            if ($hoursUntilExpiry < 1) {
                $result['score'] = 20;
                $result['warnings'][] = 'token_expiring_soon';
            } elseif ($hoursUntilExpiry < 24) {
                $result['score'] = 70;
                $result['warnings'][] = 'token_expires_today';
            } elseif ($hoursUntilExpiry < 168) { // 1 week
                $result['score'] = 90;
                $result['warnings'][] = 'token_expires_this_week';
            }
            
        } catch (Exception $e) {
            $result['score'] = 0;
            $result['warnings'][] = 'token_check_failed';
        }
        
        return $result;
    }
    
    private function checkRateLimitHealth($senderEmail) {
        $result = ['score' => 100, 'warnings' => []];
        
        try {
            $sql = "SELECT limit_type, current_count, limit_value, reset_at 
                    FROM rate_limits 
                    WHERE sender_email = ?";
            $limits = $this->db->fetchAll($sql, [$senderEmail]);
            
            foreach ($limits as $limit) {
                $usagePercentage = ($limit['current_count'] / max($limit['limit_value'], 1)) * 100;
                
                if ($usagePercentage >= 95) {
                    $result['score'] = min($result['score'], 10);
                    $result['warnings'][] = "rate_limit_critical_{$limit['limit_type']}";
                } elseif ($usagePercentage >= 80) {
                    $result['score'] = min($result['score'], 50);
                    $result['warnings'][] = "rate_limit_warning_{$limit['limit_type']}";
                } elseif ($usagePercentage >= 60) {
                    $result['score'] = min($result['score'], 75);
                    $result['warnings'][] = "rate_limit_moderate_{$limit['limit_type']}";
                }
            }
            
        } catch (Exception $e) {
            $result['score'] = 50;
            $result['warnings'][] = 'rate_limit_check_failed';
        }
        
        return $result;
    }
    
    private function checkPerformanceHealth($senderEmail) {
        $result = ['score' => 100, 'warnings' => []];
        
        try {
            // Check recent email success rate (last 7 days)
            $sql = "SELECT 
                        COUNT(*) as total_sent,
                        COUNT(CASE WHEN status = 'sent' THEN 1 END) as successful,
                        COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                        COUNT(CASE WHEN status = 'bounced' THEN 1 END) as bounced
                    FROM outreach_emails 
                    WHERE sender_email = ? 
                    AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            
            $stats = $this->db->fetchOne($sql, [$senderEmail]);
            
            if ($stats['total_sent'] > 0) {
                $successRate = ($stats['successful'] / $stats['total_sent']) * 100;
                $bounceRate = ($stats['bounced'] / $stats['total_sent']) * 100;
                
                if ($successRate < 70) {
                    $result['score'] = min($result['score'], 30);
                    $result['warnings'][] = 'low_success_rate';
                } elseif ($successRate < 85) {
                    $result['score'] = min($result['score'], 60);
                    $result['warnings'][] = 'moderate_success_rate';
                }
                
                if ($bounceRate > 5) {
                    $result['score'] = min($result['score'], 40);
                    $result['warnings'][] = 'high_bounce_rate';
                } elseif ($bounceRate > 2) {
                    $result['score'] = min($result['score'], 70);
                    $result['warnings'][] = 'elevated_bounce_rate';
                }
            }
            
            // Check for consecutive failures
            $sql = "SELECT COUNT(*) as consecutive_failures
                    FROM outreach_emails 
                    WHERE sender_email = ? 
                    AND status = 'failed'
                    AND sent_at > (
                        SELECT COALESCE(MAX(sent_at), '1970-01-01')
                        FROM outreach_emails 
                        WHERE sender_email = ? 
                        AND status = 'sent'
                    )";
            
            $failureCheck = $this->db->fetchOne($sql, [$senderEmail, $senderEmail]);
            $consecutiveFailures = $failureCheck['consecutive_failures'] ?? 0;
            
            if ($consecutiveFailures >= 5) {
                $result['score'] = min($result['score'], 20);
                $result['warnings'][] = 'multiple_consecutive_failures';
            } elseif ($consecutiveFailures >= 3) {
                $result['score'] = min($result['score'], 50);
                $result['warnings'][] = 'some_consecutive_failures';
            }
            
        } catch (Exception $e) {
            $result['score'] = 80;
            $result['warnings'][] = 'performance_check_failed';
        }
        
        return $result;
    }
    
    private function checkConnectivityHealth($senderEmail) {
        $result = ['score' => 100, 'warnings' => []];
        
        try {
            $connectionTest = $this->gmailOAuth->testConnection($senderEmail);
            
            if (!$connectionTest['success']) {
                $result['score'] = 0;
                $result['warnings'][] = 'gmail_connection_failed';
            } else {
                // Test was successful, full score
                $result['score'] = 100;
            }
            
        } catch (Exception $e) {
            $result['score'] = 0;
            $result['warnings'][] = 'connectivity_test_failed';
        }
        
        return $result;
    }
    
    private function calculateHealthStatus($score, $warnings) {
        // Check for critical warnings that override score
        $criticalWarnings = ['invalid_token', 'gmail_connection_failed', 'rate_limit_critical_hourly'];
        
        foreach ($criticalWarnings as $criticalWarning) {
            if (in_array($criticalWarning, $warnings)) {
                return 'critical';
            }
        }
        
        // Status based on score
        if ($score >= self::HEALTHY_SCORE) {
            return 'healthy';
        } elseif ($score >= self::WARNING_SCORE) {
            return 'warning';
        } elseif ($score >= self::CRITICAL_SCORE) {
            return 'critical';
        } else {
            return 'suspended';
        }
    }
    
    private function getRecentActivity($senderEmail) {
        $sql = "SELECT 
                    MAX(CASE WHEN status = 'sent' THEN sent_at END) as last_success_at,
                    MAX(CASE WHEN status = 'failed' THEN sent_at END) as last_failure_at,
                    COUNT(CASE WHEN status = 'failed' AND DATE(sent_at) = CURDATE() THEN 1 END) as total_failures_today
                FROM outreach_emails 
                WHERE sender_email = ?";
        
        $activity = $this->db->fetchOne($sql, [$senderEmail]);
        
        return [
            'last_success_at' => $activity['last_success_at'],
            'last_failure_at' => $activity['last_failure_at'],
            'total_failures_today' => $activity['total_failures_today'] ?? 0
        ];
    }
    
    private function updateSenderHealthRecord($healthData) {
        $sql = "INSERT INTO sender_health (
                    sender_email, status, health_score, last_success_at, last_failure_at,
                    consecutive_failures, total_failures_today, warning_flags, notes, checked_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    health_score = VALUES(health_score),
                    last_success_at = VALUES(last_success_at),
                    last_failure_at = VALUES(last_failure_at),
                    consecutive_failures = VALUES(consecutive_failures),
                    total_failures_today = VALUES(total_failures_today),
                    warning_flags = VALUES(warning_flags),
                    notes = VALUES(notes),
                    checked_at = VALUES(checked_at),
                    updated_at = NOW()";
        
        $warningFlags = json_encode($healthData['warning_flags']);
        $notes = 'Last health check: ' . date('Y-m-d H:i:s') . 
                 '. Checks performed: ' . implode(', ', $healthData['checks_performed']);
        
        $this->db->execute($sql, [
            $healthData['email'],
            $healthData['status'],
            $healthData['health_score'],
            $healthData['last_success_at'],
            $healthData['last_failure_at'],
            $healthData['consecutive_failures'],
            $healthData['total_failures_today'],
            $warningFlags,
            $notes
        ]);
    }
    
    public function getSenderHealthSummary() {
        $sql = "SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(health_score) as avg_health_score,
                    MIN(health_score) as lowest_score,
                    MAX(checked_at) as last_check
                FROM sender_health 
                GROUP BY status";
        
        $summary = $this->db->fetchAll($sql);
        
        // Get total counts
        $totalSql = "SELECT COUNT(*) as total FROM sender_health";
        $total = $this->db->fetchOne($totalSql);
        
        return [
            'summary' => $summary,
            'total_senders' => $total['total'] ?? 0,
            'last_updated' => date('Y-m-d H:i:s')
        ];
    }
    
    public function getUnhealthySenders($includeWarnings = true) {
        $statuses = $includeWarnings ? ['warning', 'critical', 'suspended'] : ['critical', 'suspended'];
        $placeholders = str_repeat('?,', count($statuses) - 1) . '?';
        
        $sql = "SELECT sh.*, gt.expires_at as token_expires
                FROM sender_health sh
                JOIN gmail_tokens gt ON sh.sender_email = gt.email
                WHERE sh.status IN ($placeholders)
                ORDER BY 
                    CASE sh.status 
                        WHEN 'suspended' THEN 1 
                        WHEN 'critical' THEN 2 
                        WHEN 'warning' THEN 3 
                    END,
                    sh.health_score ASC";
        
        return $this->db->fetchAll($sql, $statuses);
    }
    
    public function markSenderSuspended($senderEmail, $reason) {
        $sql = "UPDATE sender_health 
                SET status = 'suspended', 
                    health_score = 0,
                    notes = CONCAT(COALESCE(notes, ''), '\nSuspended: ', ?, ' at ', NOW()),
                    updated_at = NOW()
                WHERE sender_email = ?";
        
        return $this->db->execute($sql, [$reason, $senderEmail]);
    }
    
    public function reactivateSender($senderEmail) {
        // Reset health status and run fresh check
        $sql = "UPDATE sender_health 
                SET status = 'healthy', 
                    health_score = 100,
                    consecutive_failures = 0,
                    total_failures_today = 0,
                    warning_flags = '[]',
                    notes = CONCAT(COALESCE(notes, ''), '\nReactivated at ', NOW()),
                    updated_at = NOW()
                WHERE sender_email = ?";
        
        $this->db->execute($sql, [$senderEmail]);
        
        // Run fresh health check
        return $this->checkSenderHealth($senderEmail);
    }
}
?>