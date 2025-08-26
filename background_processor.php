<?php
/**
 * Background processor for email queue, reply monitoring, and lead forwarding
 * Run this file via cron job every 5-15 minutes
 * Example cron: */5 * * * * /usr/bin/php /path/to/background_processor.php
 */

require_once 'classes/EmailQueue.php';
require_once 'classes/EmailSearchService.php';
require_once 'classes/EmailSearchQueue.php';
require_once 'classes/GmailIntegration.php';
require_once 'classes/GmailOAuth.php';
require_once 'classes/SenderRotation.php';
require_once 'classes/SenderHealth.php';
require_once 'classes/ReplyClassifier.php';
require_once 'classes/LeadForwarder.php';
require_once 'classes/AutomatedOutreach.php';
require_once 'classes/OutreachAutomation.php';
require_once 'config/database.php';

class BackgroundProcessor {
    private $db;
    private $emailQueue;
    private $emailSearchService;
    private $emailSearchQueue;
    private $gmail;
    private $gmailOAuth;
    private $senderRotation;
    private $senderHealth;
    private $replyClassifier;
    private $leadForwarder;
    private $automatedOutreach;
    private $outreachAutomation;
    private $settings;
    private $logFile;
    
    public function __construct() {
        $this->db = new Database();
        $this->emailQueue = new EmailQueue();
        $this->emailSearchService = new EmailSearchService();
        $this->emailSearchQueue = new EmailSearchQueue();
        $this->gmail = new GmailIntegration();
        $this->gmailOAuth = new GmailOAuth();
        $this->senderRotation = new SenderRotation();
        $this->senderHealth = new SenderHealth();
        $this->replyClassifier = new ReplyClassifier();
        $this->leadForwarder = new LeadForwarder();
        $this->automatedOutreach = new AutomatedOutreach();
        $this->outreachAutomation = new OutreachAutomation();
        $this->loadSettings();
        $this->logFile = __DIR__ . '/logs/background_processor.log';
        
        // Ensure log directory exists
        if (!is_dir(dirname($this->logFile))) {
            mkdir(dirname($this->logFile), 0755, true);
        }
    }
    
