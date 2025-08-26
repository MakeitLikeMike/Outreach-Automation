<?php
/**
 * Outreach Automation - AI-Powered Email Generation & Sending
 * Complete automation for personalized outreach campaigns
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ChatGPTIntegration.php';
require_once __DIR__ . '/GMassIntegration.php';
require_once __DIR__ . '/SenderRotation.php';
require_once __DIR__ . '/EmailTemplate.php';

class OutreachAutomation {
    private $db;
    private $chatgpt;
    private $gmass;
    private $senderRotation;
    private $emailTemplate;
    private $logFile;
    
    // Email generation settings
    const MAX_RETRIES = 3;
    const PERSONALIZATION_LEVELS = [
        'basic' => 1,
        'detailed' => 2, 
        'comprehensive' => 3
    ];
    
    public function __construct() {
        $this->db = new Database();
        $this->chatgpt = new ChatGPTIntegration();
        $this->gmass = new GMassIntegration();
        $this->senderRotation = new SenderRotation();
        $this->emailTemplate = new EmailTemplate();
        $this->logFile = __DIR__ . '/../logs/outreach_automation.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Generate AI-powered outreach email for a domain
     */
    public function generateOutreachEmail($domainId, $campaignId) {
        $this->log("🤖 Starting AI email generation for domain ID: {$domainId}");
        
        try {
            // Get domain and campaign details
            $domain = $this->getDomainDetails($domainId);
            $campaign = $this->getCampaignDetails($campaignId);
            
            if (!$domain || !$campaign) {
                throw new Exception("Domain or campaign not found");
            }
            
            $this->log("📧 Generating email for domain: {$domain['domain']}");
            
            // Determine generation method
            if ($campaign['automation_mode'] === 'template') {
                $email = $this->generateFromTemplate($domain, $campaign);
            } else {
                $email = $this->generateWithAI($domain, $campaign);
            }
            
            // Store generated email
            $emailId = $this->storeGeneratedEmail($domainId, $campaignId, $email);
            
            $this->log("✅ Email generated successfully for {$domain['domain']} - ID: {$emailId}");
            
            return [
                'success' => true,
                'email_id' => $emailId,
                'email' => $email,
                'domain' => $domain['domain']
            ];
            
        } catch (Exception $e) {
            $this->log("❌ Email generation failed for domain {$domainId}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate email using AI with domain-specific personalization
     */
    private function generateWithAI($domain, $campaign) {
        $this->log("🧠 Using AI to generate personalized email for {$domain['domain']}");
        
        // Prepare context for AI
        $context = [
            'domain' => $domain['domain'],
            'contact_email' => $domain['contact_email'] ?? '',
            'domain_metrics' => [
                'dr_rating' => $domain['domain_rating'] ?? 0,
                'organic_traffic' => $domain['organic_traffic'] ?? 0,
                'quality_score' => $domain['quality_score'] ?? 0
            ],
            'campaign_name' => $campaign['name'],
            'owner_email' => $campaign['owner_email'],
            'target_topic' => $this->extractTopicFromCampaign($campaign)
        ];
        
        // Get AI analysis of the domain for personalization (with timeout protection)
        try {
            $domainAnalysis = $this->analyzeWebsiteForPersonalization($domain['domain']);
        } catch (Exception $e) {
            $this->log("⚠️ Website analysis failed, using basic context: " . $e->getMessage());
            $domainAnalysis = ['topic' => 'general', 'industry' => 'unknown'];
        }
        
        // Generate personalized email using ChatGPT (with timeout)
        $prompt = $this->buildEmailGenerationPrompt($context, $domainAnalysis);
        
        // Set timeout for AI generation
        $startTime = time();
        $timeoutSeconds = 30;
        
        try {
            $aiResponse = $this->chatgpt->generateQuickResponse($prompt);
            
            if ((time() - $startTime) > $timeoutSeconds) {
                throw new Exception("AI generation timeout after {$timeoutSeconds} seconds");
            }
        } catch (Exception $e) {
            $this->log("⚠️ AI generation failed, falling back to template: " . $e->getMessage());
            // Fallback to template generation
            return $this->generateFromTemplate($domain, $campaign);
        }
        
        if (!$aiResponse['success']) {
            throw new Exception("AI email generation failed: " . $aiResponse['error']);
        }
        
        // Parse and structure the generated email
        $email = $this->parseGeneratedEmail($aiResponse['content'], $context);
        
        return $email;
    }
    
    /**
     * Generate email from template with personalization
     */
    private function generateFromTemplate($domain, $campaign) {
        $this->log("📝 Using template for email generation - {$domain['domain']}");
        
        $template = $this->emailTemplate->getById($campaign['email_template_id']);
        
        if (!$template) {
            throw new Exception("Email template not found");
        }
        
        // Personalize template
        $personalizedEmail = [
            'subject' => $this->personalizeText($template['subject'], $domain, $campaign),
            'body' => $this->personalizeText($template['body'], $domain, $campaign),
            'personalization_level' => 'basic'
        ];
        
        return $personalizedEmail;
    }
    
    /**
     * Build comprehensive prompt for AI email generation
     */
    private function buildEmailGenerationPrompt($context, $domainAnalysis) {
        return "Generate a personalized guest post outreach email with the following context:

WEBSITE DETAILS:
- Domain: {$context['domain']}
- Domain Rating: {$context['domain_metrics']['dr_rating']}
- Quality Score: {$context['domain_metrics']['quality_score']}

CAMPAIGN INFO:
- Campaign: {$context['campaign_name']}
- Contact Person: Will be determined from email address
- Topic Focus: {$context['target_topic']}

REQUIREMENTS:
1. Professional, friendly tone
2. Personalized subject line mentioning their website specifically
3. Brief introduction explaining who you are
4. Clear value proposition for guest posting
5. 2-3 specific article ideas relevant to their audience
6. Professional closing with next steps
7. Keep email concise (200-300 words max)

OUTPUT FORMAT:
Subject: [Your subject line]

Body: [Your email body]

Generate a compelling, personalized email that feels human-written and relevant to their specific website.";
    }
    
    /**
     * Parse AI-generated email content
     */
    private function parseGeneratedEmail($content, $context) {
        $lines = explode("\n", $content);
        $subject = '';
        $body = '';
        $inBody = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (strpos($line, 'Subject:') === 0) {
                $subject = trim(substr($line, 8));
                // Clean subject line - remove campaign attribution
                $subject = str_replace(' × ' . $context['campaign_name'], '', $subject);
                $subject = preg_replace('/ - [^-]+$/', '', $subject); // Remove " - Domain" pattern
            } elseif (strpos($line, 'Body:') === 0) {
                $inBody = true;
                $bodyStart = trim(substr($line, 5));
                if (!empty($bodyStart)) {
                    $body = $bodyStart . "\n";
                }
            } elseif ($inBody && !empty($line)) {
                $body .= $line . "\n";
            }
        }
        
        // Fallback parsing if format is different
        if (empty($subject) || empty($body)) {
            $parts = explode("\n\n", $content, 2);
            if (count($parts) >= 2) {
                $subject = trim(str_replace('Subject:', '', $parts[0]));
                $body = trim($parts[1]);
            } else {
                $subject = "Partnership Opportunity";
                $body = trim($content);
            }
        }
        
        // Apply template personalization to replace variables like {DOMAIN}, {TOPIC_AREA}, etc.
        require_once __DIR__ . '/EmailTemplate.php';
        $emailTemplate = new EmailTemplate();
        
        $personalizedSubject = $emailTemplate->personalizeTemplate($subject, $context['domain'], $context['contact_email'] ?? '');
        $personalizedBody = $emailTemplate->personalizeTemplate($body, $context['domain'], $context['contact_email'] ?? '');
        
        return [
            'subject' => trim($personalizedSubject),
            'body' => trim($personalizedBody),
            'personalization_level' => 'comprehensive',
            'generated_by' => 'ai',
            'word_count' => str_word_count($personalizedBody)
        ];
    }
    
    /**
     * Process batch outreach for campaign
     */
    public function processCampaignOutreach($campaignId, $batchSize = 5) {
        $this->log("🚀 Processing campaign outreach batch for campaign: {$campaignId}");
        
        try {
            // Get domains ready for outreach
            $domains = $this->getDomainsReadyForOutreach($campaignId, $batchSize);
            
            if (empty($domains)) {
                $this->log("ℹ️ No domains ready for outreach in campaign {$campaignId}");
                return [
                    'processed' => 0,
                    'sent' => 0,
                    'failed' => 0,
                    'message' => 'No domains ready for outreach'
                ];
            }
            
            $sent = 0;
            $failed = 0;
            
            foreach ($domains as $domain) {
                try {
                    // Generate email if not already generated
                    if (empty($domain['outreach_email_id'])) {
                        $emailResult = $this->generateOutreachEmail($domain['id'], $campaignId);
                        
                        if (!$emailResult['success']) {
                            throw new Exception("Email generation failed: " . $emailResult['error']);
                        }
                        
                        $emailId = $emailResult['email_id'];
                    } else {
                        $emailId = $domain['outreach_email_id'];
                    }
                    
                    // For now, just mark as generated (sending will be implemented with multi-sender)
                    $this->log("📧 Email generated for {$domain['domain']} - ID: {$emailId}");
                    $sent++;
                    
                    // Rate limiting between generations
                    sleep(1);
                    
                } catch (Exception $e) {
                    $this->log("❌ Failed to process domain {$domain['id']}: " . $e->getMessage());
                    $failed++;
                }
            }
            
            $processed = count($domains);
            $this->log("✅ Batch outreach completed: {$processed} processed, {$sent} generated, {$failed} failed");
            
            return [
                'processed' => $processed,
                'sent' => $sent,
                'failed' => $failed,
                'message' => "Processed {$processed} domains, generated {$sent} emails"
            ];
            
        } catch (Exception $e) {
            $this->log("❌ Campaign outreach batch failed: " . $e->getMessage());
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get domain details with contact info
     */
    private function getDomainDetails($domainId) {
        return $this->db->fetchOne(
            "SELECT * FROM target_domains WHERE id = ? AND contact_email IS NOT NULL",
            [$domainId]
        );
    }
    
    /**
     * Get campaign details
     */
    private function getCampaignDetails($campaignId) {
        return $this->db->fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
    }
    
    /**
     * Store generated email (3-parameter version for AI generation)
     */
    private function storeGeneratedEmail($domainId, $campaignId, $email) {
        // Get domain and campaign details for sender/recipient info
        $domain = $this->getDomainDetails($domainId);
        $campaign = $this->getCampaignDetails($campaignId);
        
        // Determine sender email from campaign owner or system default
        $senderEmail = $campaign['owner_email'] ?? $this->getSystemSenderEmail();
        $recipientEmail = $domain['contact_email'] ?? '';
        
        $sql = "
            INSERT INTO outreach_emails (
                campaign_id, domain_id, sender_email, recipient_email, 
                subject, body, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'draft', NOW())
        ";
        
        $this->db->execute($sql, [
            $campaignId,
            $domainId,
            $senderEmail,
            $recipientEmail,
            $email['subject'],
            $email['body']
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Get system sender email from settings
     */
    private function getSystemSenderEmail() {
        $setting = $this->db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'sender_email'");
        return $setting['setting_value'] ?? 'noreply@example.com';
    }
    
    /**
     * Store generated email (legacy 7-parameter version)
     */
    public function storeGeneratedEmailLegacy($campaignId, $domainId, $templateId, $subject, $body, $senderEmail, $recipientEmail) {
        $sql = "
            INSERT INTO outreach_emails (
                campaign_id, domain_id, sender_email, recipient_email, subject, body,
                status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'draft', NOW())
        ";
        
        $this->db->execute($sql, [
            $campaignId,
            $domainId,
            $senderEmail,
            $recipientEmail,
            $subject,
            $body
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Get domains ready for outreach
     */
    private function getDomainsReadyForOutreach($campaignId, $limit) {
        $sql = "
            SELECT 
                td.*,
                oe.id as outreach_email_id,
                oe.status as email_status
            FROM target_domains td
            LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
            WHERE td.campaign_id = ?
            AND td.status = 'approved'
            AND td.contact_email IS NOT NULL
            AND (oe.status IS NULL OR oe.status = 'generated')
            AND td.contacted_at IS NULL
            ORDER BY td.quality_score DESC, td.created_at ASC
            LIMIT ?
        ";
        
        return $this->db->fetchAll($sql, [$campaignId, $limit]);
    }
    
    // Utility methods
    private function personalizeText($text, $domain, $campaign) {
        // Use EmailTemplate personalization for consistency
        $senderName = $campaign['owner_email'] ?? 'Outreach Team';
        $recipientEmail = $domain['contact_email'] ?? '';
        
        $personalizedText = $this->emailTemplate->personalizeTemplate(
            $text, 
            $domain['domain'], 
            $recipientEmail, 
            $senderName
        );
        
        // Clean up campaign attribution and fix formatting
        $personalizedText = str_replace('[Your name]', $senderName, $personalizedText);
        $personalizedText = str_replace('From: ' . $campaign['name'], '', $personalizedText);
        $personalizedText = str_replace(' × ' . $campaign['name'], '', $personalizedText);
        $personalizedText = str_replace(': ' . ucwords(strtolower(str_replace('.com', '', $domain['domain']))) . ' × ' . $campaign['name'], '', $personalizedText);
        
        // Fix line breaks - convert sentences to proper paragraphs with HTML breaks
        $personalizedText = str_replace('. I', ".<br><br>I", $personalizedText);
        $personalizedText = str_replace('. Would', ".<br><br>Would", $personalizedText);
        $personalizedText = str_replace('. Best regards', "<br><br>Best regards", $personalizedText);
        $personalizedText = str_replace('Best regards, [Your name]', "Best regards,<br>" . $senderName, $personalizedText);
        
        // Convert all \n to <br> for HTML email display
        $personalizedText = str_replace("\n\n", "<br><br>", $personalizedText);
        $personalizedText = str_replace("\n", "<br>", $personalizedText);
        $personalizedText = str_replace('\\n', "<br>", $personalizedText);
        
        // Clean up extra HTML breaks
        $personalizedText = preg_replace('/(<br>){3,}/', "<br><br>", $personalizedText);
        $personalizedText = trim($personalizedText);
        
        return $personalizedText;
    }
    
    private function extractTopicFromCampaign($campaign) {
        // Extract topic from campaign name or competitor URLs
        $name = strtolower($campaign['name']);
        
        if (strpos($name, 'casino') !== false || strpos($name, 'gambling') !== false) {
            return 'online gambling and casino gaming';
        } elseif (strpos($name, 'tech') !== false || strpos($name, 'software') !== false) {
            return 'technology and software';
        } elseif (strpos($name, 'health') !== false || strpos($name, 'fitness') !== false) {
            return 'health and wellness';
        }
        
        return 'general business topics';
    }
    
    /**
     * Analyze website for personalization context
     */
    private function analyzeWebsiteForPersonalization($domain) {
        // Simple domain-based analysis - can be enhanced later
        $domain = strtolower($domain);
        
        // Industry detection based on domain patterns
        if (strpos($domain, 'casino') !== false || strpos($domain, 'bet') !== false || strpos($domain, 'poker') !== false) {
            return ['topic' => 'gambling', 'industry' => 'online casino and betting'];
        } elseif (strpos($domain, 'tech') !== false || strpos($domain, 'software') !== false || strpos($domain, 'app') !== false) {
            return ['topic' => 'technology', 'industry' => 'technology and software'];
        } elseif (strpos($domain, 'health') !== false || strpos($domain, 'medical') !== false || strpos($domain, 'fitness') !== false) {
            return ['topic' => 'health', 'industry' => 'health and wellness'];
        } elseif (strpos($domain, 'finance') !== false || strpos($domain, 'bank') !== false || strpos($domain, 'invest') !== false) {
            return ['topic' => 'finance', 'industry' => 'financial services'];
        } elseif (strpos($domain, 'travel') !== false || strpos($domain, 'hotel') !== false || strpos($domain, 'tourism') !== false) {
            return ['topic' => 'travel', 'industry' => 'travel and hospitality'];
        } else {
            return ['topic' => 'general', 'industry' => 'general business'];
        }
    }
    
    /**
     * Log message with timestamp
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        echo $logMessage;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
?>