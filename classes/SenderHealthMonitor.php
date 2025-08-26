<?php
/**
 * Sender Health Monitor - Tracks and manages sender account health
 * Monitors deliverability, bounce rates, and reputation across multiple Gmail accounts
 */
require_once __DIR__ . '/../config/database.php';

class SenderHealthMonitor {
    private $db;
    private $logFile;
    
    // Health thresholds
    const MAX_DAILY_SENDS = 100;
    const MAX_BOUNCE_RATE = 5.0; // 5%
    const MAX_SPAM_RATE = 2.0;   // 2%
    const MIN_DELIVERY_RATE = 90.0; // 90%
    const COOLDOWN_HOURS = 24;
    
    // Health status levels
    const STATUS_HEALTHY = 'healthy';
    const STATUS_WARNING = 'warning';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_BLOCKED = 'blocked';
    
    public function __construct() {
        $this->db = new Database();
        $this->logFile = __DIR__ . '/../logs/sender_health.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Get next healthy sender for campaign
     */
    public function getNextHealthySender($campaignId = null) {
        $this->log("🔍 Finding next healthy sender for campaign: " . ($campaignId ?: 'general'));
        
        try {
            // Get all available sender accounts
            $sql = "
                SELECT 
                    sa.*,
                    COALESCE(sh.emails_sent_today, 0) as emails_sent_today,
                    COALESCE(sh.bounce_rate, 0) as bounce_rate,
                    COALESCE(sh.spam_rate, 0) as spam_rate,
                    COALESCE(sh.delivery_rate, 100) as delivery_rate,
                    sh.last_send_at,
                    sh.health_status,
                    sh.suspension_until
                FROM sender_accounts sa
                LEFT JOIN sender_health sh ON sa.id = sh.sender_id
                WHERE sa.is_active = 1
                AND (sh.health_status IS NULL OR sh.health_status = 'healthy')
                AND (sh.suspension_until IS NULL OR sh.suspension_until <= NOW())
                ORDER BY 
                    COALESCE(sh.emails_sent_today, 0) ASC,
                    sh.last_send_at ASC,
                    sa.priority DESC
                LIMIT 10
            ";
            
            $senders = $this->db->fetchAll($sql);
            
            if (empty($senders)) {
                $this->log("❌ No healthy senders available");
                return null;
            }
            
            // Find the best sender
            foreach ($senders as $sender) {
                if ($this->isSenderHealthy($sender)) {
                    $this->log("✅ Selected sender: {$sender['email']} (sent today: {$sender['emails_sent_today']})");
                    return $sender;
                }
            }
            
            $this->log("⚠️ No fully healthy senders found, checking for acceptable ones...");
            
            // If no perfect sender, find acceptable one
            foreach ($senders as $sender) {
                if ($this->isSenderAcceptable($sender)) {
                    $this->log("⚠️ Using acceptable sender: {$sender['email']} with caution");
                    return $sender;
                }
            }
            
            $this->log("❌ No acceptable senders available");
            return null;
            
        } catch (Exception $e) {
            $this->log("❌ Error getting healthy sender: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Record successful email send
     */
    public function recordSuccessfulSend($senderId, $recipientEmail = null) {
        $this->log("✅ Recording successful send for sender ID: {$senderId}");
        
        try {
            // Update sender health metrics
            $this->updateSenderHealthMetrics($senderId, 'success');
            
            // Log the send
            $this->logSenderActivity($senderId, 'email_sent', [
                'recipient' => $recipientEmail,
                'status' => 'success',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            $this->log("❌ Error recording successful send: " . $e->getMessage());
        }
    }
    
    /**
     * Record failed email send
     */
    public function recordFailedSend($senderId, $error, $recipientEmail = null) {
        $this->log("❌ Recording failed send for sender ID: {$senderId} - Error: {$error}");
        
        try {
            // Determine failure type
            $failureType = $this->classifyFailure($error);
            
            // Update sender health metrics
            $this->updateSenderHealthMetrics($senderId, $failureType);
            
            // Log the failure
            $this->logSenderActivity($senderId, 'email_failed', [
                'recipient' => $recipientEmail,
                'error' => $error,
                'failure_type' => $failureType,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // Check if sender should be suspended
            $this->evaluateSenderHealth($senderId);
            
        } catch (Exception $e) {
            $this->log("❌ Error recording failed send: " . $e->getMessage());
        }
    }
    
    /**
     * Record email bounce
     */
    public function recordBounce($senderId, $bounceType, $recipientEmail) {
        $this->log("📧 Recording bounce for sender ID: {$senderId} - Type: {$bounceType}");
        
        try {
            $this->updateSenderHealthMetrics($senderId, 'bounce', $bounceType);
            
            $this->logSenderActivity($senderId, 'email_bounced', [
                'recipient' => $recipientEmail,
                'bounce_type' => $bounceType,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // Re-evaluate sender health after bounce
            $this->evaluateSenderHealth($senderId);
            
        } catch (Exception $e) {
            $this->log("❌ Error recording bounce: " . $e->getMessage());
        }
    }
    
    /**
     * Record spam complaint
     */
    public function recordSpamComplaint($senderId, $recipientEmail) {
        $this->log("🚨 Recording spam complaint for sender ID: {$senderId}");
        
        try {
            $this->updateSenderHealthMetrics($senderId, 'spam');
            
            $this->logSenderActivity($senderId, 'spam_complaint', [
                'recipient' => $recipientEmail,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // Spam complaints are serious - immediately re-evaluate
            $this->evaluateSenderHealth($senderId);
            
        } catch (Exception $e) {
            $this->log("❌ Error recording spam complaint: " . $e->getMessage());
        }
    }
    
    /**
     * Update sender health metrics
     */
    private function updateSenderHealthMetrics($senderId, $eventType, $additionalInfo = null) {
        // Get or create health record
        $health = $this->db->fetchOne(
            "SELECT * FROM sender_health WHERE sender_id = ?",
            [$senderId]
        );
        
        if (!$health) {
            // Create new health record
            $this->db->execute(
                "INSERT INTO sender_health (sender_id, created_at) VALUES (?, NOW())",
                [$senderId]
            );
            $health = $this->db->fetchOne(
                "SELECT * FROM sender_health WHERE sender_id = ?",
                [$senderId]
            );
        }
        
        // Update metrics based on event type
        $updates = [];
        $params = [];
        
        switch ($eventType) {
            case 'success':
                $updates[] = "emails_sent_today = emails_sent_today + 1";
                $updates[] = "total_emails_sent = total_emails_sent + 1";
                $updates[] = "last_send_at = NOW()";
                break;
                
            case 'bounce':
                $updates[] = "total_bounces = total_bounces + 1";
                if ($additionalInfo === 'hard') {
                    $updates[] = "hard_bounces = hard_bounces + 1";
                } else {
                    $updates[] = "soft_bounces = soft_bounces + 1";
                }
                break;
                
            case 'spam':
                $updates[] = "spam_complaints = spam_complaints + 1";
                break;
                
            case 'delivery_failure':
                $updates[] = "delivery_failures = delivery_failures + 1";
                break;
        }
        
        if (!empty($updates)) {
            $sql = "UPDATE sender_health SET " . implode(', ', $updates) . " WHERE sender_id = ?";
            $params[] = $senderId;
            $this->db->execute($sql, $params);
            
            // Recalculate rates
            $this->recalculateHealthRates($senderId);
        }
    }
    
    /**
     * Recalculate health rates for sender
     */
    private function recalculateHealthRates($senderId) {
        $sql = "
            UPDATE sender_health 
            SET 
                bounce_rate = CASE 
                    WHEN total_emails_sent > 0 
                    THEN (total_bounces / total_emails_sent) * 100 
                    ELSE 0 
                END,
                spam_rate = CASE 
                    WHEN total_emails_sent > 0 
                    THEN (spam_complaints / total_emails_sent) * 100 
                    ELSE 0 
                END,
                delivery_rate = CASE 
                    WHEN total_emails_sent > 0 
                    THEN ((total_emails_sent - total_bounces - delivery_failures) / total_emails_sent) * 100 
                    ELSE 100 
                END,
                updated_at = NOW()
            WHERE sender_id = ?
        ";
        
        $this->db->execute($sql, [$senderId]);
    }
    
    /**
     * Evaluate sender health and update status
     */
    private function evaluateSenderHealth($senderId) {
        $health = $this->db->fetchOne(
            "SELECT * FROM sender_health WHERE sender_id = ?",
            [$senderId]
        );
        
        if (!$health) {
            return;
        }
        
        $newStatus = self::STATUS_HEALTHY;
        $suspensionUntil = null;
        
        // Check for critical violations
        if ($health['spam_rate'] > self::MAX_SPAM_RATE) {
            $newStatus = self::STATUS_SUSPENDED;
            $suspensionUntil = date('Y-m-d H:i:s', strtotime('+72 hours')); // 3-day suspension for spam
            $this->log("🚨 Sender {$senderId} suspended for high spam rate: {$health['spam_rate']}%");
            
        } elseif ($health['bounce_rate'] > self::MAX_BOUNCE_RATE) {
            $newStatus = self::STATUS_SUSPENDED;
            $suspensionUntil = date('Y-m-d H:i:s', strtotime('+24 hours')); // 1-day suspension for bounces
            $this->log("⚠️ Sender {$senderId} suspended for high bounce rate: {$health['bounce_rate']}%");
            
        } elseif ($health['delivery_rate'] < self::MIN_DELIVERY_RATE) {
            $newStatus = self::STATUS_WARNING;
            $this->log("⚠️ Sender {$senderId} marked as warning for low delivery rate: {$health['delivery_rate']}%");
            
        } elseif ($health['emails_sent_today'] >= self::MAX_DAILY_SENDS) {
            $newStatus = self::STATUS_SUSPENDED;
            $suspensionUntil = date('Y-m-d H:i:s', strtotime('tomorrow')); // Suspend until tomorrow
            $this->log("📊 Sender {$senderId} suspended for daily limit reached: {$health['emails_sent_today']}");
        }
        
        // Update health status
        $this->db->execute(
            "UPDATE sender_health SET health_status = ?, suspension_until = ?, updated_at = NOW() WHERE sender_id = ?",
            [$newStatus, $suspensionUntil, $senderId]
        );
    }
    
    /**
     * Check if sender is healthy
     */
    private function isSenderHealthy($sender) {
        return (
            $sender['emails_sent_today'] < self::MAX_DAILY_SENDS &&
            $sender['bounce_rate'] <= self::MAX_BOUNCE_RATE &&
            $sender['spam_rate'] <= self::MAX_SPAM_RATE &&
            $sender['delivery_rate'] >= self::MIN_DELIVERY_RATE &&
            empty($sender['suspension_until'])
        );
    }
    
    /**
     * Check if sender is acceptable (slightly relaxed standards)
     */
    private function isSenderAcceptable($sender) {
        return (
            $sender['emails_sent_today'] < self::MAX_DAILY_SENDS * 1.2 && // 20% over limit acceptable
            $sender['bounce_rate'] <= self::MAX_BOUNCE_RATE * 1.5 &&      // 7.5% bounce rate acceptable
            $sender['spam_rate'] <= self::MAX_SPAM_RATE &&                 // Strict on spam
            empty($sender['suspension_until'])
        );
    }
    
    /**
     * Classify failure type from error message
     */
    private function classifyFailure($error) {
        $error = strtolower($error);
        
        if (strpos($error, 'bounce') !== false || strpos($error, 'undelivered') !== false) {
            return 'bounce';
        } elseif (strpos($error, 'spam') !== false || strpos($error, 'blocked') !== false) {
            return 'spam';
        } elseif (strpos($error, 'quota') !== false || strpos($error, 'limit') !== false) {
            return 'quota_exceeded';
        } elseif (strpos($error, 'authentication') !== false || strpos($error, 'credential') !== false) {
            return 'auth_failure';
        } else {
            return 'delivery_failure';
        }
    }
    
    /**
     * Log sender activity
     */
    private function logSenderActivity($senderId, $activity, $data) {
        $this->db->execute(
            "INSERT INTO sender_activity_log (sender_id, activity_type, activity_data, created_at) VALUES (?, ?, ?, NOW())",
            [$senderId, $activity, json_encode($data)]
        );
    }
    
    /**
     * Get sender health dashboard data
     */
    public function getSenderHealthDashboard() {
        $sql = "
            SELECT 
                sa.email,
                sa.sender_name,
                sa.is_active,
                sh.health_status,
                sh.emails_sent_today,
                sh.bounce_rate,
                sh.spam_rate,
                sh.delivery_rate,
                sh.suspension_until,
                sh.last_send_at
            FROM sender_accounts sa
            LEFT JOIN sender_health sh ON sa.id = sh.sender_id
            WHERE sa.is_active = 1
            ORDER BY 
                CASE sh.health_status 
                    WHEN 'healthy' THEN 1 
                    WHEN 'warning' THEN 2 
                    WHEN 'suspended' THEN 3 
                    WHEN 'blocked' THEN 4 
                    ELSE 5 
                END,
                sa.email
        ";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Reset daily counters (run daily via cron)
     */
    public function resetDailyCounters() {
        $this->log("🔄 Resetting daily counters for all senders");
        
        $this->db->execute("UPDATE sender_health SET emails_sent_today = 0 WHERE emails_sent_today > 0");
        
        // Also clear suspensions that have expired
        $this->db->execute("UPDATE sender_health SET health_status = 'healthy', suspension_until = NULL WHERE suspension_until <= NOW()");
        
        $this->log("✅ Daily counters reset and expired suspensions cleared");
    }
    
    /**
     * Get sender health statistics
     */
    public function getHealthStatistics() {
        $sql = "
            SELECT 
                COUNT(*) as total_senders,
                SUM(CASE WHEN sh.health_status = 'healthy' OR sh.health_status IS NULL THEN 1 ELSE 0 END) as healthy_senders,
                SUM(CASE WHEN sh.health_status = 'warning' THEN 1 ELSE 0 END) as warning_senders,
                SUM(CASE WHEN sh.health_status = 'suspended' THEN 1 ELSE 0 END) as suspended_senders,
                SUM(CASE WHEN sh.health_status = 'blocked' THEN 1 ELSE 0 END) as blocked_senders,
                ROUND(AVG(CASE WHEN sh.bounce_rate IS NOT NULL THEN sh.bounce_rate ELSE 0 END), 2) as avg_bounce_rate,
                ROUND(AVG(CASE WHEN sh.spam_rate IS NOT NULL THEN sh.spam_rate ELSE 0 END), 2) as avg_spam_rate,
                ROUND(AVG(CASE WHEN sh.delivery_rate IS NOT NULL THEN sh.delivery_rate ELSE 100 END), 2) as avg_delivery_rate
            FROM sender_accounts sa
            LEFT JOIN sender_health sh ON sa.id = sh.sender_id
            WHERE sa.is_active = 1
        ";
        
        return $this->db->fetchOne($sql);
    }
    
    /**
     * Log message with timestamp
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        echo $logMessage;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
?>