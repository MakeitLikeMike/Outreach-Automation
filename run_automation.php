<?php
/**
 * Outreach Automation Runner
 * Run this script to process automated campaigns
 * Can be run manually or via cron job every 15-30 minutes
 */

require_once 'classes/OutreachAutomation.php';

// Prevent script from running too long
set_time_limit(300); // 5 minutes max
ini_set('memory_limit', '512M');

// Output content type for web access
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
}

echo "=== Outreach Automation Runner ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $automation = new OutreachAutomation();
    $automation->processAutomatedCampaigns();
    
    echo "\n=== Automation completed successfully ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "\n=== Automation failed ===\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "At: " . date('Y-m-d H:i:s') . "\n";
    
    // Log the error
    error_log("Outreach Automation Error: " . $e->getMessage());
    
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
    }
    exit(1);
}

echo "\nFor more details, check the automation logs.\n";
?>