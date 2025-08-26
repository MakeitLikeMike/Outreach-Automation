<?php
// Start output buffering to prevent any stray output
ob_start();

// Turn off error display to prevent HTML errors in JSON response
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
require_once '../config/database.php';

$db = new Database();
$type = $_GET['type'] ?? '';
$dateRange = (int)($_GET['date_range'] ?? 30);
$page = (int)($_GET['page'] ?? 1);
$limit = (int)($_GET['limit'] ?? 50);
$offset = ($page - 1) * $limit;

try {
    switch ($type) {
        case 'campaign_analytics':
            $data = getCampaignAnalytics($db);
            break;
        case 'emails_sent':
            $data = getEmailsSentDetails($db, $dateRange, $page, $limit, $offset);
            break;
        case 'replies_received':
            $data = getRepliesReceivedDetails($db, $dateRange, $page, $limit, $offset);
            break;
        case 'response_rate':
            $data = getResponseRateAnalytics($db, $dateRange);
            break;
        default:
            throw new Exception('Invalid data type requested');
    }
    
    // Clean any buffered output and return JSON response only
    ob_end_clean();
    
    // Ensure JSON encoding works
    $json = json_encode($data);
    if ($json === false) {
        $json = json_encode([
            'error' => 'JSON encoding failed: ' . json_last_error_msg(),
            'data' => null
        ]);
    }
    
    echo $json;
    
} catch (Exception $e) {
    // Clean any buffered output
    ob_end_clean();
    
    http_response_code(500);
    $json = json_encode(['error' => $e->getMessage()]);
    if ($json === false) {
        $json = json_encode(['error' => 'Unknown error occurred']);
    }
    echo $json;
}

function getCampaignAnalytics($db) {
    $sql = "SELECT 
                c.*,
                COUNT(DISTINCT td.id) as total_domains,
                COUNT(DISTINCT CASE WHEN eq.status = 'sent' THEN eq.id END) as emails_sent,
                COUNT(DISTINCT CASE WHEN oe.status = 'replied' THEN oe.id END) as replies_received,
                COUNT(DISTINCT CASE WHEN oe.reply_classification = 'interested' THEN oe.id END) as interested_replies
            FROM campaigns c
            LEFT JOIN target_domains td ON c.id = td.campaign_id
            LEFT JOIN email_queue eq ON c.id = eq.campaign_id
            LEFT JOIN outreach_emails oe ON c.id = oe.campaign_id
            GROUP BY c.id, c.name, c.competitor_urls, c.owner_email, c.email_template_id, c.status, c.created_at, c.updated_at, c.is_automated, c.automation_mode, c.auto_send, c.pipeline_status, c.processing_started_at, c.processing_completed_at, c.leads_forwarded
            ORDER BY c.created_at DESC";
    
    $campaigns = $db->fetchAll($sql);
    
    // Calculate summary statistics
    $totalCampaigns = count($campaigns);
    $avgDomains = $totalCampaigns > 0 ? round(array_sum(array_column($campaigns, 'total_domains')) / $totalCampaigns, 1) : 0;
    
    $totalSent = array_sum(array_column($campaigns, 'emails_sent'));
    $totalReplies = array_sum(array_column($campaigns, 'replies_received'));
    $avgResponseRate = $totalSent > 0 ? round(($totalReplies / $totalSent) * 100, 1) : 0;
    
    return [
        'campaigns' => $campaigns,
        'total_campaigns' => $totalCampaigns,
        'avg_domains' => $avgDomains,
        'avg_response_rate' => $avgResponseRate
    ];
}

function getEmailsSentDetails($db, $dateRange, $page, $limit, $offset) {
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total 
                 FROM outreach_emails oe
                 WHERE oe.status IN ('sent', 'delivered', 'bounced')
                 AND oe.sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $totalResult = $db->fetchOne($countSql, [$dateRange]);
    $total = $totalResult['total'];
    $totalPages = ceil($total / $limit);
    
    // Get emails with details - handle NULL domain_id for quick outreach
    $sql = "SELECT 
                oe.*,
                COALESCE(td.domain, SUBSTRING_INDEX(oe.recipient_email, '@', -1)) as domain,
                c.name as campaign_name,
                CASE 
                    WHEN oe.status = 'sent' AND oe.delivery_status IS NULL THEN 'delivered'
                    ELSE oe.delivery_status 
                END as delivery_status
            FROM outreach_emails oe
            LEFT JOIN target_domains td ON oe.domain_id = td.id
            LEFT JOIN campaigns c ON oe.campaign_id = c.id
            WHERE oe.status IN ('sent', 'delivered', 'bounced')
            AND oe.sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY oe.sent_at DESC
            LIMIT ? OFFSET ?";
    
    $emails = $db->fetchAll($sql, [$dateRange, $limit, $offset]);
    
    // Calculate status counts
    $delivered = count(array_filter($emails, function($e) { return $e['status'] === 'sent' || $e['delivery_status'] === 'delivered'; }));
    $bounced = count(array_filter($emails, function($e) { return $e['status'] === 'bounced' || $e['delivery_status'] === 'bounced'; }));
    $pending = count(array_filter($emails, function($e) { return $e['status'] === 'queued' || $e['status'] === 'processing'; }));
    
    // Format dates
    foreach ($emails as &$email) {
        $email['sent_at'] = date('M j, Y g:i A', strtotime($email['sent_at']));
    }
    
    return [
        'emails' => $emails,
        'total' => $total,
        'delivered' => $delivered,
        'bounced' => $bounced,
        'pending' => $pending,
        'page' => $page,
        'total_pages' => $totalPages
    ];
}

