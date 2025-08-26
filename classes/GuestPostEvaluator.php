<?php
/**
 * Guest Post Evaluator - SEO Analyst for Outreach Automation
 * 
 * Evaluates target websites for guest posting suitability based on:
 * - Quantitative SEO metrics (DataForSEO API)
 * - Qualitative content analysis (website scraping)
 */

class GuestPostEvaluator {
    
    private $db;
    private $apiIntegration;
    
    // Scoring weights
    private $weights = [
        'domain_rating' => 0.15,
        'organic_traffic' => 0.15,
        'traffic_ratio' => 0.10,
        'backlink_quality' => 0.15,
        'anchor_diversity' => 0.10,
        'http_status' => 0.10,
        'keyword_relevance' => 0.10,
        'traffic_distribution' => 0.05,
        'contact_page' => 0.05,
        'topical_relevance' => 0.05
    ];
    
    public function __construct($database, $apiIntegration) {
        $this->db = $database;
        $this->apiIntegration = $apiIntegration;
    }
    
    /**
     * Main evaluation function
     */
    public function evaluateWebsite($domain, $targetNiche = null) {
        try {
            // Get DataForSEO metrics
            $seoMetrics = $this->getDataForSEOMetrics($domain);
            
            // Get website content analysis
            $contentAnalysis = $this->analyzeWebsiteContent($domain);
            
            // Perform quantitative evaluation
            $quantitativeScore = $this->evaluateQuantitativeMetrics($seoMetrics);
            
            // Perform qualitative evaluation
            $qualitativeScore = $this->evaluateQualitativeContent($contentAnalysis, $targetNiche);
            
            // Calculate final decision
            $finalResult = $this->makeFinalDecision($quantitativeScore, $qualitativeScore, $seoMetrics, $contentAnalysis);
            
            // Log the evaluation
            $this->logEvaluation($domain, $finalResult, $seoMetrics, $contentAnalysis);
            
            return $finalResult;
            
        } catch (Exception $e) {
            return [
                'decision' => 'Reject',
                'reasons' => ['Technical error: ' . $e->getMessage()],
                'score' => 0,
                'final_verdict' => '❌ Failed',
                'error' => true
            ];
        }
    }
    
    /**
     * Evaluate quantitative SEO metrics from DataForSEO
     */
    private function evaluateQuantitativeMetrics($metrics) {
        $score = 0;
        $maxScore = 8; // 8 quantitative criteria
        $reasons = [];
        
        // 1. Domain Rating (DR) must be at least 30
        if (isset($metrics['domain_rating']) && $metrics['domain_rating'] >= 30) {
            $score++;
        } else {
            $reasons[] = "Domain Rating below 30 (current: " . ($metrics['domain_rating'] ?? 'unknown') . ")";
        }
        
        // 2. Monthly Organic Traffic must be 5,000+
        $traffic = $metrics['organic_traffic'] ?? 0;
        if ($traffic >= 5000) {
            $score++;
        } else {
            $reasons[] = "Organic traffic below 5,000 (current: " . number_format($traffic) . ")";
        }
        
        // 3. DR-to-Traffic Ratio validation
        if ($this->validateDRTrafficRatio($metrics['domain_rating'] ?? 0, $traffic)) {
            $score++;
        } else {
            $reasons[] = "DR-to-Traffic ratio doesn't match expected ranges";
        }
        
        // 4. Backlink Quality Assessment
        if ($this->assessBacklinkQuality($metrics['backlinks'] ?? [])) {
            $score++;
        } else {
            $reasons[] = "Poor backlink quality detected (spam or PBN links)";
        }
        
        // 5. Anchor Text Diversity
        if ($this->assessAnchorDiversity($metrics['anchor_texts'] ?? [])) {
            $score++;
        } else {
            $reasons[] = "Poor anchor text diversity or over-optimization";
        }
        
        // 6. HTTP Status Codes
        if ($this->assessHttpStatus($metrics['http_status'] ?? [])) {
            $score++;
        } else {
            $reasons[] = "Less than 80% pages return 200 status";
        }
        
        // 7. Keyword Rankings Relevance
        if ($this->assessKeywordRelevance($metrics['keywords'] ?? [])) {
            $score++;
        } else {
            $reasons[] = "Limited relevant keyword rankings";
        }
        
        // 8. Traffic Distribution Pattern
        if ($this->assessTrafficDistribution($metrics['traffic_distribution'] ?? [])) {
            $score++;
        } else {
            $reasons[] = "Poor traffic distribution (too concentrated on single page)";
        }
        
        return [
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => ($score / $maxScore) * 100,
            'reasons' => $reasons
        ];
    }
    
