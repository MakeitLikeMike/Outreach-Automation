<?php
require_once __DIR__ . '/../config/database.php';

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
        $domain_name_clean = str_replace('www.', '', $domain_name);
        
        // Extract recipient name from email if possible
        $recipient_name = $this->extractNameFromEmail($recipient_email);
        
        $replacements = [
            '{DOMAIN}' => $domain_name,
            '{DOMAIN_NAME}' => $domain_name,
            '{DOMAIN_CLEAN}' => $domain_name_clean,
            '{RECIPIENT_EMAIL}' => $recipient_email,
            '{RECIPIENT_NAME}' => $recipient_name,
            '{SENDER_NAME}' => $sender_name,
            '{TOPIC_AREA}' => $this->guessTopicArea($domain),
            '{INDUSTRY}' => 'iGaming and casino',
            '{NICHE}' => 'casino gaming',
            '{CURRENT_DATE}' => date('F j, Y'),
            '{CURRENT_MONTH}' => date('F'),
            '{CURRENT_YEAR}' => date('Y'),
            '{WEBSITE_TYPE}' => $this->guessWebsiteType($domain),
            '{CONTENT_FOCUS}' => $this->guessCasinoFocus($domain),
            '{GREETING}' => $this->getPersonalizedGreeting($recipient_name),
            '{SIGNATURE}' => $this->getPersonalizedSignature($sender_name)
        ];
        
        foreach ($replacements as $placeholder => $value) {
            $personalized = str_replace($placeholder, $value, $personalized);
        }
        
        return $personalized;
    }
    
    private function extractNameFromEmail($email) {
        if (empty($email)) {
            return 'there';
        }
        
        // Extract the part before @ symbol
        $username = explode('@', $email)[0];
        
        // Common patterns to extract names
        if (strpos($username, '.') !== false) {
            $parts = explode('.', $username);
            $firstName = ucfirst($parts[0]);
            return $firstName;
        }
        
        if (strpos($username, '_') !== false) {
            $parts = explode('_', $username);
            $firstName = ucfirst($parts[0]);
            return $firstName;
        }
        
        // If no separators, use first part of username
        if (strlen($username) > 3) {
            return ucfirst($username);
        }
        
        return 'there';
    }
    
    private function guessTopicArea($domain) {
        $casinoKeywords = ['casino', 'gaming', 'bet', 'poker', 'slots', 'igaming', 'gambling'];
        $domain_lower = strtolower($domain);
        
        foreach ($casinoKeywords as $keyword) {
            if (strpos($domain_lower, $keyword) !== false) {
                return 'casino gaming';
            }
        }
        
        $keywords = ['technology', 'digital marketing', 'business', 'lifestyle', 'entertainment', 'finance'];
        return $keywords[array_rand($keywords)];
    }
    
    private function guessWebsiteType($domain) {
        $domain_lower = strtolower($domain);
        
        if (strpos($domain_lower, 'casino') !== false) return 'casino website';
        if (strpos($domain_lower, 'gaming') !== false) return 'gaming platform';
        if (strpos($domain_lower, 'bet') !== false) return 'betting site';
        if (strpos($domain_lower, 'poker') !== false) return 'poker site';
        if (strpos($domain_lower, 'slots') !== false) return 'slots website';
        if (strpos($domain_lower, 'blog') !== false) return 'blog';
        if (strpos($domain_lower, 'news') !== false) return 'news site';
        if (strpos($domain_lower, 'review') !== false) return 'review website';
        
        return 'website';
    }
    
    private function guessCasinoFocus($domain) {
        $domain_lower = strtolower($domain);
        
        if (strpos($domain_lower, 'slots') !== false) return 'slot games and casino entertainment';
        if (strpos($domain_lower, 'poker') !== false) return 'poker strategy and gameplay';
        if (strpos($domain_lower, 'blackjack') !== false) return 'blackjack and table games';
        if (strpos($domain_lower, 'roulette') !== false) return 'roulette and casino classics';
        if (strpos($domain_lower, 'bet') !== false) return 'betting strategies and tips';
        if (strpos($domain_lower, 'review') !== false) return 'casino reviews and recommendations';
        if (strpos($domain_lower, 'bonus') !== false) return 'casino bonuses and promotions';
        
        return 'casino gaming and entertainment';
    }
    
    private function getPersonalizedGreeting($recipient_name) {
        if ($recipient_name === 'there') {
            return 'Hello';
        }
        
        $greetings = [
            "Hi $recipient_name",
            "Hello $recipient_name",
            "Hey $recipient_name"
        ];
        
        return $greetings[array_rand($greetings)];
    }
    
    private function getPersonalizedSignature($sender_name) {
        $signatures = [
            "Best regards,\n$sender_name",
            "Kind regards,\n$sender_name",
            "Best,\n$sender_name",
            "Cheers,\n$sender_name"
        ];
        
        return $signatures[array_rand($signatures)];
    }
    
    public function previewTemplate($templateId, $sampleDomain = 'example-casino.com', $sampleEmail = 'john.doe@example.com') {
        $template = $this->getById($templateId);
        if (!$template) {
            throw new Exception("Template not found");
        }
        
        return [
            'subject' => $this->personalizeTemplate($template['subject'], $sampleDomain, $sampleEmail),
            'body' => $this->personalizeTemplate($template['body'], $sampleDomain, $sampleEmail),
            'original_subject' => $template['subject'],
            'original_body' => $template['body']
        ];
    }
}
?>