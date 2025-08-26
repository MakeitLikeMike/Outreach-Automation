<?php
/**
 * Secure Credential Manager
 * Handles encryption/decryption of sensitive credentials
 * Protects GMass API keys and IMAP passwords
 */
require_once __DIR__ . '/../config/database.php';

class SecureCredentialManager {
    private $db;
    private $encryptionKey;
    private $cipher = 'AES-256-CBC';
    
    public function __construct() {
        $this->db = new Database();
        $this->initializeEncryptionKey();
    }
    
    /**
     * Store encrypted credential
     */
    public function storeCredential($key, $value) {
        if (empty($value)) {
            throw new Exception("Credential value cannot be empty");
        }
        
        $encryptedValue = $this->encrypt($value);
        
        $sql = "INSERT INTO system_settings (setting_key, setting_value, is_encrypted) 
                VALUES (?, ?, 1) 
                ON DUPLICATE KEY UPDATE setting_value = ?, is_encrypted = 1";
        
        $this->db->execute($sql, [$key, $encryptedValue, $encryptedValue]);
        
        // Log credential update (without value)
        $this->logSecurityEvent("credential_stored", $key);
        
        return true;
    }
    
    /**
     * Retrieve and decrypt credential
     */
    public function getCredential($key) {
        $sql = "SELECT setting_value, is_encrypted FROM system_settings WHERE setting_key = ?";
        $result = $this->db->fetchOne($sql, [$key]);
        
        if (!$result) {
            return null;
        }
        
        if ($result['is_encrypted'] == 1) {
            return $this->decrypt($result['setting_value']);
        }
        
        return $result['setting_value'];
    }
    
    /**
     * Test credential by attempting to decrypt
     */
    public function testCredential($key) {
        try {
            $value = $this->getCredential($key);
            return !empty($value);
        } catch (Exception $e) {
            $this->logSecurityEvent("credential_test_failed", $key, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Encrypt sensitive data
     */
    private function encrypt($data) {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        $encrypted = openssl_encrypt($data, $this->cipher, $this->encryptionKey, 0, $iv);
        
        if ($encrypted === false) {
            throw new Exception("Encryption failed");
        }
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     */
    private function decrypt($encryptedData) {
        $data = base64_decode($encryptedData);
        
        if ($data === false) {
            throw new Exception("Invalid encrypted data format");
        }
        
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        
        $decrypted = openssl_decrypt($encrypted, $this->cipher, $this->encryptionKey, 0, $iv);
        
        if ($decrypted === false) {
            throw new Exception("Decryption failed");
        }
        
        return $decrypted;
    }
    
    /**
     * Initialize or retrieve encryption key
     */
    private function initializeEncryptionKey() {
        // Try to get existing key from environment or config
        $keyFile = __DIR__ . '/../config/.encryption_key';
        
        if (file_exists($keyFile)) {
            $this->encryptionKey = file_get_contents($keyFile);
        } else {
            // Generate new encryption key
            $this->encryptionKey = random_bytes(32); // 256-bit key
            
            // Store key securely
            if (!file_put_contents($keyFile, $this->encryptionKey)) {
                throw new Exception("Cannot store encryption key");
            }
            
            // Protect key file
            if (function_exists('chmod')) {
                chmod($keyFile, 0600); // Owner read/write only
            }
            
            $this->logSecurityEvent("encryption_key_generated", "system");
        }
    }
    
    /**
     * Rotate encryption key (for security maintenance)
     */
    public function rotateEncryptionKey() {
        // Get all encrypted credentials
        $encryptedCredentials = $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM system_settings WHERE is_encrypted = 1"
        );
        
        // Decrypt with old key
        $decryptedCredentials = [];
        foreach ($encryptedCredentials as $credential) {
            try {
                $decryptedCredentials[$credential['setting_key']] = $this->decrypt($credential['setting_value']);
            } catch (Exception $e) {
                throw new Exception("Failed to decrypt credential during key rotation: " . $credential['setting_key']);
            }
        }
        
        // Generate new key
        $oldKey = $this->encryptionKey;
        $this->encryptionKey = random_bytes(32);
        
        // Store new key
        $keyFile = __DIR__ . '/../config/.encryption_key';
        if (!file_put_contents($keyFile, $this->encryptionKey)) {
            $this->encryptionKey = $oldKey; // Restore old key
            throw new Exception("Failed to store new encryption key");
        }
        
        // Re-encrypt all credentials with new key
        foreach ($decryptedCredentials as $key => $value) {
            $this->storeCredential($key, $value);
        }
        
        $this->logSecurityEvent("encryption_key_rotated", "system");
        
        return true;
    }
    
    /**
     * Validate credential strength
     */
    public function validateCredentialStrength($type, $value) {
        $issues = [];
        
        switch ($type) {
            case 'gmass_api_key':
                if (strlen($value) < 20) {
                    $issues[] = "API key appears too short";
                }
                if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $value)) {
                    $issues[] = "API key contains invalid characters";
                }
                break;
                
            case 'imap_password':
                if (strlen($value) < 16) {
                    $issues[] = "IMAP password should be 16 characters (Gmail App Password)";
                }
                if (preg_match('/\s/', $value)) {
                    $issues[] = "IMAP password should not contain spaces";
                }
                break;
                
            case 'imap_email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $issues[] = "Invalid email format";
                }
                if (!strpos($value, '@gmail.com')) {
                    $issues[] = "Only Gmail addresses are supported for IMAP";
                }
                break;
        }
        
        return [
            'valid' => empty($issues),
            'issues' => $issues
        ];
    }
    
