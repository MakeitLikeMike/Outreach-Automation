<?php
class DashboardMetrics {
    private $db;
    
    public function __construct() {
        require_once __DIR__ . "/../config/database.php";
        $this->db = new Database();
    }
    
    /**
     * Get accurate campaign statistics
     */
    public function getCampaignStats($dateRange = 30, $startDate = null, $endDate = null) {
        $whereClause = $this->buildDateWhereClause('c.created_at', $dateRange, $startDate, $endDate);
        
        $sql = "
            SELECT 
                COUNT(DISTINCT c.id) as total_campaigns,
                COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END) as active_campaigns,
                COUNT(DISTINCT CASE WHEN c.status = 'completed' THEN c.id END) as completed_campaigns,
                COUNT(DISTINCT CASE WHEN c.is_automated = 1 THEN c.id END) as automated_campaigns
            FROM campaigns c
            WHERE {$whereClause['condition']}
        ";
        
        return $this->db->fetchOne($sql, $whereClause['params']);
    }
    
    /**
     * Get accurate domain statistics
     */
    public function getDomainStats($dateRange = 30, $startDate = null, $endDate = null) {
        $whereClause = $this->buildDateWhereClause('td.created_at', $dateRange, $startDate, $endDate);
        
        $sql = "
            SELECT 
                COUNT(DISTINCT td.id) as total_domains,
                COUNT(DISTINCT CASE WHEN td.status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') THEN td.id END) as approved_domains,
                COUNT(DISTINCT CASE WHEN td.status = 'rejected' THEN td.id END) as rejected_domains,
                COUNT(DISTINCT CASE WHEN td.status = 'pending' THEN td.id END) as pending_domains,
                COUNT(DISTINCT CASE WHEN td.contact_email IS NOT NULL THEN td.id END) as domains_with_emails,
                COUNT(DISTINCT CASE WHEN td.status = 'contacted' OR EXISTS(
                    SELECT 1 FROM outreach_emails oe WHERE oe.domain_id = td.id AND oe.status = 'sent'
                ) THEN td.id END) as contacted_domains
            FROM target_domains td
            WHERE {$whereClause['condition']}
        ";
        
        return $this->db->fetchOne($sql, $whereClause['params']);
    }
    
    /**
     * Get accurate email statistics
     */
    public function getEmailStats($dateRange = 30, $startDate = null, $endDate = null) {
        $whereClause = $this->buildDateWhereClause('oe.sent_at', $dateRange, $startDate, $endDate);
        
        $sql = "
            SELECT 
                COUNT(DISTINCT oe.id) as total_emails_sent,
                COUNT(DISTINCT CASE WHEN oe.replied_at IS NOT NULL THEN oe.id END) as emails_with_replies,
                COUNT(DISTINCT CASE WHEN er.classification_category = 'positive' THEN oe.id END) as positive_replies,
                COUNT(DISTINCT CASE WHEN fl.id IS NOT NULL THEN oe.id END) as emails_that_became_leads
            FROM outreach_emails oe
            LEFT JOIN email_replies er ON oe.id = er.original_email_id
            LEFT JOIN forwarded_leads fl ON er.id = fl.reply_id
            WHERE oe.status = 'sent' AND {$whereClause['condition']}
        ";
        
        return $this->db->fetchOne($sql, $whereClause['params']);
    }
    
    /**
     * Get accurate lead forwarding statistics
     */
    public function getLeadStats($dateRange = 30, $startDate = null, $endDate = null) {
        $whereClause = $this->buildDateWhereClause('fl.created_at', $dateRange, $startDate, $endDate);
        
        $sql = "
            SELECT 
                COUNT(DISTINCT fl.id) as total_leads_forwarded,
                COUNT(DISTINCT CASE WHEN fl.status = 'sent' THEN fl.id END) as leads_sent,
                COUNT(DISTINCT er.campaign_id) as campaigns_with_leads,
                ROUND(AVG(CASE WHEN er.classification_confidence IS NOT NULL THEN er.classification_confidence ELSE 0.8 END), 2) as avg_lead_confidence
            FROM forwarded_leads fl
            LEFT JOIN email_replies er ON fl.reply_id = er.id
            WHERE {$whereClause['condition']}
        ";
        
        return $this->db->fetchOne($sql, $whereClause['params']);
    }
    
    /**
     * Get automation pipeline status with accurate data
     */
    public function getAutomationPipelineStatus() {
        $sql = "
            SELECT 
                c.id,
                c.name,
                c.pipeline_status,
                c.created_at,
                c.is_automated,
                c.auto_send,
                COUNT(DISTINCT td.id) as total_domains,
                COUNT(DISTINCT CASE WHEN td.status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') THEN td.id END) as approved_domains,
                COUNT(DISTINCT CASE WHEN td.contact_email IS NOT NULL THEN td.id END) as domains_with_emails,
                COUNT(DISTINCT oe.id) as emails_sent,
                COUNT(DISTINCT er.id) as replies_received,
                COUNT(DISTINCT fl.id) as leads_generated,
                CASE 
                    WHEN c.pipeline_status = 'completed' THEN 100
                    WHEN c.pipeline_status = 'monitoring_replies' THEN 80
                    WHEN c.pipeline_status = 'sending_outreach' THEN 60
                    WHEN c.pipeline_status = 'finding_emails' THEN 40
                    WHEN c.pipeline_status = 'analyzing_quality' THEN 30
                    WHEN c.pipeline_status = 'processing_domains' THEN 20
                    WHEN c.pipeline_status = 'fetching_domains' THEN 10
                    ELSE 5
                END as progress_percentage
            FROM campaigns c
            LEFT JOIN target_domains td ON c.id = td.campaign_id
            LEFT JOIN outreach_emails oe ON td.id = oe.domain_id AND oe.status = 'sent'
            LEFT JOIN email_replies er ON oe.id = er.original_email_id
            LEFT JOIN forwarded_leads fl ON er.id = fl.reply_id
            WHERE c.status = 'active' AND c.pipeline_status NOT IN ('completed', 'failed', 'paused')
            GROUP BY c.id, c.name, c.pipeline_status, c.created_at, c.is_automated, c.auto_send
            ORDER BY c.created_at DESC
            LIMIT 10
        ";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Get automation summary stats
     */
    public function getAutomationSummary() {
        $campaigns = $this->getAutomationPipelineStatus();
        
        $summary = [
            'total_active' => count($campaigns),
            'processing' => 0,
            'sending' => 0,
            'monitoring' => 0,
            'total_domains_processing' => 0,
            'total_emails_sent_today' => 0
        ];
        
        foreach ($campaigns as $campaign) {
            if (in_array($campaign['pipeline_status'], ['processing_domains', 'analyzing_quality', 'finding_emails'])) {
                $summary['processing']++;
            } elseif ($campaign['pipeline_status'] === 'sending_outreach') {
                $summary['sending']++;
            } elseif ($campaign['pipeline_status'] === 'monitoring_replies') {
                $summary['monitoring']++;
            }
            
            $summary['total_domains_processing'] += $campaign['total_domains'];
        }
        
        // Get today's email sending stats
        $todayStats = $this->db->fetchOne("
            SELECT COUNT(*) as emails_sent_today 
            FROM outreach_emails 
            WHERE DATE(sent_at) = CURDATE() AND status = 'sent'
        ");
        
        $summary['total_emails_sent_today'] = $todayStats['emails_sent_today'] ?? 0;
        
        return $summary;
    }
    
    /**
     * Get background job queue status
     */
    public function getJobQueueStatus() {
        $sql = "
            SELECT 
                job_type,
                status,
                COUNT(*) as count,
                AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(completed_at, NOW()))) as avg_processing_time
            FROM background_jobs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY job_type, status
            ORDER BY job_type, status
        ";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Build date where clause for filtering
     */
    private function buildDateWhereClause($dateColumn, $dateRange = 30, $startDate = null, $endDate = null) {
        if ($startDate && $endDate) {
            // Custom date range
            return [
                'condition' => "{$dateColumn} BETWEEN ? AND ?",
                'params' => [$startDate, $endDate . ' 23:59:59']
            ];
        } else {
            // Quick range (days)
            return [
                'condition' => "{$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                'params' => [$dateRange]
            ];
        }
    }
}
?>