<?php
try {
    $campaignId = (int)($_GET['id'] ?? 0);
    if (!$campaignId) {
        throw new Exception('Campaign ID is required');
    }

    // Delete campaign using service
    $campaignService = new CampaignService();
    $result = $campaignService->deleteCampaign($campaignId);

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
    error_log('[campaigns.delete] ' . $e->getMessage());
    
    // Redirect to campaigns list with error
    header('Location: campaigns.php?error=' . urlencode($e->getMessage()));
    exit;
}