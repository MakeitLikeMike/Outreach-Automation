<?php
try {
    $campaignId = (int)($_GET['id'] ?? 0);
    if (!$campaignId) {
        throw new Exception('Campaign ID is required');
    }

    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token. Please refresh and try again.');
    }

    // Update campaign using service
    $campaignService = new CampaignService();
    $result = $campaignService->updateCampaign($campaignId, $_POST);

    if ($result['success']) {
        // Redirect to campaigns list with success message
        header('Location: campaigns.php?message=' . urlencode($result['message']));
        exit;
    } else {
        // Redirect back to edit form with error
        header('Location: campaigns.php?action=edit&id=' . $campaignId . '&error=' . urlencode($result['error']));
        exit;
    }

} catch (Exception $e) {
    error_log('[campaigns.update] ' . $e->getMessage());
    
    $campaignId = (int)($_GET['id'] ?? 0);
    header('Location: campaigns.php?action=edit&id=' . $campaignId . '&error=' . urlencode($e->getMessage()));
    exit;
}