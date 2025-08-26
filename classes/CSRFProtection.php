<?php
/**
 * CSRF Protection Utility
 * Prevents Cross-Site Request Forgery attacks
 */

class CSRFProtection {
    private static $sessionKey = 'csrf_tokens';
    
    public static function generateToken($formName = 'default') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::$sessionKey][$formName] = $token;
        
        return $token;
    }
    
    public static function validateToken($token, $formName = 'default') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION[self::$sessionKey][$formName])) {
            return false;
        }
        
        $valid = hash_equals($_SESSION[self::$sessionKey][$formName], $token);
        
        // Remove token after validation (one-time use)
        unset($_SESSION[self::$sessionKey][$formName]);
        
        return $valid;
    }
    
    public static function getTokenField($formName = 'default') {
        $token = self::generateToken($formName);
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
    
    public static function validateRequest($formName = 'default') {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
        
        if (!self::validateToken($token, $formName)) {
            http_response_code(403);
            die('CSRF token validation failed. Please refresh the page and try again.');
        }
        
        return true;
    }
}
?>