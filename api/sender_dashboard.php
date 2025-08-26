<?php
require_once __DIR__ . '/../classes/SenderRotation.php';
require_once __DIR__ . '/../classes/SenderHealth.php';
require_once __DIR__ . '/../classes/GmailOAuth.php';

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? 'dashboard';
    
    $senderRotation = new SenderRotation();
    $senderHealth = new SenderHealth();
    $gmailOAuth = new GmailOAuth();
    
    switch ($action) {
        case 'dashboard':
            // Get comprehensive dashboard data
            $response = [
                'rotation_stats' => $senderRotation->getRotationStatistics(),
                'sender_status' => $senderRotation->getAllSendersStatus(),
                'health_summary' => $senderHealth->getSenderHealthSummary(),
                'unhealthy_senders' => $senderHealth->getUnhealthySenders(true),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'rotation_stats':
            $response = $senderRotation->getRotationStatistics();
            break;
            
        case 'sender_status':
            $email = $_GET['email'] ?? null;
            if ($email) {
                $response = $senderRotation->getSenderStatus($email);
            } else {
                $response = $senderRotation->getAllSendersStatus();
            }
            break;
            
        case 'health_check':
            $email = $_GET['email'] ?? null;
            if ($email) {
                $response = $senderHealth->checkSenderHealth($email);
            } else {
                $response = $senderHealth->checkAllSenders();
            }
            break;
            
        case 'health_summary':
            $response = $senderHealth->getSenderHealthSummary();
            break;
            
        case 'unhealthy_senders':
            $includeWarnings = $_GET['include_warnings'] !== 'false';
            $response = $senderHealth->getUnhealthySenders($includeWarnings);
            break;
            
        case 'reset_limits':
            $email = $_GET['email'] ?? null;
            $limitType = $_GET['limit_type'] ?? 'all';
            
            if (!$email) {
                throw new Exception('Email parameter is required for limit reset');
            }
            
            $senderRotation->resetSenderLimits($email, $limitType);
            $response = [
                'success' => true,
                'message' => "Rate limits reset for $email",
                'reset_type' => $limitType
            ];
            break;
            
        case 'suspend_sender':
            $email = $_GET['email'] ?? null;
            $reason = $_GET['reason'] ?? 'Manual suspension';
            
            if (!$email) {
                throw new Exception('Email parameter is required for suspension');
            }
            
            $senderHealth->markSenderSuspended($email, $reason);
            $response = [
                'success' => true,
                'message' => "Sender $email has been suspended",
                'reason' => $reason
            ];
            break;
            
        case 'reactivate_sender':
            $email = $_GET['email'] ?? null;
            
            if (!$email) {
                throw new Exception('Email parameter is required for reactivation');
            }
            
            $result = $senderHealth->reactivateSender($email);
            $response = [
                'success' => true,
                'message' => "Sender $email has been reactivated",
                'new_health_data' => $result
            ];
            break;
            
        case 'test_sender':
            $email = $_GET['email'] ?? null;
            
            if (!$email) {
                throw new Exception('Email parameter is required for testing');
            }
            
            $testResult = $gmailOAuth->testConnection($email);
            $healthResult = $senderHealth->checkSenderHealth($email);
            
            $response = [
                'success' => $testResult['success'],
                'connection_test' => $testResult,
                'health_check' => $healthResult,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'get_next_sender':
            $excludeEmails = isset($_GET['exclude']) ? explode(',', $_GET['exclude']) : [];
            
            try {
                $nextSender = $senderRotation->getNextAvailableSender($excludeEmails);
                $response = [
                    'success' => true,
                    'sender' => $nextSender,
                    'selection_time' => date('Y-m-d H:i:s')
                ];
            } catch (Exception $e) {
                $response = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'available_count' => count($senderRotation->getAllSendersStatus())
                ];
            }
            break;
            
        case 'queue_stats':
            // Get queue statistics integrated with sender data
            $db = new Database();
            
            $sql = "SELECT 
                        eq.sender_email,
                        eq.status,
                        COUNT(*) as count,
                        MIN(eq.scheduled_at) as next_scheduled
                    FROM email_queue eq
                    WHERE eq.status IN ('queued', 'processing', 'paused')
                    GROUP BY eq.sender_email, eq.status
                    ORDER BY eq.sender_email, eq.status";
            
            $queueStats = $db->fetchAll($sql);
            
            // Group by sender
            $senderQueues = [];
            foreach ($queueStats as $stat) {
                $email = $stat['sender_email'];
                if (!isset($senderQueues[$email])) {
                    $senderQueues[$email] = [];
                }
                $senderQueues[$email][$stat['status']] = [
                    'count' => $stat['count'],
                    'next_scheduled' => $stat['next_scheduled']
                ];
            }
            
            $response = [
                'sender_queues' => $senderQueues,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}
?>