<?php
require_once 'config/database.php';

class EmailVerification {
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
    
    public function sendVerificationEmail($email, $type = 'general') {
        // Check if verification is enabled
        if (($this->settings['enable_email_verification'] ?? 'no') === 'no') {
            return true; // Skip verification if disabled
        }
        
        // Generate verification token
        $token = $this->generateVerificationToken();
        
        // Store verification request
        $this->storeVerificationRequest($email, $token, $type);
        
        // Send verification email
        return $this->sendVerificationMessage($email, $token, $type);
    }
    
    private function generateVerificationToken() {
        return bin2hex(random_bytes(32));
    }
    
    private function storeVerificationRequest($email, $token, $type) {
        $sql = "INSERT INTO email_verification (email, verification_token, verification_type, expires_at)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
                ON DUPLICATE KEY UPDATE 
                verification_token = VALUES(verification_token),
                verification_type = VALUES(verification_type),
                expires_at = VALUES(expires_at),
                status = 'pending'";
        
        $this->db->execute($sql, [$email, $token, $type]);
    }
    
    private function sendVerificationMessage($email, $token, $type) {
        $baseUrl = $this->getBaseUrl();
        $verificationUrl = "$baseUrl/verify_email.php?token=$token";
        
        $subject = "Verify your email address - Outreach Automation";
        $body = $this->createVerificationEmailBody($email, $verificationUrl, $type);
        
        // Use system's email sending capability
        try {
            // Try to use configured email service
            if (!empty($this->settings['sender_email'])) {
                return $this->sendViaConfiguredService($email, $subject, $body);
            }
            
            // Fallback to PHP mail
            return $this->sendViaPhpMail($email, $subject, $body);
            
        } catch (Exception $e) {
            error_log("Email verification send failed: " . $e->getMessage());
            return false;
        }
    }
    
    private function createVerificationEmailBody($email, $verificationUrl, $type) {
        $typeDescription = [
            'sender' => 'sender email address',
            'owner' => 'campaign owner email',
            'general' => 'email address'
        ];
        
        $description = $typeDescription[$type] ?? 'email address';
        
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Email Verification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4facfe; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background: #f9f9f9; }
        .button { display: inline-block; background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>📧 Email Verification Required</h1>
        </div>
        
        <div class='content'>
            <h2>Hello!</h2>
            
            <p>You need to verify your $description (<strong>$email</strong>) to continue using the Outreach Automation system.</p>
            
            <p>Please click the button below to verify your email address:</p>
            
            <div style='text-align: center;'>
                <a href='$verificationUrl' class='button'>✅ Verify Email Address</a>
            </div>
            
            <p>Or copy and paste this link into your browser:</p>
            <p style='word-break: break-all; background: #fff; padding: 10px; border: 1px solid #ddd;'>$verificationUrl</p>
            
            <p><strong>Security Note:</strong> This verification link will expire in 24 hours for security reasons.</p>
            
            <p>If you didn't request this verification, you can safely ignore this email.</p>
        </div>
        
        <div class='footer'>
            <p><strong>Outreach Automation System</strong></p>
            <p>This is an automated message. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>";
    }
    
    private function sendViaConfiguredService($to, $subject, $body) {
        // Use Mailgun if configured
        if (!empty($this->settings['mailgun_api_key']) && !empty($this->settings['mailgun_domain'])) {
            require_once 'ApiIntegration.php';
            $api = new ApiIntegration();
            return $api->sendEmail($to, $subject, $body);
        }
        
        // Use Gmail if configured
        if (!empty($this->settings['gmail_credentials'])) {
            require_once 'GmailIntegration.php';
            $gmail = new GmailIntegration();
            $senderEmail = $this->settings['sender_email'];
            return $gmail->sendEmail($senderEmail, $to, $subject, $body);
        }
        
        return false;
    }
    
    private function sendViaPhpMail($to, $subject, $body) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . ($this->settings['sender_email'] ?? 'noreply@outreach-system.com'),
            'Reply-To: ' . ($this->settings['sender_email'] ?? 'noreply@outreach-system.com'),
            'X-Mailer: PHP/' . phpversion()
        ];
        
        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
    
    public function verifyEmail($token) {
        $sql = "SELECT * FROM email_verification 
                WHERE verification_token = ? 
                AND status = 'pending' 
                AND expires_at > NOW()";
        
        $verification = $this->db->fetchOne($sql, [$token]);
        
        if (!$verification) {
            return [
                'success' => false,
                'message' => 'Invalid or expired verification token'
            ];
        }
        
        // Mark as verified
        $sql = "UPDATE email_verification 
                SET status = 'verified', verified_at = NOW() 
                WHERE id = ?";
        
        $this->db->execute($sql, [$verification['id']]);
        
        // Log the verification
        $this->logVerification($verification['email'], $verification['verification_type']);
        
        return [
            'success' => true,
            'message' => 'Email verified successfully',
            'email' => $verification['email'],
            'type' => $verification['verification_type']
        ];
    }
    
