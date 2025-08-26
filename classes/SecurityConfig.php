<?php
/**
 * Security Configuration
 * Application-wide security settings
 */

class SecurityConfig {
    
    // CSRF Protection
    const CSRF_ENABLED = true;
    const CSRF_TOKEN_LIFETIME = 3600; // 1 hour
    
    // Session Security
    const SESSION_TIMEOUT = 28800; // 8 hours
    const SESSION_REGENERATE_INTERVAL = 1800; // 30 minutes
    
    // Password Requirements
    const MIN_PASSWORD_LENGTH = 8;
    const REQUIRE_SPECIAL_CHARS = true;
    const REQUIRE_NUMBERS = true;
    
    // Rate Limiting
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOGIN_LOCKOUT_TIME = 900; // 15 minutes
    
    // File Upload Security
    const ALLOWED_FILE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    const MAX_FILE_SIZE = 5242880; // 5MB
    
    public static function initializeSecureSession() {
        if (session_status() === PHP_SESSION_NONE) {
            // Secure session configuration
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
            ini_set('session.cookie_samesite', 'Strict');
            
            session_start();
            
            // Regenerate session ID periodically
            if (!isset($_SESSION['last_regeneration'])) {
                $_SESSION['last_regeneration'] = time();
            } elseif (time() - $_SESSION['last_regeneration'] > self::SESSION_REGENERATE_INTERVAL) {
                session_regenerate_id(true);
                $_SESSION['last_regeneration'] = time();
            }
            
            // Check session timeout
            if (isset($_SESSION['last_activity']) && 
                (time() - $_SESSION['last_activity'] > self::SESSION_TIMEOUT)) {
                session_unset();
                session_destroy();
                session_start();
            }
            $_SESSION['last_activity'] = time();
        }
    }
    
    public static function sanitizeOutput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeOutput'], $data);
        }
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    
    public static function generateSecurePassword($length = 12) {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $password;
    }
}
?>