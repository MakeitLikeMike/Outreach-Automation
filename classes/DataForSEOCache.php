<?php
/**
 * DataForSEO Response Caching System
 * Reduces API costs by caching responses for specified durations
 */

require_once __DIR__ . '/../config/database.php';

class DataForSEOCache {
    private $db;
    
    // Cache durations (in seconds)
    const CACHE_DURATIONS = [
        'backlinks' => 86400,           // 24 hours - backlinks change slowly
        'domain_analysis' => 43200,     // 12 hours - domain metrics update regularly  
        'competitor_analysis' => 172800, // 48 hours - competitor data is relatively stable
        'summary' => 21600,             // 6 hours - summary data updates more frequently
        'default' => 43200              // 12 hours default
    ];
    
    public function __construct() {
        $this->db = new Database();
        $this->createCacheTable();
    }
    
    /**
     * Create cache table if it doesn't exist
     */
    private function createCacheTable() {
        $sql = "CREATE TABLE IF NOT EXISTS dataforseo_cache (
            id INT PRIMARY KEY AUTO_INCREMENT,
            cache_key VARCHAR(255) UNIQUE NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            domain VARCHAR(255) NOT NULL,
            request_hash VARCHAR(64) NOT NULL,
            response_data LONGTEXT NOT NULL,
            credits_saved INT DEFAULT 1,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            accessed_count INT DEFAULT 0,
            last_accessed_at TIMESTAMP NULL,
            INDEX idx_cache_key (cache_key),
            INDEX idx_domain (domain),
            INDEX idx_expires (expires_at),
            INDEX idx_endpoint (endpoint)
        )";
        
