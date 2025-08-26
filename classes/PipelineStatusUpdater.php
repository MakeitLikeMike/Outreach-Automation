<?php
/**
 * Pipeline Status Updater - Real-time campaign progress tracking
 */
require_once __DIR__ . '/../config/database.php';

class PipelineStatusUpdater {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Update pipeline status and progress for all campaigns
     */
    public function updateAllCampaigns() {
        $campaigns = $this->db->fetchAll("SELECT id FROM campaigns WHERE pipeline_status != 'completed'");
        
        foreach ($campaigns as $campaign) {
            $this->updateCampaignStatus($campaign['id']);
        }
    }
    
    /**
     * Update pipeline status for a specific campaign
     */
    public function updateCampaignStatus($campaignId) {
        // Get campaign data
        $campaign = $this->db->fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
        if (!$campaign) return;
        
        // Get domain statistics
        $stats = $this->getDomainStats($campaignId);
        
        // Determine new pipeline status and progress
        $newStatus = $this->determineStatus($stats);
        $progressPercentage = $this->calculateRealProgress($stats);
        
        // Update only if status changed
        if ($campaign['pipeline_status'] !== $newStatus || 
            abs(($campaign['progress_percentage'] ?? 0) - $progressPercentage) > 5) {
            
            $this->db->execute("
                UPDATE campaigns 
                SET pipeline_status = ?, 
                    progress_percentage = ?, 
                    updated_at = NOW() 
                WHERE id = ?
            ", [$newStatus, $progressPercentage, $campaignId]);
        }
        
        return [
            'status' => $newStatus,
            'progress' => $progressPercentage,
            'stats' => $stats
        ];
    }
    
    /**
     * Get detailed domain statistics for a campaign
     */
    private function getDomainStats($campaignId) {
        $sql = "
            SELECT 
                COUNT(*) as total_domains,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_domains,
                COUNT(CASE WHEN contact_email IS NOT NULL AND contact_email != '' THEN 1 END) as emails_found,
                COUNT(CASE WHEN status = 'contacted' THEN 1 END) as emails_sent,
                COUNT(CASE WHEN status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'contacted') THEN 1 END) as processed_domains
            FROM target_domains 
            WHERE campaign_id = ?
        ";
        
        $domainStats = $this->db->fetchOne($sql, [$campaignId]);
        
        // Get email statistics
        $emailStats = $this->db->fetchOne("
            SELECT 
                COUNT(*) as total_emails,
                COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent_emails,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_emails
            FROM outreach_emails 
            WHERE campaign_id = ?
        ", [$campaignId]);
        
        // Get reply statistics
        $replyStats = $this->db->fetchOne("
            SELECT 
                COUNT(*) as total_replies,
                COUNT(CASE WHEN classification_category = 'positive' THEN 1 END) as qualified_leads
            FROM email_replies er
            JOIN outreach_emails oe ON er.original_email_id = oe.id
            WHERE oe.campaign_id = ?
        ", [$campaignId]);
        
        return array_merge(
            $domainStats ?: ['total_domains' => 0, 'approved_domains' => 0, 'emails_found' => 0, 'emails_sent' => 0, 'processed_domains' => 0],
            $emailStats ?: ['total_emails' => 0, 'sent_emails' => 0, 'failed_emails' => 0],
            $replyStats ?: ['total_replies' => 0, 'qualified_leads' => 0]
        );
    }
    
    /**
     * Determine pipeline status based on actual progress
     */
    private function determineStatus($stats) {
        // If we have replies and qualified leads, we're monitoring
        if ($stats['qualified_leads'] > 0 || $stats['total_replies'] > 0) {
            return 'monitoring_replies';
        }
        
        // If we have sent emails, we're monitoring for replies
        if ($stats['sent_emails'] > 0) {
            return 'monitoring_replies';
        }
        
        // If we have emails but none sent yet, we're sending
        if ($stats['emails_found'] > 0 && $stats['sent_emails'] == 0) {
            return 'sending_outreach';
        }
        
        // If we have approved domains but no emails, we're finding emails
        if ($stats['approved_domains'] > 0 && $stats['emails_found'] == 0) {
            return 'finding_emails';
        }
        
        // If we have domains but none approved, we're analyzing quality
        if ($stats['total_domains'] > 0 && $stats['approved_domains'] == 0) {
            return 'analyzing_quality';
        }
        
        // If we have any domains, we're processing
        if ($stats['total_domains'] > 0) {
            return 'processing_domains';
        }
        
        // Default to created
        return 'created';
    }
    
    /**
     * Calculate real progress percentage based on actual completion
     */
    private function calculateRealProgress($stats) {
        $totalDomains = max(1, $stats['total_domains']); // Avoid division by zero
        
        $progress = 0;
        
        // 20% for having domains
        if ($stats['total_domains'] > 0) {
            $progress += 20;
        }
        
        // 30% for domain approval (weighted by completion)
        if ($stats['approved_domains'] > 0) {
            $progress += 30 * ($stats['approved_domains'] / $totalDomains);
        }
        
        // 25% for finding emails (weighted by completion)
        if ($stats['emails_found'] > 0) {
            $emailFindRate = min(1, $stats['emails_found'] / max(1, $stats['approved_domains']));
            $progress += 25 * $emailFindRate;
        }
        
        // 20% for sending emails (weighted by completion)
        if ($stats['sent_emails'] > 0) {
            $emailSendRate = min(1, $stats['sent_emails'] / max(1, $stats['emails_found']));
            $progress += 20 * $emailSendRate;
        }
        
        // 5% bonus for getting replies
        if ($stats['total_replies'] > 0) {
            $progress += 5;
        }
        
        // 5% bonus for qualified leads
        if ($stats['qualified_leads'] > 0) {
            $progress += 5;
        }
        
        return min(100, round($progress));
    }
    
    /**
     * Mark campaign as completed if all work is done
     */
    public function checkForCompletion($campaignId) {
        $stats = $this->getDomainStats($campaignId);
        
        // Campaign is complete if:
        // 1. We have domains
        // 2. All emails that can be sent have been sent
        // 3. We're monitoring for replies
        
        if ($stats['total_domains'] > 0 && 
            $stats['sent_emails'] > 0 && 
            $this->determineStatus($stats) === 'monitoring_replies') {
            
            $this->db->execute("
                UPDATE campaigns 
                SET pipeline_status = 'completed', 
                    progress_percentage = 100,
                    processing_completed_at = NOW()
                WHERE id = ?
            ", [$campaignId]);
            
            return true;
        }
        
        return false;
    }
}
?>