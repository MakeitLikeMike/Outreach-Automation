<?php
/**
 * IMAP Monitor - Direct Gmail Inbox Monitoring
 * Replaces Gmail API with direct IMAP connection for reply monitoring
 * No OAuth required - just email + app password
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ReplyClassifier.php';
require_once __DIR__ . '/GMassIntegration.php';

class IMAPMonitor {
    private $db;
    private $imapConnection;
    private $settings;
    private $replyClassifier;
    private $gmass;
    private $logFile;
    
    // IMAP connection settings
    private $imapHost;
    private $imapPort;
    private $imapEmail;
    private $imapPassword;
    private $imapMailbox;
    
    // Reply classification types
    const REPLY_POSITIVE = 'positive';
    const REPLY_NEGATIVE = 'negative'; 
    const REPLY_NEUTRAL = 'neutral';
    const REPLY_SPAM = 'spam';
    const REPLY_BOUNCE = 'bounce';
    const REPLY_QUESTION = 'question';
    
    // Monitoring settings
    const CHECK_INTERVAL_MINUTES = 15;
    const BATCH_SIZE = 50;
    const MAX_RECONNECTION_ATTEMPTS = 3;
    
    public function __construct() {
        $this->db = new Database();
        $this->replyClassifier = new ReplyClassifier();
        $this->gmass = new GMassIntegration();
        $this->logFile = __DIR__ . '/../logs/imap_monitor.log';
        
        $this->loadSettings();
        $this->initializeImapSettings();
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Test IMAP connection without establishing persistent connection
     */
    public function testConnection() {
        try {
            $mailbox = "{{$this->imapHost}:{$this->imapPort}/imap/ssl/novalidate-cert}INBOX";
            $connection = imap_open($mailbox, $this->imapEmail, $this->imapPassword);
            
            if ($connection) {
                imap_close($connection);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            $this->log("Connection test failed: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Get unread email count for performance testing
     */
    public function getUnreadCount() {
        try {
            if (!$this->imapConnection) {
                $this->connect();
            }
            
            $unreadCount = imap_num_recent($this->imapConnection);
            return $unreadCount ?: 0;
            
        } catch (Exception $e) {
            $this->log("Failed to get unread count: " . $e->getMessage(), 'ERROR');
            return 0;
        }
    }
    
    /**
     * Enhanced connection method with retry logic
     */
    public function connect($retryCount = 0) {
        if ($retryCount >= self::MAX_RECONNECTION_ATTEMPTS) {
            throw new Exception("Failed to connect after " . self::MAX_RECONNECTION_ATTEMPTS . " attempts");
        }
        
        try {
            $mailbox = "{{$this->imapHost}:{$this->imapPort}/imap/ssl/novalidate-cert}INBOX";
            $this->imapConnection = imap_open($mailbox, $this->imapEmail, $this->imapPassword);
            
            if (!$this->imapConnection) {
                $error = imap_last_error();
                throw new Exception("IMAP connection failed: " . ($error ?: 'Unknown error'));
            }
            
            $this->log("Successfully connected to IMAP server", 'INFO');
            return true;
            
        } catch (Exception $e) {
            $this->log("Connection attempt " . ($retryCount + 1) . " failed: " . $e->getMessage(), 'WARNING');
            
            if ($retryCount < self::MAX_RECONNECTION_ATTEMPTS - 1) {
                sleep(2 ** $retryCount); // Exponential backoff
                return $this->connect($retryCount + 1);
            }
            
            throw $e;
        }
    }
    
    /**
     * Disconnect from IMAP
     */
    public function disconnect() {
        if ($this->imapConnection) {
            imap_close($this->imapConnection);
            $this->imapConnection = null;
            $this->log("🔌 IMAP connection closed");
        }
    }
    
    /**
     * Check if IMAP connection is still alive
     */
    public function isConnected() {
        if (!$this->imapConnection) {
            return false;
        }
        
        // Test connection with ping
        try {
            $check = imap_check($this->imapConnection);
            return $check !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Reconnect with retry logic
     */
    public function reconnect() {
        $this->disconnect();
        
        for ($attempt = 1; $attempt <= self::MAX_RECONNECTION_ATTEMPTS; $attempt++) {
            try {
                $this->log("🔄 Reconnection attempt {$attempt}/" . self::MAX_RECONNECTION_ATTEMPTS);
                $this->connect();
                return true;
            } catch (Exception $e) {
                $this->log("❌ Reconnection attempt {$attempt} failed: " . $e->getMessage());
                if ($attempt < self::MAX_RECONNECTION_ATTEMPTS) {
                    sleep(5 * $attempt); // Exponential backoff
                }
            }
        }
        
        throw new Exception("Failed to reconnect after " . self::MAX_RECONNECTION_ATTEMPTS . " attempts");
    }
    
    /**
     * Fetch new emails since last check
     */
    public function fetchNewEmails($sinceDate = null) {
        $this->ensureConnected();
        
        if (!$sinceDate) {
            $sinceDate = date('d-M-Y', strtotime('-1 hour'));
        } else {
            $sinceDate = date('d-M-Y', strtotime($sinceDate));
        }
        
        $this->log("📬 Fetching emails since: {$sinceDate}");
        
        try {
            // Search for emails since date
            $searchCriteria = "SINCE \"{$sinceDate}\"";
            $emails = imap_search($this->imapConnection, $searchCriteria);
            
            if (!$emails) {
                $this->log("📭 No new emails found");
                return [];
            }
            
            $this->log("📧 Found " . count($emails) . " emails to process");
            
            $newEmails = [];
            foreach ($emails as $emailNum) {
                $email = $this->processEmail($emailNum);
                if ($email && !$this->isAlreadyProcessed($email['message_id'])) {
                    $newEmails[] = $email;
                }
            }
            
            $this->log("🆕 " . count($newEmails) . " new emails to process");
            return $newEmails;
            
        } catch (Exception $e) {
            $this->log("❌ Error fetching emails: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Process individual email
     */
    private function processEmail($emailNum) {
        try {
            $header = imap_headerinfo($this->imapConnection, $emailNum);
            $body = $this->getEmailBody($emailNum);
            
            if (!$header) {
                return null;
            }
            
            return [
                'message_id' => $header->message_id ?? 'unknown_' . $emailNum,
                'subject' => $this->decodeHeader($header->subject ?? ''),
                'from_email' => $this->extractEmail($header->from[0] ?? null),
                'from_name' => $this->decodeHeader($header->from[0]->personal ?? ''),
                'to_email' => $this->extractEmail($header->to[0] ?? null),
                'date' => date('Y-m-d H:i:s', strtotime($header->date)),
                'body' => $body,
                'raw_header' => $header
            ];
            
        } catch (Exception $e) {
            $this->log("⚠️ Error processing email #{$emailNum}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get email body (handles multipart emails)
     */
    private function getEmailBody($emailNum) {
        $body = '';
        
        try {
            $structure = imap_fetchstructure($this->imapConnection, $emailNum);
            
            if (!isset($structure->parts)) {
                // Simple email (not multipart)
                $body = imap_body($this->imapConnection, $emailNum);
                
                if ($structure->encoding == 3) { // BASE64
                    $body = base64_decode($body);
                } elseif ($structure->encoding == 4) { // QUOTED_PRINTABLE
                    $body = quoted_printable_decode($body);
                }
            } else {
                // Multipart email
                foreach ($structure->parts as $partNum => $part) {
                    $partData = imap_fetchbody($this->imapConnection, $emailNum, $partNum + 1);
                    
                    if ($part->encoding == 3) { // BASE64
                        $partData = base64_decode($partData);
                    } elseif ($part->encoding == 4) { // QUOTED_PRINTABLE
                        $partData = quoted_printable_decode($partData);
                    }
                    
                    // Prefer text/plain, but accept text/html if needed
                    if (strtolower($part->subtype) == 'plain' || 
                        (strtolower($part->subtype) == 'html' && empty($body))) {
                        $body = $partData;
                    }
                }
            }
            
            return trim($body);
            
        } catch (Exception $e) {
            $this->log("⚠️ Error getting email body for #{$emailNum}: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Monitor and process all new replies
     */
    public function monitorReplies($sinceDate = null) {
        $this->log("🔍 Starting reply monitoring cycle");
        
        try {
            $newEmails = $this->fetchNewEmails($sinceDate);
            
            if (empty($newEmails)) {
                return [
                    'processed' => 0,
                    'positive_leads' => 0,
                    'total_replies' => 0
                ];
            }
            
            $processed = 0;
            $positiveLeads = 0;
            $totalReplies = 0;
            
            foreach ($newEmails as $email) {
                try {
                    $result = $this->processReply($email);
                    
                    if ($result) {
                        $processed++;
                        $totalReplies++;
                        
                        if ($result['classification'] === self::REPLY_POSITIVE) {
                            $positiveLeads++;
                        }
                    }
                    
                } catch (Exception $e) {
                    $this->log("❌ Error processing reply: " . $e->getMessage());
                }
            }
            
            $this->log("✅ Monitoring cycle complete: {$processed} processed, {$positiveLeads} positive leads");
            
            return [
                'processed' => $processed,
                'positive_leads' => $positiveLeads,
                'total_replies' => $totalReplies
            ];
            
        } catch (Exception $e) {
            $this->log("❌ Reply monitoring failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Process individual reply
     */
    public function processReply($email) {
        try {
            // Check if this is actually a reply to our outreach
            if (!$this->isReplyToOutreach($email)) {
                $this->log("ℹ️ Skipping non-outreach email: {$email['subject']}");
                return null;
            }
            
            // Classify the reply
            $classification = $this->replyClassifier->classifyReply($email['body'], $email['subject']);
            
            // Store processed email to avoid duplicates
            $this->storeProcessedEmail($email, $classification);
            
            $this->log("📥 Reply classified as '{$classification}': {$email['subject']}");
            
            // Forward positive leads
            if ($classification === self::REPLY_POSITIVE) {
                $this->forwardPositiveLead($email, $classification);
            }
            
            return [
                'message_id' => $email['message_id'],
                'classification' => $classification,
                'from' => $email['from_email'],
                'subject' => $email['subject']
            ];
            
        } catch (Exception $e) {
            $this->log("❌ Error processing reply: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if email is a reply to our outreach campaign
     */
    private function isReplyToOutreach($email) {
        // Check subject for "Re:" and outreach-related keywords
        $subject = strtolower($email['subject']);
        
        if (strpos($subject, 're:') === 0) {
            return true;
        }
        
        // Check if sender domain is in our target domains
        $senderDomain = $this->extractDomainFromEmail($email['from_email']);
        if ($this->isDomainInCampaigns($senderDomain)) {
            return true;
        }
        
        // Check email body for outreach-related content
        $body = strtolower($email['body']);
        $outreachKeywords = ['guest post', 'collaboration', 'partnership', 'backlink', 'article', 'content'];
        
        foreach ($outreachKeywords as $keyword) {
            if (strpos($body, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Forward positive leads via GMass
     */
    private function forwardPositiveLead($email, $classification) {
        try {
            // Get lead forwarding email from settings
            $forwardToEmail = $this->settings['lead_forwarding_email'] ?? $this->settings['imap_email'];
            
            if (empty($forwardToEmail)) {
                $this->log("⚠️ No forwarding email configured for positive leads");
                return;
            }
            
            $subject = "🎯 POSITIVE LEAD: " . $email['subject'];
            $body = $this->buildLeadForwardingEmail($email, $classification);
            
            // Send via GMass
            $result = $this->gmass->sendEmail(
                $this->imapEmail,
                $forwardToEmail,
                $subject,
                $body
            );
            
            if ($result['success']) {
                $this->log("✅ Positive lead forwarded to {$forwardToEmail}");
                $this->markEmailAsForwarded($email['message_id']);
            } else {
                $this->log("❌ Failed to forward positive lead");
            }
            
        } catch (Exception $e) {
            $this->log("❌ Error forwarding lead: " . $e->getMessage());
        }
    }
    
    /**
     * Build lead forwarding email content
     */
    private function buildLeadForwardingEmail($email, $classification) {
        return "
        <h2>🎯 New Positive Lead Detected</h2>
        
        <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
            <h3>Lead Information:</h3>
            <p><strong>From:</strong> {$email['from_name']} &lt;{$email['from_email']}&gt;</p>
            <p><strong>Subject:</strong> {$email['subject']}</p>
            <p><strong>Date:</strong> {$email['date']}</p>
            <p><strong>Classification:</strong> {$classification}</p>
        </div>
        
        <h3>Original Message:</h3>
        <div style='border-left: 4px solid #28a745; padding-left: 20px; margin: 20px 0;'>
            " . nl2br(htmlspecialchars($email['body'])) . "
        </div>
        
        <hr>
        <p style='color: #666; font-size: 12px;'>
            This lead was automatically detected and forwarded by the IMAP Reply Monitor system.
            <br>Time: " . date('Y-m-d H:i:s') . "
        </p>
        ";
    }
    
    /**
     * Store processed email to prevent duplicates
     */
    private function storeProcessedEmail($email, $classification) {
        $sql = "INSERT INTO imap_processed_emails (message_id, subject, sender_email, received_date, classification) 
                VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE classification = VALUES(classification)";
        
        $this->db->execute($sql, [
            $email['message_id'],
            $email['subject'],
            $email['from_email'],
            $email['date'],
            $classification
        ]);
    }
    
    /**
     * Mark email as forwarded
     */
    private function markEmailAsForwarded($messageId) {
        $sql = "UPDATE imap_processed_emails SET forwarded = TRUE WHERE message_id = ?";
        $this->db->execute($sql, [$messageId]);
    }
    
    /**
     * Check if email already processed
     */
    private function isAlreadyProcessed($messageId) {
        $sql = "SELECT id FROM imap_processed_emails WHERE message_id = ?";
        $result = $this->db->fetchOne($sql, [$messageId]);
        return !empty($result);
    }
    
    /**
     * Check if domain is in our campaigns
     */
    private function isDomainInCampaigns($domain) {
        if (empty($domain)) return false;
        
        $sql = "SELECT id FROM target_domains WHERE domain LIKE ? OR domain LIKE ?";
        $result = $this->db->fetchOne($sql, ["%{$domain}%", "%www.{$domain}%"]);
        return !empty($result);
    }
    
    /**
     * Utility functions
     */
    private function ensureConnected() {
        if (!$this->isConnected()) {
            $this->reconnect();
        }
    }
    
    private function extractEmail($headerObject) {
        if (!$headerObject) return '';
        return strtolower(trim($headerObject->mailbox . '@' . $headerObject->host));
    }
    
    private function extractDomainFromEmail($email) {
        $parts = explode('@', $email);
        return isset($parts[1]) ? $parts[1] : '';
    }
    
    private function decodeHeader($header) {
        $decoded = imap_mime_header_decode($header);
        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        return $result;
    }
    
    /**
     * Load settings from database
     */
    private function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    /**
     * Initialize IMAP connection settings
     */
    private function initializeImapSettings() {
        $this->imapHost = $this->settings['imap_host'] ?? 'imap.gmail.com';
        $this->imapPort = $this->settings['imap_port'] ?? '993';
        $this->imapEmail = $this->settings['imap_email'] ?? '';
        $this->imapPassword = $this->settings['imap_password'] ?? '';
        
        if (empty($this->imapEmail) || empty($this->imapPassword)) {
            throw new Exception("IMAP credentials not configured. Please set imap_email and imap_password in settings.");
        }
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
    
    /**
     * Cleanup on destruction
     */
    public function __destruct() {
        $this->disconnect();
    }
}
?>