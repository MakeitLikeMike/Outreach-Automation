<?php
require_once 'classes/Campaign.php';
require_once 'classes/TargetDomain.php';
require_once 'config/database.php';

$db = new Database();

// Get automation statistics
$automatedCampaigns = $db->fetchAll("SELECT * FROM campaigns WHERE is_automated = 1 ORDER BY created_at DESC");
$totalStats = $db->fetchOne("
    SELECT 
        COUNT(DISTINCT c.id) as total_campaigns,
        COUNT(DISTINCT td.id) as total_domains,
        COUNT(DISTINCT CASE WHEN td.status = 'approved' THEN td.id END) as approved_domains,
        COUNT(DISTINCT CASE WHEN td.status = 'contacted' THEN td.id END) as contacted_domains,
        SUM(c.emails_sent) as total_emails_sent,
        SUM(c.leads_forwarded) as total_leads_forwarded
    FROM campaigns c 
    LEFT JOIN target_domains td ON c.id = td.campaign_id 
    WHERE c.is_automated = 1
");

// Check automation health
$logFile = __DIR__ . '/logs/automation.log';
$automationStatus = 'unknown';
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    if (!empty($lines)) {
        $lastLogEntry = end($lines);
        // Check if automation ran in the last 2 hours
        $lastRunTime = strtotime(substr($lastLogEntry, 1, 19)); // Extract timestamp
        if ($lastRunTime > strtotime('-2 hours')) {
            $automationStatus = 'active';
        } else {
            $automationStatus = 'inactive';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automation Dashboard</title>
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
            <li><a href="quick_outreach.php"><i class="fas fa-rocket"></i> Quick Outreach</a></li>
            <li><a href="domains.php"><i class="fas fa-globe"></i> Domain Analysis</a></li>
            <li><a href="templates.php"><i class="fas fa-file-text"></i> Email Templates</a></li>
            <li><a href="monitoring.php"><i class="fas fa-chart-line"></i> Monitoring</a></li>
            <li><a href="automation_dashboard.php" class="active"><i class="fas fa-robot"></i> Automation</a></li>
            <li><a href="api_status.php"><i class="fas fa-wifi"></i> API Status</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <h1>Automation Dashboard</h1>
            </div>
            <div class="header-right">
                <button onclick="processDomains()" class="btn btn-success">
                    <i class="fas fa-cogs"></i> Process Domains Now
                </button>
                <button onclick="runAutomation()" class="btn btn-primary">
                    <i class="fas fa-play"></i> Run Full Automation
                </button>
            </div>
        </header>

        <div style="padding: 2rem;">
            <!-- Automation Status -->
            <div class="content-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                <div class="stat-card">
                    <div class="stat-icon <?php echo $automationStatus === 'active' ? 'bg-success' : 'bg-warning'; ?>">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo ucfirst($automationStatus); ?></h3>
                        <p>Automation Status</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon bg-primary">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $totalStats['total_campaigns'] ?? 0; ?></h3>
                        <p>Automated Campaigns</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon bg-info">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $totalStats['total_emails_sent'] ?? 0; ?></h3>
                        <p>Emails Sent</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon bg-success">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $totalStats['total_leads_forwarded'] ?? 0; ?></h3>
                        <p>Leads Generated</p>
                    </div>
                </div>
            </div>

            <!-- Pipeline Overview -->
            <div class="card mt-3">
                <div class="card-header">
                    <h3><i class="fas fa-sitemap"></i> Automation Pipeline</h3>
                    <p class="subtitle">Complete end-to-end automation from competitor URL to qualified leads</p>
                </div>
                <div class="card-body">
                    <div class="pipeline-overview">
                        <div class="pipeline-step">
                            <div class="step-number">1</div>
                            <div class="step-content">
                                <h4>Competitor Analysis</h4>
                                <p>Fetch backlinks, filter domains (DR > 30)</p>
                                <div class="step-stat"><?php echo $totalStats['total_domains'] ?? 0; ?> domains</div>
                            </div>
                        </div>
                        
                        <div class="pipeline-step">
                            <div class="step-number">2</div>
                            <div class="step-content">
                                <h4>Domain Analysis</h4>
                                <p>AI-powered quality analysis</p>
                                <div class="step-stat"><?php echo $totalStats['approved_domains'] ?? 0; ?> approved</div>
                            </div>
                        </div>
                        
                        <div class="pipeline-step">
                            <div class="step-number">3</div>
                            <div class="step-content">
                                <h4>Email Discovery</h4>
                                <p>Find contacts via Tomba API</p>
                                <div class="step-stat"><?php echo $totalStats['contacted_domains'] ?? 0; ?> emails</div>
                            </div>
                        </div>
                        
                        <div class="pipeline-step">
                            <div class="step-number">4</div>
                            <div class="step-content">
                                <h4>Outreach & Leads</h4>
                                <p>Send emails, monitor for casino acceptance</p>
                                <div class="step-stat"><?php echo $totalStats['total_leads_forwarded'] ?? 0; ?> leads</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Automated Campaigns -->
            <div class="card mt-3">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Automated Campaigns</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($automatedCampaigns)): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Campaign</th>
                                    <th>Mode</th>
                                    <th>Emails Sent</th>
                                    <th>Leads</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($automatedCampaigns as $camp): ?>
                                    <tr class="clickable-row" onclick="window.location.href='campaigns.php?action=view&id=<?php echo $camp['id']; ?>'">
                                        <td><strong><?php echo htmlspecialchars($camp['name']); ?></strong></td>
                                        <td>
                                            <span class="badge <?php echo $camp['automation_mode'] === 'auto_generate' ? 'badge-success' : 'badge-primary'; ?>">
                                                <?php echo $camp['automation_mode'] === 'auto_generate' ? 'AI' : 'Template'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $camp['emails_sent'] ?? 0; ?></td>
                                        <td><?php echo $camp['leads_forwarded'] ?? 0; ?></td>
                                        <td>
                                            <span class="status status-<?php echo $camp['status']; ?>">
                                                <?php echo ucfirst($camp['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-robot"></i>
                            <p>No automated campaigns yet.</p>
                            <a href="campaigns.php?action=new" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create Automated Campaign
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script>
        function processDomains() {
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            
            fetch('trigger_background_processing.php')
                .then(response => response.text())
                .then(data => {
                    alert('Domain processing completed!\n\n' + data);
                    location.reload();
                })
                .catch(error => {
                    alert('Error processing domains: ' + error.message);
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHTML;
                });
        }
        
        function runAutomation() {
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running...';
            
            fetch('run_automation.php')
                .then(response => response.text())
                .then(data => {
                    alert('Full automation completed! Check the logs for details.');
                    location.reload();
                })
                .catch(error => {
                    alert('Error running automation: ' + error.message);
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalHTML;
                });
        }
    </script>
    
    <style>
        .pipeline-overview {
            display: flex;
            justify-content: space-between;
            margin: 2rem 0;
            gap: 1rem;
        }
        
        .pipeline-step {
            text-align: center;
            flex: 1;
            position: relative;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        
        .pipeline-step:not(:last-child)::after {
            content: '→';
            position: absolute;
            right: -15px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.5rem;
            color: #cbd5e0;
            background: white;
            padding: 0 5px;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #4299e1;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin: 0 auto 1rem;
        }
        
        .step-content h4 {
            margin-bottom: 0.5rem;
            color: #2d3748;
            font-size: 1rem;
        }
        
        .step-content p {
            font-size: 0.875rem;
            color: #718096;
            margin-bottom: 0.5rem;
        }
        
        .step-stat {
            font-weight: bold;
            color: #4299e1;
            font-size: 0.875rem;
        }
        
        .subtitle {
            margin: 0;
            font-size: 0.875rem;
            color: #718096;
        }
    </style>
</body>
</html>