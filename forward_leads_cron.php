<?php
/**
 * Lead Forwarding CRON Job
 * Run every 10 minutes to forward qualified leads
 * Command: php forward_leads_cron.php
 */

// Set execution limits for hosting compatibility
set_time_limit(240); // 4 minutes max
ini_set('memory_limit', '256M');

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

$lockFile = __DIR__ . '/lead_forward.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime < 240) { // 4 minutes
        exit("Lead forwarding is already running\n");
    } else {
        unlink($lockFile); // Remove stale lock
    }
}

// Create lock file
file_put_contents($lockFile, getmypid());

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting lead forwarding...\n";
    
    require_once __DIR__ . '/classes/LeadForwarder.php';
    
    $leadForwarder = new LeadForwarder();
    
    // Forward qualified leads
    $result = $leadForwarder->forwardQualifiedLeads();
    
    echo "[" . date('Y-m-d H:i:s') . "] Forwarded {$result['forwarded']} leads\n";
    
    if (!empty($result['errors'])) {
        echo "[" . date('Y-m-d H:i:s') . "] Errors: " . implode(', ', $result['errors']) . "\n";
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Lead forwarding completed\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
} finally {
    // Remove lock file
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}
?>