    private function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    public function run() {
        $this->log("Background processor starting...");
        
        try {
            // 1. Process automated campaigns (PRIORITY: HIGHEST)
            if (($this->settings['enable_automation_pipeline'] ?? 'yes') === 'yes') {
                $this->outreachAutomation->processAutomatedCampaigns();
            }
            
            // 2. Process legacy automated outreach
            if (($this->settings['enable_automated_outreach'] ?? 'yes') === 'yes') {
                $this->processAutomatedOutreach();
            }
            
            // 3. Process email search queue (Priority: High)
            $this->processEmailSearchQueue();
            
            // 4. Process email outreach queue
            $this->processEmailQueue();
            
            // 5. Retry failed emails with exponential backoff
            $this->retryFailedEmails();
            
            // 6. Monitor replies (if enabled)
            if (($this->settings['enable_auto_reply_monitoring'] ?? 'yes') === 'yes') {
                $this->monitorReplies();
            }
            
            // 7. Process lead forwarding
            if (($this->settings['auto_forward_interested_replies'] ?? 'yes') === 'yes') {
                $this->processLeadForwarding();
            }
            
            // 8. Monitor Gmail account status
            $this->monitorGmailAccounts();
            
            // 8. Check sender health (every 30 minutes)
            if ($this->shouldRunHealthCheck()) {
                $this->monitorSenderHealth();
            }
            
            // 9. Cleanup old data
            $this->cleanup();
            
            $this->log("Background processor completed successfully");
            
        } catch (Exception $e) {
            $this->log("Background processor error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function processAutomatedOutreach() {
        $this->log("Processing automated outreach...");
        
        try {
            // Process domains with found emails and queue them for outreach
            $domainsPerBatch = (int)($this->settings['automated_outreach_batch_size'] ?? 15);
            $result = $this->automatedOutreach->processDomainsWithFoundEmails($domainsPerBatch);
            
            $this->log("Automated outreach processed: {$result['processed']} domains queued from {$result['total_ready']} ready domains");
            
            if (!empty($result['errors'])) {
                $errorCount = count($result['errors']);
                $this->log("Automated outreach had $errorCount errors", 'WARNING');
                
                // Log first 3 errors for debugging
                foreach (array_slice($result['errors'], 0, 3) as $error) {
                    $this->log("Outreach error: $error", 'ERROR');
                }
            }
            
            // Run immediate processing cycle if domains were queued
            if ($result['processed'] > 0) {
                $this->log("Running immediate email queue processing for automated outreach...");
                $immediateResult = $this->automatedOutreach->processQueueImmediate(5);
                $this->log("Immediate processing sent {$immediateResult['processed']} emails");
            }
            
            // Log automation statistics
            $stats = $this->automatedOutreach->getAutomationStatistics();
            $this->log("Automation stats - Total: {$stats['total_automated']}, Sent: {$stats['sent_automated']}, Success Rate: {$stats['success_rate']}%");
            
        } catch (Exception $e) {
            $this->log("Automated outreach processing error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function processEmailQueue() {
        $this->log("Processing email queue...");
        
        $emailsPerBatch = 10; // Process up to 10 emails per run
        $result = $this->emailQueue->processQueue($emailsPerBatch);
        
        $this->log("Processed {$result['processed']} emails");
        
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->log("Email error: $error", 'ERROR');
            }
        }
        
        // Log queue stats
        $stats = $this->emailQueue->getQueueStats();
        $statsText = [];
        foreach ($stats as $stat) {
            $statsText[] = "{$stat['status']}: {$stat['count']}";
        }
        $this->log("Queue status: " . implode(', ', $statsText));
    }
    
    private function retryFailedEmails() {
        $this->log("Retrying failed emails with exponential backoff...");
        
        try {
            // Retry failed emails from the last 24 hours
            $retriedCount = $this->emailQueue->retryFailedEmails(null, 24);
            
            if ($retriedCount > 0) {
                $this->log("Scheduled $retriedCount failed emails for retry with exponential backoff");
            } else {
                $this->log("No failed emails found for retry");
            }
            
        } catch (Exception $e) {
            $this->log("Failed email retry error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function processEmailSearchQueue() {
        $this->log("Processing email search queue...");
        
        try {
            // Reset any stuck jobs first
            $resetCount = $this->emailSearchQueue->resetStuckJobs();
            if ($resetCount > 0) {
                $this->log("Reset $resetCount stuck email search jobs");
            }
            
            // Get optimal batch size based on rate limits
            $maxBatchSize = (int)($this->settings['email_search_batch_size'] ?? 10);
            $recommendedBatchSize = $this->emailSearchService->getRecommendedBatchSize($maxBatchSize);
            
            if ($recommendedBatchSize < $maxBatchSize) {
                $this->log("Rate limits detected, reducing batch size from $maxBatchSize to $recommendedBatchSize");
            }
            
            // Process background queue
            $result = $this->emailSearchService->processBackgroundQueue($recommendedBatchSize);
            
            $this->log("Email search batch completed: {$result['processed']} processed, {$result['successful']} successful, {$result['failed']} failed");
            
            if (isset($result['error'])) {
                $this->log("Email search batch error: {$result['error']}", 'ERROR');
            }
            
            // Log queue statistics
            $queueStats = $this->emailSearchQueue->getQueueStatistics();
            $statusCounts = [];
            foreach ($queueStats['by_status'] as $status => $count) {
                $statusCounts[] = "$status: $count";
            }
            $this->log("Email search queue status: " . implode(', ', $statusCounts));
            
            // Log health metrics
            $health = $queueStats['health'];
            if ($health['stuck_jobs'] > 0) {
                $this->log("Warning: {$health['stuck_jobs']} stuck email search jobs detected", 'WARNING');
            }
            
            if ($health['failed_retryable'] > 0) {
                $this->log("Info: {$health['failed_retryable']} failed jobs ready for retry");
            }
            
        } catch (Exception $e) {
            $this->log("Email search queue processing error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function monitorReplies() {
        $this->log("Monitoring replies...");
        
        // Get all authorized Gmail accounts
        $sql = "SELECT DISTINCT email FROM gmail_tokens WHERE expires_at > NOW()";
        $accounts = $this->db->fetchAll($sql);
        
        $totalReplies = 0;
        $checkInterval = (int)($this->settings['reply_check_interval_minutes'] ?? 15);
        $sinceTime = date('Y-m-d H:i:s', strtotime("-{$checkInterval} minutes"));
        
        foreach ($accounts as $account) {
            try {
                $email = $account['email'];
                $this->log("Checking replies for $email");
                
                $replies = $this->gmail->fetchReplies($email, $sinceTime);
                
                foreach ($replies as $reply) {
                    $classification = $this->replyClassifier->processReply($reply);
                    $totalReplies++;
                    
                    $this->log("Processed reply from {$reply['from']}: {$classification['classification']['classification']}");
                }
                
            } catch (Exception $e) {
                $this->log("Error checking replies for {$account['email']}: " . $e->getMessage(), 'ERROR');
            }
        }
        
        $this->log("Processed $totalReplies new replies");
    }
    
    private function processLeadForwarding() {
        $this->log("Processing lead forwarding...");
        
        $result = $this->leadForwarder->processForwardingQueue(5);
        
        $this->log("Forwarded {$result['processed']} leads");
        
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->log("Forwarding error: $error", 'ERROR');
            }
        }
    }
    
    private function monitorGmailAccounts() {
        $this->log("Monitoring Gmail account status...");
        
        try {
            $accounts = $this->gmailOAuth->getAllTokens();
            $refreshed = 0;
            $failed = 0;
            
            foreach ($accounts as $account) {
                $email = $account['email'];
                $status = $account['status'];
                
                // Skip if already active
                if ($status === 'active') {
                    continue;
                }
                
                // Try to refresh expired tokens
                if ($status === 'expired') {
                    $this->log("Attempting to refresh token for $email");
                    
                    $refreshResult = $this->gmailOAuth->refreshToken($email);
                    
                    if ($refreshResult) {
                        $refreshed++;
                        $this->log("Successfully refreshed token for $email");
                    } else {
                        $failed++;
                        $this->log("Failed to refresh token for $email", 'WARNING');
                    }
                }
            }
            
            $this->log("Gmail account monitoring complete. Refreshed: $refreshed, Failed: $failed");
            
            // Log account status summary
            $activeCount = count(array_filter($accounts, function($account) {
                return $account['status'] === 'active';
            }));
            $expiredCount = count($accounts) - $activeCount;
            
            $this->log("Gmail account status: $activeCount active, $expiredCount expired/inactive");
            
        } catch (Exception $e) {
            $this->log("Gmail account monitoring error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function shouldRunHealthCheck() {
        $lastHealthCheck = $this->getLastHealthCheckTime();
        $minutesSinceLastCheck = (time() - strtotime($lastHealthCheck)) / 60;
        
        // Run health check every 30 minutes
        return $minutesSinceLastCheck >= 30;
    }
    
    private function getLastHealthCheckTime() {
        $sql = "SELECT MAX(checked_at) as last_check FROM sender_health";
        $result = $this->db->fetchOne($sql);
        return $result['last_check'] ?? '1970-01-01 00:00:00';
    }
    
    private function monitorSenderHealth() {
        $this->log("Monitoring sender health...");
        
        try {
            $healthResults = $this->senderHealth->checkAllSenders();
            $healthy = 0;
            $warning = 0;
            $critical = 0;
            $suspended = 0;
            
            foreach ($healthResults as $result) {
                switch ($result['status']) {
                    case 'healthy':
                        $healthy++;
                        break;
                    case 'warning':
                        $warning++;
                        $this->log("Warning: Sender {$result['email']} has health issues: " . implode(', ', $result['warning_flags']), 'WARNING');
                        break;
                    case 'critical':
                        $critical++;
                        $this->log("Critical: Sender {$result['email']} is in critical state: " . implode(', ', $result['warning_flags']), 'ERROR');
                        break;
                    case 'suspended':
                        $suspended++;
                        $this->log("Suspended: Sender {$result['email']} is suspended: " . implode(', ', $result['warning_flags']), 'ERROR');
                        break;
                }
            }
            
            $this->log("Sender health check complete. Healthy: $healthy, Warning: $warning, Critical: $critical, Suspended: $suspended");
            
            // Generate alerts for critical issues
            if ($critical > 0 || $suspended > 0) {
                $this->generateHealthAlerts($healthResults);
            }
            
        } catch (Exception $e) {
            $this->log("Sender health monitoring error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function generateHealthAlerts($healthResults) {
        $criticalSenders = array_filter($healthResults, function($result) {
            return in_array($result['status'], ['critical', 'suspended']);
        });
        
        foreach ($criticalSenders as $sender) {
            $this->log("ALERT: Sender {$sender['email']} requires immediate attention. Status: {$sender['status']}, Score: {$sender['health_score']}", 'ERROR');
            
            // Could implement email notifications here
            // $this->sendHealthAlert($sender);
        }
    }
    
    private function cleanup() {
        $this->log("Running cleanup tasks...");
        
        try {
            // Clean up old email search queue items (older than 30 days)
            $cleaned = $this->emailSearchQueue->cleanupOldItems(30);
            if ($cleaned > 0) {
                $this->log("Cleaned up $cleaned old email search queue items");
            }
            
            // Clean up old API logs (older than 90 days)
            $sql = "DELETE FROM api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)";
            $deleted = $this->db->execute($sql);
            $this->log("Cleaned up old API logs");
            
            // Clean up old API usage tracking (older than 180 days)
            $sql = "DELETE FROM api_usage_tracking WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)";
            $deleted = $this->db->execute($sql);
            $this->log("Cleaned up old API usage tracking");
            
            // Clean up old email search logs (older than 90 days)
            $sql = "DELETE FROM email_search_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)";
            $deleted = $this->db->execute($sql);
            $this->log("Cleaned up old email search logs");
            
            // Clean up old forwarding records
            $cleaned = $this->leadForwarder->cleanupOldForwards(90);
            $this->log("Cleaned up old forwarding records");
            
            // Clean up old background job records
            $sql = "DELETE FROM background_jobs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $this->db->execute($sql);
            $this->log("Cleaned up old background job records");
            
        } catch (Exception $e) {
            $this->log("Cleanup error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Also output to console if running from command line
        if (php_sapi_name() === 'cli') {
            echo $logEntry;
        }
    }
    
    public function getStatus() {
        return [
            'email_queue_stats' => $this->emailQueue->getQueueStats(),
            'email_search_queue_stats' => $this->emailSearchQueue->getQueueStatistics(),
            'email_search_stats' => $this->emailSearchService->getSearchStatistics(7),
            'forwarding_stats' => $this->leadForwarder->getQueueStatus(),
            'last_run' => $this->getLastRunTime(),
            'log_file' => $this->logFile
        ];
    }
    
    private function getLastRunTime() {
        if (file_exists($this->logFile)) {
            $lines = file($this->logFile);
            if (!empty($lines)) {
                $lastLine = trim(end($lines));
                if (preg_match('/\[([\d\-\s:]+)\]/', $lastLine, $matches)) {
                    return $matches[1];
                }
            }
        }
        return 'Never';
    }
}

// Check if running from command line or web
if (php_sapi_name() === 'cli') {
    // Command line execution
    $processor = new BackgroundProcessor();
    $processor->run();
} else {
    // Web interface for monitoring
    $processor = new BackgroundProcessor();
    $status = $processor->getStatus();
    
    header('Content-Type: application/json');
    echo json_encode($status, JSON_PRETTY_PRINT);
}
?>