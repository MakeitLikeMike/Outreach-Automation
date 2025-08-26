<?php
require_once 'auth.php';
$auth->requireAuth();

require_once 'config/database.php';
require_once 'classes/Campaign.php';
require_once 'classes/TargetDomain.php';
require_once 'classes/DashboardMetrics.php';

$db = new Database();
$campaign = new Campaign();
$targetDomain = new TargetDomain();
$metrics = new DashboardMetrics();

// Get date filter parameters
$dateRange = $_GET['days'] ?? 30;
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

// Convert date range to int for safety
$dateRange = is_numeric($dateRange) ? (int)$dateRange : 30;

// Get accurate statistics using the new metrics class with date filtering
$campaignStats = $metrics->getCampaignStats($dateRange, $startDate, $endDate);
$domainStats = $metrics->getDomainStats($dateRange, $startDate, $endDate);
$emailStats = $metrics->getEmailStats($dateRange, $startDate, $endDate);
$leadStats = $metrics->getLeadStats($dateRange, $startDate, $endDate);

// Get recent campaigns and domains
$recentCampaigns = $db->fetchAll("SELECT * FROM campaigns ORDER BY created_at DESC LIMIT 3");
$recentDomains = $db->fetchAll("SELECT * FROM target_domains ORDER BY created_at DESC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto Outreach System</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/monitoring.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Enhanced stat cards */
        .stat-card.clickable {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
        }

        .stat-card.clickable:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
        }

        .click-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card.clickable:hover .click-indicator {
            opacity: 0.7;
        }

        /* Date Filter Styles */
        .top-header {
            position: relative;
        }

        .header-content {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Date Filter and Clock Styles */
        .header-controls {
            position: absolute;
            top: 50%;
            right: 2rem;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            gap: 1rem;
            z-index: 100;
        }

        .date-filter-dropdown {
            position: relative;
        }

        .current-time-widget {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: transparent;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.875rem;
            min-width: 180px;
        }

        .current-time-widget i {
            font-size: 1rem;
            opacity: 0.7;
            color: #6b7280;
        }

        .time-info {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .time-display {
            font-weight: 600;
            font-size: 0.875rem;
            color: #374151;
        }

        .timezone-display {
            font-size: 0.7rem;
            opacity: 0.6;
            font-weight: 400;
            color: #6b7280;
        }

        .filter-trigger {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            transition: all 0.2s ease;
            min-width: 160px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .filter-trigger:hover {
            border-color: #3b82f6;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .filter-trigger.active {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .dropdown-icon {
            margin-left: auto;
            transition: transform 0.2s ease;
            font-size: 0.75rem;
            color: #6b7280;
        }

        .filter-trigger.active .dropdown-icon {
            transform: rotate(180deg);
        }

        .filter-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 0.5rem;
            min-width: 280px;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            z-index: 1000;
            display: none;
        }

        .filter-dropdown.show {
            display: block;
            animation: dropdownFadeIn 0.2s ease;
        }

        @keyframes dropdownFadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown-header {
            padding: 1rem 1rem 0.5rem;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
        }

        .filter-options {
            padding: 0.5rem;
        }

        .filter-option {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 0.875rem;
        }

        .filter-option:hover {
            background: #f3f4f6;
        }

        .filter-option.active {
            background: #eff6ff;
            color: #3b82f6;
            font-weight: 500;
        }

        .filter-option .check-icon {
            margin-left: auto;
            color: #3b82f6;
            font-size: 0.875rem;
            opacity: 0;
        }

        .filter-option.active .check-icon {
            opacity: 1;
        }

        .custom-range-section {
            padding: 1rem;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .custom-range-inputs {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .date-input {
            flex: 1;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            background: white;
        }

        .date-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .apply-btn {
            width: 100%;
            padding: 0.625rem;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background 0.15s ease;
        }

        .apply-btn:hover {
            background: #2563eb;
        }

        .apply-btn:active {
            background: #1d4ed8;
        }

        @media (max-width: 768px) {
            .filter-dropdown {
                right: -1rem;
                left: -1rem;
                min-width: auto;
            }
            
            .custom-range-inputs {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-content">
                <h1>Dashboard Overview</h1>
            </div>
            <div class="header-controls">
                <div class="current-time-widget">
                    <i class="fas fa-clock"></i>
                    <div class="time-info">
                        <span class="time-display" id="currentTime"></span>
                        <span class="timezone-display"><?php echo TimezoneManager::getUserTimezone(); ?></span>
                    </div>
                </div>
                
                <div class="date-filter-dropdown">
                    <button class="filter-trigger" id="filterTrigger" onclick="toggleDropdown()">
                        <i class="fas fa-calendar-alt"></i>
                        <span id="filterLabel">Last 30 days</span>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </button>
                <div class="filter-dropdown" id="filterDropdown">
                    <div class="dropdown-header">Filter by Date</div>
                    <div class="filter-options">
                        <div class="filter-option" data-days="7" onclick="selectQuickFilter(7, 'Last 7 days')">
                            <span>Last 7 days</span>
                            <i class="fas fa-check check-icon"></i>
                        </div>
                        <div class="filter-option active" data-days="30" onclick="selectQuickFilter(30, 'Last 30 days')">
                            <span>Last 30 days</span>
                            <i class="fas fa-check check-icon"></i>
                        </div>
                        <div class="filter-option" data-days="90" onclick="selectQuickFilter(90, 'Last 90 days')">
                            <span>Last 90 days</span>
                            <i class="fas fa-check check-icon"></i>
                        </div>
                        <div class="filter-option" data-days="365" onclick="selectQuickFilter(365, 'This Year')">
                            <span>This Year</span>
                            <i class="fas fa-check check-icon"></i>
                        </div>
                    </div>
                    <div class="custom-range-section">
                        <div class="custom-range-inputs">
                            <input type="date" id="startDate" class="date-input" placeholder="Start date">
                            <span style="color: #6b7280; font-size: 0.875rem;">to</span>
                            <input type="date" id="endDate" class="date-input" placeholder="End date">
                        </div>
                        <button class="apply-btn" onclick="applyCustomRange()">Apply Custom Range</button>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-grid">
            <div class="stat-card clickable" onclick="showActiveCampaigns()">
                <div class="stat-icon campaigns">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $campaignStats['active_campaigns'] ?? 0; ?></h3>
                    <p>Active Campaigns</p>
                </div>
                <div class="click-indicator">
                    <i class="fas fa-external-link-alt"></i>
                </div>
            </div>

            <div class="stat-card clickable" onclick="showTotalDomains()">
                <div class="stat-icon domains">
                    <i class="fas fa-globe"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $domainStats['total_domains'] ?? 0; ?></h3>
                    <p>Total Domains</p>
                </div>
                <div class="click-indicator">
                    <i class="fas fa-external-link-alt"></i>
                </div>
            </div>

            <div class="stat-card clickable" onclick="showApprovedDomains()">
                <div class="stat-icon approved">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $domainStats['approved_domains'] ?? 0; ?></h3>
                    <p>Approved Domains</p>
                </div>
                <div class="click-indicator">
                    <i class="fas fa-external-link-alt"></i>
                </div>
            </div>

            <div class="stat-card clickable" onclick="showContactedEmails()">
                <div class="stat-icon contacted">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $emailStats['total_emails_sent'] ?? 0; ?></h3>
                    <p>Emails Sent</p>
                </div>
                <div class="click-indicator">
                    <i class="fas fa-external-link-alt"></i>
                </div>
            </div>

            <div class="stat-card clickable" onclick="showForwardedEmails()">
                <div class="stat-icon forwarded">
                    <i class="fas fa-share"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $leadStats['total_leads_forwarded'] ?? 0; ?></h3>
                    <p>Leads Forwarded</p>
                </div>
                <div class="click-indicator">
                    <i class="fas fa-external-link-alt"></i>
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
                    <h3><i class="fas fa-chart-bar"></i> Recent Campaigns</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($recentCampaigns)): ?>
                        <div class="campaign-list">
                            <?php foreach ($recentCampaigns as $camp): ?>
                                <div class="campaign-item">
                                    <div class="campaign-info">
                                        <h4><?php echo htmlspecialchars($camp['name']); ?></h4>
                                        <span class="campaign-status status-<?php echo $camp['status']; ?>">
                                            <?php echo ucfirst($camp['status']); ?>
                                        </span>
                                        <div class="pipeline-info">
                                            Pipeline: <?php echo ucfirst(str_replace('_', ' ', $camp['pipeline_status'])); ?>
                                        </div>
                                    </div>
                                    <div class="campaign-actions">
                                        <a href="campaigns.php?action=view&id=<?php echo $camp['id']; ?>" class="btn btn-sm btn-outline">
                                            <i class="fas fa-eye"></i> View
                                        </a>
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
                                        <span class="domain-rating">DR: <?php echo $domain['domain_rating'] ?? 'N/A'; ?></span>
                                    </div>
                                    <div class="domain-metrics">
                                        <span class="metric">
                                            <i class="fas fa-chart-line"></i> <?php echo number_format($domain['organic_traffic'] ?? 0); ?>
                                        </span>
                                        <span class="status status-<?php echo $domain['status'] ?: 'pending'; ?>">
                                            <?php echo ucfirst($domain['status'] ?: 'pending'); ?>
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

    <script src="assets/js/main.js?v=<?php echo time(); ?>"></script>
    <script>
        // Updated: <?php echo date('Y-m-d H:i:s'); ?> - Added sender email column
        // Modal functionality
        function showModal(title, content, modalClass = '') {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalBody').innerHTML = content;
            const modal = document.getElementById('detailsModal');
            modal.className = modalClass ? `modal ${modalClass}` : 'modal';
            modal.style.display = 'block';
        }

        function closeModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('detailsModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        // Dashboard interactivity functions - Updated with proper error handling
        function showActiveCampaigns() {
            fetch('api/dashboard_data.php?type=campaigns')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    let content = '<div class="campaigns-table-container">';
                    
                    if (data.campaigns && data.campaigns.length > 0) {
                        content += `
                            <table class="campaigns-table">
                                <thead>
                                    <tr>
                                        <th>Campaign Name</th>
                                        <th>Status</th>
                                        <th>Pipeline Status</th>
                                        <th>Domains</th>
                                        <th>Approved</th>
                                        <th>Emails Sent</th>
                                    </tr>
                                </thead>
                                <tbody>
                        `;
                        
                        data.campaigns.forEach(campaign => {
                            content += `
                                <tr class="clickable-row" onclick="viewCampaignDetails(${campaign.id})">
                                    <td><strong>${campaign.name}</strong></td>
                                    <td><span class="status status-${campaign.status}">${campaign.status}</span></td>
                                    <td><span class="pipeline-status">${campaign.pipeline_status.replace('_', ' ')}</span></td>
                                    <td class="metric-cell">${campaign.total_domains || 0}</td>
                                    <td class="metric-cell">${campaign.approved_domains || 0}</td>
                                    <td class="metric-cell">${campaign.emails_sent || 0}</td>
                                </tr>
                            `;
                        });
                        
                        content += `</tbody></table>`;
                    } else {
                        content += '<div class="empty-state"><i class="fas fa-bullhorn"></i><p>No campaigns found.</p></div>';
                    }
                    
                    content += '</div>';
                    showModal('Campaign Details', content, 'large-modal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    showModal('Error', `<div class="error">Failed to load campaigns: ${error.message}</div>`);
                });
        }

        function showTotalDomains() {
            fetch('api/dashboard_data.php?type=domains')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    let content = '<div class="domains-stats">';
                    content += `<div class="stat"><span class="value">${data.total || 0}</span><span class="label">Total Domains</span></div>`;
                    content += `<div class="stat"><span class="value">${data.avg_dr || 0}</span><span class="label">Avg Domain Rating</span></div>`;
                    content += `<div class="stat"><span class="value">${data.with_email || 0}</span><span class="label">With Email</span></div>`;
                    content += '</div>';
                    
                    if (data.domains && data.domains.length > 0) {
                        content += `<div class="domains-table"><table>
                                    <thead><tr><th>Domain</th><th>Campaign</th><th>Status</th><th>DR</th><th>Traffic</th></tr></thead>
                                    <tbody>`;
                        
                        data.domains.slice(0, 20).forEach(domain => {
                            content += `
                                <tr>
                                    <td><strong>${domain.domain}</strong></td>
                                    <td>${domain.campaign_name || 'N/A'}</td>
                                    <td><span class="status status-${domain.status || 'pending'}">${domain.status || 'pending'}</span></td>
                                    <td>${domain.domain_rating || 0}</td>
                                    <td>${(domain.organic_traffic || 0).toLocaleString()}</td>
                                </tr>
                            `;
                        });
                        
                        content += '</tbody></table></div>';
                    } else {
                        content += '<div class="empty-state"><i class="fas fa-globe"></i><p>No domains found.</p></div>';
                    }
                    
                    showModal('All Domains', content, 'large-modal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    showModal('Error', `<div class="error">Failed to load domains: ${error.message}</div>`);
                });
        }

        function showApprovedDomains() {
            fetch('api/dashboard_data.php?type=approved_domains')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    let content = '<div class="approved-stats">';
                    content += `<div class="stat"><span class="value">${data.domains ? data.domains.length : 0}</span><span class="label">Approved</span></div>`;
                    content += `<div class="stat"><span class="value">${data.avg_quality_score || 0}</span><span class="label">Avg Quality</span></div>`;
                    content += `<div class="stat"><span class="value">${data.contacted_count || 0}</span><span class="label">Contacted</span></div>`;
                    content += `<div class="stat"><span class="value">${data.reply_rate || 0}%</span><span class="label">Reply Rate</span></div>`;
                    content += '</div>';
                    
                    if (data.domains && data.domains.length > 0) {
                        content += `<div class="domains-table"><table>
                                    <thead><tr><th>Domain</th><th>Campaign</th><th>Quality Score</th><th>Status</th><th>Email</th></tr></thead>
                                    <tbody>`;
                        
                        data.domains.forEach(domain => {
                            content += `
                                <tr>
                                    <td><strong>${domain.domain}</strong></td>
                                    <td>${domain.campaign_name || 'N/A'}</td>
                                    <td><span class="quality-score">${domain.quality_score || 0}</span></td>
                                    <td><span class="status status-${domain.status}">${domain.status}</span></td>
                                    <td>${domain.contact_email ? '✅' : '❌'}</td>
                                </tr>
                            `;
                        });
                        
                        content += '</tbody></table></div>';
                    } else {
                        content += '<div class="empty-state"><i class="fas fa-check-circle"></i><p>No approved domains found.</p></div>';
                    }
                    
                    showModal('Approved Domains', content, 'large-modal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    showModal('Error', `<div class="error">Failed to load approved domains: ${error.message}</div>`);
                });
        }

        function showContactedEmails() {
            fetch('api/dashboard_data.php?type=contacted')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    let content = '<div class="email-stats">';
                    content += `<div class="stat"><span class="value">${data.total_sent || 0}</span><span class="label">Total Sent</span></div>`;
                    content += `<div class="stat"><span class="value">${data.replies || 0}</span><span class="label">Replies</span></div>`;
                    content += `<div class="stat"><span class="value">${data.reply_rate || 0}%</span><span class="label">Reply Rate</span></div>`;
                    content += '</div>';
                    
                    if (data.emails && data.emails.length > 0) {
                        content += `<div class="emails-table"><table>
                                    <thead><tr><th>Sender</th><th>Recipient</th><th>Domain</th><th>Campaign</th><th>Status</th><th>Sent Date</th></tr></thead>
                                    <tbody>`;
                        
                        data.emails.forEach(email => {
                            content += `
                                <tr>
                                    <td>${email.sender_email || 'N/A'}</td>
                                    <td>${email.recipient_email}</td>
                                    <td>${email.domain}</td>
                                    <td>${email.campaign_name || 'N/A'}</td>
                                    <td><span class="status status-${email.status}">${email.status}</span></td>
                                    <td>${email.sent_at}</td>
                                </tr>
                            `;
                        });
                        
                        content += '</tbody></table></div>';
                    } else {
                        content += '<div class="empty-state"><i class="fas fa-paper-plane"></i><p>No emails sent yet.</p></div>';
                    }
                    
                    showModal('Emails Sent', content, 'large-modal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    showModal('Error', `<div class="error">Failed to load contacted emails: ${error.message}</div>`);
                });
        }

        function showForwardedEmails() {
            console.log('showForwardedEmails called');
            fetch('api/dashboard_data.php?type=forwarded')
                .then(response => {
                    console.log('Response received:', response);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Data received:', data);
                    let content = '<div class="forward-stats">';
                    content += `<div class="stat"><span class="value">${data.total || 0}</span><span class="label">Total Forwarded</span></div>`;
                    content += `<div class="stat"><span class="value">${data.recent || 0}</span><span class="label">Last 7 Days</span></div>`;
                    content += `<div class="stat"><span class="value">${data.interested || 0}</span><span class="label">Interested Leads</span></div>`;
                    content += '</div>';
                    
                    if (data.forwards && data.forwards.length > 0) {
                        content += '<div class="forwards-list">';
                        data.forwards.forEach(forward => {
                            content += `
                                <div class="forward-item">
                                    <div class="forward-header">
                                        <strong>${forward.domain}</strong>
                                        <span class="forward-date">${forward.forwarded_at}</span>
                                    </div>
                                    <div class="forward-info">
                                        <span><strong>Campaign:</strong> ${forward.campaign_name}</span>
                                        <span><strong>Contact:</strong> ${forward.contact_email}</span>
                                        <span><strong>Quality:</strong> ${forward.quality_score}/100</span>
                                    </div>
                                </div>
                            `;
                        });
                        content += '</div>';
                    } else {
                        content += '<div class="empty-state"><i class="fas fa-share"></i><p>No forwarded emails found.</p></div>';
                    }
                    
                    showModal('Forwarded Emails', content, 'large-modal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    // Show modal even with error so user knows something happened
                    showModal('Forwarded Emails', `<div class="error">Unable to load forwarded emails data. This feature may not be set up yet.</div><div class="info">If you need to set up email forwarding, please configure the forwarding queue in the system settings.</div>`);
                });
        }

        function viewCampaignDetails(campaignId) {
            closeModal();
            window.location.href = `campaigns.php?action=view&id=${campaignId}`;
        }
    </script>

    <!-- Modal styles -->
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 1% auto;
            padding: 0;
            border-radius: 12px;
            width: 96%;
            max-width: 1600px;
            min-height: 700px;
            max-height: 98vh;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .modal-header {
            padding: 20px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 24px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .loading, .error, .empty-state {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Stats display */
        .domains-stats, .email-stats, .forward-stats, .approved-stats {
            display: flex;
            justify-content: space-around;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .stat {
            text-align: center;
        }

        .stat .value {
            display: block;
            font-size: 24px;
            font-weight: bold;
            color: #4facfe;
        }

        .stat .label {
            display: block;
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
        }

        /* Table styles */
        .campaigns-table, .domains-table table, .emails-table table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .campaigns-table th, .domains-table th, .emails-table th {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
        }

        .campaigns-table td, .domains-table td, .emails-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .clickable-row:hover {
            background-color: #f8f9fa;
        }

        .metric-cell {
            text-align: center;
            font-weight: 600;
            color: #4facfe;
        }

        .pipeline-info {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }

        .campaign-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .quality-score {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .forward-item {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
        }

        .forward-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .forward-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 14px;
        }
    </style>

    <script>
        // Date filtering dropdown functionality
        let currentDateRange = <?php echo $dateRange; ?>;
        let dropdownOpen = false;
        
        // Initialize dropdown on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we have custom date range from URL
            const urlParams = new URLSearchParams(window.location.search);
            const startDate = urlParams.get('start_date');
            const endDate = urlParams.get('end_date');
            
            if (startDate && endDate) {
                // Custom range is active
                const startFormatted = new Date(startDate).toLocaleDateString();
                const endFormatted = new Date(endDate).toLocaleDateString();
                document.getElementById('filterLabel').textContent = `${startFormatted} - ${endFormatted}`;
                
                // Set the date inputs
                document.getElementById('startDate').value = startDate;
                document.getElementById('endDate').value = endDate;
                
                // Don't activate any quick filter options
                document.querySelectorAll('.filter-option').forEach(option => {
                    option.classList.remove('active');
                });
            } else {
                // Set active option based on current date range
                document.querySelectorAll('.filter-option').forEach(option => {
                    option.classList.remove('active');
                    if (option.dataset.days == currentDateRange) {
                        option.classList.add('active');
                        const label = option.querySelector('span').textContent;
                        document.getElementById('filterLabel').textContent = label;
                    }
                });
            }
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.date-filter-dropdown')) {
                    closeDropdown();
                }
            });
        });

        function toggleDropdown() {
            const dropdown = document.getElementById('filterDropdown');
            const trigger = document.getElementById('filterTrigger');
            
            if (dropdownOpen) {
                closeDropdown();
            } else {
                dropdown.classList.add('show');
                trigger.classList.add('active');
                dropdownOpen = true;
            }
        }
        
        function closeDropdown() {
            const dropdown = document.getElementById('filterDropdown');
            const trigger = document.getElementById('filterTrigger');
            
            dropdown.classList.remove('show');
            trigger.classList.remove('active');
            dropdownOpen = false;
        }
        
        function selectQuickFilter(days, label) {
            // Update active state
            document.querySelectorAll('.filter-option').forEach(option => {
                option.classList.remove('active');
            });
            event.target.closest('.filter-option').classList.add('active');
            
            // Update button label
            document.getElementById('filterLabel').textContent = label;
            
            // Apply filter and close dropdown
            applyDateFilter(days);
            closeDropdown();
        }

        function applyDateFilter(days) {
            const url = new URL(window.location);
            url.searchParams.set('days', days);
            url.searchParams.delete('start_date');
            url.searchParams.delete('end_date');
            window.location.href = url.toString();
        }
        
        function applyCustomRange() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                alert('Start date must be before end date');
                return;
            }
            
            // Update button label for custom range
            const startFormatted = new Date(startDate).toLocaleDateString();
            const endFormatted = new Date(endDate).toLocaleDateString();
            document.getElementById('filterLabel').textContent = `${startFormatted} - ${endFormatted}`;
            
            // Remove active state from quick options
            document.querySelectorAll('.filter-option').forEach(option => {
                option.classList.remove('active');
            });
            
            // Close dropdown
            closeDropdown();
            
            const url = new URL(window.location);
            url.searchParams.set('start_date', startDate);
            url.searchParams.set('end_date', endDate);
            url.searchParams.delete('days');
            window.location.href = url.toString();
        }

        // Clock functionality
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Update time immediately and then every minute
        updateCurrentTime();
        setInterval(updateCurrentTime, 60000);

    </script>
</body>
</html>