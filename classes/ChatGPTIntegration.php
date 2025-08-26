<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/OpenAIRateLimit.php';

class ChatGPTIntegration {
    private $db;
    private $settings;
    private $apiKey;
    private $model;
    private $baseUrl = 'https://api.openai.com/v1';
    private $rateLimit;
    
    public function __construct() {
        $this->db = new Database();
        $this->loadSettings();
        $this->apiKey = $this->settings['chatgpt_api_key'] ?? '';
        $this->model = $this->settings['chatgpt_model'] ?? 'gpt-4o-mini';
        
        // Initialize rate limiting for gpt-4o-mini (3 requests per minute)
        $this->rateLimit = new OpenAIRateLimit();
        if ($this->model === 'gpt-4o-mini') {
            $this->rateLimit->configure(3, 60, 20); // 3 requests per 60 seconds, 20s retry delay
        } else {
            $this->rateLimit->configure(10, 60, 15); // More generous limits for other models
        }
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
     * Test ChatGPT API connection
     */
    public function testConnection() {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error' => 'ChatGPT API key not configured'
            ];
        }
        
        try {
            $response = $this->makeRequest('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => 'Test connection. Reply with "OK".']
                ],
                'max_tokens' => 10
            ]);
            
            return [
                'success' => true,
                'message' => 'ChatGPT API connection successful',
                'model' => $this->model,
                'response' => $response['choices'][0]['message']['content'] ?? 'Unknown'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Analyze domain content for qualitative insights (Optimized version)
     */
    public function analyzeDomainOptimized($domain, $analysisType = 'comprehensive') {
        $startTime = microtime(true);
        $cacheHit = false;
        
        if (empty($this->apiKey)) {
            throw new Exception('ChatGPT API key not configured');
        }
        
        // Check cache first (24 hour cache)
        $cachedAnalysis = $this->getStoredAnalysis($domain, $analysisType);
        if (!empty($cachedAnalysis) && (time() - strtotime($cachedAnalysis[0]['created_at'])) < 86400) {
            $cached = json_decode($cachedAnalysis[0]['structured_data'], true);
            $cached['cached'] = true;
            $cached['cache_age'] = time() - strtotime($cachedAnalysis[0]['created_at']);
            $cacheHit = true;
            
            // Log performance for cache hit
            $this->logPerformance($domain, $analysisType, microtime(true) - $startTime, 0, true, false);
            
            return $cached;
        }
        
        // Fetch domain content with timeout optimization
        $domainContent = $this->fetchDomainContentOptimized($domain);
        
        // Generate analysis prompt based on type
        $prompt = $this->generateAnalysisPrompt($domain, $domainContent, $analysisType);
        
        try {
            $response = $this->makeRequestOptimized('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert content analyst specializing in evaluating websites for guest posting opportunities. Provide concise, actionable insights.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 1500, // Reduced for faster response
                'temperature' => 0.3
            ]);
            
            $analysis = $response['choices'][0]['message']['content'];
            $tokensUsed = $response['usage']['total_tokens'] ?? 0;
            
            // Parse and structure the analysis
            $structuredAnalysis = $this->parseAnalysis($analysis, $analysisType);
            
            // Store the analysis in database
            $this->storeAnalysis($domain, $analysisType, $structuredAnalysis, $analysis);
            
            $processingTime = microtime(true) - $startTime;
            
            // Log performance metrics
            $this->logPerformance($domain, $analysisType, $processingTime, $tokensUsed, false, false);
            
            return [
                'success' => true,
                'domain' => $domain,
                'analysis_type' => $analysisType,
                'structured_analysis' => $structuredAnalysis,
                'raw_analysis' => $analysis,
                'tokens_used' => $tokensUsed,
                'processing_time' => $processingTime
            ];
            
        } catch (Exception $e) {
            // Log failed performance
            $this->logPerformance($domain, $analysisType, microtime(true) - $startTime, 0, false, false);
            throw new Exception('ChatGPT domain analysis failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Analyze domain for guest posting suitability
     */
    public function analyzeGuestPostSuitability($domain) {
        $prompt = "Analyze this domain for guest posting opportunities in the iGaming/casino industry: {$domain}

Please analyze and provide:

1. CONTENT RELEVANCE (Score 1-10):
   - How relevant is this site to iGaming/casino content?
   - What topics do they typically cover?
   - Would they accept casino/betting guest posts?

2. CONTENT QUALITY (Score 1-10):
   - Writing quality and professionalism
   - Content depth and expertise
   - Editorial standards

3. AUDIENCE ALIGNMENT (Score 1-10):
   - Target audience demographics
   - Geographic focus (important for gambling regulations)
   - Audience engagement level

4. GUEST POST INDICATORS:
   - Evidence of accepting guest posts
   - \"Write for us\" pages or guidelines
   - Existing guest authors

5. OUTREACH APPROACH:
   - Best contact method (email, contact form, social)
   - Tone/style for outreach (formal, casual)
   - Unique value proposition angle

6. OVERALL RECOMMENDATION (Score 1-10):
   - Priority level for outreach
   - Likelihood of acceptance
   - Potential traffic/SEO value

Format as JSON with clear scores and explanations.";

        return $this->analyzeDomainOptimized($domain, 'guest_post_suitability');
    }
    
    /**
     * Analyze competitor content strategy
     */
    public function analyzeCompetitorStrategy($domain) {
        $prompt = "Analyze this competitor domain's content strategy: {$domain}

Provide insights on:

1. CONTENT PILLARS:
   - Main topics and themes
   - Content types (guides, news, reviews, etc.)
   - Publishing frequency

2. SEO STRATEGY:
   - Target keywords and niches
   - Content optimization approach
   - Link building tactics

3. AUDIENCE ENGAGEMENT:
   - Social media presence
   - Community building efforts
   - User-generated content

4. MONETIZATION APPROACH:
   - Revenue models visible
   - Affiliate partnerships
   - Advertising strategies

5. COMPETITIVE ADVANTAGES:
   - Unique selling propositions
   - Market positioning
   - Innovation areas

6. OPPORTUNITIES FOR US:
   - Content gaps we could fill
   - Collaboration possibilities
   - Learning opportunities

Provide actionable insights for our outreach strategy.";

        return $this->analyzeDomainOptimized($domain, 'competitor_strategy');
    }
    
    /**
     * Generate analysis prompt based on type
     */
    private function generateAnalysisPrompt($domain, $content, $analysisType) {
        $baseInfo = "Domain: {$domain}\n";
        if ($content) {
            $baseInfo .= "Content Sample:\n" . substr($content, 0, 2000) . "\n\n";
        }
        
        switch ($analysisType) {
            case 'guest_post_suitability':
                return $baseInfo . $this->getGuestPostPrompt();
                
            case 'competitor_strategy':
                return $baseInfo . $this->getCompetitorPrompt();
                
            case 'content_quality':
                return $baseInfo . $this->getContentQualityPrompt();
                
            case 'audience_analysis':
                return $baseInfo . $this->getAudiencePrompt();
                
            default: // comprehensive
                return $baseInfo . $this->getComprehensivePrompt();
        }
    }
    
    private function getGuestPostPrompt() {
        return "Analyze this domain for guest posting opportunities in the iGaming/casino industry. Provide:

1. Content Relevance Score (1-10)
2. Quality Assessment (1-10)
3. Audience Alignment (1-10)
4. Guest Post Evidence (Yes/No with details)
5. Outreach Approach Recommendations
6. Overall Priority Score (1-10)

Format as structured analysis with clear recommendations.";
    }
    
    private function getComprehensivePrompt() {
        return "You are an expert web quality reviewer. Analyze the following domain for outreach suitability based on these metrics. For each, provide a pass/fail or score (1-10), a short explanation, and an overall summary and recommendation. Output as structured JSON with keys for each metric, an overall summary, and a recommendation.

Metrics:
1. Contact Page: Does the site have a contact us page with details to contact the webmaster? Is the info readable?
2. Topical Relevance: Is the site's topic relevant to the money site's topic? (semantic topical alignment)
3. Guest Post Visibility: (Optional) Do guest posts appear in the main feed, not buried or hidden?
4. Content Quality: Is other content on the site high quality and not spam? (writing style, formatting, originality)
5. Anchor Quality: Are anchors to the site 'normal'? (anchor context & frequency, not spammy/keyword-stuffed)
6. Traffic Quality: Is the traffic relevant to the site? (infer topical match based on content)

Return a JSON object like:
{
  'contact_page': { 'score': 1-10, 'pass': true/false, 'explanation': '' },
  'topical_relevance': { 'score': 1-10, 'pass': true/false, 'explanation': '' },
  'guest_post_visibility': { 'score': 1-10, 'pass': true/false, 'explanation': '' },
  'content_quality': { 'score': 1-10, 'pass': true/false, 'explanation': '' },
  'anchor_quality': { 'score': 1-10, 'pass': true/false, 'explanation': '' },
  'traffic_quality': { 'score': 1-10, 'pass': true/false, 'explanation': '' },
  'summary': '',
  'recommendation': ''
}";
    }
    
    private function getContentQualityPrompt() {
        return "Analyze the content quality of this domain:

1. Writing Quality (grammar, style, professionalism)
2. Content Depth (research, expertise, value)
3. Publication Frequency
4. Content Types
5. SEO Optimization
6. User Experience

Provide scores and specific observations.";
    }
    
    private function getAudiencePrompt() {
        return "Analyze the target audience of this domain:

1. Demographics (age, gender, location)
2. Interests and behavior patterns
3. Engagement level
4. Geographic distribution
5. Regulatory considerations (for gambling content)
6. Purchasing power and intent

Provide insights for content strategy.";
    }
    
    private function getCompetitorPrompt() {
        return "Analyze this competitor's strategy:

1. Content Strategy
2. SEO Approach
3. Monetization Methods
4. Market Positioning
5. Strengths and Weaknesses
6. Opportunities for us

Provide competitive intelligence insights.";
    }
    
    /**
     * Fetch domain content for analysis
     */
    private function fetchDomainContent($domain) {
        try {
            $url = 'https://' . $domain;
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OutreachBot/1.0)',
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $content) {
                // Extract text content from HTML
                $text = strip_tags($content);
                $text = preg_replace('/\s+/', ' ', $text);
                return trim(substr($text, 0, 3000)); // Limit for API
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Failed to fetch content for {$domain}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch domain content with optimized settings
     */
    private function fetchDomainContentOptimized($domain) {
        try {
            $url = 'https://' . $domain;
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10, // Reduced timeout
                CURLOPT_CONNECTTIMEOUT => 5, // Fast connection timeout
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OutreachBot/1.0)',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_NOBODY => false,
                CURLOPT_HEADER => false,
                CURLOPT_ENCODING => 'gzip,deflate' // Accept compressed content
            ]);
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                error_log("cURL Error for {$domain}: " . $curlError);
                return null;
            }
            
            if ($httpCode === 200 && $content) {
                // Extract text content from HTML more efficiently
                $text = strip_tags($content);
                $text = preg_replace('/\s+/', ' ', $text);
                return trim(substr($text, 0, 2000)); // Reduced content size for faster processing
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Failed to fetch content for {$domain}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Parse ChatGPT analysis into structured data
     */
    private function parseAnalysis($analysis, $analysisType) {
        // Try to extract JSON if present
        if (preg_match('/\{.*\}/s', $analysis, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) {
                return $json;
            }
        }
        
        // Parse structured text analysis
        $structured = [
            'analysis_type' => $analysisType,
            'summary' => '',
            'scores' => [],
            'recommendations' => [],
            'insights' => []
        ];
        
        // Extract scores (looking for patterns like "Score: 8/10" or "Rating: 7")
        preg_match_all('/(?:Score|Rating|Priority|Overall).*?(\d+)(?:\/10|\s|$)/i', $analysis, $scoreMatches);
        if (!empty($scoreMatches[1])) {
            // Get the last score found (usually the overall score)
            $structured['overall_score'] = (int)end($scoreMatches[1]);
        }
        
        // Also look for specific score patterns like "**8/10**"
        if (preg_match('/\*\*(\d+)\/10\*\*/', $analysis, $boldMatches)) {
            $structured['overall_score'] = (int)$boldMatches[1];
        }
        
        // Extract recommendations - look for multiple recommendation patterns
        preg_match_all('/(?:Recommendation|Suggest|Should|Advice|Tip).*?(?=\n\n|\n[A-Z]|$)/is', $analysis, $recMatches);
        if (!empty($recMatches[0])) {
            foreach ($recMatches[0] as $rec) {
                $cleanRec = trim($rec);
                if (!empty($cleanRec) && strlen($cleanRec) > 10) {
                    $structured['recommendations'][] = $cleanRec;
                }
            }
        }
        
        // Store full analysis as summary if no specific structure found
        $structured['summary'] = $analysis;
        
        return $structured;
    }
    
    /**
     * Store analysis in database
     */
    private function storeAnalysis($domain, $analysisType, $structuredAnalysis, $rawAnalysis) {
        $sql = "INSERT INTO domain_ai_analysis (domain, analysis_type, structured_data, raw_analysis, created_at) 
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                structured_data = VALUES(structured_data),
                raw_analysis = VALUES(raw_analysis),
                updated_at = NOW()";
        
        $this->db->execute($sql, [
            $domain,
            $analysisType,
            json_encode($structuredAnalysis),
            $rawAnalysis
        ]);
    }
    
    /**
     * Get stored analysis for domain
     */
    public function getStoredAnalysis($domain, $analysisType = null) {
        $sql = "SELECT * FROM domain_ai_analysis WHERE domain = ?";
        $params = [$domain];
        
        if ($analysisType) {
            $sql .= " AND analysis_type = ?";
            $params[] = $analysisType;
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Batch analyze multiple domains with automatic rate limiting
     */
    public function batchAnalyze($domains, $analysisType = 'guest_post_suitability') {
        $results = [];
        
        foreach ($domains as $domain) {
            try {
                // The rate limiting is now handled automatically in makeRequestWithRateLimit
                $result = $this->analyzeDomainOptimized($domain, $analysisType);
                $results[$domain] = $result;
                
            } catch (Exception $e) {
                $results[$domain] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Batch analyze multiple domains with automatic rate limiting
     */
    public function batchAnalyzeOptimized($domains, $analysisType = 'guest_post_suitability') {
        $results = [];
        $maxBatchTime = 300; // 5 minutes max for batch processing
        $startTime = time();
        
        foreach ($domains as $domain) {
            // Check if we're approaching timeout
            if ((time() - $startTime) > $maxBatchTime) {
                $results[$domain] = [
                    'success' => false,
                    'error' => 'Batch processing timeout - please process remaining domains separately'
                ];
                continue;
            }
            
            try {
                // Check rate limits before proceeding
                if (!$this->rateLimit->canProceedNow()) {
                    $waitTime = $this->rateLimit->getSuggestedDelay();
                    if ($waitTime > 60) { // If we need to wait more than 1 minute
                        $results[$domain] = [
                            'success' => false,
                            'error' => "Rate limit exceeded. Please try this domain again in {$waitTime} seconds."
                        ];
                        continue;
                    }
                }
                
                // The rate limiting is now handled automatically in makeRequestWithRateLimit
                $result = $this->analyzeDomainOptimized($domain, $analysisType);
                $results[$domain] = $result;
                
            } catch (Exception $e) {
                $results[$domain] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Generate personalized outreach email
     */
    public function generateOutreachEmail($targetDomain, $userWebsite, $analysisData = null, $emailType = 'guest_post') {
        if (empty($this->apiKey)) {
            throw new Exception('ChatGPT API key not configured');
        }
        
        // Fetch additional context about the target domain
        $domainContent = $this->fetchDomainContentOptimized($targetDomain);
        $userSiteContent = $this->fetchDomainContentOptimized($userWebsite);
        
        // Generate email based on type
        $prompt = $this->generateOutreachPrompt($targetDomain, $userWebsite, $domainContent, $userSiteContent, $analysisData, $emailType);
        
        try {
            $response = $this->makeRequestOptimized('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert outreach specialist who creates personalized, professional guest post emails. Your emails are engaging, specific, and have high response rates. Always be professional, concise, and offer genuine value. Format your response as a complete email with subject line and body.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 2000,
                'temperature' => 0.7
            ]);
            
            $emailContent = $response['choices'][0]['message']['content'];
            
            // Parse the email into components
            $parsedEmail = $this->parseGeneratedEmail($emailContent);
            
            // Store the generated email
            $this->storeGeneratedEmail($targetDomain, $userWebsite, $emailType, $parsedEmail, $emailContent);
            
            return [
                'success' => true,
                'target_domain' => $targetDomain,
                'user_website' => $userWebsite,
                'email_type' => $emailType,
                'subject' => $parsedEmail['subject'],
                'body' => $parsedEmail['body'],
                'email' => $emailContent, // Full email content for display
                'tokens_used' => $response['usage']['total_tokens'] ?? 0,
                'personalization_notes' => $parsedEmail['notes'] ?? []
            ];
            
        } catch (Exception $e) {
            throw new Exception('ChatGPT email generation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate outreach prompt based on analysis data
     */
    private function generateOutreachPrompt($targetDomain, $userWebsite, $targetContent, $userContent, $analysisData, $emailType) {
        $basePrompt = "Create a personalized outreach email for guest posting opportunities.

TARGET DOMAIN: {$targetDomain}
MY WEBSITE: {$userWebsite}

";
        
        if ($targetContent) {
            $basePrompt .= "TARGET SITE CONTENT SAMPLE:\n" . substr($targetContent, 0, 1000) . "\n\n";
        }
        
        if ($userContent) {
            $basePrompt .= "MY WEBSITE CONTENT SAMPLE:\n" . substr($userContent, 0, 800) . "\n\n";
        }
        
        if ($analysisData && isset($analysisData['structured_analysis'])) {
            $analysis = $analysisData['structured_analysis'];
            $basePrompt .= "ANALYSIS INSIGHTS:\n";
            if (isset($analysis['summary'])) {
                $basePrompt .= "- " . $analysis['summary'] . "\n";
            }
            if (isset($analysis['recommendations'])) {
                foreach ($analysis['recommendations'] as $rec) {
                    $basePrompt .= "- " . $rec . "\n";
                }
            }
            $basePrompt .= "\n";
        }
        
        switch ($emailType) {
            case 'guest_post':
                return $basePrompt . $this->getGuestPostEmailPrompt();
            case 'collaboration':
                return $basePrompt . $this->getCollaborationEmailPrompt();
            case 'link_exchange':
                return $basePrompt . $this->getLinkExchangeEmailPrompt();
            default:
                return $basePrompt . $this->getGuestPostEmailPrompt();
        }
    }
    
    private function getGuestPostEmailPrompt() {
        return "Create a short, direct guest post outreach email for casino/iGaming content. Use this EXACT format:

To: [guess editor email based on domain]
Subject: Guest Post Collaboration Opportunity

Hi there,

I hope this email finds you well! I've been following [TARGET_DOMAIN] and really appreciate the quality content you publish, especially [mention specific content type/topic they cover].

I'm reaching out because I believe we could create some valuable content together. I work with high-quality websites in the iGaming and casino space and would love to discuss a potential guest post collaboration.

I can provide:
- Original, well-researched casino/iGaming content tailored to your audience  
- Professional writing that matches your site's tone and style
- Relevant topics that provide real value to your readers
- Proper attribution and links as needed

We're specifically looking to place casino-related content and would appreciate knowing your rates for casino guest posts.

Would you be interested in exploring this opportunity? I'd be happy to share some topic ideas or previous work samples.

Looking forward to hearing from you!

Best regards,
Outreach Team

REQUIREMENTS:
1. Keep the email SHORT (under 150 words)
2. Be DIRECT about wanting casino content placement
3. Ask for RATES specifically for casino guest posts
4. Mention specific details about THEIR site (content type, recent posts, etc.)
5. Make it clear we work with iGaming/casino websites
6. Use the exact format above with proper To:/Subject: headers
7. Replace [TARGET_DOMAIN] with the actual domain name
8. Guess a reasonable editor email like editor@domain.com or info@domain.com

CRITICAL REQUIREMENTS:
1. Generate ONLY the subject line and email body - nothing else
2. Do NOT include any personalization notes, explanations, or additional information
3. Do NOT mention any specific price ranges or dollar amounts
4. The email must be 100% ready to send as-is
5. No placeholders like [NAME] or [DOMAIN] - fill in actual details
6. No additional commentary or suggestions outside the email content

OUTPUT FORMAT (NOTHING ELSE):
Subject: [actual subject line]

[complete email body ready to send]";
    }
    
    private function getCollaborationEmailPrompt() {
        return "Create a collaboration outreach email that:

1. Subject line focused on mutual benefit
2. Acknowledges their expertise in their niche
3. Explains how our websites complement each other
4. Proposes specific collaboration ideas (joint content, webinar, resource sharing, etc.)
5. Suggests next steps
6. Professional tone with partnership focus

FORMAT THE RESPONSE AS:
Subject: [subject line here]

[email body here]

PERSONALIZATION NOTES:
- [collaboration angle used]
- [specific mutual benefits identified]
- [recommended follow-up approach]";
    }
    
    private function getLinkExchangeEmailPrompt() {
        return "Create a link exchange outreach email that:

1. Subject line about mutual linking opportunity
2. Compliments their content quality
3. Explains the mutual SEO benefit
4. Proposes specific pages for linking
5. Offers to link first as a gesture of good faith
6. Professional and direct approach

FORMAT THE RESPONSE AS:
Subject: [subject line here]

[email body here]

PERSONALIZATION NOTES:
- [link opportunity identified]
- [mutual benefit explanation]
- [suggested linking pages]";
    }
    
    /**
     * Parse generated email into components
     */
    private function parseGeneratedEmail($emailContent) {
        $parts = [
            'subject' => '',
            'body' => '',
            'notes' => []
        ];
        
        // Extract subject line
        if (preg_match('/Subject:\s*(.+?)(?:\n|$)/i', $emailContent, $matches)) {
            $parts['subject'] = trim($matches[1]);
        }
        
        // Extract email body (everything after subject line)
        $bodyPattern = '/Subject:\s*.+?\n\s*(.+?)$/si';
        if (preg_match($bodyPattern, $emailContent, $matches)) {
            $parts['body'] = trim($matches[1]);
        } else {
            // Fallback: use everything after subject line
            $lines = explode("\n", $emailContent);
            $bodyLines = [];
            $inBody = false;
            
            foreach ($lines as $line) {
                if (stripos($line, 'subject:') === 0) {
                    $inBody = true;
                    continue;
                }
                if ($inBody) {
                    $bodyLines[] = $line;
                }
            }
            
            $parts['body'] = implode("\n", $bodyLines);
        }
        
        return $parts;
    }
    
    /**
     * Store generated email for future reference
     */
    private function storeGeneratedEmail($targetDomain, $userWebsite, $emailType, $parsedEmail, $rawEmail) {
        $sql = "INSERT INTO generated_outreach_emails 
                (target_domain, user_website, email_type, subject, body, raw_email, personalization_notes, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        try {
            $this->db->execute($sql, [
                $targetDomain,
                $userWebsite,
                $emailType,
                $parsedEmail['subject'],
                $parsedEmail['body'],
                $rawEmail,
                json_encode($parsedEmail['notes'] ?? [])
            ]);
        } catch (Exception $e) {
            // Table might not exist yet, create it
            $this->createOutreachEmailsTable();
            // Try again
            $this->db->execute($sql, [
                $targetDomain,
                $userWebsite,
                $emailType,
                $parsedEmail['subject'],
                $parsedEmail['body'],
                $rawEmail,
                json_encode($parsedEmail['notes'] ?? [])
            ]);
        }
    }
    
    /**
     * Create table for storing generated emails
     */
    private function createOutreachEmailsTable() {
        $sql = "CREATE TABLE IF NOT EXISTS generated_outreach_emails (
            id INT AUTO_INCREMENT PRIMARY KEY,
            target_domain VARCHAR(255) NOT NULL,
            user_website VARCHAR(255) NOT NULL,
            email_type ENUM('guest_post', 'collaboration', 'link_exchange', 'custom') NOT NULL,
            subject VARCHAR(500) NOT NULL,
            body TEXT NOT NULL,
            raw_email TEXT NOT NULL,
            personalization_notes JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_target_domain (target_domain),
            INDEX idx_user_website (user_website),
            INDEX idx_created (created_at)
        )";
        
        $this->db->query($sql);
    }
    
    /**
     * Get analysis statistics
     */
    public function getAnalysisStats() {
        $sql = "SELECT 
                    analysis_type,
                    COUNT(*) as count,
                    DATE(created_at) as date
                FROM domain_ai_analysis 
                GROUP BY analysis_type, DATE(created_at)
                ORDER BY created_at DESC
                LIMIT 30";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Make API request to ChatGPT with rate limiting
     */
    private function makeRequest($endpoint, $data) {
        if (empty($this->apiKey)) {
            throw new Exception('ChatGPT API key not configured');
        }
        
        return $this->makeRequestWithRateLimit($endpoint, $data, 60);
    }

    /**
     * Make optimized API request to ChatGPT with rate limiting
     */
    private function makeRequestOptimized($endpoint, $data) {
        if (empty($this->apiKey)) {
            throw new Exception('ChatGPT API key not configured');
        }
        
        return $this->makeRequestWithRateLimit($endpoint, $data, 30);
    }
    
    /**
     * Make API request with automatic rate limiting and retry logic
     */
    private function makeRequestWithRateLimit($endpoint, $data, $timeout = 30) {
        $url = $this->baseUrl . '/' . $endpoint;
        $maxRetries = 2; // Maximum number of retries for rate limit errors
        $retryCount = 0;
        
        while ($retryCount <= $maxRetries) {
            try {
                // Check if we can proceed or need to wait
                if (!$this->rateLimit->canProceedNow()) {
                    $waitTime = $this->rateLimit->getSuggestedDelay();
                    
                    // If wait time is too long for a web request, return an error
                    if ($waitTime > 30) {
                        throw new Exception("Rate limit exceeded. Please try again in {$waitTime} seconds. The system is currently processing too many requests.");
                    }
                    
                    // Wait for shorter periods only
                    $canWait = $this->rateLimit->waitIfNeeded(30);
                    if (!$canWait) {
                        throw new Exception("Rate limit exceeded. Please try again later. Current wait time: {$waitTime} seconds.");
                    }
                }
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $this->apiKey,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_ENCODING => 'gzip,deflate',
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    throw new Exception('cURL Error: ' . $curlError);
                }
                
                if ($httpCode === 200) {
                    // Success! Record the request and return
                    $this->rateLimit->recordRequest();
                    
                    $responseData = json_decode($response, true);
                    if (!$responseData) {
                        throw new Exception('Invalid JSON response from ChatGPT API');
                    }
                    
                    return $responseData;
                }
                
                // Handle API errors
                $errorData = json_decode($response, true);
                $errorMsg = $errorData['error']['message'] ?? "HTTP $httpCode";
                
                // Check if this is a rate limit error
                if ($httpCode === 429 || $this->rateLimit->isRateLimitError($errorMsg)) {
                    if ($retryCount < $maxRetries) {
                        $retryCount++;
                        error_log("OpenAI rate limit hit (attempt $retryCount/$maxRetries): $errorMsg");
                        
                        // Let the rate limiter handle the wait (max 20 seconds for web requests)
                        $this->rateLimit->handleRateLimitError($errorMsg, 20);
                        continue; // Retry the request
                    }
                }
                
                throw new Exception("ChatGPT API Error: $errorMsg");
                
            } catch (Exception $e) {
                // If this is a rate limit error and we haven't exceeded retries
                if (($this->rateLimit->isRateLimitError($e->getMessage()) || strpos($e->getMessage(), '429') !== false) && $retryCount < $maxRetries) {
                    $retryCount++;
                    error_log("OpenAI rate limit exception (attempt $retryCount/$maxRetries): " . $e->getMessage());
                    
                    $this->rateLimit->handleRateLimitError($e->getMessage(), 20);
                    continue; // Retry the request
                }
                
                // Re-throw the exception if not a rate limit error or exceeded retries
                throw $e;
            }
        }
        
        throw new Exception('Maximum retry attempts exceeded for OpenAI API request');
    }
    
    /**
     * Get rate limit status for debugging
     */
    public function getRateLimitStatus() {
        return $this->rateLimit->getStatus();
    }
    
    /**
     * Generate a quick AI response for summaries and short texts
     */
    public function generateQuickResponse($prompt, $type = 'general') {
        try {
            // Use simple caching based on prompt hash
            $cacheKey = 'quick_' . md5($prompt);
            
            // Check cache first (1 hour cache for quick responses)
            $cachedAnalysis = $this->getStoredAnalysis($cacheKey, 'quick_response');
            if (!empty($cachedAnalysis) && (time() - strtotime($cachedAnalysis[0]['created_at'])) < 3600) {
                return [
                    'success' => true,
                    'response' => $cachedAnalysis[0]['raw_analysis'],
                    'cached' => true
                ];
            }
            
            // Generate response
            $response = $this->makeRequestOptimized('chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a concise AI assistant for domain analysis. Provide direct, helpful responses without unnecessary elaboration.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 150,
                'temperature' => 0.7
            ]);
            
            if (isset($response['success']) && $response['success']) {
                $aiResponse = trim($response['data']['choices'][0]['message']['content']);
                
                // Store the result in database cache
                try {
                    $sql = "INSERT INTO chatgpt_analysis_cache (domain, analysis_type, raw_analysis, created_at) VALUES (?, ?, ?, NOW())";
                    $this->db->execute($sql, [$cacheKey, 'quick_response', $aiResponse]);
                } catch (Exception $e) {
                    // Silently fail if caching fails
                    error_log("Quick response caching failed: " . $e->getMessage());
                }
                
                return [
                    'success' => true,
                    'response' => $aiResponse,
                    'cached' => false
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'Unknown API error'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Log performance metrics
     */
    private function logPerformance($domain, $analysisType, $processingTime, $tokensUsed, $cacheHit, $parallelProcessing) {
        try {
            $sql = "INSERT INTO analysis_performance_metrics 
                    (analysis_type, domain, processing_time, tokens_used, cache_hit, parallel_processing, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
            
            $this->db->execute($sql, [
                $analysisType,
                $domain,
                $processingTime,
                $tokensUsed,
                $cacheHit ? 1 : 0,
                $parallelProcessing ? 1 : 0
            ]);
        } catch (Exception $e) {
            // Silently fail if performance logging fails
            error_log("Performance logging failed: " . $e->getMessage());
        }
    }
}
?>