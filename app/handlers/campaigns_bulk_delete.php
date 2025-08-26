<?php
try {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token. Please refresh and try again.');
    }

    $campaignIds = $_POST['campaign_ids'] ?? [];
    
    // Bulk delete campaigns using service
    $campaignService = new CampaignService();
    $result = $campaignService->bulkDeleteCampaigns($campaignIds);

    if ($result['success']) {
        // Redirect to campaigns list with success message
        header('Location: campaigns.php?message=' . urlencode($result['message']));
        exit;
    } else {
        // Redirect to campaigns list with error
        header('Location: campaigns.php?error=' . urlencode($result['error']));
        exit;
    }

} catch (Exception $e) {
    error_log('[campaigns.bulk_delete] ' . $e->getMessage());
    
    // Redirect to campaigns list with error
    header('Location: campaigns.php?error=' . urlencode($e->getMessage()));
    exit;
}