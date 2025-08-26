<?php
require_once 'auth.php';
$auth->requireAuth();

require_once 'config/database.php';

$db = new Database();

// Get all API settings
$settings = [];
$results = $db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%api%' OR setting_key LIKE 'gmail%' OR setting_key LIKE 'tomba%' OR setting_key LIKE 'dataforseo%'");
foreach ($results as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Status Dashboard</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .status-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .status-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .status-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .status-icon.success {
            background: #dcfce7;
            color: #16a34a;
        }

        .status-icon.warning {
            background: #fef3c7;
            color: #d97706;
        }

        .status-icon.error {
            background: #fef2f2;
            color: #dc2626;
        }

        .status-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
        }

        .status-details {
            margin: 1rem 0;
        }

        .status-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .status-item:last-child {
            border-bottom: none;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.success {
            background: #dcfce7;
            color: #16a34a;
        }

        .status-badge.warning {
            background: #fef3c7;
            color: #d97706;
        }

        .status-badge.error {
            background: #fef2f2;
            color: #dc2626;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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

        .overall-status {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 2rem;
        }

        .overall-status h2 {
            margin: 0 0 1rem 0;
            font-size: 2rem;
        }

        .quick-actions {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .quick-actions h3 {
            margin: 0 0 1rem 0;
            color: #1e293b;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <h1><i class="fas fa-wifi"></i> API Integration Status</h1>
            </div>
        </header>

        <div style="padding: 2rem;">
            <?php
            // Calculate overall status
            $totalAPIs = 4;
            $workingAPIs = 0;
            
            $gmailConfigured = !empty($settings['gmail_client_id']) && !empty($settings['gmail_client_secret']);
            
            // Check Gmail authorization status from database
            $gmailAuthorized = false;
            try {
                $tokenCheck = $db->fetchOne("SELECT * FROM gmail_tokens WHERE expires_at > NOW() LIMIT 1");
                $gmailAuthorized = !empty($tokenCheck);
            } catch (Exception $e) {
                // Fallback to old method if database check fails
                $gmailAuthorized = file_exists('gmail_token.json');
            }
            $tombaConfigured = !empty($settings['tomba_api_key']) && !empty($settings['tomba_secret']);
            $dataforSEOConfigured = !empty($settings['dataforseo_login']) && !empty($settings['dataforseo_password']);
            $chatgptConfigured = !empty($settings['chatgpt_api_key']);
            
            if ($gmailConfigured && $gmailAuthorized) $workingAPIs++;
            if ($tombaConfigured) $workingAPIs++;
            if ($dataforSEOConfigured) $workingAPIs++;
            if ($chatgptConfigured) $workingAPIs++;
            
            $overallPercent = round(($workingAPIs / $totalAPIs) * 100);
            ?>

            <div class="overall-status">
                <h2><i class="fas fa-chart-pie"></i> Overall API Health</h2>
                <p style="font-size: 3rem; font-weight: bold; margin: 1rem 0;"><?php echo $overallPercent; ?>%</p>
                <p><?php echo $workingAPIs; ?> of <?php echo $totalAPIs; ?> APIs fully configured and working</p>
            </div>

            <div class="status-grid">
                <!-- Gmail API Status -->
                <div class="status-card">
                    <div class="status-header">
                        <div class="status-icon <?php echo ($gmailConfigured && $gmailAuthorized) ? 'success' : ($gmailConfigured ? 'warning' : 'error'); ?>">
                            <i class="fab fa-google"></i>
                        </div>
                        <div class="status-title">Gmail API</div>
                    </div>
                    
                    <div class="status-details">
                        <div class="status-item">
                            <span>Client ID</span>
                            <span class="status-badge <?php echo !empty($settings['gmail_client_id']) ? 'success' : 'error'; ?>">
                                <?php echo !empty($settings['gmail_client_id']) ? 'Configured' : 'Missing'; ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span>Client Secret</span>
                            <span class="status-badge <?php echo !empty($settings['gmail_client_secret']) ? 'success' : 'error'; ?>">
                                <?php echo !empty($settings['gmail_client_secret']) ? 'Configured' : 'Missing'; ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span>OAuth Authorization</span>
                            <span class="status-badge <?php echo $gmailAuthorized ? 'success' : 'warning'; ?>">
                                <?php echo $gmailAuthorized ? 'Authorized' : 'Pending'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <?php if (!$gmailConfigured): ?>
                            <a href="configure_apis.php" class="btn-sm btn-primary">
                                <i class="fas fa-cog"></i> Configure
                            </a>
                        <?php elseif (!$gmailAuthorized): ?>
                            <a href="gmail_setup.php" class="btn-sm btn-warning">
                                <i class="fas fa-key"></i> Authorize
                            </a>
                        <?php else: ?>
                            <a href="test_gmail.php" class="btn-sm btn-success">
                                <i class="fas fa-check"></i> Test
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tomba API Status -->
                <div class="status-card">
                    <div class="status-header">
                        <div class="status-icon <?php echo $tombaConfigured ? 'success' : 'error'; ?>">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="status-title">Tomba Email Finder</div>
                    </div>
                    
                    <div class="status-details">
                        <div class="status-item">
                            <span>API Key</span>
                            <span class="status-badge <?php echo !empty($settings['tomba_api_key']) ? 'success' : 'error'; ?>">
                                <?php echo !empty($settings['tomba_api_key']) ? 'Configured' : 'Missing'; ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span>Secret</span>
                            <span class="status-badge <?php echo !empty($settings['tomba_secret']) ? 'success' : 'error'; ?>">
                                <?php echo !empty($settings['tomba_secret']) ? 'Configured' : 'Missing'; ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span>Connection</span>
                            <span class="status-badge <?php echo $tombaConfigured ? 'success' : 'error'; ?>">
                                <?php echo $tombaConfigured ? 'Ready' : 'Not Ready'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <?php if (!$tombaConfigured): ?>
                            <a href="configure_apis.php" class="btn-sm btn-primary">
                                <i class="fas fa-cog"></i> Configure
                            </a>
                        <?php else: ?>
                            <a href="test_tomba.php" class="btn-sm btn-success">
                                <i class="fas fa-check"></i> Test
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- DataForSEO API Status -->
                <div class="status-card">
                    <div class="status-header">
                        <div class="status-icon <?php echo $dataforSEOConfigured ? 'success' : 'error'; ?>">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="status-title">DataForSEO</div>
                    </div>
                    
                    <div class="status-details">
                        <div class="status-item">
                            <span>API Key</span>
                            <span class="status-badge <?php echo !empty($settings['dataforseo_api_key']) ? 'success' : 'error'; ?>">
                                <?php echo !empty($settings['dataforseo_api_key']) ? 'Configured' : 'Missing'; ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span>Domain Analysis</span>
                            <span class="status-badge <?php echo $dataforSEOConfigured ? 'success' : 'error'; ?>">
                                <?php echo $dataforSEOConfigured ? 'Ready' : 'Not Ready'; ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span>Backlinks Checker</span>
                            <span class="status-badge <?php echo $dataforSEOConfigured ? 'success' : 'error'; ?>">
                                <?php echo $dataforSEOConfigured ? 'Ready' : 'Not Ready'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <?php if (!$dataforSEOConfigured): ?>
                            <a href="settings.php" class="btn-sm btn-primary">
                                <i class="fas fa-cog"></i> Configure
                            </a>
                        <?php else: ?>
                            <a href="test_dataforseo_integration.php" class="btn-sm btn-success">
                                <i class="fas fa-check"></i> Test
                            </a>
                            <a href="domain_analyzer.php" class="btn-sm btn-success">
                                <i class="fas fa-search"></i> Use
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ChatGPT API Status -->
                <div class="status-card">
                    <div class="status-header">
                        <div class="status-icon <?php echo $chatgptConfigured ? 'success' : 'error'; ?>">
                            <i class="fas fa-brain"></i>
                        </div>
                        <div class="status-title">ChatGPT API</div>
                    </div>
                    
                    <div class="status-details">
                        <div class="status-item">
                            <span>API Key</span>
                            <span class="status-badge <?php echo !empty($settings['chatgpt_api_key']) ? 'success' : 'error'; ?>">
                                <?php echo !empty($settings['chatgpt_api_key']) ? 'Configured' : 'Missing'; ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span>Model</span>
                            <span class="status-badge <?php echo !empty($settings['chatgpt_model']) ? 'success' : 'warning'; ?>">
                                <?php echo !empty($settings['chatgpt_model']) ? htmlspecialchars($settings['chatgpt_model']) : 'gpt-4o-mini'; ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span>AI Analysis</span>
                            <span class="status-badge <?php echo $chatgptConfigured ? 'success' : 'error'; ?>">
                                <?php echo $chatgptConfigured ? 'Ready' : 'Not Ready'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <?php if (!$chatgptConfigured): ?>
                            <a href="admin.php" class="btn-sm btn-primary">
                                <i class="fas fa-cog"></i> Configure
                            </a>
                        <?php else: ?>
                            <a href="domain_analyzer.php" class="btn-sm btn-success">
                                <i class="fas fa-brain"></i> Use AI Analysis
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="quick-actions">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <div class="quick-actions-grid">
                    <a href="configure_apis.php" class="btn-sm btn-primary">
                        <i class="fas fa-cog"></i> Configure All APIs
                    </a>
                    <a href="settings.php" class="btn-sm btn-primary">
                        <i class="fas fa-sliders-h"></i> System Settings
                    </a>
                    <a href="domain_analyzer.php" class="btn-sm btn-success">
                        <i class="fas fa-search"></i> Domain Analyzer
                    </a>
                    <a href="index.php" class="btn-sm btn-primary">
                        <i class="fas fa-dashboard"></i> Main Dashboard
                    </a>
                </div>
            </div>

            <!-- API Usage Statistics -->
            <?php
            try {
                $apiLogs = $db->fetchAll("SELECT api_service, COUNT(*) as calls, 
                                         SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as successful_calls,
                                         MAX(created_at) as last_call
                                         FROM api_logs 
                                         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAYS)
                                         GROUP BY api_service");
                
                if (!empty($apiLogs)): ?>
                    <div class="status-card" style="grid-column: 1 / -1; margin-top: 2rem;">
                        <h3><i class="fas fa-chart-bar"></i> API Usage (Last 7 Days)</h3>
                        <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                            <thead>
                                <tr style="background: #f8fafc;">
                                    <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid #e2e8f0;">Service</th>
                                    <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid #e2e8f0;">Total Calls</th>
                                    <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid #e2e8f0;">Successful</th>
                                    <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid #e2e8f0;">Success Rate</th>
                                    <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid #e2e8f0;">Last Call</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($apiLogs as $log): 
                                    $successRate = $log['calls'] > 0 ? round(($log['successful_calls'] / $log['calls']) * 100, 1) : 0;
                                    $badgeClass = $successRate >= 80 ? 'success' : ($successRate >= 60 ? 'warning' : 'error');
                                ?>
                                    <tr>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;"><?php echo ucfirst($log['api_service']); ?></td>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;"><?php echo number_format($log['calls']); ?></td>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;"><?php echo number_format($log['successful_calls']); ?></td>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                                            <span class="status-badge <?php echo $badgeClass; ?>"><?php echo $successRate; ?>%</span>
                                        </td>
                                        <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;"><?php echo $log['last_call'] ? date('M j, H:i', strtotime($log['last_call'])) : 'Never'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif;
            } catch (Exception $e) {
                // Silently handle if api_logs table doesn't exist yet
            }
            ?>
        </div>
    </main>

    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);

        // Add visual feedback for status changes
        document.addEventListener('DOMContentLoaded', () => {
            const statusCards = document.querySelectorAll('.status-card');
            statusCards.forEach(card => {
                const successElements = card.querySelectorAll('.status-badge.success');
                if (successElements.length > 0) {
                    card.style.borderLeft = '4px solid #16a34a';
                }
            });
        });
    </script>
</body>
</html>