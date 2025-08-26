<?php
require_once 'SystemLogger.php';

class TokenRefreshManager {
    private $db;
    private $logger;
    private $lockFile;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->logger = new SystemLogger();
        $this->lockFile = __DIR__ . '/../logs/token_refresh.lock';
    }
    
    /**
     * Safely refresh Gmail token with locking mechanism
     */
    public function refreshGmailToken($email) {
        $lockAcquired = false;
        
        try {
            // Acquire lock to prevent concurrent refresh attempts
            $lockAcquired = $this->acquireLock($email);
            if (!$lockAcquired) {
                $this->logger->logWarning('TOKEN_REFRESH', 'Could not acquire lock for token refresh', [
                    'email' => $email
                ]);
                return false;
            }
            
            // Check if token was refreshed by another process while waiting for lock
            $tokenData = $this->getTokenData($email);
            if (!$tokenData) {
                throw new Exception("No token data found for email: {$email}");
            }
            
            // Check if token is still expired (another process might have refreshed it)
            if (time() < (intval($tokenData['expires_at']) - 300)) { // 5 minute buffer
                $this->logger->logInfo('TOKEN_REFRESH', 'Token was already refreshed by another process', [
                    'email' => $email,
                    'expires_at' => $tokenData['expires_at']
                ]);
                return true;
            }
            
            $this->logger->logTokenRefresh('STARTING_REFRESH', [
                'email' => $email,
                'current_expires_at' => $tokenData['expires_at'],
                'time_until_expiry' => intval($tokenData['expires_at']) - time()
            ]);
            
            // Perform the actual refresh
            $refreshResult = $this->performTokenRefresh($tokenData);
            
            if ($refreshResult['success']) {
                // Update token in database
                $this->updateTokenData($email, $refreshResult);
                
                $this->logger->logTokenRefresh('REFRESH_SUCCESS', [
                    'email' => $email,
                    'new_expires_at' => $refreshResult['expires_at']
                ]);
                
                return true;
            } else {
                $this->handleRefreshFailure($email, $refreshResult['error']);
                return false;
            }
            
        } catch (Exception $e) {
            $this->logger->logError('TOKEN_REFRESH', 'Token refresh failed with exception', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        } finally {
            if ($lockAcquired) {
                $this->releaseLock($email);
            }
        }
    }
    
    private function acquireLock($email, $timeout = 30) {
        $lockFile = $this->lockFile . '.' . md5($email);
        $start = time();
        
        while (time() - $start < $timeout) {
            if (!file_exists($lockFile) || (time() - filemtime($lockFile)) > 300) { // 5 min max lock time
                if (file_put_contents($lockFile, getmypid()) !== false) {
                    return true;
                }
            }
            usleep(500000); // Wait 0.5 seconds
        }
        
        return false;
    }
    
    private function releaseLock($email) {
        $lockFile = $this->lockFile . '.' . md5($email);
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }
    
    private function getTokenData($email) {
        $sql = "SELECT access_token, refresh_token, expires_at FROM gmail_tokens WHERE email = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$email]);
        return $stmt->fetch();
    }
    
    private function performTokenRefresh($tokenData) {
        $client_id = $this->getSystemSetting('gmail_client_id');
        $client_secret = $this->getSystemSetting('gmail_client_secret');
        
        if (!$client_id || !$client_secret) {
            throw new Exception('Gmail OAuth credentials not configured');
        }
        
        $postData = [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $tokenData['refresh_token'],
            'grant_type' => 'refresh_token'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return ['success' => false, 'error' => "cURL error: {$curlError}"];
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode === 200 && isset($responseData['access_token'])) {
            return [
                'success' => true,
                'access_token' => $responseData['access_token'],
                'expires_at' => time() + ($responseData['expires_in'] ?? 3600),
                'refresh_token' => $responseData['refresh_token'] ?? $tokenData['refresh_token'] // Sometimes not returned
            ];
        } else {
            $error = $responseData['error'] ?? 'Unknown error';
            $errorDescription = $responseData['error_description'] ?? '';
            return [
                'success' => false, 
                'error' => "{$error}: {$errorDescription}",
                'http_code' => $httpCode,
                'response' => $response
            ];
        }
    }
    
    private function updateTokenData($email, $refreshResult) {
        $sql = "UPDATE gmail_tokens SET access_token = ?, expires_at = ?, updated_at = NOW() WHERE email = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $refreshResult['access_token'],
            $refreshResult['expires_at'],
            $email
        ]);
        
        // If new refresh token was provided, update it too
        if (isset($refreshResult['refresh_token']) && $refreshResult['refresh_token'] !== null) {
            $refreshSql = "UPDATE gmail_tokens SET refresh_token = ? WHERE email = ?";
            $refreshStmt = $this->db->prepare($refreshSql);
            $refreshStmt->execute([$refreshResult['refresh_token'], $email]);
        }
    }
    
    private function handleRefreshFailure($email, $error) {
        $this->logger->logError('TOKEN_REFRESH', 'Token refresh failed', [
            'email' => $email,
            'error' => $error
        ]);
        
        // If invalid_grant error, mark token as needing re-authorization
        if (strpos($error, 'invalid_grant') !== false) {
            $this->markTokenForReauth($email, $error);
        }
    }
    
    private function markTokenForReauth($email, $error) {
        // Create a flag in system_settings to indicate this email needs re-auth
        $settingKey = "gmail_needs_reauth_{$email}";
        $this->setSystemSetting($settingKey, json_encode([
            'email' => $email,
            'error' => $error,
            'timestamp' => time(),
            'status' => 'needs_reauth'
        ]));
        
        $this->logger->logWarning('TOKEN_REFRESH', 'Marked email for re-authorization', [
            'email' => $email,
            'error' => $error,
            'setting_key' => $settingKey
        ]);
    }
    
    private function getSystemSetting($key) {
        $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : null;
    }
    
    private function setSystemSetting($key, $value) {
        $sql = "INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$key, $value, $value]);
    }
    
    /**
     * Check if any tokens need refresh and refresh them proactively
     */
    public function refreshExpiredTokens() {
        $sql = "SELECT email, expires_at FROM gmail_tokens WHERE expires_at <= ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([time() + 600]); // Refresh tokens expiring in next 10 minutes
        $expiredTokens = $stmt->fetchAll();
        
        $refreshedCount = 0;
        foreach ($expiredTokens as $token) {
            if ($this->refreshGmailToken($token['email'])) {
                $refreshedCount++;
            }
        }
        
        if ($refreshedCount > 0) {
            $this->logger->logInfo('TOKEN_REFRESH', 'Proactive token refresh completed', [
                'total_expired' => count($expiredTokens),
                'successfully_refreshed' => $refreshedCount
            ]);
        }
        
        return $refreshedCount;
    }
}
?>