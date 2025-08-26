<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/GMassIntegration.php';
require_once __DIR__ . '/EmailTemplate.php';
require_once __DIR__ . '/SenderRotation.php';

class EmailQueue {
    private $db;
    private $gmass;
    private $emailTemplate;
    private $senderRotation;
    private $settings;
    
    public function __construct() {
        $this->db = new Database();
        $this->gmass = new GMassIntegration();
        $this->emailTemplate = new EmailTemplate();
        $this->senderRotation = new SenderRotation();
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
    
    public function queueCampaignEmails($campaignId, $templateId = null, $specificDomainIds = null) {
        // Get campaign details
        $campaign = $this->getCampaignDetails($campaignId);
        if (!$campaign) {
            throw new Exception("Campaign not found");
        }
        
        // Get approved domains for this campaign
        $domains = $specificDomainIds ? 
            $this->getSpecificDomains($specificDomainIds) : 
            $this->getApprovedDomains($campaignId);
        if (empty($domains)) {
            throw new Exception("No approved domains found for this campaign");
        }
        
        // Get email template
        $template = $templateId ? 
            $this->emailTemplate->getById($templateId) : 
            $this->emailTemplate->getDefault();
            
        if (!$template) {
            throw new Exception("Email template not found");
        }
        
        $queuedCount = 0;
        $errors = [];
        
        foreach ($domains as $domain) {
            if (!$domain['contact_email']) {
                continue; // Skip domains without contact email
            }
            
            // Check if already contacted
            if ($this->isAlreadyContacted($campaignId, $domain['id'])) {
                continue;
            }
            
            try {
                // Get next available sender using rotation
                $sender = $this->senderRotation->getNextAvailableSender();
                
                // Personalize the email
                $personalizedSubject = $this->emailTemplate->personalizeTemplate(
                    $template['subject'], 
                    $domain['domain'], 
                    $domain['contact_email'],
                    $this->settings['sender_name'] ?? 'Outreach Team'
                );
                
                $personalizedBody = $this->emailTemplate->personalizeTemplate(
                    $template['body'], 
                    $domain['domain'], 
                    $domain['contact_email'],
                    $this->settings['sender_name'] ?? 'Outreach Team'
                );
                
                // Queue the email with assigned sender
                $this->queueEmailWithSender(
                    $campaignId,
                    $domain['id'],
                    $sender['email'],
                    $domain['contact_email'],
                    $personalizedSubject,
                    $personalizedBody,
                    $template['id'],
                    $sender
                );
                
                $queuedCount++;
                
            } catch (Exception $e) {
                $errors[] = "Failed to queue email for {$domain['domain']}: " . $e->getMessage();
                continue;
            }
        }
        
        if (!empty($errors) && $queuedCount === 0) {
            throw new Exception("Failed to queue any emails: " . implode('; ', $errors));
        }
        
        return [
            'queued' => $queuedCount,
            'errors' => $errors,
            'total_processed' => count($domains)
        ];
    }
    
    private function getCampaignDetails($campaignId) {
        $sql = "SELECT * FROM campaigns WHERE id = ?";
        return $this->db->fetchOne($sql, [$campaignId]);
    }
    
    private function getApprovedDomains($campaignId) {
        $sql = "SELECT * FROM target_domains 
                WHERE campaign_id = ? AND status = 'approved' 
                AND contact_email IS NOT NULL
                ORDER BY quality_score DESC";
        return $this->db->fetchAll($sql, [$campaignId]);
    }
    
    private function getSpecificDomains($domainIds) {
        if (empty($domainIds)) return [];
        
        $placeholders = str_repeat('?,', count($domainIds) - 1) . '?';
        $sql = "SELECT * FROM target_domains 
                WHERE id IN ($placeholders) 
                AND contact_email IS NOT NULL
                ORDER BY quality_score DESC";
        return $this->db->fetchAll($sql, $domainIds);
    }
    
    private function isAlreadyContacted($campaignId, $domainId) {
        $sql = "SELECT COUNT(*) as count FROM email_queue 
                WHERE campaign_id = ? AND domain_id = ? 
                AND status IN ('queued', 'sent', 'processing')";
        $result = $this->db->fetchOne($sql, [$campaignId, $domainId]);
        return $result['count'] > 0;
    }
    
    private function queueEmailWithSender($campaignId, $domainId, $fromEmail, $toEmail, $subject, $body, $templateId, $senderInfo) {
        // Calculate priority based on sender availability and domain quality
        $priority = $this->calculateEmailPriority($senderInfo, $domainId);
        
        // Schedule email with intelligent timing
        $scheduledAt = $this->getOptimalScheduleTime($senderInfo);
        
        $sql = "INSERT INTO email_queue (
                    campaign_id, domain_id, template_id, sender_email, recipient_email,
                    subject, body, status, priority, scheduled_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'queued', ?, ?, NOW())";
        
        $this->db->execute($sql, [
            $campaignId, $domainId, $templateId, $fromEmail, $toEmail,
            $subject, $body, $priority, $scheduledAt
        ]);
        
        $queueId = $this->db->lastInsertId();
        
        // Log sender assignment for tracking
        $this->logSenderAssignment($queueId, $fromEmail, $senderInfo);
        
        return $queueId;
    }
    
