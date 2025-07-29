<?php
require_once 'config/database.php';

class TargetDomain {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function create($campaign_id, $domain, $metrics = []) {
        $sql = "INSERT INTO target_domains (campaign_id, domain, referring_domains, organic_traffic, domain_rating, ranking_keywords, quality_score, contact_email) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $campaign_id,
            $domain,
            $metrics['referring_domains'] ?? 0,
            $metrics['organic_traffic'] ?? 0,
            $metrics['domain_rating'] ?? 0,
            $metrics['ranking_keywords'] ?? 0,
            $metrics['quality_score'] ?? 0.00,
            $metrics['contact_email'] ?? null
        ];
        
        $this->db->execute($sql, $params);
        return $this->db->lastInsertId();
    }
    
    public function getByCampaign($campaign_id, $status = null) {
        $sql = "SELECT * FROM target_domains WHERE campaign_id = ?";
        $params = [$campaign_id];
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY quality_score DESC, domain_rating DESC";
        return $this->db->fetchAll($sql, $params);
    }
    
    public function updateStatus($id, $status) {
        $sql = "UPDATE target_domains SET status = ? WHERE id = ?";
        return $this->db->execute($sql, [$status, $id]);
    }
    
    public function updateMetrics($id, $metrics) {
        $sql = "UPDATE target_domains SET 
                referring_domains = ?, 
                organic_traffic = ?, 
                domain_rating = ?, 
                ranking_keywords = ?, 
                quality_score = ?,
                contact_email = ?
                WHERE id = ?";
        
        $params = [
            $metrics['referring_domains'] ?? 0,
            $metrics['organic_traffic'] ?? 0,
            $metrics['domain_rating'] ?? 0,
            $metrics['ranking_keywords'] ?? 0,
            $metrics['quality_score'] ?? 0.00,
            $metrics['contact_email'] ?? null,
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
    
    public function getQualityFiltered($min_dr = 30, $min_traffic = 5000, $min_domains = 100) {
        $sql = "SELECT td.*, c.name as campaign_name 
                FROM target_domains td 
                JOIN campaigns c ON td.campaign_id = c.id 
                WHERE td.domain_rating >= ? 
                AND td.organic_traffic >= ? 
                AND td.referring_domains >= ?
                AND td.status = 'pending'
                ORDER BY td.quality_score DESC";
        
        return $this->db->fetchAll($sql, [$min_dr, $min_traffic, $min_domains]);
    }
}
?>