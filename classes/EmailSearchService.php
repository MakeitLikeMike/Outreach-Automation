<?php

/**
 * EmailSearchService - Core Email Search Automation
 * 
 * This service handles all email search operations including:
 * - Immediate email search when domains are approved
 * - Background batch processing
 * - Retry logic with exponential backoff
 * - Rate limiting and quota management
 * - Comprehensive logging and monitoring
 */

require_once __DIR__ . '/ApiIntegration.php';
require_once __DIR__ . '/TargetDomain.php';
require_once __DIR__ . '/TombaRateLimit.php';
require_once __DIR__ . '/EmailSearchQueue.php';

class EmailSearchService {
    private $db;
    private $api;
    private $targetDomain;
    private $rateLimit;
    private $queue;
    
    // Configuration constants
    const MAX_RETRIES = 3;
    const RETRY_DELAYS = [60, 300, 900]; // 1min, 5min, 15min in seconds
    const BATCH_SIZE = 10;
    const SEARCH_TIMEOUT_MINUTES = 30;
    
    public function __construct() {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $this->db = $database->getConnection();
        
        $this->api = new ApiIntegration();
        $this->targetDomain = new TargetDomain();
        $this->rateLimit = new TombaRateLimit();
        $this->queue = new EmailSearchQueue();
    }
    
    /**
     * Immediate email search - triggered when domain is approved
     */
    public function searchEmailImmediate($domainId, $priority = 'high') {
        $this->logInfo("Starting immediate email search for domain ID: $domainId");
        
        try {
            // Get domain details
            $domain = $this->targetDomain->getById($domainId);
            if (!$domain) {
                throw new Exception("Domain not found: $domainId");
            }
            
            // Check if already has email
            if (!empty($domain['contact_email'])) {
                $this->logInfo("Domain {$domain['domain']} already has email: {$domain['contact_email']}");
                return [
                    'success' => true,
                    'email' => $domain['contact_email'],
                    'source' => 'existing'
                ];
            }
            
            // Check rate limits
            if (!$this->rateLimit->canMakeRequest()) {
                $this->logInfo("Rate limit reached, queuing domain for background processing");
                return $this->queueForBackgroundSearch($domainId, $priority);
            }
            
            // Update status to searching
            $this->updateDomainSearchStatus($domainId, 'searching');
            
            // Perform email search
            $result = $this->performEmailSearch($domain, 'immediate', 1);
            
            if ($result['success'] && $result['email']) {
                // Success - update domain with found email
                $this->updateDomainWithEmail($domainId, $result['email'], 'found');
                $this->logSuccess("Email found for {$domain['domain']}: {$result['email']}");
                
                // Queue for outreach if eligible
                $this->queueForOutreachIfEligible($domainId, $domain);
                
                return $result;
            } else {
                // Failed - queue for retry
                $this->handleSearchFailure($domainId, $result['error'] ?? 'Unknown error', 1);
            }
            
        } catch (Exception $e) {
            $this->logError("Immediate email search failed for domain $domainId: " . $e->getMessage());
            $this->handleSearchFailure($domainId, $e->getMessage(), 1);
        }
        
        return ['success' => false, 'error' => 'Search failed, queued for retry'];
    }
    
