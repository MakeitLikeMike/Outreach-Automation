<?php
/**
 * DataForSEO Rate Limiting System
 * Extends TombaRateLimit to provide DataForSEO-specific rate limiting
 */

require_once __DIR__ . '/TombaRateLimit.php';

class DataForSEORateLimit extends TombaRateLimit {
    
    // DataForSEO API Limits
    const REQUESTS_PER_MINUTE = 30;        // 30 requests per minute
    const REQUESTS_PER_HOUR = 2000;        // 2000 requests per hour  
    const MONTHLY_QUOTA = 25000;           // Default monthly quota (varies by plan)
    const DAILY_QUOTA = 1000;              // Daily quota (varies by plan)
    
    // Cost per request (in credits)
    const BACKLINKS_COST = 1;
    const DOMAIN_ANALYSIS_COST = 1;
    const COMPETITOR_ANALYSIS_COST = 2;
    
    protected $db;
    
    /**
     * Constructor - Initialize database connection
     */
    public function __construct() {
        require_once __DIR__ . '/../config/database.php';
        $this->db = new Database();
    }
    
    /**
     * Get service name for database storage
     */
    protected function getServiceName() {
        return 'dataforseo';
    }
    
    /**
     * Get current rate limit configuration
     */
    public function getRateLimits() {
        return [
            'minute' => [
                'limit' => self::REQUESTS_PER_MINUTE,
                'window' => 60
            ],
            'hour' => [
                'limit' => self::REQUESTS_PER_HOUR,
                'window' => 3600
            ],
            'day' => [
                'limit' => self::DAILY_QUOTA,
                'window' => 86400
            ],
            'month' => [
                'limit' => self::MONTHLY_QUOTA,
                'window' => 2592000 // 30 days
            ]
        ];
    }
    
    /**
     * Check if we can make a DataForSEO API request
     * @param string $endpoint - The API endpoint being called
     * @param int $expectedCost - Expected credit cost of the request
     * @return bool
     */
    public function canMakeRequest($endpoint = 'default', $expectedCost = 1) {
        $limits = $this->getRateLimits();
        
        foreach ($limits as $period => $config) {
            $usage = $this->getUsageForPeriod($period, $config['window']);
            
            if ($usage['requests'] >= $config['limit']) {
                $this->logRateLimitHit($period, $usage['requests'], $config['limit']);
                return false;
            }
        }
        
        // Check if we have enough credits for this request
        if (!$this->hasEnoughCredits($expectedCost)) {
            $this->logInsufficientCredits($expectedCost);
            return false;
        }
        
        return true;
    }
    
    /**
     * Record a successful API request (compatible with parent class)
     * @param int $creditsUsed
     */
    public function recordRequest($creditsUsed = 1) {
        // Call parent implementation first
        parent::recordRequest($creditsUsed);
    }
    
    /**
     * Record a detailed API request with endpoint and data
     * @param string $endpoint
     * @param int $creditsUsed
     * @param array $requestData
     * @param array $responseData
     */
    public function recordDetailedRequest($endpoint, $creditsUsed = 1, $requestData = [], $responseData = []) {
        try {
            $sql = "INSERT INTO api_logs (
                api_service, 
                endpoint, 
                method, 
                request_data, 
                response_data, 
                status_code, 
                credits_used, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $this->db->execute($sql, [
                $this->getServiceName(),
                $endpoint,
                'POST',
                json_encode($requestData),
                json_encode($responseData),
                200,
                $creditsUsed
            ]);
            
            // Update usage statistics
            $this->updateUsageStats($creditsUsed);
            
        } catch (Exception $e) {
            error_log("Failed to record DataForSEO request: " . $e->getMessage());
        }
    }
    
    /**
     * Get time to wait before next request (in seconds)
     * @param string $limitType
     * @return int
     */
    public function getTimeUntilReset($limitType = 'minute') {
        $limits = $this->getRateLimits();
        $shortestWait = 0;
        
        foreach ($limits as $period => $config) {
            $usage = $this->getUsageForPeriod($period, $config['window']);
            
            if ($usage['requests'] >= $config['limit']) {
                $timeUntilReset = $config['window'] - (time() - $usage['oldest_request']);
                $shortestWait = max($shortestWait, $timeUntilReset);
            }
        }
        
        return max($shortestWait, 0);
    }
    
    /**
     * Get usage statistics for different time periods
     * @return array
     */
    public function getUsageStatistics() {
        $stats = [];
        $limits = $this->getRateLimits();
        
        foreach ($limits as $period => $config) {
            $usage = $this->getUsageForPeriod($period, $config['window']);
            
            $stats[$period] = [
                'requests' => $usage['requests'],
                'credits' => $usage['credits'],
                'limit' => $config['limit'],
                'percentage' => round(($usage['requests'] / $config['limit']) * 100, 1),
                'time_until_reset' => $config['window'] - (time() - $usage['oldest_request'])
            ];
        }
        
        return $stats;
    }
    
