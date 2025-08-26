<?php
require_once 'config/database.php';

// Get campaign filter
$campaignId = (int)($_GET['campaign_id'] ?? 0);

$db = new Database();

// Get campaigns for filter dropdown
$campaigns = $db->fetchAll("SELECT id, name FROM campaigns ORDER BY id DESC");

// Build query based on filter
$sql = "
    SELECT 
        oe.id,
        oe.campaign_id,
        oe.domain_id,
        oe.sender_email,
        oe.recipient_email,
        oe.subject,
        oe.body,
        oe.status,
        oe.created_at,
        oe.sent_at,
        td.domain,
        td.domain_rating,
        td.quality_score,
        c.name as campaign_name
    FROM outreach_emails oe
    LEFT JOIN target_domains td ON oe.domain_id = td.id
    LEFT JOIN campaigns c ON oe.campaign_id = c.id
";

$params = [];
if ($campaignId > 0) {
    $sql .= " WHERE oe.campaign_id = ?";
    $params[] = $campaignId;
}

$sql .= " ORDER BY oe.created_at DESC LIMIT 50";

$emails = $db->fetchAll($sql, $params);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generated Email Pitches - Outreach Automation</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .email-card {
            border: 1px solid #e3e6f0;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            background: white;
        }
        .email-header {
            padding: 1rem;
            background: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
            border-radius: 0.375rem 0.375rem 0 0;
        }
        .email-body {
            padding: 1rem;
            max-height: 300px;
            overflow-y: auto;
        }
        .email-meta {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        .email-subject {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }
        .email-content {
            line-height: 1.6;
            white-space: pre-wrap;
            font-family: Arial, sans-serif;
        }
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-draft { background: #fff3cd; color: #856404; }
        .status-sent { background: #d1ecf1; color: #0c5460; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .domain-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }
        .filter-section {
            background: white;
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            border: 1px solid #e3e6f0;
        }
    </style>
</head>
<body>
    <?php include 'includes/navigation.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <h1><i class="fas fa-envelope"></i> Generated Email Pitches</h1>
            </div>
            <div class="header-right">
                <span class="badge bg-info"><?php echo count($emails); ?> emails</span>
            </div>
        </header>

        <div style="padding: 2rem;">
            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" style="display: flex; gap: 1rem; align-items: center;">
                    <label for="campaign_id"><strong>Filter by Campaign:</strong></label>
                    <select name="campaign_id" id="campaign_id" onchange="this.form.submit()">
                        <option value="0">All Campaigns</option>
                        <?php foreach ($campaigns as $campaign): ?>
                            <option value="<?php echo $campaign['id']; ?>" <?php echo $campaignId == $campaign['id'] ? 'selected' : ''; ?>>
                                Campaign <?php echo $campaign['id']; ?>: <?php echo htmlspecialchars($campaign['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if (empty($emails)): ?>
                <div class="email-card">
                    <div class="email-header text-center">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h3>No Generated Emails Found</h3>
                        <p class="text-muted">No email pitches have been generated yet. Check your campaigns and automation settings.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($emails as $email): ?>
                    <div class="email-card">
                        <div class="email-header">
                            <div class="email-meta">
                                <div class="domain-info">
                                    <strong>
                                        <i class="fas fa-globe"></i> 
                                        <?php echo htmlspecialchars($email['domain']); ?>
                                    </strong>
                                    <span class="status-badge status-<?php echo $email['status']; ?>">
                                        <?php echo ucfirst($email['status']); ?>
                                    </span>
                                    <span>DR: <?php echo $email['domain_rating'] ?? 'N/A'; ?></span>
                                    <span>Quality: <?php echo number_format($email['quality_score'], 1); ?></span>
                                </div>
                                <div>
                                    <?php if ($email['sender_email']): ?>
                                        <strong>From:</strong> <?php echo htmlspecialchars($email['sender_email']); ?> |
                                    <?php endif; ?>
                                    <strong>To:</strong> <?php echo htmlspecialchars($email['recipient_email']); ?> |
                                    <strong>Campaign:</strong> <?php echo htmlspecialchars($email['campaign_name']); ?> |
                                    <strong>Generated:</strong> <?php echo date('M j, Y g:i A', strtotime($email['created_at'])); ?>
                                    <?php if ($email['sent_at']): ?>
                                        | <strong>Sent:</strong> <?php echo date('M j, Y g:i A', strtotime($email['sent_at'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="email-subject">
                                <i class="fas fa-paper-plane"></i> 
                                <?php echo htmlspecialchars($email['subject']); ?>
                            </div>
                        </div>
                        
                        <div class="email-body">
                            <div class="email-content">
<?php echo htmlspecialchars($email['body']); ?>
                            </div>
                        </div>
                        
                        <div style="padding: 1rem; background: #f8f9fc; border-top: 1px solid #e3e6f0;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong>Email ID:</strong> <?php echo $email['id']; ?> | 
                                    <strong>Domain ID:</strong> <?php echo $email['domain_id']; ?>
                                </div>
                                <div>
                                    <?php if ($email['status'] === 'draft'): ?>
                                        <span class="text-warning">
                                            <i class="fas fa-clock"></i> Ready to Send
                                        </span>
                                    <?php elseif ($email['status'] === 'sent'): ?>
                                        <span class="text-success">
                                            <i class="fas fa-check"></i> Email Sent Successfully
                                        </span>
                                    <?php elseif ($email['status'] === 'failed'): ?>
                                        <span class="text-danger">
                                            <i class="fas fa-exclamation-triangle"></i> Sending Failed
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/main.js"></script>
</body>
</html>