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

try {
    switch ($type) {
        case 'campaigns':
            $data = getCampaignsData($db);
            break;
        case 'domains':
            $data = getDomainsData($db);
            break;
        case 'approved_domains':
            $data = getApprovedDomainsData($db);
            break;
        case 'contacted':
            $data = getContactedEmailsData($db);
            break;
        case 'forwarded':
            $data = getForwardedEmailsData($db);
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

function getCampaignsData($db) {
    $sql = "SELECT 
                c.*,
                COUNT(DISTINCT td.id) as total_domains,
                COUNT(DISTINCT CASE WHEN td.status IN ('approved', 'searching_email', 'generating_email', 'sending_email', 'monitoring_replies', 'contacted') THEN td.id END) as approved_domains,
                COUNT(DISTINCT CASE WHEN td.status = 'contacted' THEN td.id END) as contacted_domains,
                COUNT(DISTINCT CASE WHEN eq.status = 'sent' THEN eq.id END) as emails_sent,
                COUNT(DISTINCT CASE WHEN oe.reply_classification = 'interested' THEN oe.id END) as interested_replies
            FROM campaigns c
            LEFT JOIN target_domains td ON c.id = td.campaign_id
            LEFT JOIN email_queue eq ON c.id = eq.campaign_id
            LEFT JOIN outreach_emails oe ON c.id = oe.campaign_id
            GROUP BY c.id, c.name, c.competitor_urls, c.owner_email, c.email_template_id, c.status, c.created_at, c.updated_at, c.is_automated, c.automation_mode, c.auto_send, c.pipeline_status, c.processing_started_at, c.processing_completed_at, c.leads_forwarded
            ORDER BY c.created_at DESC";
    
    $campaigns = $db->fetchAll($sql);
    
    return ['campaigns' => $campaigns];
}

function getDomainsData($db) {
    $sql = "SELECT 
                td.*,
                c.name as campaign_name,
                COUNT(CASE WHEN oe.status = 'sent' THEN 1 END) as emails_sent,
                COUNT(CASE WHEN oe.reply_classification = 'interested' THEN 1 END) as interested_replies
            FROM target_domains td
            LEFT JOIN campaigns c ON td.campaign_id = c.id
            LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
            GROUP BY td.id, td.domain, td.campaign_id, td.status, td.domain_rating, td.organic_traffic, td.ranking_keywords, td.referring_domains, td.contact_email, td.quality_score, td.created_at, c.name
            ORDER BY td.created_at DESC
            LIMIT 100";
    
    $domains = $db->fetchAll($sql);
    
    // Calculate summary stats
    $totalDomains = count($domains);
    $avgDR = $totalDomains > 0 ? round(array_sum(array_column($domains, 'domain_rating')) / $totalDomains, 1) : 0;
    $withEmail = count(array_filter($domains, function($d) { return !empty($d['contact_email']); }));
    
    return [
        'domains' => $domains,
        'total' => $totalDomains,
        'avg_dr' => $avgDR,
        'with_email' => $withEmail
    ];
}

function getApprovedDomainsData($db) {
    $sql = "SELECT 
                td.*,
                c.name as campaign_name,
                COUNT(CASE WHEN oe.status = 'sent' THEN 1 END) as emails_sent,
                COUNT(CASE WHEN oe.status = 'replied' THEN 1 END) as replies_received,
                COUNT(CASE WHEN oe.reply_classification = 'interested' THEN 1 END) as interested_replies,
                CASE 
                    WHEN COUNT(CASE WHEN oe.status = 'sent' THEN 1 END) > 0 THEN 'sent'
                    ELSE td.status
                END as current_status
            FROM target_domains td
            LEFT JOIN campaigns c ON td.campaign_id = c.id
            LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
            WHERE td.status IN ('approved', 'contacted')
            GROUP BY td.id, td.domain, td.campaign_id, td.status, td.domain_rating, td.organic_traffic, td.ranking_keywords, td.referring_domains, td.contact_email, td.quality_score, td.created_at, c.name
            ORDER BY td.quality_score DESC
            LIMIT 50";
    
    $domains = $db->fetchAll($sql);
    
    // Calculate performance metrics
    $totalApproved = count($domains);
    $avgQualityScore = $totalApproved > 0 ? round(array_sum(array_column($domains, 'quality_score')) / $totalApproved, 1) : 0;
    $contactedCount = count(array_filter($domains, function($d) { return $d['emails_sent'] > 0; }));
    $totalReplies = array_sum(array_column($domains, 'replies_received'));
    $totalSent = array_sum(array_column($domains, 'emails_sent'));
    $replyRate = $totalSent > 0 ? round(($totalReplies / $totalSent) * 100, 1) : 0;
    
    return [
        'domains' => $domains,
        'avg_quality_score' => $avgQualityScore,
        'contacted_count' => $contactedCount,
        'reply_rate' => $replyRate
    ];
}

function getContactedEmailsData($db) {
    $sql = "SELECT 
                oe.*,
                td.domain,
                c.name as campaign_name,
                CASE WHEN oe.reply_classification IS NOT NULL THEN 1 ELSE 0 END as has_reply
            FROM outreach_emails oe
            LEFT JOIN target_domains td ON oe.domain_id = td.id
            LEFT JOIN campaigns c ON oe.campaign_id = c.id
            WHERE oe.status IN ('sent', 'replied')
            ORDER BY oe.sent_at DESC
            LIMIT 100";
    
    $emails = $db->fetchAll($sql);
    
    // Calculate stats
    $totalSent = count($emails);
    $replies = count(array_filter($emails, function($e) { return $e['has_reply']; }));
    $replyRate = $totalSent > 0 ? round(($replies / $totalSent) * 100, 1) : 0;
    
    // Format dates
    foreach ($emails as &$email) {
        $email['sent_at'] = date('M j, Y g:i A', strtotime($email['sent_at']));
    }
    
    return [
        'emails' => $emails,
        'total_sent' => $totalSent,
        'replies' => $replies,
        'reply_rate' => $replyRate
    ];
}

function getForwardedEmailsData($db) {
    $sql = "SELECT 
                fq.*,
                oe.recipient_email as contact_email,
                c.name as campaign_name,
                td.domain,
                td.quality_score,
                td.domain_rating
            FROM forwarding_queue fq
            LEFT JOIN outreach_emails oe ON fq.reply_id = oe.id
            LEFT JOIN campaigns c ON oe.campaign_id = c.id
            LEFT JOIN target_domains td ON oe.domain_id = td.id
            WHERE fq.status = 'completed'
            ORDER BY fq.processed_at DESC
            LIMIT 50";
    
    $forwards = $db->fetchAll($sql);
    
    // Calculate stats
    $total = count($forwards);
    $recent = count(array_filter($forwards, function($f) { 
        return strtotime($f['processed_at']) >= strtotime('-7 days'); 
    }));
    
    // Count interested forwards based on reply classification
    $interestedSql = "SELECT COUNT(*) as count 
                      FROM forwarding_queue fq
                      LEFT JOIN outreach_emails oe ON fq.reply_id = oe.id
                      WHERE fq.status = 'completed' 
                      AND oe.reply_classification = 'interested'";
    $interestedResult = $db->fetchOne($interestedSql);
    $interested = $interestedResult['count'];
    
    // Format dates
    foreach ($forwards as &$forward) {
        $forward['forwarded_at'] = date('M j, Y g:i A', strtotime($forward['processed_at']));
    }
    
    return [
        'forwards' => $forwards,
        'total' => $total,
        'recent' => $recent,
        'interested' => $interested
    ];
}
?>