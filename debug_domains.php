<?php
require_once 'classes/Campaign.php';
require_once 'classes/TargetDomain.php';

$campaign = new Campaign();
$targetDomain = new TargetDomain();

// Get all campaigns with their domain counts
$campaigns = $campaign->getAll();

echo "<h2>Debug: Campaign Domain Counts</h2>";

foreach ($campaigns as $camp) {
    echo "<h3>Campaign: " . htmlspecialchars($camp['name']) . " (ID: {$camp['id']})</h3>";
    echo "<p>Dashboard shows: {$camp['total_domains']} domains</p>";
    
    // Direct count from target_domains table
    $directDomains = $targetDomain->getByCampaign($camp['id']);
    echo "<p>Direct count from target_domains: " . count($directDomains) . " domains</p>";
    
    if (!empty($directDomains)) {
        echo "<ul>";
        foreach ($directDomains as $domain) {
            echo "<li>" . htmlspecialchars($domain['domain']) . " (Status: {$domain['status']})</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>No domains found in target_domains table for this campaign!</p>";
    }
    
    echo "<hr>";
}

// Check if target_domains table exists and has correct structure
echo "<h3>Target Domains Table Structure</h3>";
try {
    $db = new Database();
    $result = $db->fetchAll("DESCRIBE target_domains");
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($result as $row) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error checking table structure: " . $e->getMessage() . "</p>";
}
?>