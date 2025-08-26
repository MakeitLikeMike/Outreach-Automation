<?php
/**
 * IMAP Reply Forwarder - Forward replies to campaign owners
 * Links incoming replies to original outreach campaigns and forwards to campaign owner
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ReplyClassifier.php';
require_once __DIR__ . '/GMassIntegration.php';

class IMAPReplyForwarder {
    private $db;
    private $replyClassifier;
    private $gmass;
    private $logFile;
    
    public function __construct() {
        $this->db = new Database();
        $this->replyClassifier = new ReplyClassifier();
        $this->gmass = new GMassIntegration();
        $this->logFile = __DIR__ . '/../logs/imap_reply_forwarder.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Process incoming reply and forward to campaign owner
     */
    public function forwardReplyToCampaignOwner($reply) {
        try {
            // Find the original outreach email this reply is responding to
            $originalEmail = $this->findOriginalOutreachEmail($reply);
            
            if (!$originalEmail) {
                $this->log("⚠️ Could not find original outreach email for reply from: " . $reply['from_email']);
                return false;
            }
            
            // Get campaign owner email
            $campaignOwner = $this->getCampaignOwnerEmail($originalEmail['campaign_id']);
            
            if (!$campaignOwner) {
                $this->log("⚠️ No campaign owner found for campaign ID: " . $originalEmail['campaign_id']);
                return false;
            }
            
            // Classify the reply
            $classificationResult = $this->replyClassifier->classifyReply($reply['body'], $reply['subject'], $reply['from_email']);
            $classification = $classificationResult['category'];
            
            // Only forward positive/interested replies to campaign owner
            if ($this->shouldForwardToCampaignOwner($classification)) {
                return $this->sendForwardedReply($reply, $originalEmail, $campaignOwner, $classification);
            } else {
                $this->log("Reply classified as '{$classification}' - not forwarding to campaign owner");
                return false;
            }
            
        } catch (Exception $e) {
            $this->log("❌ Error forwarding reply: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Find original outreach email based on reply details
     */
    private function findOriginalOutreachEmail($reply) {
        // Method 1: Try to match by recipient email and sender domain
        $sql = "SELECT oe.*, c.name as campaign_name 
                FROM outreach_emails oe
                LEFT JOIN campaigns c ON oe.campaign_id = c.id
                WHERE oe.sender_email = ? AND oe.recipient_email = ?
                AND oe.status = 'sent'
                ORDER BY oe.sent_at DESC 
                LIMIT 1";
        
        $result = $this->db->fetchAll($sql, [$reply['to_email'], $reply['from_email']]);
        
        if (!empty($result)) {
            $this->log("✅ Found original email by exact match: Campaign '{$result[0]['campaign_name']}'");
            return $result[0];
        }
        
        // Method 2: Try to match by subject line patterns (Re:, Fwd:, etc.)
        if (preg_match('/^(Re:|RE:|Fwd:|FWD:)\s*(.+)$/i', $reply['subject'], $matches)) {
            $originalSubject = trim($matches[2]);
            
            $sql = "SELECT oe.*, c.name as campaign_name 
                    FROM outreach_emails oe
                    LEFT JOIN campaigns c ON oe.campaign_id = c.id
                    WHERE oe.recipient_email = ? 
                    AND oe.subject LIKE ?
                    AND oe.status = 'sent'
                    ORDER BY oe.sent_at DESC 
                    LIMIT 1";
            
            $result = $this->db->fetchAll($sql, [$reply['from_email'], '%' . $originalSubject . '%']);
            
            if (!empty($result)) {
                $this->log("✅ Found original email by subject match: Campaign '{$result[0]['campaign_name']}'");
                return $result[0];
            }
        }
        
        // Method 3: Fallback - match by recipient email only (recent emails)
        $sql = "SELECT oe.*, c.name as campaign_name 
                FROM outreach_emails oe
                LEFT JOIN campaigns c ON oe.campaign_id = c.id
                WHERE oe.recipient_email = ?
                AND oe.status = 'sent'
                AND oe.sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY oe.sent_at DESC 
                LIMIT 1";
        
        $result = $this->db->fetchAll($sql, [$reply['from_email']]);
        
        if (!empty($result)) {
            $this->log("✅ Found original email by recipient fallback: Campaign '{$result[0]['campaign_name']}'");
            return $result[0];
        }
        
        return null;
    }
    
    /**
     * Get campaign owner email from campaign ID
     */
    private function getCampaignOwnerEmail($campaignId) {
        $sql = "SELECT owner_email FROM campaigns WHERE id = ?";
        $result = $this->db->fetchAll($sql, [$campaignId]);
        
        if (!empty($result) && !empty($result[0]['owner_email'])) {
            return $result[0]['owner_email'];
        }
        
        return null;
    }
    
    /**
     * Check if reply should be forwarded to campaign owner
     */
    private function shouldForwardToCampaignOwner($classification) {
        // Forward positive, interested, and question replies to campaign owner
        $forwardableTypes = [
            'positive',
            'interested', 
            'question',
            'collaboration_interest'
        ];
        
        return in_array($classification, $forwardableTypes);
    }
    
    /**
     * Send forwarded reply to campaign owner
     */
    private function sendForwardedReply($reply, $originalEmail, $campaignOwnerEmail, $classification) {
        try {
            $subject = "🎯 LEAD REPLY: " . $reply['subject'];
            $body = $this->buildForwardedReplyEmail($reply, $originalEmail, $classification);
            
            // Send via GMass SMTP
            $result = $this->gmass->sendEmail(
                $originalEmail['sender_email'], // From original sender email  
                $campaignOwnerEmail,           // To campaign owner
                $subject,
                $body
            );
            
            if ($result['success']) {
                $this->log("✅ Reply forwarded to campaign owner: {$campaignOwnerEmail}");
                $this->logForwardedReply($reply, $originalEmail, $campaignOwnerEmail, $classification);
                return true;
            } else {
                $this->log("❌ Failed to forward reply to campaign owner");
                return false;
            }
            
        } catch (Exception $e) {
            $this->log("❌ Error sending forwarded reply: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Build forwarded reply email content
     */
    private function buildForwardedReplyEmail($reply, $originalEmail, $classification) {
        $classificationLabel = ucfirst(str_replace('_', ' ', $classification));
        $classificationColor = $this->getClassificationColor($classification);
        
        return "
        <div style='font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto;'>
            <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;'>
                <h1 style='margin: 0; font-size: 24px;'>🎯 New Lead Reply Received</h1>
                <p style='margin: 10px 0 0 0; opacity: 0.9;'>Automated Lead Forwarding System</p>
            </div>
            
            <div style='background: #f8f9fa; padding: 25px; border-radius: 0 0 8px 8px;'>
                <div style='background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;'>
                    <h2 style='color: #333; margin-top: 0;'>Reply Details</h2>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>From:</td>
                            <td style='padding: 8px 0;'>{$reply['from_name']} &lt;{$reply['from_email']}&gt;</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: bold; color: #555;'>Subject:</td>
                            <td style='padding: 8px 0;'>{$reply['subject']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: bold; color: #555;'>Date:</td>
                            <td style='padding: 8px 0;'>{$reply['date']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: bold; color: #555;'>Classification:</td>
                            <td style='padding: 8px 0;'>
                                <span style='background: {$classificationColor}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;'>
                                    {$classificationLabel}
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div style='background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px;'>
                    <h2 style='color: #333; margin-top: 0;'>Original Campaign Context</h2>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>Campaign:</td>
                            <td style='padding: 8px 0;'>{$originalEmail['campaign_name']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: bold; color: #555;'>Original Subject:</td>
                            <td style='padding: 8px 0;'>{$originalEmail['subject']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: bold; color: #555;'>Sent From:</td>
                            <td style='padding: 8px 0;'>{$originalEmail['sender_email']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: bold; color: #555;'>Sent Date:</td>
                            <td style='padding: 8px 0;'>" . date('M j, Y g:i A', strtotime($originalEmail['sent_at'])) . "</td>
                        </tr>
                    </table>
                </div>
                
                <div style='background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                    <h2 style='color: #333; margin-top: 0;'>Reply Message</h2>
                    <div style='background: #f8f9fa; padding: 15px; border-radius: 4px; border-left: 4px solid #28a745; font-family: Georgia, serif; line-height: 1.6;'>
                        " . nl2br(htmlspecialchars($reply['body'])) . "
                    </div>
                </div>
                
                <div style='margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 8px; text-align: center;'>
                    <p style='margin: 0; color: #1976d2; font-size: 14px;'>
                        💡 <strong>Next Steps:</strong> Review this lead and follow up directly from your email account
                    </p>
                </div>
            </div>
            
            <div style='text-align: center; margin-top: 20px; color: #666; font-size: 12px;'>
                <p>This lead was automatically detected and forwarded by your IMAP Reply Monitor</p>
                <p>System Time: " . date('Y-m-d H:i:s') . " | Campaign Owner: {$campaignOwnerEmail}</p>
            </div>
        </div>";
    }
    
    /**
     * Get color for classification badge
     */
    private function getClassificationColor($classification) {
        $colors = [
            'positive' => '#28a745',
            'interested' => '#17a2b8', 
            'question' => '#ffc107',
            'collaboration_interest' => '#6f42c1',
            'neutral' => '#6c757d',
            'negative' => '#dc3545'
        ];
        
        return $colors[$classification] ?? '#6c757d';
    }
    
    /**
     * Log forwarded reply to database
     */
    private function logForwardedReply($reply, $originalEmail, $campaignOwnerEmail, $classification) {
        try {
            $sql = "INSERT INTO forwarded_replies 
                    (original_email_id, campaign_id, reply_from_email, reply_subject, 
                     forwarded_to_email, classification, forwarded_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
            
            $this->db->execute($sql, [
                $originalEmail['id'],
                $originalEmail['campaign_id'],
                $reply['from_email'],
                $reply['subject'],
                $campaignOwnerEmail,
                $classification
            ]);
            
        } catch (Exception $e) {
            // Don't fail the forwarding if logging fails
            $this->log("⚠️ Failed to log forwarded reply: " . $e->getMessage());
        }
    }
    
    /**
     * Log message with timestamp
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
?>