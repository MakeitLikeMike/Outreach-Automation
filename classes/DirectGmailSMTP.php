<?php
/**
 * Direct Gmail SMTP Integration
 * Sends emails directly through Gmail SMTP using app passwords
 * Ensures emails appear in the correct Gmail account's sent folder
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class DirectGmailSMTP {
    private $db;
    private $settings;
    private $logFile;
    
    public function __construct() {
        $this->db = new Database();
        $this->loadSettings();
        $this->logFile = __DIR__ . '/../logs/direct_gmail_smtp.log';
        
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
     * Send email directly through Gmail SMTP
     * This ensures emails appear in the correct Gmail sent folder
     */
    public function sendEmail($from, $to, $subject, $body, $options = []) {
        $startTime = microtime(true);
        
        try {
            // Validate inputs
            $this->validateEmailInputs($from, $to, $subject, $body);
            
            // Get Gmail app password for the sender
            $appPassword = $this->getAppPasswordForSender($from);
            if (!$appPassword) {
                throw new Exception("No app password configured for sender: $from");
            }
            
            // Send via Gmail SMTP
            $messageId = $this->sendViaGmailSMTP($from, $to, $subject, $body, $appPassword, $options);
            
            $processingTime = round((microtime(true) - $startTime) * 1000);
            $this->logEmailSend($from, $to, $subject, $messageId, true, null, $processingTime);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'processing_time_ms' => $processingTime,
                'sent_via' => 'Direct Gmail SMTP'
            ];
            
        } catch (Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000);
            $this->logEmailSend($from, $to, $subject, null, false, $e->getMessage(), $processingTime);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime
            ];
        }
    }
    
    /**
     * Send email via Gmail SMTP using PHPMailer
     */
    private function sendViaGmailSMTP($from, $to, $subject, $body, $appPassword, $options = []) {
        $mail = new PHPMailer(true);
        
        try {
            // Gmail SMTP settings
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $from;  // Gmail email address
            $mail->Password = $appPassword;  // Gmail app password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            
            // Enable verbose debug output for troubleshooting
            if (!empty($options['debug'])) {
                $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            }
            
            // Recipients  
            $mail->setFrom($from);
            $mail->addAddress($to);
            
            // Set Reply-To if specified
            if (!empty($options['reply_to'])) {
                $mail->addReplyTo($options['reply_to']);
            }
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            
            // Send the email
            if (!$mail->send()) {
                throw new Exception('PHPMailer error: ' . $mail->ErrorInfo);
            }
            
            // Extract message ID from headers
            $messageId = $mail->getLastMessageID();
            if (empty($messageId)) {
                $messageId = 'gmail_' . uniqid() . '@' . explode('@', $from)[1];
            }
            
            return $messageId;
            
        } catch (PHPMailerException $e) {
            $errorInfo = $e->getMessage();
            
            // Handle specific Gmail errors
            if (strpos($errorInfo, 'Username and Password not accepted') !== false) {
                throw new Exception('Gmail authentication failed - check app password for ' . $from);
            } elseif (strpos($errorInfo, 'SMTP connect() failed') !== false) {
                throw new Exception('Gmail SMTP connection failed - check internet connection');
            } else {
                throw new Exception('Gmail SMTP error: ' . $errorInfo);
            }
        }
    }
    
    /**
     * Get app password for a specific sender email
     */
    private function getAppPasswordForSender($email) {
        // Map of emails to their app passwords
        $emailPasswords = [
            'teamoutreach41@gmail.com' => $this->settings['imap_password'] ?? null,
            'jimmyrose1414@gmail.com' => $this->settings['imap_account_2_password'] ?? null,
            'zackparker0905@gmail.com' => $this->settings['imap_account_3_password'] ?? null
        ];
        
        return $emailPasswords[$email] ?? null;
    }
    
    /**
     * Validate email inputs
     */
    private function validateEmailInputs($from, $to, $subject, $body) {
        if (empty($from) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid sender email address');
        }
        
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid recipient email address');
        }
        
        if (empty($subject)) {
            throw new Exception('Email subject is required');
        }
        
        if (empty($body)) {
            throw new Exception('Email body is required');
        }
    }
    
    /**
     * Log email sending attempts
     */
    private function logEmailSend($from, $to, $subject, $messageId, $success, $error, $processingTime) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'message_id' => $messageId,
            'success' => $success,
            'error' => $error,
            'processing_time_ms' => $processingTime,
            'method' => 'Direct Gmail SMTP'
        ];
        
        $logLine = json_encode($logEntry) . PHP_EOL;
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Test Gmail SMTP connection
     */
    public function testConnection($email = null) {
        $testEmail = $email ?: 'teamoutreach41@gmail.com';
        $appPassword = $this->getAppPasswordForSender($testEmail);
        
        if (!$appPassword) {
            return [
                'success' => false,
                'error' => "No app password configured for $testEmail"
            ];
        }
        
        try {
            $mail = new PHPMailer();
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $testEmail;
            $mail->Password = $appPassword;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            
            // Test connection without sending
            if (!$mail->smtpConnect()) {
                throw new Exception('Gmail SMTP connection failed');
            }
            
            $mail->smtpClose();
            
            return [
                'success' => true,
                'message' => "Direct Gmail SMTP connection successful for $testEmail",
                'server' => 'smtp.gmail.com:587'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
?>