<?php
/**
 * Performance Analytics Dashboard
 * Comprehensive analytics with historical data visualization and ROI tracking
 */
require_once __DIR__ . '/../config/database.php';

class AnalyticsDashboard {
    private $db;
    private $cacheEnabled = false;
    private $cachePrefix = 'analytics_';
    private $cacheTTL = 3600; // 1 hour
    
    public function __construct() {
        $this->db = new Database();
        
        // Check if Redis/caching is available
        $this->cacheEnabled = extension_loaded('redis') || extension_loaded('memcached');
    }
    
    /**
     * Get comprehensive campaign performance analytics
     */
    public function getCampaignPerformanceAnalytics($dateRange = 30, $campaignId = null) {
        $cacheKey = $this->cachePrefix . "campaign_perf_{$dateRange}_{$campaignId}";
        
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }
        
        $whereClause = $campaignId ? "AND c.id = ?" : "";
        $params = [$dateRange];
        if ($campaignId) $params[] = $campaignId;
        
        $overview = $this->getCampaignOverview($dateRange, $campaignId);
        
        $analytics = [
            'overview' => $overview,
            'performance_trends' => $this->getPerformanceTrends($dateRange, $campaignId),
            'email_deliverability' => $this->getEmailDeliverabilityMetrics($dateRange, $campaignId),
            'domain_analysis' => $this->getDomainAnalysis($dateRange, $campaignId),
            'roi_analysis' => $this->getROIAnalysis($dateRange, $campaignId),
            'response_patterns' => $this->getResponsePatterns($dateRange, $campaignId),
            'responses' => [
                'positive' => $overview['positive_replies'] ?? 0,
                'neutral' => $overview['negative_replies'] ?? 0, // We don't have neutral, using negative for now
                'negative' => 0 // We can add this field if needed
            ],
            'campaigns' => $this->getCampaignsList($dateRange, $campaignId),
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        $this->setCache($cacheKey, $analytics, $this->cacheTTL);
        return $analytics;
    }
    
    /**
     * Get campaign overview metrics
     */
    private function getCampaignOverview($dateRange, $campaignId = null) {
        $whereClause = $campaignId ? "AND c.id = ?" : "";
        $params = [$dateRange];
        if ($campaignId) $params[] = $campaignId;
        
        $sql = "SELECT 
                    COUNT(DISTINCT c.id) as total_campaigns,
                    COUNT(DISTINCT td.id) as total_domains,
                    COUNT(oe.id) as total_emails_sent,
                    COUNT(CASE WHEN oe.status = 'sent' THEN 1 END) as successful_sends,
                    COUNT(CASE WHEN oe.status = 'failed' THEN 1 END) as failed_sends,
                    COUNT(er.id) as total_replies,
                    COUNT(CASE WHEN er.classification_category = 'positive' THEN 1 END) as positive_replies,
                    COUNT(CASE WHEN er.classification_category = 'negative' THEN 1 END) as negative_replies,
                    AVG(td.domain_rating) as avg_domain_authority,
                    ROUND(SUM(CASE WHEN er.classification_category = 'positive' THEN 1 ELSE 0 END) / COUNT(oe.id) * 100, 2) as conversion_rate
                FROM campaigns c
                LEFT JOIN target_domains td ON c.id = td.campaign_id
                LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
                LEFT JOIN email_replies er ON oe.id = er.original_email_id
                WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}";
        
