<?php
/**
 * Continuous Automation Pipeline
 * This script runs continuously and processes domains based on their status
 * It ensures the automation pipeline never stops
 */

// Set unlimited execution time for continuous operation
set_time_limit(0);
ini_set('memory_limit', '512M');

// Include required classes
require_once 'config/database.php';
require_once 'classes/TargetDomain.php';
require_once 'classes/ChatGPTIntegration.php';
require_once 'classes/EmailSearchService.php';
require_once 'classes/OutreachAutomation.php';
require_once 'classes/GmailIntegration.php';

class ContinuousAutomationPipeline {
    private $db;
    private $targetDomain;
    private $chatgpt;
    private $emailSearch;
    private $outreach;
    private $gmail;
    private $isRunning = true;
    private $logFile;
    private $cycleDelay = 10; // seconds between cycles
    private $maxDomainsPerCycle = 5;
    
    public function __construct() {
        $this->db = new Database();
        $this->targetDomain = new TargetDomain();
        $this->chatgpt = new ChatGPTIntegration();
        $this->emailSearch = new EmailSearchService();
        $this->outreach = new OutreachAutomation();
        $this->gmail = new GmailIntegration();
        
        $this->logFile = __DIR__ . '/logs/continuous_automation.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Handle graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }
    
    public function run() {
        $this->log("🚀 Continuous Automation Pipeline Started");
        $this->log("Process ID: " . getmypid());
        $this->log("Memory Limit: " . ini_get('memory_limit'));
        
        $cycleCount = 0;
        
        while ($this->isRunning) {
            $cycleCount++;
            $cycleStart = microtime(true);
            
            try {
                $this->log("🔄 Cycle #$cycleCount started");
                
                // Process each status in pipeline order
                $totalProcessed = 0;
                $totalProcessed += $this->processPendingDomains();
                $totalProcessed += $this->processApprovedDomains();
                $totalProcessed += $this->processEmailSearchDomains();
                $totalProcessed += $this->processEmailGenerationDomains();
                $totalProcessed += $this->processEmailSendingDomains();
                $totalProcessed += $this->processMonitoringDomains();
                
                $cycleTime = round(microtime(true) - $cycleStart, 2);
                $this->log("✅ Cycle #$cycleCount completed: $totalProcessed domains processed in {$cycleTime}s");
                
                // Cleanup memory
                if ($cycleCount % 100 === 0) {
                    $this->log("🧹 Memory cleanup - Current usage: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB");
                    gc_collect_cycles();
                }
                
                // Sleep between cycles
                sleep($this->cycleDelay);
                
            } catch (Exception $e) {
                $this->log("❌ Cycle error: " . $e->getMessage());
                sleep(30); // Wait longer on error
            }
        }
        
        $this->log("⏹️ Continuous Automation Pipeline Stopped");
    }
    
    /**
     * Process domains with 'pending' status - trigger analysis
     */
    private function processPendingDomains() {
        $domains = $this->db->fetchAll("
            SELECT id, domain, campaign_id 
            FROM target_domains 
            WHERE status = 'pending' 
            ORDER BY created_at ASC 
            LIMIT ?
        ", [$this->maxDomainsPerCycle]);
        
        if (empty($domains)) {
            return 0;
        }
        
        $processed = 0;
        foreach ($domains as $domain) {
            try {
                $this->log("🔍 Starting analysis for domain: {$domain['domain']} (ID: {$domain['id']})");
                
                // Update status to analyzing
                $this->targetDomain->updateStatus($domain['id'], 'analyzing');
                
                // Run GPT analysis
                $result = $this->chatgpt->analyzeGuestPostSuitability($domain['domain']);
                
                if (isset($result['overall_score'])) {
                    // Determine if approved or rejected based on score
                    $overallScore = $result['overall_score'];
                    $newStatus = $overallScore >= 6 ? 'approved' : 'rejected';
                    
                    // Update domain with analysis results
                    $updateData = [
                        'ai_analysis_status' => 'completed',
                        'ai_overall_score' => $overallScore,
                        'ai_guest_post_score' => $overallScore,
                        'ai_recommendations' => $result['summary'] ?? 'Analysis completed',
                        'ai_last_analyzed_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $this->targetDomain->updateAIAnalysis($domain['id'], $updateData);
                    $this->targetDomain->updateStatus($domain['id'], $newStatus);
                    
                    $this->log("✅ Analysis completed for {$domain['domain']}: $newStatus (score: $overallScore)");
                    $processed++;
                } else {
                    $this->log("❌ Analysis failed for {$domain['domain']}");
                    $this->targetDomain->updateStatus($domain['id'], 'rejected');
                }
                
                // Rate limiting
                sleep(2);
                
            } catch (Exception $e) {
                $this->log("❌ Error analyzing {$domain['domain']}: " . $e->getMessage());
                $this->targetDomain->updateStatus($domain['id'], 'pending'); // Reset for retry
            }
        }
        
        return $processed;
    }
    
    /**
     * Process domains with 'approved' status - trigger email search
     */
    private function processApprovedDomains() {
        $domains = $this->db->fetchAll("
            SELECT id, domain 
            FROM target_domains 
            WHERE status = 'approved' 
            ORDER BY created_at ASC 
            LIMIT ?
        ", [$this->maxDomainsPerCycle]);
        
        if (empty($domains)) {
            return 0;
        }
        
        $processed = 0;
        foreach ($domains as $domain) {
            try {
                $this->log("📧 Starting email search for domain: {$domain['domain']} (ID: {$domain['id']})");
                
                // Update status to searching_email
                $this->targetDomain->updateStatus($domain['id'], 'searching_email');
                
                // Search for email
                $result = $this->targetDomain->startEmailSearch($domain['id'], 'high');
                
                if ($result['success'] && !empty($result['email'])) {
                    $this->log("✅ Email found for {$domain['domain']}: {$result['email']}");
                    $this->targetDomain->updateStatus($domain['id'], 'generating_email');
                } else {
                    $this->log("❌ No email found for {$domain['domain']}");
                    // Keep in searching_email status for retry
                }
                
                $processed++;
                sleep(3); // Rate limiting
                
            } catch (Exception $e) {
                $this->log("❌ Error searching email for {$domain['domain']}: " . $e->getMessage());
                $this->targetDomain->updateStatus($domain['id'], 'approved'); // Reset for retry
            }
        }
        
        return $processed;
    }
    
    /**
     * Process domains with 'searching_email' status - check if email found, move to generation
     */
    private function processEmailSearchDomains() {
        $domains = $this->db->fetchAll("
            SELECT id, domain, contact_email 
            FROM target_domains 
            WHERE status = 'searching_email' 
            AND contact_email IS NOT NULL 
            AND contact_email != ''
            ORDER BY created_at ASC 
            LIMIT ?
        ", [$this->maxDomainsPerCycle]);
        
        $processed = 0;
        foreach ($domains as $domain) {
            $this->log("🔄 Moving {$domain['domain']} to email generation (email found: {$domain['contact_email']})");
            $this->targetDomain->updateStatus($domain['id'], 'generating_email');
            $processed++;
        }
        
        return $processed;
    }
    
    /**
     * Process domains with 'generating_email' status - generate personalized emails
     */
    private function processEmailGenerationDomains() {
        $domains = $this->db->fetchAll("
            SELECT td.id, td.domain, td.campaign_id, td.contact_email, td.ai_recommendations
            FROM target_domains td
            WHERE td.status = 'generating_email' 
            AND td.contact_email IS NOT NULL
            ORDER BY td.created_at ASC 
            LIMIT ?
        ", [$this->maxDomainsPerCycle]);
        
        if (empty($domains)) {
            return 0;
        }
        
        $processed = 0;
        foreach ($domains as $domain) {
            try {
                $this->log("✏️ Generating email for domain: {$domain['domain']} (ID: {$domain['id']})");
                
                // Check if email already exists
                $existingEmail = $this->db->fetchAll("
                    SELECT id FROM outreach_emails WHERE domain_id = ? LIMIT 1
                ", [$domain['id']]);
                
                if (empty($existingEmail)) {
                    // Generate email using AI recommendations and templates
                    $emailGenerated = $this->generatePersonalizedEmail($domain);
                    
                    if ($emailGenerated) {
                        $this->log("✅ Email generated for {$domain['domain']}");
                        $this->targetDomain->updateStatus($domain['id'], 'sending_email');
                    } else {
                        $this->log("❌ Failed to generate email for {$domain['domain']}");
                    }
                } else {
                    // Email already exists, move to sending
                    $this->targetDomain->updateStatus($domain['id'], 'sending_email');
                }
                
                $processed++;
                
            } catch (Exception $e) {
                $this->log("❌ Error generating email for {$domain['domain']}: " . $e->getMessage());
            }
        }
        
        return $processed;
    }
    
    /**
     * Process domains with 'sending_email' status - send the emails
     */
    private function processEmailSendingDomains() {
        $domains = $this->db->fetchAll("
            SELECT td.id, td.domain, td.contact_email, oe.id as email_id, oe.subject, oe.body, oe.sender_email
            FROM target_domains td
            JOIN outreach_emails oe ON td.id = oe.domain_id
            WHERE td.status = 'sending_email' 
            AND oe.status = 'draft'
            ORDER BY td.created_at ASC 
            LIMIT ?
        ", [$this->maxDomainsPerCycle]);
        
        if (empty($domains)) {
            return 0;
        }
        
        $processed = 0;
        foreach ($domains as $domain) {
            try {
                $this->log("📤 Sending email to: {$domain['contact_email']} for domain {$domain['domain']}");
                
                // Send email via Gmail
                $emailSent = $this->gmail->sendEmail(
                    $domain['sender_email'],
                    $domain['contact_email'],
                    $domain['subject'],
                    $domain['body']
                );
                
                if ($emailSent) {
                    // Update email status to sent
                    $this->db->execute("
                        UPDATE outreach_emails 
                        SET status = 'sent', sent_at = NOW() 
                        WHERE id = ?
                    ", [$domain['email_id']]);
                    
                    // Update domain status to monitoring
                    $this->targetDomain->updateStatus($domain['id'], 'monitoring_replies');
                    
                    $this->log("✅ Email sent successfully to {$domain['contact_email']}");
                } else {
                    $this->log("❌ Failed to send email to {$domain['contact_email']}");
                    // Update email status to failed
                    $this->db->execute("
                        UPDATE outreach_emails 
                        SET status = 'failed' 
                        WHERE id = ?
                    ", [$domain['email_id']]);
                }
                
                $processed++;
                sleep(5); // Rate limiting for email sending
                
            } catch (Exception $e) {
                $this->log("❌ Error sending email for {$domain['domain']}: " . $e->getMessage());
            }
        }
        
        return $processed;
    }
    
    /**
     * Process domains with 'monitoring_replies' status - check for completion
     */
    private function processMonitoringDomains() {
        // Mark domains as contacted after 7 days of monitoring
        $result = $this->db->execute("
            UPDATE target_domains 
            SET status = 'contacted' 
            WHERE status = 'monitoring_replies' 
            AND updated_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        if ($result) {
            $this->log("📋 Moved aged monitoring domains to contacted status");
        }
        
        return 0;
    }
    
    /**
     * Generate personalized email for domain
     */
    private function generatePersonalizedEmail($domain) {
        try {
            require_once 'classes/Campaign.php';
            require_once 'classes/EmailTemplate.php';
            
            $campaign = new Campaign();
            $emailTemplate = new EmailTemplate();
            
            $campaignData = $campaign->getById($domain['campaign_id']);
            $template = $emailTemplate->getDefault();
            
            if (!$campaignData || !$template) {
                return false;
            }
            
            // Extract topic area from AI recommendations
            $topicArea = 'business';
            if (!empty($domain['ai_recommendations'])) {
                $recommendations = strtolower($domain['ai_recommendations']);
                if (strpos($recommendations, 'tech') !== false) {
                    $topicArea = 'technology';
                } elseif (strpos($recommendations, 'health') !== false) {
                    $topicArea = 'health and wellness';
                } elseif (strpos($recommendations, 'finance') !== false) {
                    $topicArea = 'finance';
                } elseif (strpos($recommendations, 'travel') !== false) {
                    $topicArea = 'travel';
                } elseif (strpos($recommendations, 'food') !== false) {
                    $topicArea = 'food and lifestyle';
                }
            }
            
            $senderEmail = $campaignData['owner_email'] ?? 'outreach@example.com';
            $senderName = 'Outreach Team';
            
            // Replace template variables
            $subject = str_replace(
                ['{DOMAIN}', '{TOPIC_AREA}'], 
                [$domain['domain'], $topicArea], 
                $template['subject']
            );
            
            $body = str_replace(
                ['{DOMAIN}', '{TOPIC_AREA}', '{SENDER_NAME}', '{SENDER_EMAIL}'], 
                [$domain['domain'], $topicArea, $senderName, $senderEmail], 
                $template['body']
            );
            
            // Save generated email
            $this->db->execute("
                INSERT INTO outreach_emails (domain_id, campaign_id, template_id, subject, body, sender_email, recipient_email, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', NOW())
            ", [
                $domain['id'],
                $domain['campaign_id'],
                $template['id'],
                $subject,
                $body,
                $senderEmail,
                $domain['contact_email']
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->log("❌ Email generation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log message with timestamp
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        
        // Output to console
        echo $logMessage;
        
        // Write to log file
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Graceful shutdown
     */
    public function shutdown() {
        $this->isRunning = false;
        $this->log("🛑 Shutdown signal received");
    }
}

// Create and run the pipeline
$pipeline = new ContinuousAutomationPipeline();
$pipeline->run();
?>