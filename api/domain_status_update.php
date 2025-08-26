<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    require_once __DIR__ . '/../config/database.php';
    
    $campaignId = $_GET['campaign_id'] ?? null;
    
    $db = new Database();
    
    // Get domain status counts
    $sql = "SELECT status, COUNT(*) as count FROM target_domains";
    $params = [];
    
    if ($campaignId) {
        $sql .= " WHERE campaign_id = ?";
        $params[] = $campaignId;
    }
    
    $sql .= " GROUP BY status";
    
    $statusCounts = $db->fetchAll($sql, $params);
    
    // Get recent processed domains
    $recentSql = "SELECT id, domain, status, domain_rating, quality_score FROM target_domains";
    if ($campaignId) {
        $recentSql .= " WHERE campaign_id = ?";
    }
    $recentSql .= " ORDER BY id DESC LIMIT 20";
    
    $recentDomains = $db->fetchAll($recentSql, $params);
    
    // Get processing stats
    $processingCount = 0;
    $pendingCount = 0;
    
    foreach ($statusCounts as $status) {
        if (in_array($status['status'], ['pending', 'analyzing', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies'])) {
            $processingCount += $status['count'];
        }
        if ($status['status'] === 'pending') {
            $pendingCount = $status['count'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'status_counts' => $statusCounts,
        'recent_domains' => $recentDomains,
        'processing_count' => $processingCount,
        'pending_count' => $pendingCount,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>