    private function calculateEmailPriority($senderInfo, $domainId) {
        // Get domain quality score
        $sql = "SELECT quality_score FROM target_domains WHERE id = ?";
        $domain = $this->db->fetchOne($sql, [$domainId]);
        $qualityScore = $domain['quality_score'] ?? 50;
        
        // Higher quality domains get higher priority
        if ($qualityScore >= 80) return 'high';
        if ($qualityScore >= 60) return 'medium';
        return 'low';
    }
    
    private function getOptimalScheduleTime($senderInfo) {
        $baseDelay = (int)($this->settings['email_delay_minutes'] ?? 15);
        
        // Add some randomization to avoid patterns (±50% of base delay)
        $randomFactor = rand(50, 150) / 100;
        $actualDelay = $baseDelay * $randomFactor;
        
        // Consider sender's recent usage
        $hoursSinceLastUse = (time() - strtotime($senderInfo['last_used'] ?? '1970-01-01')) / 3600;
        
        if ($hoursSinceLastUse < 1) {
            // Add extra delay if sender was used very recently
            $actualDelay += 30;
        }
        
        return date('Y-m-d H:i:s', time() + ($actualDelay * 60));
    }
    
    private function logSenderAssignment($queueId, $senderEmail, $senderInfo) {
        $sql = "INSERT INTO api_logs (api_service, endpoint, method, request_data, response_data, status_code) 
                VALUES ('email_queue', 'sender_assignment', 'POST', ?, ?, 200)";
        
        $request_data = json_encode([
            'queue_id' => $queueId,
            'sender_email' => $senderEmail,
            'assignment_time' => date('Y-m-d H:i:s')
        ]);
        
        $response_data = json_encode([
            'sender_score' => $senderInfo['score'] ?? 0,
            'hourly_usage' => $senderInfo['hourly_used'] ?? 0,
            'daily_usage' => $senderInfo['daily_used'] ?? 0,
            'selection_reason' => 'automated_rotation'
        ]);
        
        $this->db->execute($sql, [$request_data, $response_data]);
    }
    
    private function getNextAvailableSlot($senderEmail) {
        // Get rate limiting settings
        $delayMinutes = (int)($this->settings['email_delay_minutes'] ?? 60);
        $dailyLimit = (int)($this->settings['daily_email_limit'] ?? 50);
        
        // Check last email sent from this sender
        $sql = "SELECT MAX(scheduled_at) as last_scheduled 
                FROM email_queue 
                WHERE sender_email = ? 
                AND status IN ('queued', 'sent', 'processing')";
        
        $result = $this->db->fetchOne($sql, [$senderEmail]);
        $lastScheduled = $result['last_scheduled'] ?? date('Y-m-d H:i:s');
        
        // Calculate next available slot
        $nextSlot = date('Y-m-d H:i:s', strtotime($lastScheduled) + ($delayMinutes * 60));
        
        // Check daily limit
        $todayCount = $this->getTodayEmailCount($senderEmail);
        if ($todayCount >= $dailyLimit) {
            // Schedule for tomorrow
            $nextSlot = date('Y-m-d 09:00:00', strtotime('+1 day'));
        }
        
        return $nextSlot;
    }
    
