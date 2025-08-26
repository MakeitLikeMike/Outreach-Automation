<?php
/**
 * Domain Operations API
 * 
 * Handles AJAX requests for domain operations including:
 * - Approving/rejecting domains
 * - Triggering email search
 * - Manual email search retry
 * - Status updates with automated email search
 */

// Start output buffering to prevent any stray output
ob_start();

// Turn off error reporting to prevent HTML errors in JSON response
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

try {
    require_once '../classes/TargetDomain.php';
    require_once '../classes/EmailSearchService.php';
    require_once '../classes/ChatGPTIntegration.php';
    require_once '../classes/Campaign.php';
    require_once '../classes/EmailTemplate.php';
    require_once '../classes/OutreachAutomation.php';
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Required classes not found: ' . $e->getMessage()
    ]);
    exit;
}

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $domainId = $_POST['domain_id'] ?? $_GET['domain_id'] ?? null;
    
    // Add test endpoint
    if ($action === 'test') {
        $response['success'] = true;
        $response['message'] = 'API is working';
        $response['data'] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'post_data' => $_POST,
            'get_data' => $_GET
        ];
    } else {
        $targetDomain = new TargetDomain();
        $emailSearchService = new EmailSearchService();
        
        if (!$domainId && $action !== 'get_email_search_stats') {
            throw new Exception('Domain ID is required');
        }
    
    switch ($action) {
        case 'approve_domain':
            // Approve domain and trigger email search
            $result = $targetDomain->updateStatusWithEmailSearch($domainId, 'approved');
            
            if ($result['success']) {
                $response['success'] = true;
                $response['message'] = 'Domain approved successfully';
                $response['data'] = [
                    'status_updated' => $result['status_updated'],
                    'email_search_triggered' => !empty($result['email_search']),
                    'email_search_result' => $result['email_search']
                ];
                
                if ($result['email_search'] && isset($result['email_search']['email'])) {
                    $response['message'] .= ' and email found: ' . $result['email_search']['email'];
                }
            } else {
                throw new Exception($result['error'] ?? 'Failed to approve domain');
            }
            break;
            
        case 'reject_domain':
            // Reject domain
            $result = $targetDomain->updateStatus($domainId, 'rejected');
            
            if ($result) {
                $response['success'] = true;
                $response['message'] = 'Domain rejected successfully';
            } else {
                throw new Exception('Failed to reject domain');
            }
            break;
            
        case 'trigger_email_search':
            // Manually trigger email search for domain
            $priority = $_POST['priority'] ?? 'high';
            $result = $targetDomain->startEmailSearch($domainId, $priority);
            
            if ($result['success']) {
                $response['success'] = true;
                $response['message'] = 'Email search triggered successfully';
                $response['data'] = $result;
                
                if (isset($result['email'])) {
                    $response['message'] .= ' - Email found: ' . $result['email'];
                }
            } else {
                $response['success'] = false;
                $response['message'] = $result['error'] ?? 'Email search failed';
                $response['data'] = $result;
            }
            break;
            
        case 'retry_email_search':
            // Retry failed email search
            $force = isset($_POST['force']) && $_POST['force'] === 'true';
            $result = $targetDomain->retryEmailSearch($domainId, $force);
            
            if ($result['success']) {
                $response['success'] = true;
                $response['message'] = 'Email search retry triggered successfully';
                $response['data'] = $result;
                
                if (isset($result['email'])) {
                    $response['message'] .= ' - Email found: ' . $result['email'];
                }
            } else {
                $response['success'] = false;
                $response['message'] = $result['error'] ?? 'Email search retry failed';
                $response['data'] = $result;
            }
            break;
            
        case 'get_domain_status':
            // Get current domain status and email search info
            $domain = $targetDomain->getById($domainId);
            
            if ($domain) {
                $response['success'] = true;
                $response['message'] = 'Domain status retrieved';
                $response['data'] = [
                    'status' => $domain['status'],
                    'email_search_status' => $domain['email_search_status'] ?? 'pending',
                    'email_search_attempts' => $domain['email_search_attempts'] ?? 0,
                    'contact_email' => $domain['contact_email'],
                    'last_email_search_at' => $domain['last_email_search_at'] ?? null,
                    'email_search_error' => $domain['email_search_error'] ?? null,
                    'quality_score' => $domain['quality_score']
                ];
            } else {
                throw new Exception('Domain not found');
            }
            break;
            
        case 'batch_approve':
            // Batch approve multiple domains
            $domainIds = $_POST['domain_ids'] ?? [];
            
            if (!is_array($domainIds) || empty($domainIds)) {
                throw new Exception('Domain IDs array is required');
            }
            
            $results = [];
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($domainIds as $id) {
                try {
                    $result = $targetDomain->updateStatusWithEmailSearch($id, 'approved');
                    if ($result['success']) {
                        $successCount++;
                        $results[$id] = ['success' => true, 'result' => $result];
                    } else {
                        $errorCount++;
                        $results[$id] = ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
                    }
                } catch (Exception $e) {
                    $errorCount++;
                    $results[$id] = ['success' => false, 'error' => $e->getMessage()];
                }
            }
            
            $response['success'] = $successCount > 0;
            $response['message'] = "Batch operation completed: $successCount approved, $errorCount failed";
            $response['data'] = [
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'results' => $results
            ];
            break;
            
        case 'get_email_search_stats':
            // Get email search statistics for dashboard
            $stats = $targetDomain->getEmailSearchStatistics();
            
            $response['success'] = true;
            $response['message'] = 'Email search statistics retrieved';
            $response['data'] = $stats;
            break;
            
        case 'run_gpt_analysis':
            // Run GPT analysis for domain
            $domain = $targetDomain->getById($domainId);
            if (!$domain) {
                throw new Exception('Domain not found');
            }
            
            $chatgpt = new ChatGPTIntegration();
            $result = $chatgpt->analyzeGuestPostSuitability($domain['domain']);
            
            if (isset($result['overall_score'])) {
                // Update domain with AI analysis results
                $updateData = [
                    'ai_analysis_status' => 'completed',
                    'ai_overall_score' => $result['overall_score'],
                    'ai_guest_post_score' => $result['overall_score'],
                    'ai_content_quality_score' => null,
                    'ai_audience_alignment_score' => null,
                    'ai_priority_level' => 'medium',
                    'ai_recommendations' => $result['summary'] ?? 'Analysis completed',
                    'ai_last_analyzed_at' => date('Y-m-d H:i:s')
                ];
                
                $updateResult = $targetDomain->updateAIAnalysis($domainId, $updateData);
                
                if ($updateResult) {
                    $response['success'] = true;
                    $response['message'] = 'GPT analysis completed successfully';
                    $response['data'] = $result;
                } else {
                    throw new Exception('Failed to save GPT analysis results');
                }
            } else {
                $response['success'] = false;
                $response['message'] = 'GPT analysis failed: No overall score returned';
            }
            break;
            
        case 'generate_email_pitch':
            // Generate personalized email pitch for domain
            $domain = $targetDomain->getById($domainId);
            if (!$domain) {
                throw new Exception('Domain not found');
            }
            
            // Get campaign info for sender details
            $campaign = new Campaign();
            $campaignData = $campaign->getById($domain['campaign_id']);
            if (!$campaignData) {
                throw new Exception('Campaign not found');
            }
            
            // Get default email template
            $emailTemplate = new EmailTemplate();
            $template = $emailTemplate->getDefault();
            if (!$template) {
                throw new Exception('No default email template found');
            }
            
            // Extract topic area from AI recommendations
            $topicArea = 'business';
            if (!empty($domain['ai_recommendations'])) {
                // Simple keyword extraction for topic area
                $recommendations = strtolower($domain['ai_recommendations']);
                if (strpos($recommendations, 'tech') !== false || strpos($recommendations, 'software') !== false) {
                    $topicArea = 'technology';
                } elseif (strpos($recommendations, 'health') !== false || strpos($recommendations, 'medical') !== false) {
                    $topicArea = 'health and wellness';
                } elseif (strpos($recommendations, 'finance') !== false || strpos($recommendations, 'money') !== false) {
                    $topicArea = 'finance';
                } elseif (strpos($recommendations, 'travel') !== false || strpos($recommendations, 'tourism') !== false) {
                    $topicArea = 'travel';
                } elseif (strpos($recommendations, 'food') !== false || strpos($recommendations, 'recipe') !== false) {
                    $topicArea = 'food and lifestyle';
                }
            }
            
            // Get sender info from campaign or system settings
            $senderEmail = $campaignData['owner_email'] ?? 'outreach@example.com';
            $senderName = 'Outreach Team';
            
            // Replace template variables
            $subject = str_replace('{DOMAIN}', $domain['domain'], $template['subject']);
            $subject = str_replace('{TOPIC_AREA}', $topicArea, $subject);
            
            $body = str_replace('{DOMAIN}', $domain['domain'], $template['body']);
            $body = str_replace('{TOPIC_AREA}', $topicArea, $body);
            $body = str_replace('{SENDER_NAME}', $senderName, $body);
            $body = str_replace('{SENDER_EMAIL}', $senderEmail, $body);
            
            // Save generated email
            $outreachAutomation = new OutreachAutomation();
            $emailResult = $outreachAutomation->storeGeneratedEmail(
                $domain['campaign_id'],
                $domainId,
                $template['id'],
                $subject,
                $body,
                $senderEmail,
                $domain['contact_email'] ?? 'unknown@domain.com'
            );
            
            if ($emailResult) {
                $response['success'] = true;
                $response['message'] = 'Email pitch generated successfully';
                $response['data'] = [
                    'subject' => $subject,
                    'body' => $body,
                    'sender_email' => $senderEmail,
                    'topic_area' => $topicArea,
                    'email_id' => $emailResult
                ];
            } else {
                throw new Exception('Failed to save generated email');
            }
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    $response['debug'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    // Log the error
    error_log("[DomainOperationsAPI][ERROR] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
} catch (Error $e) {
    $response['success'] = false;
    $response['message'] = 'Fatal error: ' . $e->getMessage();
    $response['debug'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    // Log the error
    error_log("[DomainOperationsAPI][FATAL] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
} catch (Throwable $e) {
    $response['success'] = false;
    $response['message'] = 'Unexpected error: ' . $e->getMessage();
    $response['debug'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    // Log the error
    error_log("[DomainOperationsAPI][THROWABLE] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
}

// Ensure we always have a valid response
if (!isset($response) || !is_array($response)) {
    $response = [
        'success' => false,
        'message' => 'Invalid response generated',
        'data' => null
    ];
}

// Clean any buffered output and return JSON response only
ob_end_clean();

// Ensure JSON encoding works
$json = json_encode($response, JSON_PRETTY_PRINT);
if ($json === false) {
    $json = json_encode([
        'success' => false,
        'message' => 'JSON encoding failed: ' . json_last_error_msg(),
        'data' => null
    ]);
}

echo $json;
?>