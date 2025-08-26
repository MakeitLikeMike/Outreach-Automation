<?php
try {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token. Please refresh and try again.');
    }

    // Create campaign using service
    $campaignService = new CampaignService();
    $result = $campaignService->createCampaign($_POST);

    if ($result['success']) {
        // Redirect to campaigns list with success message
        header('Location: campaigns.php?message=' . urlencode($result['message']));
        exit;
    } else {
        // Redirect back to create form with error
        header('Location: campaigns.php?action=create&error=' . urlencode($result['error']));
        exit;
    }

} catch (Exception $e) {
    error_log('[campaigns.store] ' . $e->getMessage());
    
    // Redirect back to create form with error
    header('Location: campaigns.php?action=create&error=' . urlencode($e->getMessage()));
    exit;
}