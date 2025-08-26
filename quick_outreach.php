<?php
require_once 'auth.php';
$auth->requireAuth();

require_once 'config/database.php';
require_once 'classes/Campaign.php';
require_once 'classes/EmailTemplate.php';
require_once 'classes/ApiIntegration.php';
require_once 'classes/GMassIntegration.php';
require_once 'classes/DirectGmailSMTP.php';
require_once 'fix_quick_outreach_domains.php';

$database = new Database();
$db = $database->getConnection();

$campaignClass = new Campaign();
$templateClass = new EmailTemplate();
$apiIntegration = new ApiIntegration();
$gmassIntegration = new GMassIntegration();
$directGmailSMTP = new DirectGmailSMTP();
$domainFixer = new QuickOutreachDomainFixer();

// Get campaigns and templates for dropdowns
$campaigns = $campaignClass->getAll();
$templates = $templateClass->getAll();

// Handle form submission
$result = null;
$emailFound = null;
$showOutreachForm = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'search_email') {
        $domain = trim($_POST['domain'] ?? '');
        
        if ($domain) {
            try {
                $emailFound = $apiIntegration->findEmail($domain);
                if ($emailFound) {
                    $result = [
                        'success' => true,
                        'message' => "Email found: $emailFound",
                        'email' => $emailFound,
                        'domain' => $domain
                    ];
                    $showOutreachForm = true;
                } else {
                    $result = [
                        'success' => false,
                        'message' => 'No email found for this domain',
                        'email' => null,
                        'domain' => $domain
                    ];
                }
            } catch (Exception $e) {
                $result = [
                    'success' => false,
                    'message' => 'Error searching email: ' . $e->getMessage(),
                    'email' => null,
                    'domain' => $domain
                ];
            }
        } else {
            $result = [
                'success' => false,
                'message' => 'Please enter a domain',
                'email' => null,
                'domain' => ''
            ];
        }
    } elseif ($action === 'send_email') {
        $domain = trim($_POST['domain'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $campaignId = $_POST['campaign_id'] ?? '';
        $outreachMethod = $_POST['outreach_method'] ?? 'template';
        $templateId = $_POST['template_id'] ?? '';
        $promoteUrl = trim($_POST['promote_url'] ?? '');
        
        // Validation based on outreach method
        $isValidForTemplate = ($outreachMethod === 'template' && $domain && $email && $campaignId && $templateId);
        $isValidForAI = ($outreachMethod === 'ai_generated' && $domain && $email && $campaignId && $promoteUrl);
        
        if ($isValidForTemplate || $isValidForAI) {
            try {
                // Get campaign details
                $campaign = $campaignClass->getById($campaignId);
                if (!$campaign) {
                    throw new Exception('Campaign not found with ID: ' . $campaignId);
                }
                
                // Get automation sender email from campaign (for outreach) - separate from owner email (for lead forwarding)
                if (!empty($campaign['automation_sender_email'])) {
                    $automationSenderEmail = $campaign['automation_sender_email'];
                } else {
                    // Fallback to system default automation sender
                    $defaultSenderQuery = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
                    $defaultSenderQuery->execute(['gmass_from_email']);
                    $defaultSender = $defaultSenderQuery->fetch(PDO::FETCH_ASSOC);
                    $automationSenderEmail = $defaultSender ? $defaultSender['setting_value'] : 'teamoutreach41@gmail.com';
                }
                
                // Get campaign owner email for lead forwarding
                $campaignOwnerEmail = $campaign['owner_email'] ?? 'zackparker0905@gmail.com';
                
                if ($outreachMethod === 'ai_generated') {
                    // For AI-generated emails, create personalized content with promote URL
                    $domainName = ucwords(str_replace(['.com', '.net', '.org', 'www.'], '', $domain));
                    $subject = "Partnership Opportunity";
                    
                    $body = "Hi there,<br><br>";
                    $body .= "I hope this email finds you well. I'm reaching out regarding a potential collaboration opportunity.<br><br>";
                    $body .= "I've been following the excellent work at {$domain} and I'm impressed with your content quality and audience engagement. ";
                    $body .= "I believe there could be a fantastic partnership opportunity between us.<br><br>";
                    $body .= "I'd love to discuss featuring some content that I think would be valuable for your audience: {$promoteUrl}<br><br>";
                    $body .= "This could be a great fit for your platform, and I'd be happy to discuss how we can make this mutually beneficial.<br><br>";
                    $body .= "Would you be interested in exploring this partnership? I'd be glad to share more details and answer any questions you might have.<br><br>";
                    $body .= "Best regards,<br>Outreach Team";
                } else {
                    // Template-based approach
                    $template = $templateClass->getById($templateId);
                    if (!$template) {
                        throw new Exception('Email template not found with ID: ' . $templateId);
                    }
                    
                    if (empty($template['body'])) {
                        throw new Exception('Email template has empty body content. Please edit the template and add content.');
                    }
                    
                    // Replace template variables
                    $subject = str_replace('{domain}', $domain, $template['subject']);
                    $body = str_replace([
                        '{domain}', 
                        '{campaign_name}'
                    ], [
                        $domain, 
                        $campaign['name']
                    ], $template['body']);
                }
                
                // Additional validation
                if (empty($body)) {
                    throw new Exception('Email body is empty. Please check the content generation.');
                }
                
                // Send email using GMass integration with automation sender email and Reply-To campaign owner
                $emailOptions = [
                    'reply_to' => $campaignOwnerEmail  // Replies go directly to campaign owner
                ];
                $sendResult = $gmassIntegration->sendEmail($automationSenderEmail, $email, $subject, $body, $emailOptions);
                
                if ($sendResult && isset($sendResult['success']) && $sendResult['success']) {
                    // Create target_domains record for quick outreach
                    $domainId = $domainFixer->createQuickOutreachDomain($campaignId, $domain, $email);
                    $messageId = $sendResult['message_id'] ?? null;
                    
                    // Try to insert with new columns, fallback to basic logging if they don't exist
                    try {
                        // For template method, promoteUrl will be empty, for AI method it will have a value
                        $promoteUrlValue = ($outreachMethod === 'ai_generated') ? $promoteUrl : null;
                        $linkAcquisitionValue = ($outreachMethod === 'template') ? 'template' : 'ai_generated';
                        $templateIdValue = ($outreachMethod === 'template') ? $templateId : null; // NULL for AI-generated
                        
                        $logSql = "INSERT INTO outreach_emails (campaign_id, domain_id, template_id, sender_email, recipient_email, subject, body, status, sent_at, gmail_message_id, promote_url, link_acquisition_type, created_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW())";
                        $stmt = $db->prepare($logSql);
                        $stmt->execute([$campaignId, $domainId, $templateIdValue, $senderEmail, $email, $subject, $body, 'sent', $messageId, $promoteUrlValue, $linkAcquisitionValue]);
                    } catch (Exception $e) {
                        // Fallback to basic logging if new columns don't exist
                        $logSql = "INSERT INTO outreach_emails (campaign_id, domain_id, template_id, sender_email, recipient_email, subject, body, status, sent_at, gmail_message_id, created_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())";
                        $stmt = $db->prepare($logSql);
                        $stmt->execute([$campaignId, $domainId, $templateIdValue, $senderEmail, $email, $subject, $body, 'sent', $messageId]);
                    }
                    
                    $result = [
                        'success' => true,
                        'message' => "Email sent successfully to $email!" . ($messageId ? " (Message ID: $messageId)" : ""),
                        'email' => $email,
                        'domain' => $domain
                    ];
                } else {
                    $errorMessage = $sendResult['error'] ?? 'Unknown error occurred';
                    throw new Exception("Failed to send email from $senderEmail to $email: " . $errorMessage);
                }
            } catch (Exception $e) {
                $result = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'email' => $email,
                    'domain' => $domain
                ];
            }
        } else {
            $missingFields = [];
            if (empty($domain)) $missingFields[] = 'domain';
            if (empty($email)) $missingFields[] = 'email';
            if (empty($campaignId)) $missingFields[] = 'campaign';
            if ($outreachMethod === 'template' && empty($templateId)) $missingFields[] = 'template';
            if ($outreachMethod === 'ai_generated' && empty($promoteUrl)) $missingFields[] = 'URL to promote';
            
            $result = [
                'success' => false,
                'message' => 'Please fill in all required fields: ' . implode(', ', $missingFields),
                'email' => $email ?? '',
                'domain' => $domain ?? ''
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Outreach - Outreach Automation</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/quick_outreach.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button onclick="goBack()" class="back-btn" title="Go Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1>Quick Outreach</h1>
            </div>
        </header>

        <div class="quick-outreach-container">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-rocket"></i> Quick Domain Outreach</h2>
                    <p>Search for emails and send outreach campaigns instantly</p>
                </div>

                <div class="card-body">
                    <!-- Process Steps -->
                    <div class="outreach-steps">
                        <div class="step <?php echo (!$result || !$result['success'] || !$result['email']) ? 'active' : ''; ?>">
                            <div class="step-circle">1</div>
                            <div class="step-label">Enter Domain</div>
                        </div>
                        <div class="step <?php echo ($result && $result['success'] && $result['email'] && !(isset($showOutreachForm) && $showOutreachForm)) ? 'active' : ''; ?>">
                            <div class="step-circle">2</div>
                            <div class="step-label">Email Found</div>
                        </div>
                        <div class="step <?php echo (isset($showOutreachForm) && $showOutreachForm && !isset($_POST['send_email'])) ? 'active' : ''; ?>">
                            <div class="step-circle">3</div>
                            <div class="step-label">Setup Outreach</div>
                        </div>
                        <div class="step <?php echo (isset($_POST['send_email'])) ? 'active' : ''; ?>">
                            <div class="step-circle">4</div>
                            <div class="step-label">Send Email</div>
                        </div>
                    </div>

                    <?php if ($result): ?>
                        <div class="alert <?php echo $result['success'] ? 'alert-success' : 'alert-error'; ?>">
                            <i class="fas fa-<?php echo $result['success'] ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                            <?php echo htmlspecialchars($result['message']); ?>
                        </div>
                    <?php endif; ?>

                    <div class="outreach-form">
                        <!-- Step 1: Domain Search -->
                        <form method="POST" id="searchForm">
                            <input type="hidden" name="action" value="search_email">
                            
                            <div class="form-group">
                                <label for="domain">Domain to Research</label>
                                <div class="form-row">
                                    <input type="text" 
                                           id="domain" 
                                           name="domain" 
                                           class="form-control" 
                                           placeholder="example.com" 
                                           value="<?php echo htmlspecialchars($_POST['domain'] ?? ''); ?>"
                                           required>
                                    <button type="submit" class="btn-search">
                                        <i class="fas fa-search"></i> Find Email
                                    </button>
                                </div>
                            </div>
                        </form>

                        <!-- Step 2: Email Found -->
                        <?php if ($result && $result['success'] && $result['email']): ?>
                            <div class="email-found">
                                <div class="email-address">
                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($result['email']); ?>
                                </div>
                                <div class="success-message">✅ Email found for <?php echo htmlspecialchars($result['domain']); ?>! Ready for personalized outreach.</div>
                            </div>
                        <?php endif; ?>

                        <!-- Step 3: Outreach Setup Form (Auto-shown when email is found) -->
                        <?php if (isset($showOutreachForm) && $showOutreachForm): ?>
                            <div class="form-section outreach-automation-section">
                                <h3><i class="fas fa-robot"></i> Automated Personalized Outreach</h3>
                                <p class="automation-note">🤖 System will automatically generate personalized email content based on your selections below</p>
                                
                                <form method="POST" id="sendForm">
                                    <input type="hidden" name="action" value="send_email">
                                    <input type="hidden" name="domain" value="<?php echo htmlspecialchars($result['domain']); ?>">
                                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($result['email']); ?>">
                                    
                                    <!-- Outreach Method Selection -->
                                    <div class="form-group">
                                        <label for="outreach_method">Outreach Method *</label>
                                        <select id="outreach_method" name="outreach_method" class="form-control" required onchange="toggleOutreachFields()">
                                            <option value="">Choose outreach method</option>
                                            <option value="template">📧 Use Email Template</option>
                                            <option value="ai_generated">🤖 AI-Generated Outreach</option>
                                        </select>
                                        <small class="help-text">Choose how you want to create the outreach email</small>
                                    </div>
                                    
                                    <!-- Campaign Selection (Always Shown) -->
                                    <div class="form-group">
                                        <label for="campaign_id">Select Campaign *</label>
                                        <select id="campaign_id" name="campaign_id" class="form-control" required>
                                            <option value="">Choose Campaign</option>
                                            <?php foreach ($campaigns as $campaign): ?>
                                                <option value="<?php echo $campaign['id']; ?>">
                                                    <?php echo htmlspecialchars($campaign['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="help-text">Domain will be associated with this campaign</small>
                                    </div>
                                    
                                    <!-- Template-Specific Fields -->
                                    <div id="template-fields" style="display: none;">
                                        <!-- Template Selection -->
                                        <div class="form-group">
                                            <label for="template_id">Select Email Template *</label>
                                            <select id="template_id" name="template_id" class="form-control" onchange="previewTemplate()">
                                                <option value="">Choose Template</option>
                                                <?php foreach ($templates as $template): ?>
                                                    <option value="<?php echo $template['id']; ?>" 
                                                            data-subject="<?php echo htmlspecialchars($template['subject']); ?>"
                                                            data-body="<?php echo htmlspecialchars($template['body']); ?>">
                                                        <?php echo htmlspecialchars($template['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Template Preview -->
                                        <div id="template-preview" class="form-group" style="display: none;">
                                            <label>Email Preview</label>
                                            <div class="template-preview-box">
                                                <div class="preview-subject">
                                                    <strong>Subject:</strong> <span id="preview-subject-text"></span>
                                                </div>
                                                <div class="preview-body">
                                                    <strong>Body:</strong>
                                                    <div id="preview-body-text"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- AI-Generated Fields -->
                                    <div id="ai-fields" style="display: none;">
                                        <!-- URL to Promote for AI -->
                                        <div class="form-group">
                                            <label for="promote_url_ai">URL to Promote *</label>
                                            <input type="url" id="promote_url_ai" name="promote_url" class="form-control" 
                                                   placeholder="https://yoursite.com/article-or-page">
                                            <small class="help-text">Enter the URL you want to promote through this AI-generated outreach</small>
                                        </div>
                                        
                                        <div class="ai-info-box">
                                            <h4>🤖 AI-Generated Outreach</h4>
                                            <p>The system will automatically generate a personalized outreach email for <strong><?php echo htmlspecialchars($result['domain'] ?? ''); ?></strong> using:</p>
                                            <ul>
                                                <li>✅ Domain analysis and context</li>
                                                <li>✅ Your URL to promote</li>
                                                <li>✅ Campaign-specific messaging</li>
                                                <li>✅ Professional partnership approach</li>
                                                <li>✅ Personalized subject line</li>
                                            </ul>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn-send">
                                        <i class="fas fa-paper-plane"></i> Send Outreach Email
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script>
        // Form validation and UX improvements
        document.addEventListener('DOMContentLoaded', function() {
            const domainInput = document.getElementById('domain');
            const searchForm = document.getElementById('searchForm');
            const sendForm = document.getElementById('sendForm');

            // Domain input validation
            if (domainInput) {
                domainInput.addEventListener('input', function() {
                    let value = this.value.trim();
                    // Remove protocol if present
                    value = value.replace(/^https?:\/\//, '');
                    // Remove www if present
                    value = value.replace(/^www\./, '');
                    // Remove trailing slash
                    value = value.replace(/\/$/, '');
                    this.value = value;
                });
            }

            // Loading states
            if (searchForm) {
                searchForm.addEventListener('submit', function() {
                    const button = this.querySelector('.btn-search');
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
                    button.disabled = true;
                });
            }

            if (sendForm) {
                sendForm.addEventListener('submit', function() {
                    const button = this.querySelector('.btn-send');
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending Email...';
                    button.disabled = true;
                });
            }

            // Template preview function
            window.previewTemplate = function() {
                const templateSelect = document.getElementById('template_id');
                const previewDiv = document.getElementById('template-preview');
                const subjectSpan = document.getElementById('preview-subject-text');
                const bodyDiv = document.getElementById('preview-body-text');
                
                if (templateSelect && templateSelect.value) {
                    const selectedOption = templateSelect.options[templateSelect.selectedIndex];
                    const subject = selectedOption.getAttribute('data-subject') || '';
                    const body = selectedOption.getAttribute('data-body') || '';
                    const domain = '<?php echo htmlspecialchars($result['domain'] ?? '{domain}'); ?>';
                    
                    // Replace placeholders for preview (simplified for templates)
                    const previewSubject = subject.replace(/\{domain\}/g, domain);
                    const previewBody = body.replace(/\{domain\}/g, domain)
                                           .replace(/\{campaign_name\}/g, '{campaign_name}');
                    
                    if (subjectSpan) subjectSpan.textContent = previewSubject;
                    if (bodyDiv) bodyDiv.textContent = previewBody;
                    if (previewDiv) previewDiv.style.display = 'block';
                } else {
                    if (previewDiv) previewDiv.style.display = 'none';
                }
            };
            
            // Toggle outreach fields based on method selection
            window.toggleOutreachFields = function() {
                const method = document.getElementById('outreach_method').value;
                const templateFields = document.getElementById('template-fields');
                const aiFields = document.getElementById('ai-fields');
                const promoteUrlAi = document.getElementById('promote_url_ai');
                const templateId = document.getElementById('template_id');
                
                if (method === 'template') {
                    if (templateFields) templateFields.style.display = 'block';
                    if (aiFields) aiFields.style.display = 'none';
                    if (templateId) templateId.required = true;
                    if (promoteUrlAi) promoteUrlAi.required = false;
                } else if (method === 'ai_generated') {
                    if (templateFields) templateFields.style.display = 'none';
                    if (aiFields) aiFields.style.display = 'block';
                    if (templateId) templateId.required = false;
                    if (promoteUrlAi) promoteUrlAi.required = true;
                } else {
                    if (templateFields) templateFields.style.display = 'none';
                    if (aiFields) aiFields.style.display = 'none';
                    if (templateId) templateId.required = false;
                    if (promoteUrlAi) promoteUrlAi.required = false;
                }
            };
        });
    </script>
</body>
</html>