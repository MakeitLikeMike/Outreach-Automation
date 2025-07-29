<?php
require_once 'classes/Campaign.php';
require_once 'classes/TargetDomain.php';
require_once 'config/database.php';

$campaign = new Campaign();
$targetDomain = new TargetDomain();
$db = new Database();

$campaignId = $_GET['campaign_id'] ?? null;
$dateRange = $_GET['date_range'] ?? '30';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring & Analytics - Outreach Automation</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            <li><a href="monitoring.php" class="active"><i class="fas fa-chart-line"></i> Monitoring</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-header">
            <h1>Campaign Monitoring & Analytics</h1>
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
                <div class="stat-card">
                    <div class="stat-icon campaigns">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $totalCampaigns; ?></h3>
                        <p>Active Campaigns</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon domains">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $emailStats['sent_emails'] ?? 0; ?></h3>
                        <p>Emails Sent</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon approved">
                        <i class="fas fa-reply"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $emailStats['replied_emails'] ?? 0; ?></h3>
                        <p>Replies Received</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon contacted">
                        <i class="fas fa-percentage"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $emailSuccessRate; ?>%</h3>
                        <p>Response Rate</p>
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
                        <canvas id="emailChart" width="400" height="200"></canvas>
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

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-cloud"></i> API Usage</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($apiUsage)): ?>
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
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($campaigns as $camp): ?>
                                    <?php
                                    $campApprovalRate = $camp['total_domains'] > 0 ? 
                                        round(($camp['approved_domains'] / $camp['total_domains']) * 100, 1) : 0;
                                    ?>
                                    <tr>
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
                                        <td>
                                            <a href="campaigns.php?action=view&id=<?php echo $camp['id']; ?>" class="btn btn-sm btn-info">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
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
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script>
        function updateDateRange(days) {
            const url = new URL(window.location);
            url.searchParams.set('date_range', days);
            window.location = url;
        }

        // Email Performance Chart
        const emailCtx = document.getElementById('emailChart').getContext('2d');
        new Chart(emailCtx, {
            type: 'doughnut',
            data: {
                labels: ['Sent', 'Replied', 'Bounced', 'Pending'],
                datasets: [{
                    data: [
                        <?php echo $emailStats['sent_emails'] ?? 0; ?>,
                        <?php echo $emailStats['replied_emails'] ?? 0; ?>,
                        <?php echo $emailStats['bounced_emails'] ?? 0; ?>,
                        <?php echo ($emailStats['total_emails'] ?? 0) - ($emailStats['sent_emails'] ?? 0); ?>
                    ],
                    backgroundColor: [
                        '#4facfe',
                        '#43e97b',
                        '#f093fb',
                        '#e2e8f0'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    </script>
</body>
</html>