    private function logVerification($email, $type) {
        $sql = "INSERT INTO api_logs (api_service, endpoint, request_data, response_data, status_code)
                VALUES ('verification', 'email_verify', ?, ?, 200)";
        
        $this->db->execute($sql, [
            json_encode(['email' => $email, 'type' => $type]),
            json_encode(['status' => 'verified', 'timestamp' => date('Y-m-d H:i:s')])
        ]);
    }
    
    public function isEmailVerified($email, $type = null) {
        // Skip verification check if disabled
        if (($this->settings['enable_email_verification'] ?? 'no') === 'no') {
            return true;
        }
        
        $sql = "SELECT COUNT(*) as count FROM email_verification 
                WHERE email = ? AND status = 'verified'";
        $params = [$email];
        
        if ($type) {
            $sql .= " AND verification_type = ?";
            $params[] = $type;
        }
        
        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] > 0;
    }
    
    public function getVerificationStatus($email) {
        $sql = "SELECT * FROM email_verification 
                WHERE email = ? 
                ORDER BY created_at DESC 
                LIMIT 1";
        
        return $this->db->fetchOne($sql, [$email]);
    }
    
    public function resendVerification($email, $type = 'general') {
        // Check if there's a recent verification request
        $sql = "SELECT * FROM email_verification 
                WHERE email = ? AND verification_type = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
        
        $recent = $this->db->fetchOne($sql, [$email, $type]);
        
        if ($recent) {
            return [
                'success' => false,
                'message' => 'Please wait at least 5 minutes before requesting another verification email'
            ];
        }
        
        $sent = $this->sendVerificationEmail($email, $type);
        
        return [
            'success' => $sent,
            'message' => $sent ? 
                'Verification email sent successfully' : 
                'Failed to send verification email'
        ];
    }
    
    public function cleanupExpiredVerifications() {
        $sql = "DELETE FROM email_verification 
                WHERE expires_at < NOW() 
                AND status = 'pending'";
        
        return $this->db->execute($sql);
    }
    
    public function getVerificationStats($days = 30) {
        $sql = "SELECT 
                    verification_type,
                    status,
                    COUNT(*) as count
                FROM email_verification 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY verification_type, status";
        
        return $this->db->fetchAll($sql, [$days]);
    }
    
    private function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['REQUEST_URI'] ?? '');
        
        return rtrim("$protocol://$host$path", '/');
    }
    
    /**
     * Find domain emails using Tomba API
     */
    public function findDomainEmails($domain) {
        try {
            // Check rate limiting first
            require_once __DIR__ . '/TombaRateLimit.php';
            $rateLimit = new TombaRateLimit();
            
            if (!$rateLimit->canMakeRequest()) {
                throw new Exception("Tomba API rate limit reached");
            }
            
            // Get Tomba API credentials (using same naming as ApiIntegration class)
            $apiKey = $this->settings['tomba_api_key'] ?? null;
            $apiSecret = $this->settings['tomba_secret'] ?? null;
            
            if (!$apiKey || !$apiSecret) {
                throw new Exception("Tomba API credentials not configured");
            }
            
            // Call Tomba API (match working ApiIntegration implementation)
            $url = "https://api.tomba.io/v1/domain-search?domain=" . urlencode($domain);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25, // 25 second timeout to stay under processor timeout
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [
                    'X-Tomba-Key: ' . $apiKey,
                    'X-Tomba-Secret: ' . $apiSecret,
                    'Content-Type: application/json'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                throw new Exception("CURL error: " . $curlError);
            }
            
            if ($httpCode !== 200) {
                $rateLimit->recordFailedRequest("HTTP " . $httpCode . " - " . $response, $httpCode);
                throw new Exception("Tomba API returned HTTP " . $httpCode . " - Response: " . $response);
            }
            
            $data = json_decode($response, true);
            
            if (!$data || !isset($data['data'])) {
                $rateLimit->recordFailedRequest("Invalid response format");
                throw new Exception("Invalid response from Tomba API");
            }
            
            // Record successful API usage
            $rateLimit->recordRequest(1);
            
            // Extract first email found
            if (!empty($data['data']['emails'])) {
                $firstEmail = $data['data']['emails'][0];
                return [
                    'email' => $firstEmail['email'],
                    'confidence' => $firstEmail['confidence'] ?? 'unknown',
                    'source' => 'tomba'
                ];
            }
            
            // No emails found
            return null;
            
        } catch (Exception $e) {
            error_log("EmailVerification::findDomainEmails error for {$domain}: " . $e->getMessage());
            throw $e;
        }
    }
}
?>