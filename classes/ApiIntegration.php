<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/DataForSEORateLimit.php";
require_once __DIR__ . "/DataForSEOCache.php";

class ApiIntegration {
    public $db;
    public $settings;
    public $rateLimiter;
    public $cache;

    public function __construct() {
        $this->db = new Database();
        $this->loadSettings();
        $this->rateLimiter = new DataForSEORateLimit();
        $this->cache = new DataForSEOCache();
    }
    
    public function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row["setting_key"]] = $row["setting_value"];
        }
    }

    /**
     * Get properly formatted DataForSEO authentication header
     * DataForSEO requires username:password format, not single API key
     */
    public function getDataForSEOAuthHeader() {
        $login = $this->settings['dataforseo_login'] ?? '';
        $password = $this->settings['dataforseo_password'] ?? '';
        
        if (empty($login) || empty($password)) {
            throw new Exception('DataForSEO credentials not configured. Please set dataforseo_login and dataforseo_password in system settings.');
        }
        
        $credentials = $login . ':' . $password;
        return 'Authorization: Basic ' . base64_encode($credentials);
    }
    
    /**
     * Validate domain input for security
     */
    public function validateDomain($domain) {
        // Ensure input is a string
        if (!is_string($domain)) {
            throw new InvalidArgumentException('Domain must be a string, ' . gettype($domain) . ' given');
        }
        
        $originalDomain = $domain;
        $domain = strtolower(trim($domain));
        
        // Handle cases where domain comes with additional data (metrics, status, etc.)
        // Extract only the domain part if there are spaces (indicating additional data)
        if (strpos($domain, ' ') !== false) {
            $parts = explode(' ', $domain);
            $domain = trim($parts[0]); // Take only the first part (the domain)
            
            // If the first part doesn't look like a domain, reject it
            if (empty($domain) || strpos($domain, '.') === false) {
                throw new InvalidArgumentException('Invalid domain format: unable to extract valid domain from input: ' . $originalDomain);
            }
        }
        
        // Remove protocol if present
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        
        // Remove www. prefix if present
        $domain = preg_replace('/^www\./', '', $domain);
        
        // Remove path, query parameters, and fragments - extract just the domain
        $domain = preg_replace('/\/.*$/', '', $domain);
        $domain = preg_replace('/\?.*$/', '', $domain);
        $domain = preg_replace('/#.*$/', '', $domain);
        
        // Remove trailing slash if somehow still present
        $domain = rtrim($domain, '/');
        
        // Additional cleanup: remove any non-domain characters
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain);
        
        // Basic domain validation - must have at least one dot and valid characters
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
            throw new InvalidArgumentException('Invalid domain format: ' . $domain);
        }
        
        return $domain;
    }
    
    public function fetchBacklinks($competitor_url, $limit = 1000) {
        $competitor_url = $this->validateDomain($competitor_url);
        
        // Try DataForSEO backlinks endpoint with fallback
        try {
            $endpoint = "https://api.dataforseo.com/v3/backlinks/backlinks/live";
            
            $data = [
                [
                    "target" => $competitor_url,
                    "limit" => min($limit, 100), // Limit to 100 for basic accounts
                    "mode" => "as_is"
                ]
            ];
            
            $response = $this->makeDataForSEOCall($endpoint, $data, "backlinks");
            
            if ($response && isset($response["tasks"][0]["result"])) {
                return $response["tasks"][0]["result"];
            }
        } catch (Exception $e) {
            // Log the DataForSEO error but continue with fallback
            error_log("DataForSEO fetchBacklinks failed: " . $e->getMessage());
            
            // Return empty result with explanation
            return [
                [
                    'target' => $competitor_url,
                    'backlinks' => [],
                    'backlinks_count' => 0,
                    'analysis_type' => 'fallback',
                    'timestamp' => date('Y-m-d H:i:s'),
                    'status' => 'api_unavailable',
                    'note' => 'DataForSEO Backlinks API access required - please upgrade account'
                ]
            ];
        }
        
        return [];
    }
    
    public function getBacklinkProfile($domain) {
        $domain = $this->validateDomain($domain);
        
        // Try the comprehensive backlinks summary endpoint first
        try {
            $endpoint = "https://api.dataforseo.com/v3/backlinks/summary/live";
            
            $data = [
                [
                    "target" => $domain,
                    "mode" => "as_is"
                ]
            ];
            
            $response = $this->makeDataForSEOCall($endpoint, $data, "backlinks");
            
            if ($response && isset($response["tasks"][0]["result"])) {
                $result = $response["tasks"][0]["result"];
                
                if (!empty($result) && isset($result[0])) {
                    $summary = $result[0];
                    
                    return [
                        'target' => $domain,
                        'referring_domains' => $summary['referring_domains'] ?? 0,
                        'backlinks' => $summary['backlinks'] ?? 0,
                        'referring_pages' => $summary['referring_pages'] ?? 0,
                        'referring_main_domains' => $summary['referring_main_domains'] ?? 0,
                        'referring_domains_nofollow' => $summary['referring_domains_nofollow'] ?? 0,
                        'rank' => $summary['rank'] ?? 0, // Domain authority rank
                        'broken_backlinks' => $summary['broken_backlinks'] ?? 0,
                        'broken_pages' => $summary['broken_pages'] ?? 0,
                        'analysis_type' => 'backlinks_summary',
                        'timestamp' => date('Y-m-d H:i:s'),
                        'status' => 'complete'
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("DataForSEO backlinks summary failed: " . $e->getMessage());
            
            // Try the referring domains endpoint as fallback
            try {
                return $this->getReferringDomainsOnly($domain);
            } catch (Exception $e2) {
                error_log("DataForSEO referring domains fallback failed: " . $e2->getMessage());
            }
        }
        
        return $this->getBasicBacklinkInfo($domain);
    }
    
    /**
     * Get referring domains count using dedicated endpoint
     */
    public function getReferringDomainsOnly($domain) {
        $domain = $this->validateDomain($domain);
        
        $endpoint = "https://api.dataforseo.com/v3/backlinks/referring_domains/live";
        
        $data = [
            [
                "target" => $domain,
                "mode" => "as_is",
                "limit" => 1 // We just need the count
            ]
        ];
        
        $response = $this->makeDataForSEOCall($endpoint, $data, "backlinks");
        
        if ($response && isset($response["tasks"][0]["result"])) {
            $result = $response["tasks"][0]["result"];
            
            return [
                'target' => $domain,
                'referring_domains' => $result['total_count'] ?? 0,
                'backlinks' => 0,
                'referring_pages' => 0,
                'analysis_type' => 'referring_domains_only',
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'partial'
            ];
        }
        
        throw new Exception("Failed to get referring domains data");
    }
    
    /**
     * Get additional domain metrics from DataForSEO Labs
     */
    public function getDataForSEOLabsMetrics($domain) {
        $domain = $this->validateDomain($domain);
        
        try {
            // Try domain metrics by categories endpoint for additional insights
            $endpoint = "https://api.dataforseo.com/v3/dataforseo_labs/google/domain_metrics_by_categories/live";
            
            $data = [
                [
                    "target" => $domain,
                    "language_name" => "English",
                    "location_code" => 2840, // United States
                    "include_subdomains" => true
                ]
            ];
            
            $response = $this->makeDataForSEOCall($endpoint, $data, "domain_metrics_categories");
            
            if ($response && isset($response["tasks"][0]["result"])) {
                $result = $response["tasks"][0]["result"];
                return $result;
            }
        } catch (Exception $e) {
            error_log("DataForSEO Labs metrics failed: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Fallback method for backlink information when DataForSEO is not available
     */
    public function getBasicBacklinkInfo($domain) {
        return [
            [
                'target' => $domain,
                'backlinks_count' => 0,
                'referring_domains' => 0,
                'backlinks' => [],
                'analysis_type' => 'fallback',
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'limited_analysis',
                'note' => 'DataForSEO Backlinks API not available - upgrade required for backlink data'
            ]
        ];
    }
    
    public function getCompetitorBacklinks($domain, $competitors_limit = 50) {
        $domain = $this->validateDomain($domain);
        
        // Try DataForSEO competitors endpoint with fallback
        try {
            $endpoint = "https://api.dataforseo.com/v3/backlinks/competitors/live";
            
            $data = [
                [
                    "target" => $domain,
                    "limit" => min($competitors_limit, 10), // Limit to 10 for basic accounts
                    "order_by" => ["intersections,desc"]
                ]
            ];
            
            $response = $this->makeDataForSEOCall($endpoint, $data, "competitor_analysis");
            
            if ($response && isset($response["tasks"][0]["result"])) {
                return $response["tasks"][0]["result"];
            }
        } catch (Exception $e) {
            // Log the DataForSEO error but continue with fallback
            error_log("DataForSEO getCompetitorBacklinks failed: " . $e->getMessage());
            
            // Return fallback competitor analysis
            return $this->getBasicCompetitorInfo($domain);
        }
        
        return $this->getBasicCompetitorInfo($domain);
    }
    
    /**
     * Fallback method for competitor analysis when DataForSEO is not available
     */
    public function getBasicCompetitorInfo($domain) {
        return [
            [
                'target' => $domain,
                'competitors' => [],
                'competitors_count' => 0,
                'analysis_type' => 'fallback',
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'limited_analysis',
                'note' => 'DataForSEO Competitors API not available - upgrade required for competitor analysis'
            ]
        ];
    }
    
    /**
     * Make a cache-aware DataForSEO API call with rate limiting
     */
    public function makeDataForSEOCall($endpoint, $data, $operation = "default") {
        // Generate cache key
        $domain = $this->extractDomainFromData($data);
        $cacheKey = $this->cache->generateCacheKey($endpoint, $domain, $data);
        
        // Try to get from cache first
        $cachedResponse = $this->cache->get($cacheKey);
        if ($cachedResponse !== null) {
            return $cachedResponse;
        }
        
        // Check rate limits
        $expectedCost = DataForSEORateLimit::getCostForOperation($operation);
        if (!$this->rateLimiter->canMakeRequest($endpoint, $expectedCost)) {
            $waitTime = $this->rateLimiter->getTimeUntilReset();
            throw new Exception("DataForSEO rate limit exceeded. Please wait {$waitTime} seconds before retrying.");
        }
        
        // Make the API call
        try {
            $headers = [$this->getDataForSEOAuthHeader()];
            $response = $this->makeApiCall("dataforseo", $endpoint, $data, $headers);
            
            if ($response) {
                // Record successful request
                $this->rateLimiter->recordRequest($endpoint, $expectedCost, $data, $response);
                
                // Cache the response
                $this->cache->set($cacheKey, $endpoint, $domain, $data, $response, $operation, $expectedCost);
                
                return $response;
            }
            
            return null;
            
        } catch (Exception $e) {
            // Re-throw the exception as is, don't try to handle it again
            throw $e;
        }
    }
    
    /**
     * Extract domain from API request data
     */
    public function extractDomainFromData($data) {
        if (isset($data[0]["target"])) {
            return $data[0]["target"];
        }
        
        foreach ($data as $item) {
            if (isset($item["target"])) {
                return $item["target"];
            }
            if (isset($item["domain"])) {
                return $item["domain"];
            }
        }
        
        return "unknown";
    }
    
    /**
     * Handle DataForSEO-specific API errors
     */
    public function handleDataForSEOError($response, $statusCode) {
        $responseData = is_string($response) ? json_decode($response, true) : $response;
        $statusMessage = isset($responseData['status_message']) ? $responseData['status_message'] : '';
        
        switch ($statusCode) {
            case 401:
                if (strpos($statusMessage, 'not authorized to access this resource') !== false) {
                    throw new Exception("DataForSEO account does not have access to this API endpoint. Please check your subscription plan at https://app.dataforseo.com/api-access or contact support.");
                }
                throw new Exception("DataForSEO Authentication failed. Please check your login and password in system settings.");
            case 404:
                if (strpos($statusMessage, 'Not Found') !== false) {
                    throw new Exception("DataForSEO API endpoint not found. This may indicate an account access issue or the endpoint may not be available in your subscription plan.");
                }
                throw new Exception("DataForSEO API endpoint not found: " . $statusMessage);
            case 429:
                throw new Exception("DataForSEO rate limit exceeded. Please wait before making more requests.");
            case 402:
                throw new Exception("DataForSEO quota exhausted. Please check your account balance.");
            case 400:
                $errorMsg = $statusMessage ?: 'Bad request';
                throw new Exception("DataForSEO API error: " . $errorMsg);
            default:
                if ($statusMessage) {
                    throw new Exception("DataForSEO API error (HTTP $statusCode): " . $statusMessage);
                }
                throw new Exception("DataForSEO API error: HTTP " . $statusCode);
        }
    }
    
    /**
     * Basic API call method
     */
    public function makeApiCall($service, $endpoint, $data, $headers = []) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array_merge([
                "Content-Type: application/json"
            ], $headers),
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Handle cURL errors (HTTP 0)
        if ($response === false || $httpCode === 0) {
            if (!empty($curlError)) {
                throw new Exception("cURL Error: $curlError");
            } else {
                throw new Exception("Connection failed: Unable to connect to $endpoint");
            }
        }
        
        if ($httpCode !== 200) {
            $this->handleDataForSEOError($response, $httpCode);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Get DataForSEO usage statistics
     */
    public function getDataForSEOUsageStats() {
        return $this->rateLimiter->getUsageStatistics();
    }
    
    /**
     * Get DataForSEO cache statistics
     */
    public function getDataForSEOCacheStats() {
        return $this->cache->getCacheStatistics();
    }

    /**
     * Get basic domain metrics - attempts DataForSEO first, falls back to basic analysis
     */
    public function getDomainMetrics($domain) {
        $domain = $this->validateDomain($domain);
        
        // Try DataForSEO first
        try {
            $endpoint = "https://api.dataforseo.com/v3/dataforseo_labs/google/domain_rank_overview/live";
            
            $data = [
                [
                    "target" => $domain,
                    "language_name" => "English",
                    "location_code" => 2840, // United States
                    "include_subdomains" => true
                ]
            ];
            
            $response = $this->makeDataForSEOCall($endpoint, $data, "domain_metrics");
            
            if ($response && isset($response["tasks"][0]["result"])) {
                $result = $response["tasks"][0]["result"];
                
                // Parse the actual DataForSEO response structure
                if (isset($result[0]["items"][0]["metrics"]["organic"])) {
                    $organic = $result[0]["items"][0]["metrics"]["organic"];
                    
                    return [
                        'target' => $domain,
                        'domain_rating' => min(100, max(1, intval(($organic['count'] ?? 0) / 50))), // Approximate DR from keyword count
                        'organic_traffic' => intval($organic['etv'] ?? 0),
                        'organic_keywords' => intval($organic['count'] ?? 0),
                        'estimated_traffic_cost' => floatval($organic['estimated_paid_traffic_cost'] ?? 0),
                        'analysis_type' => 'dataforseo_domain_rank_overview',
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                }
                
                return $result[0] ?? [];
            }
        } catch (Exception $e) {
            // Log the DataForSEO error but continue with fallback
            error_log("DataForSEO getDomainMetrics failed: " . $e->getMessage());
            
            // Return basic domain info as fallback
            return $this->getBasicDomainInfo($domain);
        }
        
        return $this->getBasicDomainInfo($domain);
    }
    
    /**
     * Fallback method to get basic domain information without external APIs
     */
    public function getBasicDomainInfo($domain) {
        $domain = $this->validateDomain($domain);
        
        // Basic domain analysis without external APIs
        $info = [
            'target' => $domain,
            'analysis_type' => 'basic_fallback',
            'timestamp' => date('Y-m-d H:i:s'),
            'domain_rating' => 0,
            'organic_traffic' => 0,
            'organic_keywords' => 0,
            'backlinks' => 0,
            'referring_domains' => 0,
            'status' => 'limited_analysis',
            'note' => 'DataForSEO API not available - using basic analysis'
        ];
        
        // Try to get basic domain info like checking if domain is accessible
        try {
            $url = 'https://' . $domain;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true, // HEAD request only
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OutreachBot/1.0)'
            ]);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 400 && empty($curlError)) {
                $info['domain_accessible'] = true;
                $info['http_status'] = $httpCode;
                $info['status'] = 'domain_accessible';
            } else {
                $info['domain_accessible'] = false;
                $info['http_status'] = $httpCode ?: 0;
                $info['error'] = $curlError ?: 'Domain not accessible';
            }
            
        } catch (Exception $e) {
            $info['domain_accessible'] = false;
            $info['error'] = $e->getMessage();
        }
        
        return $info;
    }

    /**
     * Get comprehensive domain analysis combining multiple DataForSEO endpoints
     */
    public function getComprehensiveDomainAnalysis($domain) {
        $domain = $this->validateDomain($domain);
        
        $analysis = [
            'domain' => $domain,
            'analysis_timestamp' => date('Y-m-d H:i:s')
        ];
        
        try {
            // Get domain metrics
            $domainMetrics = $this->getDomainMetrics($domain);
            if ($domainMetrics) {
                $analysis['domain_rating'] = $domainMetrics['domain_rating'] ?? 0;
                $analysis['organic_traffic'] = $domainMetrics['organic_traffic'] ?? 0;
                $analysis['organic_keywords'] = $domainMetrics['organic_keywords'] ?? 0;
            }
            
            // Get backlink profile using real DataForSEO data
            $backlinkProfile = $this->getBacklinkProfile($domain);
            if ($backlinkProfile) {
                $analysis['referring_domains'] = $backlinkProfile['referring_domains'] ?? 0;
                $analysis['backlinks'] = $backlinkProfile['backlinks'] ?? 0;
                $analysis['referring_pages'] = $backlinkProfile['referring_pages'] ?? 0;
                $analysis['referring_main_domains'] = $backlinkProfile['referring_main_domains'] ?? 0;
                $analysis['domain_rank'] = $backlinkProfile['rank'] ?? 0; // DataForSEO domain authority rank
                $analysis['backlink_analysis_type'] = $backlinkProfile['analysis_type'] ?? 'unknown';
            }
            
            // Calculate composite scores
            $analysis['comprehensive_quality_score'] = $this->calculateQualityScore($analysis);
            $analysis['link_building_potential'] = $this->calculateLinkBuildingPotential($analysis);
            $analysis['competitive_strength'] = $this->calculateCompetitiveStrength($analysis);
            
        } catch (Exception $e) {
            $analysis['error'] = $e->getMessage();
        }
        
        return $analysis;
    }

    /**
     * Bulk domain analysis for multiple domains
     */
    public function bulkDomainAnalysis($domains, $comprehensive = true) {
        $results = [];
        
        foreach ($domains as $domain) {
            try {
                if ($comprehensive) {
                    $results[$domain] = $this->getComprehensiveDomainAnalysis($domain);
                } else {
                    $results[$domain] = $this->getDomainMetrics($domain);
                }
                
                // Rate limiting - wait between requests
                sleep(1);
                
            } catch (Exception $e) {
                $results[$domain] = ['error' => $e->getMessage()];
            }
        }
        
        return $results;
    }
    
    /**
     * Get DataForSEO health status
     */
    public function getDataForSEOHealth() {
        $rateLimitHealth = $this->rateLimiter->getHealthStatus();
        $cacheHealth = $this->cache->getCacheHealth();
        
        return [
            "rate_limiting" => $rateLimitHealth,
            "caching" => $cacheHealth,
            "overall_status" => ($rateLimitHealth["status"] === "healthy" && $cacheHealth["status"] === "healthy") ? "healthy" : "warning"
        ];
    }
    
    /**
     * Clear DataForSEO cache for a specific domain
     */
    public function clearDataForSEOCache($domain = null) {
        if ($domain) {
            return $this->cache->clearDomainCache($domain);
        } else {
            return $this->cache->clearAllCache();
        }
    }

    /**
     * Find email using Tomba API
     */
    public function findEmail($domain) {
        $domain = $this->validateDomain($domain);
        
        $apiKey = $this->settings['tomba_api_key'] ?? '';
        $secret = $this->settings['tomba_secret'] ?? '';
        
        if (empty($apiKey) || empty($secret)) {
            throw new Exception('Tomba API credentials not configured. Please set tomba_api_key and tomba_secret in system settings.');
        }
        
        $endpoint = "https://api.tomba.io/v1/domain-search?domain=" . urlencode($domain);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'X-Tomba-Key: ' . $apiKey,
                'X-Tomba-Secret: ' . $secret,
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 429) {
            throw new Exception('Tomba API rate limit exceeded. Please wait before retrying.');
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Tomba API error: HTTP $httpCode - $response");
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['data'])) {
            return null;
        }
        
        // Look for emails in the response
        $emails = $data['data']['emails'] ?? [];
        
        if (empty($emails)) {
            return null;
        }
        
        // Return the first email found (usually the most relevant)
        return $emails[0]['email'] ?? null;
    }

    /**
     * Verify email using Tomba API
     */
    public function verifyEmail($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format: ' . $email);
        }
        
        $apiKey = $this->settings['tomba_api_key'] ?? '';
        $secret = $this->settings['tomba_secret'] ?? '';
        
        if (empty($apiKey) || empty($secret)) {
            throw new Exception('Tomba API credentials not configured. Please set tomba_api_key and tomba_secret in system settings.');
        }
        
        $endpoint = "https://api.tomba.io/v1/email-verifier/" . urlencode($email);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'X-Tomba-Key: ' . $apiKey,
                'X-Tomba-Secret: ' . $secret,
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 429) {
            throw new Exception('Tomba API rate limit exceeded. Please wait before retrying.');
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Tomba API error: HTTP $httpCode - $response");
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['data'])) {
            return null;
        }
        
        return [
            'email' => $data['data']['email'] ?? $email,
            'result' => $data['data']['result'] ?? 'unknown',
            'score' => $data['data']['score'] ?? 0,
            'disposable' => $data['data']['disposable'] ?? false,
            'webmail' => $data['data']['webmail'] ?? false
        ];
    }

    /**
     * Get Tomba API usage statistics
     */
    public function getTombaUsage() {
        $apiKey = $this->settings['tomba_api_key'] ?? '';
        $secret = $this->settings['tomba_secret'] ?? '';
        
        if (empty($apiKey) || empty($secret)) {
            throw new Exception('Tomba API credentials not configured.');
        }
        
        $endpoint = "https://api.tomba.io/v1/usage";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'X-Tomba-Key: ' . $apiKey,
                'X-Tomba-Secret: ' . $secret
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Tomba API error: HTTP $httpCode");
        }
        
        $data = json_decode($response, true);
        return $data['data'] ?? null;
    }

    /**
     * Calculate quality score based on domain metrics
     */
    public function calculateQualityScore($analysis) {
        $score = 0;
        
        // Domain Rating (0-100 scale)
        $domainRating = $analysis['domain_rating'] ?? 0;
        $score += min($domainRating, 100) * 0.4;
        
        // Organic Traffic (logarithmic scale)
        $organicTraffic = $analysis['organic_traffic'] ?? 0;
        if ($organicTraffic > 0) {
            $score += min(log10($organicTraffic) * 10, 30);
        }
        
        // Referring Domains (logarithmic scale)
        $referringDomains = $analysis['referring_domains'] ?? 0;
        if ($referringDomains > 0) {
            $score += min(log10($referringDomains) * 15, 30);
        }
        
        return round($score, 1);
    }

    /**
     * Calculate link building potential
     */
    public function calculateLinkBuildingPotential($analysis) {
        $potential = 'low';
        
        $domainRating = $analysis['domain_rating'] ?? 0;
        $referringDomains = $analysis['referring_domains'] ?? 0;
        $organicTraffic = $analysis['organic_traffic'] ?? 0;
        
        if ($domainRating >= 30 && $referringDomains >= 100 && $organicTraffic >= 1000) {
            $potential = 'high';
        } elseif ($domainRating >= 20 && $referringDomains >= 50 && $organicTraffic >= 500) {
            $potential = 'medium';
        }
        
        return $potential;
    }

    /**
     * Calculate competitive strength
     */
    public function calculateCompetitiveStrength($analysis) {
        $strength = 'weak';
        
        $domainRating = $analysis['domain_rating'] ?? 0;
        $organicKeywords = $analysis['organic_keywords'] ?? 0;
        $organicTraffic = $analysis['organic_traffic'] ?? 0;
        
        if ($domainRating >= 50 && $organicKeywords >= 10000 && $organicTraffic >= 10000) {
            $strength = 'strong';
        } elseif ($domainRating >= 30 && $organicKeywords >= 1000 && $organicTraffic >= 1000) {
            $strength = 'moderate';
        }
        
        return $strength;
    }

    /**
     * Test DataForSEO credentials and connection
     */
    public function testDataForSEOConnection() {
        try {
            // First check if credentials are configured
            $login = $this->settings['dataforseo_login'] ?? '';
            $password = $this->settings['dataforseo_password'] ?? '';
            
            if (empty($login) || empty($password)) {
                return [
                    'success' => false,
                    'error' => 'DataForSEO credentials not configured. Please set dataforseo_login and dataforseo_password in system settings.',
                    'details' => [
                        'login_configured' => !empty($login),
                        'password_configured' => !empty($password)
                    ]
                ];
            }
            
            // Test connection with a simple ping endpoint
            $endpoint = "https://api.dataforseo.com/v3/appendix/user_data";
            $data = [];
            
            $headers = [$this->getDataForSEOAuthHeader()];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => array_merge([
                    "Content-Type: application/json"
                ], $headers),
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($response === false || $httpCode === 0) {
                return [
                    'success' => false,
                    'error' => 'Connection failed: ' . ($curlError ?: 'Unable to connect to DataForSEO API'),
                    'details' => [
                        'http_code' => $httpCode,
                        'curl_error' => $curlError,
                        'endpoint' => $endpoint
                    ]
                ];
            }
            
            if ($httpCode === 401) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed: Invalid DataForSEO credentials',
                    'details' => [
                        'http_code' => $httpCode,
                        'login_used' => $login
                    ]
                ];
            }
            
            if ($httpCode !== 200) {
                $responseData = json_decode($response, true);
                $errorMessage = $responseData['status_message'] ?? "HTTP $httpCode";
                
                return [
                    'success' => false,
                    'error' => "DataForSEO API error: $errorMessage",
                    'details' => [
                        'http_code' => $httpCode,
                        'response' => $responseData
                    ]
                ];
            }
            
            $responseData = json_decode($response, true);
            
            return [
                'success' => true,
                'message' => 'DataForSEO connection successful',
                'details' => [
                    'http_code' => $httpCode,
                    'user_data' => $responseData['tasks'][0]['result'] ?? null
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => [
                    'exception_type' => get_class($e)
                ]
            ];
        }
    }
}
?>