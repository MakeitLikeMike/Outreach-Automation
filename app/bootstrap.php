<?php
// Memory and execution limits for shared hosting compatibility
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '30');

// Error reporting tuned for production
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Define application root
define('APP_ROOT', dirname(__DIR__));

// Simple autoload for core classes
spl_autoload_register(function ($class) {
    $classMap = [
        'CampaignService' => APP_ROOT . '/app/CampaignService.php',
        'Campaign' => APP_ROOT . '/classes/Campaign.php',
        'TargetDomain' => APP_ROOT . '/classes/TargetDomain.php',
        'ApiIntegration' => APP_ROOT . '/classes/ApiIntegration.php',
        'EmailTemplate' => APP_ROOT . '/classes/EmailTemplate.php',
        'BackgroundJobProcessor' => APP_ROOT . '/classes/BackgroundJobProcessor.php',
        'Database' => APP_ROOT . '/config/database.php'
    ];
    
    if (isset($classMap[$class])) {
        require_once $classMap[$class];
    }
});

// Legacy require_once for existing structure
require_once APP_ROOT . '/classes/Campaign.php';
require_once APP_ROOT . '/classes/TargetDomain.php';
require_once APP_ROOT . '/classes/ApiIntegration.php';
require_once APP_ROOT . '/classes/EmailTemplate.php';

// Database connection helper (lazy loading)
function getDatabase() {
    static $db;
    if (!$db) {
        require_once APP_ROOT . '/config/database.php';
        $db = new Database();
    }
    return $db;
}

// Utility functions for campaigns
function isSpamDomain($domain) {
    $spamPatterns = [
        'seo-anomaly', 'seo-analitycs', 'seo-analytics', 'seoanalytics',
        'fake-seo', 'spam-seo', 'seo-spam', 'link-farm', 'linkfarm',
        'pbn-', '-pbn', 'private-blog-network', 'automated-seo',
        'bot-seo', 'fake-traffic', 'traffic-bot', 'seo-tools',
        'tool-seo', 'bulk-seo', 'mass-seo', 'auto-seo',
        'generated-', 'dummy-', 'test-site', 'placeholder', 'sample-site'
    ];
    
    $domain = strtolower($domain);
    foreach ($spamPatterns as $pattern) {
        if (strpos($domain, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

// CSRF protection helper
function generateCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}