    /**
     * Evaluate qualitative website content
     */
    private function evaluateQualitativeContent($content, $targetNiche) {
        $score = 0;
        $maxScore = 6; // 6 qualitative criteria
        $reasons = [];
        
        // 1. Contact Page Quality
        if ($this->hasQualityContactPage($content['contact_page'] ?? '')) {
            $score++;
        } else {
            $reasons[] = "No quality contact page or editor details found";
        }
        
        // 2. Topical Relevance
        if ($this->assessTopicalRelevance($content['content'] ?? '', $targetNiche)) {
            $score++;
        } else {
            $reasons[] = "Content not relevant to target niche";
        }
        
        // 3. Guest Post Visibility
        if ($this->assessGuestPostVisibility($content)) {
            $score++;
        } else {
            $reasons[] = "Guest posts likely to be buried or not visible";
        }
        
        // 4. Content Quality
        if ($this->assessContentQuality($content['blog_posts'] ?? [])) {
            $score++;
        } else {
            $reasons[] = "Low content quality or AI-generated content detected";
        }
        
        // 5. Natural Anchor Usage
        if ($this->assessNaturalAnchors($content)) {
            $score++;
        } else {
            $reasons[] = "Unnatural or over-optimized internal linking";
        }
        
        // 6. Traffic Quality Inference
        if ($this->inferTrafficQuality($content)) {
            $score++;
        } else {
            $reasons[] = "Content suggests low-quality or irrelevant traffic";
        }
        
        return [
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => ($score / $maxScore) * 100,
            'reasons' => $reasons
        ];
    }
    
    /**
     * Make final decision based on all criteria
     */
    private function makeFinalDecision($quantitative, $qualitative, $seoMetrics, $contentAnalysis) {
        $totalScore = $quantitative['score'] + $qualitative['score'];
        $maxScore = $quantitative['max_score'] + $qualitative['max_score'];
        $percentage = ($totalScore / $maxScore) * 100;
        
        // Decision logic
        $decision = 'Reject';
        $verdict = '❌ Failed';
        
        if ($percentage >= 70 && $quantitative['score'] >= 6 && $qualitative['score'] >= 4) {
            $decision = 'Accept';
            $verdict = '✅ Passed';
        }
        
        // Compile all reasons
        $allReasons = array_merge($quantitative['reasons'], $qualitative['reasons']);
        
        if ($decision === 'Accept') {
            $allReasons = [
                "Domain Rating: " . ($seoMetrics['domain_rating'] ?? 'N/A'),
                "Organic Traffic: " . number_format($seoMetrics['organic_traffic'] ?? 0),
                "Quality backlink profile detected",
                "Content appears human-written and relevant",
                "Good technical SEO foundation"
            ];
        }
        
        return [
            'decision' => $decision,
            'reasons' => $allReasons,
            'score' => $totalScore . '/' . $maxScore,
            'final_verdict' => $verdict,
            'percentage' => round($percentage, 1),
            'quantitative_score' => $quantitative,
            'qualitative_score' => $qualitative,
            'detailed_analysis' => [
                'seo_metrics' => $seoMetrics,
                'content_analysis' => $contentAnalysis
            ]
        ];
    }
    
    // Helper Methods for Quantitative Analysis
    
    private function validateDRTrafficRatio($dr, $traffic) {
        if ($dr >= 0 && $dr <= 30) {
            return $traffic >= 100 && $traffic <= 10000;
        } elseif ($dr >= 31 && $dr <= 60) {
            return $traffic >= 10000 && $traffic <= 100000;
        } elseif ($dr >= 61 && $dr <= 100) {
            return $traffic >= 100000;
        }
        return false;
    }
    
    private function assessBacklinkQuality($backlinks) {
        if (empty($backlinks)) return false;
        
        $qualityCount = 0;
        $total = count($backlinks);
        
        foreach ($backlinks as $backlink) {
            // Check for spam indicators
            $domain = $backlink['domain'] ?? '';
            if (!$this->isSpamDomain($domain)) {
                $qualityCount++;
            }
        }
        
        return ($qualityCount / $total) >= 0.7; // 70% quality threshold
    }
    
    private function assessAnchorDiversity($anchors) {
        if (empty($anchors)) return false;
        
        $total = array_sum($anchors);
        $maxPercentage = 0;
        
        foreach ($anchors as $anchor => $count) {
            $percentage = ($count / $total) * 100;
            if ($percentage > $maxPercentage) {
                $maxPercentage = $percentage;
            }
        }
        
        // No single anchor should be more than 30%
        return $maxPercentage <= 30;
    }
    
    private function assessHttpStatus($statusCodes) {
        if (empty($statusCodes)) return false;
        
        $total = array_sum($statusCodes);
        $success = $statusCodes['200'] ?? 0;
        
        return ($success / $total) >= 0.8; // 80% success rate
    }
    
    private function assessKeywordRelevance($keywords) {
        // This would need to be customized based on target niche
        return count($keywords) >= 50; // At least 50 ranking keywords
    }
    