    /**
     * Background batch email search - runs every 5-10 minutes
     */
    public function processBackgroundQueue($batchSize = self::BATCH_SIZE) {
        $this->logInfo("Starting background email search batch processing (size: $batchSize)");
        
        try {
            // Check rate limits
            if (!$this->rateLimit->canMakeRequest()) {
                $this->logInfo("Rate limit reached, skipping background processing");
                return ['processed' => 0, 'message' => 'Rate limited'];
            }
            
            // Get domains needing email search
            $domains = $this->getDomainsNeedingEmailSearch($batchSize);
            
            if (empty($domains)) {
                $this->logInfo("No domains need email search");
                return ['processed' => 0, 'message' => 'No work needed'];
            }
            
            $processed = 0;
            $successful = 0;
            $failed = 0;
            
            foreach ($domains as $domain) {
                try {
                    // Check rate limits before each request
                    if (!$this->rateLimit->canMakeRequest()) {
                        $this->logInfo("Rate limit reached mid-batch, stopping processing");
                        break;
                    }
                    
                    $this->logInfo("Processing domain: {$domain['domain']} (attempt {$domain['email_search_attempts']})");
                    
                    // Update status to searching
                    $this->updateDomainSearchStatus($domain['id'], 'searching');
                    
                    // Perform email search
                    $result = $this->performEmailSearch($domain, 'background', $domain['email_search_attempts'] + 1);
                    
                    if ($result['success'] && $result['email']) {
                        // Success
                        $this->updateDomainWithEmail($domain['id'], $result['email'], 'found');
                        $this->queueForOutreachIfEligible($domain['id'], $domain);
                        $successful++;
                        
                        $this->logSuccess("Background search found email for {$domain['domain']}: {$result['email']}");
                    } else {
                        // Failed
                        $attemptNumber = $domain['email_search_attempts'] + 1;
                        $this->handleSearchFailure($domain['id'], $result['error'] ?? 'Unknown error', $attemptNumber);
                        $failed++;
                    }
                    
                    $processed++;
                    
                    // Small delay between requests
                    usleep(100000); // 0.1 seconds
                    
                } catch (Exception $e) {
                    $this->logError("Background processing failed for {$domain['domain']}: " . $e->getMessage());
                    $this->handleSearchFailure($domain['id'], $e->getMessage(), $domain['email_search_attempts'] + 1);
                    $failed++;
                    $processed++;
                }
            }
            
            $this->logInfo("Background batch completed: $processed processed, $successful successful, $failed failed");
            
            return [
                'processed' => $processed,
                'successful' => $successful,
                'failed' => $failed,
                'message' => "Processed $processed domains"
            ];
            
        } catch (Exception $e) {
            $this->logError("Background batch processing failed: " . $e->getMessage());
            return ['processed' => 0, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Perform the actual email search using Tomba API
     */
    private function performEmailSearch($domain, $searchType, $attemptNumber) {
        $domainName = is_array($domain) ? $domain['domain'] : $domain;
        $domainId = is_array($domain) ? $domain['id'] : null;
        
        $startTime = microtime(true);
        $this->logInfo("Searching email for domain: $domainName (attempt: $attemptNumber)");
        
        try {
            // Record the request for rate limiting
            $this->rateLimit->recordRequest();
            
            // Use Tomba API through existing ApiIntegration
            $email = $this->api->findEmail($domainName);
            
            $processingTime = round((microtime(true) - $startTime) * 1000); // milliseconds
            
            // Log the search attempt
            $this->logEmailSearch($domainId, $domainName, $searchType, $attemptNumber, null, null, 
                !empty($email), $email, $processingTime);
            
            if (!empty($email)) {
                return [
                    'success' => true,
                    'email' => $email,
                    'processing_time_ms' => $processingTime
                ];
            } else {
                return [
                    'success' => false,
                    'email' => null,
                    'error' => 'No email found',
                    'processing_time_ms' => $processingTime
                ];
            }
            
        } catch (Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000);
            
            // Log the failed search
            $this->logEmailSearch($domainId, $domainName, $searchType, $attemptNumber, null, null, 
                false, null, $processingTime, 'API_ERROR', $e->getMessage());
            
            return [
                'success' => false,
                'email' => null,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime
            ];
        }
    }
    
    /**
     * Get domains that need email search
     */
    private function getDomainsNeedingEmailSearch($limit = self::BATCH_SIZE) {
        $sql = "
            SELECT id, domain, campaign_id, status, email_search_status, 
                   email_search_attempts, last_email_search_at, quality_score
            FROM target_domains 
            WHERE (
                (status = 'approved' AND email_search_status = 'pending') OR
                (email_search_status = 'failed' AND email_search_attempts < ?) OR
                (email_search_status = 'searching' AND last_email_search_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
            )
            AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ORDER BY 
                CASE email_search_status 
                    WHEN 'pending' THEN 1 
                    WHEN 'failed' THEN 2 
                    WHEN 'searching' THEN 3 
                END,
                quality_score DESC,
                created_at ASC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([self::MAX_RETRIES, self::SEARCH_TIMEOUT_MINUTES, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update domain search status
     */
    private function updateDomainSearchStatus($domainId, $status, $error = null) {
        $sql = "
            UPDATE target_domains SET 
                email_search_status = ?,
                last_email_search_at = NOW(),
                email_search_error = ?
            WHERE id = ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status, $error, $domainId]);
    }
    
    /**
     * Update domain with found email
     */
    private function updateDomainWithEmail($domainId, $email, $status = 'found') {
        $sql = "
            UPDATE target_domains SET 
                contact_email = ?,
                email_search_status = ?,
                email_search_attempts = email_search_attempts + 1,
                last_email_search_at = NOW(),
                email_search_error = NULL,
                next_retry_at = NULL
            WHERE id = ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$email, $status, $domainId]);
    }
    
    /**
     * Handle search failure with retry logic
     */
    private function handleSearchFailure($domainId, $error, $attemptNumber) {
        $nextRetryAt = null;
        $newStatus = 'failed';
        
        if ($attemptNumber < self::MAX_RETRIES) {
            // Schedule retry with exponential backoff
            $delaySeconds = self::RETRY_DELAYS[$attemptNumber - 1] ?? 900; // Default 15 minutes
            $nextRetryAt = date('Y-m-d H:i:s', time() + $delaySeconds);
            $newStatus = 'failed'; // Will be retried
            
            $this->logInfo("Scheduling retry for domain $domainId in $delaySeconds seconds (attempt $attemptNumber/" . self::MAX_RETRIES . ")");
        } else {
            $this->logError("Domain $domainId failed all " . self::MAX_RETRIES . " attempts, marking as permanently failed");
        }
        
        $sql = "
            UPDATE target_domains SET 
                email_search_status = ?,
                email_search_attempts = ?,
                last_email_search_at = NOW(),
                next_retry_at = ?,
                email_search_error = ?
            WHERE id = ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$newStatus, $attemptNumber, $nextRetryAt, $error, $domainId]);
    }
    
