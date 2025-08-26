<?php
/**
 * Background Processor Runner
 * This script runs the background job processor
 * Can be called via cron or manually
 */

// Set execution time limit
set_time_limit(600); // 10 minutes max
ini_set('memory_limit', '256M');

// Include the processor
require_once 'classes/BackgroundJobProcessor.php';
require_once 'classes/PipelineStatusUpdater.php';

// Create and run processor
try {
    echo "🚀 Starting Outreach Automation Background Processor\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Memory: " . memory_get_usage(true) / 1024 / 1024 . " MB\n\n";
    
    $processor = new BackgroundJobProcessor();
    $pipelineUpdater = new PipelineStatusUpdater();
    
    // Update pipeline status before processing jobs
    echo "📊 Updating pipeline status for all campaigns...\n";
    $pipelineUpdater->updateAllCampaigns();
    
    // Handle graceful shutdown
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, function() use ($processor) {
            echo "⏹️ Received SIGTERM, shutting down gracefully...\n";
            $processor->stop();
        });
        
        pcntl_signal(SIGINT, function() use ($processor) {
            echo "⏹️ Received SIGINT, shutting down gracefully...\n";
            $processor->stop();
        });
    }
    
    $processor->processJobs();
    
    echo "\n✅ Background processor finished successfully\n";
    echo "Final Memory: " . memory_get_usage(true) / 1024 / 1024 . " MB\n";
    
} catch (Exception $e) {
    echo "\n❌ Background processor error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
?>