<?php
// Get pagination parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25; // Keep small for shared hosting

// Get campaigns with pagination
$campaignService = new CampaignService();
$result = $campaignService->listPaged($page, $perPage);
$campaigns = $result['campaigns'];
$total = $result['total'];
$pages = $result['pages'];

// Get messages from URL parameters
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns - Outreach Automation</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/campaigns.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include APP_ROOT . '/includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button onclick="goBack()" class="back-btn" title="Go Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1>Campaign Management</h1>
            </div>
        </header>

        <div style="padding: 2rem;">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-bullhorn"></i> All Campaigns (<?php echo $total; ?>)</h3>
                    <div class="actions">
                        <button id="bulk-delete-btn" class="btn btn-danger" style="display: none;" onclick="confirmBulkDelete()">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                        <button onclick="restartBackgroundProcessor()" class="btn btn-warning" style="margin-right: 10px;" title="Temporary: Restart background processing for stuck campaigns">
                            <i class="fas fa-sync"></i> Restart Processor
                        </button>
                        <a href="campaigns.php?action=create" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Campaign
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($campaigns)): ?>
                        <form id="bulk-delete-form" method="POST" action="campaigns.php?action=bulk_delete">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" id="select-all" onchange="toggleSelectAll()" title="Select All">
                                    </th>
                                    <th>Campaign Name</th>
                                    <th>Pipeline Status</th>
                                    <th>Progress</th>
                                    <th>Domains</th>
                                    <th>Emails Sent</th>
                                    <th>Qualified Leads</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($campaigns as $camp): ?>
                                    <tr class="clickable-row" onclick="handleRowClick(event, <?php echo $camp['id']; ?>)">
                                        <td onclick="event.stopPropagation();">
                                            <input type="checkbox" name="campaign_ids[]" value="<?php echo $camp['id']; ?>" class="campaign-checkbox" onchange="updateBulkDeleteButton()">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($camp['name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($camp['owner_email'] ?? 'No owner email'); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $pipelineStatus = $camp['pipeline_status'] ?? 'created';
                                            $statusLabels = [
                                                'created' => '📋 Created',
                                                'processing_domains' => '🔍 Finding Domains', 
                                                'analyzing_quality' => '⚖️ Analyzing Quality',
                                                'finding_emails' => '📧 Finding Emails',
                                                'sending_outreach' => '📤 Sending Outreach',
                                                'monitoring_replies' => '👀 Monitoring Replies',
                                                'completed' => '✅ Complete'
                                            ];
                                            ?>
                                            <span class="pipeline-status status-<?php echo $pipelineStatus; ?>">
                                                <?php echo $statusLabels[$pipelineStatus] ?? ucfirst($pipelineStatus); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $progress = $camp['progress_percentage'] ?? 0;
                                            $progressColor = $progress >= 75 ? '#10b981' : ($progress >= 50 ? '#f59e0b' : '#ef4444');
                                            ?>
                                            <div class="progress-bar" title="Campaign Progress: <?php echo $progress; ?>%">
                                                <div class="progress-fill" style="width: <?php echo $progress; ?>%; background: <?php echo $progressColor; ?>"></div>
                                            </div>
                                            <small><?php echo $progress; ?>% complete</small>
                                        </td>
                                        <td>
                                            <div class="metric-stack">
                                                <div><strong><?php echo $camp['total_domains_scraped'] ?? $camp['total_domains'] ?? 0; ?></strong> <small>found</small></div>
                                                <div><strong><?php echo $camp['approved_domains_count'] ?? $camp['approved_domains'] ?? 0; ?></strong> <small>approved</small></div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo $camp['emails_sent_count'] ?? 0; ?></strong>
                                            <?php if ($camp['emails_found_count'] ?? 0 > 0): ?>
                                                <br><small class="text-muted"><?php echo $camp['emails_found_count']; ?> emails found</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo $camp['qualified_leads_count'] ?? 0; ?></strong>
                                            <?php if ($camp['replies_received_count'] ?? 0 > 0): ?>
                                                <br><small class="text-muted"><?php echo $camp['replies_received_count']; ?> replies</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo TimezoneManager::toUserTime($camp['created_at'], 'M j, Y g:i A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </form>

                        <?php if ($pages > 1): ?>
                            <div class="pagination">
                                <?php for ($i = 1; $i <= $pages; $i++): ?>
                                    <a href="campaigns.php?page=<?php echo $i; ?>" 
                                       class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <p>No campaigns created yet.</p>
                            <a href="campaigns.php?action=create" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create Your First Campaign
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script src="assets/js/campaigns.js"></script>
    <script>
        async function restartBackgroundProcessor() {
            const button = event.target.closest('button');
            const originalText = button.innerHTML;
            
            try {
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                button.disabled = true;
                
                const response = await fetch('restart_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Background processor restarted successfully!\nJobs created: ' + result.jobs_created);
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error restarting processor: ' + error.message);
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }

    </script>
</body>
</html>