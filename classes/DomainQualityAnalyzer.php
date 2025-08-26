<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ApiIntegration.php';

class DomainQualityAnalyzer {
    private $db;
    private $api;
    
    public function __construct() {
        $this->db = new Database();
        $this->api = new ApiIntegration();
    }
    
    public function analyzeDomainQuality($domain) {
        try {
            // Get comprehensive domain analysis
            $analysis = $this->api->getComprehensiveDomainAnalysis($domain);
            
            // Calculate quality metrics
            $qualityMetrics = $this->calculateQualityMetrics($analysis);
            
            // Get link building opportunities
            $linkOpportunities = $this->identifyLinkBuildingOpportunities($analysis);
            
            // Risk assessment
            $riskAssessment = $this->assessDomainRisks($analysis);
            
            return [
                'domain' => $domain,
                'analysis' => $analysis,
                'quality_metrics' => $qualityMetrics,
                'link_opportunities' => $linkOpportunities,
                'risk_assessment' => $riskAssessment,
                'recommendation' => $this->generateRecommendation($qualityMetrics, $riskAssessment),
                'analyzed_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'domain' => $domain,
                'error' => $e->getMessage(),
                'success' => false
            ];
        }
    }
    
    private function calculateQualityMetrics($analysis) {
        $metrics = [];
        
        // Calculate a realistic overall score based on available metrics
        $baseScore = $analysis['comprehensive_quality_score'] ?? 0;
        
        // If API doesn't provide a score, calculate our own
        if ($baseScore == 0) {
            $domainRating = $analysis['domain_rating'] ?? 0;
            $organicTraffic = $analysis['organic_traffic'] ?? 0;
            $referringDomains = $analysis['referring_domains'] ?? 0;
            
            // Calculate composite score
            $scoreFromDR = min($domainRating, 100) * 0.4; // 40% weight
            $scoreFromTraffic = min(log10($organicTraffic + 1) * 10, 30); // 30% weight, log scale
            $scoreFromDomains = min(log10($referringDomains + 1) * 15, 30); // 30% weight
            
            $baseScore = $scoreFromDR + $scoreFromTraffic + $scoreFromDomains;
        }
        
        $metrics['overall_score'] = round($baseScore, 1);
        
        // Authority Score (based on DR, backlinks, traffic)
        $metrics['authority_score'] = $this->calculateAuthorityScore($analysis);
        
        // Content Quality Score
        $metrics['content_score'] = $this->calculateContentScore($analysis);
        
        // Technical Score
        $metrics['technical_score'] = $this->calculateTechnicalScore($analysis);
        
        // Link Building Potential
        $metrics['link_potential'] = $analysis['link_building_potential'] ?? 0;
        
        // Competitive Strength
        $metrics['competitive_strength'] = $analysis['competitive_strength'] ?? 0;
        
        // Trust Indicators
        $metrics['trust_score'] = $this->calculateTrustScore($analysis);
        
        // Include domain rating for filtering decisions
        $metrics['domain_rating'] = $analysis['domain_rating'] ?? 0;
        
        return $metrics;
    }
    
    private function calculateAuthorityScore($analysis) {
        $score = 0;
        
        // Domain Rating (40% weight)
        $domainRating = $analysis['domain_rating'] ?? 0;
        $score += ($domainRating / 100) * 40;
        
        // Referring Domains (30% weight)
        $referringDomains = $analysis['referring_domains'] ?? 0;
        $domainsScore = min($referringDomains / 1000, 1) * 30;
        $score += $domainsScore;
        
        // Organic Traffic (30% weight)
        $organicTraffic = $analysis['organic_traffic'] ?? 0;
        if ($organicTraffic > 0) {
            $trafficScore = min(log10($organicTraffic) / 6, 1) * 30;
            $score += $trafficScore;
        }
        
        return round($score, 2);
    }
    
    private function calculateContentScore($analysis) {
        $score = 0;
        
        // Ranking Keywords
        $keywords = $analysis['ranking_keywords'] ?? 0;
        $keywordScore = min($keywords / 10000, 1) * 40;
        $score += $keywordScore;
        
        // Traffic Distribution
        $homepageTraffic = $analysis['homepage_traffic_percentage'] ?? 100;
        if ($homepageTraffic < 80) { // Good content distribution
            $score += 30;
        } elseif ($homepageTraffic < 90) {
            $score += 20;
        } else {
            $score += 10;
        }
        
        // Fresh Content (based on new backlinks)
        $newBacklinks = $analysis['new_backlinks'] ?? 0;
        $freshnessScore = min($newBacklinks / 100, 1) * 30;
        $score += $freshnessScore;
        
        return round($score, 2);
    }
    
