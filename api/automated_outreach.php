<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../classes/AutomatedOutreach.php';
require_once '../classes/TargetDomain.php';
require_once '../config/database.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

try {
    $automatedOutreach = new AutomatedOutreach();
    $targetDomain = new TargetDomain();
    
    switch ($action) {
        case 'status':
            // Get overall automation status
            $stats = $automatedOutreach->getAutomationStatistics();
            $domainsReady = count($targetDomain->getDomainsReadyForOutreach());
            
            echo json_encode([
                'success' => true,
                'status' => 'active',
                'statistics' => $stats,
                'domains_ready_for_outreach' => $domainsReady,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'trigger_immediate':
            // Trigger immediate processing of domains with found emails
            $limit = (int)($_POST['limit'] ?? 10);
            $result = $automatedOutreach->processDomainsWithFoundEmails($limit);
            
            echo json_encode([
                'success' => true,
                'action' => 'trigger_immediate',
                'result' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'trigger_domain':
            // Manual trigger for specific domain
            $domainId = (int)($_POST['domain_id'] ?? 0);
            
            if (!$domainId) {
                throw new Exception('Domain ID is required');
            }
            
            $result = $automatedOutreach->manualTriggerOutreach($domainId);
            
            echo json_encode([
                'success' => $result['success'],
                'action' => 'trigger_domain',
                'domain_id' => $domainId,
                'result' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'process_queue':
            // Process email queue immediately
            $limit = (int)($_POST['limit'] ?? 5);
            $result = $automatedOutreach->processQueueImmediate($limit);
            
            echo json_encode([
                'success' => true,
                'action' => 'process_queue',
                'processed' => $result['processed'],
                'errors' => $result['errors'] ?? [],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'ready_domains':
            // Get domains ready for outreach
            $campaignId = $_GET['campaign_id'] ?? null;
            $domains = $targetDomain->getDomainsReadyForOutreach($campaignId);
            
            echo json_encode([
                'success' => true,
                'action' => 'ready_domains',
                'count' => count($domains),
                'domains' => array_map(function($domain) {
                    return [
                        'id' => $domain['id'],
                        'domain' => $domain['domain'],
                        'campaign_name' => $domain['campaign_name'],
                        'contact_email' => $domain['contact_email'],
                        'quality_score' => $domain['quality_score'],
                        'domain_rating' => $domain['domain_rating'],
                        'organic_traffic' => $domain['organic_traffic']
                    ];
                }, $domains),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'campaign_stats':
            // Get automation statistics for specific campaign
            $campaignId = $_GET['campaign_id'] ?? null;
            
            if (!$campaignId) {
                throw new Exception('Campaign ID is required');
            }
            
            $stats = $automatedOutreach->getAutomationStatistics($campaignId);
            $readyDomains = count($targetDomain->getDomainsReadyForOutreach($campaignId));
            
            echo json_encode([
                'success' => true,
                'action' => 'campaign_stats',
                'campaign_id' => $campaignId,
                'statistics' => $stats,
                'domains_ready' => $readyDomains,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'run_cycle':
            // Run full automated cycle
            $result = $automatedOutreach->runAutomatedOutreachCycle();
            
            echo json_encode([
                'success' => true,
                'action' => 'run_cycle',
                'result' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>