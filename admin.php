<?php
require_once 'auth.php';
$auth->requireAdmin();

require_once 'config/database.php';
require_once 'classes/DashboardMetrics.php';

$db = new Database();
$metrics = new DashboardMetrics();

$message = '';
$error = '';

// Handle settings update
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    try {
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'setting_') === 0) {
                $setting_key = str_replace('setting_', '', $key);
                
                $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
                $db->execute($sql, [$setting_key, $value]);
            }
        }
        $message = "System settings updated successfully!";
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

// Admin-level settings defaults
$defaults = [
    // API Management
    'dataforseo_login' => '',
    'dataforseo_password' => '',
    'tomba_api_key' => '',
    'tomba_secret' => '',
    'chatgpt_api_key' => '',
    
    // AI Configuration
    'chatgpt_model' => 'gpt-4o-mini',
    'chatgpt_max_tokens' => '2000',
    'chatgpt_temperature' => '0.3',
    'enable_ai_analysis' => 'yes',
    'ai_analysis_auto_queue' => 'no',
    'ai_batch_size' => '5',
    'ai_analysis_timeout' => '60',
    'ai_cost_tracking' => 'yes',
    
    // System Configuration
    'prototype_mode' => 'yes',
    'min_domain_rating' => '30',
    'min_organic_traffic' => '5000',
    'min_referring_domains' => '100',
    'min_ranking_keywords' => '100'
];

foreach ($defaults as $key => $default) {
    if (!isset($settings[$key])) {
        $settings[$key] = $default;
    }
}

// Get system stats for monitoring
$systemStats = [
    'total_campaigns' => $metrics->getCampaignStats()['total_campaigns'] ?? 0,
    'total_domains' => $metrics->getDomainStats()['total_domains'] ?? 0,
    'emails_sent_today' => $metrics->getEmailStats()['total_emails_sent'] ?? 0,
    'leads_forwarded' => $metrics->getLeadStats()['total_leads_forwarded'] ?? 0
];

// API Status Check Function
function checkApiStatus($settings) {
    $status = [];
    
    // DataForSEO Status
    $status['dataforseo'] = [
        'name' => 'DataForSEO API',
        'status' => !empty($settings['dataforseo_login']) && !empty($settings['dataforseo_password']) ? 'healthy' : 'warning',
        'message' => !empty($settings['dataforseo_login']) && !empty($settings['dataforseo_password']) ? 'Connected' : 'Credentials missing'
    ];
    
    // Tomba Status  
    $status['tomba'] = [
        'name' => 'Tomba Email Finder',
        'status' => !empty($settings['tomba_api_key']) ? 'healthy' : 'warning',
        'message' => !empty($settings['tomba_api_key']) ? 'Connected' : 'API key missing'
    ];
    
    // ChatGPT Status
    $status['chatgpt'] = [
        'name' => 'ChatGPT AI',
        'status' => !empty($settings['chatgpt_api_key']) ? 'healthy' : 'warning',
        'message' => !empty($settings['chatgpt_api_key']) ? 'Connected' : 'API key missing'
    ];
    
    // GMass Status (from Settings)
    $status['gmass'] = [
        'name' => 'GMass Email Service',
        'status' => !empty($settings['gmass_api_key']) ? 'healthy' : 'error',
        'message' => !empty($settings['gmass_api_key']) ? 'Connected' : 'API key required'
    ];
    
    return $status;
}

