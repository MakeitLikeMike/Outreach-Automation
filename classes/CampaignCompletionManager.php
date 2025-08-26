<?php
/**
 * Campaign Completion Manager - Automated campaign lifecycle management
 * Handles campaign completion detection and post-campaign automation
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/GMassIntegration.php';

class CampaignCompletionManager {
    private $db;
    private $gmass;
    private $logFile;
    
    // Campaign completion criteria
    const COMPLETION_CHECK_DAYS = 30; // Monitor replies for 30 days
    const MIN_COMPLETION_PERCENTAGE = 90; // 90% of domains processed
    const MONITORING_GRACE_PERIOD = 7; // Extra days for reply monitoring
    
    public function __construct() {
        $this->db = new Database();
        $this->gmass = new GMassIntegration();
        $this->logFile = __DIR__ . '/../logs/campaign_completion.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Check and process campaign completions
     */
    public function processCampaignCompletions() {
        $this->log("🔍 Starting campaign completion check");
        
        try {
            // Get campaigns that might be complete
            $candidateCampaigns = $this->getCandidateCompletionCampaigns();
            
            $completedCount = 0;
            $updatedCount = 0;
            
            foreach ($candidateCampaigns as $campaign) {
                $result = $this->evaluateCampaignCompletion($campaign);
                
                if ($result['action'] === 'complete') {
                    $this->completeCampaign($campaign['id']);
                    $completedCount++;
                } elseif ($result['action'] === 'update_status') {
                    $this->updateCampaignProgress($campaign['id'], $result['new_status']);
                    $updatedCount++;
                }
            }
            
            $this->log("✅ Campaign completion check finished: {$completedCount} completed, {$updatedCount} updated");
            
            return [
                'completed_campaigns' => $completedCount,
                'updated_campaigns' => $updatedCount,
                'total_checked' => count($candidateCampaigns)
            ];
            
        } catch (Exception $e) {
            $this->log("❌ Error in campaign completion processing: " . $e->getMessage());
            return [
                'completed_campaigns' => 0,
                'updated_campaigns' => 0,
                'total_checked' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get campaigns that are candidates for completion
     */
    private function getCandidateCompletionCampaigns() {
        $sql = "
            SELECT 
                c.*,
                COUNT(td.id) as total_domains,
                COUNT(CASE WHEN td.status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') THEN 1 END) as approved_domains,
                COUNT(CASE WHEN td.contact_email IS NOT NULL THEN 1 END) as domains_with_emails,
                COUNT(CASE WHEN oe.status = 'sent' THEN 1 END) as emails_sent,
                COUNT(CASE WHEN oe.replied_at IS NOT NULL THEN 1 END) as replies_received,
                COUNT(CASE WHEN lf.id IS NOT NULL THEN 1 END) as leads_forwarded,
                MAX(oe.sent_at) as last_email_sent,
                MIN(oe.sent_at) as first_email_sent
            FROM campaigns c
            LEFT JOIN target_domains td ON c.id = td.campaign_id
            LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
            LEFT JOIN email_replies er ON oe.id = er.original_email_id
            LEFT JOIN lead_forwardings lf ON er.id = lf.reply_id
            WHERE c.pipeline_status IN ('sending_outreach', 'monitoring_replies', 'processing_domains', 'finding_emails')
            AND c.created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
            GROUP BY c.id
            ORDER BY c.created_at ASC
        ";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Evaluate if a campaign should be completed
     */
    private function evaluateCampaignCompletion($campaign) {
        $campaignId = $campaign['id'];
        $status = $campaign['pipeline_status'];
        $totalDomains = $campaign['total_domains'] ?? 0;
        $approvedDomains = $campaign['approved_domains'] ?? 0;
        $emailsSent = $campaign['emails_sent'] ?? 0;
        $lastEmailSent = $campaign['last_email_sent'];
        
        $this->log("📊 Evaluating campaign {$campaignId} ({$campaign['name']})");
        $this->log("   Status: {$status}, Domains: {$totalDomains}, Approved: {$approvedDomains}, Emails: {$emailsSent}");
        
        // Check if campaign has been running long enough
        $daysSinceLastEmail = $lastEmailSent ? 
            (time() - strtotime($lastEmailSent)) / (24 * 60 * 60) : 999;
        
        switch ($status) {
            case 'processing_domains':
                return $this->evaluateProcessingDomainsCompletion($campaign);
                
            case 'finding_emails':
                return $this->evaluateFindingEmailsCompletion($campaign);
                
            case 'sending_outreach':
                return $this->evaluateSendingOutreachCompletion($campaign);
                
            case 'monitoring_replies':
                return $this->evaluateMonitoringRepliesCompletion($campaign, $daysSinceLastEmail);
                
            default:
                return ['action' => 'none'];
        }
    }
    
    /**
     * Evaluate completion for processing_domains status
     */
    private function evaluateProcessingDomainsCompletion($campaign) {
        // Check if all domains have been processed
        $pendingDomains = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM target_domains WHERE campaign_id = ? AND status = 'pending'",
            [$campaign['id']]
        )['count'] ?? 0;
        
        if ($pendingDomains == 0 && $campaign['total_domains'] > 0) {
            return ['action' => 'update_status', 'new_status' => 'finding_emails'];
        }
        
        return ['action' => 'none'];
    }
    
    /**
     * Evaluate completion for finding_emails status
     */
    private function evaluateFindingEmailsCompletion($campaign) {
        // Check if all approved domains have been searched for emails
        $pendingEmailSearch = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM target_domains WHERE campaign_id = ? AND status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') AND contact_email IS NULL",
            [$campaign['id']]
        )['count'] ?? 0;
        
        if ($pendingEmailSearch == 0 && $campaign['approved_domains'] > 0) {
            return ['action' => 'update_status', 'new_status' => 'sending_outreach'];
        }
        
        return ['action' => 'none'];
    }
    
    /**
     * Evaluate completion for sending_outreach status
     */
    private function evaluateSendingOutreachCompletion($campaign) {
        // Check if all domains with emails have been contacted
        $pendingOutreach = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM target_domains td 
             WHERE td.campaign_id = ? AND td.status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') AND td.contact_email IS NOT NULL 
             AND NOT EXISTS (SELECT 1 FROM outreach_emails oe WHERE oe.domain_id = td.id AND oe.status = 'sent')",
            [$campaign['id']]
        )['count'] ?? 0;
        
        if ($pendingOutreach == 0 && $campaign['emails_sent'] > 0) {
            return ['action' => 'update_status', 'new_status' => 'monitoring_replies'];
        }
        
        return ['action' => 'none'];
    }
    
    /**
     * Evaluate completion for monitoring_replies status
     */
    private function evaluateMonitoringRepliesCompletion($campaign, $daysSinceLastEmail) {
        // If it's been long enough since last email, consider completion
        if ($daysSinceLastEmail >= self::COMPLETION_CHECK_DAYS + self::MONITORING_GRACE_PERIOD) {
            return ['action' => 'complete'];
        }
        
        // Check if we've received replies from a significant portion
        $replyRate = $campaign['emails_sent'] > 0 ? 
            ($campaign['replies_received'] / $campaign['emails_sent']) * 100 : 0;
        
        if ($daysSinceLastEmail >= self::COMPLETION_CHECK_DAYS && $replyRate >= 5) {
            // Good reply rate after monitoring period
            return ['action' => 'complete'];
        }
        
        return ['action' => 'none'];
    }
    
    /**
     * Complete a campaign
     */
    private function completeCampaign($campaignId) {
        $this->log("🎉 Completing campaign {$campaignId}");
        
        try {
            // Calculate final campaign metrics
            $metrics = $this->calculateCampaignMetrics($campaignId);
            
            // Update campaign status and metrics
            $sql = "
                UPDATE campaigns SET 
                    pipeline_status = 'completed',
                    completion_date = NOW(),
                    final_domains_count = ?,
                    final_approved_count = ?,
                    final_emails_sent = ?,
                    final_replies_received = ?,
                    final_leads_generated = ?,
                    reply_rate = ?,
                    lead_conversion_rate = ?,
                    campaign_roi_score = ?
                WHERE id = ?
            ";
            
            $this->db->execute($sql, [
                $metrics['total_domains'],
                $metrics['approved_domains'],
                $metrics['emails_sent'],
                $metrics['replies_received'],
                $metrics['leads_generated'],
                $metrics['reply_rate'],
                $metrics['lead_conversion_rate'],
                $metrics['roi_score'],
                $campaignId
            ]);
            
            // Send completion notification
            $this->sendCompletionNotification($campaignId, $metrics);
            
            // Clean up old background jobs for this campaign
            $this->cleanupCampaignJobs($campaignId);
            
            $this->log("✅ Campaign {$campaignId} completed successfully");
            
        } catch (Exception $e) {
            $this->log("❌ Error completing campaign {$campaignId}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Calculate comprehensive campaign metrics
     */
    private function calculateCampaignMetrics($campaignId) {
        $sql = "
            SELECT 
                COUNT(DISTINCT td.id) as total_domains,
                COUNT(DISTINCT CASE WHEN td.status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') THEN td.id END) as approved_domains,
                COUNT(DISTINCT CASE WHEN oe.status = 'sent' THEN oe.id END) as emails_sent,
                COUNT(DISTINCT CASE WHEN er.id IS NOT NULL THEN er.id END) as replies_received,
                COUNT(DISTINCT CASE WHEN lf.id IS NOT NULL THEN lf.id END) as leads_generated,
                COUNT(DISTINCT CASE WHEN er.classification_category = 'positive' THEN er.id END) as positive_replies,
                AVG(td.quality_score) as avg_quality_score,
                AVG(td.domain_rating) as avg_dr_rating,
                SUM(td.organic_traffic) as total_traffic_reach
            FROM campaigns c
            LEFT JOIN target_domains td ON c.id = td.campaign_id
            LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
            LEFT JOIN email_replies er ON oe.id = er.original_email_id
            LEFT JOIN lead_forwardings lf ON er.id = lf.reply_id
            WHERE c.id = ?
            GROUP BY c.id
        ";
        
        $result = $this->db->fetchOne($sql, [$campaignId]);
        
        $totalEmails = $result['emails_sent'] ?? 0;
        $totalReplies = $result['replies_received'] ?? 0;
        $totalLeads = $result['leads_generated'] ?? 0;
        
        $replyRate = $totalEmails > 0 ? ($totalReplies / $totalEmails) * 100 : 0;
        $leadConversionRate = $totalEmails > 0 ? ($totalLeads / $totalEmails) * 100 : 0;
        
        // Calculate ROI score based on multiple factors
        $roiScore = $this->calculateROIScore($result, $replyRate, $leadConversionRate);
        
        return [
            'total_domains' => $result['total_domains'] ?? 0,
            'approved_domains' => $result['approved_domains'] ?? 0,
            'emails_sent' => $totalEmails,
            'replies_received' => $totalReplies,
            'leads_generated' => $totalLeads,
            'positive_replies' => $result['positive_replies'] ?? 0,
            'reply_rate' => round($replyRate, 2),
            'lead_conversion_rate' => round($leadConversionRate, 2),
            'avg_quality_score' => round($result['avg_quality_score'] ?? 0, 1),
            'avg_dr_rating' => round($result['avg_dr_rating'] ?? 0, 1),
            'total_traffic_reach' => $result['total_traffic_reach'] ?? 0,
            'roi_score' => $roiScore
        ];
    }
    
    /**
     * Calculate ROI score based on campaign performance
     */
    private function calculateROIScore($metrics, $replyRate, $leadConversionRate) {
        $score = 0;
        
        // Reply rate contribution (40% weight)
        $score += min(40, $replyRate * 8); // Up to 40 points for 5% reply rate
        
        // Lead conversion contribution (35% weight)
        $score += min(35, $leadConversionRate * 17.5); // Up to 35 points for 2% conversion
        
        // Quality score contribution (15% weight)
        $avgQuality = $metrics['avg_quality_score'] ?? 0;
        $score += ($avgQuality / 100) * 15;
        
        // Domain rating contribution (10% weight)
        $avgDR = $metrics['avg_dr_rating'] ?? 0;
        $score += min(10, ($avgDR / 100) * 10);
        
        return round(min(100, $score), 1);
    }
    
    /**
     * Send completion notification to campaign owner
     */
    private function sendCompletionNotification($campaignId, $metrics) {
        try {
            $campaign = $this->db->fetchOne(
                "SELECT * FROM campaigns WHERE id = ?",
                [$campaignId]
            );
            
            if (!$campaign || empty($campaign['owner_email'])) {
                $this->log("⚠️ No owner email for campaign completion notification");
                return;
            }
            
            $emailData = $this->createCompletionEmail($campaign, $metrics);
            
            // Configure Gmail for sending
            $this->gmail->configureSender($campaign['owner_email']);
            
            // Send completion email
            $messageId = $this->gmass->sendEmail(
                $campaign['owner_email'],
                $emailData['subject'],
                $emailData['body']
            );
            
            $this->log("📧 Completion notification sent to {$campaign['owner_email']} (Message ID: {$messageId})");
            
        } catch (Exception $e) {
            $this->log("❌ Error sending completion notification: " . $e->getMessage());
        }
    }
    
    /**
     * Create campaign completion email
     */
    private function createCompletionEmail($campaign, $metrics) {
        $subject = "🎉 Campaign Complete: {$campaign['name']} - Final Results";
        
        $body = $this->buildCompletionEmailBody($campaign, $metrics);
        
        return [
            'subject' => $subject,
            'body' => $body
        ];
    }
    
    /**
     * Build campaign completion email body
     */
    private function buildCompletionEmailBody($campaign, $metrics) {
        $campaignName = htmlspecialchars($campaign['name']);
        $startDate = date('M j, Y', strtotime($campaign['created_at']));
        $endDate = date('M j, Y');
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Campaign Completion Report</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 32px; }
        .header p { margin: 10px 0 0 0; opacity: 0.9; font-size: 18px; }
        .content { padding: 30px; }
        .summary-card { background: #f8f9fa; border-left: 5px solid #28a745; padding: 25px; margin: 25px 0; border-radius: 0 8px 8px 0; }
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0; }
        .metric-card { background: #fff; border: 1px solid #e9ecef; padding: 20px; text-align: center; border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .metric-value { font-size: 36px; font-weight: bold; color: #495057; margin-bottom: 8px; }
        .metric-label { font-size: 14px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .roi-score { background: linear-gradient(135deg, #ffd700, #ffed4a); }
        .roi-score .metric-value { color: #856404; }
        .performance-section { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; margin: 25px 0; }
        .performance-header { background: #e9ecef; padding: 15px 20px; font-weight: bold; }
        .performance-body { padding: 25px; }
        .stats-bar { background: #e9ecef; height: 12px; border-radius: 6px; overflow: hidden; margin: 10px 0; }
        .stats-fill { background: linear-gradient(90deg, #28a745, #20c997); height: 100%; transition: width 0.3s ease; border-radius: 6px; }
        .next-steps { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 25px; border-radius: 8px; margin: 30px 0; }
        .next-steps h3 { color: #0066cc; margin-top: 0; }
        .footer { border-top: 2px solid #e9ecef; padding: 25px; margin-top: 40px; text-align: center; color: #6c757d; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎉 Campaign Complete!</h1>
        <p>Your outreach automation campaign has finished with great results</p>
    </div>
    
    <div class="content">
        <div class="summary-card">
            <h2 style="margin-top: 0; color: #28a745;">' . $campaignName . '</h2>
            <p><strong>Duration:</strong> ' . $startDate . ' - ' . $endDate . '</p>
            <p><strong>Total Processing Time:</strong> ' . $this->calculateDuration($campaign['created_at']) . '</p>
            <p><strong>Automation Status:</strong> Successfully completed all phases</p>
        </div>
        
        <div class="metrics-grid">
            <div class="metric-card roi-score">
                <div class="metric-value">' . $metrics['roi_score'] . '</div>
                <div class="metric-label">ROI Score</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">' . $metrics['total_domains'] . '</div>
                <div class="metric-label">Total Domains</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">' . $metrics['emails_sent'] . '</div>
                <div class="metric-label">Emails Sent</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">' . $metrics['leads_generated'] . '</div>
                <div class="metric-label">Qualified Leads</div>
            </div>
        </div>
        
        <div class="performance-section">
            <div class="performance-header">📊 Campaign Performance</div>
            <div class="performance-body">
                <div>
                    <strong>Reply Rate: ' . $metrics['reply_rate'] . '%</strong>
                    <div class="stats-bar">
                        <div class="stats-fill" style="width: ' . min(100, $metrics['reply_rate'] * 20) . '%"></div>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <strong>Lead Conversion Rate: ' . $metrics['lead_conversion_rate'] . '%</strong>
                    <div class="stats-bar">
                        <div class="stats-fill" style="width: ' . min(100, $metrics['lead_conversion_rate'] * 50) . '%"></div>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <strong>Average Domain Quality: ' . $metrics['avg_quality_score'] . '/100</strong>
                    <div class="stats-bar">
                        <div class="stats-fill" style="width: ' . $metrics['avg_quality_score'] . '%"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-value">' . number_format($metrics['total_traffic_reach']) . '</div>
                <div class="metric-label">Total Traffic Reach</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">' . $metrics['avg_dr_rating'] . '</div>
                <div class="metric-label">Avg Domain Rating</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">' . $metrics['positive_replies'] . '</div>
                <div class="metric-label">Positive Responses</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">' . ($metrics['approved_domains'] > 0 ? round(($metrics['emails_sent'] / $metrics['approved_domains']) * 100) : 0) . '%</div>
                <div class="metric-label">Outreach Coverage</div>
            </div>
        </div>
        
        <div class="next-steps">
            <h3>🚀 What\'s Next?</h3>
            <ul>
                <li><strong>Follow up with leads</strong> - You have ' . $metrics['leads_generated'] . ' qualified leads to pursue</li>
                <li><strong>Analyze performance</strong> - Review which domains and approaches worked best</li>
                <li><strong>Scale successful strategies</strong> - Use insights to improve future campaigns</li>
                <li><strong>Launch new campaigns</strong> - Apply lessons learned to new outreach efforts</li>
            </ul>
        </div>
        
        <div class="footer">
            <p><strong>🤖 Automated Outreach System</strong></p>
            <p>This campaign was fully automated from domain discovery to lead forwarding.</p>
            <p>Total automation saved you approximately <strong>' . $this->estimateTimeSaved($metrics) . ' hours</strong> of manual work!</p>
            <p><em>Campaign completed on ' . date('M j, Y g:i A') . '</em></p>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Calculate campaign duration
     */
    private function calculateDuration($startDate) {
        $start = new DateTime($startDate);
        $end = new DateTime();
        $interval = $start->diff($end);
        
        if ($interval->days > 0) {
            return $interval->days . ' days';
        } else {
            return $interval->h . ' hours, ' . $interval->i . ' minutes';
        }
    }
    
    /**
     * Estimate time saved by automation
     */
    private function estimateTimeSaved($metrics) {
        // Conservative estimates of manual time per task
        $timePerDomainAnalysis = 5; // minutes
        $timePerEmailSearch = 3; // minutes
        $timePerOutreachEmail = 10; // minutes
        $timePerReplyCheck = 2; // minutes
        
        $totalMinutes = 
            ($metrics['total_domains'] * $timePerDomainAnalysis) +
            ($metrics['approved_domains'] * $timePerEmailSearch) +
            ($metrics['emails_sent'] * $timePerOutreachEmail) +
            ($metrics['emails_sent'] * $timePerReplyCheck * 5); // Check 5 times on average
        
        return round($totalMinutes / 60, 1);
    }
    
    /**
     * Clean up old background jobs for completed campaign
     */
    private function cleanupCampaignJobs($campaignId) {
        $this->db->execute(
            "DELETE FROM background_jobs WHERE campaign_id = ? AND status IN ('completed', 'failed') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$campaignId]
        );
        
        $this->log("🧹 Cleaned up old background jobs for campaign {$campaignId}");
    }
    
    /**
     * Update campaign progress status
     */
    private function updateCampaignProgress($campaignId, $newStatus) {
        $this->db->execute(
            "UPDATE campaigns SET pipeline_status = ?, updated_at = NOW() WHERE id = ?",
            [$newStatus, $campaignId]
        );
        
        $this->log("📈 Updated campaign {$campaignId} status to: {$newStatus}");
    }
    
    /**
     * Get completion statistics
     */
    public function getCompletionStats($days = 30) {
        $sql = "
            SELECT 
                DATE(completion_date) as completion_date,
                COUNT(*) as campaigns_completed,
                AVG(reply_rate) as avg_reply_rate,
                AVG(lead_conversion_rate) as avg_conversion_rate,
                AVG(campaign_roi_score) as avg_roi_score,
                SUM(final_leads_generated) as total_leads_generated
            FROM campaigns
            WHERE completion_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(completion_date)
            ORDER BY completion_date DESC
        ";
        
        return $this->db->fetchAll($sql, [$days]);
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