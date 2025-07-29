<?php
require_once 'classes/Campaign.php';
require_once 'classes/TargetDomain.php';
require_once 'classes/ApiIntegration.php';

$campaign = new Campaign();
$targetDomain = new TargetDomain();
$api = new ApiIntegration();

$action = $_GET['action'] ?? 'list';
$domainId = $_GET['id'] ?? null;
$campaignId = $_GET['campaign_id'] ?? null;
$message = '';
$error = '';

if ($_POST) {
    try {
        if ($action === 'update_status' && $domainId) {
            $status = $_POST['status'];
            $targetDomain->updateStatus($domainId, $status);
            $message = "Domain status updated successfully!";
        } elseif ($action === 'analyze_domain') {
            $domain = $_POST['domain'];
            $campaign_id = $_POST['campaign_id'];
            
            try {
                $metrics = $api->getDomainMetrics($domain);
                $email = $api->findEmail($domain);
                $metrics['contact_email'] = $email;
                
                $quality_score = calculateQualityScore($metrics);
                $metrics['quality_score'] = $quality_score;
                
                $id = $targetDomain->create($campaign_id, $domain, $metrics);
                $message = "Domain analyzed and added successfully!";
            } catch (Exception $e) {
                $error = "Error analyzing domain: " . $e->getMessage();
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$campaigns = $campaign->getAll();
$domains = [];
$selectedCampaign = null;

if ($campaignId) {
    $domains = $targetDomain->getByCampaign($campaignId);
    $selectedCampaign = $campaign->getById($campaignId);
} else {
    $domains = $targetDomain->getQualityFiltered();
}

$currentDomain = null;
if ($domainId && $action === 'view') {
    $currentDomain = $targetDomain->getById($domainId);
}

function calculateQualityScore($metrics) {
    $score = 0;
    
    if (($metrics['domain_rating'] ?? 0) >= 30) $score += 25;
    if (($metrics['organic_traffic'] ?? 0) >= 5000) $score += 25;
    if (($metrics['referring_domains'] ?? 0) >= 100) $score += 25;
    if (($metrics['ranking_keywords'] ?? 0) >= 100) $score += 25;
    
    return $score;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Analysis - Outreach Automation</title>
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
            <li><a href="domains.php" class="active"><i class="fas fa-globe"></i> Domain Analysis</a></li>
            <li><a href="templates.php"><i class="fas fa-file-text"></i> Email Templates</a></li>
            <li><a href="monitoring.php"><i class="fas fa-chart-line"></i> Monitoring</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-header">
            <h1>Domain Analysis</h1>
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

            <?php if ($action === 'view' && $currentDomain): ?>
                <div class="content-grid" style="grid-template-columns: 2fr 1fr;">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-globe"></i> Domain Details</h3>
                            <div class="actions">
                                <a href="https://<?php echo $currentDomain['domain']; ?>" target="_blank" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-external-link-alt"></i> Visit Site
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="detail-item">
                                <strong>Domain:</strong> 
                                <a href="https://<?php echo $currentDomain['domain']; ?>" target="_blank">
                                    <?php echo htmlspecialchars($currentDomain['domain']); ?>
                                </a>
                            </div>
                            <div class="detail-item">
                                <strong>Campaign:</strong> <?php echo htmlspecialchars($currentDomain['campaign_name']); ?>
                            </div>
                            <div class="detail-item">
                                <strong>Status:</strong> 
                                <span class="status status-<?php echo $currentDomain['status']; ?>">
                                    <?php echo ucfirst($currentDomain['status']); ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <strong>Quality Score:</strong> 
                                <span class="quality-score score-<?php echo $currentDomain['quality_score'] >= 75 ? 'high' : ($currentDomain['quality_score'] >= 50 ? 'medium' : 'low'); ?>">
                                    <?php echo $currentDomain['quality_score']; ?>/100
                                </span>
                            </div>
                            <div class="detail-item">
                                <strong>Contact Email:</strong> 
                                <?php if ($currentDomain['contact_email']): ?>
                                    <a href="mailto:<?php echo $currentDomain['contact_email']; ?>">
                                        <?php echo $currentDomain['contact_email']; ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">Not found</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-bar"></i> Metrics</h3>
                        </div>
                        <div class="card-body">
                            <div class="metric-item">
                                <div class="metric-label">Domain Rating</div>
                                <div class="metric-value"><?php echo $currentDomain['domain_rating']; ?></div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-label">Organic Traffic</div>
                                <div class="metric-value"><?php echo number_format($currentDomain['organic_traffic']); ?></div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-label">Referring Domains</div>
                                <div class="metric-value"><?php echo number_format($currentDomain['referring_domains']); ?></div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-label">Ranking Keywords</div>
                                <div class="metric-value"><?php echo number_format($currentDomain['ranking_keywords']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h3><i class="fas fa-edit"></i> Update Status</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="domains.php?action=update_status&id=<?php echo $currentDomain['id']; ?>">
                            <div style="display: flex; gap: 1rem; align-items: center;">
                                <select name="status" class="form-control" style="width: auto;">
                                    <option value="pending" <?php echo $currentDomain['status'] === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                    <option value="approved" <?php echo $currentDomain['status'] === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $currentDomain['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="contacted" <?php echo $currentDomain['status'] === 'contacted' ? 'selected' : ''; ?>>Contacted</option>
                                </select>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Status
                                </button>
                                <a href="domains.php<?php echo $campaignId ? '?campaign_id=' . $campaignId : ''; ?>" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Back to List
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'analyze'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-search"></i> Analyze New Domain</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="domains.php?action=analyze_domain">
                            <div class="form-group">
                                <label for="campaign_id">Select Campaign *</label>
                                <select id="campaign_id" name="campaign_id" class="form-control" required>
                                    <option value="">Choose a campaign...</option>
                                    <?php foreach ($campaigns as $camp): ?>
                                        <option value="<?php echo $camp['id']; ?>" <?php echo $campaignId == $camp['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($camp['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="domain">Domain to Analyze *</label>
                                <input type="text" id="domain" name="domain" class="form-control" required 
                                       placeholder="example.com (without http://)">
                                <small class="help-text">Enter the domain without protocol (e.g., example.com)</small>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Analyze Domain
                                </button>
                                <a href="domains.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-globe"></i> 
                            <?php if ($selectedCampaign): ?>
                                Domains for "<?php echo htmlspecialchars($selectedCampaign['name']); ?>"
                            <?php else: ?>
                                All Quality Domains
                            <?php endif ?>
                        </h3>
                        <div class="actions">
                            <a href="domains.php?action=analyze<?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Analyze Domain
                            </a>
                            <?php if ($campaignId): ?>
                                <a href="domains.php" class="btn btn-secondary">
                                    <i class="fas fa-list"></i> View All Domains
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($campaigns) && !$campaignId): ?>
                            <div class="filter-section mb-3">
                                <label for="campaign-filter">Filter by Campaign:</label>
                                <select id="campaign-filter" class="form-control" style="width: auto; display: inline-block;" onchange="filterByCampaign(this.value)">
                                    <option value="">All Campaigns</option>
                                    <?php foreach ($campaigns as $camp): ?>
                                        <option value="<?php echo $camp['id']; ?>">
                                            <?php echo htmlspecialchars($camp['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($domains)): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Domain</th>
                                        <th>Campaign</th>
                                        <th>DR</th>
                                        <th>Traffic</th>
                                        <th>Domains</th>
                                        <th>Keywords</th>
                                        <th>Quality</th>
                                        <th>Status</th>
                                        <th>Contact</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($domains as $domain): ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <a href="https://<?php echo $domain['domain']; ?>" target="_blank">
                                                        <?php echo htmlspecialchars($domain['domain']); ?>
                                                    </a>
                                                </strong>
                                            </td>
                                            <td>
                                                <?php if (isset($domain['campaign_name'])): ?>
                                                    <a href="campaigns.php?action=view&id=<?php echo $domain['campaign_id']; ?>">
                                                        <?php echo htmlspecialchars($domain['campaign_name']); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="domain-rating"><?php echo $domain['domain_rating']; ?></span>
                                            </td>
                                            <td><?php echo number_format($domain['organic_traffic']); ?></td>
                                            <td><?php echo number_format($domain['referring_domains']); ?></td>
                                            <td><?php echo number_format($domain['ranking_keywords']); ?></td>
                                            <td>
                                                <span class="quality-score score-<?php echo $domain['quality_score'] >= 75 ? 'high' : ($domain['quality_score'] >= 50 ? 'medium' : 'low'); ?>">
                                                    <?php echo $domain['quality_score']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status status-<?php echo $domain['status']; ?>">
                                                    <?php echo ucfirst($domain['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($domain['contact_email']): ?>
                                                    <i class="fas fa-check text-success"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-times text-muted"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="domains.php?action=view&id=<?php echo $domain['id']; ?><?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>" class="btn btn-sm btn-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-globe"></i>
                                <p>
                                    <?php if ($campaignId): ?>
                                        No domains found for this campaign.
                                    <?php else: ?>
                                        No quality domains found. Start by creating a campaign and analyzing competitor backlinks.
                                    <?php endif; ?>
                                </p>
                                <a href="domains.php?action=analyze<?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Analyze Domains
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script>
        function filterByCampaign(campaignId) {
            if (campaignId) {
                window.location.href = 'domains.php?campaign_id=' + campaignId;
            } else {
                window.location.href = 'domains.php';
            }
        }
    </script>
</body>
</html>