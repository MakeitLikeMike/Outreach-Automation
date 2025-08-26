<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ApiIntegration.php';
require_once __DIR__ . '/ChatGPTIntegration.php';
require_once __DIR__ . '/TargetDomain.php';

class DomainQualityAnalyzerEnhanced {
    private $db;
    private $apiIntegration;
    private $chatgpt;
    private $targetDomain;
    private $settings;
    
    public function __construct() {
        $this->db = new Database();
        $this->apiIntegration = new ApiIntegration();
        $this->chatgpt = new ChatGPTIntegration();
        $this->targetDomain = new TargetDomain();
        $this->loadSettings();
    }
    
    private function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    /**
     * Comprehensive domain analysis combining technical metrics with AI insights
     */
    public function analyzeEnhanced($domain, $campaignId = null) {
        $analysisStart = microtime(true);
        
        try {
            $this->logInfo("Starting enhanced analysis for domain: {$domain}");
            
            // Phase 1: Technical Analysis (DataForSEO)
            $technicalAnalysis = $this->getTechnicalAnalysis($domain);
            
            // Phase 2: AI-Powered Qualitative Analysis
            $qualitativeAnalysis = null;
            if ($this->isAiAnalysisEnabled()) {
                try {
                    $qualitativeAnalysis = $this->getQualitativeAnalysis($domain);
                } catch (Exception $e) {
                    $this->logError("AI analysis failed for {$domain}: " . $e->getMessage());
                    // Continue without AI analysis
                }
            }
            
            // Phase 3: Combined Scoring and Recommendations
            $combinedAnalysis = $this->combineAnalyses($domain, $technicalAnalysis, $qualitativeAnalysis);
            
            // Phase 4: Store Results
            if ($campaignId) {
                $this->storeEnhancedAnalysis($domain, $campaignId, $combinedAnalysis);
            }
            
            $processingTime = round((microtime(true) - $analysisStart) * 1000);
            $combinedAnalysis['processing_time_ms'] = $processingTime;
            
            $this->logInfo("Enhanced analysis completed for {$domain} in {$processingTime}ms");
            
            return [
                'success' => true,
                'domain' => $domain,
                'analysis' => $combinedAnalysis
            ];
            
        } catch (Exception $e) {
            $this->logError("Enhanced analysis failed for {$domain}: " . $e->getMessage());
            return [
                'success' => false,
                'domain' => $domain,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get technical analysis from DataForSEO
     */
    private function getTechnicalAnalysis($domain) {
        $analysis = [
            'domain' => $domain,
            'technical_metrics' => [],
            'seo_metrics' => [],
            'quality_indicators' => []
        ];
        
        try {
            // Get domain metrics
            $domainMetrics = $this->apiIntegration->getDomainMetrics($domain);
            if ($domainMetrics) {
                $analysis['technical_metrics'] = [
                    'domain_rating' => $domainMetrics['domain_rating'] ?? 0,
                    'organic_traffic' => $domainMetrics['organic_traffic'] ?? 0,
                    'organic_keywords' => $domainMetrics['organic_keywords'] ?? 0,
                    'paid_keywords' => $domainMetrics['paid_keywords'] ?? 0,
                    'etv' => $domainMetrics['etv'] ?? 0
                ];
            }
            
            // Get backlink profile
            $backlinkProfile = $this->apiIntegration->getBacklinkProfile($domain);
            if ($backlinkProfile && !empty($backlinkProfile[0])) {
                $profile = $backlinkProfile[0];
                $analysis['seo_metrics'] = [
                    'referring_domains' => $profile['referring_domains'] ?? 0,
                    'backlinks' => $profile['backlinks_count'] ?? 0,
                    'referring_pages' => $profile['referring_pages'] ?? 0,
                    'backlink_diversity' => $this->calculateBacklinkDiversity($profile)
                ];
            }
            
            // Calculate technical quality score
            $analysis['technical_quality_score'] = $this->calculateTechnicalQualityScore($analysis);
            
        } catch (Exception $e) {
            $this->logError("Technical analysis failed for {$domain}: " . $e->getMessage());
            $analysis['technical_error'] = $e->getMessage();
        }
        
        return $analysis;
    }
    
    /**
     * Get qualitative analysis from ChatGPT
     */
    private function getQualitativeAnalysis($domain) {
        $analysis = [
            'domain' => $domain,
            'content_analysis' => [],
            'guest_post_potential' => [],
            'audience_insights' => [],
            'competitive_position' => []
        ];
        
        try {
            // Run multiple AI analyses
            $guestPostAnalysis = $this->chatgpt->analyzeGuestPostSuitability($domain);
            if ($guestPostAnalysis['success']) {
                $analysis['guest_post_potential'] = $guestPostAnalysis['structured_analysis'];
                $analysis['ai_recommendations'] = $this->extractRecommendations($guestPostAnalysis['raw_analysis']);
            }
            
            // Content quality analysis
            $contentAnalysis = $this->chatgpt->analyzeDomain($domain, 'content_quality');
            if ($contentAnalysis['success']) {
                $analysis['content_analysis'] = $contentAnalysis['structured_analysis'];
            }
            
            // Audience analysis
            $audienceAnalysis = $this->chatgpt->analyzeDomain($domain, 'audience_analysis');
            if ($audienceAnalysis['success']) {
                $analysis['audience_insights'] = $audienceAnalysis['structured_analysis'];
            }
            
            // Calculate AI quality scores
            $analysis['ai_quality_score'] = $this->calculateAiQualityScore($analysis);
            $analysis['guest_post_score'] = $this->extractNumericScore($guestPostAnalysis['raw_analysis'] ?? '');
            $analysis['content_quality_score'] = $this->extractNumericScore($contentAnalysis['raw_analysis'] ?? '');
            $analysis['audience_alignment_score'] = $this->extractNumericScore($audienceAnalysis['raw_analysis'] ?? '');
            
        } catch (Exception $e) {
            $this->logError("Qualitative analysis failed for {$domain}: " . $e->getMessage());
            $analysis['ai_error'] = $e->getMessage();
        }
        
        return $analysis;
    }
    
    /**
     * Combine technical and qualitative analyses
     */
    private function combineAnalyses($domain, $technicalAnalysis, $qualitativeAnalysis) {
        $combinedAnalysis = [
            'domain' => $domain,
            'analysis_timestamp' => date('Y-m-d H:i:s'),
            'technical_analysis' => $technicalAnalysis,
            'qualitative_analysis' => $qualitativeAnalysis,
            'combined_metrics' => [],
            'priority_assessment' => [],
            'recommendations' => []
        ];
        
        // Calculate combined scores
        $technicalScore = $technicalAnalysis['technical_quality_score'] ?? 0;
        $aiScore = $qualitativeAnalysis['ai_quality_score'] ?? 0;
        
        $combinedAnalysis['combined_metrics'] = [
            'technical_score' => $technicalScore,
            'ai_score' => $aiScore,
            'combined_score' => $this->calculateCombinedScore($technicalScore, $aiScore),
            'guest_post_score' => $qualitativeAnalysis['guest_post_score'] ?? 0,
            'content_quality_score' => $qualitativeAnalysis['content_quality_score'] ?? 0,
            'audience_alignment_score' => $qualitativeAnalysis['audience_alignment_score'] ?? 0
        ];
        
        // Priority assessment
        $combinedAnalysis['priority_assessment'] = $this->assessPriority($combinedAnalysis['combined_metrics']);
        
        // Generate recommendations
        $combinedAnalysis['recommendations'] = $this->generateRecommendations($technicalAnalysis, $qualitativeAnalysis);
        
        // Risk assessment
        $combinedAnalysis['risk_assessment'] = $this->assessRisks($technicalAnalysis, $qualitativeAnalysis);
        
        return $combinedAnalysis;
    }
    
    /**
     * Calculate technical quality score
     */
    private function calculateTechnicalQualityScore($analysis) {
        $score = 0;
        $technical = $analysis['technical_metrics'];
        $seo = $analysis['seo_metrics'];
        
        // Domain Rating (30% weight)
        $domainRating = $technical['domain_rating'] ?? 0;
        $score += min($domainRating, 100) * 0.3;
        
        // Organic Traffic (25% weight)
        $organicTraffic = $technical['organic_traffic'] ?? 0;
        if ($organicTraffic > 0) {
            $trafficScore = min(log10($organicTraffic) * 10, 25);
            $score += $trafficScore;
        }
        
        // Referring Domains (25% weight)
        $referringDomains = $seo['referring_domains'] ?? 0;
        if ($referringDomains > 0) {
            $rdScore = min(log10($referringDomains) * 12, 25);
            $score += $rdScore;
        }
        
        // Organic Keywords (20% weight)
        $organicKeywords = $technical['organic_keywords'] ?? 0;
        if ($organicKeywords > 0) {
            $keywordScore = min(log10($organicKeywords) * 8, 20);
            $score += $keywordScore;
        }
        
        return round($score, 1);
    }
    
    /**
     * Calculate AI quality score from qualitative analysis
     */
    private function calculateAiQualityScore($analysis) {
        $scores = [];
        
        if (isset($analysis['guest_post_potential']['overall_score'])) {
            $scores[] = $analysis['guest_post_potential']['overall_score'];
        }
        
        if (isset($analysis['content_analysis']['overall_score'])) {
            $scores[] = $analysis['content_analysis']['overall_score'];
        }
        
        if (isset($analysis['audience_insights']['overall_score'])) {
            $scores[] = $analysis['audience_insights']['overall_score'];
        }
        
        return !empty($scores) ? round(array_sum($scores) / count($scores), 1) : 0;
    }
    
    /**
     * Calculate combined score from technical and AI scores
     */
    private function calculateCombinedScore($technicalScore, $aiScore) {
        if ($aiScore > 0) {
            // When AI analysis is available, use weighted average (60% technical, 40% AI)
            return round(($technicalScore * 0.6) + ($aiScore * 0.4), 1);
        } else {
            // When AI analysis is not available, use technical score only
            return $technicalScore;
        }
    }
    
    /**
     * Assess priority level based on combined metrics
     */
    private function assessPriority($metrics) {
        $combinedScore = $metrics['combined_score'];
        $guestPostScore = $metrics['guest_post_score'];
        
        $priority = 'low';
        $reasoning = [];
        
        if ($combinedScore >= 75 && $guestPostScore >= 8) {
            $priority = 'high';
            $reasoning[] = 'Excellent combined score and high guest post potential';
        } elseif ($combinedScore >= 60 && $guestPostScore >= 6) {
            $priority = 'medium';
            $reasoning[] = 'Good metrics with moderate guest post potential';
        } else {
            $reasoning[] = 'Lower scores indicate limited opportunity';
        }
        
        return [
            'level' => $priority,
            'score_based_priority' => $this->getScoreBasedPriority($combinedScore),
            'reasoning' => $reasoning
        ];
    }
    
    /**
     * Generate actionable recommendations
     */
    private function generateRecommendations($technicalAnalysis, $qualitativeAnalysis) {
        $recommendations = [];
        
        // Technical recommendations
        $technical = $technicalAnalysis['technical_metrics'] ?? [];
        
        if (($technical['domain_rating'] ?? 0) < 30) {
            $recommendations[] = [
                'type' => 'technical',
                'priority' => 'medium',
                'recommendation' => 'Low domain authority - focus on building quality backlinks before outreach'
            ];
        }
        
        if (($technical['organic_traffic'] ?? 0) < 1000) {
            $recommendations[] = [
                'type' => 'technical',
                'priority' => 'low',
                'recommendation' => 'Limited organic traffic - consider if audience alignment justifies outreach'
            ];
        }
        
        // AI recommendations
        if ($qualitativeAnalysis && isset($qualitativeAnalysis['ai_recommendations'])) {
            foreach ($qualitativeAnalysis['ai_recommendations'] as $rec) {
                $recommendations[] = [
                    'type' => 'ai_generated',
                    'priority' => 'high',
                    'recommendation' => $rec
                ];
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Assess risks for outreach
     */
    private function assessRisks($technicalAnalysis, $qualitativeAnalysis) {
        $risks = [];
        
        // Technical risks
        $technical = $technicalAnalysis['technical_metrics'] ?? [];
        $seo = $technicalAnalysis['seo_metrics'] ?? [];
        
        if (($technical['domain_rating'] ?? 0) > 80 && ($seo['backlinks'] ?? 0) < 1000) {
            $risks[] = [
                'level' => 'medium',
                'type' => 'technical_mismatch',
                'description' => 'High DR but low backlink count may indicate artificial metrics'
            ];
        }
        
        if (($technical['organic_traffic'] ?? 0) > 100000 && ($technical['organic_keywords'] ?? 0) < 1000) {
            $risks[] = [
                'level' => 'medium',
                'type' => 'traffic_concentration',
                'description' => 'High traffic from few keywords indicates concentrated risk'
            ];
        }
        
        // AI-based risks
        if ($qualitativeAnalysis && isset($qualitativeAnalysis['content_analysis']['risk_factors'])) {
            foreach ($qualitativeAnalysis['content_analysis']['risk_factors'] as $risk) {
                $risks[] = [
                    'level' => 'high',
                    'type' => 'content_risk',
                    'description' => $risk
                ];
            }
        }
        
        return $risks;
    }
    
    /**
     * Store enhanced analysis results
     */
    private function storeEnhancedAnalysis($domain, $campaignId, $analysis) {
        try {
            // Update target_domains table with AI metrics
            $metrics = $analysis['combined_metrics'];
            
            $sql = "UPDATE target_domains SET 
                    ai_analysis_status = 'completed',
                    ai_overall_score = ?,
                    ai_guest_post_score = ?,
                    ai_content_quality_score = ?,
                    ai_audience_alignment_score = ?,
                    ai_priority_level = ?,
                    ai_recommendations = ?,
                    ai_last_analyzed_at = NOW()
                    WHERE domain = ? AND campaign_id = ?";
                    
            $this->db->execute($sql, [
                $metrics['combined_score'],
                $metrics['guest_post_score'],
                $metrics['content_quality_score'],
                $metrics['audience_alignment_score'],
                $analysis['priority_assessment']['level'],
                json_encode($analysis['recommendations']),
                $domain,
                $campaignId
            ]);
            
        } catch (Exception $e) {
            $this->logError("Failed to store enhanced analysis for {$domain}: " . $e->getMessage());
        }
    }
    
    /**
     * Batch analyze domains with enhanced analysis
     */
    public function batchAnalyzeEnhanced($domains, $campaignId = null) {
        $results = [];
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($domains as $domain) {
            try {
                $result = $this->analyzeEnhanced($domain, $campaignId);
                $results[$domain] = $result;
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
                
                // Rate limiting between requests
                sleep(2);
                
            } catch (Exception $e) {
                $results[$domain] = [
                    'success' => false,
                    'domain' => $domain,
                    'error' => $e->getMessage()
                ];
                $errorCount++;
            }
        }
        
        return [
            'total_processed' => count($domains),
            'successful' => $successCount,
            'failed' => $errorCount,
            'results' => $results
        ];
    }
    
    /**
     * Get enhanced analysis for domain from database
     */
    public function getStoredEnhancedAnalysis($domain, $campaignId = null) {
        $sql = "SELECT 
                    td.*,
                    c.name as campaign_name,
                    dai.raw_analysis,
                    dai.structured_data,
                    dai.created_at as analysis_date
                FROM target_domains td
                LEFT JOIN campaigns c ON td.campaign_id = c.id
                LEFT JOIN domain_ai_analysis dai ON td.domain = dai.domain
                WHERE td.domain = ?";
        
        $params = [$domain];
        
        if ($campaignId) {
            $sql .= " AND td.campaign_id = ?";
            $params[] = $campaignId;
        }
        
        return $this->db->fetchOne($sql, $params);
    }
    
    // Helper methods
    private function isAiAnalysisEnabled() {
        return ($this->settings['enable_ai_analysis'] ?? 'no') === 'yes';
    }
    
    private function calculateBacklinkDiversity($profile) {
        $backlinks = $profile['backlinks'] ?? [];
        if (empty($backlinks)) return 0;
        
        $domains = array_unique(array_column($backlinks, 'domain_from'));
        return count($domains) / max(count($backlinks), 1);
    }
    
    private function extractRecommendations($text) {
        $recommendations = [];
        $lines = explode("\n", $text);
        
        foreach ($lines as $line) {
            if (preg_match('/(?:recommend|suggest|should|consider)/i', $line)) {
                $recommendations[] = trim($line);
            }
        }
        
        return array_slice($recommendations, 0, 5); // Limit to 5 recommendations
    }
    
    private function extractNumericScore($text) {
        if (preg_match('/(?:score|rating|priority).*?(\d+)(?:\/10|\s|$)/i', $text, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }
    
    private function getScoreBasedPriority($score) {
        if ($score >= 75) return 'high';
        if ($score >= 50) return 'medium';
        return 'low';
    }
    
    private function logInfo($message) {
        error_log("[DomainQualityAnalyzerEnhanced][INFO] " . $message);
    }
    
    private function logError($message) {
        error_log("[DomainQualityAnalyzerEnhanced][ERROR] " . $message);
    }
}
?>