function getRepliesReceivedDetails($db, $dateRange, $page, $limit, $offset) {
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total 
                 FROM outreach_emails oe
                 WHERE oe.reply_classification IS NOT NULL
                 AND oe.replied_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $totalResult = $db->fetchOne($countSql, [$dateRange]);
    $total = $totalResult['total'];
    $totalPages = ceil($total / $limit);
    
    // Get replies with details
    $sql = "SELECT 
                oe.*,
                td.domain,
                c.name as campaign_name,
                SUBSTRING(oe.reply_body, 1, 200) as reply_snippet,
                oe.subject as original_subject,
                oe.replied_at,
                oe.from_email
            FROM outreach_emails oe
            LEFT JOIN target_domains td ON oe.domain_id = td.id
            LEFT JOIN campaigns c ON oe.campaign_id = c.id
            WHERE oe.reply_classification IS NOT NULL
            AND oe.replied_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY oe.replied_at DESC
            LIMIT ? OFFSET ?";
    
    $replies = $db->fetchAll($sql, [$dateRange, $limit, $offset]);
    
    // Calculate classification counts
    $interested = count(array_filter($replies, function($r) { return $r['reply_classification'] === 'interested'; }));
    $notInterested = count(array_filter($replies, function($r) { return $r['reply_classification'] === 'not_interested'; }));
    $unclassified = count(array_filter($replies, function($r) { return empty($r['reply_classification']); }));
    
    // Format dates
    foreach ($replies as &$reply) {
        $reply['replied_at'] = date('M j, Y g:i A', strtotime($reply['replied_at']));
    }
    
    return [
        'replies' => $replies,
        'total' => $total,
        'interested' => $interested,
        'not_interested' => $notInterested,
        'unclassified' => $unclassified,
        'page' => $page,
        'total_pages' => $totalPages
    ];
}

function getResponseRateAnalytics($db, $dateRange) {
    // Overall statistics
    $overallSql = "SELECT 
                       COUNT(CASE WHEN status = 'sent' THEN 1 END) as total_sent,
                       COUNT(CASE WHEN reply_classification IS NOT NULL THEN 1 END) as total_replies,
                       COUNT(CASE WHEN reply_classification = 'interested' THEN 1 END) as interested_replies
                   FROM outreach_emails 
                   WHERE sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    
    $overall = $db->fetchOne($overallSql, [$dateRange]);
    $overall['response_rate'] = $overall['total_sent'] > 0 ? 
        round(($overall['total_replies'] / $overall['total_sent']) * 100, 1) : 0;
    $overall['interest_rate'] = $overall['total_replies'] > 0 ? 
        round(($overall['interested_replies'] / $overall['total_replies']) * 100, 1) : 0;
    
    // Response rate by campaign
    $campaignSql = "SELECT 
                        c.name as campaign_name,
                        COUNT(CASE WHEN oe.status = 'sent' THEN 1 END) as emails_sent,
                        COUNT(CASE WHEN oe.reply_classification IS NOT NULL THEN 1 END) as replies,
                        ROUND(
                            CASE WHEN COUNT(CASE WHEN oe.status = 'sent' THEN 1 END) > 0
                            THEN (COUNT(CASE WHEN oe.reply_classification IS NOT NULL THEN 1 END) / 
                                  COUNT(CASE WHEN oe.status = 'sent' THEN 1 END)) * 100
                            ELSE 0 END, 1
                        ) as response_rate
                    FROM campaigns c
                    LEFT JOIN outreach_emails oe ON c.id = oe.campaign_id 
                        AND oe.sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY c.id, c.name
                    HAVING emails_sent > 0
                    ORDER BY response_rate DESC";
    
    $byCampaign = $db->fetchAll($campaignSql, [$dateRange]);
    
    // Daily trends
    $trendsSql = "SELECT 
                      DATE(sent_at) as date,
                      COUNT(CASE WHEN status = 'sent' THEN 1 END) as emails_sent,
                      COUNT(CASE WHEN reply_classification IS NOT NULL THEN 1 END) as replies,
                      ROUND(
                          CASE WHEN COUNT(CASE WHEN status = 'sent' THEN 1 END) > 0
                          THEN (COUNT(CASE WHEN reply_classification IS NOT NULL THEN 1 END) / 
                                COUNT(CASE WHEN status = 'sent' THEN 1 END)) * 100
                          ELSE 0 END, 1
                      ) as response_rate
                  FROM outreach_emails
                  WHERE sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  GROUP BY DATE(sent_at)
                  ORDER BY date DESC
                  LIMIT 30";
    
    $dailyTrends = $db->fetchAll($trendsSql, [$dateRange]);
    
    // Format dates
    foreach ($dailyTrends as &$trend) {
        $trend['date'] = date('M j', strtotime($trend['date']));
    }
    
    return [
        'overall' => $overall,
        'by_campaign' => $byCampaign,
        'daily_trends' => array_reverse($dailyTrends) // Reverse to show chronological order
    ];
}
?>