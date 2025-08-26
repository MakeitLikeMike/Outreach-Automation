<?php
require_once 'auth.php';
$auth->requireAuth();

require_once 'config/database.php';

$db = new Database();
$message = '';
$error = '';

if ($_POST) {
    try {
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'setting_') === 0) {
                $setting_key = str_replace('setting_', '', $key);
                
                $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
                $db->execute($sql, [$setting_key, $value]);
            }
        }
        $message = "Settings updated successfully!";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get current settings
$settings = [];
$results = $db->fetchAll("SELECT setting_key, setting_value FROM system_settings");
foreach ($results as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// User-friendly settings defaults
$defaults = [
    // Email & Outreach Settings
    'gmass_api_key' => '',
    'gmass_from_email' => '',
    'gmass_from_name' => 'Outreach Team',
    'imap_email' => '',
    'imap_password' => '',
    'imap_host' => 'imap.gmail.com',
    'imap_port' => '993',
    
    // Campaign Settings
    'daily_email_limit' => '50',
    'email_delay_minutes' => '60',
    'cooldown_hours' => '24',
    'emails_per_sender_per_day' => '20'
];

foreach ($defaults as $key => $default) {
    if (!isset($settings[$key])) {
        $settings[$key] = $default;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Auto Outreach System</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin-pages.css">
 
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navigation.php'; ?>
    
    <main class="main-content">
        <header class="top-header">
            <h1><i class="fas fa-cog"></i> System Settings</h1>
            <p>Configure your email outreach and campaign preferences</p>
        </header>
        
        <div class="admin-content-wrapper">
        <?php if ($message): ?>
            <div class="success-box">
                <i class="fas fa-check-circle"></i>
                <div>
                    <strong>Success!</strong><br>
                    <?= htmlspecialchars($message) ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="warning-box">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Error!</strong><br>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <!-- Email & GMass Configuration -->
            <div class="content-section">
                <div class="section-header">
                    <div class="icon icon-blue">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <h3>Email & GMass Configuration</h3>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>GMass Integration:</strong> Configure your GMass API and email settings for automated outreach campaigns. IMAP settings are used for monitoring replies and tracking responses.
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="gmass_api_key">
                            <i class="fas fa-key"></i>
                            GMass API Key
                        </label>
                        <div class="password-input-wrapper">
                            <input type="password" 
                                   id="gmass_api_key" 
                                   name="setting_gmass_api_key" 
                                   value="<?= htmlspecialchars($settings['gmass_api_key']) ?>" 
                                   placeholder="Enter your GMass API key"
                                   class="form-input">
                            <button type="button" class="password-toggle" onclick="togglePassword('gmass_api_key')">
                                <i class="fas fa-eye" id="gmass_api_key-icon"></i>
                            </button>
                        </div>
                        <div class="form-help">Your GMass API key for sending campaigns</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="gmass_from_email">
                            <i class="fas fa-at"></i>
                            From Email Address
                        </label>
                        <input type="email" 
                               id="gmass_from_email" 
                               name="setting_gmass_from_email" 
                               value="<?= htmlspecialchars($settings['gmass_from_email']) ?>" 
                               placeholder="your-email@domain.com"
                               class="form-input">
                        <div class="form-help">The email address campaigns will be sent from</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="gmass_from_name">
                            <i class="fas fa-user"></i>
                            From Name
                        </label>
                        <input type="text" 
                               id="gmass_from_name" 
                               name="setting_gmass_from_name" 
                               value="<?= htmlspecialchars($settings['gmass_from_name']) ?>" 
                               placeholder="Your Name or Company"
                               class="form-input">
                        <div class="form-help">The sender name that recipients will see</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="imap_email">
                            <i class="fas fa-inbox"></i>
                            IMAP Email (Reply Monitoring)
                        </label>
                        <input type="email" 
                               id="imap_email" 
                               name="setting_imap_email" 
                               value="<?= htmlspecialchars($settings['imap_email']) ?>" 
                               placeholder="your-email@domain.com"
                               class="form-input">
                        <div class="form-help">Email account for monitoring replies</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="imap_password">
                            <i class="fas fa-lock"></i>
                            IMAP Password/App Password
                        </label>
                        <div class="password-input-wrapper">
                            <input type="password" 
                                   id="imap_password" 
                                   name="setting_imap_password" 
                                   value="<?= htmlspecialchars($settings['imap_password']) ?>" 
                                   placeholder="App password for Gmail"
                                   class="form-input">
                            <button type="button" class="password-toggle" onclick="togglePassword('imap_password')">
                                <i class="fas fa-eye" id="imap_password-icon"></i>
                            </button>
                        </div>
                        <div class="form-help">Use App Password for Gmail accounts</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="imap_host">
                            <i class="fas fa-server"></i>
                            IMAP Host
                        </label>
                        <input type="text" 
                               id="imap_host" 
                               name="setting_imap_host" 
                               value="<?= htmlspecialchars($settings['imap_host']) ?>" 
                               placeholder="imap.gmail.com"
                               class="form-input">
                        <div class="form-help">IMAP server hostname</div>
                    </div>
                </div>
            </div>
            
            <!-- Campaign & Sending Limits -->
            <div class="content-section">
                <div class="section-header">
                    <div class="icon icon-orange">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <h3>Campaign & Sending Limits</h3>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-shield-alt"></i>
                    <div>
                        <strong>Deliverability Protection:</strong> Control email sending pace and limits to maintain good sender reputation and avoid being flagged as spam by email providers.
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="daily_email_limit">
                            <i class="fas fa-calendar-day"></i>
                            Daily Email Limit
                        </label>
                        <input type="number" 
                               id="daily_email_limit" 
                               name="setting_daily_email_limit" 
                               value="<?= htmlspecialchars($settings['daily_email_limit']) ?>" 
                               min="1" max="200" 
                               placeholder="50"
                               class="form-input">
                        <div class="form-help">Maximum emails to send per day (recommended: 50-100)</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email_delay_minutes">
                            <i class="fas fa-clock"></i>
                            Delay Between Emails (minutes)
                        </label>
                        <input type="number" 
                               id="email_delay_minutes" 
                               name="setting_email_delay_minutes" 
                               value="<?= htmlspecialchars($settings['email_delay_minutes']) ?>" 
                               min="1" max="1440" 
                               placeholder="60"
                               class="form-input">
                        <div class="form-help">Wait time between each email (recommended: 30-120 minutes)</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="cooldown_hours">
                            <i class="fas fa-pause-circle"></i>
                            Campaign Cooldown (hours)
                        </label>
                        <input type="number" 
                               id="cooldown_hours" 
                               name="setting_cooldown_hours" 
                               value="<?= htmlspecialchars($settings['cooldown_hours']) ?>" 
                               min="1" max="168" 
                               placeholder="24"
                               class="form-input">
                        <div class="form-help">Wait time between campaigns (recommended: 24+ hours)</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="emails_per_sender_per_day">
                            <i class="fas fa-user-clock"></i>
                            Emails per Sender per Day
                        </label>
                        <input type="number" 
                               id="emails_per_sender_per_day" 
                               name="setting_emails_per_sender_per_day" 
                               value="<?= htmlspecialchars($settings['emails_per_sender_per_day']) ?>" 
                               min="1" max="100" 
                               placeholder="20"
                               class="form-input">
                        <div class="form-help">Limit per individual sender account</div>
                    </div>
                </div>
            </div>
            
            <!-- Save Settings -->
            <div class="action-section">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i>
                    Save Settings
                </button>
                <p style="margin-top: 1rem; color: #6b7280; font-size: 0.95rem;">
                    <i class="fas fa-info-circle"></i>
                    Changes will take effect immediately for new campaigns
                </p>
            </div>
        </form>
        </div>
    </main>

    <style>
    .password-input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    
    .password-input-wrapper .form-input {
        padding-right: 2.5rem;
        flex: 1;
    }
    
    .password-toggle {
        position: absolute;
        right: 0.75rem;
        background: none;
        border: none;
        color: #6b7280;
        cursor: pointer;
        padding: 0.25rem;
        border-radius: 0.25rem;
        transition: all 0.2s ease;
        z-index: 10;
    }
    
    .password-toggle:hover {
        color: #374151;
        background-color: #f3f4f6;
    }
    
    .password-toggle i {
        font-size: 0.875rem;
    }
    </style>

    <script>
    function togglePassword(fieldId) {
        const passwordInput = document.getElementById(fieldId);
        const toggleIcon = document.getElementById(fieldId + '-icon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.className = 'fas fa-eye-slash';
        } else {
            passwordInput.type = 'password';
            toggleIcon.className = 'fas fa-eye';
        }
    }
    </script>
</body>
</html>