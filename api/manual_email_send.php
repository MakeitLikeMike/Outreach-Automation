<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../classes/AutomatedOutreach.php';
require_once '../classes/TargetDomain.php';
require_once '../classes/Campaign.php';
require_once '../classes/EmailQueue.php';
require_once '../config/database.php';

try {
    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests allowed');
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    // Validate required fields
    $requiredFields = ['domain_id', 'domain_name', 'contact_email', 'campaign_name'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    $domainId = (int)$input['domain_id'];
    $contactEmail = $input['contact_email'];
    $domainName = $input['domain_name'];
    $campaignName = $input['campaign_name'];

    // Initialize classes
    $automatedOutreach = new AutomatedOutreach();
    $targetDomain = new TargetDomain();
    $campaign = new Campaign();
    $emailQueue = new EmailQueue();

    // Get domain details
    $domain = $targetDomain->getById($domainId);
    if (!$domain) {
        throw new Exception("Domain not found with ID: $domainId");
    }

    // Verify domain has contact email
    if (empty($domain['contact_email'])) {
        throw new Exception("Domain has no contact email");
    }

    // Get campaign details
    $campaignDetails = $campaign->getById($domain['campaign_id']);
    if (!$campaignDetails) {
        throw new Exception("Campaign not found");
    }

    if ($campaignDetails['status'] !== 'active') {
        throw new Exception("Campaign is not active");
    }

    // Check if email already sent for this domain
    $db = new Database();
    $existingEmail = $db->fetchOne(
        "SELECT id, status FROM email_queue WHERE domain_id = ? AND campaign_id = ? ORDER BY created_at DESC LIMIT 1",
        [$domainId, $domain['campaign_id']]
    );

    if ($existingEmail && $existingEmail['status'] === 'sent') {
        throw new Exception("Email already sent for this domain");
    }

    // Trigger manual outreach
    $result = $automatedOutreach->manualTriggerOutreach($domainId);

    if (!$result['success']) {
        throw new Exception($result['error']);
    }

    // Get the queued email details
    $queuedEmail = $db->fetchOne(
        "SELECT eq.*, c.name as campaign_name, et.subject 
         FROM email_queue eq 
         JOIN campaigns c ON eq.campaign_id = c.id 
         LEFT JOIN email_templates et ON eq.template_id = et.id 
         WHERE eq.domain_id = ? 
         ORDER BY eq.created_at DESC LIMIT 1",
        [$domainId]
    );

    // Try to process the email immediately
    $processResult = $emailQueue->processQueue(1);

    // Check if email was sent
    $sentEmail = $db->fetchOne(
        "SELECT * FROM email_queue WHERE domain_id = ? AND status = 'sent' ORDER BY created_at DESC LIMIT 1",
        [$domainId]
    );

    $response = [
        'success' => true,
        'message' => 'Email queued successfully',
        'domain_id' => $domainId,
        'domain_name' => $domainName,
        'contact_email' => $contactEmail,
        'campaign_name' => $campaignName,
        'queue_id' => $queuedEmail['id'] ?? null,
        'sender_email' => $queuedEmail['sender_email'] ?? 'Unknown',
        'subject' => $queuedEmail['subject'] ?? 'Campaign Email',
        'scheduled_at' => $queuedEmail['scheduled_at'] ?? null,
        'immediately_processed' => $processResult['processed'] > 0,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    if ($sentEmail) {
        $response['status'] = 'sent';
        $response['message'] = 'Email sent successfully!';
        $response['sent_at'] = $sentEmail['created_at'];
    } else {
        $response['status'] = 'queued';
        $response['message'] = 'Email queued and will be sent shortly';
    }

    // Log the manual trigger
    $logData = [
        'action' => 'manual_email_trigger',
        'domain_id' => $domainId,
        'domain_name' => $domainName,
        'contact_email' => $contactEmail,
        'user_triggered' => true,
        'queue_id' => $queuedEmail['id'] ?? null
    ];

    $db->execute(
        "INSERT INTO api_logs (api_service, endpoint, method, request_data, response_data, status_code) VALUES (?, ?, ?, ?, ?, ?)",
        [
            'manual_outreach',
            'manual_email_send',
            'POST',
            json_encode($logData),
            json_encode($response),
            200
        ]
    );

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    $errorResponse = [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];

    // Log the error
    if (isset($db)) {
        try {
            $db->execute(
                "INSERT INTO api_logs (api_service, endpoint, method, request_data, response_data, status_code) VALUES (?, ?, ?, ?, ?, ?)",
                [
                    'manual_outreach',
                    'manual_email_send',
                    'POST',
                    json_encode($input ?? []),
                    json_encode($errorResponse),
                    400
                ]
            );
        } catch (Exception $logError) {
            // Ignore logging errors
        }
    }

    echo json_encode($errorResponse);
}
?>