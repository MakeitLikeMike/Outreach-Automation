<?php
require_once 'auth.php';
$auth->requireAuth();

require_once 'classes/Campaign.php';
require_once 'classes/TargetDomain.php';
require_once 'classes/EmailSearchService.php';
require_once 'classes/EmailSearchQueue.php';
require_once 'classes/TombaRateLimit.php';
require_once 'classes/AnalyticsDashboard.php';
require_once 'config/database.php';

$campaign = new Campaign();
$targetDomain = new TargetDomain();
$emailSearchService = new EmailSearchService();
$emailSearchQueue = new EmailSearchQueue();
$tombaRateLimit = new TombaRateLimit();
$analytics = new AnalyticsDashboard();
$db = new Database();

$campaignId = $_GET['campaign_id'] ?? null;
$dateRange = $_GET['date_range'] ?? '30';
$exportFormat = $_GET['export'] ?? null;

// Handle export requests
if ($exportFormat) {
    $data = $analytics->exportAnalytics($exportFormat, $dateRange, $campaignId);
    
    $filename = "analytics_" . date('Y-m-d') . "." . $exportFormat;
    
    switch ($exportFormat) {
        case 'csv':
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $data;
            exit;
        case 'json':
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $data;
            exit;
    }
}

// Get all campaigns for filter
$campaigns = $campaign->getAll();

// Get overall statistics
$totalCampaigns = count($campaigns);
$totalDomains = array_sum(array_column($campaigns, 'total_domains'));
$approvedDomains = array_sum(array_column($campaigns, 'approved_domains'));
$contactedDomains = array_sum(array_column($campaigns, 'contacted_domains'));

