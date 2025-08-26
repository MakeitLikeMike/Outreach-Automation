<?php
$campaignId = (int)($_GET['id'] ?? 0);
if (!$campaignId) {
    header('Location: campaigns.php?error=' . urlencode('Campaign ID is required'));
    exit;
}

// Function to get status information with icons and descriptions
function getStatusInfo($status) {
    $statusMap = [
        'pending' => [
            'icon' => 'fas fa-clock',
            'label' => 'Pending',
            'description' => 'Domain is queued for analysis'
        ],
        'analyzing' => [
            'icon' => 'fas fa-search',
            'label' => 'Analyzing',
            'description' => 'Quality analysis in progress'
        ],
        'approved' => [
            'icon' => 'fas fa-check-circle',
            'label' => 'Approved',
            'description' => 'Domain passed quality analysis'
        ],
        'rejected' => [
            'icon' => 'fas fa-times-circle',
            'label' => 'Rejected',
            'description' => 'Domain did not meet quality standards'
        ],
        'searching_email' => [
            'icon' => 'fas fa-envelope-open',
            'label' => 'Finding Email',
            'description' => 'Searching for contact email address'
        ],
        'generating_email' => [
            'icon' => 'fas fa-pen',
            'label' => 'Writing Email',
            'description' => 'AI generating personalized outreach email'
        ],
        'sending_email' => [
            'icon' => 'fas fa-paper-plane',
            'label' => 'Sending',
            'description' => 'Email being sent to contact'
        ],
        'monitoring_replies' => [
            'icon' => 'fas fa-eye',
            'label' => 'Monitoring',
            'description' => 'Monitoring for replies and engagement'
        ],
        'contacted' => [
            'icon' => 'fas fa-handshake',
            'label' => 'Contacted',
            'description' => 'Successfully contacted domain owner'
        ],
        'forwarding_lead' => [
            'icon' => 'fas fa-share',
            'label' => 'Forwarding',
            'description' => 'Forwarding qualified lead to you'
        ]
    ];
    
    return $statusMap[$status] ?? [
        'icon' => 'fas fa-question-circle',
        'label' => ucfirst(str_replace('_', ' ', $status)),
        'description' => 'Status: ' . $status
    ];
}

// Function to get email status information
function getEmailStatusInfo($status) {
    $emailStatusMap = [
        'draft' => [
            'icon' => 'fas fa-edit',
            'label' => 'Draft',
            'description' => 'Email draft created, ready to send'
        ],
        'pending' => [
            'icon' => 'fas fa-clock',
            'label' => 'Pending',
            'description' => 'Email queued for sending'
        ],
        'sending' => [
            'icon' => 'fas fa-spinner fa-spin',
            'label' => 'Sending',
            'description' => 'Email currently being sent'
        ],
        'sent' => [
            'icon' => 'fas fa-check-circle',
            'label' => 'Sent',
            'description' => 'Email successfully delivered'
        ],
        'failed' => [
            'icon' => 'fas fa-exclamation-triangle',
            'label' => 'Failed',
            'description' => 'Email delivery failed'
        ],
        'bounced' => [
            'icon' => 'fas fa-reply',
            'label' => 'Bounced',
            'description' => 'Email bounced back'
        ],
        'replied' => [
            'icon' => 'fas fa-reply-all',
            'label' => 'Replied',
            'description' => 'Recipient responded to email'
        ],
        'opened' => [
            'icon' => 'fas fa-envelope-open',
            'label' => 'Opened',
            'description' => 'Email was opened by recipient'
        ]
    ];
    
    return $emailStatusMap[$status] ?? [
        'icon' => 'fas fa-envelope',
        'label' => ucfirst(str_replace('_', ' ', $status)),
        'description' => 'Email status: ' . $status
    ];
}

// Get campaign data
$campaignService = new CampaignService();
$currentCampaign = $campaignService->getCampaignById($campaignId);

if (!$currentCampaign) {
    header('Location: campaigns.php?error=' . urlencode('Campaign not found'));
    exit;
}

$stats = $campaignService->getCampaignStats($currentCampaign['id']);

