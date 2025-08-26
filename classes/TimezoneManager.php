<?php
/**
 * Timezone Manager - Handle user timezone detection and conversion
 */
class TimezoneManager {
    private static $userTimezone = null;
    private static $defaultTimezone = 'Asia/Manila'; // Default to Philippines Time
    
    /**
     * Get user's timezone (from session, cookie, or browser detection)
     */
    public static function getUserTimezone() {
        if (self::$userTimezone) {
            return self::$userTimezone;
        }
        
        // Check session first
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['user_timezone'])) {
            self::$userTimezone = $_SESSION['user_timezone'];
            return self::$userTimezone;
        }
        
        // Check cookie
        if (isset($_COOKIE['user_timezone'])) {
            self::$userTimezone = $_COOKIE['user_timezone'];
            $_SESSION['user_timezone'] = self::$userTimezone;
            return self::$userTimezone;
        }
        
        // Default timezone
        self::$userTimezone = self::$defaultTimezone;
        return self::$userTimezone;
    }
    
    /**
     * Set user's timezone
     */
    public static function setUserTimezone($timezone) {
        if (!in_array($timezone, timezone_identifiers_list())) {
            throw new Exception("Invalid timezone: $timezone");
        }
        
        self::$userTimezone = $timezone;
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['user_timezone'] = $timezone;
        
        // Set cookie for 30 days
        setcookie('user_timezone', $timezone, time() + (30 * 24 * 60 * 60), '/');
    }
    
    /**
     * Convert database timestamp to user timezone
     */
    public static function toUserTime($dbTimestamp, $format = 'M j, Y g:i A') {
        if (empty($dbTimestamp)) {
            return '';
        }
        
        try {
            // Database times are in Asia/Singapore timezone
            $dbTz = new DateTimeZone('Asia/Singapore');
            $userTz = new DateTimeZone(self::getUserTimezone());
            
            $dt = new DateTime($dbTimestamp, $dbTz);
            $dt->setTimezone($userTz);
            
            return $dt->format($format);
            
        } catch (Exception $e) {
            // Fallback to original timestamp
            return date($format, strtotime($dbTimestamp));
        }
    }
    
    /**
     * Get current time in user's timezone
     */
    public static function getUserCurrentTime($format = 'Y-m-d H:i:s') {
        try {
            $userTz = new DateTimeZone(self::getUserTimezone());
            $dt = new DateTime('now', $userTz);
            return $dt->format($format);
        } catch (Exception $e) {
            return date($format);
        }
    }
    
    /**
     * Convert user time to database timezone for storage
     */
    public static function toDatabaseTime($userTime) {
        try {
            $userTz = new DateTimeZone(self::getUserTimezone());
            $dbTz = new DateTimeZone('Asia/Singapore');
            
            $dt = new DateTime($userTime, $userTz);
            $dt->setTimezone($dbTz);
            
            return $dt->format('Y-m-d H:i:s');
            
        } catch (Exception $e) {
            return $userTime;
        }
    }
    
    /**
     * Get timezone offset for JavaScript
     */
    public static function getTimezoneOffset() {
        try {
            $userTz = new DateTimeZone(self::getUserTimezone());
            $dt = new DateTime('now', $userTz);
            return $dt->getOffset() / 60; // Return offset in minutes
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get common timezones for selection
     */
    public static function getCommonTimezones() {
        return [
            'Asia/Manila' => 'Philippines Time (PHT)',
            'Asia/Singapore' => 'Singapore (SGT)',
            'Asia/Tokyo' => 'Tokyo (JST)',
            'Asia/Shanghai' => 'Shanghai (CST)',
            'Asia/Kolkata' => 'India (IST)',
            'America/New_York' => 'Eastern Time (ET)',
            'America/Chicago' => 'Central Time (CT)', 
            'America/Denver' => 'Mountain Time (MT)',
            'America/Los_Angeles' => 'Pacific Time (PT)',
            'America/Phoenix' => 'Arizona Time (MST)',
            'Europe/London' => 'London (GMT/BST)',
            'Europe/Paris' => 'Paris (CET/CEST)',
            'Europe/Berlin' => 'Berlin (CET/CEST)',
            'Australia/Sydney' => 'Sydney (AEST/AEDT)',
            'Pacific/Auckland' => 'Auckland (NZST/NZDT)',
            'UTC' => 'UTC (Coordinated Universal Time)'
        ];
    }
    
    /**
     * Auto-detect timezone from JavaScript
     */
    public static function getTimezoneDetectionScript() {
        return "
        <script>
        // Auto-detect user timezone
        if (!document.cookie.includes('user_timezone=')) {
            var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (timezone) {
                // Send timezone to server
                fetch(window.location.origin + '/set_timezone.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({timezone: timezone})
                }).then(function() {
                    // Reload page to apply timezone
                    if (!window.location.search.includes('tz_set')) {
                        window.location.href = window.location.href + (window.location.search ? '&' : '?') + 'tz_set=1';
                    }
                });
            }
        }
        </script>";
    }
}
?>