    /**
     * Audit credential access
     */
    public function auditCredentialAccess($key, $action = 'accessed') {
        $this->logSecurityEvent($action, $key);
    }
    
    /**
     * Log security events
     */
    private function logSecurityEvent($event, $key, $details = null) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'credential_key' => $key,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'details' => $details
        ];
        
        // Store in security log table
        try {
            $this->db->execute(
                "INSERT INTO security_log (event_type, credential_key, ip_address, user_agent, details, created_at) 
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$event, $key, $logEntry['ip_address'], $logEntry['user_agent'], $details]
            );
        } catch (Exception $e) {
            // If security log table doesn't exist, create it
            $this->createSecurityLogTable();
            
            // Retry logging
            try {
                $this->db->execute(
                    "INSERT INTO security_log (event_type, credential_key, ip_address, user_agent, details, created_at) 
                     VALUES (?, ?, ?, ?, ?, NOW())",
                    [$event, $key, $logEntry['ip_address'], $logEntry['user_agent'], $details]
                );
            } catch (Exception $e2) {
                // Log to file as fallback
                $logFile = __DIR__ . '/../logs/security.log';
                $logMessage = json_encode($logEntry) . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
            }
        }
    }
    
    /**
     * Create security log table if it doesn't exist
     */
    private function createSecurityLogTable() {
        $sql = "CREATE TABLE IF NOT EXISTS security_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(100) NOT NULL,
            credential_key VARCHAR(100),
            ip_address VARCHAR(45),
            user_agent TEXT,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_created_at (created_at)
        )";
        
        $this->db->execute($sql);
    }
    
    /**
     * Get security audit report
     */
    public function getSecurityAuditReport($days = 7) {
        $sql = "SELECT 
                    event_type,
                    credential_key,
                    COUNT(*) as event_count,
                    MAX(created_at) as last_event,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM security_log 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY event_type, credential_key
                ORDER BY last_event DESC";
        
        return $this->db->fetchAll($sql, [$days]);
    }
    
    /**
     * Check if credentials need rotation
     */
    public function checkCredentialRotationNeeded() {
        // Check age of credentials
        $sql = "SELECT setting_key, updated_at FROM system_settings 
                WHERE is_encrypted = 1 AND updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY)";
        
        $oldCredentials = $this->db->fetchAll($sql);
        
        return [
            'rotation_needed' => !empty($oldCredentials),
            'old_credentials' => $oldCredentials,
            'recommendation' => !empty($oldCredentials) ? 'Rotate credentials older than 90 days' : 'No rotation needed'
        ];
    }
    
    /**
     * Secure credential cleanup
     */
    public function secureCleanup() {
        // Clear any temporary credential files
        $tempFiles = glob(__DIR__ . '/../temp/credential_*');
        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                // Overwrite file with random data before deletion
                $fileSize = filesize($file);
                file_put_contents($file, random_bytes($fileSize));
                unlink($file);
            }
        }
        
        // Clear old security logs (keep 30 days)
        $this->db->execute(
            "DELETE FROM security_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        $this->logSecurityEvent("secure_cleanup", "system");
    }
}
?>