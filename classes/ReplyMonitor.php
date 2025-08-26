<?php
/**
 * Reply Monitor - Automated reply detection and classification via IMAP
 * Monitors Gmail inboxes for replies using direct IMAP connection (no OAuth)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/IMAPMonitor.php';
require_once __DIR__ . '/ReplyClassifier.php';
require_once __DIR__ . '/LeadForwarder.php';

class ReplyMonitor {
    private $db;
    private $imapMonitor;
    private $classifier;
    private $leadForwarder;
    private $logFile;
    
    // Reply classification types
    const REPLY_POSITIVE = 'positive';
    const REPLY_NEGATIVE = 'negative';
    const REPLY_NEUTRAL = 'neutral';
    const REPLY_SPAM = 'spam';
    const REPLY_BOUNCE = 'bounce';
    const REPLY_QUESTION = 'question';
    
    // Monitoring intervals
    const CHECK_INTERVAL_MINUTES = 15;
    const BATCH_SIZE = 50;
    
    public function __construct() {
        $this->db = new Database();
        $this->imapMonitor = new IMAPMonitor();
        $this->classifier = new ReplyClassifier();
        $this->leadForwarder = new LeadForwarder();
        $this->logFile = __DIR__ . '/../logs/reply_monitor.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Monitor replies for all active campaigns via IMAP
     */
    public function monitorAllCampaigns() {
        $this->log("🔍 Starting IMAP reply monitoring for all active campaigns");
        
        try {
            // Use IMAP monitor to get all new replies
            $sinceDate = $this->getLastMonitoringTime();
            $result = $this->imapMonitor->monitorReplies($sinceDate);
            
            // Update last monitoring time
            $this->updateLastMonitoringTime();
            
            $this->log("✅ IMAP monitoring completed: {$result['processed']} processed, {$result['positive_leads']} positive leads");
            
            return [
                'campaigns_checked' => 1, // IMAP monitors all campaigns at once
                'replies_found' => $result['total_replies'],
                'leads_forwarded' => $result['positive_leads'],
                'emails_processed' => $result['processed']
            ];
            
        } catch (Exception $e) {
            $this->log("❌ IMAP reply monitoring failed: " . $e->getMessage());
            return [
                'campaigns_checked' => 0,
                'replies_found' => 0,
                'leads_forwarded' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Monitor replies for a specific campaign
     */
    public function monitorCampaignReplies($campaignId) {
        $this->log("📧 Monitoring replies for campaign: {$campaignId}");
        
        try {
            // Get sent emails that need reply checking
            $sentEmails = $this->getSentEmailsForMonitoring($campaignId);
            
            if (empty($sentEmails)) {
                $this->log("ℹ️ No sent emails to monitor for campaign {$campaignId}");
                return [
                    'replies_found' => 0,
                    'leads_forwarded' => 0
                ];
            }
            
            $repliesFound = 0;
            $leadsForwarded = 0;
            
            // Group emails by sender to minimize Gmail API calls
            $emailsBySender = $this->groupEmailsBySender($sentEmails);
            
            foreach ($emailsBySender as $senderId => $emails) {
                try {
                    // Configure Gmail for this sender
                    $sender = $this->getSenderAccount($senderId);
                    if (!$sender) {
                        continue;
                    }
                    
                    $this->gmail->setSenderAccount($sender);
                    
                    // Check for replies to these emails
                    $replies = $this->checkEmailsForReplies($emails);
                    
                    foreach ($replies as $reply) {
                        $processed = $this->processReply($reply);
                        if ($processed) {
                            $repliesFound++;
                            if ($processed['is_lead']) {
                                $leadsForwarded++;
                            }
                        }
                    }
                    
                    // Rate limiting between sender accounts
                    sleep(2);
                    
                } catch (Exception $e) {
                    $this->log("❌ Error checking replies for sender {$senderId}: " . $e->getMessage());
                }
            }
            
            // Update campaign last monitoring timestamp
            $this->updateCampaignMonitoringTimestamp($campaignId);
            
            $this->log("✅ Campaign {$campaignId} monitoring completed: {$repliesFound} replies, {$leadsForwarded} leads");
            
            return [
                'replies_found' => $repliesFound,
                'leads_forwarded' => $leadsForwarded
            ];
            
        } catch (Exception $e) {
            $this->log("❌ Campaign reply monitoring failed for {$campaignId}: " . $e->getMessage());
            return [
                'replies_found' => 0,
                'leads_forwarded' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check specific emails for replies via Gmail API
     */
    private function checkEmailsForReplies($emails) {
        $replies = [];
        
        foreach ($emails as $email) {
            try {
                if (empty($email['message_id'])) {
                    continue; // Skip emails without message IDs
                }
                
                // Search for replies to this specific email
                $searchQuery = "in:inbox subject:\"Re:\" OR subject:\"RE:\" OR subject:\"re:\"";
                $gmailReplies = $this->gmail->searchEmails($searchQuery);
                
                if (!empty($gmailReplies)) {
                    foreach ($gmailReplies as $gmailReply) {
                        // Check if this reply is related to our outreach email
                        if ($this->isReplyToOurEmail($gmailReply, $email)) {
                            $replies[] = [
                                'original_email_id' => $email['id'],
                                'campaign_id' => $email['campaign_id'],
                                'domain_id' => $email['domain_id'],
                                'reply_data' => $gmailReply,
                                'sender_email' => $email['recipient_email'],
                                'original_subject' => $email['subject']
                            ];
                        }
                    }
                }
                
            } catch (Exception $e) {
                $this->log("⚠️ Error checking reply for email {$email['id']}: " . $e->getMessage());
            }
        }
        
        return $replies;
    }
    
    /**
     * Process a detected reply
     */
    private function processReply($reply) {
        $this->log("🔍 Processing reply from: {$reply['sender_email']}");
        
        try {
            // Extract reply content
            $replyContent = $this->extractReplyContent($reply['reply_data']);
            
            // Classify the reply using AI
            $classification = $this->classifier->classifyReply(
                $replyContent,
                $reply['original_subject'],
                $reply['sender_email']
            );
            
            // Store the reply in database
            $replyId = $this->storeReply($reply, $replyContent, $classification);
            
            // Update original email status
            $this->updateOriginalEmailWithReply($reply['original_email_id'], $replyId);
            
            // Determine if this is a qualified lead
            $isLead = $this->isQualifiedLead($classification);
            
            if ($isLead) {
                $this->log("🎯 Qualified lead detected from: {$reply['sender_email']}");
                
                // Forward to campaign owner
                $forwarded = $this->leadForwarder->forwardLead(
                    $reply['campaign_id'],
                    $reply['domain_id'],
                    $replyId,
                    $classification
                );
                
                if ($forwarded) {
                    $this->updateCampaignLeadCount($reply['campaign_id']);
                }
            }
            
            $this->log("✅ Reply processed: Classification={$classification['category']}, Lead={" . ($isLead ? 'yes' : 'no') . "}");
            
            return [
                'reply_id' => $replyId,
                'classification' => $classification,
                'is_lead' => $isLead
            ];
            
        } catch (Exception $e) {
            $this->log("❌ Error processing reply: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if reply is related to our outreach email
     */
    private function isReplyToOurEmail($gmailReply, $originalEmail) {
        // Check if reply contains references to our email
        $replySubject = strtolower($gmailReply['subject'] ?? '');
        $originalSubject = strtolower($originalEmail['subject']);
        
        // Remove "Re:" prefixes for comparison
        $cleanOriginalSubject = preg_replace('/^(re:|fwd?:|fw:)\s*/i', '', $originalSubject);
        $cleanReplySubject = preg_replace('/^(re:|fwd?:|fw:)\s*/i', '', $replySubject);
        
        // Check if subjects match
        if (strpos($cleanReplySubject, $cleanOriginalSubject) !== false) {
            return true;
        }
        
        // Check if reply is from the same domain we contacted
        $replyFrom = strtolower($gmailReply['from'] ?? '');
        $originalTo = strtolower($originalEmail['recipient_email']);
        
        if ($replyFrom === $originalTo) {
            return true;
        }
        
        // Check message threading (if available)
        if (!empty($originalEmail['message_id']) && !empty($gmailReply['in_reply_to'])) {
            if ($gmailReply['in_reply_to'] === $originalEmail['message_id']) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract meaningful content from reply
     */
    private function extractReplyContent($replyData) {
        $content = $replyData['body'] ?? $replyData['snippet'] ?? '';
        
        // Remove quoted text (lines starting with >)
        $lines = explode("\n", $content);
        $cleanLines = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '>') === 0) {
                continue; // Skip quoted text
            }
            $cleanLines[] = $line;
        }
        
        return implode("\n", $cleanLines);
    }
    
    /**
     * Store reply in database
     */
    private function storeReply($reply, $content, $classification) {
        $sql = "
            INSERT INTO email_replies (
                original_email_id, campaign_id, domain_id, sender_email,
                reply_subject, reply_content, classification_category,
                classification_confidence, sentiment_score, reply_date,
                gmail_message_id, processing_status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'processed', NOW())
        ";
        
        $this->db->execute($sql, [
            $reply['original_email_id'],
            $reply['campaign_id'],
            $reply['domain_id'],
            $reply['sender_email'],
            $reply['reply_data']['subject'] ?? '',
            $content,
            $classification['category'],
            $classification['confidence'] ?? 0.8,
            $classification['sentiment_score'] ?? 0.5,
            $reply['reply_data']['message_id'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Update original email with reply information
     */
    private function updateOriginalEmailWithReply($emailId, $replyId) {
        $this->db->execute(
            "UPDATE outreach_emails SET replied_at = NOW(), reply_id = ? WHERE id = ?",
            [$replyId, $emailId]
        );
    }
    
    /**
     * Determine if reply qualifies as a lead
     */
    private function isQualifiedLead($classification) {
        $category = $classification['category'];
        $confidence = $classification['confidence'] ?? 0;
        $sentimentScore = $classification['sentiment_score'] ?? 0;
        
        // Positive replies with high confidence are leads
        if ($category === self::REPLY_POSITIVE && $confidence >= 0.7) {
            return true;
        }
        
        // Questions with positive sentiment might be leads
        if ($category === self::REPLY_QUESTION && $sentimentScore >= 0.6) {
            return true;
        }
        
        // Neutral replies with high sentiment score could be leads
        if ($category === self::REPLY_NEUTRAL && $sentimentScore >= 0.8) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get campaigns that need reply monitoring
     */
    private function getCampaignsForMonitoring() {
        $sql = "
            SELECT DISTINCT c.id, c.name, c.owner_email
            FROM campaigns c
            INNER JOIN outreach_emails oe ON c.id = oe.campaign_id
            WHERE c.auto_reply_monitoring = 1
            AND oe.status = 'sent'
            AND oe.sent_at IS NOT NULL
            AND oe.replied_at IS NULL
            AND oe.sent_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND (c.last_reply_check IS NULL OR c.last_reply_check < DATE_SUB(NOW(), INTERVAL ? MINUTE))
            ORDER BY c.last_reply_check ASC
            LIMIT 10
        ";
        
        return $this->db->fetchAll($sql, [self::CHECK_INTERVAL_MINUTES]);
    }
    
    /**
     * Get sent emails that need reply monitoring
     */
    private function getSentEmailsForMonitoring($campaignId) {
        $sql = "
            SELECT 
                oe.*,
                td.domain,
                sa.email as sender_account_email
            FROM outreach_emails oe
            LEFT JOIN target_domains td ON oe.domain_id = td.id
            LEFT JOIN sender_accounts sa ON oe.sender_id = sa.id
            WHERE oe.campaign_id = ?
            AND oe.status = 'sent'
            AND oe.replied_at IS NULL
            AND oe.sent_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY oe.sent_at DESC
            LIMIT ?
        ";
        
        return $this->db->fetchAll($sql, [$campaignId, self::BATCH_SIZE]);
    }
    
    /**
     * Group emails by sender for efficient processing
     */
    private function groupEmailsBySender($emails) {
        $grouped = [];
        
        foreach ($emails as $email) {
            $senderId = $email['sender_id'] ?? 'unknown';
            if (!isset($grouped[$senderId])) {
                $grouped[$senderId] = [];
            }
            $grouped[$senderId][] = $email;
        }
        
        return $grouped;
    }
    
    /**
     * Get sender account details
     */
    private function getSenderAccount($senderId) {
        return $this->db->fetchOne(
            "SELECT * FROM sender_accounts WHERE id = ? AND is_active = 1",
            [$senderId]
        );
    }
    
    /**
     * Update campaign monitoring timestamp
     */
    private function updateCampaignMonitoringTimestamp($campaignId) {
        $this->db->execute(
            "UPDATE campaigns SET last_reply_check = NOW() WHERE id = ?",
            [$campaignId]
        );
    }
    
    /**
     * Update campaign lead count
     */
    private function updateCampaignLeadCount($campaignId) {
        $this->db->execute(
            "UPDATE campaigns SET qualified_leads_count = qualified_leads_count + 1, leads_forwarded = leads_forwarded + 1 WHERE id = ?",
            [$campaignId]
        );
    }
    
    /**
     * Get reply monitoring statistics
     */
    public function getMonitoringStats($days = 7) {
        $sql = "
            SELECT 
                COUNT(*) as total_replies,
                SUM(CASE WHEN classification_category = 'positive' THEN 1 ELSE 0 END) as positive_replies,
                SUM(CASE WHEN classification_category = 'negative' THEN 1 ELSE 0 END) as negative_replies,
                SUM(CASE WHEN classification_category = 'neutral' THEN 1 ELSE 0 END) as neutral_replies,
                SUM(CASE WHEN classification_category = 'question' THEN 1 ELSE 0 END) as question_replies,
                ROUND(AVG(sentiment_score), 2) as avg_sentiment,
                ROUND(AVG(classification_confidence), 2) as avg_confidence
            FROM email_replies
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
        ";
        
        return $this->db->fetchOne($sql, [$days]);
    }
    
    /**
     * Get last monitoring time for IMAP
     */
    private function getLastMonitoringTime() {
        $sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'last_imap_monitoring_time'";
        $result = $this->db->fetchOne($sql);
        
        if ($result && $result['setting_value']) {
            return $result['setting_value'];
        }
        
        // Default to 1 hour ago for first run
        return date('Y-m-d H:i:s', strtotime('-1 hour'));
    }
    
    /**
     * Update last monitoring time
     */
    private function updateLastMonitoringTime() {
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES ('last_imap_monitoring_time', ?) 
                ON DUPLICATE KEY UPDATE setting_value = ?";
        $this->db->execute($sql, [$now, $now]);
    }
    
    /**
     * Test IMAP connection
     */
    public function testImapConnection() {
        return $this->imapMonitor->testConnection();
    }
    
    /**
     * Log message with timestamp
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        echo $logMessage;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
?>