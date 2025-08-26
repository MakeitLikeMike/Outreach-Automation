<?php
/**
 * Single Job Processor - Designed for cron jobs on shared hosting
 * 
 * This script processes ONE job at a time and exits.
 * Perfect for shared hosting with cron jobs running every minute.
 * 
 * Usage: Add to cPanel cron jobs:
 * * * * * * /usr/bin/php /path/to/run_single_job.php
 */

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    http_response_code(403);
    die('Direct access not allowed. This script must be run via command line or cron job.');
}

// Set execution limits suitable for shared hosting
ini_set('max_execution_time', 30);  // 30 seconds max
ini_set('memory_limit', '128M');

require_once __DIR__ . '/classes/BackgroundJobProcessor.php';

try {
    $processor = new BackgroundJobProcessor();
    
    // Clean up stuck jobs first (with shorter timeout for shared hosting)
    $processor->cleanupStuckJobs(2); // 2 minute timeout instead of 5
    
    // Process exactly ONE job and exit
    $processed = $processor->processSingleJob();
    
    if ($processed) {
        echo date('Y-m-d H:i:s') . " - Job processed successfully\n";
        exit(0); // Success
    } else {
        echo date('Y-m-d H:i:s') . " - No jobs to process\n";
        exit(0); // Success (no jobs available)
    }
    
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n";
    exit(1); // Error
}
?>