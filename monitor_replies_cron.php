<?php
/**
 * Reply Monitoring CRON Job
 * Run every 5 minutes to monitor email replies
 * Command: php monitor_replies_cron.php
 */

// Set execution limits for hosting compatibility
set_time_limit(240); // 4 minutes max
ini_set('memory_limit', '256M');

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

$lockFile = __DIR__ . '/reply_monitor.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime < 240) { // 4 minutes
        exit("Reply monitoring is already running\n");
    } else {
        unlink($lockFile); // Remove stale lock
    }
}

// Create lock file
file_put_contents($lockFile, getmypid());

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting reply monitoring...\n";
    
    require_once __DIR__ . '/classes/ReplyMonitor.php';
    require_once __DIR__ . '/classes/IMAPMonitor.php';
    
    // Monitor replies for all active campaigns
    $replyMonitor = new ReplyMonitor();
    $imapMonitor = new IMAPMonitor();
    
    // Check for new replies
    $newReplies = $imapMonitor->checkForNewReplies();
    echo "[" . date('Y-m-d H:i:s') . "] Found " . count($newReplies) . " new replies\n";
    
    // Process each reply
    foreach ($newReplies as $reply) {
        try {
            $result = $replyMonitor->processReply($reply);
            echo "[" . date('Y-m-d H:i:s') . "] Processed reply from {$reply['from_email']}: {$result['status']}\n";
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Error processing reply: " . $e->getMessage() . "\n";
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Reply monitoring completed\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
} finally {
    // Remove lock file
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}
?>