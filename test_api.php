<?php
header('Content-Type: application/json');

require_once 'classes/ApiIntegration.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $service = $input['service'] ?? '';
    
    $api = new ApiIntegration();
    $success = false;
    $message = '';
    
    switch (strtolower($service)) {
        case 'dataforseo':
            try {
                // Test with a simple backlink check
                $domains = $api->fetchBacklinks('https://example.com');
                if (is_array($domains)) {
                    $success = true;
                    $message = 'Connected successfully! Found ' . count($domains) . ' domains.';
                } else {
                    $message = 'API connected but returned no data.';
                }
            } catch (Exception $e) {
                $message = $e->getMessage();
            }
            break;
            
        case 'tomba':
            try {
                // Test with a simple email search
                $email = $api->findEmail('example.com');
                $success = true;
                $message = $email ? 'Connected! Found email: ' . $email : 'Connected but no email found for test domain.';
            } catch (Exception $e) {
                $message = $e->getMessage();
            }
            break;
            
        case 'gmail':
            // Check if Gmail credentials are set
            $db = new Database();
            $credentials = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'gmail_credentials'");
            
            if (!empty($credentials['setting_value'])) {
                // Try to validate JSON format
                $json = json_decode($credentials['setting_value'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $success = true;
                    $message = 'Gmail credentials are properly formatted JSON.';
                } else {
                    $message = 'Gmail credentials are not valid JSON format.';
                }
            } else {
                $message = 'Gmail credentials not configured.';
            }
            break;
            
        default:
            $message = 'Unknown service: ' . $service;
    }
    
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>