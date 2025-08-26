<?php
require_once __DIR__ . '/../config/database.php';

class TargetDomain {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function create($campaign_id, $domain, $metrics = []) {
        $sql = "INSERT INTO target_domains (campaign_id, domain, referring_domains, organic_traffic, domain_rating, ranking_keywords, quality_score, contact_email, backlinks_total, referring_pages, referring_main_domains, domain_authority_rank, backlink_analysis_type) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $campaign_id,
            $domain,
            $metrics['referring_domains'] ?? 0,
            $metrics['organic_traffic'] ?? 0,
            $metrics['domain_rating'] ?? 0,
            $metrics['ranking_keywords'] ?? 0,
            $metrics['quality_score'] ?? 0.00,
            $metrics['contact_email'] ?? null,
            $metrics['backlinks_total'] ?? 0,
            $metrics['referring_pages'] ?? 0,
            $metrics['referring_main_domains'] ?? 0,
            $metrics['domain_authority_rank'] ?? 0,
            $metrics['backlink_analysis_type'] ?? 'unknown'
        ];
        
        $this->db->execute($sql, $params);
        return $this->db->lastInsertId();
    }
    
    public function getByCampaign($campaign_id, $status = null, $exclude_rejected = true) {
        $sql = "SELECT * FROM target_domains WHERE campaign_id = ?";
        $params = [$campaign_id];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        } elseif ($exclude_rejected) {
            // By default, exclude rejected domains from the list
            $sql .= " AND status != 'rejected'";
        }
        
        $sql .= " ORDER BY quality_score DESC, domain_rating DESC";
        return $this->db->fetchAll($sql, $params);
    }
    
    public function updateStatus($id, $status) {
        $sql = "UPDATE target_domains SET status = ?, updated_at = NOW() WHERE id = ?";
        $result = $this->db->execute($sql, [$status, $id]);
        
        // Trigger next automation step based on status
        if ($result) {
            $this->triggerAutomationStep($id, $status);
        }
        
        return $result;
    }
    
    /**
     * Trigger the next automation step based on status change
     */
    private function triggerAutomationStep($domainId, $newStatus) {
        try {
            switch ($newStatus) {
                case 'approved':
                    // Immediately trigger email search
                    $this->logInfo("Domain $domainId approved, triggering email search");
                    $this->startEmailSearch($domainId, 'high');
                    break;
                    
                case 'searching_email':
                    // Check if email search should continue or has completed
                    $this->logInfo("Domain $domainId searching email");
                    break;
                    
                case 'generating_email':
                    // Could trigger immediate email generation here
                    $this->logInfo("Domain $domainId ready for email generation");
                    break;
                    
                case 'sending_email':
                    // Could trigger immediate email sending here
                    $this->logInfo("Domain $domainId ready for email sending");
                    break;
                    
                case 'monitoring_replies':
                    $this->logInfo("Domain $domainId now monitoring for replies");
                    break;
                    
                case 'contacted':
                    $this->logInfo("Domain $domainId successfully contacted");
                    break;
                    
                case 'rejected':
                    $this->logInfo("Domain $domainId rejected");
                    break;
            }
        } catch (Exception $e) {
            $this->logError("Failed to trigger automation step for domain $domainId: " . $e->getMessage());
        }
    }
    
    public function updateMetrics($id, $metrics) {
        $sql = "UPDATE target_domains SET 
                referring_domains = ?, 
                organic_traffic = ?, 
                domain_rating = ?, 
                ranking_keywords = ?, 
                quality_score = ?,
                contact_email = ?,
                backlinks_total = ?,
                referring_pages = ?,
                referring_main_domains = ?,
                domain_authority_rank = ?,
                backlink_analysis_type = ?,
                backlink_last_updated = NOW()
                WHERE id = ?";
        
        $params = [
            $metrics['referring_domains'] ?? 0,
            $metrics['organic_traffic'] ?? 0,
            $metrics['domain_rating'] ?? 0,
            $metrics['ranking_keywords'] ?? 0,
            $metrics['quality_score'] ?? 0.00,
            $metrics['contact_email'] ?? null,
            $metrics['backlinks_total'] ?? 0,
            $metrics['referring_pages'] ?? 0,
            $metrics['referring_main_domains'] ?? 0,
            $metrics['domain_authority_rank'] ?? 0,
            $metrics['backlink_analysis_type'] ?? 'unknown',
            $id
        ];
        
        return $this->db->execute($sql, $params);
    }
    
    public function getById($id) {
        $sql = "SELECT td.*, c.name as campaign_name 
                FROM target_domains td 
                JOIN campaigns c ON td.campaign_id = c.id 
                WHERE td.id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }
    
    public function delete($id) {
        $sql = "DELETE FROM target_domains WHERE id = ?";
        return $this->db->execute($sql, [$id]);
    }
    
    public function getQualityFiltered($min_dr = 30, $min_traffic = 1000, $min_domains = 50) {
        $sql = "SELECT td.*, c.name as campaign_name 
                FROM target_domains td 
                JOIN campaigns c ON td.campaign_id = c.id 
                WHERE td.domain_rating >= ? 
                AND td.organic_traffic >= ? 
                AND td.referring_domains >= ?
                AND td.status != 'rejected'
                ORDER BY td.quality_score DESC, td.domain_rating DESC";
        
        return $this->db->fetchAll($sql, [$min_dr, $min_traffic, $min_domains]);
    }
    
    public function domainExists($campaign_id, $domain) {
        $sql = "SELECT COUNT(*) as count FROM target_domains 
                WHERE campaign_id = ? AND domain = ?";
        $result = $this->db->fetchOne($sql, [$campaign_id, strtolower($domain)]);
        return $result['count'] > 0;
    }
    
    /**
     * Start email search for a domain (immediate trigger)
     */
    public function startEmailSearch($domainId, $priority = 'high') {
        try {
            require_once 'EmailSearchService.php';
            $emailService = new EmailSearchService();
            
            $this->logInfo("Starting email search for domain ID: $domainId");
            
            // Trigger immediate email search
            $result = $emailService->searchEmailImmediate($domainId, $priority);
            
            return $result;
            
        } catch (Exception $e) {
            $this->logError("Failed to start email search for domain $domainId: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update domain status and trigger email search if approved
     */
    public function updateStatusWithEmailSearch($id, $status) {
        try {
            // Update the status first
            $result = $this->updateStatus($id, $status);
            
            if ($result && $status === 'approved') {
                $this->logInfo("Domain $id approved, triggering email search");
                
                // Trigger email search for approved domains
                $emailSearchResult = $this->startEmailSearch($id, 'high');
                
                return [
                    'success' => true,
                    'status_updated' => true,
                    'email_search' => $emailSearchResult
                ];
            }
            
            return [
                'success' => $result,
                'status_updated' => $result,
                'email_search' => null
            ];
            
        } catch (Exception $e) {
            $this->logError("Failed to update status with email search for domain $id: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get domains needing email search
     */
    public function getDomainsNeedingEmailSearch($limit = 10) {
        $sql = "SELECT td.*, c.name as campaign_name 
                FROM target_domains td 
                JOIN campaigns c ON td.campaign_id = c.id 
                WHERE (
                    (td.status = 'approved' AND td.email_search_status = 'pending') OR
                    (td.email_search_status = 'failed' AND td.email_search_attempts < 3) OR
                    (td.email_search_status = 'searching' AND td.last_email_search_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
                )
                AND (td.next_retry_at IS NULL OR td.next_retry_at <= NOW())
                ORDER BY 
                    CASE td.email_search_status 
                        WHEN 'pending' THEN 1 
                        WHEN 'failed' THEN 2 
                        WHEN 'searching' THEN 3 
                    END,
                    td.quality_score DESC,
                    td.created_at ASC
                LIMIT ?";
        
        return $this->db->fetchAll($sql, [$limit]);
    }
    
    /**
     * Get domains by email search status
     */
    public function getDomainsByEmailSearchStatus() {
        $sql = "SELECT 
                    email_search_status,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM target_domains), 2) as percentage
                FROM target_domains 
                GROUP BY email_search_status
                ORDER BY count DESC";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Update AI analysis data for a domain
     */
    public function updateAIAnalysis($id, $analysisData) {
        $setParts = [];
        $params = [];
        
        foreach ($analysisData as $field => $value) {
            $setParts[] = "$field = ?";
            $params[] = $value;
        }
        
        $params[] = $id;
        
        $sql = "UPDATE target_domains SET " . implode(', ', $setParts) . " WHERE id = ?";
        
        return $this->db->execute($sql, $params);
    }
    
    /**
     * Get domains with found emails ready for outreach
     */
    public function getDomainsReadyForOutreach($campaignId = null) {
        $sql = "SELECT td.*, c.name as campaign_name 
                FROM target_domains td 
                JOIN campaigns c ON td.campaign_id = c.id 
                WHERE td.status = 'approved' 
                    AND td.email_search_status = 'found' 
                    AND td.contact_email IS NOT NULL 
                    AND td.contact_email != ''
                    AND td.quality_score >= 70";
        
        $params = [];
        
        if ($campaignId) {
            $sql .= " AND td.campaign_id = ?";
            $params[] = $campaignId;
        }
        
        $sql .= " ORDER BY td.quality_score DESC, td.domain_rating DESC";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Update email search status
     */
    public function updateEmailSearchStatus($id, $status, $error = null) {
        $sql = "UPDATE target_domains SET 
                email_search_status = ?,
                last_email_search_at = NOW(),
                email_search_error = ?
                WHERE id = ?";
        
        return $this->db->execute($sql, [$status, $error, $id]);
    }
    
    /**
     * Update domain with found email
     */
    public function updateWithFoundEmail($id, $email) {
        $sql = "UPDATE target_domains SET 
                contact_email = ?,
                email_search_status = 'found',
                email_search_attempts = email_search_attempts + 1,
                last_email_search_at = NOW(),
                email_search_error = NULL,
                next_retry_at = NULL
                WHERE id = ?";
        
        return $this->db->execute($sql, [$email, $id]);
    }
    
    /**
     * Manual retry email search for failed domains
     */
    public function retryEmailSearch($id, $force = false) {
        try {
            $domain = $this->getById($id);
            
            if (!$domain) {
                throw new Exception("Domain not found");
            }
            
            if (!$force && $domain['email_search_attempts'] >= 3) {
                throw new Exception("Domain has exceeded maximum retry attempts");
            }
            
            $this->logInfo("Manual retry email search for domain: {$domain['domain']}");
            return $this->startEmailSearch($id, 'high');
            
        } catch (Exception $e) {
            $this->logError("Failed to retry email search for domain $id: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get email search statistics for domains
     */
    public function getEmailSearchStatistics() {
        $sql = "SELECT 
                    COUNT(*) as total_domains,
                    SUM(CASE WHEN email_search_status = 'found' THEN 1 ELSE 0 END) as emails_found,
                    SUM(CASE WHEN email_search_status = 'pending' THEN 1 ELSE 0 END) as pending_search,
                    SUM(CASE WHEN email_search_status = 'failed' THEN 1 ELSE 0 END) as failed_search,
                    SUM(CASE WHEN email_search_status = 'searching' THEN 1 ELSE 0 END) as currently_searching,
                    ROUND(AVG(CASE WHEN email_search_status = 'found' THEN 1 ELSE 0 END) * 100, 2) as success_rate
                FROM target_domains 
                WHERE status = 'approved'";
        
        return $this->db->fetchOne($sql);
    }
    
    // Logging methods
    private function logInfo($message) {
        error_log("[TargetDomain][INFO] " . $message);
    }
    
    private function logError($message) {
        error_log("[TargetDomain][ERROR] " . $message);
    }
}
?>