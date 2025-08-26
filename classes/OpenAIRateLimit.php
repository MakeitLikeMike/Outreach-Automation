<?php

/**
 * OpenAI Rate Limiting Manager
 * 
 * Handles rate limiting for OpenAI API calls to avoid "Rate limit reached" errors.
 * Specifically designed for gpt-4o-mini with 3 requests per minute limit.
 */
class OpenAIRateLimit {
    private $requestLog;
    private $maxRequestsPerMinute = 3;
    private $timeWindow = 60; // seconds
    private $retryDelay = 20; // seconds to wait after rate limit error
    private $logFile;
    
    public function __construct() {
        $this->requestLog = [];
        $this->logFile = __DIR__ . '/../logs/openai_rate_limit.log';
        
        // Create logs directory if it doesn't exist
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Load existing request log from file
        $this->loadRequestLog();
    }
    
    /**
     * Check if we can make a request without hitting rate limits
     * Returns the number of seconds to wait, or 0 if ready to proceed
     */
    public function checkRateLimit() {
        $currentTime = time();
        
        // Remove requests older than the time window
        $this->cleanupOldRequests($currentTime);
        
        // Count recent requests
        $recentRequests = count($this->requestLog);
        
        $this->logDebug("Current requests in window: $recentRequests / {$this->maxRequestsPerMinute}");
        
        if ($recentRequests >= $this->maxRequestsPerMinute) {
            // Calculate how long to wait
            $oldestRequest = min($this->requestLog);
            $waitTime = ($oldestRequest + $this->timeWindow) - $currentTime + 1; // +1 for safety margin
            
            $this->logDebug("Rate limit reached. Need to wait: {$waitTime} seconds");
            return max(0, $waitTime);
        }
        
        return 0; // Ready to proceed
    }
    
    /**
     * Wait before making a request if necessary
     * Automatically handles the timing without user input
     * Returns false if wait time is too long for web requests
     */
    public function waitIfNeeded($maxWaitTime = 30) {
        $waitTime = $this->checkRateLimit();
        
        if ($waitTime > 0) {
            $this->logDebug("Need to wait {$waitTime} seconds to avoid rate limit");
            
            // If wait time is too long, don't block the web request
            if ($waitTime > $maxWaitTime) {
                $this->logDebug("Wait time ({$waitTime}s) exceeds max wait time ({$maxWaitTime}s) - skipping wait");
                return false;
            }
            
            $this->logDebug("Waiting {$waitTime} seconds...");
            sleep($waitTime);
        }
        
        return true;
    }
    
    /**
     * Check if request can proceed without waiting
     * Returns true if ready, false if rate limited
     */
    public function canProceedNow() {
        $waitTime = $this->checkRateLimit();
        return $waitTime === 0;
    }
    
    /**
     * Get suggested delay before next attempt (for non-blocking approach)
     */
    public function getSuggestedDelay() {
        return $this->checkRateLimit();
    }
    
    /**
     * Record a successful request
     */
    public function recordRequest() {
        $currentTime = time();
        $this->requestLog[] = $currentTime;
        
        // Keep only recent requests
        $this->cleanupOldRequests($currentTime);
        
        // Save to file
        $this->saveRequestLog();
        
        $this->logDebug("Request recorded. Total in window: " . count($this->requestLog));
    }
    
    /**
     * Handle rate limit error - wait and return true if should retry
     */
    public function handleRateLimitError($errorMessage = '', $maxWaitTime = 30) {
        $this->logDebug("Rate limit error encountered: $errorMessage");
        
        $waitTime = min($this->retryDelay, $maxWaitTime);
        
        if ($waitTime > $maxWaitTime) {
            $this->logDebug("Retry delay ({$this->retryDelay}s) exceeds max wait time ({$maxWaitTime}s) - using {$maxWaitTime}s");
            $waitTime = $maxWaitTime;
        }
        
        $this->logDebug("Waiting {$waitTime} seconds before retry...");
        sleep($waitTime);
        
        // Clear some old requests to help with retry
        $currentTime = time();
        $this->cleanupOldRequests($currentTime);
        
        return true; // Always retry after waiting
    }
    
    /**
     * Check if error message indicates rate limiting
     */
    public function isRateLimitError($errorMessage) {
        $rateLimitIndicators = [
            'rate limit',
            'rate_limit_exceeded',
            'too many requests',
            'quota exceeded',
            'requests per minute',
            'RPM'
        ];
        
        $errorLower = strtolower($errorMessage);
        
        foreach ($rateLimitIndicators as $indicator) {
            if (strpos($errorLower, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get current rate limit status for debugging
     */
    public function getStatus() {
        $currentTime = time();
        $this->cleanupOldRequests($currentTime);
        
        $recentRequests = count($this->requestLog);
        $waitTime = $this->checkRateLimit();
        
        return [
            'requests_in_window' => $recentRequests,
            'max_requests' => $this->maxRequestsPerMinute,
            'time_window' => $this->timeWindow,
            'wait_time' => $waitTime,
            'can_proceed' => $waitTime === 0,
            'next_available' => $waitTime > 0 ? date('H:i:s', time() + $waitTime) : 'Now'
        ];
    }
    
    /**
     * Remove requests older than the time window
     */
    private function cleanupOldRequests($currentTime) {
        $cutoffTime = $currentTime - $this->timeWindow;
        
        $this->requestLog = array_filter($this->requestLog, function($timestamp) use ($cutoffTime) {
            return $timestamp > $cutoffTime;
        });
        
        // Re-index array
        $this->requestLog = array_values($this->requestLog);
    }
    
    /**
     * Load request log from file
     */
    private function loadRequestLog() {
        if (file_exists($this->logFile)) {
            $content = file_get_contents($this->logFile);
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $this->requestLog = $data;
                    // Clean up old requests on load
                    $this->cleanupOldRequests(time());
                }
            }
        }
    }
    
    /**
     * Save request log to file
     */
    private function saveRequestLog() {
        file_put_contents($this->logFile, json_encode($this->requestLog));
    }
    
    /**
     * Debug logging
     */
    private function logDebug($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] OpenAI Rate Limit: $message" . PHP_EOL;
        
        // Log to file
        file_put_contents($this->logFile . '.debug', $logMessage, FILE_APPEND | LOCK_EX);
        
        // Also log to error_log for development
        error_log("OpenAI Rate Limit: $message");
    }
    
    /**
     * Reset rate limit tracking (for testing or emergency override)
     */
    public function reset() {
        $this->requestLog = [];
        $this->saveRequestLog();
        $this->logDebug("Rate limit tracking reset");
    }
    
    /**
     * Get time until next request is allowed
     */
    public function getTimeUntilNextRequest() {
        return $this->checkRateLimit();
    }
    
    /**
     * Configure rate limits (for different models or endpoints)
     */
    public function configure($maxRequests, $timeWindow, $retryDelay = 20) {
        $this->maxRequestsPerMinute = $maxRequests;
        $this->timeWindow = $timeWindow;
        $this->retryDelay = $retryDelay;
        
        $this->logDebug("Rate limit configured: {$maxRequests} requests per {$timeWindow} seconds, retry delay: {$retryDelay}s");
    }
}
?>