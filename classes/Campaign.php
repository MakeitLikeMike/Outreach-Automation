<?php
require_once 'config/database.php';

class Campaign {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function create($name, $competitor_urls, $owner_email = null) {
        $sql = "INSERT INTO campaigns (name, competitor_urls, owner_email) VALUES (?, ?, ?)";
        $this->db->execute($sql, [$name, $competitor_urls, $owner_email]);
        return $this->db->lastInsertId();
    }
    
    public function getAll() {
        $sql = "SELECT c.*, 
                       COUNT(td.id) as total_domains,
                       COUNT(CASE WHEN td.status = 'approved' THEN 1 END) as approved_domains,
                       COUNT(CASE WHEN td.status = 'contacted' THEN 1 END) as contacted_domains,
                       0 as forwarded_emails
                FROM campaigns c 
                LEFT JOIN target_domains td ON c.id = td.campaign_id 
                GROUP BY c.id 
                ORDER BY c.created_at DESC";
        
        $result = $this->db->fetchAll($sql);
        
        // Debug: Log the query and results
        error_log("Campaign query: " . $sql);
        error_log("Campaign results: " . print_r($result, true));
        
        return $result;
    }
    
    public function getById($id) {
        $sql = "SELECT * FROM campaigns WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }
    
    public function update($id, $name, $competitor_urls, $owner_email, $status) {
        $sql = "UPDATE campaigns SET name = ?, competitor_urls = ?, owner_email = ?, status = ? WHERE id = ?";
        return $this->db->execute($sql, [$name, $competitor_urls, $owner_email, $status, $id]);
    }
    
    public function delete($id) {
        $sql = "DELETE FROM campaigns WHERE id = ?";
        return $this->db->execute($sql, [$id]);
    }
    
    public function getStats($id) {
        $sql = "SELECT 
                    COUNT(*) as total_domains,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'contacted' THEN 1 END) as contacted,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
                    AVG(quality_score) as avg_quality_score
                FROM target_domains WHERE campaign_id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }
}
?>