    private function calculateTechnicalScore($analysis) {
        $score = 0;
        
        // Status Pages (50% weight)
        $statusPages = $analysis['status_pages_200'] ?? 0;
        $score += ($statusPages / 100) * 50;
        
        // Broken Links (penalty)
        $brokenBacklinks = $analysis['broken_backlinks'] ?? 0;
        $totalBacklinks = $analysis['total_backlinks'] ?? 1;
        $brokenRatio = $brokenBacklinks / $totalBacklinks;
        $brokenPenalty = min($brokenRatio * 30, 30);
        $score += (50 - $brokenPenalty);
        
        return round($score, 2);
    }
    
    private function calculateTrustScore($analysis) {
        $score = 0;
        
        // Government and Educational Backlinks
        $govBacklinks = $analysis['gov_backlinks'] ?? 0;
        $eduBacklinks = $analysis['edu_backlinks'] ?? 0;
        $authorityBacklinks = $govBacklinks + $eduBacklinks;
        $score += min($authorityBacklinks * 10, 40);
        
        // Backlink Diversity
        $referringDomains = $analysis['referring_domains'] ?? 0;
        $totalBacklinks = $analysis['total_backlinks'] ?? 1;
        $diversityRatio = $referringDomains / $totalBacklinks;
        $score += min($diversityRatio * 100, 30);
        
        // Domain Age (estimate from first_seen)
        $firstSeen = $analysis['first_seen'] ?? null;
        if ($firstSeen) {
            $ageInYears = (time() - strtotime($firstSeen)) / (365 * 24 * 3600);
            $ageScore = min($ageInYears * 5, 30);
            $score += $ageScore;
        }
        
        return round($score, 2);
    }
    
    private function identifyLinkBuildingOpportunities($analysis) {
        $opportunities = [];
        
        // High traffic, moderate backlinks
        $traffic = $analysis['organic_traffic'] ?? 0;
        $backlinks = $analysis['total_backlinks'] ?? 0;
        if ($traffic > 5000 && $backlinks < 5000) {
            $opportunities[] = [
                'type' => 'high_traffic_low_backlinks',
                'description' => 'High traffic domain with relatively few backlinks - excellent guest post opportunity',
                'priority' => 'high'
            ];
        }
        
        // Growing backlink profile
        $newBacklinks = $analysis['new_backlinks'] ?? 0;
        if ($newBacklinks > 10) {
            $opportunities[] = [
                'type' => 'growing_authority',
                'description' => 'Domain is actively gaining new backlinks - good timing for outreach',
                'priority' => 'medium'
            ];
        }
        
        // Good domain rating, moderate competition
        $domainRating = $analysis['domain_rating'] ?? 0;
        $competitors = $analysis['top_competitors'] ?? [];
        if ($domainRating > 40 && count($competitors) < 20) {
            $opportunities[] = [
                'type' => 'authority_low_competition',
                'description' => 'Authoritative domain with low competition - strategic link building target',
                'priority' => 'high'
            ];
        }
        
        // Content opportunity
        $keywords = $analysis['ranking_keywords'] ?? 0;
        if ($keywords > 1000) {
            $opportunities[] = [
                'type' => 'content_authority',
                'description' => 'Domain ranks for many keywords - good for topically relevant guest posts',
                'priority' => 'medium'
            ];
        }
        
        return $opportunities;
    }
    
    private function assessDomainRisks($analysis) {
        $risks = [];
        $riskScore = 0;
        
        // High spam score
        $spamScore = $this->calculateSpamRisk($analysis);
        if ($spamScore > 30) {
            $risks[] = [
                'type' => 'spam_risk',
                'level' => 'high',
                'description' => 'Domain may have spam-related issues based on backlink profile'
            ];
            $riskScore += 30;
        }
        
        // Declining backlinks
        $lostBacklinks = $analysis['lost_backlinks'] ?? 0;
        $newBacklinks = $analysis['new_backlinks'] ?? 0;
        if ($lostBacklinks > $newBacklinks * 2) {
            $risks[] = [
                'type' => 'declining_authority',
                'level' => 'medium',
                'description' => 'Domain is losing backlinks faster than gaining them'
            ];
            $riskScore += 20;
        }
        
        // High broken links ratio
        $brokenBacklinks = $analysis['broken_backlinks'] ?? 0;
        $totalBacklinks = $analysis['total_backlinks'] ?? 1;
        $brokenRatio = $brokenBacklinks / $totalBacklinks;
        if ($brokenRatio > 0.1) {
            $risks[] = [
                'type' => 'technical_issues',
                'level' => 'medium',
                'description' => 'High ratio of broken backlinks indicates potential technical issues'
            ];
            $riskScore += 15;
        }
        
        // Low diversity score
        $diversityScore = $analysis['backlink_diversity_score'] ?? 0;
        if ($diversityScore < 20) {
            $risks[] = [
                'type' => 'low_diversity',
                'level' => 'low',
                'description' => 'Backlink profile lacks diversity - may indicate artificial link building'
            ];
            $riskScore += 10;
        }
        
        return [
            'risks' => $risks,
            'overall_risk_score' => min($riskScore, 100),
            'risk_level' => $this->getRiskLevel($riskScore)
        ];
    }
    
