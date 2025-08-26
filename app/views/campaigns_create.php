<?php
// Get email templates
$campaignService = new CampaignService();
$emailTemplates = $campaignService->getEmailTemplates();

// Get messages from URL parameters
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Campaign - Outreach Automation</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/campaigns.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .scheduler-panel {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 0.5rem;
        }
        
        .scheduler-options {
            margin-top: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .scheduling-preview {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }
        
        .automation-panel, .scheduler-panel {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 0.5rem;
        }
        
        .checkbox-group {
            margin-bottom: 1rem;
        }
        
        .checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: background-color 0.2s;
        }
        
        .checkbox-label:hover {
            background: rgba(59, 130, 246, 0.05);
        }
        
        .checkmark {
            width: 18px;
            height: 18px;
            border: 2px solid #d1d5db;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        input[type="checkbox"]:checked + .checkmark {
            background: #3b82f6;
            border-color: #3b82f6;
        }
        
        input[type="checkbox"]:checked + .checkmark:after {
            content: "✓";
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        
        input[type="checkbox"] {
            display: none;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include APP_ROOT . '/includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button onclick="goBack()" class="back-btn" title="Go Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1>Create New Campaign</h1>
            </div>
        </header>

        <div style="padding: 2rem;">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-plus"></i> Create New Campaign</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="campaigns.php?action=store">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
                        <div class="form-group">
                            <label for="name">Campaign Name *</label>
                            <input type="text" id="name" name="name" class="form-control" required 
                                   placeholder="e.g., Technology Guest Posts Q1 2024">
                        </div>

                        <div class="form-group">
                            <label for="owner_email">Campaign Owner Email *</label>
                            <input type="email" id="owner_email" name="owner_email" class="form-control" required 
                                   placeholder="zackparker0905@gmail.com">
                            <small class="help-text">📧 Your personal business email - where you'll receive qualified leads for direct follow-up</small>
                        </div>

                        <div class="form-group">
                            <label for="automation_sender_email">Automation Sender Email *</label>
                            <input type="email" id="automation_sender_email" name="automation_sender_email" class="form-control" required 
                                   value="teamoutreach41@gmail.com" readonly>
                            <small class="help-text">🤖 System automation email - used for initial outreach and reply monitoring (managed by system)</small>
                        </div>

                        <div class="form-group">
                            <label for="automation_mode">Email Generation Mode *</label>
                            <select id="automation_mode" name="automation_mode" class="form-control" required>
                                <option value="">Select email generation mode</option>
                                <option value="auto_generate">Auto-Generate Outreach Emails (AI-powered)</option>
                                <option value="template">Use Provided Email Template</option>
                            </select>
                            <small class="help-text">Choose how outreach emails will be generated for this campaign</small>
                        </div>

                        <div class="form-group" id="template_selection" style="display: none;">
                            <label for="email_template_id">Email Template</label>
                            <select id="email_template_id" name="email_template_id" class="form-control">
                                <option value="">Select an email template</option>
                                <?php foreach ($emailTemplates as $template): ?>
                                    <option value="<?php echo $template['id']; ?>" <?php echo $template['is_default'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($template['name']); ?>
                                        <?php echo $template['is_default'] ? ' (Default)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="help-text">Choose an email template for outreach emails in this campaign</small>
                        </div>

                        <div class="form-group">
                            <label for="automation_settings">🤖 Full Automation Settings</label>
                            <div class="automation-panel">
                                <div class="checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="auto_domain_analysis" name="auto_domain_analysis" value="1" checked>
                                        <span class="checkmark"></span>
                                        <strong>Auto-analyze domains</strong> - Automatically score and approve/reject domains based on quality metrics
                                    </label>
                                </div>
                                
                                <div class="checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="auto_email_search" name="auto_email_search" value="1" checked>
                                        <span class="checkmark"></span>
                                        <strong>Auto-find contact emails</strong> - Automatically search for contact emails using Tomba API
                                    </label>
                                </div>
                                
                                <div class="checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="auto_send" name="auto_send" value="1" checked>
                                        <span class="checkmark"></span>
                                        <strong>Auto-send outreach emails</strong> - Automatically send personalized emails to found contacts
                                    </label>
                                </div>
                                
                                <div class="checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="auto_reply_monitoring" name="auto_reply_monitoring" value="1" checked>
                                        <span class="checkmark"></span>
                                        <strong>Auto-monitor replies</strong> - Automatically monitor and classify incoming replies
                                    </label>
                                </div>
                                
                                <div class="checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="auto_lead_forwarding" name="auto_lead_forwarding" value="1" checked>
                                        <span class="checkmark"></span>
                                        <strong>Auto-forward qualified leads</strong> - Automatically forward positive replies to campaign owner
                                    </label>
                                </div>
                                
                                <div class="automation-summary">
                                    <div class="alert alert-info">
                                        <i class="fas fa-robot"></i>
                                        <strong>Full Automation Enabled:</strong> With all options selected, you only need to provide campaign details and competitor URLs. 
                                        The system will handle everything from domain analysis to delivering qualified leads to your inbox.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Smart Scheduler Settings -->
                        <div class="form-group">
                            <label for="smart_schedule_settings">⏰ Smart Scheduling Settings</label>
                            <div class="scheduler-panel">
                                <div class="checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="enable_smart_scheduling" name="enable_smart_scheduling" value="1" checked>
                                        <span class="checkmark"></span>
                                        <strong>Enable Smart Scheduling</strong> - Optimize send times based on recipient time zones and engagement patterns
                                    </label>
                                </div>
                                
                                <div id="scheduler-options" class="scheduler-options">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="schedule_mode">Scheduling Mode</label>
                                            <select id="schedule_mode" name="schedule_mode" class="form-control">
                                                <option value="immediate">Send Immediately</option>
                                                <option value="optimized" selected>Optimize Send Times</option>
                                                <option value="custom">Custom Schedule</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="batch_size">Batch Size</label>
                                            <select id="batch_size" name="batch_size" class="form-control">
                                                <option value="25">25 emails per batch</option>
                                                <option value="50" selected>50 emails per batch</option>
                                                <option value="100">100 emails per batch</option>
                                                <option value="200">200 emails per batch</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="delay_between_batches">Delay Between Batches</label>
                                            <select id="delay_between_batches" name="delay_between_batches" class="form-control">
                                                <option value="60">1 minute</option>
                                                <option value="300" selected>5 minutes</option>
                                                <option value="600">10 minutes</option>
                                                <option value="1800">30 minutes</option>
                                                <option value="3600">1 hour</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="max_sends_per_day">Max Sends Per Day</label>
                                            <select id="max_sends_per_day" name="max_sends_per_day" class="form-control">
                                                <option value="50">50 emails/day</option>
                                                <option value="100">100 emails/day</option>
                                                <option value="200" selected>200 emails/day</option>
                                                <option value="500">500 emails/day</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="checkbox-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" id="respect_business_hours" name="respect_business_hours" value="1" checked>
                                            <span class="checkmark"></span>
                                            <strong>Respect Business Hours</strong> - Only send during business hours (9 AM - 5 PM local time)
                                        </label>
                                    </div>
                                    
                                    <div class="checkbox-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" id="avoid_holidays" name="avoid_holidays" value="1" checked>
                                            <span class="checkmark"></span>
                                            <strong>Avoid Holidays</strong> - Skip sending on major holidays and weekends
                                        </label>
                                    </div>
                                    
                                    <div class="checkbox-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" id="timezone_optimization" name="timezone_optimization" value="1" checked>
                                            <span class="checkmark"></span>
                                            <strong>Timezone Optimization</strong> - Automatically adjust send times for recipient time zones
                                        </label>
                                    </div>
                                    
                                    <div class="scheduling-preview">
                                        <div class="alert alert-info">
                                            <i class="fas fa-clock"></i>
                                            <strong>Smart Scheduling Active:</strong> Emails will be sent in optimized batches with intelligent timing based on your settings.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="competitor_urls">Competitor URLs</label>
                            <textarea id="competitor_urls" name="competitor_urls" class="form-control textarea" 
                                      placeholder="Enter competitor URLs (one per line)&#10;example1.com&#10;https://example2.com&#10;competitor3.com"></textarea>
                            <small class="help-text">Enter competitor URLs to analyze their backlinks. One URL per line. HTTPS will be added automatically if no protocol is specified.</small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create Campaign
                            </button>
                            <a href="campaigns.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script src="assets/js/campaigns.js"></script>
    <script>
        // Show/hide template selection based on automation mode
        document.getElementById('automation_mode').addEventListener('change', function() {
            const templateSelection = document.getElementById('template_selection');
            if (this.value === 'template') {
                templateSelection.style.display = 'block';
            } else {
                templateSelection.style.display = 'none';
            }
        });

        // Smart Scheduler toggle functionality
        document.getElementById('enable_smart_scheduling').addEventListener('change', function() {
            const schedulerOptions = document.getElementById('scheduler-options');
            if (this.checked) {
                schedulerOptions.style.display = 'block';
            } else {
                schedulerOptions.style.display = 'none';
            }
        });
    </script>
</body>
</html>