        return $this->db->fetchOne($sql, $params);
    }
    
    /**
     * Get performance trends over time
     */
    private function getPerformanceTrends($dateRange, $campaignId = null) {
        $whereClause = $campaignId ? "AND c.id = ?" : "";
        $params = [$dateRange];
        if ($campaignId) $params[] = $campaignId;
        
        $sql = "SELECT 
                    DATE(oe.created_at) as date,
                    COUNT(oe.id) as emails_sent,
                    COUNT(CASE WHEN oe.status = 'sent' THEN 1 END) as successful_sends,
                    COUNT(er.id) as replies_received,
                    COUNT(CASE WHEN er.classification_category = 'positive' THEN 1 END) as positive_replies,
                    AVG(td.domain_rating) as avg_da_score,
                    COUNT(DISTINCT td.id) as domains_contacted
                FROM campaigns c
                LEFT JOIN target_domains td ON c.id = td.campaign_id  
                LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
                LEFT JOIN email_replies er ON oe.id = er.original_email_id
                WHERE oe.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}
                GROUP BY DATE(oe.created_at)
                ORDER BY date ASC";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get campaigns list with performance data
     */
    private function getCampaignsList($dateRange, $campaignId = null) {
        $whereClause = $campaignId ? "AND c.id = ?" : "";
        $params = [$dateRange];
        if ($campaignId) $params[] = $campaignId;
        
        $sql = "SELECT 
                    c.id,
                    c.name,
                    c.status,
                    COUNT(DISTINCT oe.id) as emails_sent,
                    COUNT(DISTINCT er.id) as responses,
                    ROUND(COUNT(DISTINCT er.id) / COUNT(DISTINCT oe.id) * 100, 1) as response_rate,
                    AVG(td.domain_rating) as quality_score
                FROM campaigns c
                LEFT JOIN target_domains td ON c.id = td.campaign_id
                LEFT JOIN outreach_emails oe ON td.id = oe.domain_id
                LEFT JOIN email_replies er ON oe.id = er.original_email_id
                WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}
                GROUP BY c.id, c.name, c.status
                HAVING emails_sent > 0
                ORDER BY emails_sent DESC";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get email deliverability metrics
     */
    private function getEmailDeliverabilityMetrics($dateRange, $campaignId = null) {
        $whereClause = $campaignId ? "AND c.id = ?" : "";
        $params = [$dateRange];
        if ($campaignId) $params[] = $campaignId;
        
        $sql = "SELECT 
                    eq.status,
                    COUNT(*) as count,
                    COUNT(*) * 100.0 / SUM(COUNT(*)) OVER() as percentage,
                    AVG(TIMESTAMPDIFF(SECOND, eq.created_at, eq.processed_at)) as avg_send_time
                FROM campaigns c
                JOIN target_domains td ON c.id = td.campaign_id
                JOIN email_queue eq ON td.id = eq.domain_id
                WHERE eq.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}
                GROUP BY eq.status
                ORDER BY count DESC";
        
        $deliverability = $this->db->fetchAll($sql, $params);
        
        // Get bounce analysis
        $bounceSql = "SELECT 
                        er.classification,
                        COUNT(*) as count
                      FROM campaigns c
                      JOIN target_domains td ON c.id = td.campaign_id
                      JOIN email_queue eq ON td.id = eq.domain_id
                      JOIN email_replies er ON eq.id = er.original_email_id
                      WHERE eq.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) 
                      AND er.classification IN ('bounce', 'spam') {$whereClause}
                      GROUP BY er.classification";
        
        $bounces = $this->db->fetchAll($bounceSql, $params);
        
        return [
            'delivery_status' => $deliverability,
            'bounce_analysis' => $bounces,
            'deliverability_score' => $this->calculateDeliverabilityScore($deliverability)
        ];
    }
    
    /**
     * Get domain analysis metrics
     */
    private function getDomainAnalysis($dateRange, $campaignId = null) {
        $whereClause = $campaignId ? "AND c.id = ?" : "";
        $params = [$dateRange];
        if ($campaignId) $params[] = $campaignId;
        
        $sql = "SELECT 
                    CASE 
                        WHEN td.domain_authority >= 80 THEN 'High DA (80+)'
                        WHEN td.domain_authority >= 60 THEN 'Medium DA (60-79)'
                        WHEN td.domain_authority >= 40 THEN 'Low DA (40-59)'
                        ELSE 'Very Low DA (<40)'
                    END as da_range,
                    COUNT(DISTINCT td.id) as domain_count,
                    COUNT(eq.id) as emails_sent,
                    COUNT(CASE WHEN er.classification = 'positive' THEN 1 END) as positive_responses,
                    COUNT(CASE WHEN er.classification = 'positive' THEN 1 END) * 100.0 / COUNT(eq.id) as success_rate,
                    AVG(td.domain_authority) as avg_da
                FROM campaigns c
                JOIN target_domains td ON c.id = td.campaign_id
                LEFT JOIN email_queue eq ON td.id = eq.domain_id  
                LEFT JOIN email_replies er ON eq.id = er.original_email_id
                WHERE td.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}
                GROUP BY da_range
                ORDER BY avg_da DESC";
        
        $domainMetrics = $this->db->fetchAll($sql, $params);
        
        // Get top performing domains
        $topDomainsSql = "SELECT 
                            td.domain,
                            td.domain_authority,
                            COUNT(eq.id) as emails_sent,
                            COUNT(CASE WHEN er.classification = 'positive' THEN 1 END) as positive_responses,
                            COUNT(CASE WHEN er.classification = 'positive' THEN 1 END) * 100.0 / COUNT(eq.id) as success_rate
                          FROM campaigns c
                          JOIN target_domains td ON c.id = td.campaign_id
                          LEFT JOIN email_queue eq ON td.id = eq.domain_id
                          LEFT JOIN email_replies er ON eq.id = er.original_email_id
                          WHERE td.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}
                          AND eq.id IS NOT NULL
                          GROUP BY td.id, td.domain, td.domain_authority
                          HAVING positive_responses > 0
                          ORDER BY success_rate DESC, positive_responses DESC
                          LIMIT 10";
        
        $topDomains = $this->db->fetchAll($topDomainsSql, $params);
        
        return [
            'da_distribution' => $domainMetrics,
            'top_performing_domains' => $topDomains,
            'domain_quality_correlation' => $this->calculateQualityCorrelation($domainMetrics)
        ];
    }
    
    /**
     * Calculate ROI analysis
     */
    private function getROIAnalysis($dateRange, $campaignId = null) {
        $whereClause = $campaignId ? "AND c.id = ?" : "";
        $params = [$dateRange];
        if ($campaignId) $params[] = $campaignId;
        
        // Estimate costs (API calls, time, resources)
        $costAnalysis = "SELECT 
                            COUNT(DISTINCT td.id) * 0.05 as domain_research_cost,
                            COUNT(eq.id) * 0.02 as email_sending_cost,
                            COUNT(er.id) * 0.10 as reply_processing_cost,
                            (COUNT(DISTINCT td.id) * 0.05) + (COUNT(eq.id) * 0.02) + (COUNT(er.id) * 0.10) as total_estimated_cost
                         FROM campaigns c
                         JOIN target_domains td ON c.id = td.campaign_id
                         LEFT JOIN email_queue eq ON td.id = eq.domain_id
                         LEFT JOIN email_replies er ON eq.id = er.original_email_id
                         WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}";
        
        $costs = $this->db->fetchOne($costAnalysis, $params);
        
        // Calculate value from positive responses
        $valueAnalysis = "SELECT 
                            COUNT(CASE WHEN er.classification = 'positive' THEN 1 END) as positive_leads,
                            COUNT(CASE WHEN er.classification = 'positive' THEN 1 END) * 50 as estimated_lead_value,
                            COUNT(CASE WHEN er.classification = 'interested' THEN 1 END) as interested_leads,
                            COUNT(CASE WHEN er.classification = 'interested' THEN 1 END) * 25 as estimated_interest_value
                          FROM campaigns c
                          JOIN target_domains td ON c.id = td.campaign_id
                          JOIN email_queue eq ON td.id = eq.domain_id
                          JOIN email_replies er ON eq.id = er.original_email_id
                          WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}";
        
        $value = $this->db->fetchOne($valueAnalysis, $params) ?: [
            'positive_leads' => 0,
            'estimated_lead_value' => 0,
            'interested_leads' => 0,
            'estimated_interest_value' => 0
        ];
        
        $totalValue = $value['estimated_lead_value'] + $value['estimated_interest_value'];
        $totalCost = $costs['total_estimated_cost'] ?: 1; // Avoid division by zero
        
        return [
            'cost_breakdown' => $costs,
            'value_generation' => $value,
            'roi_percentage' => (($totalValue - $totalCost) / $totalCost) * 100,
            'cost_per_lead' => $value['positive_leads'] > 0 ? $totalCost / $value['positive_leads'] : 0,
            'estimated_total_value' => $totalValue,
            'estimated_total_cost' => $totalCost
        ];
    }
    
    /**
     * Get response patterns analysis
     */
    private function getResponsePatterns($dateRange, $campaignId = null) {
        $whereClause = $campaignId ? "AND c.id = ?" : "";
        $params = [$dateRange];
        if ($campaignId) $params[] = $campaignId;
        
        // Response time analysis
        $responseTimeSql = "SELECT 
                              AVG(TIMESTAMPDIFF(HOUR, eq.processed_at, er.reply_date)) as avg_response_time_hours,
                              MIN(TIMESTAMPDIFF(HOUR, eq.processed_at, er.reply_date)) as fastest_response_hours,
                              MAX(TIMESTAMPDIFF(HOUR, eq.processed_at, er.reply_date)) as slowest_response_hours,
                              COUNT(*) as total_responses
                            FROM campaigns c
                            JOIN target_domains td ON c.id = td.campaign_id
                            JOIN email_queue eq ON td.id = eq.domain_id
                            JOIN email_replies er ON eq.id = er.original_email_id
                            WHERE er.reply_date >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}
                            AND eq.processed_at IS NOT NULL";
        
        $responseTime = $this->db->fetchOne($responseTimeSql, $params);
        
        // Response classification distribution
        $classificationSql = "SELECT 
                                er.classification,
                                COUNT(*) as count,
                                COUNT(*) * 100.0 / SUM(COUNT(*)) OVER() as percentage
                              FROM campaigns c
                              JOIN target_domains td ON c.id = td.campaign_id
                              JOIN email_queue eq ON td.id = eq.domain_id
                              JOIN email_replies er ON eq.id = er.original_email_id
                              WHERE er.reply_date >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}
                              GROUP BY er.classification
                              ORDER BY count DESC";
        
        $classification = $this->db->fetchAll($classificationSql, $params);
        
        // Day of week analysis
        $dayOfWeekSql = "SELECT 
                           DAYNAME(er.reply_date) as day_name,
                           DAYOFWEEK(er.reply_date) as day_number,
                           COUNT(*) as response_count,
                           COUNT(CASE WHEN er.classification = 'positive' THEN 1 END) as positive_count
                         FROM campaigns c
                         JOIN target_domains td ON c.id = td.campaign_id
                         JOIN email_queue eq ON td.id = eq.domain_id
                         JOIN email_replies er ON eq.id = er.original_email_id
                         WHERE er.reply_date >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}
                         GROUP BY DAYOFWEEK(er.reply_date), DAYNAME(er.reply_date)
                         ORDER BY day_number";
        
        $dayOfWeek = $this->db->fetchAll($dayOfWeekSql, $params);
        
        return [
            'response_time_metrics' => $responseTime,
            'classification_distribution' => $classification,
            'day_of_week_patterns' => $dayOfWeek,
            'response_rate' => $this->calculateResponseRate($dateRange, $campaignId)
        ];
    }
    
    /**
     * Generate weekly performance summary
     */
    public function getWeeklyPerformanceSummary($weekOffset = 0) {
        $startDate = date('Y-m-d', strtotime("-" . ($weekOffset * 7) . " days - " . date('w') . " days"));
        $endDate = date('Y-m-d', strtotime($startDate . " +6 days"));
        
        $sql = "SELECT 
                    DATE(eq.created_at) as date,
                    DAYNAME(eq.created_at) as day_name,
                    COUNT(eq.id) as emails_sent,
                    COUNT(CASE WHEN eq.status = 'sent' THEN 1 END) as successful_sends,
                    COUNT(er.id) as replies_received,
                    COUNT(CASE WHEN er.classification = 'positive' THEN 1 END) as positive_replies,
                    COUNT(CASE WHEN er.classification = 'negative' THEN 1 END) as negative_replies
                FROM email_queue eq
                LEFT JOIN email_replies er ON eq.id = er.original_email_id
                WHERE DATE(eq.created_at) BETWEEN ? AND ?
                GROUP BY DATE(eq.created_at), DAYNAME(eq.created_at)
                ORDER BY eq.created_at";
        
        $dailyStats = $this->db->fetchAll($sql, [$startDate, $endDate]);
        
        $summary = [
            'week_period' => "{$startDate} to {$endDate}",
            'daily_breakdown' => $dailyStats,
            'week_totals' => [
                'total_emails' => array_sum(array_column($dailyStats, 'emails_sent')),
                'successful_sends' => array_sum(array_column($dailyStats, 'successful_sends')),
                'total_replies' => array_sum(array_column($dailyStats, 'replies_received')),
                'positive_replies' => array_sum(array_column($dailyStats, 'positive_replies')),
                'negative_replies' => array_sum(array_column($dailyStats, 'negative_replies'))
            ]
        ];
        
        $summary['week_totals']['success_rate'] = $summary['week_totals']['total_emails'] > 0 ? 
            ($summary['week_totals']['positive_replies'] / $summary['week_totals']['total_emails']) * 100 : 0;
            
        return $summary;
    }
    
    /**
     * Export analytics data to various formats
     */
    public function exportAnalytics($format, $dateRange = 30, $campaignId = null) {
        $analytics = $this->getCampaignPerformanceAnalytics($dateRange, $campaignId);
        
        switch (strtolower($format)) {
            case 'csv':
                return $this->exportToCSV($analytics);
            case 'json':
                return json_encode($analytics, JSON_PRETTY_PRINT);
            case 'pdf':
                return $this->exportToPDF($analytics);
            default:
                throw new Exception("Unsupported export format: {$format}");
        }
    }
    
    /**
     * Helper methods
     */
    private function calculateDeliverabilityScore($deliverabilityData) {
        $totalEmails = array_sum(array_column($deliverabilityData, 'count'));
        $successfulEmails = 0;
        
        foreach ($deliverabilityData as $status) {
            if ($status['status'] === 'sent') {
                $successfulEmails = $status['count'];
                break;
            }
        }
        
        return $totalEmails > 0 ? ($successfulEmails / $totalEmails) * 100 : 0;
    }
    
    private function calculateQualityCorrelation($domainMetrics) {
        if (empty($domainMetrics)) return 0;
        
        $totalDomains = array_sum(array_column($domainMetrics, 'domain_count'));
        $weightedSuccessRate = 0;
        
        foreach ($domainMetrics as $metric) {
            $weight = $metric['domain_count'] / $totalDomains;
            $weightedSuccessRate += $metric['success_rate'] * $weight;
        }
        
        return $weightedSuccessRate;
    }
    
    private function calculateResponseRate($dateRange, $campaignId = null) {
        $whereClause = $campaignId ? "AND c.id = ?" : "";
        $params = [$dateRange];
        if ($campaignId) $params[] = $campaignId;
        
        $sql = "SELECT 
                    COUNT(DISTINCT eq.id) as total_emails_sent,
                    COUNT(DISTINCT er.id) as total_replies
                FROM campaigns c
                JOIN target_domains td ON c.id = td.campaign_id
                JOIN email_queue eq ON td.id = eq.domain_id
                LEFT JOIN email_replies er ON eq.id = er.original_email_id
                WHERE eq.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) {$whereClause}";
        
        $result = $this->db->fetchOne($sql, $params);
        
        return $result['total_emails_sent'] > 0 ? 
            ($result['total_replies'] / $result['total_emails_sent']) * 100 : 0;
    }
    
    private function exportToCSV($analytics) {
        $csv = "Campaign Performance Analytics Report\n";
        $csv .= "Generated: " . $analytics['generated_at'] . "\n\n";
        
        // Overview section
        $csv .= "OVERVIEW\n";
        $overview = $analytics['overview'];
        foreach ($overview as $key => $value) {
            $csv .= ucwords(str_replace('_', ' ', $key)) . "," . $value . "\n";
        }
        
        $csv .= "\nPERFORMANCE TRENDS\n";
        $csv .= "Date,Emails Sent,Successful Sends,Replies,Positive Replies,Avg DA Score\n";
        foreach ($analytics['performance_trends'] as $trend) {
            $csv .= implode(',', [
                $trend['date'],
                $trend['emails_sent'],
                $trend['successful_sends'],
                $trend['replies_received'],
                $trend['positive_replies'],
                round($trend['avg_da_score'], 2)
            ]) . "\n";
        }
        
        return $csv;
    }
    
    private function exportToPDF($analytics) {
        // This would integrate with a PDF library like TCPDF or DOMPDF
        // For now, return a placeholder
        return "PDF export functionality would be implemented here using TCPDF or similar library";
    }
    
    // Cache management methods
    private function getFromCache($key) {
        if (!$this->cacheEnabled) return null;
        
        // Implementation would depend on caching system (Redis/Memcached)
        return null;
    }
    
    private function setCache($key, $data, $ttl) {
        if (!$this->cacheEnabled) return false;
        
        // Implementation would depend on caching system (Redis/Memcached)
        return false;
    }
}
?>