// Get domains with complete metrics and generated email info
$db = getDatabase();
$domains = $db->fetchAll("
    SELECT 
        td.*,
        oe.id as email_id,
        oe.subject as email_subject,
        oe.status as email_status,
        oe.sent_at as email_sent_at
    FROM target_domains td
    LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
    WHERE td.campaign_id = ?
    ORDER BY 
        CASE td.status 
            WHEN 'contacted' THEN 1 
            WHEN 'sent' THEN 2
            WHEN 'monitoring_replies' THEN 3
            WHEN 'sending_email' THEN 4
            WHEN 'generating_email' THEN 5
            WHEN 'searching_email' THEN 6
            WHEN 'approved' THEN 7
            WHEN 'analyzing_quality' THEN 8
            WHEN 'pending' THEN 9
            WHEN 'rejected' THEN 10
            ELSE 11 
        END ASC, 
        td.quality_score DESC, 
        td.domain_rating DESC
", [$currentCampaign['id']]);

// Get messages from URL parameters
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaign Details - Outreach Automation</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/campaigns.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .email-info {
            font-size: 0.875rem;
            line-height: 1.4;
        }
        .email-info div {
            margin-bottom: 0.25rem;
        }
        .table th {
            font-size: 0.875rem;
            white-space: nowrap;
        }
        .table td {
            font-size: 0.875rem;
            vertical-align: middle;
        }
        .clickable-row:hover {
            background-color: #f8f9fa;
        }
        .status-ready_to_send {
            background-color: #fff3cd;
            color: #856404;
        }
        
        /* Enhanced Status Styling */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
            text-transform: capitalize;
            white-space: nowrap;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }
        
        .status i {
            font-size: 0.75rem;
        }
        
        /* Status Colors with Icons */
        .status-pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            border-color: #f59e0b;
        }
        
        .status-analyzing {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            border-color: #3b82f6;
        }
        
        .status-approved {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            border-color: #10b981;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border-color: #ef4444;
        }
        
        .status-searching_email {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #3730a3;
            border-color: #6366f1;
        }
        
        .status-generating_email {
            background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
            color: #6b21a8;
            border-color: #8b5cf6;
        }
        
        .status-sending_email {
            background: linear-gradient(135deg, #fef7cd 0%, #fef08a 100%);
            color: #a16207;
            border-color: #eab308;
        }
        
        .status-monitoring_replies {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            color: #14532d;
            border-color: #22c55e;
        }
        
        .status-contacted {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #14532d;
            border-color: #16a34a;
        }
        
        .status-forwarding_lead {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            color: #1e3a8a;
            border-color: #3b82f6;
        }
        
        /* Hover Effects */
        .status:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.12);
        }
        
        /* Status Progress Indicator */
        .status-with-progress {
            position: relative;
            overflow: hidden;
        }
        
        .status-with-progress::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            height: 2px;
            background: currentColor;
            opacity: 0.3;
            transition: width 0.3s ease;
        }
        
        .status-pending::after { width: 10%; }
        .status-analyzing::after { width: 25%; }
        .status-approved::after { width: 40%; }
        .status-searching_email::after { width: 55%; }
        .status-generating_email::after { width: 70%; }
        .status-sending_email::after { width: 85%; }
        .status-monitoring_replies::after { width: 95%; }
        .status-contacted::after { width: 100%; }
        
        /* Status Tooltip */
        .status[title] {
            cursor: help;
        }
        
        /* Table Enhancements */
        .table {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .table thead th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.8125rem;
            padding: 1rem 0.75rem;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .table tbody td {
            padding: 1rem 0.75rem;
            border: none;
        }
        
        /* Status Legend */
        .status-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        
        .status-legend-item {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.8125rem;
            color: #64748b;
        }
        
        .status-legend-item .status {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        /* Quality Score Styling */
        .quality-score {
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
        }
        
        .quality-high { color: #059669; background: #ecfdf5; }
        .quality-medium { color: #d97706; background: #fffbeb; }
        .quality-low { color: #dc2626; background: #fef2f2; }
        
        /* Metric Enhancement */
        .metric-value {
            font-weight: 500;
            color: #374151;
            font-size: 0.875rem;
        }
        
        .domain-rating {
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
        }
        
        .dr-high { color: #059669; background: #ecfdf5; }
        .dr-medium { color: #d97706; background: #fffbeb; }
        .dr-low { color: #dc2626; background: #fef2f2; }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .table {
                font-size: 0.75rem;
            }
            
            .status {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
            
            .status i {
                font-size: 0.625rem;
            }
            
            .status-legend {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .table th, .table td {
                padding: 0.5rem 0.375rem;
            }
        }
        
        /* Animation for status changes */
        @keyframes statusPulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .status-analyzing, .status-searching_email, .status-generating_email, .status-sending_email {
            animation: statusPulse 2s infinite;
        }
    </style>
</head>
<body>
    <?php include APP_ROOT . '/includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button onclick="goBack()" class="back-btn" title="Go Back">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1>Campaign Details</h1>
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

            <div class="content-grid" style="grid-template-columns: 1fr 1fr;">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-info-circle"></i> Campaign Details</h3>
                        <div class="actions">
                            <a href="campaigns.php?action=edit&id=<?php echo $currentCampaign['id']; ?>" class="btn btn-secondary">
                                <i class="fas fa-edit"></i> Edit Campaign
                            </a>
                            <a href="campaigns.php?action=delete&id=<?php echo $currentCampaign['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this campaign?')">
                                <i class="fas fa-trash"></i> Delete Campaign
                            </a>
                        </div>
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
                            <strong>Created:</strong> <?php echo TimezoneManager::toUserTime($currentCampaign['created_at'], 'M j, Y g:i A'); ?>
                        </div>
                        <div class="detail-item">
                            <strong>Last Updated:</strong> <?php echo TimezoneManager::toUserTime($currentCampaign['updated_at'], 'M j, Y g:i A'); ?>
                        </div>
                        <?php if ($currentCampaign['is_automated'] ?? 0): ?>
                        <div class="detail-item">
                            <strong>Automation:</strong> 
                            <span class="badge badge-success">
                                <i class="fas fa-robot"></i> Fully Automated
                            </span>
                        </div>
                        <div class="detail-item">
                            <strong>Email Mode:</strong> <?php echo ucwords(str_replace('_', ' ', $currentCampaign['automation_mode'])); ?>
                        </div>
                        <div class="detail-item">
                            <strong>Auto Send:</strong> <?php echo $currentCampaign['auto_send'] ? 'Enabled' : 'Disabled'; ?>
                        </div>
                        <?php endif; ?>
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
                            <?php if ($currentCampaign['is_automated'] ?? 0): ?>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $currentCampaign['emails_sent'] ?? 0; ?></div>
                                <div class="stat-label">Emails Sent</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $currentCampaign['leads_forwarded'] ?? 0; ?></div>
                                <div class="stat-label">Leads Generated</div>
                            </div>
                            <?php endif; ?>
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
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th>Campaign</th>
                                    <th>DR</th>
                                    <th>Traffic</th>
                                    <th>Ref Domains</th>
                                    <th>Backlinks</th>
                                    <th>DA Rank</th>
                                    <th>Keywords</th>
                                    <th>Quality</th>
                                    <th>Status</th>
                                    <th>Contact</th>
                                    <th>Generated Email</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($domains as $domain): ?>
                                    <tr class="clickable-row" onclick="window.location.href='domains.php?action=view&id=<?php echo $domain['id']; ?>'" style="cursor: pointer;">
                                        <td>
                                            <strong><?php echo htmlspecialchars($domain['domain']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($currentCampaign['name']); ?></td>
                                        <td>
                                            <?php 
                                            $dr = $domain['domain_rating'] ?? 0;
                                            $drClass = $dr >= 50 ? 'dr-high' : ($dr >= 30 ? 'dr-medium' : 'dr-low');
                                            ?>
                                            <span class="domain-rating <?php echo $drClass; ?>">
                                                <?php echo $dr ?: 'N/A'; ?>
                                            </span>
                                        </td>
                                        <td><span class="metric-value"><?php echo number_format($domain['organic_traffic'] ?? 0); ?></span></td>
                                        <td><span class="metric-value"><?php echo number_format($domain['referring_domains'] ?? 0); ?></span></td>
                                        <td><span class="metric-value"><?php echo number_format($domain['backlinks_total'] ?? 0); ?></span></td>
                                        <td><span class="metric-value"><?php echo $domain['domain_authority_rank'] ?? 'N/A'; ?></span></td>
                                        <td><span class="metric-value"><?php echo number_format($domain['ranking_keywords'] ?? 0); ?></span></td>
                                        <td>
                                            <?php 
                                            $quality = $domain['quality_score'];
                                            $qualityClass = $quality >= 80 ? 'quality-high' : ($quality >= 60 ? 'quality-medium' : 'quality-low');
                                            ?>
                                            <span class="quality-score <?php echo $qualityClass; ?>">
                                                <?php echo number_format($quality, 1); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $statusInfo = getStatusInfo($domain['status']);
                                            $progressClass = in_array($domain['status'], ['pending', 'analyzing', 'approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted']) ? 'status-with-progress' : '';
                                            ?>
                                            <span class="status status-<?php echo $domain['status']; ?> <?php echo $progressClass; ?>" 
                                                  title="<?php echo htmlspecialchars($statusInfo['description']); ?>">
                                                <i class="<?php echo $statusInfo['icon']; ?>"></i>
                                                <?php echo $statusInfo['label']; ?>
                                            </span>
                                        </td>
                                        <td onclick="event.stopPropagation();">
                                            <?php if ($domain['contact_email']): ?>
                                                <a href="mailto:<?php echo $domain['contact_email']; ?>">
                                                    <?php echo $domain['contact_email']; ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">Not found</span>
                                            <?php endif; ?>
                                        </td>
                                        <td onclick="event.stopPropagation();">
                                            <?php if ($domain['email_id']): ?>
                                                <div class="email-info">
                                                    <div><strong>Subject:</strong> <?php echo htmlspecialchars($domain['email_subject']); ?></div>
                                                    <div>
                                                        <?php 
                                                        $emailStatusInfo = getEmailStatusInfo($domain['email_status']);
                                                        ?>
                                                        <span class="status status-<?php echo $domain['email_status']; ?>" 
                                                              title="<?php echo htmlspecialchars($emailStatusInfo['description']); ?>">
                                                            <i class="<?php echo $emailStatusInfo['icon']; ?>"></i>
                                                            <?php echo $emailStatusInfo['label']; ?>
                                                        </span>
                                                        <?php if ($domain['email_sent_at']): ?>
                                                            <small class="text-success"><i class="fas fa-check"></i> Sent: <?php echo TimezoneManager::toUserTime($domain['email_sent_at'], 'M j, g:i A'); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">No email generated</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="mt-3">
                            <small class="text-muted">
                                Showing all <?php echo count($domains); ?> domains for this campaign. 
                                Click on any row to view detailed domain analysis.
                            </small>
                        </div>
                        
                        <!-- Status Legend -->
                        <div class="status-legend">
                            <div class="status-legend-item">
                                <strong>Status Guide:</strong>
                            </div>
                            <div class="status-legend-item">
                                <span class="status status-pending"><i class="fas fa-clock"></i> Pending</span>
                                Queued for analysis
                            </div>
                            <div class="status-legend-item">
                                <span class="status status-analyzing"><i class="fas fa-search"></i> Analyzing</span>
                                Quality check in progress
                            </div>
                            <div class="status-legend-item">
                                <span class="status status-approved"><i class="fas fa-check-circle"></i> Approved</span>
                                Passed quality standards
                            </div>
                            <div class="status-legend-item">
                                <span class="status status-searching_email"><i class="fas fa-envelope-open"></i> Finding Email</span>
                                Searching for contact
                            </div>
                            <div class="status-legend-item">
                                <span class="status status-generating_email"><i class="fas fa-pen"></i> Writing Email</span>
                                Creating personalized pitch
                            </div>
                            <div class="status-legend-item">
                                <span class="status status-sending_email"><i class="fas fa-paper-plane"></i> Sending</span>
                                Delivering outreach email
                            </div>
                            <div class="status-legend-item">
                                <span class="status status-monitoring_replies"><i class="fas fa-eye"></i> Monitoring</span>
                                Watching for responses
                            </div>
                            <div class="status-legend-item">
                                <span class="status status-contacted"><i class="fas fa-handshake"></i> Contacted</span>
                                Successfully reached out
                            </div>
                            <div class="status-legend-item">
                                <span class="status status-rejected"><i class="fas fa-times-circle"></i> Rejected</span>
                                Did not meet criteria
                            </div>
                        </div>
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
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script src="assets/js/campaigns.js"></script>
</body>
</html>