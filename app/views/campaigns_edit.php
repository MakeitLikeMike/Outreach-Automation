<?php
$campaignId = (int)($_GET['id'] ?? 0);
if (!$campaignId) {
    header('Location: campaigns.php?error=' . urlencode('Campaign ID is required'));
    exit;
}

// Get campaign and templates
$campaignService = new CampaignService();
$currentCampaign = $campaignService->getCampaignById($campaignId);
$emailTemplates = $campaignService->getEmailTemplates();

if (!$currentCampaign) {
    header('Location: campaigns.php?error=' . urlencode('Campaign not found'));
    exit;
}

// Get messages from URL parameters
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Campaign - Outreach Automation</title>
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
                <h1>Edit Campaign</h1>
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
                    <h3><i class="fas fa-edit"></i> Edit Campaign</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="campaigns.php?action=update&id=<?php echo $currentCampaign['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        
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
                            <label for="email_template_id">Email Template</label>
                            <select id="email_template_id" name="email_template_id" class="form-control">
                                <option value="">Select an email template (optional)</option>
                                <?php foreach ($emailTemplates as $template): ?>
                                    <option value="<?php echo $template['id']; ?>" 
                                            <?php echo (($currentCampaign['email_template_id'] ?? null) == $template['id'] || (empty($currentCampaign['email_template_id']) && $template['is_default'])) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($template['name']); ?>
                                        <?php echo $template['is_default'] ? ' (Default)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="help-text">Choose an email template for outreach emails in this campaign</small>
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
        </div>
    </main>

    <script src="assets/js/main.js"></script>
    <script src="assets/js/campaigns.js"></script>
</body>
</html>