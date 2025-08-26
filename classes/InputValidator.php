<?php
/**
 * Input Validation Utility
 * Comprehensive input sanitization and validation
 */

class InputValidator {
    
    public static function sanitizeString($input, $maxLength = 255) {
        $input = trim($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        return substr($input, 0, $maxLength);
    }
    
    public static function sanitizeEmail($email) {
        $email = trim($email);
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }
    
    public static function sanitizeUrl($url) {
        $url = trim($url);
        $url = filter_var($url, FILTER_SANITIZE_URL);
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
    }
    
    public static function sanitizeInt($input, $min = null, $max = null) {
        $input = filter_var($input, FILTER_SANITIZE_NUMBER_INT);
        $input = filter_var($input, FILTER_VALIDATE_INT);
        
        if ($input === false) return false;
        
        if ($min !== null && $input < $min) return false;
        if ($max !== null && $input > $max) return false;
        
        return $input;
    }
    
    public static function sanitizeFloat($input, $min = null, $max = null) {
        $input = filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $input = filter_var($input, FILTER_VALIDATE_FLOAT);
        
        if ($input === false) return false;
        
        if ($min !== null && $input < $min) return false;
        if ($max !== null && $input > $max) return false;
        
        return $input;
    }
    
    public static function validateRequired($fields, $data) {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $missing[] = $field;
            }
        }
        return empty($missing) ? true : $missing;
    }
    
    public static function sanitizeJson($input) {
        if (!is_string($input)) return false;
        
        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) return false;
        
        return $decoded;
    }
    
    public static function preventSQLInjection($input) {
        // Remove common SQL injection patterns
        $patterns = [
            '/(\\bUNION\\b|\\bSELECT\\b|\\bINSERT\\b|\\bUPDATE\\b|\\bDELETE\\b|\\bDROP\\b|\\bCREATE\\b|\\bALTER\\b)/i',
            '/(\\bOR\\b.*\\b=\\b|\\bAND\\b.*\\b=\\b)/i',
            '/['";\\x00\\x1a]/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return false;
            }
        }
        
        return $input;
    }
}
?>