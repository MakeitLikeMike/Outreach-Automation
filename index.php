<?php
require_once 'classes/Campaign.php';
require_once 'classes/TargetDomain.php';

$campaign = new Campaign();
$targetDomain = new TargetDomain();

$campaigns = $campaign->getAll();
$recentDomains = [];
if (!empty($campaigns)) {
    $recentDomains = $targetDomain->getByCampaign($campaigns[0]['id']);
    $recentDomains = array_slice($recentDomains, 0, 5);
}

$totalCampaigns = count($campaigns);
$totalDomains = array_sum(array_column($campaigns, 'total_domains'));
$approvedDomains = array_sum(array_column($campaigns, 'approved_domains'));
$contactedDomains = array_sum(array_column($campaigns, 'contacted_domains'));
$forwardedEmails = array_sum(array_column($campaigns, 'forwarded_emails'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Post Outreach Automation System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">
            <h2><i class="fas fa-envelope"></i> Outreach Automation</h2>
        </div>
        <ul class="nav-menu">
            <li><a href="index.php" class="active"><i class="fas fa-dashboard"></i> Dashboard</a></li>
            <li><a href="campaigns.php"><i class="fas fa-bullhorn"></i> Campaigns</a></li>
            <li><a href="domains.php"><i class="fas fa-globe"></i> Domain Analysis</a></li>
            <li><a href="templates.php"><i class="fas fa-file-text"></i> Email Templates</a></li>
            <li><a href="monitoring.php"><i class="fas fa-chart-line"></i> Monitoring</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-header">
            <h1>Dashboard Overview</h1>
        </header>

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
                    <i class="fas fa-globe"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $totalDomains; ?></h3>
                    <p>Total Domains</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon approved">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $approvedDomains; ?></h3>
                    <p>Approved Domains</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon contacted">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $contactedDomains; ?></h3>
                    <p>Contacted</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon forwarded">
                    <i class="fas fa-share"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $forwardedEmails; ?></h3>
                    <p>Forwarded Emails</p>
                </div>
            </div>
        </div>

        <div class="content-grid">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-plus"></i> Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        <a href="campaigns.php?action=new" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Campaign
                        </a>
                        <a href="domains.php?action=analyze" class="btn btn-secondary">
                            <i class="fas fa-search"></i> Analyze Domains
                        </a>
                        <a href="templates.php" class="btn btn-info">
                            <i class="fas fa-edit"></i> Edit Templates
                        </a>
                        <a href="monitoring.php" class="btn btn-success">
                            <i class="fas fa-eye"></i> View Reports
                        </a>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Campaign Performance</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($campaigns)): ?>
                        <div class="campaign-list">
                            <?php foreach (array_slice($campaigns, 0, 3) as $camp): ?>
                                <div class="campaign-item">
                                    <div class="campaign-info">
                                        <h4><?php echo htmlspecialchars($camp['name']); ?></h4>
                                        <span class="campaign-status status-<?php echo $camp['status']; ?>">
                                            <?php echo ucfirst($camp['status']); ?>
                                        </span>
                                    </div>
                                    <div class="campaign-stats">
                                        <span class="stat">
                                            <i class="fas fa-globe"></i> <?php echo $camp['total_domains']; ?>
                                        </span>
                                        <span class="stat">
                                            <i class="fas fa-check"></i> <?php echo $camp['approved_domains']; ?>
                                        </span>
                                        <span class="stat">
                                            <i class="fas fa-paper-plane"></i> <?php echo $camp['contacted_domains']; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="campaigns.php" class="view-all">View All Campaigns →</a>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <p>No campaigns yet. <a href="campaigns.php?action=new">Create your first campaign</a></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Recent Domains</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($recentDomains)): ?>
                        <div class="domain-list">
                            <?php foreach ($recentDomains as $domain): ?>
                                <div class="domain-item">
                                    <div class="domain-info">
                                        <strong><?php echo htmlspecialchars($domain['domain']); ?></strong>
                                        <span class="domain-rating">DR: <?php echo $domain['domain_rating']; ?></span>
                                    </div>
                                    <div class="domain-metrics">
                                        <span class="metric">
                                            <i class="fas fa-chart-line"></i> <?php echo number_format($domain['organic_traffic']); ?>
                                        </span>
                                        <span class="status status-<?php echo $domain['status']; ?>">
                                            <?php echo ucfirst($domain['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="domains.php" class="view-all">View All Domains →</a>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-globe"></i>
                            <p>No domains analyzed yet. <a href="domains.php?action=analyze">Start analyzing</a></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
</body>
</html>