    private function assessTrafficDistribution($distribution) {
        if (empty($distribution)) return false;
        
        // Check if traffic is not too concentrated on homepage
        $homepageTraffic = $distribution['homepage_percentage'] ?? 0;
        return $homepageTraffic <= 70; // Homepage shouldn't have more than 70% of traffic
    }
    
    // Helper Methods for Qualitative Analysis
    
    private function hasQualityContactPage($contactContent) {
        if (empty($contactContent)) return false;
        
        $qualityIndicators = ['editor', 'webmaster', 'contact', 'email', '@', 'team'];
        $indicatorCount = 0;
        
        foreach ($qualityIndicators as $indicator) {
            if (stripos($contactContent, $indicator) !== false) {
                $indicatorCount++;
            }
        }
        
        return $indicatorCount >= 3;
    }
    
    private function assessTopicalRelevance($content, $niche) {
        if (empty($content) || empty($niche)) return false;
        
        $niches = [
            'gambling' => ['casino', 'poker', 'betting', 'jackpot', 'slots', 'gambling'],
            'finance' => ['investment', 'trading', 'financial', 'money', 'crypto', 'stock'],
            'wellness' => ['health', 'fitness', 'nutrition', 'wellness', 'medical', 'diet'],
            'technology' => ['tech', 'software', 'digital', 'ai', 'computer', 'innovation'],
            'marketing' => ['marketing', 'seo', 'advertising', 'brand', 'social media', 'content']
        ];
        
        $keywords = $niches[strtolower($niche)] ?? [];
        if (empty($keywords)) return true; // If niche not defined, assume relevant
        
        $relevantCount = 0;
        foreach ($keywords as $keyword) {
            if (stripos($content, $keyword) !== false) {
                $relevantCount++;
            }
        }
        
        return $relevantCount >= 2; // At least 2 relevant keywords found
    }
    
    private function assessGuestPostVisibility($content) {
        // Check if site has recent blog posts and active content
        $blogIndicators = ['blog', 'news', 'articles', 'posts', 'recent'];
        $recentContent = $content['recent_posts'] ?? '';
        
        foreach ($blogIndicators as $indicator) {
            if (stripos($recentContent, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function assessContentQuality($blogPosts) {
        if (empty($blogPosts)) return false;
        
        $qualityCount = 0;
        foreach ($blogPosts as $post) {
            $content = $post['content'] ?? '';
            $wordCount = str_word_count($content);
            
            // Check for quality indicators
            if ($wordCount >= 300 && !$this->isAIGenerated($content)) {
                $qualityCount++;
            }
        }
        
        return $qualityCount >= (count($blogPosts) * 0.7); // 70% quality posts
    }
    
    private function assessNaturalAnchors($content) {
        // Look for natural vs over-optimized anchor text patterns
        $content_str = json_encode($content);
        $unnaturalPatterns = ['click here', 'read more', 'best', 'top', 'buy now'];
        
        $unnaturalCount = 0;
        foreach ($unnaturalPatterns as $pattern) {
            $unnaturalCount += substr_count(strtolower($content_str), $pattern);
        }
        
        return $unnaturalCount <= 5; // Max 5 unnatural anchors
    }
    
    private function inferTrafficQuality($content) {
        // Infer traffic quality from content engagement indicators
        $qualityIndicators = ['comment', 'share', 'like', 'subscribe', 'newsletter', 'social'];
        $indicatorCount = 0;
        
        $content_str = json_encode($content);
        foreach ($qualityIndicators as $indicator) {
            if (stripos($content_str, $indicator) !== false) {
                $indicatorCount++;
            }
        }
        
        return $indicatorCount >= 3;
    }
    
    // Utility Methods
    
    private function isSpamDomain($domain) {
        $spamIndicators = ['pbn', 'link', 'seo', 'spam', 'cheap', 'free'];
        foreach ($spamIndicators as $indicator) {
            if (stripos($domain, $indicator) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function isAIGenerated($content) {
        // Basic AI detection patterns
        $aiPatterns = [
            'in conclusion,',
            'as an ai',
            'i cannot',
            'furthermore,',
            'additionally,',
            'in summary,'
        ];
        
        $content_lower = strtolower($content);
        foreach ($aiPatterns as $pattern) {
            if (strpos($content_lower, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function getDataForSEOMetrics($domain) {
        // This would integrate with your existing DataForSEO API integration
        return $this->apiIntegration->getDomainMetrics($domain);
    }
    
    private function analyzeWebsiteContent($domain) {
        // This would integrate with website scraping functionality
        return $this->apiIntegration->scrapeWebsiteContent($domain);
    }
    
    private function logEvaluation($domain, $result, $metrics, $content) {
        // Log to database for tracking and analysis
        $sql = "INSERT INTO guest_post_evaluations 
                (domain, decision, score, reasons, metrics, content_analysis, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $this->db->query($sql, [
            $domain,
            $result['decision'],
            $result['percentage'],
            json_encode($result['reasons']),
            json_encode($metrics),
            json_encode($content)
        ]);
    }
}
?>