<?php

/**
 * TombaRateLimit - API Rate Limiting & Quota Management
 * 
 * Manages Tomba API rate limits and quota usage to prevent:
 * - Exceeding API request limits
 * - Running out of monthly quota
 * - API blocking due to rate limit violations
 * 
 * Features:
 * - Request rate limiting (requests per minute/hour)
 * - Monthly quota tracking
 * - Usage analytics and alerts
 * - Graceful degradation when limits reached
 */

class TombaRateLimit {
    private $db;
    
    // Tomba Pro Plan Limits (adjust based on your actual plan)
    const REQUESTS_PER_MINUTE = 60;  // 60 requests per minute
    const REQUESTS_PER_HOUR = 3600;  // 3600 requests per hour  
    const MONTHLY_QUOTA = 50000;     // 50,000 email searches per month
    const DAILY_QUOTA = 1667;       // Roughly 50k/30 days
    
    // Alert thresholds
    const QUOTA_WARNING_THRESHOLD = 0.8;  // Alert at 80% usage
    const QUOTA_CRITICAL_THRESHOLD = 0.95; // Critical at 95% usage
    
    public function __construct() {
        require_once __DIR__ . '/../config/database.php';
        try {
            $this->db = new Database();
        } catch (Exception $e) {
            error_log("TombaRateLimit database connection error: " . $e->getMessage());
            $this->db = null;
        }
    }
    