    private function getTodayEmailCount($senderEmail) {
        $sql = "SELECT COUNT(*) as count FROM email_queue 
                WHERE sender_email = ? 
                AND DATE(scheduled_at) = CURDATE()
                AND status IN ('queued', 'sent', 'processing')";
        
        $result = $this->db->fetchOne($sql, [$senderEmail]);
        return $result['count'];
    }
    
    public function processQueue($limit = 10) {
        // Get emails ready to be sent
        $sql = "SELECT * FROM email_queue 
                WHERE status = 'queued' 
                AND scheduled_at <= NOW() 
                ORDER BY priority DESC, scheduled_at ASC 
                LIMIT ?";
        
        $emails = $this->db->fetchAll($sql, [$limit]);
        $processed = 0;
        $errors = [];
        
        foreach ($emails as $email) {
            try {
                // Mark as processing
                $this->updateEmailStatus($email['id'], 'processing');
                
                // Send the email via GMass
                $result = $this->gmass->sendEmail(
                    $email['sender_email'],
                    $email['recipient_email'],
                    $email['subject'],
                    $email['body']
                );
                
                $messageId = $result['message_id'];
                $gmassId = $result['gmass_id'] ?? null;
                
                // Record sender usage for rate limiting (GMass handles this automatically)
                $this->senderRotation->recordEmailSent($email['sender_email'], $messageId, $gmassId);
                
                // Update status to sent
                $this->updateEmailStatus($email['id'], 'sent', $messageId);
                
                // Note: Keep domain status as 'approved' - don't change to 'contacted'
                // The 'sent' status is tracked in the outreach_emails table
                
                // Log to outreach_emails table for campaign tracking
                $this->logOutreachEmail($email, $messageId, $threadId);
                
                $processed++;
                
                // Add small delay between emails
                usleep(500000); // 0.5 seconds
                
            } catch (Exception $e) {
                // Mark as failed and increment retry count
                $this->handleEmailFailure($email, $e->getMessage());
                $errors[] = "Email ID {$email['id']}: " . $e->getMessage();
            }
        }
        
        return [
            'processed' => $processed,
            'errors' => $errors
        ];
    }
    
