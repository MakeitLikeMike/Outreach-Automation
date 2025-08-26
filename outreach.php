<?php
require_once 'classes/Campaign.php';
require_once 'classes/EmailQueue.php';
require_once 'classes/GmailIntegration.php';
require_once 'classes/EmailTemplate.php';

$campaign = new Campaign();
$emailQueue = new EmailQueue();
$gmail = new GmailIntegration();
$emailTemplate = new EmailTemplate();

$action = $_GET['action'] ?? 'dashboard';
$campaignId = $_GET['campaign_id'] ?? null;
$message = '';
$error = '';

if ($_POST) {
    try {
        if ($action === 'queue_emails') {
            $campaignId = $_POST['campaign_id'];
            $templateId = $_POST['template_id'] ?? null;
            
            $queuedCount = $emailQueue->queueCampaignEmails($campaignId, $templateId);
            $message = "Successfully queued $queuedCount emails for outreach!";
            
        } elseif ($action === 'authorize_gmail') {
            $userEmail = $_POST['user_email'];
            $authUrl = $gmail->getAuthUrl($userEmail);
            header("Location: $authUrl");
            exit;
            
        } elseif ($action === 'process_oauth') {
            $code = $_GET['code'] ?? '';
            $state = $_GET['state'] ?? '';
            $userEmail = base64_decode($state);
            
            if ($code && $userEmail) {
                $gmail->exchangeCodeForTokens($code, $userEmail);
                $message = "Gmail authorization successful for $userEmail!";
            } else {
                $error = "Gmail authorization failed";
            }
            
        } elseif ($action === 'pause_campaign') {
            $campaignId = $_POST['campaign_id'];
            $emailQueue->pauseCampaignEmails($campaignId);
            $message = "Campaign emails paused successfully!";
            
        } elseif ($action === 'resume_campaign') {
            $campaignId = $_POST['campaign_id'];
            $emailQueue->resumeCampaignEmails($campaignId);
            $message = "Campaign emails resumed successfully!";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$campaigns = $campaign->getAll();
$emailTemplates = $emailTemplate->getAll();
$queueStats = $emailQueue->getQueueStats($campaignId);

$selectedCampaign = null;
if ($campaignId) {
    $selectedCampaign = $campaign->getById($campaignId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Outreach - Outreach Automation</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
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
            <li><a href="domains.php"><i class="fas fa-globe"></i> Domain Analysis</a></li>
            <li><a href="quick_outreach.php"><i class="fas fa-rocket"></i> Quick Outreach</a></li>
            <li><a href="campaigns.php"><i class="fas fa-bullhorn"></i> Campaigns</a></li>
            <li><a href="domain_analyzer.php"><i class="fas fa-search"></i> Domain Analyzer</a></li>
            <li><a href="templates.php"><i class="fas fa-file-text"></i> Email Templates</a></li>
            <li><a href="monitoring.php"><i class="fas fa-chart-line"></i> Monitoring</a></li>
            <!-- <li><a href="outreach.php" class="active"><i class="fas fa-paper-plane"></i> Email Outreach</a></li> -->
            <li><a href="api_status.php"><i class="fas fa-wifi"></i> API Status</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-header">
            <h1>Email Outreach Management</h1>
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

            <?php if ($action === 'dashboard'): ?>
                <!-- Outreach Dashboard -->
                <div class="content-grid">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-rocket"></i> Quick Start Outreach</h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="outreach.php?action=queue_emails">
                                <div class="form-group">
                                    <label for="campaign_id">Select Campaign *</label>
                                    <select id="campaign_id" name="campaign_id" class="form-control" required>
                                        <option value="">Choose a campaign...</option>
                                        <?php foreach ($campaigns as $camp): ?>
                                            <option value="<?php echo $camp['id']; ?>">
                                                <?php echo htmlspecialchars($camp['name']); ?> 
                                                (<?php echo $camp['approved_domains']; ?> approved domains)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="template_id">Email Template</label>
                                    <select id="template_id" name="template_id" class="form-control">
                                        <option value="">Use default template</option>
                                        <?php foreach ($emailTemplates as $template): ?>
                                            <option value="<?php echo $template['id']; ?>">
                                                <?php echo htmlspecialchars($template['name']); ?>
                                                <?php echo $template['is_default'] ? ' (Default)' : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Queue Outreach Emails
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-user-shield"></i> Gmail Authorization</h3>
                        </div>
                        <div class="card-body">
                            <p>Authorize Gmail accounts for sending outreach emails:</p>
                            <form method="POST" action="outreach.php?action=authorize_gmail">
                                <div class="form-group">
                                    <label for="user_email">Gmail Address</label>
                                    <input type="email" id="user_email" name="user_email" class="form-control" 
                                           placeholder="your-email@gmail.com" required>
                                </div>
                                <button type="submit" class="btn btn-secondary">
                                    <i class="fas fa-key"></i> Authorize Gmail
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Email Queue Stats -->
                <?php if (!empty($queueStats)): ?>
                <div class="card mt-3">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> Email Queue Status</h3>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid">
                            <?php foreach ($queueStats as $stat): ?>
                                <div class="stat-item">
                                    <div class="stat-value status-<?php echo $stat['status']; ?>">
                                        <?php echo $stat['count']; ?>
                                    </div>
                                    <div class="stat-label"><?php echo ucfirst($stat['status']); ?> Emails</div>
                                    <?php if ($stat['earliest_scheduled']): ?>
                                        <div class="stat-detail">
                                            Next: <?php echo date('M j, g:i A', strtotime($stat['earliest_scheduled'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Active Campaigns -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h3><i class="fas fa-bullhorn"></i> Active Campaigns</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($campaigns)): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Campaign</th>
                                        <th>Status</th>
                                        <th>Approved Domains</th>
                                        <th>Contacted</th>
                                        <th>Queue Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($campaigns as $camp): ?>
                                        <?php $campQueueStats = $emailQueue->getQueueStats($camp['id']); ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($camp['name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="status status-<?php echo $camp['status']; ?>">
                                                    <?php echo ucfirst($camp['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $camp['approved_domains']; ?></td>
                                            <td><?php echo $camp['contacted_domains']; ?></td>
                                            <td>
                                                <?php if (!empty($campQueueStats)): ?>
                                                    <?php foreach ($campQueueStats as $stat): ?>
                                                        <span class="badge badge-<?php echo $stat['status']; ?>">
                                                            <?php echo $stat['count']; ?> <?php echo $stat['status']; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No emails queued</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="outreach.php?action=manage&campaign_id=<?php echo $camp['id']; ?>" 
                                                   class="btn btn-sm btn-info">
                                                    <i class="fas fa-cog"></i> Manage
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-bullhorn"></i>
                                <p>No campaigns found. Create a campaign first.</p>
                                <a href="campaigns.php?action=new" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Create Campaign
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($action === 'manage' && $selectedCampaign): ?>
                <!-- Campaign Management -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-cog"></i> 
                            Manage: <?php echo htmlspecialchars($selectedCampaign['name']); ?>
                        </h3>
                        <div class="actions">
                            <a href="outreach.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="content-grid">
                            <div class="campaign-controls">
                                <h4>Campaign Controls</h4>
                                <div class="button-group">
                                    <form method="POST" action="outreach.php?action=pause_campaign" style="display: inline;">
                                        <input type="hidden" name="campaign_id" value="<?php echo $campaignId; ?>">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="fas fa-pause"></i> Pause Emails
                                        </button>
                                    </form>
                                    
                                    <form method="POST" action="outreach.php?action=resume_campaign" style="display: inline;">
                                        <input type="hidden" name="campaign_id" value="<?php echo $campaignId; ?>">
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-play"></i> Resume Emails
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="campaign-stats">
                                <h4>Campaign Statistics</h4>
                                <div class="stats-grid">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo $selectedCampaign['total_domains'] ?? 0; ?></div>
                                        <div class="stat-label">Total Domains</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo $selectedCampaign['approved_domains'] ?? 0; ?></div>
                                        <div class="stat-label">Approved</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo $selectedCampaign['contacted_domains'] ?? 0; ?></div>
                                        <div class="stat-label">Contacted</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Queue Details for this Campaign -->
                        <?php $campaignQueueStats = $emailQueue->getQueueStats($campaignId); ?>
                        <?php if (!empty($campaignQueueStats)): ?>
                        <div class="mt-3">
                            <h4>Queue Status</h4>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Count</th>
                                        <th>Earliest Scheduled</th>
                                        <th>Latest Scheduled</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($campaignQueueStats as $stat): ?>
                                        <tr>
                                            <td>
                                                <span class="status status-<?php echo $stat['status']; ?>">
                                                    <?php echo ucfirst($stat['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $stat['count']; ?></td>
                                            <td>
                                                <?php echo $stat['earliest_scheduled'] ? 
                                                    date('M j, Y g:i A', strtotime($stat['earliest_scheduled'])) : 
                                                    'N/A'; ?>
                                            </td>
                                            <td>
                                                <?php echo $stat['latest_scheduled'] ? 
                                                    date('M j, Y g:i A', strtotime($stat['latest_scheduled'])) : 
                                                    'N/A'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script>
        // Auto-refresh queue stats every 30 seconds
        setInterval(function() {
            if (window.location.pathname.includes('outreach.php')) {
                location.reload();
            }
        }, 30000);
        
        // Confirm actions
        document.querySelectorAll('form[action*="pause"], form[action*="resume"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = this.action.includes('pause') ? 'pause' : 'resume';
                if (!confirm(`Are you sure you want to ${action} this campaign's emails?`)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>