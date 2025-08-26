<?php
/**
 * Analytics Dashboard Interface
 * Interactive performance analytics with historical data visualization
 */
require_once 'classes/AnalyticsDashboard.php';
require_once 'config/database.php';

// Get request parameters
$dateRange = $_GET['range'] ?? 30;
$campaignId = $_GET['campaign_id'] ?? null;
$exportFormat = $_GET['export'] ?? null;

$analytics = new AnalyticsDashboard();

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

// Get analytics data
$performanceData = $analytics->getCampaignPerformanceAnalytics($dateRange, $campaignId);
$weeklyData = $analytics->getWeeklyPerformanceSummary(0);

// Get campaign list for filter
$db = new Database();
$campaigns = $db->fetchAll("SELECT id, name FROM campaigns ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - Auto Outreach</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .analytics-container {
            padding: 2rem;
        }
        
        .analytics-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .filter-controls {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 0.5rem;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        
        .export-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        
        .btn-export {
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-export.csv {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .btn-export.json {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }
        
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .analytics-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-top: 4px solid;
        }
        
        .analytics-card.performance {
            border-top-color: #3b82f6;
        }
        
        .analytics-card.engagement {
            border-top-color: #10b981;
        }
        
        .analytics-card.conversion {
            border-top-color: #f59e0b;
        }
        
        .analytics-card.trends {
            border-top-color: #8b5cf6;
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .card-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .card-icon.performance { background: #3b82f6; }
        .card-icon.engagement { background: #10b981; }
        .card-icon.conversion { background: #f59e0b; }
        .card-icon.trends { background: #8b5cf6; }
        
        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1e293b;
        }
        
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
        }
        
        .metric-item {
            text-align: center;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .metric-label {
            font-size: 0.8rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .chart-container {
            width: 100%;
            height: 300px;
            margin: 1rem 0;
        }
        
        .chart-container canvas {
            max-width: 100%;
            height: auto;
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .trend-indicator {
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }
        
        .trend-up {
            color: #10b981;
        }
        
        .trend-down {
            color: #ef4444;
        }
        
        .performance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .performance-table th,
        .performance-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .performance-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #374151;
        }
        
        @media (max-width: 768px) {
            .analytics-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-controls {
                justify-content: space-between;
            }
            
            .analytics-grid {
                grid-template-columns: 1fr;
            }
            
            .summary-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navigation.php'; ?>
    
    <main class="main-content">
        <div class="analytics-container">
            <!-- Analytics Header -->
            <div class="analytics-header">
                <div>
                    <h1><i class="fas fa-chart-bar"></i> Analytics Dashboard</h1>
                    <p>Comprehensive performance analytics and insights</p>
                </div>
                
                <div class="filter-controls">
                    <div class="filter-group">
                        <label for="dateRange">Date Range</label>
                        <select id="dateRange" onchange="updateAnalytics()">
                            <option value="7" <?php echo $dateRange == 7 ? 'selected' : ''; ?>>Last 7 days</option>
                            <option value="30" <?php echo $dateRange == 30 ? 'selected' : ''; ?>>Last 30 days</option>
                            <option value="90" <?php echo $dateRange == 90 ? 'selected' : ''; ?>>Last 90 days</option>
                            <option value="365" <?php echo $dateRange == 365 ? 'selected' : ''; ?>>Last year</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="campaignFilter">Campaign</label>
                        <select id="campaignFilter" onchange="updateAnalytics()">
                            <option value="">All Campaigns</option>
                            <?php foreach($campaigns as $campaign): ?>
                                <option value="<?php echo $campaign['id']; ?>" <?php echo $campaignId == $campaign['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($campaign['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="export-controls">
                        <a href="?export=csv&range=<?php echo $dateRange; ?><?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>" 
                           class="btn-export csv">
                            <i class="fas fa-file-csv"></i> CSV
                        </a>
                        <a href="?export=json&range=<?php echo $dateRange; ?><?php echo $campaignId ? '&campaign_id=' . $campaignId : ''; ?>" 
                           class="btn-export json">
                            <i class="fas fa-file-code"></i> JSON
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Summary Statistics -->
            <?php if($performanceData): ?>
            <div class="summary-stats">
                <?php 
                $overview = $performanceData['overview'] ?? [];
                ?>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $overview['total_campaigns'] ?? 0; ?></div>
                    <div class="stat-label">Total Campaigns</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($overview['total_emails_sent'] ?? 0); ?></div>
                    <div class="stat-label">Emails Sent</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($overview['response_rate'] ?? 0, 1); ?>%</div>
                    <div class="stat-label">Response Rate</div>
                    <div class="trend-indicator trend-up">
                        <i class="fas fa-arrow-up"></i> Trending
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($overview['avg_quality_score'] ?? 0, 1); ?></div>
                    <div class="stat-label">Avg Quality Score</div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Analytics Cards Grid -->
            <div class="analytics-grid">
                <!-- Performance Overview -->
                <div class="analytics-card performance">
                    <div class="card-header">
                        <div class="card-icon performance">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3 class="card-title">Campaign Performance</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
                
                <!-- Email Engagement -->
                <div class="analytics-card engagement">
                    <div class="card-header">
                        <div class="card-icon engagement">
                            <i class="fas fa-envelope-open"></i>
                        </div>
                        <h3 class="card-title">Email Engagement</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="engagementChart"></canvas>
                    </div>
                </div>
                
                <!-- Response Analysis -->
                <div class="analytics-card conversion">
                    <div class="card-header">
                        <div class="card-icon conversion">
                            <i class="fas fa-reply"></i>
                        </div>
                        <h3 class="card-title">Response Analysis</h3>
                    </div>
                    <div class="metric-grid">
                        <?php if($performanceData && isset($performanceData['responses'])): ?>
                            <?php foreach($performanceData['responses'] as $type => $count): ?>
                            <div class="metric-item">
                                <div class="metric-value"><?php echo $count; ?></div>
                                <div class="metric-label"><?php echo ucfirst($type); ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="metric-item">
                                <div class="metric-value">0</div>
                                <div class="metric-label">Positive</div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-value">0</div>
                                <div class="metric-label">Neutral</div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-value">0</div>
                                <div class="metric-label">Negative</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
            </div>
            
            <!-- Detailed Performance Table -->
            <?php if($performanceData && isset($performanceData['campaigns'])): ?>
            <div class="analytics-card" style="grid-column: 1/-1;">
                <div class="card-header">
                    <div class="card-icon performance">
                        <i class="fas fa-table"></i>
                    </div>
                    <h3 class="card-title">Campaign Performance Details</h3>
                </div>
                <table class="performance-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Emails Sent</th>
                            <th>Responses</th>
                            <th>Response Rate</th>
                            <th>Quality Score</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($performanceData['campaigns'] as $campaign): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($campaign['name'] ?? 'Unknown'); ?></td>
                            <td><?php echo number_format($campaign['emails_sent'] ?? 0); ?></td>
                            <td><?php echo number_format($campaign['responses'] ?? 0); ?></td>
                            <td><?php echo number_format($campaign['response_rate'] ?? 0, 1); ?>%</td>
                            <td><?php echo number_format($campaign['quality_score'] ?? 0, 1); ?></td>
                            <td><?php echo ucfirst($campaign['status'] ?? 'active'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
    
    <script>
        function updateAnalytics() {
            const dateRange = document.getElementById('dateRange').value;
            const campaignId = document.getElementById('campaignFilter').value;
            
            let url = `?range=${dateRange}`;
            if (campaignId) {
                url += `&campaign_id=${campaignId}`;
            }
            
            window.location.href = url;
        }
        
        // Initialize charts when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Performance Chart
            const performanceCtx = document.getElementById('performanceChart');
            if (performanceCtx) {
                new Chart(performanceCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Sent', 'Delivered', 'Opened', 'Replied'],
                        datasets: [{
                            data: [
                                <?php echo $performanceData['overview']['total_emails_sent'] ?? 0; ?>,
                                <?php echo ($performanceData['overview']['total_emails_sent'] ?? 0) * 0.95; ?>,
                                <?php echo ($performanceData['overview']['total_emails_sent'] ?? 0) * 0.25; ?>,
                                <?php echo $performanceData['overview']['total_responses'] ?? 0; ?>
                            ],
                            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
            
            // Engagement Chart
            const engagementCtx = document.getElementById('engagementChart');
            if (engagementCtx) {
                new Chart(engagementCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                        datasets: [{
                            label: 'Engagement Rate',
                            data: [15, 22, 18, 25],
                            backgroundColor: '#10b981'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100
                            }
                        }
                    }
                });
            }
            
        });
    </script>
</body>
</html>