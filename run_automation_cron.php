<?php
/**
 * Automation Cron Job Runner
 * Run this script every 5 minutes via Windows Task Scheduler or cron
 * Command: php run_automation_cron.php
 */

// Prevent script from running multiple times
$lockFile = __DIR__ . '/automation.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime < 300) { // 5 minutes
        exit("Automation is already running\n");
    } else {
        unlink($lockFile); // Remove stale lock
    }
}

// Create lock file
file_put_contents($lockFile, getmypid());

// Set execution limits
set_time_limit(600); // 10 minutes max
ini_set('memory_limit', '256M');

try {
    require_once __DIR__ . '/classes/BackgroundJobProcessor.php';
    
    echo "[" . date('Y-m-d H:i:s') . "] Starting automation cron job...\n";
    
    $processor = new BackgroundJobProcessor();
    
    // Process pending jobs for up to 5 minutes
    $startTime = time();
    $processed = 0;
    
    while (time() - $startTime < 300) { // 5 minutes
        $hasJob = $processor->processSingleJob();
        
        if (!$hasJob) {
            break; // No more jobs to process
        }
        
        $processed++;
        
        // Short pause to prevent overwhelming the system
        usleep(100000); // 0.1 seconds
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Processed $processed jobs in " . (time() - $startTime) . " seconds\n";
    
    // Check for campaigns that need to be initiated
    require_once __DIR__ . '/classes/Campaign.php';
    $campaign = new Campaign();
    $newCampaigns = $campaign->getNewAutomatedCampaigns();
    
    foreach ($newCampaigns as $camp) {
        echo "[" . date('Y-m-d H:i:s') . "] Initiating automation for campaign: {$camp['name']}\n";
        
        // Queue initial backlink fetching job
        if (!empty($camp['competitor_urls'])) {
            $processor->queueJob('fetch_backlinks', $camp['id'], null, [
                'competitor_urls' => $camp['competitor_urls']
            ], 10);
            
            echo "[" . date('Y-m-d H:i:s') . "] Queued backlink fetching for campaign {$camp['id']}\n";
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Automation cron job completed\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
} finally {
    // Remove lock file
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}
?>