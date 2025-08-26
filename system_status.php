<?php
require_once 'auth.php';
$auth->requireAuth();

require_once 'config/database.php';

$db = new Database();
$startTime = microtime(true);

// Collect all system status data
$systemData = [
    'settings' => [],
    'gmass_config' => null,
    'imap_config' => null,
    'queue_stats' => [],
    'recent_emails' => [],
    'templates' => [],
    'performance_stats' => [],
    'security_status' => [],
    'system_ready' => false,
    'migration_status' => 'gmass'
];

try {
    // 1. System Settings Check - Updated for GMass + IMAP
    $settings = $db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sender_email', 'tomba_api_key', 'tomba_secret', 'gmass_api_key', 'imap_email', 'imap_app_password')");
    
    foreach ($settings as $setting) {
        $systemData['settings'][$setting['setting_key']] = $setting['setting_value'];
    }
    
    // Check GMass configuration
    $systemData['gmass_config'] = [
        'api_key_configured' => !empty($systemData['settings']['gmass_api_key']),
        'sender_email' => $systemData['settings']['sender_email'] ?? null
    ];
    
    // Check IMAP configuration
    $systemData['imap_config'] = [
        'email_configured' => !empty($systemData['settings']['imap_email']),
        'password_configured' => !empty($systemData['settings']['imap_app_password']),
        'email' => $systemData['settings']['imap_email'] ?? null
    ];
    
    // 2. Test GMass and IMAP connections
    require_once 'classes/GMassIntegration.php';
    require_once 'classes/IMAPMonitor.php';
    
    try {
        $gmass = new GMassIntegration();
        $systemData['gmass_config']['connection_test'] = $gmass->testConnection();
    } catch (Exception $e) {
        $systemData['gmass_config']['connection_test'] = ['success' => false, 'error' => $e->getMessage()];
    }
    
    try {
        $imap = new IMAPMonitor();
        $systemData['imap_config']['connection_test'] = $imap->testConnection();
    } catch (Exception $e) {
        $systemData['imap_config']['connection_test'] = false;
        $systemData['imap_config']['connection_error'] = $e->getMessage();
    }
    
    // 3. Email Queue Status
    $systemData['queue_stats'] = $db->fetchAll("SELECT status, COUNT(*) as count FROM email_queue GROUP BY status");
    
    // 4. Recent Activity
    $systemData['recent_emails'] = $db->fetchAll("SELECT recipient_email, subject, status, created_at FROM email_queue ORDER BY created_at DESC LIMIT 5");
    
    // 5. Email Templates
    require_once 'classes/EmailTemplate.php';
    $emailTemplate = new EmailTemplate();
    $systemData['templates'] = $emailTemplate->getAll();
    $defaultTemplate = $emailTemplate->getDefault();
    
    // 6. Performance Statistics
    try {
        require_once 'classes/PerformanceMonitor.php';
        $perfMonitor = new PerformanceMonitor();
        $systemData['performance_stats'] = $perfMonitor->getPerformanceStats(1);
    } catch (Exception $e) {
        $systemData['performance_stats'] = [];
    }
    
    // 7. Security Status
    try {
        require_once 'classes/SecurityValidator.php';
        $secValidator = new SecurityValidator();
        $systemData['security_status'] = $secValidator->getSecurityReport(1);
    } catch (Exception $e) {
        $systemData['security_status'] = [];
    }
    
    // 8. System Status Summary - Updated for GMass + IMAP
    $gmassReady = $systemData['gmass_config']['api_key_configured'] && 
                  isset($systemData['gmass_config']['connection_test']['success']) &&
                  $systemData['gmass_config']['connection_test']['success'];
                  
    $imapReady = $systemData['imap_config']['email_configured'] && 
                 $systemData['imap_config']['password_configured'] &&
                 $systemData['imap_config']['connection_test'];
                 
    $basicConfigured = !empty($systemData['settings']['sender_email']) && 
                       !empty($systemData['settings']['tomba_api_key']) && 
                       !empty($systemData['settings']['tomba_secret']);
    
    $systemData['system_ready'] = $basicConfigured && $gmassReady && $imapReady;
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

$endTime = microtime(true);
$executionTime = round(($endTime - $startTime) * 1000, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Status - Auto Outreach</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Status indicators */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .status-indicator.success {
            background: rgba(16, 185, 129, 0.1);
            color: #065f46;
        }
        
        .status-indicator.warning {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }
        
        .status-indicator.error {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
        }
        
        /* System overview cards */
        .system-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }
        
        .overview-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #f1f5f9;
            position: relative;
        }
        
        .overview-card.success {
            border-left: 4px solid #10b981;
        }
        
        .overview-card.warning {
            border-left: 4px solid #f59e0b;
        }
        
        .overview-card.error {
            border-left: 4px solid #ef4444;
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }
        
        .card-icon.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .card-icon.warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }
        
        .card-icon.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .card-icon.info {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        
        .card-content {
            color: #64748b;
            line-height: 1.6;
        }
        
        .config-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f8fafc;
        }
        
        .config-item:last-child {
            border-bottom: none;
        }
        
        .config-label {
            font-weight: 600;
            color: #374151;
        }
        
        .config-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.875rem;
        }
        
        /* Queue stats */
        .queue-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .queue-stat {
            text-align: center;
            padding: 1rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .queue-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .queue-label {
            font-size: 0.8rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        /* Recent activity */
        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: #fafbfc;
            border: 1px solid #f1f5f9;
        }
        
        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            flex-shrink: 0;
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .activity-meta {
            font-size: 0.8rem;
            color: #64748b;
        }
        
        /* Overall system status */
        .system-status-banner {
            background: white;
            border-radius: 16px;
            padding: 3rem;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #f1f5f9;
            margin-bottom: 3rem;
        }
        
        .system-status-banner.operational {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-color: #bbf7d0;
        }
        
        .system-status-banner.warning {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-color: #fde68a;
        }
        
        .system-status-banner.error {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border-color: #fecaca;
        }
        
        .status-icon-large {
            font-size: 4rem;
            margin-bottom: 1.5rem;
        }
        
        .status-icon-large.operational {
            color: #10b981;
        }
        
        .status-icon-large.warning {
            color: #f59e0b;
        }
        
        .status-icon-large.error {
            color: #ef4444;
        }
        
        .status-title {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .status-title.operational {
            color: #065f46;
        }
        
        .status-title.warning {
            color: #92400e;
        }
        
        .status-title.error {
            color: #991b1b;
        }
        
        .status-description {
            font-size: 1.1rem;
            color: #64748b;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        /* Quick actions */
        .quick-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .action-btn.primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .action-btn.primary:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }
        
        .action-btn.success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .action-btn.success:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
        }
        
        .action-btn.purple {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .action-btn.purple:hover {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.4);
        }
        
        .action-btn.orange {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .action-btn.orange:hover {
            background: linear-gradient(135deg, #d97706, #b45309);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.4);
        }
        
        .execution-badge {
            position: absolute;
            top: 2rem;
            right: 3rem;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }
    </style>
</head>
<body>
    <?php include 'includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header" style="position: relative;">
            <div class="execution-badge">
                <i class="fas fa-stopwatch"></i> <?php echo $executionTime; ?>ms
            </div>
            <h1><i class="fas fa-heartbeat"></i> System Status</h1>
            <p class="header-subtitle">Complete system health monitoring and configuration status</p>
        </header>

        <div class="content-area">
            <!-- Overall System Status Banner -->
            <?php
            $bannerClass = 'error';
            $statusIcon = 'fas fa-exclamation-triangle';
            $statusTitle = 'Configuration Required';
            $statusDesc = 'System components need configuration before automation can begin.';
            
            if ($systemData['system_ready']) {
                $bannerClass = 'operational';
                $statusIcon = 'fas fa-check-circle';
                $statusTitle = 'GMass + IMAP System Operational';
                $statusDesc = 'All components are configured and ready. Your GMass + IMAP email automation system is live and running smoothly.';
            } elseif ($basicConfigured && ($gmassReady || $imapReady)) {
                $bannerClass = 'warning';
                $statusIcon = 'fas fa-exclamation-circle';
                $statusTitle = 'Almost Ready';
                $statusDesc = 'Basic configuration complete. Complete GMass + IMAP setup to begin email automation.';
            }
            ?>
            <div class="system-status-banner <?php echo $bannerClass; ?>">
                <div class="status-icon-large <?php echo $bannerClass; ?>">
                    <i class="<?php echo $statusIcon; ?>"></i>
                </div>
                <h2 class="status-title <?php echo $bannerClass; ?>"><?php echo $statusTitle; ?></h2>
                <p class="status-description"><?php echo $statusDesc; ?></p>
                
                <div class="quick-actions">
                    <a href="setup_wizard.php" class="action-btn primary">
                        <i class="fas fa-magic"></i> Setup Wizard
                    </a>
                    <a href="test_gmass_imap_integration.php" class="action-btn success">
                        <i class="fas fa-vial"></i> Test System
                    </a>
                    <a href="campaigns.php" class="action-btn purple">
                        <i class="fas fa-bullhorn"></i> Campaigns
                    </a>
                    <a href="test_performance.php" class="action-btn orange">
                        <i class="fas fa-tachometer-alt"></i> Performance
                    </a>
                </div>
            </div>

            <!-- System Components Overview -->
            <div class="system-overview">
                <!-- GMass Configuration -->
                <div class="overview-card <?php echo $gmassReady ? 'success' : 'warning'; ?>">
                    <div class="card-header">
                        <div class="card-icon <?php echo $gmassReady ? 'success' : 'warning'; ?>">
                            <i class="fas fa-rocket"></i>
                        </div>
                        <h3 class="card-title">GMass Email Sending</h3>
                    </div>
                    <div class="card-content">
                        <div class="config-item">
                            <span class="config-label">GMass API Key</span>
                            <span class="status-indicator <?php echo $systemData['gmass_config']['api_key_configured'] ? 'success' : 'error'; ?>">
                                <?php echo $systemData['gmass_config']['api_key_configured'] ? 'Configured' : 'Missing'; ?>
                            </span>
                        </div>
                        <div class="config-item">
                            <span class="config-label">Connection Test</span>
                            <span class="status-indicator <?php echo (isset($systemData['gmass_config']['connection_test']['success']) && $systemData['gmass_config']['connection_test']['success']) ? 'success' : 'error'; ?>">
                                <?php echo (isset($systemData['gmass_config']['connection_test']['success']) && $systemData['gmass_config']['connection_test']['success']) ? 'Connected' : 'Failed'; ?>
                            </span>
                        </div>
                        <div class="config-item">
                            <span class="config-label">Sender Email</span>
                            <span class="config-value">
                                <?php echo $systemData['gmass_config']['sender_email'] ?? 'Not configured'; ?>
                            </span>
                        </div>
                        <div class="config-item">
                            <span class="config-label">System Status</span>
                            <span class="status-indicator <?php echo $gmassReady ? 'success' : 'warning'; ?>">
                                <?php echo $gmassReady ? 'Ready' : 'Needs Setup'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- IMAP Configuration -->
                <div class="overview-card <?php echo $imapReady ? 'success' : 'error'; ?>">
                    <div class="card-header">
                        <div class="card-icon <?php echo $imapReady ? 'success' : 'error'; ?>">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <h3 class="card-title">IMAP Reply Monitoring</h3>
                    </div>
                    <div class="card-content">
                        <div class="config-item">
                            <span class="config-label">IMAP Email</span>
                            <span class="config-value">
                                <?php echo $systemData['imap_config']['email'] ?? 'Not configured'; ?>
                            </span>
                        </div>
                        <div class="config-item">
                            <span class="config-label">App Password</span>
                            <span class="status-indicator <?php echo $systemData['imap_config']['password_configured'] ? 'success' : 'error'; ?>">
                                <?php echo $systemData['imap_config']['password_configured'] ? 'Configured' : 'Missing'; ?>
                            </span>
                        </div>
                        <div class="config-item">
                            <span class="config-label">Connection Test</span>
                            <span class="status-indicator <?php echo $systemData['imap_config']['connection_test'] ? 'success' : 'error'; ?>">
                                <?php echo $systemData['imap_config']['connection_test'] ? 'Connected' : 'Failed'; ?>
                            </span>
                        </div>
                        <?php if (!$imapReady): ?>
                            <div style="margin-top: 1rem;">
                                <a href="setup_wizard.php?step=2" class="action-btn primary">
                                    <i class="fas fa-cog"></i> Configure IMAP
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Email Queue Status -->
                <div class="overview-card info">
                    <div class="card-header">
                        <div class="card-icon info">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h3 class="card-title">Email Queue</h3>
                    </div>
                    <div class="card-content">
                        <?php if (!empty($systemData['queue_stats'])): ?>
                            <div class="queue-stats">
                                <?php
                                $totalQueued = 0;
                                $statsMap = [];
                                foreach ($systemData['queue_stats'] as $stat) {
                                    $totalQueued += $stat['count'];
                                    $statsMap[$stat['status']] = $stat['count'];
                                }
                                $statusTypes = ['queued', 'processing', 'sent', 'failed'];
                                foreach ($statusTypes as $status):
                                    $count = $statsMap[$status] ?? 0;
                                ?>
                                <div class="queue-stat">
                                    <div class="queue-number"><?php echo $count; ?></div>
                                    <div class="queue-label"><?php echo $status; ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #f1f5f9;">
                                <strong>Total: <?php echo $totalQueued; ?> emails</strong>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; color: #64748b;">
                                <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No emails in queue</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- System Health -->
                <div class="overview-card info">
                    <div class="card-header">
                        <div class="card-icon info">
                            <i class="fas fa-heartbeat"></i>
                        </div>
                        <h3 class="card-title">System Health</h3>
                    </div>
                    <div class="card-content">
                        <div class="config-item">
                            <span class="config-label">System Type</span>
                            <span class="config-value">GMass + IMAP</span>
                        </div>
                        <div class="config-item">
                            <span class="config-label">Performance Stats</span>
                            <span class="config-value"><?php echo count($systemData['performance_stats']); ?> operations logged</span>
                        </div>
                        <div class="config-item">
                            <span class="config-label">Security Events</span>
                            <span class="config-value"><?php echo count($systemData['security_status']['security_events'] ?? []); ?> events</span>
                        </div>
                        <div class="config-item">
                            <span class="config-label">Email Templates</span>
                            <span class="config-value"><?php echo count($systemData['templates']); ?> available</span>
                        </div>
                        <?php if (!empty($systemData['templates'])): ?>
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #f1f5f9;">
                            <strong style="color: #374151; margin-bottom: 0.5rem; display: block;">Default Template:</strong>
                            <div style="color: #64748b;">
                                <?php 
                                $defaultTemplate = array_filter($systemData['templates'], fn($t) => ($t['is_default'] ?? 0));
                                echo !empty($defaultTemplate) ? current($defaultTemplate)['name'] : 'None set';
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <?php if (!empty($systemData['recent_emails'])): ?>
            <div class="overview-section">
                <h2 class="section-title">
                    <i class="fas fa-clock"></i> Recent Email Activity
                </h2>
                <div class="overview-card">
                    <div class="card-content">
                        <?php foreach ($systemData['recent_emails'] as $email): ?>
                            <?php
                            $statusClass = match($email['status']) {
                                'queued' => 'warning',
                                'sent' => 'success',
                                'failed' => 'error',
                                default => 'info'
                            };
                            $statusIcon = match($email['status']) {
                                'queued' => 'fas fa-clock',
                                'sent' => 'fas fa-check',
                                'failed' => 'fas fa-times',
                                default => 'fas fa-envelope'
                            };
                            ?>
                            <div class="activity-item">
                                <div class="activity-icon card-icon <?php echo $statusClass; ?>">
                                    <i class="<?php echo $statusIcon; ?>"></i>
                                </div>
                                <div class="activity-details">
                                    <div class="activity-title">
                                        To: <?php echo htmlspecialchars($email['recipient_email']); ?>
                                    </div>
                                    <div class="activity-meta">
                                        Subject: <?php echo htmlspecialchars($email['subject']); ?> • 
                                        <?php echo $email['created_at']; ?>
                                    </div>
                                </div>
                                <span class="status-indicator <?php echo $statusClass; ?>">
                                    <?php echo ucfirst($email['status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>