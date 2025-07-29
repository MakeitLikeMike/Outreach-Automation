<?php
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

// Default values
$defaults = [
    'dataforseo_api_key' => '',
    'tomba_api_key' => '',
    'tomba_secret' => '',
    'gmail_credentials' => '',
    'sender_name' => 'Outreach Team',
    'sender_email' => '',
    'rotation_emails' => '',
    'rotation_mode' => 'sequential',
    'emails_per_sender_per_day' => '20',
    'enable_rotation' => 'no',
    'cooldown_hours' => '24',
    'min_domain_rating' => '30',
    'min_organic_traffic' => '5000',
    'min_referring_domains' => '100',
    'min_ranking_keywords' => '100',
    'email_delay_minutes' => '60',
    'daily_email_limit' => '50'
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
    <title>Settings - Outreach Automation</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-envelope"></i> Outreach Automation</h2>
        </div>
        <ul class="nav-menu">
            <li><a href="index.php"><i class="fas fa-dashboard"></i> Dashboard</a></li>
            <li><a href="campaigns.php"><i class="fas fa-bullhorn"></i> Campaigns</a></li>
            <li><a href="domains.php"><i class="fas fa-globe"></i> Domain Analysis</a></li>
            <li><a href="templates.php"><i class="fas fa-file-text"></i> Email Templates</a></li>
            <li><a href="monitoring.php"><i class="fas fa-chart-line"></i> Monitoring</a></li>
            <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-header">
            <h1>System Settings</h1>
        </header>

        <div style="padding: 2rem;">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="settings.php">
                <!-- API Configuration -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3><i class="fas fa-key"></i> API Configuration</h3>
                    </div>
                    <div class="card-body">
                        <div class="settings-grid">
                            <div class="form-group">
                                <label for="dataforseo_api_key">DataForSEO API Key</label>
                                <input type="password" id="dataforseo_api_key" name="setting_dataforseo_api_key" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['dataforseo_api_key']); ?>"
                                       placeholder="Enter your DataForSEO API key">
                                <small class="help-text">Used for backlink analysis and domain metrics</small>
                            </div>

                            <div class="form-group">
                                <label for="tomba_api_key">Tomba.io API Key</label>
                                <input type="password" id="tomba_api_key" name="setting_tomba_api_key" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['tomba_api_key']); ?>"
                                       placeholder="Enter your Tomba.io API key">
                                <small class="help-text">Used for finding email addresses</small>
                            </div>

                            <div class="form-group">
                                <label for="tomba_secret">Tomba.io Secret</label>
                                <input type="password" id="tomba_secret" name="setting_tomba_secret" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['tomba_secret'] ?? ''); ?>"
                                       placeholder="Enter your Tomba.io secret">
                                <small class="help-text">Required for Tomba.io authentication</small>
                            </div>

                            <div class="form-group">
                                <label for="gmail_credentials">Gmail API Credentials (JSON)</label>
                                <textarea id="gmail_credentials" name="setting_gmail_credentials" 
                                       class="form-control textarea" 
                                       placeholder="Paste your Gmail API credentials JSON here"><?php echo htmlspecialchars($settings['gmail_credentials'] ?? ''); ?></textarea>
                                <small class="help-text">Upload your Gmail API service account credentials JSON</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Email Configuration -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3><i class="fas fa-envelope"></i> Email Configuration</h3>
                    </div>
                    <div class="card-body">
                        <div class="settings-grid">
                            <div class="form-group">
                                <label for="sender_name">Default Sender Name</label>
                                <input type="text" id="sender_name" name="setting_sender_name" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['sender_name']); ?>"
                                       placeholder="Your Name or Company">
                                <small class="help-text">Will be used in email templates as {SENDER_NAME}</small>
                            </div>

                            <div class="form-group">
                                <label for="sender_email">Default Sender Email</label>
                                <input type="email" id="sender_email" name="setting_sender_email" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['sender_email']); ?>"
                                       placeholder="your-email@yourdomain.com">
                                <small class="help-text">Your Gmail address for sending emails</small>
                            </div>

                            <div class="form-group">
                                <label for="email_delay_minutes">Email Delay (Minutes)</label>
                                <input type="number" id="email_delay_minutes" name="setting_email_delay_minutes" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['email_delay_minutes'] ?? '60'); ?>"
                                       min="1" max="1440">
                                <small class="help-text">Delay between sending emails to avoid spam filters</small>
                            </div>

                            <div class="form-group">
                                <label for="daily_email_limit">Daily Email Limit</label>
                                <input type="number" id="daily_email_limit" name="setting_daily_email_limit" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['daily_email_limit'] ?? '50'); ?>"
                                       min="1" max="1000">
                                <small class="help-text">Maximum emails to send per day</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sender Email Rotation -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3><i class="fas fa-sync-alt"></i> Sender Email Rotation</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-box mb-3">
                            <h4><i class="fas fa-info-circle"></i> Email Rotation System</h4>
                            <p>Add multiple sender emails to rotate and avoid account restrictions. The system will automatically rotate between these emails when sending outreach campaigns. Maximum 100 emails allowed.</p>
                        </div>

                        <div class="form-group">
                            <label for="rotation_emails">Sender Email Addresses</label>
                            <textarea id="rotation_emails" name="setting_rotation_emails" 
                                     class="form-control textarea" rows="10"
                                     placeholder="Enter email addresses (one per line)&#10;sender1@yourdomain.com&#10;sender2@yourdomain.com&#10;sender3@yourdomain.com"><?php echo htmlspecialchars($settings['rotation_emails'] ?? ''); ?></textarea>
                            <small class="help-text">Enter up to 100 email addresses, one per line. System will rotate between these for outreach campaigns.</small>
                        </div>

                        <div class="settings-grid">
                            <div class="form-group">
                                <label for="rotation_mode">Rotation Mode</label>
                                <select id="rotation_mode" name="setting_rotation_mode" class="form-control">
                                    <option value="sequential" <?php echo ($settings['rotation_mode'] ?? 'sequential') === 'sequential' ? 'selected' : ''; ?>>Sequential</option>
                                    <option value="random" <?php echo ($settings['rotation_mode'] ?? 'sequential') === 'random' ? 'selected' : ''; ?>>Random</option>
                                    <option value="balanced" <?php echo ($settings['rotation_mode'] ?? 'sequential') === 'balanced' ? 'selected' : ''; ?>>Balanced Load</option>
                                </select>
                                <small class="help-text">How to select sender emails from the rotation list</small>
                            </div>

                            <div class="form-group">
                                <label for="emails_per_sender_per_day">Emails per Sender per Day</label>
                                <input type="number" id="emails_per_sender_per_day" name="setting_emails_per_sender_per_day" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['emails_per_sender_per_day'] ?? '20'); ?>"
                                       min="1" max="100">
                                <small class="help-text">Maximum emails each sender can send per day</small>
                            </div>

                            <div class="form-group">
                                <label for="enable_rotation">Enable Rotation</label>
                                <select id="enable_rotation" name="setting_enable_rotation" class="form-control">
                                    <option value="yes" <?php echo ($settings['enable_rotation'] ?? 'no') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="no" <?php echo ($settings['enable_rotation'] ?? 'no') === 'no' ? 'selected' : ''; ?>>No</option>
                                </select>
                                <small class="help-text">Enable/disable email rotation system</small>
                            </div>

                            <div class="form-group">
                                <label for="cooldown_hours">Sender Cooldown (Hours)</label>
                                <input type="number" id="cooldown_hours" name="setting_cooldown_hours" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['cooldown_hours'] ?? '24'); ?>"
                                       min="1" max="168">
                                <small class="help-text">Hours to wait before reusing a sender email</small>
                            </div>
                        </div>

                        <div class="rotation-stats mt-3" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div class="stat-item">
                                <div class="stat-value" id="total-sender-emails">0</div>
                                <div class="stat-label">Total Sender Emails</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value" id="active-sender-emails">0</div>
                                <div class="stat-label">Active Emails</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value" id="daily-capacity">0</div>
                                <div class="stat-label">Daily Capacity</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Domain Quality Filters -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3><i class="fas fa-filter"></i> Domain Quality Filters</h3>
                    </div>
                    <div class="card-body">
                        <div class="settings-grid">
                            <div class="form-group">
                                <label for="min_domain_rating">Minimum Domain Rating</label>
                                <input type="number" id="min_domain_rating" name="setting_min_domain_rating" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['min_domain_rating']); ?>"
                                       min="0" max="100">
                                <small class="help-text">Minimum DR score to consider a domain (0-100)</small>
                            </div>

                            <div class="form-group">
                                <label for="min_organic_traffic">Minimum Organic Traffic</label>
                                <input type="number" id="min_organic_traffic" name="setting_min_organic_traffic" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['min_organic_traffic']); ?>"
                                       min="0">
                                <small class="help-text">Minimum monthly organic traffic</small>
                            </div>

                            <div class="form-group">
                                <label for="min_referring_domains">Minimum Referring Domains</label>
                                <input type="number" id="min_referring_domains" name="setting_min_referring_domains" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['min_referring_domains']); ?>"
                                       min="0">
                                <small class="help-text">Minimum number of referring domains</small>
                            </div>

                            <div class="form-group">
                                <label for="min_ranking_keywords">Minimum Ranking Keywords</label>
                                <input type="number" id="min_ranking_keywords" name="setting_min_ranking_keywords" 
                                       class="form-control" value="<?php echo htmlspecialchars($settings['min_ranking_keywords'] ?? '100'); ?>"
                                       min="0">
                                <small class="help-text">Minimum number of ranking keywords</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Information -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3><i class="fas fa-info-circle"></i> System Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <strong>PHP Version:</strong>
                                <span><?php echo phpversion(); ?></span>
                            </div>
                            <div class="info-item">
                                <strong>MySQL Connection:</strong>
                                <span class="status-badge status-success">
                                    <i class="fas fa-check"></i> Connected
                                </span>
                            </div>
                            <div class="info-item">
                                <strong>cURL Support:</strong>
                                <span class="status-badge <?php echo function_exists('curl_version') ? 'status-success' : 'status-error'; ?>">
                                    <?php if (function_exists('curl_version')): ?>
                                        <i class="fas fa-check"></i> Available
                                    <?php else: ?>
                                        <i class="fas fa-times"></i> Not Available
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <strong>System Time:</strong>
                                <span><?php echo date('Y-m-d H:i:s T'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- API Testing -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3><i class="fas fa-vial"></i> API Connection Testing</h3>
                    </div>
                    <div class="card-body">
                        <div class="test-grid">
                            <div class="test-item">
                                <div class="test-info">
                                    <strong>DataForSEO API</strong>
                                    <p>Test connection to DataForSEO API</p>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="testApi('DataForSEO')">
                                    <i class="fas fa-flask"></i> Test
                                </button>
                            </div>
                            <div class="test-item">
                                <div class="test-info">
                                    <strong>Tomba.io API</strong>
                                    <p>Test connection to Tomba.io API</p>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="testApi('Tomba')">
                                    <i class="fas fa-flask"></i> Test
                                </button>
                            </div>
                            <div class="test-item">
                                <div class="test-info">
                                    <strong>Gmail API</strong>
                                    <p>Test connection to Gmail API</p>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="testApi('Gmail')">
                                    <i class="fas fa-flask"></i> Test
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="location.reload()">
                        <i class="fas fa-undo"></i> Reset Changes
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script>
        function testApi(service) {
            const button = event.target.closest('button');
            const originalHTML = button.innerHTML;
            const originalClass = button.className;
            
            showLoading(button);
            
            // Make actual API test call
            fetch('test_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ service: service })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Success state - change to green
                    button.className = originalClass.replace('btn-secondary', 'btn-success');
                    button.innerHTML = '<i class="fas fa-check-circle"></i> Connected';
                    showToast(`${service} API: ${data.message}`, 'success');
                } else {
                    // Failure state - change to red
                    button.className = originalClass.replace('btn-secondary', 'btn-danger');
                    button.innerHTML = '<i class="fas fa-times-circle"></i> Failed';
                    showToast(`${service} API: ${data.message}`, 'error');
                }
                
                // Reset after 5 seconds
                setTimeout(() => {
                    button.className = originalClass;
                    button.innerHTML = originalHTML;
                }, 5000);
            })
            .catch(error => {
                // Error state - change to red
                button.className = originalClass.replace('btn-secondary', 'btn-danger');
                button.innerHTML = '<i class="fas fa-times-circle"></i> Error';
                showToast(`${service} API: Connection error`, 'error');
                
                // Reset after 5 seconds
                setTimeout(() => {
                    button.className = originalClass;
                    button.innerHTML = originalHTML;
                }, 5000);
            });
        }

        // Calculate rotation stats
        function updateRotationStats() {
            const rotationEmails = document.getElementById('rotation_emails').value;
            const emailsPerSender = parseInt(document.getElementById('emails_per_sender_per_day').value) || 20;
            const enableRotation = document.getElementById('enable_rotation').value === 'yes';
            
            const emailLines = rotationEmails.split('\n').filter(line => {
                const email = line.trim();
                return email && email.includes('@') && email.includes('.');
            });
            
            const totalEmails = emailLines.length;
            const activeEmails = enableRotation ? totalEmails : (totalEmails > 0 ? 1 : 0);
            const dailyCapacity = activeEmails * emailsPerSender;
            
            document.getElementById('total-sender-emails').textContent = totalEmails;
            document.getElementById('active-sender-emails').textContent = activeEmails;
            document.getElementById('daily-capacity').textContent = dailyCapacity;
            
            // Limit to 100 emails
            if (totalEmails > 100) {
                document.getElementById('rotation_emails').style.borderColor = '#ef4444';
                showToast('Maximum 100 sender emails allowed', 'error');
            } else {
                document.getElementById('rotation_emails').style.borderColor = '#e2e8f0';
            }
        }
        
        // Update stats on input change
        document.getElementById('rotation_emails').addEventListener('input', updateRotationStats);
        document.getElementById('emails_per_sender_per_day').addEventListener('input', updateRotationStats);
        document.getElementById('enable_rotation').addEventListener('change', updateRotationStats);
        
        // Initial calculation
        updateRotationStats();

        // Toggle password visibility
        document.querySelectorAll('input[type="password"]').forEach(input => {
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
            toggleBtn.className = 'password-toggle';
            toggleBtn.style.cssText = `
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                border: none;
                background: none;
                color: #64748b;
                cursor: pointer;
            `;
            
            input.parentNode.style.position = 'relative';
            input.parentNode.appendChild(toggleBtn);
            
            toggleBtn.addEventListener('click', () => {
                if (input.type === 'password') {
                    input.type = 'text';
                    toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    input.type = 'password';
                    toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });
        });
    </script>
</body>
</html>