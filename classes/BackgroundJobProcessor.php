<?php

/**
 * BackgroundJobProcessor - Core automation engine
 * Handles all background processing for the outreach automation system
 */

require_once __DIR__ . '/../config/database.php';

class BackgroundJobProcessor {
    private $db;
    private $isRunning = false;
    private $maxExecutionTime = 300; // 5 minutes
    private $maxConcurrentJobs = 5;
    private $logFile = 'logs/background_processor.log';
    private $logRotator;
    
    public function __construct() {
        $this->db = new Database();
        
        // Ensure logs directory exists
        if (!file_exists(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
        
        // Initialize log rotator
        require_once 'LogRotator.php';
        $this->logRotator = new LogRotator($this->logFile, 800);
    }
    
    /**
     * Main processing loop - processes jobs from the queue
     */
    public function processJobs() {
        $this->log("🚀 Background processor started");
        
        // Clean up any stuck jobs first
        $this->cleanupStuckJobs(5); // 5 minute timeout
        
        $startTime = time();
        $this->isRunning = true;
        
        while ($this->isRunning && (time() - $startTime) < $this->maxExecutionTime) {
            try {
                // Clean up stuck jobs every 30 iterations
                static $cleanupCounter = 0;
                if (++$cleanupCounter % 30 === 0) {
                    $this->cleanupStuckJobs(5);
                }
                
                // Get next pending job
                $job = $this->getNextJob();
                
                if (!$job) {
                    $this->log("📭 No pending jobs, sleeping for 10 seconds");
                    sleep(10);
                    continue;
                }
                
                $this->processJob($job);
                
                // Small delay between jobs
                sleep(2);
                
            } catch (Exception $e) {
                $this->log("❌ Error in main loop: " . $e->getMessage());
                sleep(5);
            }
        }
        
        $this->log("⏹️ Background processor stopped after " . (time() - $startTime) . " seconds");
    }
    
    /**
     * Get the next job to process (improved for continuous processing)
     */
    private function getNextJob() {
        // First, try to get jobs from campaigns that don't have recent processing jobs
        // Only block if there's a job that was updated in the last 2 minutes
        $sql = "
            SELECT bj.* FROM background_jobs bj
            WHERE bj.status = 'pending' 
            AND bj.scheduled_at <= NOW()
            AND bj.attempts < bj.max_attempts
            AND bj.campaign_id NOT IN (
                SELECT DISTINCT campaign_id FROM background_jobs 
                WHERE status = 'processing' 
                AND updated_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                AND campaign_id IS NOT NULL
            )
            ORDER BY bj.priority DESC, bj.scheduled_at ASC 
            LIMIT 1
        ";
        
        $job = $this->db->fetchOne($sql);
        
        // If no jobs from idle campaigns, get any available job
        // Don't let stuck jobs block the entire system
        if (!$job) {
            $sql = "
                SELECT * FROM background_jobs 
                WHERE status = 'pending' 
                AND scheduled_at <= NOW()
                AND attempts < max_attempts
                ORDER BY priority DESC, scheduled_at ASC 
                LIMIT 1
            ";
            
            $job = $this->db->fetchOne($sql);
        }
        
        return $job;
    }
    
    /**
     * Process a single background job
     */
    private function processJob($job) {
        $jobId = $job['id'];
        $jobType = $job['job_type'];
        
        $this->log("🔄 Processing job #{$jobId} ({$jobType})");
        
        try {
            // Mark job as processing
            $this->updateJobStatus($jobId, 'processing', 'Started processing');
            
            // Increment attempt count
            $this->db->execute(
                "UPDATE background_jobs SET attempts = attempts + 1, started_at = NOW() WHERE id = ?", 
                [$jobId]
            );
            
            // Process based on job type
            $result = $this->executeJob($job);
            
            if ($result['success']) {
                $this->updateJobStatus($jobId, 'completed', $result['message'] ?? 'Completed successfully');
                $this->log("✅ Job #{$jobId} completed successfully");
            } else {
                $this->handleJobFailure($job, $result['error'] ?? 'Unknown error');
            }
            
        } catch (Exception $e) {
            $this->handleJobFailure($job, $e->getMessage());
        }
    }
    
    /**
     * Execute specific job based on type
     */
    private function executeJob($job) {
        try {
            $result = $this->processJobByType($job);
            return ['success' => true, 'message' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Legacy job processing for backward compatibility
     */
    private function executeLegacyJob($job) {
        switch ($job['job_type']) {
            case 'analyze_domain_quality':
                return $this->processDomainQuality($job);
                
            case 'search_contact_email':
                return $this->processEmailSearch($job);
                
            case 'generate_outreach_email':
                return $this->processEmailGeneration($job);
                
            case 'send_outreach_email':
                return $this->processEmailSending($job);
                
            case 'monitor_replies':
                return $this->processReplyMonitoring($job);
                
            case 'monitor_imap_replies':
                return $this->processImapReplyMonitoring($job);
                
            case 'forward_lead':
                return $this->processLeadForwarding($job);
                
            default:
                return ['success' => false, 'error' => 'Unknown job type: ' . $job['job_type']];
        }
    }
    
    /**
     * Process backlink fetching for a campaign
     */
    private function processFetchBacklinks($job) {
        try {
            require_once __DIR__ . '/ApiIntegration.php';
            require_once __DIR__ . '/TargetDomain.php';
            
            $payload = json_decode($job['payload'], true);
            $campaignId = $job['campaign_id'];
            $competitorUrls = $payload['competitor_urls'];
            
            $api = new ApiIntegration();
            $targetDomain = new TargetDomain();
            
            $totalDomainsCreated = 0;
            $urls = array_filter(array_map('trim', explode("\n", $competitorUrls)));
            
            foreach ($urls as $url) {
                // Auto-detect and add protocol if missing
                if (!preg_match('/^https?:\/\//', $url)) {
                    $url = 'https://' . $url;
                }
                
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    continue;
                }
                
                // Fetch backlinks
                $backlinkResults = $api->fetchBacklinks($url);
                
                if (!empty($backlinkResults)) {
                    $domains = [];
                    if (isset($backlinkResults[0]['items']) && is_array($backlinkResults[0]['items'])) {
                        foreach ($backlinkResults[0]['items'] as $backlink) {
                            if (isset($backlink['domain_from']) && isset($backlink['domain_from_rank'])) {
                                $domain = $backlink['domain_from'];
                                $domainRating = $backlink['domain_from_rank'] ?? 0;
                                
                                // Only include domains with DR > 30
                                if ($domainRating > 30) {
                                    $domains[] = [
                                        'domain' => $domain,
                                        'dr_rating' => $domainRating
                                    ];
                                }
                            }
                        }
                    }
                    
                    // Create domain entries
                    $domains = array_unique($domains, SORT_REGULAR);
                    
                    foreach ($domains as $domainData) {
                        if (is_array($domainData) && !empty($domainData['domain'])) {
                            $basicMetrics = [
                                'domain_rating' => $domainData['dr_rating'],
                                'organic_traffic' => 0,
                                'referring_domains' => 0,
                                'ranking_keywords' => 0,
                                'quality_score' => 0,
                                'contact_email' => null
                            ];
                            
                            // Create domain with DR rating
                            $domainId = $targetDomain->create($campaignId, $domainData['domain'], $basicMetrics);
                            
                            // Normalize DR to 1-100 scale and update in database
                            $normalizedDR = min(100, max(1, intval($domainData['dr_rating'] / 10)));
                            $this->db->execute(
                                "UPDATE target_domains SET domain_rating = ? WHERE id = ?", 
                                [$normalizedDR, $domainId]
                            );
                            
                            $totalDomainsCreated++;
                            
                            // Queue domain quality analysis job
                            $this->queueJob('analyze_domain_quality', $campaignId, $domainId, [
                                'domain' => $domainData['domain']
                            ], 8);
                        }
                    }
                }
            }
            
            // Update campaign metrics
            $this->db->execute(
                "UPDATE campaigns SET total_domains_scraped = ?, domains_dr_above_30 = ?, pipeline_status = 'analyzing_quality' WHERE id = ?",
                [$totalDomainsCreated, $totalDomainsCreated, $campaignId]
            );
            
            return [
                'success' => true,
                'message' => "Created {$totalDomainsCreated} domains with DR > 30"
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process domain quality analysis
     */
    private function processDomainQuality($job) {
        try {
            require_once __DIR__ . '/DomainQualityAnalyzer.php';
            
            $payload = json_decode($job['payload'], true);
            $domainId = $job['domain_id'];
            $domain = $payload['domain'];
            
            // Update domain status to show it's being processed
            $this->db->execute(
                "UPDATE target_domains SET status = 'analyzing' WHERE id = ?",
                [$domainId]
            );
            $this->log("🔍 Domain {$domain} status updated to 'analyzing'");
            
            $analyzer = new DomainQualityAnalyzer();
            $analysis = $analyzer->analyzeDomainQuality($domain);
            
            // Update domain with quality metrics
            $recommendationStatus = $analysis['recommendation']['status'] ?? 'reject';
            $status = (in_array($recommendationStatus, ['recommended', 'highly_recommended'])) ? 'approved' : 'rejected';
            $approvalReason = $analysis['recommendation']['reason'];
            
            $this->db->execute(
                "UPDATE target_domains SET 
                 status = ?, 
                 quality_score = ?, 
                 organic_traffic = ?,
                 referring_domains = ?,
                 ranking_keywords = ?,
                 domain_rating = ?,
                 backlinks_total = ?,
                 referring_pages = ?,
                 referring_main_domains = ?,
                 domain_authority_rank = ?,
                 broken_backlinks = ?,
                 backlink_analysis_type = ?,
                 backlink_last_updated = NOW()
                 WHERE id = ?",
                [
                    $status,
                    $analysis['quality_metrics']['overall_score'] ?? 0,
                    $analysis['analysis']['organic_traffic'] ?? 0,
                    $analysis['analysis']['referring_domains'] ?? 0,
                    $analysis['analysis']['organic_keywords'] ?? 0,
                    $analysis['analysis']['domain_rating'] ?? 0,
                    $analysis['analysis']['backlinks'] ?? 0,
                    $analysis['analysis']['referring_pages'] ?? 0,
                    $analysis['analysis']['referring_main_domains'] ?? 0,
                    $analysis['analysis']['domain_rank'] ?? 0,
                    $analysis['analysis']['broken_backlinks'] ?? 0,
                    $analysis['analysis']['backlink_analysis_type'] ?? 'unknown',
                    $domainId
                ]
            );
            
            // If approved and auto email search enabled, queue email search
            if ($status === 'approved') {
                $campaignId = $job['campaign_id'];
                $campaign = $this->db->fetchOne("SELECT auto_email_search FROM campaigns WHERE id = ?", [$campaignId]);
                
                if ($campaign['auto_email_search']) {
                    $this->queueJob('search_contact_email', $campaignId, $domainId, [
                        'domain' => $domain
                    ], 6);
                }
            }
            
            return [
                'success' => true,
                'message' => "Domain {$domain} analyzed and marked as {$status}"
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process email search for a domain
     */
    private function processEmailSearch($job) {
        $domainId = $job['domain_id'];
        $domain = null;
        
        try {
            require_once __DIR__ . '/EmailVerification.php';
            
            $payload = json_decode($job['payload'], true);
            $domain = $payload['domain'];
            
            // Update domain status and email search tracking
            $this->db->execute(
                "UPDATE target_domains SET 
                 status = 'searching_email',
                 email_search_status = 'searching',
                 email_search_attempts = COALESCE(email_search_attempts, 0) + 1,
                 last_email_search_at = NOW()
                 WHERE id = ?",
                [$domainId]
            );
            $this->log("📧 Domain {$domain} status updated to 'searching_email'");
            
            // Set a timeout for email search (30 seconds max)
            $timeoutSeconds = 30;
            $startTime = time();
            
            $emailVerification = new EmailVerification();
            
            // Add timeout wrapper
            $emailResult = null;
            $timeoutReached = false;
            
            try {
                $emailResult = $emailVerification->findDomainEmails($domain);
                
                // Check if we exceeded timeout
                if ((time() - $startTime) > $timeoutSeconds) {
                    $timeoutReached = true;
                    throw new Exception("Email search timeout after {$timeoutSeconds} seconds");
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'timeout') !== false || (time() - $startTime) > $timeoutSeconds) {
                    $timeoutReached = true;
                }
                throw $e;
            }
            
            if ($emailResult && !empty($emailResult['email'])) {
                $contactEmail = $emailResult['email'];
                
                // Update domain with found email and continue pipeline
                $this->db->execute(
                    "UPDATE target_domains SET 
                     contact_email = ?, 
                     email_search_status = 'found',
                     email_search_completed = 1,
                     status = 'generating_email'
                     WHERE id = ?",
                    [$contactEmail, $domainId]
                );
                
                // Queue outreach email generation/sending
                $campaignId = $job['campaign_id'];
                $campaign = $this->db->fetchOne(
                    "SELECT auto_send, automation_mode FROM campaigns WHERE id = ?", 
                    [$campaignId]
                );
                
                if ($campaign['auto_send']) {
                    $this->queueJob('generate_outreach_email', $campaignId, $domainId, [
                        'domain' => $domain,
                        'contact_email' => $contactEmail,
                        'automation_mode' => $campaign['automation_mode']
                    ], 5);
                }
                
                $this->log("✅ Found email {$contactEmail} for domain {$domain}");
                return [
                    'success' => true,
                    'message' => "Found email {$contactEmail} for domain {$domain}"
                ];
            } else {
                // No email found - mark as completed and continue to next steps
                $this->db->execute(
                    "UPDATE target_domains SET 
                     email_search_status = 'not_found',
                     email_search_completed = 1,
                     status = 'approved',
                     contact_email = NULL,
                     email_search_error = 'No contact email found after search'
                     WHERE id = ?",
                    [$domainId]
                );
                
                $this->log("❌ No email found for domain {$domain} - pipeline continues");
                return [
                    'success' => true,
                    'message' => "No email found for domain {$domain} - marked as completed"
                ];
            }
            
        } catch (Exception $e) {
            // Handle timeout and other errors gracefully
            $errorMessage = $e->getMessage();
            
            // Update domain with error status but keep pipeline moving
            $this->db->execute(
                "UPDATE target_domains SET 
                 email_search_status = 'failed',
                 email_search_completed = 1,
                 status = 'approved',
                 email_search_error = ?
                 WHERE id = ?",
                [$errorMessage, $domainId]
            );
            
            $this->log("⚠️ Email search failed for domain {$domain}: {$errorMessage}");
            
            // Return success to prevent job retry loop, but log the error
            return [
                'success' => true, // Mark as success to prevent endless retries
                'message' => "Email search failed for domain {$domain}: {$errorMessage}"
            ];
        }
    }
    
    /**
     * Clean up stuck jobs that have been processing too long
     */
    public function cleanupStuckJobs($timeoutMinutes = 30) {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$timeoutMinutes} minutes"));
        
        // Find stuck jobs
        $stuckJobs = $this->db->fetchAll(
            "SELECT id, job_type, domain_id, updated_at FROM background_jobs 
             WHERE status = 'processing' AND updated_at < ?",
            [$cutoffTime]
        );
        
        $cleanedCount = 0;
        foreach ($stuckJobs as $job) {
            // Mark job as failed
            $this->db->execute(
                "UPDATE background_jobs 
                 SET status = 'failed', 
                     error_message = 'Job timed out - stuck in processing for over {$timeoutMinutes} minutes',
                     updated_at = NOW()
                 WHERE id = ?",
                [$job['id']]
            );
            
            // If it's an email search job, update the domain status
            if ($job['job_type'] === 'search_contact_email' && $job['domain_id']) {
                $this->db->execute(
                    "UPDATE target_domains 
                     SET status = 'approved',
                         email_search_status = 'failed',
                         email_search_error = 'Email search timed out',
                         email_search_completed = 1
                     WHERE id = ?",
                    [$job['domain_id']]
                );
            }
            
            $cleanedCount++;
            $this->log("🧹 Cleaned up stuck job #{$job['id']} ({$job['job_type']}) - timeout after {$timeoutMinutes} minutes");
        }
        
        return $cleanedCount;
    }
    
    /**
     * Queue a new background job
     */
    public function queueJob($jobType, $campaignId = null, $domainId = null, $payload = [], $priority = 0, $scheduledAt = null) {
        $sql = "
            INSERT INTO background_jobs (job_type, campaign_id, domain_id, payload, priority, scheduled_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        
        $this->db->execute($sql, [
            $jobType,
            $campaignId,
            $domainId,
            json_encode($payload),
            $priority,
            $scheduledAt ?: date('Y-m-d H:i:s')
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Update job status
     */
    private function updateJobStatus($jobId, $status, $message = null) {
        $sql = "UPDATE background_jobs SET status = ?, completed_at = NOW()";
        $params = [$status, $jobId];
        
        if ($message) {
            $sql .= ", error_message = ?";
            $params = [$status, $message, $jobId];
        }
        
        $sql .= " WHERE id = ?";
        
        $this->db->execute($sql, $params);
    }
    
    /**
     * Handle job failure
     */
    private function handleJobFailure($job, $error) {
        $jobId = $job['id'];
        $attempts = $job['attempts'] + 1;
        $maxAttempts = $job['max_attempts'];
        
        $this->log("❌ Job #{$jobId} failed: {$error}");
        
        if ($attempts >= $maxAttempts) {
            $this->updateJobStatus($jobId, 'failed', $error);
            $this->log("💀 Job #{$jobId} marked as failed after {$attempts} attempts");
        } else {
            // Schedule retry with exponential backoff
            $retryDelay = min(300, pow(2, $attempts) * 30); // Max 5 minutes
            $retryTime = date('Y-m-d H:i:s', time() + $retryDelay);
            
            $this->db->execute(
                "UPDATE background_jobs SET status = 'pending', scheduled_at = ?, error_message = ? WHERE id = ?",
                [$retryTime, $error, $jobId]
            );
            
            $this->log("🔄 Job #{$jobId} scheduled for retry in {$retryDelay} seconds");
        }
    }
    
    /**
     * Get sender email for campaign
     */
    private function getSenderEmail($campaignId) {
        // Try to get campaign-specific sender email
        $campaign = $this->db->fetchOne("SELECT owner_email FROM campaigns WHERE id = ?", [$campaignId]);
        
        if ($campaign && !empty($campaign['owner_email'])) {
            return $campaign['owner_email'];
        }
        
        // Fallback to system default sender email
        $senderSetting = $this->db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'sender_email'");
        
        if ($senderSetting && !empty($senderSetting['setting_value'])) {
            return $senderSetting['setting_value'];
        }
        
        // Final fallback - use a default email
        return 'noreply@autooutreach.com';
    }
    
    /**
     * Log message with timestamp
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}";
        
        echo $logMessage . "\n";
        
        // Use log rotator which handles rotation automatically
        $this->logRotator->logMessage($logMessage);
    }
    
    /**
     * Stop the processor
     */
    public function stop() {
        $this->isRunning = false;
    }
    
    /**
     * Get system status for dashboard
     */
    public function getSystemStatus() {
        $stats = $this->db->fetchOne("
            SELECT 
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_jobs,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_jobs,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_jobs,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_jobs
            FROM background_jobs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        return $stats ?: [
            'pending_jobs' => 0,
            'processing_jobs' => 0,
            'failed_jobs' => 0,
            'completed_jobs' => 0
        ];
    }
    
    /**
     * Get recent jobs for dashboard
     */
    public function getRecentJobs($limit = 10) {
        $sql = "
            SELECT 
                bj.*,
                c.name as campaign_name
            FROM background_jobs bj
            LEFT JOIN campaigns c ON bj.campaign_id = c.id
            ORDER BY bj.created_at DESC
            LIMIT ?
        ";
        
        return $this->db->fetchAll($sql, [$limit]);
    }
    
    /**
     * Process a single job from the queue
     */
    public function processSingleJob() {
        // Get next pending job
        $job = $this->getNextJob();
        
        if (!$job) {
            return false; // No jobs available
        }
        
        // Process the job
        $this->processJob($job);
        
        return true; // Job was processed
    }

    /**
     * Process different job types
     */
    private function processJobByType($job) {
        switch ($job['job_type']) {
            case 'fetch_backlinks':
                return $this->processFetchBacklinks($job);
                
            case 'analyze_domains':
                return $this->processAnalyzeDomains($job);
                
            case 'analyze_domain_quality':
                return $this->processDomainQuality($job);
                
            case 'search_emails':
                return $this->processSearchEmails($job);
                
            case 'search_contact_email':
                return $this->processEmailSearch($job);
                
            case 'send_outreach':
                return $this->processSendOutreach($job);
                
            case 'generate_outreach_email':
                return $this->processEmailGeneration($job);
                
            case 'send_outreach_email':
                return $this->processEmailSending($job);
                
            case 'monitor_replies':
                return $this->processReplyMonitoring($job);
                
            case 'forward_lead':
                return $this->processLeadForwarding($job);
                
            case 'check_completion':
                return $this->processCheckCompletion($job);
                
            default:
                throw new Exception("Unknown job type: {$job['job_type']}");
        }
    }
    
    
    /**
     * Process domain analysis job
     */
    private function processAnalyzeDomains($job) {
        require_once __DIR__ . '/DomainQualityAnalyzerEnhanced.php';
        
        $this->log("⚖️ Processing domain analysis job for campaign {$job['campaign_id']}");
        
        $analyzer = new DomainQualityAnalyzerEnhanced();
        
        // Get pending domains for this campaign
        $domains = $this->db->fetchAll(
            "SELECT id FROM target_domains WHERE campaign_id = ? AND status = 'pending' LIMIT 10",
            [$job['campaign_id']]
        );
        
        $approved = 0;
        $rejected = 0;
        
        foreach ($domains as $domain) {
            try {
                $result = $analyzer->analyzeDomain($domain['id']);
                
                if ($result['decision'] === 'approved') {
                    $approved++;
                } elseif ($result['decision'] === 'rejected') {
                    $rejected++;
                }
                
                // Small delay between analyses
                sleep(1);
                
            } catch (Exception $e) {
                $this->log("❌ Failed to analyze domain {$domain['id']}: " . $e->getMessage());
            }
        }
        
        // Check if more domains need analysis
        $remainingCount = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM target_domains WHERE campaign_id = ? AND status = 'pending'",
            [$job['campaign_id']]
        )['count'] ?? 0;
        
        if ($remainingCount > 0) {
            // Queue another analysis job
            $this->queueJob('analyze_domains', $job['campaign_id'], null, [], 7);
        } else {
            // All domains analyzed, move to email search
            $this->updateCampaignStatus($job['campaign_id'], 'finding_emails', $approved);
            $this->queueJob('search_emails', $job['campaign_id'], null, [], 6);
        }
        
        $this->log("✅ Analyzed domains for campaign {$job['campaign_id']}: {$approved} approved, {$rejected} rejected");
        
        return "Analyzed domains: {$approved} approved, {$rejected} rejected";
    }
    
    /**
     * Process email search job
     */
    private function processSearchEmails($job) {
        require_once __DIR__ . '/EmailSearchService.php';
        
        $this->log("📧 Processing email search job for campaign {$job['campaign_id']}");
        
        $emailService = new EmailSearchService();
        $result = $emailService->batchSearchEmails($job['campaign_id'], 5);
        
        $emailsFound = $result['successful'] ?? 0;
        
        // Check if more domains need email search
        $remainingCount = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM target_domains WHERE campaign_id = ? AND status = 'approved' AND contact_email IS NULL",
            [$job['campaign_id']]
        )['count'] ?? 0;
        
        if ($remainingCount > 0) {
            // Queue another email search job
            $this->queueJob('search_emails', $job['campaign_id'], null, [], 5);
        } else {
            // All emails searched, move to outreach
            $this->updateCampaignStatus($job['campaign_id'], 'sending_outreach');
            $this->queueJob('send_outreach', $job['campaign_id'], null, [], 4);
        }
        
        $this->log("✅ Found emails for campaign {$job['campaign_id']}: {$emailsFound} emails discovered");
        
        return "Found {$emailsFound} contact emails";
    }
    
    /**
     * Process send outreach job
     */
    private function processSendOutreach($job) {
        require_once __DIR__ . '/OutreachAutomation.php';
        require_once __DIR__ . '/SenderHealthMonitor.php';
        
        $this->log("📤 Processing outreach job for campaign {$job['campaign_id']}");
        
        $outreach = new OutreachAutomation();
        $senderHealth = new SenderHealthMonitor();
        
        // Check if we have healthy senders available
        $healthySender = $senderHealth->getNextHealthySender($job['campaign_id']);
        if (!$healthySender) {
            throw new Exception("No healthy senders available for outreach");
        }
        
        // Process campaign outreach batch
        $result = $outreach->processCampaignOutreach($job['campaign_id'], 3); // Smaller batches for reliability
        
        $emailsGenerated = $result['sent'] ?? 0;
        
        // Check if more domains need outreach processing
        $remainingCount = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM target_domains WHERE campaign_id = ? AND status = 'approved' AND contact_email IS NOT NULL AND contacted_at IS NULL",
            [$job['campaign_id']]
        )['count'] ?? 0;
        
        if ($remainingCount > 0) {
            // Queue another outreach job
            $this->queueJob('send_outreach', $job['campaign_id'], null, [], 3);
        } else {
            // All emails processed, move to reply monitoring
            $this->updateCampaignStatus($job['campaign_id'], 'monitoring_replies');
            $this->queueJob('monitor_replies', $job['campaign_id'], null, [], 2);
        }
        
        $this->log("✅ Outreach batch completed for campaign {$job['campaign_id']}: {$emailsGenerated} emails processed");
        
        return "Generated {$emailsGenerated} outreach emails";
    }
    
    /**
     * Process monitor replies job
     */
    private function processMonitorReplies($job) {
        require_once __DIR__ . '/ReplyMonitor.php';
        
        $this->log("👀 Processing reply monitoring job for campaign {$job['campaign_id']}");
        
        $replyMonitor = new ReplyMonitor();
        
        if ($job['campaign_id']) {
            // Monitor specific campaign
            $result = $replyMonitor->monitorCampaignReplies($job['campaign_id']);
            
            $repliesFound = $result['replies_found'] ?? 0;
            $leadsForwarded = $result['leads_forwarded'] ?? 0;
            
            // Check if campaign still has emails to monitor
            $remainingCount = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM outreach_emails WHERE campaign_id = ? AND status = 'sent' AND replied_at IS NULL AND sent_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
                [$job['campaign_id']]
            )['count'] ?? 0;
            
            if ($remainingCount > 0) {
                // Schedule next monitoring check in 15 minutes
                $nextCheck = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $this->db->execute(
                    "INSERT INTO background_jobs (job_type, campaign_id, payload, priority, scheduled_at) VALUES ('monitor_replies', ?, '{}', 2, ?)",
                    [$job['campaign_id'], $nextCheck]
                );
            } else {
                // All emails monitored, mark campaign as complete
                $this->updateCampaignStatus($job['campaign_id'], 'completed');
            }
            
            $this->log("✅ Reply monitoring completed for campaign {$job['campaign_id']}: {$repliesFound} replies, {$leadsForwarded} leads");
            
            return "Monitored {$repliesFound} replies and forwarded {$leadsForwarded} leads";
        } else {
            // Monitor all active campaigns
            $result = $replyMonitor->monitorAllCampaigns();
            
            $campaignsChecked = $result['campaigns_checked'] ?? 0;
            $totalReplies = $result['replies_found'] ?? 0;
            $totalLeads = $result['leads_forwarded'] ?? 0;
            
            $this->log("✅ All-campaign reply monitoring completed: {$campaignsChecked} campaigns, {$totalReplies} replies, {$totalLeads} leads");
            
            return "Monitored {$campaignsChecked} campaigns, found {$totalReplies} replies, forwarded {$totalLeads} leads";
        }
    }
    
    /**
     * Process completion check job
     */
    private function processCheckCompletion($job) {
        require_once __DIR__ . '/CampaignCompletionManager.php';
        
        $this->log("🏁 Processing campaign completion check");
        
        $completionManager = new CampaignCompletionManager();
        $result = $completionManager->processCampaignCompletions();
        
        $completedCampaigns = $result['completed_campaigns'] ?? 0;
        $updatedCampaigns = $result['updated_campaigns'] ?? 0;
        $totalChecked = $result['total_checked'] ?? 0;
        
        // Schedule next completion check
        $nextCheck = date('Y-m-d H:i:s', strtotime('+60 minutes'));
        $this->queueJob('check_completion', null, null, [], 1, $nextCheck);
        
        $this->log("✅ Completion check completed: {$completedCampaigns} completed, {$updatedCampaigns} updated, {$totalChecked} checked");
        
        return "Checked {$totalChecked} campaigns: {$completedCampaigns} completed, {$updatedCampaigns} updated";
    }
    
    /**
     * Create domain entry from backlink data
     */
    private function createDomainEntry($campaignId, $backlinkData) {
        $domain = $backlinkData['domain'] ?? '';
        $drRating = $backlinkData['domain_rating'] ?? 0;
        $organicTraffic = $backlinkData['organic_traffic'] ?? 0;
        
        // Check if domain already exists for this campaign
        $existing = $this->db->fetchOne(
            "SELECT id FROM target_domains WHERE campaign_id = ? AND domain = ?",
            [$campaignId, $domain]
        );
        
        if (!$existing) {
            // Normalize DR to 1-100 scale
            $normalizedDR = min(100, max(1, intval($drRating / 10)));
            $this->db->execute(
                "INSERT INTO target_domains (campaign_id, domain, domain_rating, organic_traffic, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())",
                [$campaignId, $domain, $normalizedDR, $organicTraffic]
            );
        }
    }
    
    /**
     * Update campaign pipeline status
     */
    private function updateCampaignStatus($campaignId, $status, $metricValue = null) {
        $sql = "UPDATE campaigns SET pipeline_status = ?, processing_started_at = COALESCE(processing_started_at, NOW())";
        $params = [$status, $campaignId];
        
        // Update specific metrics based on status
        switch ($status) {
            case 'processing_domains':
                $sql .= ", total_domains_scraped = ?";
                array_unshift($params, $metricValue);
                break;
            case 'finding_emails':
                $sql .= ", approved_domains_count = ?";
                array_unshift($params, $metricValue);
                break;
            case 'sending_outreach':
                $sql .= ", emails_found_count = ?";
                array_unshift($params, $metricValue);
                break;
        }
        
        $sql .= " WHERE id = ?";
        
        $this->db->execute($sql, $params);
    }

    /**
     * Process email generation for a domain
     */
    private function processEmailGeneration($job) {
        try {
            require_once __DIR__ . '/OutreachAutomation.php';
            
            $payload = json_decode($job['payload'] ?? '{}', true) ?? [];
            $domainId = $job['domain_id'];
            $campaignId = $job['campaign_id'];
            $domain = $payload['domain'] ?? '';
            $contactEmail = $payload['contact_email'] ?? '';
            $automationMode = $payload['automation_mode'] ?? 'auto_generate';
            
            // Update domain status to show email generation in progress
            $this->db->execute(
                "UPDATE target_domains SET status = 'generating_email' WHERE id = ?",
                [$domainId]
            );
            $this->log("✉️ Domain {$domain} status updated to 'generating_email'");
            
            $outreach = new OutreachAutomation();
            $emailResult = $outreach->generateOutreachEmail($domainId, $campaignId);
            
            if ($emailResult && $emailResult['success']) {
                $emailId = $emailResult['email_id'];
                
                // Queue email sending for the generated email
                $this->queueJob('send_outreach_email', $campaignId, $domainId, [
                    'email_id' => $emailId,
                    'domain' => $domain,
                    'contact_email' => $contactEmail
                ], 4);
                
                return [
                    'success' => true,
                    'message' => "Generated outreach email for {$domain} - ID: {$emailId}"
                ];
            } else {
                return [
                    'success' => false,
                    'error' => "Failed to generate email content for {$domain}: " . ($emailResult['error'] ?? 'Unknown error')
                ];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process email sending
     */
    private function processEmailSending($job) {
        try {
            require_once __DIR__ . '/GMassIntegration.php';
            
            $payload = json_decode($job['payload'] ?? '{}', true) ?? [];
            $emailId = $payload['email_id'] ?? null;
            $contactEmail = $payload['contact_email'] ?? '';
            $domain = $payload['domain'] ?? '';
            
            // Update domain status to show email sending in progress
            $this->db->execute(
                "UPDATE target_domains SET status = 'sending_email' WHERE id = ?",
                [$job['domain_id']]
            );
            
            // Get email content
            $email = $this->db->fetchOne(
                "SELECT * FROM outreach_emails WHERE id = ?", 
                [$emailId]
            );
            
            if (!$email) {
                throw new Exception("Email record not found: {$emailId}");
            }
            
            $gmass = new GMassIntegration();
            
            // Get sender email from campaign or system settings
            $senderEmail = $this->getSenderEmail($email['campaign_id']);
            
            $result = $gmass->sendEmail(
                $senderEmail,
                $contactEmail,
                $email['subject'],
                $email['body']
            );
            
            if ($result['success']) {
                // Update email status and sender email
                $this->db->execute(
                    "UPDATE outreach_emails SET status = 'sent', sent_at = NOW(), sender_email = ? WHERE id = ?",
                    [$senderEmail, $emailId]
                );
                
                // Update domain status
                $this->db->execute(
                    "UPDATE target_domains SET status = 'contacted' WHERE id = ?",
                    [$email['domain_id']]
                );
                
                // Queue reply monitoring
                $this->queueJob('monitor_replies', $email['campaign_id'], $email['domain_id'], [
                    'email_id' => $emailId,
                    'domain' => $domain
                ], 3, date('Y-m-d H:i:s', strtotime('+24 hours')));
                
                return [
                    'success' => true,
                    'message' => "Email sent successfully to {$contactEmail}"
                ];
            } else {
                // Handle Gmail auth issues gracefully
                if (strpos($result['error'], 'not available') !== false || strpos($result['error'], 'authorize') !== false) {
                    // Mark email as ready to send (needs manual intervention)
                    $this->db->execute(
                        "UPDATE outreach_emails SET status = 'ready_to_send' WHERE id = ?",
                        [$emailId]
                    );
                    
                    // Update domain status to show it's ready for outreach
                    $this->db->execute(
                        "UPDATE target_domains SET status = 'ready_for_outreach' WHERE id = ?",
                        [$email['domain_id']]
                    );
                    
                    return [
                        'success' => true,
                        'message' => "Email ready to send to {$contactEmail} - Gmail re-authorization needed"
                    ];
                } else {
                    throw new Exception("Email sending failed: " . $result['error']);
                }
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process reply monitoring
     */
    private function processReplyMonitoring($job) {
        try {
            require_once __DIR__ . '/ReplyClassifier.php';
            require_once __DIR__ . '/GMassIntegration.php';
            
            $payload = json_decode($job['payload'], true);
            $emailId = $payload['email_id'];
            $domain = $payload['domain'];
            
            // Update domain status to show monitoring in progress
            $this->db->execute(
                "UPDATE target_domains SET status = 'monitoring_replies' WHERE id = ?",
                [$job['domain_id']]
            );
            
            $gmass = new GMassIntegration();
            $classifier = new ReplyClassifier();
            
            // Check for replies to this email
            $replies = $gmail->checkForReplies($emailId);
            
            if (!empty($replies)) {
                foreach ($replies as $reply) {
                    // Classify the reply
                    $classification = $classifier->classifyReply($reply['content']);
                    
                    // Store reply
                    $this->db->execute(
                        "INSERT INTO email_replies (outreach_email_id, reply_content, classification, received_at) 
                         VALUES (?, ?, ?, ?)",
                        [$emailId, $reply['content'], $classification, $reply['received_at']]
                    );
                    
                    // If it's an interested reply, queue lead forwarding
                    if ($classification === 'interested') {
                        $this->queueJob('forward_lead', $job['campaign_id'], $job['domain_id'], [
                            'email_id' => $emailId,
                            'reply_content' => $reply['content'],
                            'domain' => $domain
                        ], 2);
                    }
                }
                
                return [
                    'success' => true,
                    'message' => "Processed " . count($replies) . " replies for {$domain}"
                ];
            } else {
                // No replies yet, reschedule for later
                $this->queueJob('monitor_replies', $job['campaign_id'], $job['domain_id'], $payload, 3, 
                    date('Y-m-d H:i:s', strtotime('+24 hours')));
                
                return [
                    'success' => true,
                    'message' => "No replies yet for {$domain}, scheduled next check"
                ];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process IMAP reply monitoring (new system)
     */
    private function processImapReplyMonitoring($job) {
        try {
            require_once __DIR__ . '/ReplyMonitor.php';
            
            $this->log("📧 Starting IMAP reply monitoring");
            
            // Use the new ReplyMonitor with IMAP
            $replyMonitor = new ReplyMonitor();
            $result = $replyMonitor->monitorAllCampaigns();
            
            $this->log("✅ IMAP monitoring completed: {$result['replies_found']} replies, {$result['leads_forwarded']} leads");
            
            // Schedule next monitoring cycle
            $this->queueJob('monitor_imap_replies', null, null, [], 2, 
                date('Y-m-d H:i:s', strtotime('+15 minutes')));
            
            return [
                'success' => true,
                'message' => "IMAP monitoring: {$result['replies_found']} replies processed, {$result['leads_forwarded']} leads forwarded",
                'data' => $result
            ];
            
        } catch (Exception $e) {
            $this->log("❌ IMAP monitoring failed: " . $e->getMessage());
            
            // Reschedule with backoff on failure
            $this->queueJob('monitor_imap_replies', null, null, [], 3, 
                date('Y-m-d H:i:s', strtotime('+30 minutes')));
            
            return [
                'success' => false, 
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process lead forwarding
     */
    private function processLeadForwarding($job) {
        try {
            require_once __DIR__ . '/LeadForwarder.php';
            
            $payload = json_decode($job['payload'], true);
            $emailId = $payload['email_id'];
            $replyContent = $payload['reply_content'];
            $domain = $payload['domain'];
            
            // Update domain status to show lead forwarding in progress
            $this->db->execute(
                "UPDATE target_domains SET status = 'forwarding_lead' WHERE id = ?",
                [$job['domain_id']]
            );
            
            // Get campaign details
            $campaign = $this->db->fetchOne(
                "SELECT owner_email, name FROM campaigns WHERE id = ?",
                [$job['campaign_id']]
            );
            
            if (!$campaign) {
                throw new Exception("Campaign not found: {$job['campaign_id']}");
            }
            
            $forwarder = new LeadForwarder();
            $result = $forwarder->forwardQualifiedLead(
                $campaign['owner_email'],
                $domain,
                $replyContent,
                $campaign['name']
            );
            
            if ($result['success']) {
                // Update campaign lead count
                $this->db->execute(
                    "UPDATE campaigns SET leads_forwarded = leads_forwarded + 1 WHERE id = ?",
                    [$job['campaign_id']]
                );
                
                return [
                    'success' => true,
                    'message' => "Lead forwarded to {$campaign['owner_email']} for {$domain}"
                ];
            } else {
                return [
                    'success' => false,
                    'error' => "Failed to forward lead: " . $result['error']
                ];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}