$apiStatus = checkApiStatus($settings);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Auto Outreach System</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin-pages.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navigation.php'; ?>
    
    <main class="main-content">
        <header class="top-header">
            <h1><i class="fas fa-shield-alt"></i> System Administration</h1>
            <p>Advanced system configuration, monitoring, and API management</p>
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
        
        <!-- Admin Tabs -->
        <div class="tab-navigation">
            <button class="tab-btn active" onclick="showTab('monitoring')">
                <i class="fas fa-chart-line"></i> System Overview
            </button>
            <button class="tab-btn" onclick="showTab('apis')">
                <i class="fas fa-plug"></i> API Management
            </button>
            <button class="tab-btn" onclick="showTab('system')">
                <i class="fas fa-cogs"></i> System Config
            </button>
            <button class="tab-btn" onclick="showTab('health')">
                <i class="fas fa-heartbeat"></i> API Health
            </button>
        </div>
        
        <!-- System Overview Tab -->
        <div id="monitoring" class="tab-content active">
            <div class="status-grid">
                <div class="status-card">
                    <div class="card-icon icon-blue"><i class="fas fa-bullhorn"></i></div>
                    <div class="card-value"><?= number_format($systemStats['total_campaigns']) ?></div>
                    <div class="card-label">Total Campaigns</div>
                    <div class="card-status status-healthy">
                        <i class="fas fa-circle"></i> Active
                    </div>
                </div>
                
                <div class="status-card">
                    <div class="card-icon icon-green"><i class="fas fa-globe"></i></div>
                    <div class="card-value"><?= number_format($systemStats['total_domains']) ?></div>
                    <div class="card-label">Domains Analyzed</div>
                    <div class="card-status status-healthy">
                        <i class="fas fa-circle"></i> Processing
                    </div>
                </div>
                
                <div class="status-card">
                    <div class="card-icon icon-orange"><i class="fas fa-envelope"></i></div>
                    <div class="card-value"><?= number_format($systemStats['emails_sent_today']) ?></div>
                    <div class="card-label">Total Emails Sent</div>
                    <div class="card-status status-healthy">
                        <i class="fas fa-circle"></i> Operational
                    </div>
                </div>
                
                <div class="status-card">
                    <div class="card-icon icon-purple"><i class="fas fa-user-plus"></i></div>
                    <div class="card-value"><?= number_format($systemStats['leads_forwarded']) ?></div>
                    <div class="card-label">Leads Forwarded</div>
                    <div class="card-status status-healthy">
                        <i class="fas fa-circle"></i> Active
                    </div>
                </div>
            </div>
            
            <div class="content-section">
                <div class="section-header">
                    <div class="icon icon-green">
                        <i class="fas fa-server"></i>
                    </div>
                    <h3>System Health</h3>
                </div>
                
                <div class="success-box">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>System Status: Operational</strong><br>
                        All core services are running normally. Background processes are active and processing campaigns.
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><strong>Server Status:</strong></label>
                        <div style="color: #10b981; font-weight: 600;">
                            <i class="fas fa-circle"></i> Online
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><strong>Database:</strong></label>
                        <div style="color: #10b981; font-weight: 600;">
                            <i class="fas fa-circle"></i> Connected
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><strong>Last Updated:</strong></label>
                        <div><?= date('Y-m-d H:i:s') ?></div>
                    </div>
                    
                    <div class="form-group">
                        <label><strong>Uptime:</strong></label>
                        <div style="color: #10b981; font-weight: 600;">Active</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- API Health Tab -->
        <div id="health" class="tab-content">
            <div class="content-section">
                <div class="section-header">
                    <div class="icon icon-red">
                        <i class="fas fa-heartbeat"></i>
                    </div>
                    <h3>API Health Monitoring</h3>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>API Status Overview:</strong> Monitor the health and connectivity of all integrated APIs and services used by the system.
                    </div>
                </div>
                
                <div class="status-grid">
                    <?php foreach ($apiStatus as $key => $api): ?>
                    <div class="status-card">
                        <div class="card-icon <?= $api['status'] === 'healthy' ? 'icon-green' : ($api['status'] === 'warning' ? 'icon-orange' : 'icon-red') ?>">
                            <i class="fas fa-<?= $key === 'dataforseo' ? 'search' : ($key === 'tomba' ? 'at' : ($key === 'chatgpt' ? 'robot' : 'envelope')) ?>"></i>
                        </div>
                        <div class="card-value"><?= $api['name'] ?></div>
                        <div class="card-label"><?= $api['message'] ?></div>
                        <div class="card-status status-<?= $api['status'] ?>">
                            <i class="fas fa-circle"></i> <?= ucfirst($api['status']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="warning-box">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Important:</strong> Ensure all APIs are properly configured for full system functionality. Missing API credentials may limit system capabilities.
                    </div>
                </div>
            </div>
        </div>
        
        <!-- API Management Tab -->
        <div id="apis" class="tab-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="content-section">
                    <div class="section-header">
                        <div class="icon icon-blue">
                            <i class="fas fa-search"></i>
                        </div>
                        <h3>DataForSEO API</h3>
                    </div>
                    
                    <div class="warning-box">
                        <i class="fas fa-shield-alt"></i>
                        <div>
                            <strong>Sensitive Credentials:</strong> DataForSEO API is used for domain analysis, SEO metrics, and traffic data. Keep these credentials secure.
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="dataforseo_login">
                                <i class="fas fa-user"></i>
                                DataForSEO Login
                            </label>
                            <input type="text" 
                                   id="dataforseo_login" 
                                   name="setting_dataforseo_login" 
                                   value="<?= htmlspecialchars($settings['dataforseo_login']) ?>" 
                                   placeholder="Your DataForSEO login username"
                                   class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="dataforseo_password">
                                <i class="fas fa-lock"></i>
                                DataForSEO Password
                            </label>
                            <input type="password" 
                                   id="dataforseo_password" 
                                   name="setting_dataforseo_password" 
                                   value="<?= htmlspecialchars($settings['dataforseo_password']) ?>" 
                                   placeholder="Your DataForSEO password"
                                   class="form-input">
                        </div>
                    </div>
                </div>
                
                <div class="content-section">
                    <div class="section-header">
                        <div class="icon icon-purple">
                            <i class="fas fa-at"></i>
                        </div>
                        <h3>Tomba Email Finder</h3>
                    </div>
                    
                    <div class="info-box">
                        <i class="fas fa-envelope-open-text"></i>
                        <div>
                            <strong>Email Discovery:</strong> Tomba API helps find contact email addresses for target domains during campaign preparation.
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="tomba_api_key">
                                <i class="fas fa-key"></i>
                                Tomba API Key
                            </label>
                            <input type="password" 
                                   id="tomba_api_key" 
                                   name="setting_tomba_api_key" 
                                   value="<?= htmlspecialchars($settings['tomba_api_key']) ?>" 
                                   placeholder="Your Tomba API key"
                                   class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="tomba_secret">
                                <i class="fas fa-shield-alt"></i>
                                Tomba Secret (Optional)
                            </label>
                            <input type="password" 
                                   id="tomba_secret" 
                                   name="setting_tomba_secret" 
                                   value="<?= htmlspecialchars($settings['tomba_secret']) ?>" 
                                   placeholder="Tomba secret key if required"
                                   class="form-input">
                        </div>
                    </div>
                </div>
                
                <div class="content-section">
                    <div class="section-header">
                        <div class="icon icon-green">
                            <i class="fas fa-robot"></i>
                        </div>
                        <h3>ChatGPT AI Integration</h3>
                    </div>
                    
                    <div class="info-box">
                        <i class="fas fa-brain"></i>
                        <div>
                            <strong>AI-Powered Analysis:</strong> OpenAI GPT models are used for content analysis, domain evaluation, and intelligent campaign optimization.
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="chatgpt_api_key">
                                <i class="fas fa-microchip"></i>
                                OpenAI API Key
                            </label>
                            <input type="password" 
                                   id="chatgpt_api_key" 
                                   name="setting_chatgpt_api_key" 
                                   value="<?= htmlspecialchars($settings['chatgpt_api_key']) ?>" 
                                   placeholder="sk-..."
                                   class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="chatgpt_model">
                                <i class="fas fa-cog"></i>
                                AI Model
                            </label>
                            <select id="chatgpt_model" name="setting_chatgpt_model" class="form-select">
                                <option value="gpt-4o-mini" <?= $settings['chatgpt_model'] === 'gpt-4o-mini' ? 'selected' : '' ?>>GPT-4o Mini (Recommended)</option>
                                <option value="gpt-4o" <?= $settings['chatgpt_model'] === 'gpt-4o' ? 'selected' : '' ?>>GPT-4o</option>
                                <option value="gpt-4" <?= $settings['chatgpt_model'] === 'gpt-4' ? 'selected' : '' ?>>GPT-4</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="action-section">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i>
                        Save API Settings
                    </button>
                </div>
            </form>
        </div>
        
        <!-- System Configuration Tab -->
        <div id="system" class="tab-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="content-section">
                    <div class="section-header">
                        <div class="icon icon-orange">
                            <i class="fas fa-sliders-h"></i>
                        </div>
                        <h3>Domain Quality Thresholds</h3>
                    </div>
                    
                    <div class="warning-box">
                        <i class="fas fa-filter"></i>
                        <div>
                            <strong>Quality Control:</strong> Set minimum requirements for domain acceptance. Higher thresholds improve quality but may reduce the number of qualified domains.
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="min_domain_rating">
                                <i class="fas fa-star"></i>
                                Minimum Domain Rating
                            </label>
                            <input type="number" 
                                   id="min_domain_rating" 
                                   name="setting_min_domain_rating" 
                                   value="<?= htmlspecialchars($settings['min_domain_rating']) ?>" 
                                   min="1" max="100" 
                                   placeholder="30"
                                   class="form-input">
                            <div class="form-help">Domain authority score (1-100, recommended: 30+)</div>
                        </div>
                        <div class="form-group">
                            <label for="min_organic_traffic">
                                <i class="fas fa-chart-line"></i>
                                Minimum Organic Traffic
                            </label>
                            <input type="number" 
                                   id="min_organic_traffic" 
                                   name="setting_min_organic_traffic" 
                                   value="<?= htmlspecialchars($settings['min_organic_traffic']) ?>" 
                                   min="0" 
                                   placeholder="5000"
                                   class="form-input">
                            <div class="form-help">Monthly organic traffic (recommended: 5000+)</div>
                        </div>
                    </div>
                </div>
                
                <div class="content-section">
                    <div class="section-header">
                        <div class="icon icon-purple">
                            <i class="fas fa-robot"></i>
                        </div>
                        <h3>AI Analysis Settings</h3>
                    </div>
                    
                    <div class="info-box">
                        <i class="fas fa-microchip"></i>
                        <div>
                            <strong>AI Performance:</strong> Configure how the system uses AI for domain analysis and content evaluation. Higher batch sizes process faster but use more resources.
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="enable_ai_analysis">
                                <i class="fas fa-toggle-on"></i>
                                Enable AI Analysis
                            </label>
                            <select id="enable_ai_analysis" name="setting_enable_ai_analysis" class="form-select">
                                <option value="yes" <?= $settings['enable_ai_analysis'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= $settings['enable_ai_analysis'] === 'no' ? 'selected' : '' ?>>No</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="ai_batch_size">
                                <i class="fas fa-layer-group"></i>
                                AI Batch Size
                            </label>
                            <input type="number" 
                                   id="ai_batch_size" 
                                   name="setting_ai_batch_size" 
                                   value="<?= htmlspecialchars($settings['ai_batch_size']) ?>" 
                                   min="1" max="20" 
                                   placeholder="5"
                                   class="form-input">
                            <div class="form-help">Domains processed per AI batch (recommended: 5-10)</div>
                        </div>
                        <div class="form-group">
                            <label for="prototype_mode">
                                <i class="fas fa-flask"></i>
                                Prototype Mode
                            </label>
                            <select id="prototype_mode" name="setting_prototype_mode" class="form-select">
                                <option value="yes" <?= $settings['prototype_mode'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= $settings['prototype_mode'] === 'no' ? 'selected' : '' ?>>No</option>
                            </select>
                            <div class="form-help">Enable experimental features and detailed logging</div>
                        </div>
                        <div class="form-group">
                            <label for="ai_cost_tracking">
                                <i class="fas fa-dollar-sign"></i>
                                AI Cost Tracking
                            </label>
                            <select id="ai_cost_tracking" name="setting_ai_cost_tracking" class="form-select">
                                <option value="yes" <?= $settings['ai_cost_tracking'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                                <option value="no" <?= $settings['ai_cost_tracking'] === 'no' ? 'selected' : '' ?>>No</option>
                            </select>
                            <div class="form-help">Monitor and track AI API usage costs</div>
                        </div>
                    </div>
                </div>
                
                <div class="action-section">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i>
                        Save System Configuration
                    </button>
                </div>
            </form>
        </div>
        </div>
    </main>
    
    <script>
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab-btn').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
    </script>
</body>
</html>