<?php
/**
 * GMass Integration - Email Sending via GMass SMTP
 * Supports both individual SMTP sending and bulk campaigns
 * Uses GMass SMTP server with PHPMailer authentication
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class GMassIntegration {
    private $db;
    private $settings;
    private $apiKey;
    private $smtpHost = 'smtp.gmass.co';
    private $smtpUsername = 'gmass';
    private $smtpPassword;
    private $smtpPort = 587;
    private $baseUrl = 'https://api.gmass.co/api';
    private $logFile;
    
    // GMass API endpoints (for bulk campaigns)
    const ENDPOINT_CAMPAIGNS = '/campaigns';
    const ENDPOINT_SHEETS = '/sheets';
    const ENDPOINT_REPORTS = '/reports';
    
    // Email sending modes
    const MODE_SMTP = 'smtp';
    const MODE_CAMPAIGN = 'campaign';
    
    public function __construct() {
        $this->db = new Database();
        $this->loadSettings();
        $this->apiKey = $this->settings['gmass_api_key'] ?? '';
        $this->smtpPassword = $this->settings['gmass_smtp_password'] ?? '';
        $this->logFile = __DIR__ . '/../logs/gmass_integration.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    private function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    /**
     * Send individual email via GMass SMTP
     * Direct replacement for GmailIntegration::sendEmail()
     */
    public function sendEmail($from, $to, $subject, $body, $options = []) {
        $startTime = microtime(true);
        
        try {
            // Validate inputs
            $this->validateEmailInputs($from, $to, $subject, $body);
            
            // Check SMTP configuration
            if (empty($this->smtpPassword)) {
                throw new Exception('GMass SMTP password not configured');
            }
            
            // Send via SMTP
            $messageId = $this->sendViaSMTP($from, $to, $subject, $body, $options);
            
            $processingTime = round((microtime(true) - $startTime) * 1000);
            $this->logEmailSend($from, $to, $subject, $messageId, true, null, $processingTime);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'processing_time_ms' => $processingTime
            ];
            
        } catch (Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000);
            $this->logEmailSend($from, $to, $subject, null, false, $e->getMessage(), $processingTime);
            
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * Send email via SMTP using PHPMailer
     */
    private function sendViaSMTP($from, $to, $subject, $body, $options = []) {
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUsername;
            $mail->Password = $this->smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            
            // Disable recipient verification - GMass specific settings
            $mail->SMTPKeepAlive = true;
            $mail->do_verp = false;
            
            // Add custom SMTP command to disable verification
            $mail->addCustomHeader('X-GMass-Skip-Verification', 'true');
            
            // Recipients  
            $mail->setFrom($from);
            $mail->addAddress($to);
            
            // Set Reply-To if specified in options
            if (!empty($options['reply_to'])) {
                $mail->addReplyTo($options['reply_to']);
            }
            
            // Add CC/BCC if specified
            if (!empty($options['cc'])) {
                $ccEmails = is_array($options['cc']) ? $options['cc'] : [$options['cc']];
                foreach ($ccEmails as $ccEmail) {
                    $mail->addCC($ccEmail);
                }
            }
            if (!empty($options['bcc'])) {
                $bccEmails = is_array($options['bcc']) ? $options['bcc'] : [$options['bcc']];
                foreach ($bccEmails as $bccEmail) {
                    $mail->addBCC($bccEmail);
                }
            }
            
            // Content - fix encoding issues
            $mail->isHTML(($options['format'] ?? 'html') === 'html');
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8');
            $mail->Body = $body;
            $mail->CharSet = 'UTF-8';
            
            // Send
            $mail->send();
            
            return $mail->getLastMessageID() ?: ('<' . uniqid() . '@gmass.co>');
            
        } catch (PHPMailerException $e) {
            $errorInfo = $mail->ErrorInfo;
            
            // Check for specific error patterns and provide user-friendly messages
            if (strpos($errorInfo, 'unsubscribe or bounce list') !== false) {
                throw new Exception('Email skipped - this address previously bounced');
            } elseif (strpos($errorInfo, 'address failed verification') !== false) {
                throw new Exception('Email skipped - invalid email address detected');
            } elseif (strpos($errorInfo, 'DATA END command failed') !== false && strpos($errorInfo, '554') !== false) {
                throw new Exception('Email skipped - this address previously bounced');
            } elseif (strpos($errorInfo, 'SMTP Error') !== false && strpos($errorInfo, 'recipients failed') !== false) {
                throw new Exception('Email skipped - address validation failed');
            } else {
                throw new Exception('Email delivery failed - please try again');
            }
        }
    }
    
    /**
     * Send bulk campaign using PHPMailer for better SMTP control
     */
    public function sendBulkCampaign($recipients, $subject, $body, $fromEmail, $options = []) {
        $results = [];
        $successCount = 0;
        $failCount = 0;
        
        foreach ($recipients as $recipient) {
            try {
                $messageId = $this->sendViaSMTP($fromEmail, $recipient, $subject, $body, $options);
                $results[] = [
                    'email' => $recipient,
                    'success' => true,
                    'message_id' => $messageId
                ];
                $successCount++;
                
                // Add delay between emails to avoid rate limiting
                usleep(100000); // 0.1 second delay
                
            } catch (Exception $e) {
                $results[] = [
                    'email' => $recipient,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $failCount++;
            }
        }
        
        return [
            'success' => $successCount > 0,
            'total_sent' => $successCount,
            'total_failed' => $failCount,
            'details' => $results
        ];
    }
    
    /**
     * Send bulk campaign via GMass API (for larger volumes)
     */
    public function sendCampaign($campaignData) {
        $this->log("📧 Starting GMass campaign send");
        
        try {
            $response = $this->makeApiRequest('POST', self::ENDPOINT_CAMPAIGNS, $campaignData);
            
            if ($response['success']) {
                // Store campaign tracking info
                $this->storeCampaignTracking($campaignData, $response['data']);
                
                $this->log("✅ GMass campaign created successfully - ID: " . ($response['data']['id'] ?? 'unknown'));
                
                return [
                    'success' => true,
                    'gmass_campaign_id' => $response['data']['id'] ?? null,
                    'status' => $response['data']['status'] ?? 'created',
                    'recipients' => $response['data']['recipient_count'] ?? 0
                ];
            } else {
                throw new Exception($response['error'] ?? 'Campaign creation failed');
            }
            
        } catch (Exception $e) {
            $this->log("❌ GMass campaign failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Test SMTP connection
     */
    public function testConnection() {
        if (empty($this->smtpPassword)) {
            return [
                'success' => false,
                'error' => 'GMass SMTP password not configured'
            ];
        }
        
        try {
            $mail = new PHPMailer();
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUsername;
            $mail->Password = $this->smtpPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtpPort;
            
            // Test connection without sending
            if (!$mail->smtpConnect()) {
                throw new Exception('SMTP connection failed');
            }
            
            $mail->smtpClose();
            
            return [
                'success' => true,
                'message' => 'GMass SMTP connection successful',
                'server' => $this->smtpHost . ':' . $this->smtpPort
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get campaign reports and analytics
     */
    public function getCampaignReports($gmassId, $reportType = 'all') {
        $reports = [];
        $reportTypes = ['recipients', 'opens', 'clicks', 'bounces', 'unsubscribes'];
        
        if ($reportType === 'all') {
            $types = $reportTypes;
        } else {
            $types = [$reportType];
        }
        
        foreach ($types as $type) {
            try {
                $endpoint = self::ENDPOINT_REPORTS . "/{$gmassId}/{$type}";
                $response = $this->makeApiRequest('GET', $endpoint);
                
                if ($response['success']) {
                    $reports[$type] = $response['data'];
                }
            } catch (Exception $e) {
                $this->log("⚠️ Failed to get {$type} report for campaign {$gmassId}: " . $e->getMessage());
                $reports[$type] = null;
            }
        }
        
        return $reports;
    }
    
    /**
     * Make API request to GMass (for bulk campaigns)
     */
    private function makeApiRequest($method, $endpoint, $data = null) {
        if (empty($this->apiKey)) {
            throw new Exception('GMass API key not configured');
        }
        
        $url = $this->baseUrl . $endpoint;
        
        // Add API key to query string
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= $separator . 'apikey=' . urlencode($this->apiKey);
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'AutooutreachSystem/1.0'
        ]);
        
        // Set method and data
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'X-apikey: ' . $this->apiKey
                ]);
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'X-apikey: ' . $this->apiKey
                ]);
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception("CURL Error: " . $error);
        }
        
        if ($httpCode !== 200) {
            $errorMsg = "HTTP {$httpCode}";
            if ($response) {
                $decoded = json_decode($response, true);
                if ($decoded && isset($decoded['error'])) {
                    $errorMsg .= ": " . $decoded['error'];
                }
            }
            throw new Exception($errorMsg);
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new Exception("Invalid JSON response from GMass API");
        }
        
        return [
            'success' => true,
            'data' => $decoded,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * Validate email inputs
     */
    private function validateEmailInputs($from, $to, $subject, $body) {
        if (empty($from) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid sender email: {$from}");
        }
        
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid recipient email: {$to}");
        }
        
        if (empty($subject)) {
            throw new Exception("Email subject cannot be empty");
        }
        
        if (empty($body)) {
            throw new Exception("Email body cannot be empty");
        }
    }
    
    /**
     * Store campaign tracking information
     */
    private function storeCampaignTracking($campaignData, $gmassResponse) {
        $sql = "INSERT INTO gmass_campaigns (local_campaign_id, gmass_campaign_id, status, total_recipients) VALUES (?, ?, ?, ?)";
        
        $this->db->execute($sql, [
            $campaignData['local_campaign_id'] ?? null,
            $gmassResponse['id'] ?? null,
            $gmassResponse['status'] ?? 'created',
            $gmassResponse['recipient_count'] ?? 0
        ]);
    }
    
    /**
     * Log email send attempt
     */
    private function logEmailSend($from, $to, $subject, $messageId, $success, $error, $processingTime, $errorType = null, $errorCode = null) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'message_id' => $messageId,
            'success' => $success ? 'true' : 'false',
            'error' => $error,
            'processing_time_ms' => $processingTime,
            'error_type' => $errorType,
            'error_code' => $errorCode
        ];
        
        // Log to database (email_log table)
        try {
            $sql = "INSERT INTO email_log (sender_email, recipient_email, subject, success, error_message, processing_time_ms, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $this->db->execute($sql, [$from, $to, $subject, $success ? 1 : 0, $error, $processingTime]);
        } catch (Exception $e) {
            // Continue even if logging fails
        }
        
        // Log to file
        $logMessage = json_encode($logEntry);
        $this->log($logMessage);
    }
    
    /**
     * Log message with timestamp
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
?>