    private function logOutreachEmail($email, $messageId, $threadId) {
        // Log to outreach_emails table for comprehensive tracking
        $sql = "INSERT INTO outreach_emails (
                    campaign_id, domain_id, template_id, sender_email, recipient_email,
                    subject, body, status, sent_at, gmail_message_id, thread_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), ?, ?)";
        
        $this->db->execute($sql, [
            $email['campaign_id'],
            $email['domain_id'],
            $email['template_id'],
            $email['sender_email'],
            $email['recipient_email'],
            $email['subject'],
            $email['body'],
            $messageId,
            $threadId
        ]);
    }
    
    private function updateEmailStatus($emailId, $status, $messageId = null, $error = null) {
        $sql = "UPDATE email_queue SET 
                status = ?, 
                gmail_message_id = ?, 
                error_message = ?,
                processed_at = NOW() 
                WHERE id = ?";
        
        $this->db->execute($sql, [$status, $messageId, $error, $emailId]);
    }
    
    private function handleEmailFailure($email, $errorMessage) {
        $currentRetries = $email['retry_count'] ?? 0;
        $maxRetries = 3;
        
        if ($currentRetries < $maxRetries) {
            // Schedule for retry with exponential backoff
            $backoffMinutes = $this->calculateBackoffDelay($currentRetries);
            $retryTime = date('Y-m-d H:i:s', time() + ($backoffMinutes * 60));
            
            $sql = "UPDATE email_queue SET 
                    status = 'queued',
                    retry_count = ?,
                    scheduled_at = ?,
                    error_message = ?,
                    processed_at = NOW()
                    WHERE id = ?";
            
            $this->db->execute($sql, [$currentRetries + 1, $retryTime, $errorMessage, $email['id']]);
            
            // Log retry scheduling
            $this->logRetryScheduled($email['id'], $currentRetries + 1, $backoffMinutes, $errorMessage);
        } else {
            // Max retries reached, mark as permanently failed
            $sql = "UPDATE email_queue SET 
                    status = 'failed_permanent',
                    retry_count = ?,
                    error_message = ?,
                    processed_at = NOW()
                    WHERE id = ?";
            
            $this->db->execute($sql, [$currentRetries, $errorMessage, $email['id']]);
        }
    }
    
    private function logRetryScheduled($emailId, $retryCount, $delayMinutes, $error) {
        $sql = "INSERT INTO api_logs (api_service, endpoint, method, request_data, response_data, status_code) 
                VALUES ('email_queue', 'retry_scheduled', 'POST', ?, ?, 202)";
        
        $request_data = json_encode([
            'email_id' => $emailId,
            'retry_count' => $retryCount,
            'delay_minutes' => $delayMinutes,
            'scheduled_at' => date('Y-m-d H:i:s', time() + ($delayMinutes * 60))
        ]);
        
        $response_data = json_encode([
            'status' => 'retry_scheduled',
            'backoff_strategy' => 'exponential',
            'original_error' => $error
        ]);
        
        $this->db->execute($sql, [$request_data, $response_data]);
    }
    
    private function updateDomainStatus($domainId, $status) {
        $sql = "UPDATE target_domains SET status = ? WHERE id = ?";
        $this->db->execute($sql, [$status, $domainId]);
    }
    
    public function getQueueStats($campaignId = null) {
        $whereClause = $campaignId ? "WHERE campaign_id = ?" : "";
        $params = $campaignId ? [$campaignId] : [];
        
        $sql = "SELECT 
                    status,
                    COUNT(*) as count,
                    MIN(scheduled_at) as earliest_scheduled,
                    MAX(scheduled_at) as latest_scheduled
                FROM email_queue 
                $whereClause
                GROUP BY status";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    public function pauseCampaignEmails($campaignId) {
        $sql = "UPDATE email_queue SET status = 'paused' 
                WHERE campaign_id = ? AND status = 'queued'";
        return $this->db->execute($sql, [$campaignId]);
    }
    
    public function resumeCampaignEmails($campaignId) {
        $sql = "UPDATE email_queue SET status = 'queued' 
                WHERE campaign_id = ? AND status = 'paused'";
        return $this->db->execute($sql, [$campaignId]);
    }
    
    public function cancelCampaignEmails($campaignId) {
        $sql = "UPDATE email_queue SET status = 'cancelled' 
                WHERE campaign_id = ? AND status IN ('queued', 'paused')";
        return $this->db->execute($sql, [$campaignId]);
    }
    
    public function retryFailedEmails($campaignId = null, $hours = 24) {
        // Get failed emails to retry with exponential backoff
        $whereClause = "WHERE status = 'failed' AND processed_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)";
        $params = [$hours];
        
        if ($campaignId) {
            $whereClause .= " AND campaign_id = ?";
            $params[] = $campaignId;
        }
        
        // Get failed emails with retry counts
        $sql = "SELECT id, retry_count, processed_at FROM email_queue $whereClause";
        $failedEmails = $this->db->fetchAll($sql, $params);
        
        $retryCount = 0;
        foreach ($failedEmails as $email) {
            $retryCount++;
            $currentRetries = $email['retry_count'] ?? 0;
            
            // Max 3 retries per email
            if ($currentRetries >= 3) {
                continue;
            }
            
            // Calculate exponential backoff delay
            $backoffMinutes = $this->calculateBackoffDelay($currentRetries);
            $newScheduleTime = date('Y-m-d H:i:s', time() + ($backoffMinutes * 60));
            
            // Update email for retry
            $updateSql = "UPDATE email_queue SET 
                         status = 'queued',
                         scheduled_at = ?,
                         retry_count = ?,
                         error_message = NULL
                         WHERE id = ?";
            
            $this->db->execute($updateSql, [$newScheduleTime, $currentRetries + 1, $email['id']]);
        }
        
        return $retryCount;
    }
    
    private function calculateBackoffDelay($retryCount) {
        // Exponential backoff: 15 minutes, 60 minutes, 240 minutes (4 hours)
        $baseDelay = 15; // minutes
        $exponentialFactor = pow(4, $retryCount);
        
        return min($baseDelay * $exponentialFactor, 240); // Cap at 4 hours
    }
}
?>