    private function calculateSpamRisk($analysis) {
        $spamScore = 0;
        
        // Check for unnatural backlink patterns
        $totalBacklinks = $analysis['total_backlinks'] ?? 0;
        $referringDomains = $analysis['referring_domains'] ?? 0;
        $backlinkRatio = $referringDomains > 0 ? $totalBacklinks / $referringDomains : 0;
        
        if ($backlinkRatio > 10) { // Too many links from same domains
            $spamScore += 20;
        }
        
        // Check for rapid backlink growth
        $newBacklinks = $analysis['new_backlinks'] ?? 0;
        if ($newBacklinks > $totalBacklinks * 0.5) { // More than 50% new links
            $spamScore += 25;
        }
        
        // Low quality referring domains
        $govBacklinks = $analysis['gov_backlinks'] ?? 0;
        $eduBacklinks = $analysis['edu_backlinks'] ?? 0;
        $authorityRatio = ($govBacklinks + $eduBacklinks) / max($referringDomains, 1);
        
        if ($authorityRatio < 0.01) { // Less than 1% authority links
            $spamScore += 15;
        }
        
        return $spamScore;
    }
    
    private function getRiskLevel($riskScore) {
        if ($riskScore > 60) return 'high';
        if ($riskScore > 30) return 'medium';
        return 'low';
    }
    
    private function generateRecommendation($qualityMetrics, $riskAssessment) {
        $overallScore = $qualityMetrics['overall_score'];
        $riskLevel = $riskAssessment['risk_level'];
        $domainRating = $qualityMetrics['domain_rating'] ?? 0;
        
        // Hard filter: Reject domains with DR < 30 regardless of other metrics
        if ($domainRating < 30) {
            return [
                'status' => 'reject',
                'reason' => "Domain Rating too low ($domainRating) - minimum DR 30 required",
                'confidence' => 'high'
            ];
        }
        
        if ($riskLevel === 'high') {
            return [
                'status' => 'reject',
                'reason' => 'High risk domain - avoid outreach',
                'confidence' => 'high'
            ];
        }
        
        if ($overallScore >= 70 && $riskLevel === 'low') {
            return [
                'status' => 'highly_recommended',
                'reason' => 'High quality, low risk domain - excellent outreach target',
                'confidence' => 'high'
            ];
        }
        
        if ($overallScore >= 50 && $riskLevel === 'low') {
            return [
                'status' => 'recommended',
                'reason' => 'Good quality domain suitable for outreach',
                'confidence' => 'medium'
            ];
        }
        
        if ($overallScore >= 30) {
            return [
                'status' => 'conditional',
                'reason' => 'Moderate quality - consider based on campaign needs',
                'confidence' => 'low'
            ];
        }
        
        return [
            'status' => 'not_recommended',
            'reason' => 'Low quality domain - poor outreach target',
            'confidence' => 'high'
        ];
    }
    
    public function bulkAnalyzeQuality($domains) {
        $results = [];
        $batchSize = 10;
        $batches = array_chunk($domains, $batchSize);
        
        foreach ($batches as $batch) {
            foreach ($batch as $domain) {
                $results[] = $this->analyzeDomainQuality($domain);
                
                // Rate limiting
                usleep(500000); // 0.5 seconds between requests
            }
            
            // Longer pause between batches
            sleep(2);
        }
        
        return $results;
    }
    
    public function getQualityReport($campaignId = null) {
        $sql = "SELECT td.*, c.name as campaign_name 
                FROM target_domains td 
                LEFT JOIN campaigns c ON td.campaign_id = c.id";
        
        $params = [];
        if ($campaignId) {
            $sql .= " WHERE td.campaign_id = ?";
            $params[] = $campaignId;
        }
        
        $sql .= " ORDER BY td.quality_score DESC";
        
        $domains = $this->db->fetchAll($sql, $params);
        
        return [
            'total_domains' => count($domains),
            'high_quality' => count(array_filter($domains, fn($d) => $d['quality_score'] >= 70)),
            'medium_quality' => count(array_filter($domains, fn($d) => $d['quality_score'] >= 40 && $d['quality_score'] < 70)),
            'low_quality' => count(array_filter($domains, fn($d) => $d['quality_score'] < 40)),
            'domains' => $domains
        ];
    }
}
?>