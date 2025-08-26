<?php
/**
 * Lead Forwarder - Automated qualified lead forwarding system
 * Forwards qualified leads to campaign owners with detailed analysis
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/GmailIntegration.php';

class LeadForwarder {
    private $db;
    private $gmail;
    private $logFile;
    
    // Lead qualification thresholds
    const MIN_QUALITY_SCORE = 60;
    const MIN_SENTIMENT_SCORE = 0.6;
    const MIN_CONFIDENCE = 0.7;
    
    public function __construct() {
        $this->db = new Database();
        $this->gmail = new GmailIntegration();
        $this->logFile = __DIR__ . '/../logs/lead_forwarder.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Forward a qualified lead to campaign owner
     */
    public function forwardLead($campaignId, $domainId, $replyId, $classification) {
        $this->log("🎯 Processing lead forward for reply ID: {$replyId}");
        
        try {
            // Get comprehensive lead information
            $leadData = $this->getLeadInformation($campaignId, $domainId, $replyId);
            
            if (!$leadData) {
                throw new Exception("Could not retrieve lead information");
            }
            
            // Validate lead qualification
            if (!$this->isQualifiedLead($leadData, $classification)) {
                $this->log("⚠️ Lead did not meet qualification criteria");
                return false;
            }
            
            // Create forwarding email
            $emailData = $this->createLeadForwardEmail($leadData, $classification);
            
            // Configure Gmail for sending
            $this->configureGmailForCampaign($campaignId);
            
            // Send forwarding email
            $messageId = $this->sendForwardEmail($leadData['owner_email'], $emailData);
            
            // Record the forwarding
            $forwardingId = $this->recordLeadForwarding($campaignId, $domainId, $replyId, $messageId, $classification);
            
            // Update campaign metrics
            $this->updateCampaignLeadMetrics($campaignId);
            
            $this->log("✅ Lead successfully forwarded to {$leadData['owner_email']} (Message ID: {$messageId})");
            
            return $forwardingId;
            
        } catch (Exception $e) {
            $this->log("❌ Error forwarding lead: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get comprehensive lead information
     */
    private function getLeadInformation($campaignId, $domainId, $replyId) {
        $sql = "
            SELECT 
                c.id as campaign_id,
                c.name as campaign_name,
                c.owner_email,
                c.owner_name,
                c.template_id,
                td.id as domain_id,
                td.domain,
                td.domain_rating,
                td.quality_score,
                td.organic_traffic,
                td.referring_domains,
                td.contact_email,
                oe.id as outreach_email_id,
                oe.subject as original_subject,
                oe.body as original_body,
                oe.sent_at as outreach_sent_at,
                oe.recipient_email,
                er.reply_subject,
                er.reply_content,
                er.reply_date,
                er.sender_email as reply_sender_email,
                er.classification_category,
                er.classification_confidence,
                er.sentiment_score
            FROM campaigns c
            LEFT JOIN target_domains td ON c.id = td.campaign_id AND td.id = ?
            LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
            LEFT JOIN email_replies er ON er.id = ?
            WHERE c.id = ?
        ";
        
        return $this->db->fetchOne($sql, [$domainId, $replyId, $campaignId]);
    }
    
    /**
     * Check if lead meets qualification criteria
     */
    private function isQualifiedLead($leadData, $classification) {
        // Check basic data availability
        if (empty($leadData['owner_email']) || empty($leadData['contact_email'])) {
            return false;
        }
        
        // Check domain quality score
        if ($leadData['quality_score'] < self::MIN_QUALITY_SCORE) {
            return false;
        }
        
        // Check classification confidence and sentiment
        $confidence = $classification['confidence'] ?? 0;
        $sentiment = $classification['sentiment_score'] ?? 0;
        
        if ($confidence < self::MIN_CONFIDENCE || $sentiment < self::MIN_SENTIMENT_SCORE) {
            return false;
        }
        
        // Check if it's a positive category
        if (!in_array($classification['category'], ['positive', 'question'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Create lead forwarding email
     */
    private function createLeadForwardEmail($leadData, $classification) {
        $domain = $leadData['domain'];
        $campaignName = $leadData['campaign_name'];
        $contactEmail = $leadData['contact_email'];
        $qualityScore = $leadData['quality_score'] ?? 0;
        $domainRating = $leadData['domain_rating'] ?? 0;
        $organicTraffic = number_format($leadData['organic_traffic'] ?? 0);
        
        $subject = "Qualified Lead: {$domain} - {$classification['category']} response";
        
        $body = $this->buildLeadForwardEmailBody([
            'lead_data' => $leadData,
            'classification' => $classification,
            'domain' => $domain,
            'campaign_name' => $campaignName,
            'contact_email' => $contactEmail,
            'quality_score' => $qualityScore,
            'domain_rating' => $domainRating,
            'organic_traffic' => $organicTraffic,
            'reply_content' => $leadData['reply_content'],
            'original_subject' => $leadData['original_subject'],
            'original_body' => $leadData['original_body'],
            'outreach_sent_at' => $leadData['outreach_sent_at'],
            'reply_date' => $leadData['reply_date']
        ]);
        
        return [
            'subject' => $subject,
            'body' => $body
        ];
    }
    
    /**
     * Build comprehensive lead forwarding email body
     */
    private function buildLeadForwardEmailBody($data) {
        $replySnippet = $this->extractReplySnippet($data['reply_content']);
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Qualified Lead Forward</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0 0; opacity: 0.9; }
        .content { padding: 0 25px; }
        .lead-summary { background: #f8f9fa; border-left: 5px solid #28a745; padding: 20px; margin: 20px 0; border-radius: 0 5px 5px 0; }
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 20px 0; }
        .metric-card { background: #fff; border: 1px solid #e9ecef; padding: 15px; text-align: center; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .metric-value { font-size: 24px; font-weight: bold; color: #495057; margin-bottom: 5px; }
        .metric-label { font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .email-thread { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; margin: 20px 0; }
        .email-header { background: #e9ecef; padding: 15px; border-bottom: 1px solid #dee2e6; }
        .email-body { padding: 20px; }
        .reply-highlight { background: #d1ecf1; border-left: 4px solid #bee5eb; padding: 15px; margin: 15px 0; border-radius: 0 5px 5px 0; }
        .action-buttons { text-align: center; margin: 30px 0; }
        .btn { display: inline-block; padding: 12px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 25px; font-weight: 500; margin: 0 10px; transition: all 0.3s ease; }
        .btn:hover { background: #0056b3; transform: translateY(-1px); }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #1e7e34; }
        .next-steps { background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .next-steps h3 { color: #856404; margin-top: 0; }
        .next-steps ol li { margin-bottom: 8px; }
        .footer { border-top: 2px solid #e9ecef; padding: 20px; margin-top: 40px; text-align: center; color: #6c757d; font-size: 14px; }
        .classification-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .positive { background: #d4edda; color: #155724; }
        .question { background: #d1ecf1; color: #0c5460; }
        .confidence-bar { background: #e9ecef; height: 8px; border-radius: 4px; overflow: hidden; margin-top: 8px; }
        .confidence-fill { background: linear-gradient(90deg, #ffc107, #28a745); height: 100%; transition: width 0.3s ease; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Qualified Lead Alert</h1>
        <p>A high-quality prospect has shown interest in your guest post campaign</p>
    </div>
    
    <div class="content">
        <div class="lead-summary">
            <h2 style="margin-top: 0; color: #28a745;">
                ' . htmlspecialchars($data['domain']) . ' 
                <span class="classification-badge ' . $data['classification']['category'] . '">
                    ' . $data['classification']['category'] . '
                </span>
            </h2>
            <p><strong>Contact:</strong> <a href="mailto:' . htmlspecialchars($data['contact_email']) . '">' . htmlspecialchars($data['contact_email']) . '</a></p>
            <p><strong>Campaign:</strong> ' . htmlspecialchars($data['campaign_name']) . '</p>
            <p><strong>Classification:</strong> ' . ucfirst($data['classification']['category']) . ' response with ' . round($data['classification']['confidence'] * 100) . '% confidence</p>
            <div class="confidence-bar">
                <div class="confidence-fill" style="width: ' . round($data['classification']['confidence'] * 100) . '%"></div>
            </div>
        </div>
        
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-value">' . $data['quality_score'] . '</div>
                <div class="metric-label">Quality Score</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">' . $data['domain_rating'] . '</div>
                <div class="metric-label">Domain Rating</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">' . $data['organic_traffic'] . '</div>
                <div class="metric-label">Monthly Traffic</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">' . round($data['classification']['sentiment_score'] * 100) . '%</div>
                <div class="metric-label">Sentiment Score</div>
            </div>
        </div>
        
        <h3>Their Response</h3>
        <div class="reply-highlight">
            <p><strong>Received:</strong> ' . date('M j, Y g:i A', strtotime($data['reply_date'])) . '</p>
            <div style="margin-top: 15px; font-style: italic;">
                "' . htmlspecialchars($replySnippet) . '"
            </div>
        </div>
        
        <h3>Email Thread</h3>
        <div class="email-thread">
            <div class="email-header">
                <strong>Your Original Outreach</strong> - ' . date('M j, Y g:i A', strtotime($data['outreach_sent_at'])) . '
            </div>
            <div class="email-body">
                <p><strong>Subject:</strong> ' . htmlspecialchars($data['original_subject']) . '</p>
                <p><strong>To:</strong> ' . htmlspecialchars($data['contact_email']) . '</p>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e9ecef;">
                    ' . nl2br(htmlspecialchars(substr($data['original_body'], 0, 500))) . (strlen($data['original_body']) > 500 ? '...' : '') . '
                </div>
            </div>
        </div>
        
        <div class="action-buttons">
            <a href="mailto:' . htmlspecialchars($data['contact_email']) . '?subject=Re: ' . urlencode($data['original_subject']) . '" class="btn btn-success">
                Reply to Lead
            </a>
            <a href="https://' . htmlspecialchars($data['domain']) . '" target="_blank" class="btn">
                Visit Website
            </a>
        </div>
        
        <div class="footer">
            <p><strong>Automated Lead Detection System</strong></p>
            <p>This lead was automatically identified using AI-powered reply classification.</p>
            <p>Classification confidence: ' . round($data['classification']['confidence'] * 100) . '% | 
               Sentiment score: ' . round($data['classification']['sentiment_score'] * 100) . '%</p>
            <p style="margin-top: 15px;"><em>Generated on ' . date('M j, Y g:i A') . '</em></p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Extract meaningful snippet from reply
     */
    private function extractReplySnippet($replyContent, $maxLength = 200) {
        // Clean the content
        $content = strip_tags($replyContent);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        // Get first meaningful sentence or paragraph
        $sentences = preg_split('/[.!?]+/', $content);
        $snippet = '';
        
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) > 10) { // Skip very short sentences
                $snippet = $sentence;
                break;
            }
        }
        
        if (empty($snippet)) {
            $snippet = substr($content, 0, $maxLength);
        }
        
        return strlen($snippet) > $maxLength ? substr($snippet, 0, $maxLength) . '...' : $snippet;
    }
    
    /**
     * Configure Gmail for campaign sending
     */
    private function configureGmailForCampaign($campaignId) {
        // Get campaign email settings
        $campaign = $this->db->fetchOne(
            "SELECT * FROM campaigns WHERE id = ?",
            [$campaignId]
        );
        
        if (!$campaign || empty($campaign['owner_email'])) {
            throw new Exception("Campaign email configuration not found");
        }
        
        // Configure Gmail with appropriate sender credentials
        $this->gmail->configureSender($campaign['owner_email']);
    }
    
    /**
     * Send forwarding email
     */
    private function sendForwardEmail($recipientEmail, $emailData) {
        return $this->gmail->sendEmail(
            $recipientEmail,
            $emailData['subject'],
            $emailData['body']
        );
    }
    
    /**
     * Record lead forwarding in database
     */
    private function recordLeadForwarding($campaignId, $domainId, $replyId, $messageId, $classification) {
        $sql = "
            INSERT INTO lead_forwardings (
                campaign_id, domain_id, reply_id, forwarded_to_email,
                gmail_message_id, classification_category, classification_confidence,
                sentiment_score, forwarded_at, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'sent')
        ";
        
        // Get owner email
        $ownerEmail = $this->db->fetchOne(
            "SELECT owner_email FROM campaigns WHERE id = ?",
            [$campaignId]
        )['owner_email'];
        
        $this->db->execute($sql, [
            $campaignId,
            $domainId,
            $replyId,
            $ownerEmail,
            $messageId,
            $classification['category'],
            $classification['confidence'] ?? 0.8,
            $classification['sentiment_score'] ?? 0.7
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Update campaign lead metrics
     */
    private function updateCampaignLeadMetrics($campaignId) {
        $this->db->execute(
            "UPDATE campaigns SET qualified_leads_count = qualified_leads_count + 1, leads_forwarded = leads_forwarded + 1 WHERE id = ?",
            [$campaignId]
        );
    }
    
    /**
     * Get lead forwarding statistics
     */
    public function getForwardingStats($campaignId = null, $days = 30) {
        $whereClause = "WHERE lf.forwarded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params = [$days];
        
        if ($campaignId) {
            $whereClause .= " AND lf.campaign_id = ?";
            $params[] = $campaignId;
        }
        
        $sql = "
            SELECT 
                DATE(lf.forwarded_at) as forward_date,
                COUNT(*) as forwards_count,
                COUNT(DISTINCT lf.forwarded_to_email) as unique_recipients,
                AVG(lf.classification_confidence) as avg_confidence,
                AVG(lf.sentiment_score) as avg_sentiment
            FROM lead_forwardings lf
            {$whereClause}
            GROUP BY DATE(lf.forwarded_at)
            ORDER BY forward_date DESC
        ";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get recent lead forwarding activity
     */
    public function getRecentForwardings($limit = 10) {
        $sql = "
            SELECT 
                lf.*,
                c.name as campaign_name,
                td.domain,
                er.reply_content,
                er.sender_email
            FROM lead_forwardings lf
            LEFT JOIN campaigns c ON lf.campaign_id = c.id
            LEFT JOIN target_domains td ON lf.domain_id = td.id
            LEFT JOIN email_replies er ON lf.reply_id = er.id
            ORDER BY lf.forwarded_at DESC
            LIMIT ?
        ";
        
        return $this->db->fetchAll($sql, [$limit]);
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