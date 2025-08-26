<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/TargetDomain.php';
require_once __DIR__ . '/EmailQueue.php';
require_once __DIR__ . '/GmailIntegration.php';
require_once __DIR__ . '/SenderRotation.php';
require_once __DIR__ . '/EmailTemplate.php';

class AutomatedOutreach {
    private $db;
    private $targetDomain;
    private $emailQueue;
    private $gmailIntegration;
    private $senderRotation;
    private $emailTemplate;
    private $settings;
    
    public function __construct() {
        $this->db = new Database();
        $this->targetDomain = new TargetDomain();
        $this->emailQueue = new EmailQueue();
        $this->gmailIntegration = new GmailIntegration();
        $this->senderRotation = new SenderRotation();
        $this->emailTemplate = new EmailTemplate();
        $this->loadSettings();
    }
    
    private function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    /**
     * Process all domains that have found emails and queue them for outreach
     */
    public function processDomainsWithFoundEmails($limit = 20) {
        $this->logInfo("Starting automated outreach processing");
        
        try {
            $readyDomains = $this->targetDomain->getDomainsReadyForOutreach();
            $this->logInfo("Found " . count($readyDomains) . " domains ready for outreach");
            
            $processedCount = 0;
            $errors = [];
            
            foreach (array_slice($readyDomains, 0, $limit) as $domain) {
                try {
                    $result = $this->processIndividualDomain($domain);
                    if ($result['success']) {
                        $processedCount++;
                        $this->logInfo("Successfully queued outreach for {$domain['domain']}");
                    } else {
                        $errors[] = "Failed to process {$domain['domain']}: " . $result['error'];
                    }
                } catch (Exception $e) {
                    $errors[] = "Exception processing {$domain['domain']}: " . $e->getMessage();
                    $this->logError("Exception processing {$domain['domain']}: " . $e->getMessage());
                }
            }
            
            return [
                'success' => true,
                'processed' => $processedCount,
                'total_ready' => count($readyDomains),
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            $this->logError("Error in processDomainsWithFoundEmails: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process an individual domain for outreach
     */
    private function processIndividualDomain($domain) {
        try {
            // Validate domain has required data
            if (empty($domain['contact_email'])) {
                return ['success' => false, 'error' => 'No contact email found'];
            }
            
            if (empty($domain['campaign_id'])) {
                return ['success' => false, 'error' => 'No campaign associated'];
            }
            
            // Check if already processed
            if ($this->isAlreadyQueued($domain['campaign_id'], $domain['id'])) {
                return ['success' => false, 'error' => 'Already queued for outreach'];
            }
            
            // Get campaign details
            $campaign = $this->getCampaignDetails($domain['campaign_id']);
            if (!$campaign) {
                return ['success' => false, 'error' => 'Campaign not found'];
            }
            
            // Skip if campaign is not active
            if ($campaign['status'] !== 'active') {
                return ['success' => false, 'error' => 'Campaign is not active'];
            }
            
            // Get email template
            $template = $this->getEmailTemplate($campaign['email_template_id']);
            if (!$template) {
                return ['success' => false, 'error' => 'Email template not found'];
            }
            
            // Get next available sender
            $sender = $this->senderRotation->getNextAvailableSender();
            if (!$sender) {
                return ['success' => false, 'error' => 'No available Gmail senders'];
            }
            
            // Personalize email content
            $personalizedSubject = $this->personalizeEmailContent(
                $template['subject'], 
                $domain, 
                $campaign
            );
            
            $personalizedBody = $this->personalizeEmailContent(
                $template['body'], 
                $domain, 
                $campaign
            );
            
            // Calculate priority and schedule time
            $priority = $this->calculatePriority($domain);
            $scheduledAt = $this->calculateScheduleTime($sender);
            
            // Queue the email
            $queueId = $this->queueOutreachEmail(
                $domain['campaign_id'],
                $domain['id'],
                $sender['email'],
                $domain['contact_email'],
                $personalizedSubject,
                $personalizedBody,
                $template['id'],
                $priority,
                $scheduledAt
            );
            
            // Update domain status to prevent reprocessing
            $this->targetDomain->updateStatus($domain['id'], 'queued_for_outreach');
            
            // Log the automation
            $this->logAutomatedAction($domain, $sender, $queueId, 'queued');
            
            return [
                'success' => true,
                'queue_id' => $queueId,
                'sender' => $sender['email'],
                'scheduled_at' => $scheduledAt
            ];
            
        } catch (Exception $e) {
            $this->logError("Error processing domain {$domain['domain']}: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Immediate outreach trigger when domain is approved and email is found
     */
    public function triggerImmediateOutreach($domainId, $campaignId, $foundEmail) {
        $this->logInfo("Triggering immediate outreach for domain ID: $domainId");
        
        try {
            // Get domain details
            $domain = $this->targetDomain->getById($domainId);
            if (!$domain) {
                throw new Exception("Domain not found");
            }
            
            // Update domain with found email if not already set
            if (empty($domain['contact_email']) && $foundEmail) {
                $this->targetDomain->updateWithFoundEmail($domainId, $foundEmail);
                $domain['contact_email'] = $foundEmail;
            }
            
            // Process the domain immediately
            $result = $this->processIndividualDomain($domain);
            
            if ($result['success']) {
                $this->logInfo("Successfully triggered immediate outreach for {$domain['domain']}");
                
                // Try to process the queue immediately if there are available slots
                $this->processQueueImmediate(1);
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->logError("Error in triggerImmediateOutreach: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process email queue immediately for quick sends
     */
    public function processQueueImmediate($limit = 5) {
        try {
            $result = $this->emailQueue->processQueue($limit);
            $this->logInfo("Immediate queue processing: {$result['processed']} emails sent");
            return $result;
        } catch (Exception $e) {
            $this->logError("Error in immediate queue processing: " . $e->getMessage());
            return ['processed' => 0, 'errors' => [$e->getMessage()]];
        }
    }
    
    /**
     * Automated background processing - to be called by cron or background service
     */
    public function runAutomatedOutreachCycle() {
        $this->logInfo("Starting automated outreach cycle");
        
        $results = [
            'domains_processed' => 0,
            'emails_sent' => 0,
            'errors' => []
        ];
        
        try {
            // Step 1: Process domains with found emails
            $domainResult = $this->processDomainsWithFoundEmails(10);
            $results['domains_processed'] = $domainResult['processed'] ?? 0;
            $results['errors'] = array_merge($results['errors'], $domainResult['errors'] ?? []);
            
            // Step 2: Process email queue
            $queueResult = $this->emailQueue->processQueue(20);
            $results['emails_sent'] = $queueResult['processed'] ?? 0;
            $results['errors'] = array_merge($results['errors'], $queueResult['errors'] ?? []);
            
            // Step 3: Update campaign statistics
            $this->updateCampaignStatistics();
            
            $this->logInfo("Automated cycle completed: {$results['domains_processed']} domains processed, {$results['emails_sent']} emails sent");
            
        } catch (Exception $e) {
            $results['errors'][] = "Cycle error: " . $e->getMessage();
            $this->logError("Error in automated outreach cycle: " . $e->getMessage());
        }
        
        return $results;
    }
    
    private function personalizeEmailContent($template, $domain, $campaign) {
        $replacements = [
            '{DOMAIN}' => $domain['domain'],
            '{CONTACT_EMAIL}' => $domain['contact_email'],
            '{CAMPAIGN_NAME}' => $campaign['name'],
            '{SENDER_NAME}' => $this->settings['sender_name'] ?? 'Outreach Team',
            '{DOMAIN_RATING}' => $domain['domain_rating'] ?? 'N/A',
            '{ORGANIC_TRAFFIC}' => number_format($domain['organic_traffic'] ?? 0),
            '{REFERRING_DOMAINS}' => number_format($domain['referring_domains'] ?? 0),
            '{QUALITY_SCORE}' => round($domain['quality_score'] ?? 0, 1)
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
    
    private function calculatePriority($domain) {
        $qualityScore = $domain['quality_score'] ?? 0;
        
        if ($qualityScore >= 90) return 'high';
        if ($qualityScore >= 70) return 'medium';
        return 'low';
    }
    
    private function calculateScheduleTime($sender) {
        $baseDelay = (int)($this->settings['email_delay_minutes'] ?? 30);
        
        // Add randomization to avoid patterns
        $randomDelay = rand(0, $baseDelay);
        $totalDelay = $baseDelay + $randomDelay;
        
        // Consider sender's recent activity
        $lastUsed = strtotime($sender['last_used'] ?? '1970-01-01 00:00:00');
        $timeSinceLastUse = time() - $lastUsed;
        
        if ($timeSinceLastUse < 3600) { // Less than 1 hour
            $totalDelay += 60; // Add 1 hour extra delay
        }
        
        return date('Y-m-d H:i:s', time() + ($totalDelay * 60));
    }
    
    private function queueOutreachEmail($campaignId, $domainId, $fromEmail, $toEmail, $subject, $body, $templateId, $priority, $scheduledAt) {
        $sql = "INSERT INTO email_queue (
                    campaign_id, domain_id, template_id, sender_email, recipient_email,
                    subject, body, status, priority, scheduled_at, automation_triggered,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'queued', ?, ?, 1, NOW())";
        
        $this->db->execute($sql, [
            $campaignId, $domainId, $templateId, $fromEmail, $toEmail,
            $subject, $body, $priority, $scheduledAt
        ]);
        
        return $this->db->lastInsertId();
    }
    
    private function isAlreadyQueued($campaignId, $domainId) {
        $sql = "SELECT COUNT(*) as count FROM email_queue 
                WHERE campaign_id = ? AND domain_id = ? 
                AND status IN ('queued', 'sent', 'processing')";
        $result = $this->db->fetchOne($sql, [$campaignId, $domainId]);
        return $result['count'] > 0;
    }
    
    private function getCampaignDetails($campaignId) {
        $sql = "SELECT * FROM campaigns WHERE id = ?";
        return $this->db->fetchOne($sql, [$campaignId]);
    }
    
    private function getEmailTemplate($templateId) {
        if ($templateId) {
            return $this->emailTemplate->getById($templateId);
        } else {
            return $this->emailTemplate->getDefault();
        }
    }
    
    private function logAutomatedAction($domain, $sender, $queueId, $action) {
        $sql = "INSERT INTO api_logs (api_service, endpoint, method, request_data, response_data, status_code) 
                VALUES ('automated_outreach', 'domain_processing', 'POST', ?, ?, 200)";
        
        $request_data = json_encode([
            'domain_id' => $domain['id'],
            'domain' => $domain['domain'],
            'campaign_id' => $domain['campaign_id'],
            'action' => $action,
            'automation_timestamp' => date('Y-m-d H:i:s')
        ]);
        
        $response_data = json_encode([
            'success' => true,
            'queue_id' => $queueId,
            'sender_email' => $sender['email'],
            'contact_email' => $domain['contact_email'],
            'quality_score' => $domain['quality_score']
        ]);
        
        $this->db->execute($sql, [$request_data, $response_data]);
    }
    
    private function updateCampaignStatistics() {
        // Update campaign statistics for better reporting
        $sql = "UPDATE campaigns c SET 
                c.forwarded_emails = (
                    SELECT COUNT(*) FROM email_queue eq 
                    WHERE eq.campaign_id = c.id AND eq.status = 'sent'
                )";
        
        $this->db->execute($sql);
    }
    
    /**
     * Get automation statistics
     */
    public function getAutomationStatistics($campaignId = null) {
        $whereClause = $campaignId ? "WHERE eq.campaign_id = ?" : "";
        $params = $campaignId ? [$campaignId] : [];
        
        $sql = "SELECT 
                    COUNT(*) as total_automated,
                    SUM(CASE WHEN eq.status = 'sent' THEN 1 ELSE 0 END) as sent_automated,
                    SUM(CASE WHEN eq.status = 'queued' THEN 1 ELSE 0 END) as queued_automated,
                    SUM(CASE WHEN eq.status = 'failed' THEN 1 ELSE 0 END) as failed_automated,
                    ROUND(AVG(CASE WHEN eq.status = 'sent' THEN 1 ELSE 0 END) * 100, 2) as success_rate
                FROM email_queue eq 
                $whereClause
                AND eq.automation_triggered = 1";
        
        return $this->db->fetchOne($sql, $params);
    }
    
    /**
     * Get recently sent automated emails
     */
    public function getRecentSentEmails($limit = 20, $campaignId = null) {
        $whereClause = "WHERE eq.status = 'sent' AND eq.automation_triggered = 1";
        $params = [];
        
        if ($campaignId) {
            $whereClause .= " AND eq.campaign_id = ?";
            $params[] = $campaignId;
        }
        
        $sql = "SELECT 
                    eq.*,
                    c.name as campaign_name,
                    td.domain,
                    td.quality_score,
                    td.domain_rating
                FROM email_queue eq
                JOIN campaigns c ON eq.campaign_id = c.id
                JOIN target_domains td ON eq.domain_id = td.id
                $whereClause
                ORDER BY eq.processed_at DESC
                LIMIT ?";
        
        $params[] = $limit;
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get queued emails ready for outreach
     */
    public function getQueuedEmails($limit = 50, $campaignId = null) {
        $whereClause = "WHERE eq.status IN ('queued', 'processing') AND eq.automation_triggered = 1";
        $params = [];
        
        if ($campaignId) {
            $whereClause .= " AND eq.campaign_id = ?";
            $params[] = $campaignId;
        }
        
        $sql = "SELECT 
                    eq.*,
                    c.name as campaign_name,
                    td.domain,
                    td.quality_score,
                    td.domain_rating,
                    CASE 
                        WHEN eq.scheduled_at <= NOW() THEN 'ready'
                        ELSE 'scheduled'
                    END as send_status,
                    TIMESTAMPDIFF(MINUTE, NOW(), eq.scheduled_at) as minutes_until_send
                FROM email_queue eq
                JOIN campaigns c ON eq.campaign_id = c.id
                JOIN target_domains td ON eq.domain_id = td.id
                $whereClause
                ORDER BY eq.scheduled_at ASC
                LIMIT ?";
        
        $params[] = $limit;
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get failed emails for troubleshooting
     */
    public function getFailedEmails($limit = 20, $campaignId = null) {
        $whereClause = "WHERE eq.status IN ('failed', 'failed_permanent') AND eq.automation_triggered = 1";
        $params = [];
        
        if ($campaignId) {
            $whereClause .= " AND eq.campaign_id = ?";
            $params[] = $campaignId;
        }
        
        $sql = "SELECT 
                    eq.*,
                    c.name as campaign_name,
                    td.domain,
                    td.quality_score
                FROM email_queue eq
                JOIN campaigns c ON eq.campaign_id = c.id
                JOIN target_domains td ON eq.domain_id = td.id
                $whereClause
                ORDER BY eq.processed_at DESC
                LIMIT ?";
        
        $params[] = $limit;
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get comprehensive email status overview
     */
    public function getEmailStatusOverview($campaignId = null) {
        $whereClause = $campaignId ? "WHERE eq.campaign_id = ? AND eq.automation_triggered = 1" : "WHERE eq.automation_triggered = 1";
        $params = $campaignId ? [$campaignId] : [];
        
        $sql = "SELECT 
                    eq.status,
                    COUNT(*) as count,
                    AVG(td.quality_score) as avg_quality_score,
                    COUNT(DISTINCT eq.sender_email) as unique_senders,
                    MIN(eq.created_at) as earliest_created,
                    MAX(eq.processed_at) as latest_processed
                FROM email_queue eq
                JOIN target_domains td ON eq.domain_id = td.id
                $whereClause
                GROUP BY eq.status
                ORDER BY 
                    CASE eq.status 
                        WHEN 'sent' THEN 1 
                        WHEN 'queued' THEN 2 
                        WHEN 'processing' THEN 3 
                        WHEN 'failed' THEN 4 
                        ELSE 5 
                    END";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Manual trigger for specific domain
     */
    public function manualTriggerOutreach($domainId) {
        $this->logInfo("Manual trigger outreach for domain ID: $domainId");
        
        try {
            $domain = $this->targetDomain->getById($domainId);
            if (!$domain) {
                throw new Exception("Domain not found");
            }
            
            // Process the domain
            $result = $this->processIndividualDomain($domain);
            
            if ($result['success']) {
                $this->logInfo("Manual trigger successful for {$domain['domain']}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->logError("Error in manual trigger: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Logging methods
    private function logInfo($message) {
        error_log("[AutomatedOutreach][INFO] " . $message);
    }
    
    private function logError($message) {
        error_log("[AutomatedOutreach][ERROR] " . $message);
    }
}
?>