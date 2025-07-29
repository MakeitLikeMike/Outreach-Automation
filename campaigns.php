<?php
require_once 'classes/Campaign.php';
require_once 'classes/TargetDomain.php';
require_once 'classes/ApiIntegration.php';

$campaign = new Campaign();
$targetDomain = new TargetDomain();
$api = new ApiIntegration();

$action = $_GET['action'] ?? 'list';
$campaignId = $_GET['id'] ?? null;
$message = '';
$error = '';

if ($_POST) {
    try {
        if ($action === 'create') {
            $name = $_POST['name'];
            $competitor_urls = $_POST['competitor_urls'];
            $owner_email = $_POST['owner_email'];
            
            $id = $campaign->create($name, $competitor_urls, $owner_email);
            
            if (!empty($competitor_urls)) {
                $urls = array_filter(array_map('trim', explode("\n", $competitor_urls)));
                $totalDomainsProcessed = 0;
                
                foreach ($urls as $url) {
                    try {
                        // Auto-detect and add protocol if missing
                        if (!preg_match('/^https?:\/\//', $url)) {
                            // First try HTTPS, then fallback to HTTP if needed
                            $url = 'https://' . $url;
                        }
                        
                        // Add URL validation
                        if (!filter_var($url, FILTER_VALIDATE_URL)) {
                            $error .= "Invalid URL format: $url<br>";
                            continue;
                        }
                        
                        $domains = $api->fetchBacklinks($url);
                        
                        if (empty($domains)) {
                            $error .= "No backlinks found for: $url<br>";
                            continue;
                        }
                        
                        foreach ($domains as $domain) {
                            try {
                                $metrics = $api->getDomainMetrics($domain);
                                $email = $api->findEmail($domain);
                                $metrics['contact_email'] = $email;
                                
                                // Calculate quality score
                                $quality_score = calculateQualityScore($metrics);
                                $metrics['quality_score'] = $quality_score;
                                
                                $targetDomain->create($id, $domain, $metrics);
                                $totalDomainsProcessed++;
                            } catch (Exception $e) {
                                $error .= "Error processing domain $domain: " . $e->getMessage() . "<br>";
                            }
                        }
                        
                        $message .= "Processed " . count($domains) . " domains from $url<br>";
                        
                    } catch (Exception $e) {
                        $error .= "Error processing $url: " . $e->getMessage() . "<br>";
                    }
                }
                
                if ($totalDomainsProcessed > 0) {
                    $message = "Campaign created successfully! Processed $totalDomainsProcessed domains total.";
                } else {
                    $message = "Campaign created but no domains were processed. Check your API credentials and competitor URLs.";
                }
            }
            
            $action = 'list';
        } elseif ($action === 'update' && $campaignId) {
            $name = $_POST['name'];
            $competitor_urls = $_POST['competitor_urls'];
            $owner_email = $_POST['owner_email'];
            $status = $_POST['status'];
            
            $campaign->update($campaignId, $name, $competitor_urls, $owner_email, $status);
            $message = "Campaign updated successfully!";
            $action = 'list';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($action === 'delete' && $campaignId) {
    try {
        $campaign->delete($campaignId);
        $message = "Campaign deleted successfully!";
        $action = 'list';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$campaigns = $campaign->getAll();
$currentCampaign = null;
if ($campaignId && in_array($action, ['edit', 'view'])) {
    $currentCampaign = $campaign->getById($campaignId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns - Outreach Automation</title>
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
            <li><a href="campaigns.php" class="active"><i class="fas fa-bullhorn"></i> Campaigns</a></li>
            <li><a href="domains.php"><i class="fas fa-globe"></i> Domain Analysis</a></li>
            <li><a href="templates.php"><i class="fas fa-file-text"></i> Email Templates</a></li>
            <li><a href="monitoring.php"><i class="fas fa-chart-line"></i> Monitoring</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-header">
            <h1>
                <?php
                switch ($action) {
                    case 'new':
                    case 'create':
                        echo 'Create New Campaign';
                        break;
                    case 'edit':
                        echo 'Edit Campaign';
                        break;
                    case 'view':
                        echo 'Campaign Details';
                        break;
                    default:
                        echo 'Campaign Management';
                }
                ?>
            </h1>
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

            <?php if ($action === 'list'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bullhorn"></i> All Campaigns</h3>
                        <a href="campaigns.php?action=new" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Campaign
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($campaigns)): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Campaign Name</th>
                                        <th>Owner Email</th>
                                        <th>Status</th>
                                        <th>Domains</th>
                                        <th>Approved</th>
                                        <th>Contacted</th>
                                        <th>Forwarded</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($campaigns as $camp): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($camp['name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($camp['owner_email'] ?? 'Not set'); ?></td>
                                            <td>
                                                <span class="status status-<?php echo $camp['status']; ?>">
                                                    <?php echo ucfirst($camp['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $camp['total_domains']; ?></td>
                                            <td><?php echo $camp['approved_domains']; ?></td>
                                            <td><?php echo $camp['contacted_domains']; ?></td>
                                            <td><?php echo $camp['forwarded_emails'] ?? 0; ?></td>
                                            <td><?php echo date('M j, Y', strtotime($camp['created_at'])); ?></td>
                                            <td>
                                                <a href="campaigns.php?action=view&id=<?php echo $camp['id']; ?>" class="btn btn-sm btn-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="campaigns.php?action=edit&id=<?php echo $camp['id']; ?>" class="btn btn-sm btn-secondary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="campaigns.php?action=delete&id=<?php echo $camp['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-bullhorn"></i>
                                <p>No campaigns created yet.</p>
                                <a href="campaigns.php?action=new" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Create Your First Campaign
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($action === 'new' || $action === 'create'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-plus"></i> Create New Campaign</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="campaigns.php?action=create">
                            <div class="form-group">
                                <label for="name">Campaign Name *</label>
                                <input type="text" id="name" name="name" class="form-control" required 
                                       placeholder="e.g., Technology Guest Posts Q1 2024">
                            </div>

                            <div class="form-group">
                                <label for="owner_email">Campaign Owner Email *</label>
                                <input type="email" id="owner_email" name="owner_email" class="form-control" required 
                                       placeholder="owner@yourcompany.com">
                                <small class="help-text">Email where done deal outreach will be forwarded for publication coordination</small>
                            </div>

                            <div class="form-group">
                                <label for="competitor_urls">Competitor URLs</label>
                                <textarea id="competitor_urls" name="competitor_urls" class="form-control textarea" 
                                          placeholder="Enter competitor URLs (one per line)&#10;example1.com&#10;https://example2.com&#10;competitor3.com"></textarea>
                                <small class="help-text">Enter competitor URLs to analyze their backlinks. One URL per line. HTTPS will be added automatically if no protocol is specified.</small>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Create Campaign
                                </button>
                                <a href="campaigns.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'edit' && $currentCampaign): ?>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-edit"></i> Edit Campaign</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="campaigns.php?action=update&id=<?php echo $currentCampaign['id']; ?>">
                            <div class="form-group">
                                <label for="name">Campaign Name *</label>
                                <input type="text" id="name" name="name" class="form-control" required 
                                       value="<?php echo htmlspecialchars($currentCampaign['name']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="owner_email">Campaign Owner Email *</label>
                                <input type="email" id="owner_email" name="owner_email" class="form-control" required 
                                       value="<?php echo htmlspecialchars($currentCampaign['owner_email'] ?? ''); ?>">
                                <small class="help-text">Email where done deal outreach will be forwarded for publication coordination</small>
                            </div>

                            <div class="form-group">
                                <label for="competitor_urls">Competitor URLs</label>
                                <textarea id="competitor_urls" name="competitor_urls" class="form-control textarea"><?php echo htmlspecialchars($currentCampaign['competitor_urls']); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="active" <?php echo $currentCampaign['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="paused" <?php echo $currentCampaign['status'] === 'paused' ? 'selected' : ''; ?>>Paused</option>
                                    <option value="completed" <?php echo $currentCampaign['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Campaign
                                </button>
                                <a href="campaigns.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'view' && $currentCampaign): ?>
                <?php
                $stats = $campaign->getStats($currentCampaign['id']);
                $domains = $targetDomain->getByCampaign($currentCampaign['id']);
                ?>
                <div class="content-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-info-circle"></i> Campaign Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="detail-item">
                                <strong>Name:</strong> <?php echo htmlspecialchars($currentCampaign['name']); ?>
                            </div>
                            <div class="detail-item">
                                <strong>Owner Email:</strong> <?php echo htmlspecialchars($currentCampaign['owner_email'] ?? 'Not set'); ?>
                            </div>
                            <div class="detail-item">
                                <strong>Status:</strong> 
                                <span class="status status-<?php echo $currentCampaign['status']; ?>">
                                    <?php echo ucfirst($currentCampaign['status']); ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($currentCampaign['created_at'])); ?>
                            </div>
                            <div class="detail-item">
                                <strong>Last Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($currentCampaign['updated_at'])); ?>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-chart-bar"></i> Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="stat-grid">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['total_domains'] ?? 0; ?></div>
                                    <div class="stat-label">Total Domains</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['approved'] ?? 0; ?></div>
                                    <div class="stat-label">Approved</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['contacted'] ?? 0; ?></div>
                                    <div class="stat-label">Contacted</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo number_format($stats['avg_quality_score'] ?? 0, 1); ?></div>
                                    <div class="stat-label">Avg Quality</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h3><i class="fas fa-globe"></i> Target Domains (<?php echo count($domains); ?>)</h3>
                        <div class="actions">
                            <a href="domains.php?campaign_id=<?php echo $currentCampaign['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-search"></i> Analyze More Domains
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($domains)): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Domain</th>
                                        <th>DR</th>
                                        <th>Traffic</th>
                                        <th>Quality</th>
                                        <th>Status</th>
                                        <th>Email</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($domains, 0, 10) as $domain): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($domain['domain']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="domain-rating"><?php echo $domain['domain_rating']; ?></span>
                                            </td>
                                            <td><?php echo number_format($domain['organic_traffic']); ?></td>
                                            <td><?php echo number_format($domain['quality_score'], 1); ?></td>
                                            <td>
                                                <span class="status status-<?php echo $domain['status']; ?>">
                                                    <?php echo ucfirst($domain['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($domain['contact_email']): ?>
                                                    <a href="mailto:<?php echo $domain['contact_email']; ?>">
                                                        <?php echo $domain['contact_email']; ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">Not found</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="domains.php?action=view&id=<?php echo $domain['id']; ?>" class="btn btn-sm btn-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (count($domains) > 10): ?>
                                <div class="text-center mt-3">
                                    <a href="domains.php?campaign_id=<?php echo $currentCampaign['id']; ?>" class="btn btn-secondary">
                                        View All <?php echo count($domains); ?> Domains
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-globe"></i>
                                <p>No domains found for this campaign.</p>
                                <a href="domains.php?campaign_id=<?php echo $currentCampaign['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Start Domain Analysis
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
</body>
</html>