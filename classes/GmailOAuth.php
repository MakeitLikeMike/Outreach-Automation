<?php
/**
 * Gmail OAuth Integration - Placeholder
 * This is a minimal placeholder to prevent crashes
 */

class GmailOAuth {
    public function __construct($settings = []) {
        // Placeholder constructor
    }
    
    public function authenticate() {
        return false; // Not implemented
    }
    
    public function isAuthenticated() {
        return false; // Not implemented
    }
    
    public function sendEmail($to, $subject, $body, $from = null) {
        throw new Exception('Gmail OAuth not implemented - use GMass instead');
    }
}
?>