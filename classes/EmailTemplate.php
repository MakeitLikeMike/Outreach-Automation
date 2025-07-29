<?php
require_once 'config/database.php';

class EmailTemplate {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    public function create($name, $subject, $body, $is_default = false) {
        if ($is_default) {
            $this->db->execute("UPDATE email_templates SET is_default = 0");
        }
        
        $sql = "INSERT INTO email_templates (name, subject, body, is_default) VALUES (?, ?, ?, ?)";
        $this->db->execute($sql, [$name, $subject, $body, $is_default]);
        return $this->db->lastInsertId();
    }
    
    public function getAll() {
        $sql = "SELECT * FROM email_templates ORDER BY is_default DESC, name ASC";
        return $this->db->fetchAll($sql);
    }
    
    public function getById($id) {
        $sql = "SELECT * FROM email_templates WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }
    
    public function getDefault() {
        $sql = "SELECT * FROM email_templates WHERE is_default = 1 LIMIT 1";
        return $this->db->fetchOne($sql);
    }
    
    public function update($id, $name, $subject, $body, $is_default = false) {
        if ($is_default) {
            $this->db->execute("UPDATE email_templates SET is_default = 0");
        }
        
        $sql = "UPDATE email_templates SET name = ?, subject = ?, body = ?, is_default = ? WHERE id = ?";
        return $this->db->execute($sql, [$name, $subject, $body, $is_default, $id]);
    }
    
    public function delete($id) {
        $sql = "DELETE FROM email_templates WHERE id = ?";
        return $this->db->execute($sql, [$id]);
    }
    
    public function personalizeTemplate($template, $domain, $recipient_email = '', $sender_name = 'Outreach Team') {
        $personalized = $template;
        
        $domain_name = parse_url("https://$domain", PHP_URL_HOST) ?: $domain;
        
        $replacements = [
            '{DOMAIN}' => $domain_name,
            '{DOMAIN_NAME}' => $domain_name,
            '{RECIPIENT_EMAIL}' => $recipient_email,
            '{SENDER_NAME}' => $sender_name,
            '{TOPIC_AREA}' => $this->guessTopicArea($domain),
            '{INDUSTRY}' => 'digital marketing'
        ];
        
        foreach ($replacements as $placeholder => $value) {
            $personalized = str_replace($placeholder, $value, $personalized);
        }
        
        return $personalized;
    }
    
    private function guessTopicArea($domain) {
        $keywords = ['technology', 'marketing', 'business', 'lifestyle', 'health', 'finance'];
        return $keywords[array_rand($keywords)];
    }
}
?>