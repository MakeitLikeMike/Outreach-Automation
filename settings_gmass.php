<?php
/**
 * Simplified Settings Panel for GMass + IMAP
 * Replaces complex Gmail OAuth with simple API key + IMAP credentials
 */
require_once 'config/database.php';
require_once 'classes/GMassIntegration.php';
require_once 'includes/navigation.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new Database();
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_gmass_settings':
                // Save GMass API settings
                $apiKey = trim($_POST['gmass_api_key'] ?? '');
                
                if (!empty($apiKey)) {
                    $db->execute("INSERT INTO system_settings (setting_key, setting_value) VALUES ('gmass_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$apiKey, $apiKey]);
                    $successMessage = "GMass API key saved successfully!";
                } else {
                    $errorMessage = "GMass API key cannot be empty.";
                }
                break;
                
            case 'save_imap_settings':
                // Save IMAP settings
                $imapEmail = trim($_POST['imap_email'] ?? '');
                $imapPassword = trim($_POST['imap_app_password'] ?? '');
                $imapHost = trim($_POST['imap_host'] ?? 'imap.gmail.com');
                $imapPort = trim($_POST['imap_port'] ?? '993');
                
                if (!empty($imapEmail) && !empty($imapPassword)) {
                    $db->execute("INSERT INTO system_settings (setting_key, setting_value) VALUES ('imap_email', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$imapEmail, $imapEmail]);
                    $db->execute("INSERT INTO system_settings (setting_key, setting_value) VALUES ('imap_app_password', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$imapPassword, $imapPassword]);
                    $db->execute("INSERT INTO system_settings (setting_key, setting_value) VALUES ('imap_host', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$imapHost, $imapHost]);
                    $db->execute("INSERT INTO system_settings (setting_key, setting_value) VALUES ('imap_port', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$imapPort, $imapPort]);
                    
                    $successMessage = "IMAP settings saved successfully!";
                } else {
                    $errorMessage = "Email and App Password are required.";
                }
                break;
                
            case 'test_gmass':
                // Test GMass API connection
                $gmass = new GMassIntegration();
                $testResult = $gmass->testConnection();
                
                if ($testResult['success']) {
                    $successMessage = "GMass API test successful!";
                } else {
                    $errorMessage = "GMass API test failed: " . $testResult['error'];
                }
                break;
                
            case 'test_imap':
                // Test IMAP connection
                $imapEmail = $_POST['imap_email'] ?? '';
                $imapPassword = $_POST['imap_app_password'] ?? '';
                
                if (!empty($imapEmail) && !empty($imapPassword)) {
                    try {
                        $connection = imap_open('{imap.gmail.com:993/imap/ssl}INBOX', $imapEmail, $imapPassword);
                        if ($connection) {
                            imap_close($connection);
                            $successMessage = "IMAP connection test successful!";
                        } else {
                            $errorMessage = "IMAP connection failed: " . imap_last_error();
                        }
                    } catch (Exception $e) {
                        $errorMessage = "IMAP test error: " . $e->getMessage();
                    }
                } else {
                    $errorMessage = "Email and password required for IMAP test.";
                }
                break;
        }
    }
}

// Load current settings
$db = new Database();
$results = $db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('gmass_api_key', 'imap_email', 'imap_app_password', 'imap_host', 'imap_port')");

$settings = [];
foreach ($results as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Check API status
$gmassConfigured = !empty($settings['gmass_api_key']);
$imapConfigured = !empty($settings['imap_email']) && !empty($settings['imap_app_password']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email System Settings - GMass + IMAP</title>
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .settings-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .settings-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .status-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .status-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        
        .status-icon {
            font-size: 3em;
            margin-bottom: 15px;
        }
        
        .status-title {
            font-size: 1.2em;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: bold;
        }
        
        .status-badge.success {
            background: rgba(34, 197, 94, 0.2);
            color: #16a34a;
        }
        
        .status-badge.error {
            background: rgba(239, 68, 68, 0.2);
            color: #dc2626;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
        }
        
        .form-section {
            background: #f8fafc;
            padding: 25px;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
        }
        
        .form-group {
            margin-bottom: 20px;
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
            transition: border-color 0.3s;
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
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
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
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        
        .help-text {
            font-size: 0.9em;
            color: #6b7280;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="settings-container">
        <h1><i class="fas fa-cog"></i> Email System Configuration</h1>
        <p class="text-muted">Simple GMass + IMAP setup - No OAuth complexity!</p>
        
        <?php if (isset($successMessage)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>
        
        <!-- System Status Overview -->
        <div class="status-overview">
            <div class="status-card">
                <div class="status-icon">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="status-title">GMass Email Sending</div>
                <div class="status-badge <?php echo $gmassConfigured ? 'success' : 'error'; ?>">
                    <?php echo $gmassConfigured ? 'Configured' : 'Not Configured'; ?>
                </div>
            </div>
            
            <div class="status-card">
                <div class="status-icon">
                    <i class="fas fa-inbox"></i>
                </div>
                <div class="status-title">IMAP Reply Monitoring</div>
                <div class="status-badge <?php echo $imapConfigured ? 'success' : 'error'; ?>">
                    <?php echo $imapConfigured ? 'Configured' : 'Not Configured'; ?>
                </div>
            </div>
        </div>
        
        <!-- Configuration Forms -->
        <div class="form-grid">
            <!-- GMass Configuration -->
            <div class="settings-card">
                <div class="form-section">
                    <h3><i class="fas fa-rocket"></i> GMass API Configuration</h3>
                    <p class="help-text">Get your API key from GMass Dashboard → Settings → API Keys</p>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="save_gmass_settings">
                        
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
                            <i class="fas fa-save"></i> Save GMass Settings
                        </button>
                    </form>
                    
                    <?php if ($gmassConfigured): ?>
                        <form method="POST" style="margin-top: 15px;">
                            <input type="hidden" name="action" value="test_gmass">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-test-tube"></i> Test GMass Connection
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- IMAP Configuration -->
            <div class="settings-card">
                <div class="form-section">
                    <h3><i class="fas fa-envelope-open"></i> IMAP Reply Monitoring</h3>
                    <p class="help-text">Configure Gmail IMAP access for automatic reply processing</p>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="save_imap_settings">
                        
                        <div class="form-group">
                            <label for="imap_email">Gmail Address *</label>
                            <input type="email" 
                                   id="imap_email" 
                                   name="imap_email" 
                                   value="<?php echo htmlspecialchars($settings['imap_email'] ?? ''); ?>"
                                   placeholder="your-email@gmail.com"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="imap_app_password">Gmail App Password *</label>
                            <input type="password" 
                                   id="imap_app_password" 
                                   name="imap_app_password" 
                                   value="<?php echo htmlspecialchars($settings['imap_app_password'] ?? ''); ?>"
                                   placeholder="Enter 16-character app password"
                                   required>
                            <div class="help-text">
                                <a href="https://support.google.com/accounts/answer/185833" target="_blank">
                                    <i class="fas fa-external-link-alt"></i> How to generate Gmail App Password
                                </a>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="imap_host">IMAP Host</label>
                            <input type="text" 
                                   id="imap_host" 
                                   name="imap_host" 
                                   value="<?php echo htmlspecialchars($settings['imap_host'] ?? 'imap.gmail.com'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="imap_port">IMAP Port</label>
                            <input type="number" 
                                   id="imap_port" 
                                   name="imap_port" 
                                   value="<?php echo htmlspecialchars($settings['imap_port'] ?? '993'); ?>">
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save IMAP Settings
                        </button>
                    </form>
                    
                    <?php if ($imapConfigured): ?>
                        <form method="POST" style="margin-top: 15px;">
                            <input type="hidden" name="action" value="test_imap">
                            <input type="hidden" name="imap_email" value="<?php echo htmlspecialchars($settings['imap_email'] ?? ''); ?>">
                            <input type="hidden" name="imap_app_password" value="<?php echo htmlspecialchars($settings['imap_app_password'] ?? ''); ?>">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-test-tube"></i> Test IMAP Connection
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Migration Info -->
        <div class="settings-card">
            <h3><i class="fas fa-info-circle"></i> Migration Information</h3>
            <div style="background: #eff6ff; padding: 20px; border-radius: 8px; border-left: 4px solid #3b82f6;">
                <h4>✅ Benefits of GMass + IMAP Setup:</h4>
                <ul>
                    <li><strong>Simple Authentication:</strong> Just API key + app password (no OAuth complexity)</li>
                    <li><strong>Better Reliability:</strong> No token expiration issues</li>
                    <li><strong>Higher Limits:</strong> GMass automatically handles Gmail limits</li>
                    <li><strong>Auto-Distribution:</strong> Large campaigns spread over multiple days</li>
                    <li><strong>Direct Inbox Access:</strong> Real-time reply monitoring via IMAP</li>
                </ul>
                
                <div style="margin-top: 20px;">
                    <a href="settings.php" class="btn btn-warning">
                        <i class="fas fa-arrow-left"></i> Back to Legacy Settings
                    </a>
                    
                    <?php if ($gmassConfigured && $imapConfigured): ?>
                        <button class="btn btn-success" onclick="alert('System ready! All campaigns will now use GMass + IMAP.')">
                            <i class="fas fa-check"></i> Migration Complete
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/js/main.js"></script>
</body>
</html>