    /**
     * Queue domain for background search
     */
    private function queueForBackgroundSearch($domainId, $priority = 'medium') {
        $domain = $this->targetDomain->getById($domainId);
        
        $sql = "
            INSERT INTO email_search_queue (domain_id, domain, campaign_id, priority, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE priority = VALUES(priority)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$domainId, $domain['domain'], $domain['campaign_id'], $priority]);
        
        $this->updateDomainSearchStatus($domainId, 'pending');
        
        return [
            'success' => true,
            'message' => 'Queued for background processing',
            'source' => 'queued'
        ];
    }
    
    /**
     * Queue domain for outreach if eligible
     */
    private function queueForOutreachIfEligible($domainId, $domain) {
        try {
            // Check if domain meets outreach criteria
            $qualityScore = $domain['quality_score'] ?? 0;
            $hasEmail = !empty($domain['contact_email']) || !empty($domain['email']); // Handle both cases
            
            if ($qualityScore >= 75 && $hasEmail) {
                // Queue for outreach (using existing EmailQueue class if available)
                require_once __DIR__ . '/EmailQueue.php';
                $emailQueue = new EmailQueue();
                $emailQueue->queueCampaignEmails($domain['campaign_id'], null, [$domainId]);
                
                $this->logSuccess("Domain {$domain['domain']} queued for outreach (quality: $qualityScore)");
            } else {
                $this->logInfo("Domain {$domain['domain']} not eligible for outreach (quality: $qualityScore, email: " . ($hasEmail ? 'yes' : 'no') . ")");
            }
        } catch (Exception $e) {
            $this->logError("Failed to queue domain for outreach: " . $e->getMessage());
        }
    }
    
    /**
     * Log email search attempt to database
     */
    private function logEmailSearch($domainId, $domain, $searchType, $attemptNumber, $tombaRequest, $tombaResponse, 
                                  $success, $selectedEmail, $processingTimeMs, $errorCode = null, $errorMessage = null) {
        $sql = "
            INSERT INTO email_search_logs (
                domain_id, domain, search_type, attempt_number, tomba_request, tomba_response,
                emails_found, selected_email, processing_time_ms, success, error_code, error_message
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $domainId, $domain, $searchType, $attemptNumber, 
            json_encode($tombaRequest), json_encode($tombaResponse),
            $selectedEmail ? 1 : 0, $selectedEmail, $processingTimeMs, 
            $success ? 1 : 0, $errorCode, $errorMessage
        ]);
    }
    
    /**
     * Get email search statistics
     */
    public function getSearchStatistics($days = 7) {
        $sql = "
            SELECT 
                COUNT(*) as total_searches,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_searches,
                SUM(CASE WHEN emails_found > 0 THEN 1 ELSE 0 END) as emails_found,
                ROUND(AVG(processing_time_ms), 2) as avg_processing_time,
                ROUND((SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as success_rate
            FROM email_search_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$days]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get domains by search status
     */
    public function getDomainsBySearchStatus() {
        $sql = "
            SELECT 
                email_search_status,
                COUNT(*) as count,
                ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM target_domains), 2) as percentage
            FROM target_domains 
            GROUP BY email_search_status
            ORDER BY count DESC
        ";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Manual retry for failed domains
     */
    public function retryFailedDomain($domainId, $force = false) {
        $domain = $this->targetDomain->getById($domainId);
        
        if (!$domain) {
            throw new Exception("Domain not found");
        }
        
        if (!$force && $domain['email_search_attempts'] >= self::MAX_RETRIES) {
            throw new Exception("Domain has exceeded maximum retry attempts");
        }
        
        $this->logInfo("Manual retry for domain: {$domain['domain']}");
        return $this->searchEmailImmediate($domainId, 'high');
    }
    
    /**
     * Get recommended batch size based on current rate limits
     */
    public function getRecommendedBatchSize($maxBatchSize = self::BATCH_SIZE) {
        return $this->rateLimit->getRecommendedBatchSize($maxBatchSize);
    }
    
    // Logging methods
    private function logInfo($message) {
        error_log("[EmailSearchService][INFO] " . $message);
    }
    
    private function logSuccess($message) {
        error_log("[EmailSearchService][SUCCESS] " . $message);
    }
    
    private function logError($message) {
        error_log("[EmailSearchService][ERROR] " . $message);
    }
}
?>