    /**
     * Check if we can make a new API request
     */
    public function canMakeRequest() {
        try {
            // Check minute limit
            if (!$this->checkMinuteLimit()) {
                $this->logWarning("Minute rate limit reached");
                return false;
            }
            
            // Check hourly limit
            if (!$this->checkHourlyLimit()) {
                $this->logWarning("Hourly rate limit reached");
                return false;
            }
            
            // Check daily quota
            if (!$this->checkDailyQuota()) {
                $this->logWarning("Daily quota reached");
                return false;
            }
            
            // Check monthly quota
            if (!$this->checkMonthlyQuota()) {
                $this->logWarning("Monthly quota reached");
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->logError("Rate limit check failed: " . $e->getMessage());
            // Fail safe - don't allow requests if we can't check limits
            return false;
        }
    }
    
    /**
     * Record a new API request
     */
    public function recordRequest($creditsUsed = 1) {
        if (!$this->db) {
            // Silently fail if no database connection - don't spam logs
            return false;
        }
        
        try {
            // Record the API request in database
            $this->db->execute("
                INSERT INTO tomba_api_usage (request_time, credits_used, created_at) 
                VALUES (NOW(), ?, NOW())
            ", [$creditsUsed]);
            
            return true;
        } catch (Exception $e) {
            // Database error - silently fail to avoid breaking the process
            return false;
        }
    }
    
    /**
     * Record a failed API request
     */
    public function recordFailedRequest($errorMessage, $statusCode = null) {
        if (!$this->db) {
            return false;
        }
        
        try {
            $sql = "
                INSERT INTO api_usage_tracking (
                    api_service, endpoint, request_type, status_code,
                    success, error_message, created_at
                ) VALUES ('tomba', 'email-finder', 'email_search', ?, 0, ?, NOW())
            ";
            
            $this->db->execute($sql, [$statusCode, $errorMessage]);
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check minute rate limit (60 requests per minute)
     */
    private function checkMinuteLimit() {
        if (!$this->db) {
            return true; // Allow if can't check (fail-open for availability)
        }
        
        try {
            $sql = "
                SELECT COUNT(*) as request_count
                FROM api_usage_tracking 
                WHERE api_service = 'tomba' 
                    AND request_type = 'email_search'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ";
            
            $stmt = $this->db->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['request_count'] < self::REQUESTS_PER_MINUTE;
        } catch (Exception $e) {
            return true; // Allow if can't check
        }
    }
    
    /**
     * Check hourly rate limit
     */
    private function checkHourlyLimit() {
        if (!$this->db) {
            return true; // Allow if can't check
        }
        
        try {
            $sql = "
                SELECT COUNT(*) as request_count
                FROM api_usage_tracking 
                WHERE api_service = 'tomba' 
                    AND request_type = 'email_search'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ";
            
            $stmt = $this->db->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['request_count'] < self::REQUESTS_PER_HOUR;
        } catch (Exception $e) {
            return true; // Allow if can't check
        }
    }
    
    /**
     * Check daily quota
     */
    private function checkDailyQuota() {
        if (!$this->db) {
            return true; // Allow if can't check
        }
        
        try {
            $sql = "
                SELECT SUM(credits_used) as credits_used
                FROM api_usage_tracking 
                WHERE api_service = 'tomba' 
                    AND request_type = 'email_search'
                    AND success = 1
                    AND DATE(created_at) = CURDATE()
            ";
            
            $stmt = $this->db->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $todaysUsage = $result['credits_used'] ?? 0;
            return $todaysUsage < self::DAILY_QUOTA;
        } catch (Exception $e) {
            return true; // Allow if can't check
        }
    }
    
    /**
     * Check monthly quota
     */
    private function checkMonthlyQuota() {
        if (!$this->db) {
            return true; // Allow if can't check
        }
        
        try {
            $sql = "
                SELECT SUM(credits_used) as credits_used
                FROM api_usage_tracking 
                WHERE api_service = 'tomba' 
                    AND request_type = 'email_search'
                    AND success = 1
                    AND YEAR(created_at) = YEAR(CURDATE())
                    AND MONTH(created_at) = MONTH(CURDATE())
            ";
            
            $stmt = $this->db->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $monthlyUsage = $result['credits_used'] ?? 0;
            return $monthlyUsage < self::MONTHLY_QUOTA;
        } catch (Exception $e) {
            return true; // Allow if can't check
        }
    }
    
    /**
     * Get current usage statistics
     */
    public function getUsageStatistics() {
        $stats = [];
        
        // Today's usage
        $sql = "
            SELECT 
                COUNT(*) as requests_today,
                SUM(credits_used) as credits_today,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_requests,
                ROUND(AVG(response_time_ms), 2) as avg_response_time
            FROM api_usage_tracking 
            WHERE api_service = 'tomba' 
                AND request_type = 'email_search'
                AND DATE(created_at) = CURDATE()
        ";
        
        $stmt = $this->db->query($sql);
        $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // This month's usage
        $sql = "
            SELECT 
                COUNT(*) as requests_month,
                SUM(credits_used) as credits_month
            FROM api_usage_tracking 
            WHERE api_service = 'tomba' 
                AND request_type = 'email_search'
                AND success = 1
                AND YEAR(created_at) = YEAR(CURDATE())
                AND MONTH(created_at) = MONTH(CURDATE())
        ";
        
        $stmt = $this->db->query($sql);
        $monthStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // This hour's usage
        $sql = "
            SELECT COUNT(*) as requests_hour
            FROM api_usage_tracking 
            WHERE api_service = 'tomba' 
                AND request_type = 'email_search'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ";
        
        $stmt = $this->db->query($sql);
        $hourStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // This minute's usage
        $sql = "
            SELECT COUNT(*) as requests_minute
            FROM api_usage_tracking 
            WHERE api_service = 'tomba' 
                AND request_type = 'email_search'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ";
        
        $stmt = $this->db->query($sql);
        $minuteStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'current_limits' => [
                'requests_per_minute' => self::REQUESTS_PER_MINUTE,
                'requests_per_hour' => self::REQUESTS_PER_HOUR,
                'daily_quota' => self::DAILY_QUOTA,
                'monthly_quota' => self::MONTHLY_QUOTA
            ],
            'usage' => [
                'minute' => [
                    'requests' => $minuteStats['requests_minute'] ?? 0,
                    'limit' => self::REQUESTS_PER_MINUTE,
                    'percentage' => round(($minuteStats['requests_minute'] ?? 0) / self::REQUESTS_PER_MINUTE * 100, 1)
                ],
                'hour' => [
                    'requests' => $hourStats['requests_hour'] ?? 0,
                    'limit' => self::REQUESTS_PER_HOUR,
                    'percentage' => round(($hourStats['requests_hour'] ?? 0) / self::REQUESTS_PER_HOUR * 100, 1)
                ],
                'today' => [
                    'requests' => $todayStats['requests_today'] ?? 0,
                    'credits' => $todayStats['credits_today'] ?? 0,
                    'successful' => $todayStats['successful_requests'] ?? 0,
                    'limit' => self::DAILY_QUOTA,
                    'percentage' => round(($todayStats['credits_today'] ?? 0) / self::DAILY_QUOTA * 100, 1)
                ],
                'month' => [
                    'requests' => $monthStats['requests_month'] ?? 0,
                    'credits' => $monthStats['credits_month'] ?? 0,
                    'limit' => self::MONTHLY_QUOTA,
                    'percentage' => round(($monthStats['credits_month'] ?? 0) / self::MONTHLY_QUOTA * 100, 1)
                ]
            ],
            'performance' => [
                'avg_response_time_today' => $todayStats['avg_response_time'] ?? 0,
                'success_rate_today' => $todayStats['requests_today'] > 0 ? 
                    round(($todayStats['successful_requests'] ?? 0) / $todayStats['requests_today'] * 100, 1) : 0
            ]
        ];
    }
    
    /**
     * Check if we need to send usage alerts
     */
    private function checkAndSendAlerts() {
        $stats = $this->getUsageStatistics();
        
        // Check monthly quota alerts
        $monthlyPercentage = $stats['usage']['month']['percentage'];
        
        if ($monthlyPercentage >= self::QUOTA_CRITICAL_THRESHOLD * 100) {
            $this->sendQuotaAlert('critical', $monthlyPercentage, $stats['usage']['month']['credits']);
        } elseif ($monthlyPercentage >= self::QUOTA_WARNING_THRESHOLD * 100) {
            $this->sendQuotaAlert('warning', $monthlyPercentage, $stats['usage']['month']['credits']);
        }
        
        // Check daily quota alerts
        $dailyPercentage = $stats['usage']['today']['percentage'];
        
        if ($dailyPercentage >= 90) {
            $this->logWarning("Daily quota at {$dailyPercentage}% ({$stats['usage']['today']['credits']} / " . self::DAILY_QUOTA . ")");
        }
    }
    
    /**
     * Send quota usage alert
     */
    private function sendQuotaAlert($level, $percentage, $creditsUsed) {
        $message = "Tomba API quota $level alert: {$percentage}% used ($creditsUsed / " . self::MONTHLY_QUOTA . " credits)";
        
        // Log the alert
        if ($level === 'critical') {
            $this->logError($message);
        } else {
            $this->logWarning($message);
        }
        
        // Here you could add email notifications, Slack alerts, etc.
        // For now, we'll just log it
        
        // Prevent duplicate alerts by storing alert timestamps
        $this->recordQuotaAlert($level, $percentage, $creditsUsed);
    }
    
    /**
     * Record quota alert to prevent duplicates
     */
    private function recordQuotaAlert($level, $percentage, $creditsUsed) {
        // Check if we already sent this level of alert today
        $sql = "
            SELECT COUNT(*) as alert_count
            FROM api_usage_tracking 
            WHERE api_service = 'tomba' 
                AND request_type = 'quota_alert'
                AND error_message LIKE ?
                AND DATE(created_at) = CURDATE()
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(["%$level%"]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['alert_count'] == 0) {
            // Record the alert
            $sql = "
                INSERT INTO api_usage_tracking (
                    api_service, endpoint, request_type, credits_used,
                    success, error_message, created_at
                ) VALUES ('tomba', 'quota-monitor', 'quota_alert', ?, 1, ?, NOW())
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$creditsUsed, "Quota $level alert: {$percentage}%"]);
        }
    }
    
    /**
     * Get time until rate limit resets
     */
    public function getTimeUntilReset($limitType = 'minute') {
        switch ($limitType) {
            case 'minute':
                $nextMinute = strtotime(date('Y-m-d H:i:00', strtotime('+1 minute')));
                return $nextMinute - time();
                
            case 'hour':
                $nextHour = strtotime(date('Y-m-d H:00:00', strtotime('+1 hour')));
                return $nextHour - time();
                
            case 'day':
                $nextDay = strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')));
                return $nextDay - time();
                
            case 'month':
                $nextMonth = strtotime(date('Y-m-01 00:00:00', strtotime('+1 month')));
                return $nextMonth - time();
                
            default:
                return 0;
        }
    }
    
    /**
     * Get recommended batch size based on current usage
     */
    public function getRecommendedBatchSize($maxBatchSize = 10) {
        $stats = $this->getUsageStatistics();
        
        // Calculate available requests based on most restrictive limit
        $minuteAvailable = self::REQUESTS_PER_MINUTE - $stats['usage']['minute']['requests'];
        $hourlyAvailable = self::REQUESTS_PER_HOUR - $stats['usage']['hour']['requests'];
        $dailyAvailable = self::DAILY_QUOTA - $stats['usage']['today']['credits'];
        
        // Use the most restrictive limit
        $availableRequests = min($minuteAvailable, $hourlyAvailable, $dailyAvailable, $maxBatchSize);
        
        // Ensure we don't return negative or zero
        return max(1, $availableRequests);
    }
    
    // Logging methods
    private function logInfo($message) {
        error_log("[TombaRateLimit][INFO] " . $message);
    }
    
    private function logWarning($message) {
        error_log("[TombaRateLimit][WARNING] " . $message);
    }
    
    private function logError($message) {
        error_log("[TombaRateLimit][ERROR] " . $message);
    }
}
?>