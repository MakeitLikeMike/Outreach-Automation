<?php
/**
 * Domain Utilities
 * Helper functions for domain processing and validation
 */

class DomainUtils {
    
    /**
     * Clean and extract domain from various input formats
     * Handles cases where domains come with metrics or other data appended
     */
    public static function extractDomain($input) {
        if (!is_string($input)) {
            throw new InvalidArgumentException('Domain input must be a string');
        }
        
        $domain = strtolower(trim($input));
        
        // Handle cases where domain comes with additional data (metrics, status, etc.)
        // Extract only the domain part if there are spaces (indicating additional data)
        if (strpos($domain, ' ') !== false) {
            $parts = explode(' ', $domain);
            $domain = trim($parts[0]); // Take only the first part (the domain)
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
        
        return $domain;
    }
    
    /**
     * Validate that a domain is properly formatted
     */
    public static function isValidDomain($domain) {
        $domain = self::extractDomain($domain);
        
        // Basic domain validation - must have at least one dot and valid characters
        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain);
    }
    
    /**
     * Get clean domain or throw exception if invalid
     */
    public static function validateAndCleanDomain($input) {
        $domain = self::extractDomain($input);
        
        if (!self::isValidDomain($domain)) {
            throw new InvalidArgumentException('Invalid domain format: ' . $input);
        }
        
        return $domain;
    }
    
    /**
     * Check if domain is likely spam based on patterns
     */
    public static function isSpamDomain($domain) {
        $spamPatterns = [
            'seo-anomaly',
            'seo-analitycs', 
            'seo-analytics',
            'seoanalytics',
            'fake-seo',
            'spam-seo',
            'seo-spam',
            'link-farm',
            'linkfarm',
            'pbn-',
            '-pbn',
            'private-blog-network',
            'automated-seo',
            'bot-seo',
            'fake-traffic',
            'traffic-bot',
            'seo-tools',
            'tool-seo',
            'bulk-seo',
            'mass-seo',
            'auto-seo',
            'generated-',
            'dummy-',
            'test-site',
            'placeholder',
            'sample-site'
        ];
        
        $domain = strtolower($domain);
        
        foreach ($spamPatterns as $pattern) {
            if (strpos($domain, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
}
?>