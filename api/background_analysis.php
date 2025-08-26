<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../classes/DomainQualityAnalyzer.php';

try {
    $db = new Database();
    $analyzer = new DomainQualityAnalyzer();
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'start_analysis':
            $domain = trim($_POST['domain'] ?? '');
            $analysisType = $_POST['analysis_type'] ?? 'comprehensive';
            
            if (empty($domain)) {
                throw new Exception('Domain is required');
            }
            
            // Generate unique analysis ID
            $analysisId = uniqid('analysis_', true);
            
            // Store analysis request in session
            $_SESSION['domain_analysis'][$analysisId] = [
                'domain' => $domain,
                'analysis_type' => $analysisType,
                'status' => 'processing',
                'started_at' => time(),
                'progress' => 0
            ];
            
            // Start analysis in background (using output buffering to prevent timeout)
            if (function_exists('fastcgi_finish_request')) {
                // Send response immediately and continue processing in background
                echo json_encode([
                    'success' => true,
                    'analysis_id' => $analysisId,
                    'status' => 'processing',
                    'message' => 'Analysis started in background'
                ]);
                fastcgi_finish_request();
            }
            
            // Perform the actual analysis
            try {
                $_SESSION['domain_analysis'][$analysisId]['progress'] = 25;
                
                if ($analysisType === 'comprehensive') {
                    $result = $analyzer->analyzeDomainQuality($domain);
                } else if ($analysisType === 'quality') {
                    $result = $analyzer->analyzeDomainQuality($domain);
                } else {
                    // Backlinks only analysis
                    $api = new ApiIntegration();
                    $backlinks = $api->fetchBacklinks($domain, 100);
                    $profile = $api->getBacklinkProfile($domain);
                    $competitors = $api->getCompetitorBacklinks($domain, 20);
                    
                    $result = [
                        'domain' => $domain,
                        'backlinks' => $backlinks,
                        'profile' => $profile,
                        'competitors' => $competitors,
                        'analysis_date' => date('Y-m-d H:i:s')
                    ];
                }
                
                $_SESSION['domain_analysis'][$analysisId] = [
                    'domain' => $domain,
                    'analysis_type' => $analysisType,
                    'status' => 'completed',
                    'started_at' => $_SESSION['domain_analysis'][$analysisId]['started_at'],
                    'completed_at' => time(),
                    'progress' => 100,
                    'result' => $result
                ];
                
            } catch (Exception $e) {
                $_SESSION['domain_analysis'][$analysisId] = [
                    'domain' => $domain,
                    'analysis_type' => $analysisType,
                    'status' => 'failed',
                    'started_at' => $_SESSION['domain_analysis'][$analysisId]['started_at'],
                    'completed_at' => time(),
                    'progress' => 0,
                    'error' => $e->getMessage()
                ];
            }
            
            // If fastcgi_finish_request wasn't available, send response now
            if (!function_exists('fastcgi_finish_request')) {
                echo json_encode([
                    'success' => true,
                    'analysis_id' => $analysisId,
                    'status' => $_SESSION['domain_analysis'][$analysisId]['status'],
                    'message' => 'Analysis completed'
                ]);
            }
            break;
            
        case 'check_status':
            $analysisId = $_GET['analysis_id'] ?? '';
            
            if (empty($analysisId) || !isset($_SESSION['domain_analysis'][$analysisId])) {
                throw new Exception('Invalid analysis ID');
            }
            
            $analysis = $_SESSION['domain_analysis'][$analysisId];
            
            $response = [
                'success' => true,
                'analysis_id' => $analysisId,
                'domain' => $analysis['domain'],
                'status' => $analysis['status'],
                'progress' => $analysis['progress'],
                'started_at' => $analysis['started_at']
            ];
            
            if ($analysis['status'] === 'completed') {
                $response['result'] = $analysis['result'];
                $response['completed_at'] = $analysis['completed_at'];
            } elseif ($analysis['status'] === 'failed') {
                $response['error'] = $analysis['error'];
                $response['completed_at'] = $analysis['completed_at'];
            }
            
            echo json_encode($response);
            break;
            
        case 'get_all_analyses':
            $analyses = $_SESSION['domain_analysis'] ?? [];
            
            // Clean up old analyses (older than 1 hour)
            $cutoff = time() - 3600;
            foreach ($analyses as $id => $analysis) {
                if ($analysis['started_at'] < $cutoff) {
                    unset($_SESSION['domain_analysis'][$id]);
                }
            }
            
            echo json_encode([
                'success' => true,
                'analyses' => $_SESSION['domain_analysis'] ?? []
            ]);
            break;
            
        case 'clear_analysis':
            $analysisId = $_POST['analysis_id'] ?? '';
            
            if (!empty($analysisId) && isset($_SESSION['domain_analysis'][$analysisId])) {
                unset($_SESSION['domain_analysis'][$analysisId]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Analysis cleared']);
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>