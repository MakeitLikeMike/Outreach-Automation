<?php
/**
 * Email Compatibility Wrapper
 * Provides backward compatibility during Gmail → GMass migration
 * Gradually routes traffic from Gmail API to GMass API
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/GMassIntegration.php';
require_once __DIR__ . '/GmailIntegration.php';

class EmailCompatibilityWrapper {
    private $db;
    private $gmass;
    private $gmail;
    private $settings;
    private $logFile;
    
    // Migration modes
    const MODE_GMAIL_ONLY = 'gmail_only';
    const MODE_GMASS_ONLY = 'gmass_only';  
    const MODE_HYBRID = 'hybrid';          // Use both based on conditions
    const MODE_TESTING = 'testing';        // Parallel sending for comparison
    
    public function __construct() {
        $this->db = new Database();
        $this->gmass = new GMassIntegration();
        
        // Only initialize Gmail if still needed
        if ($this->shouldUseGmail()) {
            try {
                $this->gmail = new GmailIntegration();
            } catch (Exception $e) {
                // Gmail integration failed, force GMass mode
                $this->logError("Gmail initialization failed: " . $e->getMessage());
                $this->gmail = null;
            }
        }
        
        $this->loadSettings();
        $this->logFile = __DIR__ . '/../logs/email_compatibility.log';
    }
    
    /**
     * Smart email sending - automatically chooses best method
     */
    public function sendEmail($from, $to, $subject, $body, $options = []) {
        $mode = $this->getMigrationMode();
        
        switch ($mode) {
            case self::MODE_GMASS_ONLY:
                return $this->sendViaGMass($from, $to, $subject, $body, $options);
                
            case self::MODE_GMAIL_ONLY:
                if ($this->gmail) {
                    return $this->sendViaGmail($from, $to, $subject, $body, $options);
                } else {
                    // Fallback to GMass if Gmail not available
                    return $this->sendViaGMass($from, $to, $subject, $body, $options);
                }
                
            case self::MODE_HYBRID:
                return $this->sendViaSmartRouting($from, $to, $subject, $body, $options);
                
            case self::MODE_TESTING:
                return $this->sendViaParallelTesting($from, $to, $subject, $body, $options);
                
            default:
                return $this->sendViaGMass($from, $to, $subject, $body, $options);
        }
    }
    
    /**
     * Send via GMass API
     */
    private function sendViaGMass($from, $to, $subject, $body, $options = []) {
        try {
            $result = $this->gmass->sendEmail($from, $to, $subject, $body, $options);
            $this->logSend('gmass', $from, $to, $subject, true, $result);
            
            return [
                'success' => true,
                'method' => 'gmass',
                'message_id' => $result['message_id'],
                'gmass_id' => $result['gmass_id'] ?? null,
                'processing_time_ms' => $result['processing_time_ms'] ?? 0
            ];
            
        } catch (Exception $e) {
            $this->logSend('gmass', $from, $to, $subject, false, null, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Send via Gmail API (legacy)
     */
    private function sendViaGmail($from, $to, $subject, $body, $options = []) {
        if (!$this->gmail) {
            throw new Exception("Gmail integration not available");
        }
        
        try {
            $result = $this->gmail->sendEmail($from, $to, $subject, $body);
            $this->logSend('gmail', $from, $to, $subject, true, $result);
            
            return [
                'success' => true,
                'method' => 'gmail',
                'message_id' => $result['message_id'],
                'thread_id' => $result['thread_id'] ?? null,
                'processing_time_ms' => $result['processing_time_ms'] ?? 0
            ];
            
        } catch (Exception $e) {
            $this->logSend('gmail', $from, $to, $subject, false, null, $e->getMessage());
            
            // Try GMass fallback if Gmail fails
            if ($this->shouldFallbackToGMass()) {
                $this->log("Gmail failed, attempting GMass fallback for {$to}");
                return $this->sendViaGMass($from, $to, $subject, $body, $options);
            }
            
            throw $e;
        }
    }
    
    /**
     * Smart routing based on sender, recipient, or content
     */
    private function sendViaSmartRouting($from, $to, $subject, $body, $options = []) {
        // Route based on various factors
        if ($this->shouldUseGMassFor($from, $to, $subject, $body)) {
            return $this->sendViaGMass($from, $to, $subject, $body, $options);
        } else {
            return $this->sendViaGmail($from, $to, $subject, $body, $options);
        }
    }
    
    /**
     * Parallel testing - send via both methods for comparison
     */
    private function sendViaParallelTesting($from, $to, $subject, $body, $options = []) {
        $results = [];
        
        // Send via GMass (primary)
        try {
            $gmassResult = $this->sendViaGMass($from, $to, $subject, $body, $options);
            $results['gmass'] = $gmassResult;
        } catch (Exception $e) {
            $results['gmass'] = ['success' => false, 'error' => $e->getMessage()];
        }
        
        // Don't send duplicate via Gmail in testing mode - just test connection
        if ($this->gmail) {
            try {
                $testResult = $this->gmail->testConnection();
                $results['gmail_available'] = $testResult['success'];
            } catch (Exception $e) {
                $results['gmail_available'] = false;
            }
        }
        
        // Return GMass result as primary
        return $results['gmass'];
    }
    
    /**
     * Determine which method to use based on various factors
     */
    private function shouldUseGMassFor($from, $to, $subject, $body) {
        // Use GMass for:
        // 1. Bulk campaigns (multiple recipients)
        // 2. When Gmail API has issues
        // 3. High-volume senders
        // 4. Specific domain patterns
        
        // Check if this is a bulk operation
        if (stripos($subject, 'campaign') !== false || stripos($subject, 'newsletter') !== false) {
            return true;
        }
        
        // Check sender reputation/volume
        $senderStats = $this->getSenderStats($from);
        if ($senderStats['daily_count'] > 50) {
            return true; // High volume sender
        }
        
        // Default to Gmail for low-volume, transactional emails
        return false;
    }
    
    /**
     * Get migration mode from settings
     */
    private function getMigrationMode() {
        $mode = $this->settings['email_migration_mode'] ?? self::MODE_GMASS_ONLY;
        
        // Auto-detect if GMass is configured
        if (empty($this->settings['gmass_api_key'])) {
            return self::MODE_GMAIL_ONLY;
        }
        
        return $mode;
    }
    
    /**
     * Check if we should use Gmail at all
     */
    private function shouldUseGmail() {
        $mode = $this->settings['email_migration_mode'] ?? self::MODE_GMASS_ONLY;
        return in_array($mode, [self::MODE_GMAIL_ONLY, self::MODE_HYBRID, self::MODE_TESTING]);
    }
    
    /**
     * Check if we should fallback to GMass when Gmail fails
     */
    private function shouldFallbackToGMass() {
        return !empty($this->settings['gmass_api_key']);
    }
    
    /**
     * Get sender statistics for routing decisions
     */
    private function getSenderStats($senderEmail) {
        $sql = "SELECT COUNT(*) as daily_count FROM email_log 
                WHERE sender_email = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
        
        $result = $this->db->fetchOne($sql, [$senderEmail]);
        
        return [
            'daily_count' => $result['daily_count'] ?? 0
        ];
    }
    
    /**
     * Load system settings
     */
    private function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    /**
     * Log email sending attempts
     */
    private function logSend($method, $from, $to, $subject, $success, $result = null, $error = null) {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $method,
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'success' => $success,
            'message_id' => $result['message_id'] ?? null,
            'processing_time' => $result['processing_time_ms'] ?? 0,
            'error' => $error
        ];
        
        $this->log(json_encode($logData));
    }
    
    /**
     * Log error messages
     */
    private function logError($message) {
        $this->log("ERROR: {$message}");
    }
    
    /**
     * Write to log file
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
?>