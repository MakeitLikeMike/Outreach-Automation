<?php
/**
 * GMass + IMAP Setup Wizard
 * Guided configuration for the new email system
 */
require_once 'config/database.php';
require_once 'classes/GMassIntegration.php';
require_once 'classes/IMAPMonitor.php';

$step = $_GET['step'] ?? '1';
$db = new Database();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['action']) {
        case 'save_gmass':
            $apiKey = trim($_POST['gmass_api_key']);
            if (!empty($apiKey)) {
                $db->execute("INSERT INTO system_settings (setting_key, setting_value) VALUES ('gmass_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$apiKey, $apiKey]);
                header('Location: setup_wizard.php?step=2&success=gmass');
                exit;
            }
            break;
            
        case 'save_imap':
            $email = trim($_POST['imap_email']);
            $password = trim($_POST['imap_password']);
            
            if (!empty($email) && !empty($password)) {
                $db->execute("INSERT INTO system_settings (setting_key, setting_value) VALUES ('imap_email', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$email, $email]);
                $db->execute("INSERT INTO system_settings (setting_key, setting_value) VALUES ('imap_app_password', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$password, $password]);
                header('Location: setup_wizard.php?step=3&success=imap');
                exit;
            }
            break;
            
        case 'test_system':
            header('Location: setup_wizard.php?step=4&test=complete');
            exit;
    }
}

// Load current settings
$settings = [];
$settingsQuery = $db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('gmass_api_key', 'imap_email', 'imap_app_password')");
foreach ($settingsQuery as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

$gmassConfigured = !empty($settings['gmass_api_key']);
$imapConfigured = !empty($settings['imap_email']) && !empty($settings['imap_app_password']);
$systemReady = $gmassConfigured && $imapConfigured;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GMass + IMAP Setup Wizard</title>
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .wizard-container {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
        }
        
        .wizard-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 40px;
        }
        
        .step {
            display: flex;
            align-items: center;
            margin: 0 10px;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            margin-right: 10px;
        }
        
        .step.active .step-number {
            background: #3b82f6;
        }
        
        .step.completed .step-number {
            background: #10b981;
        }
        
        .step.pending .step-number {
            background: #9ca3af;
        }
        
        .wizard-content {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #374151;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        
        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #3b82f6;
        }
        
        .help-text {
            font-size: 0.9em;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .status-item {
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
        }
        
        .status-item.success {
            border-color: #10b981;
            background: #ecfdf5;
        }
        
        .status-item.pending {
            border-color: #f59e0b;
            background: #fffbeb;
        }
    </style>
</head>
<body>
    <div class="wizard-container">
        <div class="wizard-header">
            <h1><i class="fas fa-magic"></i> GMass + IMAP Setup Wizard</h1>
            <p>Configure your simplified email automation system in 4 easy steps</p>
        </div>
        
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step <?php echo $step >= 1 ? ($step == 1 ? 'active' : 'completed') : 'pending'; ?>">
                <div class="step-number">1</div>
                <span>GMass API</span>
            </div>
            <div class="step <?php echo $step >= 2 ? ($step == 2 ? 'active' : 'completed') : 'pending'; ?>">
                <div class="step-number">2</div>
                <span>IMAP Setup</span>
            </div>
            <div class="step <?php echo $step >= 3 ? ($step == 3 ? 'active' : 'completed') : 'pending'; ?>">
                <div class="step-number">3</div>
                <span>Testing</span>
            </div>
            <div class="step <?php echo $step >= 4 ? ($step == 4 ? 'active' : 'completed') : 'pending'; ?>">
                <div class="step-number">4</div>
                <span>Complete</span>
            </div>
        </div>

        <div class="wizard-content">
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> Configuration saved successfully!
                </div>
            <?php endif; ?>

            <?php if ($step == '1'): ?>
                <!-- Step 1: GMass API Configuration -->
                <h2><i class="fas fa-rocket"></i> Step 1: GMass API Configuration</h2>
                <p>Configure your GMass API key for email sending.</p>
                
                <div class="alert alert-info">
                    <strong>How to get your GMass API key:</strong><br>
                    1. Go to <a href="https://www.gmass.co/" target="_blank">GMass.co</a><br>
                    2. Login to your account<br>
                    3. Go to Settings → API Keys<br>
                    4. Generate a new API key<br>
                    5. Copy and paste it below
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="save_gmass">
                    
                    <div class="form-group">
                        <label for="gmass_api_key">GMass API Key *</label>
                        <input type="password" 
                               id="gmass_api_key" 
                               name="gmass_api_key" 
                               value="<?php echo htmlspecialchars($settings['gmass_api_key'] ?? ''); ?>"
                               placeholder="Enter your GMass API key"
                               required>
                        <div class="help-text">This key will be used for all outbound email sending</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> Save & Continue
                    </button>
                </form>

            <?php elseif ($step == '2'): ?>
                <!-- Step 2: IMAP Configuration -->
                <h2><i class="fas fa-envelope"></i> Step 2: IMAP Configuration</h2>
                <p>Configure IMAP access for reply monitoring (works with Gmail, Outlook, etc.).</p>
                
                <div class="alert alert-info">
                    <strong>Email Provider Setup:</strong><br>
                    • <strong>Gmail:</strong> Go to <a href="https://myaccount.google.com/security" target="_blank">Google Account Security</a>, enable 2FA, generate App Password<br>
                    • <strong>Outlook:</strong> Use your regular email and password<br>
                    3. Click "App passwords"<br>
                    4. Generate app password for "Mail"<br>
                    5. Copy the 16-character password below
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="save_imap">
                    
                    <div class="form-group">
                        <label for="imap_email">Email Address *</label>
                        <input type="email" 
                               id="imap_email" 
                               name="imap_email" 
                               value="<?php echo htmlspecialchars($settings['imap_email'] ?? ''); ?>"
                               placeholder="your-email@gmail.com or outlook.com"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="imap_password">Email Password *</label>
                        <input type="password" 
                               id="imap_password" 
                               name="imap_password" 
                               value="<?php echo htmlspecialchars($settings['imap_app_password'] ?? ''); ?>"
                               placeholder="App password (Gmail) or regular password"
                               required>
                        <div class="help-text">Gmail: Use App Password | Outlook/others: Use regular password</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> Save & Continue
                    </button>
                    <a href="setup_wizard.php?step=1" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </form>

            <?php elseif ($step == '3'): ?>
                <!-- Step 3: System Testing -->
                <h2><i class="fas fa-test-tube"></i> Step 3: System Testing</h2>
                <p>Test your configuration to ensure everything is working properly.</p>
                
                <div class="status-grid">
                    <div class="status-item <?php echo $gmassConfigured ? 'success' : 'pending'; ?>">
                        <i class="fas fa-rocket fa-2x"></i>
                        <h4>GMass API</h4>
                        <p><?php echo $gmassConfigured ? 'Configured' : 'Not Configured'; ?></p>
                    </div>
                    <div class="status-item <?php echo $imapConfigured ? 'success' : 'pending'; ?>">
                        <i class="fas fa-envelope fa-2x"></i>
                        <h4>IMAP Access</h4>
                        <p><?php echo $imapConfigured ? 'Configured' : 'Not Configured'; ?></p>
                    </div>
                </div>
                
                <?php if ($systemReady): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> Configuration complete! Ready for testing.
                    </div>
                    
                    <div style="text-align: center;">
                        <a href="test_gmass_imap_pipeline.php" class="btn btn-primary" target="_blank">
                            <i class="fas fa-play"></i> Run System Test
                        </a>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="test_system">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-arrow-right"></i> Continue to Completion
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        Please complete the previous configuration steps before testing.
                    </div>
                    <a href="setup_wizard.php?step=<?php echo $gmassConfigured ? '2' : '1'; ?>" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Complete Configuration
                    </a>
                <?php endif; ?>

            <?php elseif ($step == '4'): ?>
                <!-- Step 4: Completion -->
                <h2><i class="fas fa-party-horn"></i> Setup Complete!</h2>
                <p>Your GMass + IMAP email system is now configured and ready to use.</p>
                
                <div class="alert alert-success">
                    <h4><i class="fas fa-check-circle"></i> System Ready!</h4>
                    <p>Your email automation system is configured with GMass + IMAP for powerful outreach campaigns.</p>
                </div>
                
                <h3>🎯 What's Next?</h3>
                <ul>
                    <li><strong>Start Monitoring:</strong> Run <code>php start_imap_monitoring.php</code> to activate reply monitoring</li>
                    <li><strong>Create Campaigns:</strong> Use your existing campaign interface with the new system</li>
                    <li><strong>Monitor Activity:</strong> Check the logs directory for system activity</li>
                    <li><strong>System Status:</strong> Visit system_status.php for health monitoring</li>
                </ul>
                
                <h3>📊 System Benefits</h3>
                <div class="status-grid">
                    <div class="status-item success">
                        <i class="fas fa-key fa-2x"></i>
                        <h4>Simple Auth</h4>
                        <p>No more OAuth complexity</p>
                    </div>
                    <div class="status-item success">
                        <i class="fas fa-sync fa-2x"></i>
                        <h4>Auto Distribution</h4>
                        <p>GMass handles email limits</p>
                    </div>
                    <div class="status-item success">
                        <i class="fas fa-inbox fa-2x"></i>
                        <h4>Real-time Monitoring</h4>
                        <p>Direct IMAP inbox access</p>
                    </div>
                    <div class="status-item success">
                        <i class="fas fa-forward fa-2x"></i>
                        <h4>Auto Lead Forwarding</h4>
                        <p>Positive leads sent instantly</p>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 30px;">
                    <a href="index.php" class="btn btn-success">
                        <i class="fas fa-home"></i> Go to Dashboard
                    </a>
                    <a href="settings_gmass.php" class="btn btn-primary">
                        <i class="fas fa-cog"></i> Manage Settings
                    </a>
                    <a href="start_imap_monitoring.php" class="btn btn-primary">
                        <i class="fas fa-play"></i> Start System
                    </a>
                </div>
                
            <?php endif; ?>
        </div>
    </div>
</body>
</html>