// Get email statistics
$emailStats = $db->fetchOne("
    SELECT 
        COUNT(*) as total_emails,
        COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent_emails,
        COUNT(CASE WHEN status = 'replied' THEN 1 END) as replied_emails,
        COUNT(CASE WHEN status = 'bounced' THEN 1 END) as bounced_emails,
        COUNT(CASE WHEN reply_classification = 'interested' THEN 1 END) as interested_replies,
        COUNT(CASE WHEN reply_classification = 'not_interested' THEN 1 END) as not_interested_replies
    FROM outreach_emails 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
", [$dateRange]);

// Get recent API usage
$apiUsage = $db->fetchAll("
    SELECT 
        api_service,
        COUNT(*) as total_calls,
        SUM(credits_used) as credits_used,
        COUNT(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 END) as successful_calls
    FROM api_logs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY api_service
    ORDER BY total_calls DESC
", [$dateRange]);

// Get advanced analytics data
$performanceData = $analytics->getCampaignPerformanceAnalytics($dateRange, $campaignId);
$weeklyData = $analytics->getWeeklyPerformanceSummary(0);

// Get recent activity
$recentActivity = $db->fetchAll("
    SELECT 
        'campaign' as type,
        c.name as title,
        'Campaign created' as action,
        c.created_at as timestamp
    FROM campaigns c
    WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    
    UNION ALL
    
    SELECT 
        'domain' as type,
        td.domain as title,
        CONCAT('Domain ', td.status) as action,
        td.created_at as timestamp
    FROM target_domains td
    WHERE td.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    
    UNION ALL
    
    SELECT 
        'email' as type,
        oe.recipient_email as title,
        CONCAT('Email ', oe.status) as action,
        oe.created_at as timestamp
    FROM outreach_emails oe
    WHERE oe.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    
    ORDER BY timestamp DESC
    LIMIT 20
", [$dateRange, $dateRange, $dateRange]);

// Calculate success rates
$emailSuccessRate = $emailStats['sent_emails'] > 0 ? 
    round(($emailStats['replied_emails'] / $emailStats['sent_emails']) * 100, 1) : 0;

$interestRate = $emailStats['replied_emails'] > 0 ? 
    round(($emailStats['interested_replies'] / $emailStats['replied_emails']) * 100, 1) : 0;

$domainApprovalRate = $totalDomains > 0 ? 
    round(($approvedDomains / $totalDomains) * 100, 1) : 0;

// Get email search statistics
$emailSearchStats = $emailSearchService->getSearchStatistics(intval($dateRange));
$domainsBySearchStatus = $targetDomain->getDomainsByEmailSearchStatus();
$queueStats = $emailSearchQueue->getQueueStatistics();
$apiUsageStats = $tombaRateLimit->getUsageStatistics();

// Calculate email search rates
$emailSearchSuccessRate = $emailSearchStats['total_searches'] > 0 ? 
    round(($emailSearchStats['successful_searches'] / $emailSearchStats['total_searches']) * 100, 1) : 0;

$emailFoundRate = $emailSearchStats['total_searches'] > 0 ? 
    round(($emailSearchStats['emails_found'] / $emailSearchStats['total_searches']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring & Analytics - Outreach Automation</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/monitoring.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button onclick="goBack()" class="back-btn" title="Go Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1>Campaign Monitoring & Analytics</h1>
            </div>
            <div class="header-controls">
                <select onchange="updateDateRange(this.value)" style="margin-right: 1rem;">
                    <option value="7" <?php echo $dateRange == '7' ? 'selected' : ''; ?>>Last 7 days</option>
                    <option value="30" <?php echo $dateRange == '30' ? 'selected' : ''; ?>>Last 30 days</option>
                    <option value="90" <?php echo $dateRange == '90' ? 'selected' : ''; ?>>Last 90 days</option>
                    <option value="365" <?php echo $dateRange == '365' ? 'selected' : ''; ?>>Last year</option>
                </select>
            </div>
        </header>

        <div style="padding: 2rem;">
            <!-- Key Metrics Overview -->
            <div class="dashboard-grid">
                <div class="stat-card clickable" onclick="showDetailedCampaignAnalytics()">
                    <div class="stat-icon campaigns">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $totalCampaigns; ?></h3>
                        <p>Active Campaigns</p>
                    </div>
                    <div class="click-indicator">
                        <i class="fas fa-external-link-alt"></i>
                    </div>
                </div>

                <div class="stat-card clickable" onclick="showDetailedDomainsAnalytics()">
                    <div class="stat-icon domains">
                        <i class="fas fa-globe"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $totalDomains; ?></h3>
                        <p>Total Domains</p>
                        <small><?php echo $approvedDomains; ?> approved</small>
                    </div>
                    <div class="click-indicator">
                        <i class="fas fa-external-link-alt"></i>
                    </div>
                </div>

                <div class="stat-card clickable" onclick="showEmailSearchAnalytics()">
                    <div class="stat-icon approved">
                        <i class="fas fa-search"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $emailSearchStats['emails_found'] ?? 0; ?></h3>
                        <p>Emails Found</p>
                        <small><?php echo $emailFoundRate; ?>% success rate</small>
                    </div>
                    <div class="click-indicator">
                        <i class="fas fa-external-link-alt"></i>
                    </div>
                </div>

                <div class="stat-card clickable" onclick="showDetailedEmailsSent(<?php echo $dateRange; ?>)">
                    <div class="stat-icon contacted">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $emailStats['sent_emails'] ?? 0; ?></h3>
                        <p>Emails Sent</p>
                        <small><?php echo $emailSuccessRate; ?>% reply rate</small>
                    </div>
                    <div class="click-indicator">
                        <i class="fas fa-external-link-alt"></i>
                    </div>
                </div>
            </div>

            <!-- Email Search Queue Alert -->
            <?php if ($queueStats['health']['stuck_jobs'] > 0 || $queueStats['health']['failed_retryable'] > 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Email Search Queue Attention Required:</strong>
                    <?php if ($queueStats['health']['stuck_jobs'] > 0): ?>
                        <?php echo $queueStats['health']['stuck_jobs']; ?> stuck jobs detected.
                    <?php endif; ?>
                    <?php if ($queueStats['health']['failed_retryable'] > 0): ?>
                        <?php echo $queueStats['health']['failed_retryable']; ?> failed jobs ready for retry.
                    <?php endif; ?>
                    <a href="#email-search-section" class="btn btn-sm btn-warning ml-2">View Details</a>
                </div>
            <?php endif; ?>

            <!-- API Usage Alert -->
            <?php if ($apiUsageStats['usage']['month']['percentage'] >= 80): ?>
                <div class="alert alert-<?php echo $apiUsageStats['usage']['month']['percentage'] >= 90 ? 'error' : 'warning'; ?>">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>High API Usage:</strong>
                    Tomba API usage is at <?php echo $apiUsageStats['usage']['month']['percentage']; ?>% of monthly quota 
                    (<?php echo number_format($apiUsageStats['usage']['month']['credits']); ?> / <?php echo number_format($apiUsageStats['usage']['month']['limit']); ?> credits).
                    <a href="#api-usage-section" class="btn btn-sm btn-warning ml-2">View Rate Limits</a>
                </div>
            <?php endif; ?>

            <!-- Analytics Export Controls -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3><i class="fas fa-download"></i> Analytics Export</h3>
                </div>
                <div class="card-body">
                    <div class="export-controls" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                        <div>
                            <label>Export Data:</label>
                        </div>
                        <a href="?export=csv&date_range=<?php echo $dateRange; ?><?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>" 
                           class="btn btn-success">
                            <i class="fas fa-file-csv"></i> CSV Export
                        </a>
                        <a href="?export=json&date_range=<?php echo $dateRange; ?><?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>" 
                           class="btn btn-info">
                            <i class="fas fa-file-code"></i> JSON Export
                        </a>
                        <?php if($performanceData): ?>
                        <div style="margin-left: 2rem; color: #666;">
                            <?php 
                            $overview = $performanceData['overview'] ?? [];
                            echo "Total Campaigns: " . ($overview['total_campaigns'] ?? 0) . " | ";
                            echo "Response Rate: " . number_format(($overview['response_rate'] ?? 0), 1) . "% | ";
                            echo "Avg Quality Score: " . number_format(($overview['avg_quality_score'] ?? 0), 1);
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Performance Charts -->
            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Email Performance</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="emailChart" width="400" height="200"></canvas></div>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <span class="legend-color" style="background: #4facfe;"></span>
                                <span>Sent (<?php echo $emailStats['sent_emails'] ?? 0; ?>)</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color" style="background: #43e97b;"></span>
                                <span>Replied (<?php echo $emailStats['replied_emails'] ?? 0; ?>)</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color" style="background: #f093fb;"></span>
                                <span>Bounced (<?php echo $emailStats['bounced_emails'] ?? 0; ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-bar"></i> Success Rates</h3>
                    </div>
                    <div class="card-body">
                        <div class="metrics-grid">
                            <div class="metric-card">
                                <div class="metric-value"><?php echo $domainApprovalRate; ?>%</div>
                                <div class="metric-label">Domain Approval Rate</div>
                                <div class="metric-bar">
                                    <div class="metric-bar-fill" style="width: <?php echo $domainApprovalRate; ?>%;"></div>
                                </div>
                            </div>
                            <div class="metric-card">
                                <div class="metric-value"><?php echo $emailSuccessRate; ?>%</div>
                                <div class="metric-label">Email Response Rate</div>
                                <div class="metric-bar">
                                    <div class="metric-bar-fill" style="width: <?php echo $emailSuccessRate; ?>%;"></div>
                                </div>
                            </div>
                            <div class="metric-card">
                                <div class="metric-value"><?php echo $interestRate; ?>%</div>
                                <div class="metric-label">Interest Rate</div>
                                <div class="metric-bar">
                                    <div class="metric-bar-fill" style="width: <?php echo $interestRate; ?>%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" id="email-search-section">
                    <div class="card-header">
                        <h3><i class="fas fa-search"></i> Email Search Performance</h3>
                    </div>
                    <div class="card-body">
                        <div class="metrics-grid">
                            <div class="metric-card">
                                <div class="metric-value"><?php echo $emailSearchStats['total_searches'] ?? 0; ?></div>
                                <div class="metric-label">Total Searches</div>
                                <small class="text-muted">Last <?php echo $dateRange; ?> days</small>
                            </div>
                            <div class="metric-card">
                                <div class="metric-value"><?php echo $emailFoundRate; ?>%</div>
                                <div class="metric-label">Email Discovery Rate</div>
                                <div class="metric-bar">
                                    <div class="metric-bar-fill" style="width: <?php echo $emailFoundRate; ?>%;"></div>
                                </div>
                            </div>
                            <div class="metric-card">
                                <div class="metric-value"><?php echo $emailSearchSuccessRate; ?>%</div>
                                <div class="metric-label">Search Success Rate</div>
                                <div class="metric-bar">
                                    <div class="metric-bar-fill" style="width: <?php echo $emailSearchSuccessRate; ?>%;"></div>
                                </div>
                            </div>
                            <div class="metric-card">
                                <div class="metric-value"><?php echo number_format($emailSearchStats['avg_processing_time'] ?? 0); ?>ms</div>
                                <div class="metric-label">Avg. Processing Time</div>
                                <small class="text-muted">Per search</small>
                            </div>
                        </div>
                        
                        <!-- Email Search Queue Status -->
                        <div class="queue-status mt-3">
                            <h4><i class="fas fa-list"></i> Search Queue Status</h4>
                            <div class="queue-metrics">
                                <?php foreach ($queueStats['by_status'] as $status => $count): ?>
                                    <div class="queue-metric">
                                        <span class="status status-<?php echo $status; ?>"><?php echo ucfirst($status); ?></span>
                                        <span class="count"><?php echo $count; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($queueStats['health']['stuck_jobs'] > 0): ?>
                                <div class="alert alert-warning mt-2">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?php echo $queueStats['health']['stuck_jobs']; ?> stuck jobs detected
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card" id="api-usage-section">
                    <div class="card-header">
                        <h3><i class="fas fa-cloud"></i> API Usage & Rate Limits</h3>
                    </div>
                    <div class="card-body">
                        <!-- Tomba API Rate Limits -->
                        <div class="api-rate-limits mb-4">
                            <h4><i class="fas fa-tachometer-alt"></i> Tomba API Rate Limits</h4>
                            <div class="rate-limit-grid">
                                <div class="rate-limit-item">
                                    <div class="rate-limit-label">Current Minute</div>
                                    <div class="rate-limit-bar">
                                        <div class="rate-limit-fill" style="width: <?php echo $apiUsageStats['usage']['minute']['percentage']; ?>%;"></div>
                                    </div>
                                    <div class="rate-limit-text">
                                        <?php echo $apiUsageStats['usage']['minute']['requests']; ?> / <?php echo $apiUsageStats['usage']['minute']['limit']; ?>
                                        (<?php echo $apiUsageStats['usage']['minute']['percentage']; ?>%)
                                    </div>
                                </div>
                                <div class="rate-limit-item">
                                    <div class="rate-limit-label">Current Hour</div>
                                    <div class="rate-limit-bar">
                                        <div class="rate-limit-fill" style="width: <?php echo $apiUsageStats['usage']['hour']['percentage']; ?>%;"></div>
                                    </div>
                                    <div class="rate-limit-text">
                                        <?php echo $apiUsageStats['usage']['hour']['requests']; ?> / <?php echo $apiUsageStats['usage']['hour']['limit']; ?>
                                        (<?php echo $apiUsageStats['usage']['hour']['percentage']; ?>%)
                                    </div>
                                </div>
                                <div class="rate-limit-item">
                                    <div class="rate-limit-label">Today</div>
                                    <div class="rate-limit-bar">
                                        <div class="rate-limit-fill" style="width: <?php echo $apiUsageStats['usage']['today']['percentage']; ?>%;"></div>
                                    </div>
                                    <div class="rate-limit-text">
                                        <?php echo $apiUsageStats['usage']['today']['credits']; ?> / <?php echo $apiUsageStats['usage']['today']['limit']; ?>
                                        (<?php echo $apiUsageStats['usage']['today']['percentage']; ?>%)
                                    </div>
                                </div>
                                <div class="rate-limit-item">
                                    <div class="rate-limit-label">This Month</div>
                                    <div class="rate-limit-bar">
                                        <div class="rate-limit-fill <?php echo $apiUsageStats['usage']['month']['percentage'] >= 90 ? 'critical' : ($apiUsageStats['usage']['month']['percentage'] >= 80 ? 'warning' : ''); ?>" 
                                             style="width: <?php echo $apiUsageStats['usage']['month']['percentage']; ?>%;"></div>
                                    </div>
                                    <div class="rate-limit-text">
                                        <?php echo number_format($apiUsageStats['usage']['month']['credits']); ?> / <?php echo number_format($apiUsageStats['usage']['month']['limit']); ?>
                                        (<?php echo $apiUsageStats['usage']['month']['percentage']; ?>%)
                                    </div>
                                    <?php if ($apiUsageStats['usage']['month']['percentage'] >= 80): ?>
                                        <div class="rate-limit-warning">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <?php echo $apiUsageStats['usage']['month']['percentage'] >= 90 ? 'Critical usage level' : 'High usage level'; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Historical API Usage -->
                        <?php if (!empty($apiUsage)): ?>
                            <h4><i class="fas fa-history"></i> Historical Usage (Last <?php echo $dateRange; ?> days)</h4>
                            <div class="api-usage-list">
                                <?php foreach ($apiUsage as $api): ?>
                                    <div class="api-usage-item">
                                        <div class="api-info">
                                            <strong><?php echo ucfirst($api['api_service']); ?></strong>
                                            <span class="api-calls"><?php echo $api['total_calls']; ?> calls</span>
                                        </div>
                                        <div class="api-metrics">
                                            <span class="credits">Credits: <?php echo $api['credits_used'] ?? 'N/A'; ?></span>
                                            <span class="success-rate">
                                                <?php echo round(($api['successful_calls'] / $api['total_calls']) * 100, 1); ?>% success
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <h4><i class="fas fa-history"></i> Historical Usage</h4>
                            <div class="empty-state">
                                <i class="fas fa-cloud"></i>
                                <p>No API usage data for the selected period.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Campaign Performance Table -->
            <div class="card mt-3">
                <div class="card-header">
                    <h3><i class="fas fa-table"></i> Campaign Performance</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($campaigns)): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Campaign</th>
                                    <th>Status</th>
                                    <th>Domains</th>
                                    <th>Approved</th>
                                    <th>Contacted</th>
                                    <th>Approval Rate</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($campaigns as $camp): ?>
                                    <?php
                                    $campApprovalRate = $camp['total_domains'] > 0 ? 
                                        round(($camp['approved_domains'] / $camp['total_domains']) * 100, 1) : 0;
                                    ?>
                                    <tr class="clickable-row" onclick="window.location.href='campaigns.php?action=view&id=<?php echo $camp['id']; ?>'">
                                        <td><strong><?php echo htmlspecialchars($camp['name']); ?></strong></td>
                                        <td>
                                            <span class="status status-<?php echo $camp['status']; ?>">
                                                <?php echo ucfirst($camp['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $camp['total_domains']; ?></td>
                                        <td><?php echo $camp['approved_domains']; ?></td>
                                        <td><?php echo $camp['contacted_domains']; ?></td>
                                        <td>
                                            <span class="performance-rate rate-<?php echo $campApprovalRate >= 70 ? 'high' : ($campApprovalRate >= 40 ? 'medium' : 'low'); ?>">
                                                <?php echo $campApprovalRate; ?>%
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($camp['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <p>No campaigns to monitor yet.</p>
                            <a href="campaigns.php?action=new" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create Your First Campaign
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card mt-3">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Recent Activity</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($recentActivity)): ?>
                        <div class="activity-feed">
                            <?php foreach ($recentActivity as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <?php
                                        switch ($activity['type']) {
                                            case 'campaign':
                                                echo '<i class="fas fa-bullhorn"></i>';
                                                break;
                                            case 'domain':
                                                echo '<i class="fas fa-globe"></i>';
                                                break;
                                            case 'email':
                                                echo '<i class="fas fa-envelope"></i>';
                                                break;
                                        }
                                        ?>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title"><?php echo htmlspecialchars($activity['action']); ?></div>
                                        <div class="activity-subtitle"><?php echo htmlspecialchars($activity['title']); ?></div>
                                    </div>
                                    <div class="activity-time">
                                        <?php echo date('M j, g:i A', strtotime($activity['timestamp'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <p>No recent activity for the selected period.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Advanced Analytics Section -->
            <?php if($performanceData && !empty($performanceData['trends'])): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-area"></i> Performance Trends</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="trendsChart" width="400" height="200"></canvas>
                    </div>
                    <div class="trends-summary" style="margin-top: 1rem;">
                        <?php 
                        $trends = $performanceData['trends'];
                        if (!empty($trends)):
                        ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div class="metric-summary">
                                <h4>Email Volume Trend</h4>
                                <p>Recent activity shows <?php echo count($trends); ?> data points</p>
                            </div>
                            <div class="metric-summary">
                                <h4>Response Pattern</h4>
                                <p>Analytics available for <?php echo $dateRange; ?>-day period</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Modal Container -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Details</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="loading">
                    <i class="fas fa-spinner fa-spin"></i> Loading...
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js"></script>
    <script src="assets/js/monitoring.js?v=<?php echo time(); ?>"></script>
    <script>
        // Initialize email chart with PHP data
        if (typeof window.initEmailChart === 'function') {
            window.initEmailChart([
                <?php echo $emailStats['sent_emails'] ?? 0; ?>,
                <?php echo $emailStats['replied_emails'] ?? 0; ?>,
                <?php echo $emailStats['bounced_emails'] ?? 0; ?>,
                <?php echo ($emailStats['total_emails'] ?? 0) - ($emailStats['sent_emails'] ?? 0); ?>
            ]);
        }

        // Initialize trends chart if analytics data is available
        <?php if($performanceData && !empty($performanceData['trends'])): ?>
        const trendsChart = document.getElementById('trendsChart');
        if (trendsChart) {
            new Chart(trendsChart, {
                type: 'line',
                data: {
                    labels: [<?php 
                        $trends = $performanceData['trends'];
                        $labels = [];
                        foreach($trends as $trend) {
                            if(isset($trend['date'])) {
                                $labels[] = "'" . date('M j', strtotime($trend['date'])) . "'";
                            }
                        }
                        echo implode(',', $labels);
                    ?>],
                    datasets: [{
                        label: 'Email Volume',
                        data: [<?php 
                            $data = [];
                            foreach($trends as $trend) {
                                $data[] = $trend['emails_sent'] ?? 0;
                            }
                            echo implode(',', $data);
                        ?>],
                        borderColor: '#4facfe',
                        backgroundColor: 'rgba(79, 172, 254, 0.1)',
                        borderWidth: 2,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        }
                    }
                }
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>