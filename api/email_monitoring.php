<?php
/**
 * Email Sending Status Monitoring API
 * Provides real-time status and analytics for email campaigns
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../classes/EmailQueue.php';
require_once '../classes/SenderRotation.php';
require_once '../classes/SenderHealth.php';
require_once '../config/database.php';

class EmailMonitoringAPI {
    private $db;
    private $emailQueue;
    private $senderRotation;
    private $senderHealth;
    
    public function __construct() {
        $this->db = new Database();
        $this->emailQueue = new EmailQueue();
        $this->senderRotation = new SenderRotation();
        $this->senderHealth = new SenderHealth();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? 'dashboard';
        
        try {
            switch ($action) {
                case 'dashboard':
                    return $this->getDashboardData();
                    
                case 'queue_stats':
                    return $this->getQueueStats();
                    
                case 'sender_performance':
                    return $this->getSenderPerformance();
                    
                case 'campaign_progress':
                    $campaignId = $_GET['campaign_id'] ?? null;
                    return $this->getCampaignProgress($campaignId);
                    
                case 'retry_stats':
                    return $this->getRetryStatistics();
                    
                case 'real_time_status':
                    return $this->getRealTimeStatus();
                    
                case 'error_analysis':
                    return $this->getErrorAnalysis();
                    
                default:
                    throw new Exception("Unknown action: $action");
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function getDashboardData() {
        return [
            'success' => true,
            'data' => [
                'queue_stats' => $this->getQueueStats()['data'],
                'sender_health' => $this->getSenderHealthSummary(),
                'recent_activity' => $this->getRecentActivity(),
                'performance_metrics' => $this->getPerformanceMetrics(),
                'alert_summary' => $this->getAlertSummary()
            ]
        ];
    }
    
    private function getQueueStats() {
        $stats = $this->emailQueue->getQueueStats();
        
        $summary = [
            'total' => 0,
            'queued' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
            'failed_permanent' => 0,
            'paused' => 0,
            'cancelled' => 0
        ];
        
        foreach ($stats as $stat) {
            $status = $stat['status'];
            $count = $stat['count'];
            $summary['total'] += $count;
            $summary[$status] = $count;
        }
        
        // Calculate rates
        $summary['success_rate'] = $summary['total'] > 0 ? 
            round(($summary['sent'] / $summary['total']) * 100, 2) : 0;
            
        $summary['failure_rate'] = $summary['total'] > 0 ? 
            round((($summary['failed'] + $summary['failed_permanent']) / $summary['total']) * 100, 2) : 0;
        
        return [
            'success' => true,
            'data' => $summary
        ];
    }
    
    private function getSenderPerformance() {
        $sql = "SELECT 
                    sender_email,
                    COUNT(*) as total_sent,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status IN ('failed', 'failed_permanent') THEN 1 ELSE 0 END) as failed,
                    AVG(retry_count) as avg_retries,
                    MAX(processed_at) as last_activity
                FROM email_queue 
                WHERE sender_email IS NOT NULL 
                AND processed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY sender_email
                ORDER BY total_sent DESC";
        
        $results = $this->db->fetchAll($sql);
        
        foreach ($results as &$result) {
            $result['success_rate'] = $result['total_sent'] > 0 ? 
                round(($result['successful'] / $result['total_sent']) * 100, 2) : 0;
                
            $result['failure_rate'] = $result['total_sent'] > 0 ? 
                round(($result['failed'] / $result['total_sent']) * 100, 2) : 0;
        }
        
        return [
            'success' => true,
            'data' => $results
        ];
    }
    
    private function getCampaignProgress($campaignId = null) {
        $whereClause = $campaignId ? "WHERE eq.campaign_id = ?" : "";
        $params = $campaignId ? [$campaignId] : [];
        
        $sql = "SELECT 
                    eq.campaign_id,
                    c.name as campaign_name,
                    COUNT(*) as total_emails,
                    SUM(CASE WHEN eq.status = 'queued' THEN 1 ELSE 0 END) as queued,
                    SUM(CASE WHEN eq.status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN eq.status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN eq.status IN ('failed', 'failed_permanent') THEN 1 ELSE 0 END) as failed,
                    MIN(eq.scheduled_at) as earliest_scheduled,
                    MAX(eq.scheduled_at) as latest_scheduled,
                    AVG(eq.retry_count) as avg_retries
                FROM email_queue eq
                LEFT JOIN campaigns c ON eq.campaign_id = c.id
                $whereClause
                GROUP BY eq.campaign_id, c.name
                ORDER BY total_emails DESC";
        
        $results = $this->db->fetchAll($sql, $params);
        
        foreach ($results as &$result) {
            $result['completion_rate'] = $result['total_emails'] > 0 ? 
                round(($result['sent'] / $result['total_emails']) * 100, 2) : 0;
                
            $result['failure_rate'] = $result['total_emails'] > 0 ? 
                round(($result['failed'] / $result['total_emails']) * 100, 2) : 0;
        }
        
        return [
            'success' => true,
            'data' => $campaignId ? ($results[0] ?? null) : $results
        ];
    }
    
    private function getRetryStatistics() {
        $sql = "SELECT 
                    retry_count,
                    COUNT(*) as count,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as eventually_successful,
                    SUM(CASE WHEN status = 'failed_permanent' THEN 1 ELSE 0 END) as permanently_failed
                FROM email_queue 
                WHERE retry_count > 0
                GROUP BY retry_count
                ORDER BY retry_count";
        
        $retryStats = $this->db->fetchAll($sql);
        
        // Overall retry metrics
        $sql = "SELECT 
                    COUNT(*) as total_emails,
                    SUM(CASE WHEN retry_count > 0 THEN 1 ELSE 0 END) as emails_retried,
                    AVG(retry_count) as avg_retry_count,
                    MAX(retry_count) as max_retry_count
                FROM email_queue";
        
        $overallStats = $this->db->fetchOne($sql);
        
        return [
            'success' => true,
            'data' => [
                'retry_breakdown' => $retryStats,
                'overall_stats' => $overallStats
            ]
        ];
    }
    
    private function getRealTimeStatus() {
        // Get current processing status
        $sql = "SELECT 
                    COUNT(*) as currently_processing
                FROM email_queue 
                WHERE status = 'processing'";
        $processing = $this->db->fetchOne($sql);
        
        // Get next scheduled emails
        $sql = "SELECT 
                    sender_email,
                    recipient_email,
                    scheduled_at,
                    retry_count,
                    campaign_id
                FROM email_queue 
                WHERE status = 'queued' 
                AND scheduled_at <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
                ORDER BY scheduled_at ASC 
                LIMIT 10";
        $upcoming = $this->db->fetchAll($sql);
        
        // Get recent completions (last hour)
        $sql = "SELECT 
                    COUNT(*) as sent_last_hour,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_last_hour
                FROM email_queue 
                WHERE processed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                AND status IN ('sent', 'failed', 'failed_permanent')";
        $recentActivity = $this->db->fetchOne($sql);
        
        return [
            'success' => true,
            'data' => [
                'currently_processing' => $processing['currently_processing'],
                'upcoming_emails' => $upcoming,
                'recent_activity' => $recentActivity,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ];
    }
    
    private function getErrorAnalysis() {
        $sql = "SELECT 
                    COALESCE(error_message, 'Unknown error') as error_type,
                    COUNT(*) as frequency,
                    MAX(processed_at) as last_occurrence,
                    AVG(retry_count) as avg_retries_before_error
                FROM email_queue 
                WHERE status IN ('failed', 'failed_permanent')
                AND processed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY error_message
                ORDER BY frequency DESC
                LIMIT 20";
        
        $errorBreakdown = $this->db->fetchAll($sql);
        
        // Error trends by hour
        $sql = "SELECT 
                    DATE_FORMAT(processed_at, '%Y-%m-%d %H:00:00') as hour,
                    COUNT(*) as error_count
                FROM email_queue 
                WHERE status IN ('failed', 'failed_permanent')
                AND processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY DATE_FORMAT(processed_at, '%Y-%m-%d %H:00:00')
                ORDER BY hour DESC";
        
        $errorTrends = $this->db->fetchAll($sql);
        
        return [
            'success' => true,
            'data' => [
                'error_breakdown' => $errorBreakdown,
                'error_trends' => $errorTrends
            ]
        ];
    }
    
    private function getSenderHealthSummary() {
        try {
            $healthResults = $this->senderHealth->checkAllSenders();
            
            $summary = [
                'healthy' => 0,
                'warning' => 0,
                'critical' => 0,
                'suspended' => 0,
                'total' => count($healthResults)
            ];
            
            foreach ($healthResults as $result) {
                $summary[$result['status']]++;
            }
            
            return $summary;
        } catch (Exception $e) {
            return [
                'error' => 'Unable to get sender health: ' . $e->getMessage()
            ];
        }
    }
    
    private function getRecentActivity() {
        $sql = "SELECT 
                    'email_sent' as activity_type,
                    CONCAT('Email sent to ', recipient_email, ' via ', sender_email) as description,
                    processed_at as timestamp
                FROM email_queue 
                WHERE status = 'sent' 
                AND processed_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                
                UNION ALL
                
                SELECT 
                    'email_failed' as activity_type,
                    CONCAT('Email failed for ', recipient_email, ': ', COALESCE(error_message, 'Unknown error')) as description,
                    processed_at as timestamp
                FROM email_queue 
                WHERE status IN ('failed', 'failed_permanent')
                AND processed_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                
                ORDER BY timestamp DESC 
                LIMIT 20";
        
        return $this->db->fetchAll($sql);
    }
    
    private function getPerformanceMetrics() {
        $sql = "SELECT 
                    COUNT(*) as total_processed_today,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_today,
                    SUM(CASE WHEN status IN ('failed', 'failed_permanent') THEN 1 ELSE 0 END) as failed_today,
                    ROUND(AVG(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) * 100, 2) as success_rate_today
                FROM email_queue 
                WHERE DATE(processed_at) = CURDATE()";
        
        $todayStats = $this->db->fetchOne($sql);
        
        $sql = "SELECT 
                    COUNT(*) as total_processed_week,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_week,
                    ROUND(AVG(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) * 100, 2) as success_rate_week
                FROM email_queue 
                WHERE processed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        
        $weekStats = $this->db->fetchOne($sql);
        
        return [
            'today' => $todayStats,
            'week' => $weekStats
        ];
    }
    
    private function getAlertSummary() {
        $alerts = [];
        
        // Check for high failure rates
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('failed', 'failed_permanent') THEN 1 ELSE 0 END) as failed
                FROM email_queue 
                WHERE processed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        
        $recentStats = $this->db->fetchOne($sql);
        
        if ($recentStats['total'] > 0) {
            $failureRate = ($recentStats['failed'] / $recentStats['total']) * 100;
            if ($failureRate > 20) {
                $alerts[] = [
                    'type' => 'high_failure_rate',
                    'severity' => 'warning',
                    'message' => "High failure rate in last hour: {$failureRate}%"
                ];
            }
        }
        
        // Check for stuck processing emails
        $sql = "SELECT COUNT(*) as stuck_count 
                FROM email_queue 
                WHERE status = 'processing' 
                AND processed_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        
        $stuckEmails = $this->db->fetchOne($sql);
        
        if ($stuckEmails['stuck_count'] > 0) {
            $alerts[] = [
                'type' => 'stuck_emails',
                'severity' => 'critical',
                'message' => "{$stuckEmails['stuck_count']} emails stuck in processing state"
            ];
        }
        
        return $alerts;
    }
}

// Handle the request
$api = new EmailMonitoringAPI();
$response = $api->handleRequest();

echo json_encode($response, JSON_PRETTY_PRINT);
?>