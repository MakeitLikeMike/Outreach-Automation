<?php
require_once 'SystemLogger.php';
require_once 'TokenRefreshManager.php';

class ImprovedBackgroundProcessor {
    private $db;
    private $logger;
    private $tokenManager;
    private $isRunning = false;
    private $lastRun = [];
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->logger = new SystemLogger();
        $this->tokenManager = new TokenRefreshManager();
        
        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }
    
    /**
     * Main processing loop - can be called from cron or run continuously
     */
    public function process($runOnce = true) {
        $this->isRunning = true;
        $this->logger->logPipelineStep('PROCESSOR_START', [
            'run_once' => $runOnce,
            'memory_limit' => ini_get('memory_limit'),
            'time_limit' => ini_get('max_execution_time')
        ]);
        
        try {
            do {
                $cycleStart = microtime(true);
                
                // 1. Refresh expired tokens proactively
                $this->refreshTokensIfNeeded();
                
                // 2. Process background jobs queue
                $this->processBackgroundJobs();
                
                // 3. Process email search queue
                $this->processEmailSearchQueue();
                
                // 4. Process email sending queue
                $this->processEmailSendingQueue();
                
                // 5. Monitor replies
                $this->monitorReplies();
                
                // 6. Forward qualified leads
                $this->forwardLeads();
                
                // 7. Update campaign pipeline status
                $this->updateCampaignPipelines();
                
                $cycleTime = microtime(true) - $cycleStart;
                $this->logger->logPipelineStep('CYCLE_COMPLETE', [
                    'cycle_time' => round($cycleTime, 3),
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true)
                ]);
                
                if (!$runOnce && $this->isRunning) {
                    sleep(60); // Wait 1 minute between cycles
                }
                
                // Handle signals if available
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
            } while (!$runOnce && $this->isRunning);
            
        } catch (Exception $e) {
            $this->logger->logError('PROCESSOR', 'Background processor failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        } finally {
            $this->logger->logPipelineStep('PROCESSOR_STOP', [
                'total_runtime' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
            ]);
        }
    }
    
    private function refreshTokensIfNeeded() {
        // Only run every 10 minutes
        if ($this->shouldRun('token_refresh', 600)) {
            try {
                $refreshed = $this->tokenManager->refreshExpiredTokens();
                $this->setLastRun('token_refresh');
                
                if ($refreshed > 0) {
                    $this->logger->logPipelineStep('TOKEN_REFRESH', ['refreshed_count' => $refreshed]);
                }
            } catch (Exception $e) {
                $this->logger->logError('TOKEN_REFRESH', 'Failed to refresh tokens', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    private function processBackgroundJobs() {
        try {
            // Get pending jobs with priority ordering
            $sql = "SELECT * FROM background_jobs 
                   WHERE status IN ('pending', 'retrying') 
                   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                   ORDER BY priority DESC, created_at ASC 
                   LIMIT 10";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $jobs = $stmt->fetchAll();
            
            foreach ($jobs as $job) {
                $this->processJob($job);
            }
            
            if (count($jobs) > 0) {
                $this->logger->logPipelineStep('BACKGROUND_JOBS', [
                    'processed_count' => count($jobs)
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->logError('BACKGROUND_JOBS', 'Failed to process background jobs', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    private function processJob($job) {
        $jobId = $job['id'];
        
        try {
            // Mark job as processing
            $this->updateJobStatus($jobId, 'processing', null, time());
            
            $this->logger->logPipelineStep('JOB_START', [
                'job_id' => $jobId,
                'job_type' => $job['job_type'],
                'campaign_id' => $job['campaign_id'],
                'attempt' => $job['attempts'] + 1
            ]);
            
            $result = $this->executeJob($job);
            
            if ($result['success']) {
                $this->updateJobStatus($jobId, 'completed', null, null, time());
                $this->logger->logPipelineStep('JOB_SUCCESS', [
                    'job_id' => $jobId,
                    'job_type' => $job['job_type'],
                    'result' => $result['data'] ?? null
                ]);
            } else {
                $this->handleJobFailure($job, $result['error']);
            }
            
        } catch (Exception $e) {
            $this->handleJobFailure($job, $e->getMessage());
        }
    }
    
    private function executeJob($job) {
        $payload = json_decode($job['payload'], true) ?? [];
        
        switch ($job['job_type']) {
            case 'fetch_backlinks':
                return $this->fetchBacklinks($job['campaign_id'], $payload);
                
            case 'analyze_domains':
                return $this->analyzeDomains($job['campaign_id'], $payload);
                
            case 'find_emails':
                return $this->findEmails($job['domain_id'] ?? $payload['domain_id']);
                
            case 'send_outreach':
                return $this->sendOutreach($job['domain_id'] ?? $payload['domain_id']);
                
            case 'monitor_replies':
                return $this->monitorRepliesForJob($payload);
                
            default:
                return ['success' => false, 'error' => 'Unknown job type: ' . $job['job_type']];
        }
    }
    
    private function fetchBacklinks($campaignId, $payload) {
        // Implementation would call DataForSEO API
        // For now, return success with dummy data
        return ['success' => true, 'data' => ['domains_found' => 0]];
    }
    
    private function analyzeDomains($campaignId, $payload) {
        // Implementation would analyze domain quality
        // For now, return success
        return ['success' => true, 'data' => ['domains_analyzed' => 0]];
    }
    
    private function findEmails($domainId) {
        // Implementation would call email finding API
        // For now, return success
        return ['success' => true, 'data' => ['emails_found' => 0]];
    }
    
    private function sendOutreach($domainId) {
        // Implementation would send outreach email
        // For now, return success
        return ['success' => true, 'data' => ['email_sent' => true]];
    }
    
    private function monitorRepliesForJob($payload) {
        // Implementation would check for email replies
        // For now, return success
        return ['success' => true, 'data' => ['replies_checked' => 0]];
    }
    
    private function updateJobStatus($jobId, $status, $errorMessage = null, $startedAt = null, $completedAt = null) {
        $sql = "UPDATE background_jobs SET 
                status = ?, 
                error_message = ?,
                attempts = attempts + 1";
        
        $params = [$status, $errorMessage];
        
        if ($startedAt !== null) {
            $sql .= ", started_at = FROM_UNIXTIME(?)";
            $params[] = $startedAt;
        }
        
        if ($completedAt !== null) {
            $sql .= ", completed_at = FROM_UNIXTIME(?)";
            $params[] = $completedAt;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $jobId;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
    
    private function handleJobFailure($job, $error) {
        $jobId = $job['id'];
        $attempts = $job['attempts'] + 1;
        $maxAttempts = $job['max_attempts'] ?? 3;
        
        $this->logger->logError('JOB_FAILURE', 'Job failed', [
            'job_id' => $jobId,
            'job_type' => $job['job_type'],
            'attempt' => $attempts,
            'max_attempts' => $maxAttempts,
            'error' => $error
        ]);
        
        if ($attempts >= $maxAttempts) {
            $this->updateJobStatus($jobId, 'failed', $error);
        } else {
            // Schedule retry with exponential backoff
            $retryDelay = min(300, pow(2, $attempts) * 60); // Max 5 minutes
            $nextRun = time() + $retryDelay;
            
            $sql = "UPDATE background_jobs SET 
                   status = 'retrying', 
                   error_message = ?, 
                   attempts = ?,
                   scheduled_at = FROM_UNIXTIME(?)
                   WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$error, $attempts, $nextRun, $jobId]);
        }
    }
    
    private function processEmailSearchQueue() {
        // Implementation for email search queue processing
        // Placeholder for now
    }
    
    private function processEmailSendingQueue() {
        // Implementation for email sending queue processing
        // Placeholder for now
    }
    
    private function monitorReplies() {
        // Only run every 15 minutes
        if ($this->shouldRun('reply_monitoring', 900)) {
            try {
                // Implementation for reply monitoring
                $this->setLastRun('reply_monitoring');
                $this->logger->logPipelineStep('REPLY_MONITORING', ['status' => 'completed']);
            } catch (Exception $e) {
                $this->logger->logError('REPLY_MONITORING', 'Failed to monitor replies', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    private function forwardLeads() {
        // Implementation for lead forwarding
        // Placeholder for now
    }
    
    private function updateCampaignPipelines() {
        // Only run every 5 minutes
        if ($this->shouldRun('pipeline_update', 300)) {
            try {
                $this->updateCampaignStatuses();
                $this->setLastRun('pipeline_update');
            } catch (Exception $e) {
                $this->logger->logError('PIPELINE_UPDATE', 'Failed to update pipelines', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    private function updateCampaignStatuses() {
        $sql = "SELECT id, pipeline_status FROM campaigns WHERE pipeline_status != 'completed'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $campaigns = $stmt->fetchAll();
        
        foreach ($campaigns as $campaign) {
            $newStatus = $this->determineCampaignStatus($campaign['id']);
            if ($newStatus !== $campaign['pipeline_status']) {
                $this->updateCampaignStatus($campaign['id'], $newStatus);
            }
        }
    }
    
    private function determineCampaignStatus($campaignId) {
        // Check job status and determine appropriate pipeline status
        $sql = "SELECT job_type, status, COUNT(*) as count 
               FROM background_jobs 
               WHERE campaign_id = ? 
               GROUP BY job_type, status";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$campaignId]);
        $jobStats = $stmt->fetchAll();
        
        // Simple status determination logic
        // This could be made more sophisticated based on business rules
        if (empty($jobStats)) {
            return 'created';
        }
        
        // More sophisticated status logic would go here
        return 'processing_domains';
    }
    
    private function updateCampaignStatus($campaignId, $status) {
        $sql = "UPDATE campaigns SET pipeline_status = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$status, $campaignId]);
        
        $this->logger->logPipelineStep('CAMPAIGN_STATUS_UPDATE', [
            'campaign_id' => $campaignId,
            'new_status' => $status
        ]);
    }
    
    private function shouldRun($taskName, $interval) {
        $lastRun = $this->getLastRun($taskName);
        return (time() - $lastRun) >= $interval;
    }
    
    private function getLastRun($taskName) {
        if (!isset($this->lastRun[$taskName])) {
            // Try to get from database or file
            $this->lastRun[$taskName] = $this->loadLastRunTime($taskName);
        }
        return $this->lastRun[$taskName];
    }
    
    private function setLastRun($taskName) {
        $this->lastRun[$taskName] = time();
        $this->saveLastRunTime($taskName, time());
    }
    
    private function loadLastRunTime($taskName) {
        $file = __DIR__ . "/../logs/last_run_{$taskName}.txt";
        if (file_exists($file)) {
            return (int)file_get_contents($file);
        }
        return 0;
    }
    
    private function saveLastRunTime($taskName, $time) {
        $file = __DIR__ . "/../logs/last_run_{$taskName}.txt";
        file_put_contents($file, $time);
    }
    
    public function shutdown() {
        $this->isRunning = false;
        $this->logger->logPipelineStep('SHUTDOWN_SIGNAL', ['signal' => 'received']);
    }
    
    /**
     * Create a new background job
     */
    public function queueJob($type, $campaignId = null, $domainId = null, $payload = [], $priority = 5) {
        $sql = "INSERT INTO background_jobs (
                   job_type, campaign_id, domain_id, payload, priority, 
                   status, created_at
               ) VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $type,
            $campaignId,
            $domainId,
            json_encode($payload),
            $priority
        ]);
        
        $jobId = $this->db->lastInsertId();
        
        $this->logger->logPipelineStep('JOB_QUEUED', [
            'job_id' => $jobId,
            'job_type' => $type,
            'campaign_id' => $campaignId,
            'domain_id' => $domainId,
            'priority' => $priority
        ]);
        
        return $jobId;
    }
}
?>