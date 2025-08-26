<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

try {
    $db = new Database();
    
    // Get parameters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $campaign = $_GET['campaign'] ?? '';
    $domainId = $_GET['domain_id'] ?? '';
    
    // Build WHERE conditions
    $whereConditions = ["1=1"];
    $params = [];
    
    if (!empty($search)) {
        $whereConditions[] = "(oe.recipient_email LIKE ? OR td.domain LIKE ? OR oe.subject LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if (!empty($status)) {
        $whereConditions[] = "oe.status = ?";
        $params[] = $status;
    }
    
    if (!empty($campaign)) {
        $whereConditions[] = "oe.campaign_id = ?";
        $params[] = $campaign;
    }
    
    if (!empty($domainId)) {
        $whereConditions[] = "oe.domain_id = ?";
        $params[] = $domainId;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total 
                 FROM outreach_emails oe
                 LEFT JOIN target_domains td ON oe.domain_id = td.id
                 LEFT JOIN campaigns c ON oe.campaign_id = c.id
                 WHERE $whereClause";
    
    $totalResult = $db->fetchOne($countSql, $params);
    $totalEmails = $totalResult['total'];
    $totalPages = ceil($totalEmails / $perPage);
    
    // Get emails with detailed tracking info
    $sql = "SELECT 
                oe.id,
                oe.sender_email,
                oe.recipient_email,
                oe.subject,
                oe.status,
                oe.sent_at,
                oe.reply_received_at,
                oe.reply_classification,
                oe.opened_at,
                oe.clicked_at,
                oe.bounced_at,
                oe.opened_count,
                oe.clicked_count,
                oe.unsubscribed_at,
                oe.gmail_message_id,
                oe.thread_id,
                td.domain,
                td.quality_score,
                td.domain_rating,
                c.name as campaign_name,
                c.id as campaign_id,
                -- Derive engagement status
                CASE 
                    WHEN oe.unsubscribed_at IS NOT NULL THEN 'unsubscribed'
                    WHEN oe.bounced_at IS NOT NULL THEN 'bounced'
                    WHEN oe.reply_received_at IS NOT NULL THEN 'replied'
                    WHEN oe.clicked_at IS NOT NULL THEN 'clicked'
                    WHEN oe.opened_at IS NOT NULL THEN 'opened'
                    WHEN oe.status = 'sent' THEN 'sent'
                    ELSE oe.status
                END as engagement_status,
                -- Calculate engagement metrics
                CASE 
                    WHEN oe.sent_at IS NOT NULL THEN 1 
                    ELSE 0 
                END as is_sent,
                CASE 
                    WHEN oe.opened_at IS NOT NULL THEN 1 
                    ELSE 0 
                END as is_opened,
                CASE 
                    WHEN oe.clicked_at IS NOT NULL THEN 1 
                    ELSE 0 
                END as is_clicked,
                CASE 
                    WHEN oe.reply_received_at IS NOT NULL THEN 1 
                    ELSE 0 
                END as is_replied,
                CASE 
                    WHEN oe.bounced_at IS NOT NULL THEN 1 
                    ELSE 0 
                END as is_bounced
            FROM outreach_emails oe
            LEFT JOIN target_domains td ON oe.domain_id = td.id
            LEFT JOIN campaigns c ON oe.campaign_id = c.id
            WHERE $whereClause
            ORDER BY oe.sent_at DESC
            LIMIT ? OFFSET ?";
    
    $finalParams = array_merge($params, [$perPage, $offset]);
    $emails = $db->fetchAll($sql, $finalParams);
    
    // Format dates and add time ago
    foreach ($emails as &$email) {
        $email['sent_at_formatted'] = $email['sent_at'] ? date('M j, Y g:i A', strtotime($email['sent_at'])) : null;
        $email['sent_at_ago'] = $email['sent_at'] ? timeAgo($email['sent_at']) : null;
        $email['opened_at_formatted'] = $email['opened_at'] ? date('M j, Y g:i A', strtotime($email['opened_at'])) : null;
        $email['clicked_at_formatted'] = $email['clicked_at'] ? date('M j, Y g:i A', strtotime($email['clicked_at'])) : null;
        $email['reply_received_at_formatted'] = $email['reply_received_at'] ? date('M j, Y g:i A', strtotime($email['reply_received_at'])) : null;
        $email['bounced_at_formatted'] = $email['bounced_at'] ? date('M j, Y g:i A', strtotime($email['bounced_at'])) : null;
        
        // Add engagement score (0-100)
        $engagementScore = 0;
        if ($email['is_sent']) $engagementScore += 20;
        if ($email['is_opened']) $engagementScore += 30;
        if ($email['is_clicked']) $engagementScore += 25;
        if ($email['is_replied']) $engagementScore += 25;
        $email['engagement_score'] = $engagementScore;
    }
    
    // Calculate summary statistics
    $statsSql = "SELECT 
                    COUNT(*) as total_sent,
                    SUM(CASE WHEN oe.opened_at IS NOT NULL THEN 1 ELSE 0 END) as total_opened,
                    SUM(CASE WHEN oe.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as total_clicked,
                    SUM(CASE WHEN oe.reply_received_at IS NOT NULL THEN 1 ELSE 0 END) as total_replied,
                    SUM(CASE WHEN oe.bounced_at IS NOT NULL THEN 1 ELSE 0 END) as total_bounced,
                    SUM(CASE WHEN oe.unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) as total_unsubscribed,
                    SUM(oe.opened_count) as total_opens,
                    SUM(oe.clicked_count) as total_clicks
                 FROM outreach_emails oe
                 LEFT JOIN target_domains td ON oe.domain_id = td.id
                 LEFT JOIN campaigns c ON oe.campaign_id = c.id
                 WHERE $whereClause";
    
    $stats = $db->fetchOne($statsSql, $params);
    
    // Calculate rates
    $openRate = $stats['total_sent'] > 0 ? round(($stats['total_opened'] / $stats['total_sent']) * 100, 1) : 0;
    $clickRate = $stats['total_sent'] > 0 ? round(($stats['total_clicked'] / $stats['total_sent']) * 100, 1) : 0;
    $replyRate = $stats['total_sent'] > 0 ? round(($stats['total_replied'] / $stats['total_sent']) * 100, 1) : 0;
    $bounceRate = $stats['total_sent'] > 0 ? round(($stats['total_bounced'] / $stats['total_sent']) * 100, 1) : 0;
    
    // Get available campaigns for filter
    $campaigns = $db->fetchAll("SELECT id, name FROM campaigns ORDER BY name");
    
    $response = [
        'success' => true,
        'emails' => $emails,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'total_emails' => $totalEmails
        ],
        'stats' => [
            'total_sent' => (int)$stats['total_sent'],
            'total_opened' => (int)$stats['total_opened'],
            'total_clicked' => (int)$stats['total_clicked'],
            'total_replied' => (int)$stats['total_replied'],
            'total_bounced' => (int)$stats['total_bounced'],
            'total_unsubscribed' => (int)$stats['total_unsubscribed'],
            'total_opens' => (int)$stats['total_opens'],
            'total_clicks' => (int)$stats['total_clicks'],
            'open_rate' => $openRate,
            'click_rate' => $clickRate,
            'reply_rate' => $replyRate,
            'bounce_rate' => $bounceRate
        ],
        'filters' => [
            'campaigns' => $campaigns,
            'statuses' => ['sent', 'replied', 'bounced', 'failed']
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . 'm ago';
    if ($time < 86400) return floor($time/3600) . 'h ago';
    if ($time < 2592000) return floor($time/86400) . 'd ago';
    if ($time < 31536000) return floor($time/2592000) . 'mo ago';
    return floor($time/31536000) . 'y ago';
}
?>