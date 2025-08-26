<?php
require_once __DIR__ . '/../config/database.php';

class Campaign {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function create($name, $competitor_urls, $owner_email = null, $email_template_id = null, $automation_mode = 'template', $auto_send = 0, $automation_settings = [], $schedule_settings = [], $automation_sender_email = 'teamoutreach41@gmail.com') {
        // Check for duplicate campaign name
        if ($this->campaignNameExists($name)) {
            throw new Exception("Campaign name '$name' already exists. Please choose a different name.");
        }
        
        // Set default automation settings
        $auto_domain_analysis = $automation_settings['auto_domain_analysis'] ?? 1;
        $auto_email_search = $automation_settings['auto_email_search'] ?? 1;
        $auto_reply_monitoring = $automation_settings['auto_reply_monitoring'] ?? 1;
        $auto_lead_forwarding = $automation_settings['auto_lead_forwarding'] ?? 1;
        
        // Check if new automation columns exist
        if ($this->columnExists('campaigns', 'auto_domain_analysis')) {
            $sql = "INSERT INTO campaigns (
                name, competitor_urls, owner_email, automation_sender_email, email_template_id, automation_mode, auto_send, is_automated,
                auto_domain_analysis, auto_email_search, auto_reply_monitoring, auto_lead_forwarding,
                pipeline_status, processing_started_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, 'created', NOW())";
            
            $this->db->execute($sql, [
                $name, $competitor_urls, $owner_email, $automation_sender_email, $email_template_id, $automation_mode, $auto_send,
                $auto_domain_analysis, $auto_email_search, $auto_reply_monitoring, $auto_lead_forwarding
            ]);
        } elseif ($this->columnExists('campaigns', 'automation_mode')) {
            $sql = "INSERT INTO campaigns (name, competitor_urls, owner_email, automation_sender_email, email_template_id, automation_mode, auto_send, is_automated) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
            $this->db->execute($sql, [$name, $competitor_urls, $owner_email, $automation_sender_email, $email_template_id, $automation_mode, $auto_send]);
        } else {
            return $this->createBasic($name, $competitor_urls);
        }
        
        $campaignId = $this->db->lastInsertId();
        
        // Store scheduling settings if provided
        if (!empty($schedule_settings) && $schedule_settings['enable_smart_scheduling']) {
            $this->storeScheduleSettings($campaignId, $schedule_settings);
        }
        
