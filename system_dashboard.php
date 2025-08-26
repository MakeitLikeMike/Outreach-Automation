<?php
require_once 'config/database.php';
$db = new Database();

// Check if this is an AJAX request for auto-refresh
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    $stats = $db->fetchOne("
        SELECT 
            COUNT(*) as total_jobs,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM background_jobs
    ");
    
    $recentActivity = $db->fetchOne("
        SELECT COUNT(*) as recent_jobs
        FROM background_jobs 
        WHERE updated_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
    ");
    
    echo json_encode([
        'jobs' => $stats,
        'active' => $recentActivity['recent_jobs'] > 0,
        'timestamp' => date('H:i:s')
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Dashboard - Outreach Automation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .status-active { color: #28a745; }
        .status-inactive { color: #dc3545; }
        .progress-custom { height: 30px; }
        .metric-card {
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="fas fa-tachometer-alt"></i> System Dashboard</h1>
                    <div>
                        <span id="status-indicator" class="badge fs-6">
                            <i class="fas fa-circle"></i> Checking...
                        </span>
                        <small class="text-muted ms-2">Last update: <span id="timestamp">--:--:--</span></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card metric-card border-primary">
                    <div class="card-body text-center">
                        <h5 class="card-title text-primary">Total Jobs</h5>
                        <h2 class="mb-0" id="total-jobs">-</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card border-warning">
                    <div class="card-body text-center">
                        <h5 class="card-title text-warning">Pending</h5>
                        <h2 class="mb-0" id="pending-jobs">-</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card border-info">
                    <div class="card-body text-center">
                        <h5 class="card-title text-info">Processing</h5>
                        <h2 class="mb-0" id="processing-jobs">-</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card border-success">
                    <div class="card-body text-center">
                        <h5 class="card-title text-success">Completed</h5>
                        <h2 class="mb-0" id="completed-jobs">-</h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Processing Progress</h5>
                    </div>
                    <div class="card-body">
                        <div class="progress progress-custom mb-3">
                            <div id="progress-bar" class="progress-bar bg-success" role="progressbar" style="width: 0%"></div>
                        </div>
                        <div class="text-center">
                            <span id="progress-text">Calculating...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="btn-group" role="group">
                            <a href="domains.php" class="btn btn-primary">
                                <i class="fas fa-globe"></i> View Domains
                            </a>
                            <a href="monitoring.php" class="btn btn-info">
                                <i class="fas fa-chart-line"></i> Monitoring
                            </a>
                            <a href="campaigns.php" class="btn btn-warning">
                                <i class="fas fa-bullhorn"></i> Campaigns
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
    <script>
        function updateDashboard() {
            fetch('system_dashboard.php?ajax=1')
                .then(response => response.json())
                .then(data => {
                    // Update metrics
                    document.getElementById('total-jobs').textContent = data.jobs.total_jobs;
                    document.getElementById('pending-jobs').textContent = data.jobs.pending;
                    document.getElementById('processing-jobs').textContent = data.jobs.processing;
                    document.getElementById('completed-jobs').textContent = data.jobs.completed;
                    
                    // Update status indicator
                    const statusElement = document.getElementById('status-indicator');
                    if (data.active) {
                        statusElement.className = 'badge bg-success fs-6';
                        statusElement.innerHTML = '<i class="fas fa-circle"></i> System Active';
                    } else {
                        statusElement.className = 'badge bg-danger fs-6';
                        statusElement.innerHTML = '<i class="fas fa-circle"></i> System Inactive';
                    }
                    
                    // Update progress bar
                    const total = data.jobs.total_jobs;
                    const completed = data.jobs.completed;
                    const percentage = total > 0 ? (completed / total) * 100 : 0;
                    
                    document.getElementById('progress-bar').style.width = percentage + '%';
                    document.getElementById('progress-text').textContent = 
                        `${completed} of ${total} jobs completed (${percentage.toFixed(1)}%)`;
                    
                    // Update timestamp
                    document.getElementById('timestamp').textContent = data.timestamp;
                })
                .catch(error => {
                    console.error('Error updating dashboard:', error);
                });
        }

        // Update immediately and then every 3 seconds
        updateDashboard();
        setInterval(updateDashboard, 3000);
    </script>
</body>
</html>