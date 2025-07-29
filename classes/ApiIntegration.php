<?php
require_once 'config/database.php';

class ApiIntegration {
    private $db;
    private $settings;
    
    public function __construct() {
        $this->db = new Database();
        $this->loadSettings();
    }
    
    private function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    public function fetchBacklinks($competitor_url) {
        $api_key = $this->settings['dataforseo_api_key'] ?? '';
        if (empty($api_key)) {
            throw new Exception('DataForSEO API key not configured');
        }
        
        $endpoint = 'https://api.dataforseo.com/v3/backlinks/backlinks/live';
        
        $data = [
            [
                'target' => $competitor_url,
                'limit' => 1000,
                'order_by' => ['referring_domains,desc']
            ]
        ];
        
        $response = $this->makeApiCall('dataforseo', $endpoint, $data, [
            'Authorization: Basic ' . base64_encode($api_key)
        ]);
        
        if ($response && isset($response['tasks'][0]['result'])) {
            return $this->processBacklinksResponse($response['tasks'][0]['result']);
        }
        
        return [];
    }
    
    public function getDomainMetrics($domain) {
        $api_key = $this->settings['dataforseo_api_key'] ?? '';
        if (empty($api_key)) {
            throw new Exception('DataForSEO API key not configured');
        }
        
        $endpoint = 'https://api.dataforseo.com/v3/domain_analytics/overview/live';
        
        $data = [
            [
                'target' => $domain,
                'location_code' => 2840,
                'language_code' => 'en'
            ]
        ];
        
        $response = $this->makeApiCall('dataforseo', $endpoint, $data, [
            'Authorization: Basic ' . base64_encode($api_key)
        ]);
        
        if ($response && isset($response['tasks'][0]['result'][0])) {
            return $this->processDomainMetrics($response['tasks'][0]['result'][0]);
        }
        
        return [];
    }
    
    public function findEmail($domain) {
        $api_key = $this->settings['tomba_api_key'] ?? '';
        if (empty($api_key)) {
            throw new Exception('Tomba.io API key not configured');
        }
        
        $endpoint = "https://api.tomba.io/v1/domain-search?domain=$domain";
        
        $response = $this->makeApiCall('tomba', $endpoint, null, [
            'X-Tomba-Key: ' . $api_key,
            'X-Tomba-Secret: ' . ($this->settings['tomba_secret'] ?? '')
        ]);
        
        if ($response && isset($response['data']['emails'][0])) {
            return $response['data']['emails'][0]['email'];
        }
        
        return null;
    }
    
    public function sendEmail($to, $subject, $body, $from = null) {
        $api_key = $this->settings['mailgun_api_key'] ?? '';
        $domain = $this->settings['mailgun_domain'] ?? '';
        
        if (empty($api_key) || empty($domain)) {
            throw new Exception('Mailgun configuration not complete');
        }
        
        $from = $from ?: $this->settings['sender_email'] ?? "outreach@$domain";
        
        $endpoint = "https://api.mailgun.net/v3/$domain/messages";
        
        $data = [
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'text' => $body
        ];
        
        $response = $this->makeApiCall('mailgun', $endpoint, $data, [
            'Authorization: Basic ' . base64_encode("api:$api_key")
        ], 'POST');
        
        return $response !== false;
    }
    
    private function makeApiCall($service, $endpoint, $data = null, $headers = [], $method = 'POST') {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'User-Agent: Outreach-Automation/1.0'
            ], $headers)
        ]);
        
        if ($method === 'POST' && $data) {
            if ($service === 'mailgun') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
                    'Content-Type: application/x-www-form-urlencoded'
                ], $headers));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->logApiCall($service, $endpoint, $data, ['error' => $error], 0);
            throw new Exception("API call failed: $error");
        }
        
        $response_data = json_decode($response, true);
        $this->logApiCall($service, $endpoint, $data, $response_data, $status_code);
        
        if ($status_code >= 200 && $status_code < 300) {
            return $response_data;
        }
        
        return false;
    }
    
    private function logApiCall($service, $endpoint, $request, $response, $status_code) {
        $sql = "INSERT INTO api_logs (api_service, endpoint, request_data, response_data, status_code) 
                VALUES (?, ?, ?, ?, ?)";
        
        $this->db->execute($sql, [
            $service,
            $endpoint,
            json_encode($request),
            json_encode($response),
            $status_code
        ]);
    }
    
    private function processBacklinksResponse($result) {
        $domains = [];
        
        if (isset($result['items'])) {
            foreach ($result['items'] as $item) {
                $domain = parse_url($item['url'], PHP_URL_HOST);
                if ($domain && !in_array($domain, $domains)) {
                    $domains[] = $domain;
                }
            }
        }
        
        return array_unique($domains);
    }
    
    private function processDomainMetrics($result) {
        return [
            'organic_traffic' => $result['organic_etv'] ?? 0,
            'referring_domains' => $result['backlinks_info']['referring_domains'] ?? 0,
            'domain_rating' => $result['domain_rank'] ?? 0,
            'ranking_keywords' => $result['organic_keywords'] ?? 0
        ];
    }
}
?>