        return $campaignId;
    }
    
    /**
     * Store campaign scheduling settings
     */
    private function storeScheduleSettings($campaignId, $scheduleSettings) {
        try {
            // Create campaign_schedules table if it doesn't exist
            $this->db->execute("
                CREATE TABLE IF NOT EXISTS campaign_schedules (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    campaign_id INT NOT NULL,
                    schedule_mode VARCHAR(20) DEFAULT 'optimized',
                    batch_size INT DEFAULT 50,
                    delay_between_batches INT DEFAULT 300,
                    max_sends_per_day INT DEFAULT 200,
                    respect_business_hours TINYINT(1) DEFAULT 1,
                    avoid_holidays TINYINT(1) DEFAULT 1,
                    timezone_optimization TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
                )
            ");
            
            // Insert schedule settings
            $sql = "INSERT INTO campaign_schedules (
                campaign_id, schedule_mode, batch_size, delay_between_batches, 
                max_sends_per_day, respect_business_hours, avoid_holidays, timezone_optimization
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $this->db->execute($sql, [
                $campaignId,
                $scheduleSettings['schedule_mode'],
                $scheduleSettings['batch_size'],
                $scheduleSettings['delay_between_batches'],
                $scheduleSettings['max_sends_per_day'],
                $scheduleSettings['respect_business_hours'],
                $scheduleSettings['avoid_holidays'],
                $scheduleSettings['timezone_optimization']
            ]);
        } catch (Exception $e) {
            // Log error but don't fail campaign creation
            error_log('Failed to store schedule settings: ' . $e->getMessage());
        }
    }
    
    public function createBasic($name, $competitor_urls) {
        $sql = "INSERT INTO campaigns (name, competitor_urls) VALUES (?, ?)";
        $this->db->execute($sql, [$name, $competitor_urls]);
        return $this->db->lastInsertId();
    }
    
    public function getAll() {
        $sql = "SELECT c.id, c.name, c.competitor_urls, c.owner_email, c.email_template_id, c.status, 
                       c.leads_forwarded, c.is_automated, c.automation_mode, c.auto_send, 
                       c.pipeline_status, c.processing_started_at, c.processing_completed_at, 
                       c.created_at, c.updated_at,
                       COUNT(td.id) as total_domains,
                       COUNT(CASE WHEN td.status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') THEN 1 END) as approved_domains,
                       COUNT(CASE WHEN td.status = 'contacted' THEN 1 END) as contacted_domains,
                       COUNT(CASE WHEN td.status = 'pending' THEN 1 END) as pending_domains,
                       COUNT(CASE WHEN td.domain_rating > 30 THEN 1 END) as domains_dr_above_30,
                       COUNT(CASE WHEN td.contact_email IS NOT NULL AND td.contact_email != '' THEN 1 END) as emails_found_count,
                       COUNT(CASE WHEN oe.status = 'sent' THEN 1 END) as emails_sent_count,
                       COUNT(CASE WHEN er.classification_category = 'positive' THEN 1 END) as qualified_leads_count,
                       COUNT(CASE WHEN er.classification_category = 'positive' OR er.classification_category = 'interested' THEN 1 END) as replies_received_count,
                       COALESCE(c.progress_percentage, 
                           CASE 
                               WHEN c.pipeline_status = 'completed' THEN 100
                               WHEN c.pipeline_status = 'monitoring_replies' THEN 90
                               WHEN c.pipeline_status = 'sending_outreach' THEN 75
                               WHEN c.pipeline_status = 'finding_emails' THEN 60
                               WHEN c.pipeline_status = 'analyzing_quality' THEN 45
                               WHEN c.pipeline_status = 'processing_domains' THEN 25
                               WHEN c.pipeline_status = 'created' THEN 10
                               ELSE 5
                           END
                       ) as progress_percentage
                FROM campaigns c 
                LEFT JOIN target_domains td ON c.id = td.campaign_id 
                LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
                LEFT JOIN email_replies er ON oe.id = er.original_email_id
                GROUP BY c.id, c.name, c.competitor_urls, c.owner_email, c.email_template_id, c.status, 
                         c.leads_forwarded, c.is_automated, c.automation_mode, c.auto_send, 
                         c.pipeline_status, c.processing_started_at, c.processing_completed_at, 
                         c.created_at, c.updated_at
                ORDER BY c.created_at DESC";
        
        $campaigns = $this->db->fetchAll($sql);
        
        // Add calculated fields for display
        foreach ($campaigns as &$campaign) {
            // Use actual counts from the query instead of potentially missing column values
            $campaign['total_domains_scraped'] = $campaign['total_domains'];
            $campaign['approved_domains_count'] = $campaign['approved_domains'];
            
            // Progress is now calculated based on pipeline_status in the SQL query
            // Just ensure it's not null and use pipeline_status for accuracy
            if (!isset($campaign['progress_percentage']) || $campaign['progress_percentage'] === null) {
                $campaign['progress_percentage'] = 5; // Default for new campaigns
            }
            
            // Ensure we have default values for any missing fields
            $campaign['replies_received_count'] = $campaign['replies_received_count'] ?? 0;
            $campaign['qualified_leads_count'] = $campaign['qualified_leads_count'] ?? 0;
        }
        
        return $campaigns;
    }
    
    public function getById($id) {
        $sql = "SELECT * FROM campaigns WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }
    
    public function update($id, $name, $competitor_urls, $owner_email, $status, $email_template_id = null) {
        // Check if new columns exist, if not use basic update
        if ($this->columnExists('campaigns', 'owner_email')) {
            $sql = "UPDATE campaigns SET name = ?, competitor_urls = ?, owner_email = ?, status = ?, email_template_id = ? WHERE id = ?";
            return $this->db->execute($sql, [$name, $competitor_urls, $owner_email, $status, $email_template_id, $id]);
        } else {
            $sql = "UPDATE campaigns SET name = ?, competitor_urls = ?, status = ? WHERE id = ?";
            return $this->db->execute($sql, [$name, $competitor_urls, $status, $id]);
        }
    }
    
    private function columnExists($table, $column) {
        try {
            $sql = "SELECT $column FROM $table LIMIT 1";
            $this->db->fetchOne($sql);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function delete($id) {
        try {
            // First check if campaign exists
            $existing = $this->getById($id);
            if (!$existing) {
                throw new Exception("Campaign with ID $id not found");
            }
            
            // Delete the campaign (CASCADE will handle related records)
            $sql = "DELETE FROM campaigns WHERE id = ?";
            $result = $this->db->execute($sql, [$id]);
            
            if (!$result) {
                throw new Exception("Failed to delete campaign with ID $id");
            }
            
            return $result;
        } catch (Exception $e) {
            error_log("Campaign delete error: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function getStats($id) {
        $sql = "SELECT 
                    COUNT(*) as total_domains,
                    COUNT(CASE WHEN status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'contacted' THEN 1 END) as contacted,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
                    AVG(quality_score) as avg_quality_score
                FROM target_domains WHERE campaign_id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }
    
    /**
     * Get detailed automation status for a campaign
     */
    public function getAutomationStatus($campaignId) {
        $sql = "
            SELECT 
                c.*,
                COUNT(td.id) as total_domains,
                COUNT(CASE WHEN td.status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') THEN 1 END) as approved_count,
                COUNT(CASE WHEN td.contact_email IS NOT NULL THEN 1 END) as emails_found,
                COUNT(CASE WHEN oe.id IS NOT NULL THEN 1 END) as emails_sent,
                COUNT(CASE WHEN bj.id IS NOT NULL AND bj.status = 'pending' THEN 1 END) as pending_jobs,
                COUNT(CASE WHEN bj.id IS NOT NULL AND bj.status = 'processing' THEN 1 END) as processing_jobs,
                COUNT(CASE WHEN bj.id IS NOT NULL AND bj.status = 'failed' THEN 1 END) as failed_jobs
            FROM campaigns c
            LEFT JOIN target_domains td ON c.id = td.campaign_id
            LEFT JOIN outreach_emails oe ON c.id = oe.campaign_id
            LEFT JOIN background_jobs bj ON c.id = bj.campaign_id
            WHERE c.id = ?
            GROUP BY c.id
        ";
        
        return $this->db->fetchOne($sql, [$campaignId]);
    }
    
    /**
     * Get pipeline stage status for a campaign
     */
    public function getPipelineStatus($campaignId) {
        $status = $this->getAutomationStatus($campaignId);
        
        if (!$status) {
            return null;
        }
        
        // Calculate stage completion
        $stages = [
            'created' => [
                'name' => 'Campaign Created',
                'status' => 'completed',
                'description' => 'Campaign setup complete'
            ],
            'processing_domains' => [
                'name' => 'Processing Domains',
                'status' => $status['total_domains'] > 0 ? 'completed' : 'pending',
                'description' => "{$status['total_domains']} domains found from competitor backlinks"
            ],
            'analyzing_quality' => [
                'name' => 'Analyzing Quality',
                'status' => $status['approved_count'] > 0 ? 'completed' : 'pending', 
                'description' => "{$status['approved_count']} domains approved for outreach"
            ],
            'finding_emails' => [
                'name' => 'Finding Emails',
                'status' => $status['emails_found'] > 0 ? 'completed' : 'pending',
                'description' => "{$status['emails_found']} contact emails found"
            ],
            'sending_outreach' => [
                'name' => 'Sending Outreach',
                'status' => $status['emails_sent'] > 0 ? 'completed' : 'pending',
                'description' => "{$status['emails_sent']} outreach emails sent"
            ],
            'monitoring_replies' => [
                'name' => 'Monitoring Replies',
                'status' => 'pending',
                'description' => "Monitoring for positive responses"
            ],
            'completed' => [
                'name' => 'Campaign Complete',
                'status' => $status['pipeline_status'] === 'completed' ? 'completed' : 'pending',
                'description' => "All qualified leads forwarded to campaign owner"
            ]
        ];
        
        return [
            'current_stage' => $status['pipeline_status'],
            'progress_percentage' => $this->calculateProgressPercentage($status['pipeline_status']),
            'stages' => $stages,
            'pending_jobs' => $status['pending_jobs'],
            'processing_jobs' => $status['processing_jobs'],
            'failed_jobs' => $status['failed_jobs'],
            'automation_settings' => [
                'auto_domain_analysis' => $status['auto_domain_analysis'] ?? 0,
                'auto_email_search' => $status['auto_email_search'] ?? 0,
                'auto_send' => $status['auto_send'] ?? 0,
                'auto_reply_monitoring' => $status['auto_reply_monitoring'] ?? 0,
                'auto_lead_forwarding' => $status['auto_lead_forwarding'] ?? 0
            ]
        ];
    }
    
    /**
     * Calculate progress percentage based on pipeline status
     */
    private function calculateProgressPercentage($status) {
        $percentages = [
            'created' => 10,
            'processing_domains' => 25,
            'analyzing_quality' => 45,
            'finding_emails' => 60,
            'sending_outreach' => 75,
            'monitoring_replies' => 90,
            'completed' => 100
        ];
        
        return $percentages[$status] ?? 0;
    }
    
    /**
     * Check if campaign name already exists
     */
    private function campaignNameExists($name) {
        $sql = "SELECT COUNT(*) as count FROM campaigns WHERE name = ?";
        $result = $this->db->fetchOne($sql, [$name]);
        return $result['count'] > 0;
    }

    /**
     * Get active automated campaigns for dashboard
     */
    public function getActiveCampaigns() {
        $sql = "
            SELECT 
                c.*,
                COUNT(td.id) as total_domains,
                COUNT(CASE WHEN td.status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') THEN 1 END) as approved_domains,
                COUNT(CASE WHEN td.contact_email IS NOT NULL THEN 1 END) as emails_found_count,
                COUNT(CASE WHEN oe.id IS NOT NULL THEN 1 END) as emails_sent_count,
                c.qualified_leads_count,
                c.replies_received_count,
                CASE 
                    WHEN c.pipeline_status = 'completed' THEN 100
                    WHEN c.pipeline_status = 'monitoring_replies' THEN 90
                    WHEN c.pipeline_status = 'sending_outreach' THEN 75
                    WHEN c.pipeline_status = 'finding_emails' THEN 60
                    WHEN c.pipeline_status = 'analyzing_quality' THEN 45
                    WHEN c.pipeline_status = 'processing_domains' THEN 25
                    ELSE 10
                END as progress_percentage
            FROM campaigns c
            LEFT JOIN target_domains td ON c.id = td.campaign_id
            LEFT JOIN outreach_emails oe ON c.id = oe.campaign_id
            WHERE c.is_automated = 1 
            AND c.pipeline_status != 'completed'
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ";
        
        return $this->db->fetchAll($sql);
    }

    /**
     * Get new automated campaigns that need to be initiated
     */
    public function getNewAutomatedCampaigns() {
        $sql = "
            SELECT * 
            FROM campaigns 
            WHERE is_automated = 1 
            AND pipeline_status = 'created'
            AND processing_started_at IS NULL
            ORDER BY created_at ASC
            LIMIT 5
        ";
        
        return $this->db->fetchAll($sql);
    }
}
?>