        try {
            $this->db->execute($sql);
        } catch (Exception $e) {
            error_log("Failed to create dataforseo_cache table: " . $e->getMessage());
        }
    }
    
    /**
     * Get cached response if available and not expired
     * @param string $cacheKey
     * @return array|null
     */
    public function get($cacheKey) {
        try {
            $sql = "SELECT response_data, expires_at, id 
                    FROM dataforseo_cache 
                    WHERE cache_key = ? AND expires_at > NOW()";
            
            $result = $this->db->fetchOne($sql, [$cacheKey]);
            
            if ($result) {
                // Update access statistics
                $this->updateAccessStats($result['id']);
                
                $responseData = json_decode($result['response_data'], true);
                
                // Add cache metadata
                $responseData['_cache_info'] = [
                    'cached' => true,
                    'expires_at' => $result['expires_at'],
                    'source' => 'dataforseo_cache'
                ];
                
                return $responseData;
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("Cache get error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Store response in cache
     * @param string $cacheKey
     * @param string $endpoint
     * @param string $domain
     * @param array $requestData
     * @param array $responseData
     * @param string $operation
     * @param int $creditsSaved
     */
    public function set($cacheKey, $endpoint, $domain, $requestData, $responseData, $operation = 'default', $creditsSaved = 1) {
        try {
            $requestHash = $this->generateRequestHash($requestData);
            $cacheDuration = self::CACHE_DURATIONS[$operation] ?? self::CACHE_DURATIONS['default'];
            $expiresAt = date('Y-m-d H:i:s', time() + $cacheDuration);
            
            // Remove cache metadata before storing
            $cleanResponseData = $responseData;
            unset($cleanResponseData['_cache_info']);
            
            $sql = "INSERT INTO dataforseo_cache (
                        cache_key, 
                        endpoint, 
                        domain, 
                        request_hash, 
                        response_data, 
                        credits_saved, 
                        expires_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        response_data = VALUES(response_data),
                        expires_at = VALUES(expires_at),
                        credits_saved = VALUES(credits_saved),
                        created_at = CURRENT_TIMESTAMP";
            
            $this->db->execute($sql, [
                $cacheKey,
                $endpoint,
                $domain,
                $requestHash,
                json_encode($cleanResponseData),
                $creditsSaved,
                $expiresAt
            ]);
            
            // Clean up expired cache entries periodically
            if (rand(1, 100) === 1) { // 1% chance
                $this->cleanupExpiredCache();
            }
            
        } catch (Exception $e) {
            error_log("Cache set error: " . $e->getMessage());
        }
    }
    
    /**
     * Generate cache key for a request
     * @param string $endpoint
     * @param string $domain
     * @param array $requestData
     * @return string
     */
    public function generateCacheKey($endpoint, $domain, $requestData = []) {
        // Normalize domain
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        
        // Create a hash of the request parameters for uniqueness
        $requestHash = $this->generateRequestHash($requestData);
        
        // Extract operation type from endpoint
        $operation = $this->extractOperationFromEndpoint($endpoint);
        
        return "dataforseo_{$operation}_{$domain}_{$requestHash}";
    }
    
    /**
     * Generate hash of request data for cache uniqueness
     * @param array $requestData
     * @return string
     */
    private function generateRequestHash($requestData) {
        // Sort request data to ensure consistent hashing
        if (is_array($requestData)) {
            ksort($requestData);
            $hashData = json_encode($requestData);
        } else {
            $hashData = (string)$requestData;
        }
        
        return substr(hash('sha256', $hashData), 0, 16);
    }
    
    /**
     * Extract operation type from API endpoint
     * @param string $endpoint
     * @return string
     */
    private function extractOperationFromEndpoint($endpoint) {
        if (strpos($endpoint, 'backlinks/backlinks') !== false) {
            return 'backlinks';
        } elseif (strpos($endpoint, 'backlinks/summary') !== false) {
            return 'summary';
        } elseif (strpos($endpoint, 'backlinks/competitors') !== false) {
            return 'competitor_analysis';
        } elseif (strpos($endpoint, 'domain_analytics') !== false) {
            return 'domain_analysis';
        }
        
        return 'default';
    }
    
    /**
     * Update access statistics for cached item
     * @param int $cacheId
     */
    private function updateAccessStats($cacheId) {
        try {
            $sql = "UPDATE dataforseo_cache 
                    SET accessed_count = accessed_count + 1, 
                        last_accessed_at = NOW() 
                    WHERE id = ?";
            
            $this->db->execute($sql, [$cacheId]);
        } catch (Exception $e) {
            error_log("Failed to update cache access stats: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up expired cache entries
     */
    public function cleanupExpiredCache() {
        try {
            $sql = "DELETE FROM dataforseo_cache WHERE expires_at < NOW()";
            $deletedCount = $this->db->execute($sql);
            
            if ($deletedCount > 0) {
                error_log("Cleaned up $deletedCount expired DataForSEO cache entries");
            }
            
        } catch (Exception $e) {
            error_log("Cache cleanup error: " . $e->getMessage());
        }
    }
    
    /**
     * Get cache statistics
     * @return array
     */
    public function getCacheStatistics() {
        try {
            $stats = [];
            
            // Overall cache stats
            $overallSql = "SELECT 
                            COUNT(*) as total_entries,
                            COUNT(CASE WHEN expires_at > NOW() THEN 1 END) as active_entries,
                            COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired_entries,
                            SUM(credits_saved) as total_credits_saved,
                            SUM(accessed_count) as total_accesses
                        FROM dataforseo_cache";
            
            $overall = $this->db->fetchOne($overallSql);
            $stats['overall'] = $overall;
            
            // By endpoint stats
            $endpointSql = "SELECT 
                                endpoint,
                                COUNT(*) as entries,
                                SUM(credits_saved) as credits_saved,
                                SUM(accessed_count) as accesses,
                                AVG(accessed_count) as avg_accesses
                            FROM dataforseo_cache 
                            WHERE expires_at > NOW()
                            GROUP BY endpoint
                            ORDER BY credits_saved DESC";
            
            $stats['by_endpoint'] = $this->db->fetchAll($endpointSql);
            
            // Cache hit rate (last 24 hours)
            $hitRateSql = "SELECT 
                            SUM(accessed_count) as cache_hits
                        FROM dataforseo_cache 
                        WHERE last_accessed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            
            $hitData = $this->db->fetchOne($hitRateSql);
            $stats['cache_hits_24h'] = $hitData['cache_hits'] ?? 0;
            
            // Most accessed domains
            $topDomainsSql = "SELECT 
                                domain,
                                COUNT(*) as cache_entries,
                                SUM(accessed_count) as total_accesses,
                                SUM(credits_saved) as credits_saved
                            FROM dataforseo_cache 
                            WHERE expires_at > NOW()
                            GROUP BY domain
                            ORDER BY total_accesses DESC
                            LIMIT 10";
            
            $stats['top_domains'] = $this->db->fetchAll($topDomainsSql);
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Error getting cache statistics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clear cache for a specific domain
     * @param string $domain
     * @return int Number of entries cleared
     */
    public function clearDomainCache($domain) {
        try {
            $domain = strtolower(trim($domain));
            $domain = preg_replace('/^https?:\/\//', '', $domain);
            
            $sql = "DELETE FROM dataforseo_cache WHERE domain = ?";
            return $this->db->execute($sql, [$domain]);
            
        } catch (Exception $e) {
            error_log("Error clearing domain cache: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Clear all cache entries
     * @return int Number of entries cleared
     */
    public function clearAllCache() {
        try {
            $sql = "DELETE FROM dataforseo_cache";
            return $this->db->execute($sql);
            
        } catch (Exception $e) {
            error_log("Error clearing all cache: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get cache status for monitoring
     * @return array
     */
    public function getCacheHealth() {
        $stats = $this->getCacheStatistics();
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'recommendations' => []
        ];
        
        $totalEntries = $stats['overall']['total_entries'] ?? 0;
        $expiredEntries = $stats['overall']['expired_entries'] ?? 0;
        
        // Check for high number of expired entries
        if ($totalEntries > 0) {
            $expiredPercentage = ($expiredEntries / $totalEntries) * 100;
            
            if ($expiredPercentage > 30) {
                $health['status'] = 'warning';
                $health['issues'][] = "High percentage of expired cache entries ({$expiredPercentage}%)";
                $health['recommendations'][] = "Run cache cleanup more frequently";
            }
        }
        
        // Check cache effectiveness
        $cacheHits = $stats['cache_hits_24h'] ?? 0;
        if ($cacheHits < 10 && $totalEntries > 50) {
            $health['issues'][] = "Low cache utilization detected";
            $health['recommendations'][] = "Review cache duration settings";
        }
        
        return $health;
    }
}
?>