    /**
     * Check if we have enough credits for a request
     * @param int $requiredCredits
     * @return bool
     */
    private function hasEnoughCredits($requiredCredits) {
        $monthlyUsage = $this->getUsageForPeriod('month', 2592000);
        $remainingCredits = self::MONTHLY_QUOTA - $monthlyUsage['credits'];
        
        return $remainingCredits >= $requiredCredits;
    }
    
    /**
     * Get usage for a specific time period
     * @param string $period
     * @param int $windowSeconds
     * @return array
     */
    private function getUsageForPeriod($period, $windowSeconds) {
        $sql = "SELECT 
                    COUNT(*) as requests,
                    SUM(credits_used) as credits,
                    MIN(UNIX_TIMESTAMP(created_at)) as oldest_request
                FROM api_logs 
                WHERE api_service = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)";
        
        $result = $this->db->fetchOne($sql, [$this->getServiceName(), $windowSeconds]);
        
        return [
            'requests' => (int)($result['requests'] ?? 0),
            'credits' => (int)($result['credits'] ?? 0),
            'oldest_request' => (int)($result['oldest_request'] ?? time())
        ];
    }
    
    /**
     * Update usage statistics cache
     * @param int $creditsUsed
     */
    private function updateUsageStats($creditsUsed) {
        // This could be used to update a cache table for faster lookups
        // For now, we rely on real-time queries to api_logs table
    }
    
    /**
     * Log rate limit hit for monitoring
     * @param string $period
     * @param int $currentUsage
     * @param int $limit
     */
    private function logRateLimitHit($period, $currentUsage, $limit) {
        error_log("DataForSEO rate limit hit - $period: $currentUsage/$limit requests");
        
        // Could send alert/notification here
        $this->recordRateLimitEvent($period, $currentUsage, $limit);
    }
    
    /**
     * Log insufficient credits event
     * @param int $requiredCredits
     */
    private function logInsufficientCredits($requiredCredits) {
        $monthlyUsage = $this->getUsageForPeriod('month', 2592000);
        $remaining = self::MONTHLY_QUOTA - $monthlyUsage['credits'];
        
        error_log("DataForSEO insufficient credits - Required: $requiredCredits, Remaining: $remaining");
    }
    
    /**
     * Record rate limit event for analytics
     * @param string $period
     * @param int $currentUsage
     * @param int $limit
     */
    private function recordRateLimitEvent($period, $currentUsage, $limit) {
        try {
            $sql = "INSERT INTO api_logs (
                api_service, 
                endpoint, 
                method, 
                request_data, 
                response_data, 
                status_code, 
                credits_used, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $this->db->execute($sql, [
                $this->getServiceName(),
                'rate_limit_hit',
                'SYSTEM',
                json_encode(['period' => $period, 'usage' => $currentUsage, 'limit' => $limit]),
                json_encode(['event' => 'rate_limit_exceeded']),
                429, // HTTP 429 Too Many Requests
                0
            ]);
        } catch (Exception $e) {
            error_log("Failed to record rate limit event: " . $e->getMessage());
        }
    }
    
    /**
     * Get cost estimate for different API operations
     * @param string $operation
     * @return int
     */
    public static function getCostForOperation($operation) {
        $costs = [
            'backlinks' => self::BACKLINKS_COST,
            'domain_analysis' => self::DOMAIN_ANALYSIS_COST,
            'competitor_analysis' => self::COMPETITOR_ANALYSIS_COST,
            'summary' => 1,
            'default' => 1
        ];
        
        return $costs[$operation] ?? $costs['default'];
    }
    
    /**
     * Smart delay between requests to avoid rate limits
     * @param string $lastRequestTime
     */
    public function smartDelay($lastRequestTime = null) {
        if ($lastRequestTime === null) {
            $lastRequestTime = time();
        }
        
        $minDelayBetweenRequests = 60 / self::REQUESTS_PER_MINUTE; // seconds
        $timeSinceLastRequest = time() - $lastRequestTime;
        
        if ($timeSinceLastRequest < $minDelayBetweenRequests) {
            $sleepTime = $minDelayBetweenRequests - $timeSinceLastRequest;
            usleep($sleepTime * 1000000); // Convert to microseconds
        }
    }
    
    /**
     * Get health status of DataForSEO API usage
     * @return array
     */
    public function getHealthStatus() {
        $stats = $this->getUsageStatistics();
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'recommendations' => []
        ];
        
        // Check for concerning usage patterns
        foreach ($stats as $period => $data) {
            if ($data['percentage'] >= 90) {
                $health['status'] = 'critical';
                $health['issues'][] = "DataForSEO $period usage at {$data['percentage']}%";
                $health['recommendations'][] = "Reduce API calls or upgrade plan";
            } elseif ($data['percentage'] >= 80) {
                $health['status'] = 'warning';
                $health['issues'][] = "DataForSEO $period usage at {$data['percentage']}%";
                $health['recommendations'][] = "Monitor usage closely";
            }
        }
        
        return $health;
    }
}
?>