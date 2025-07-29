<?php
require_once 'classes/ApiIntegration.php';

echo "<h2>API Debug Test</h2>";

try {
    $api = new ApiIntegration();
    
    // Test with a simple domain
    $testUrl = 'https://example.com';
    
    echo "<h3>Testing DataForSEO API with: $testUrl</h3>";
    
    // Test fetchBacklinks
    echo "<h4>1. Testing fetchBacklinks():</h4>";
    try {
        $domains = $api->fetchBacklinks($testUrl);
        echo "<p>Success! Found " . count($domains) . " domains</p>";
        if (!empty($domains)) {
            echo "<ul>";
            foreach (array_slice($domains, 0, 5) as $domain) {
                echo "<li>" . htmlspecialchars($domain) . "</li>";
            }
            echo "</ul>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error in fetchBacklinks: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Test getDomainMetrics with the first domain found
    if (!empty($domains)) {
        $testDomain = $domains[0];
        echo "<h4>2. Testing getDomainMetrics() with: $testDomain</h4>";
        try {
            $metrics = $api->getDomainMetrics($testDomain);
            echo "<p>Success! Metrics retrieved:</p>";
            echo "<pre>" . print_r($metrics, true) . "</pre>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>Error in getDomainMetrics: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        echo "<h4>3. Testing findEmail() with: $testDomain</h4>";
        try {
            $email = $api->findEmail($testDomain);
            if ($email) {
                echo "<p>Success! Found email: " . htmlspecialchars($email) . "</p>";
            } else {
                echo "<p>No email found for this domain</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>Error in findEmail: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Failed to initialize API: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Check API settings
echo "<h3>Current API Settings:</h3>";
try {
    $db = new Database();
    $settings = $db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%api%'");
    
    echo "<table border='1'>";
    echo "<tr><th>Setting</th><th>Value</th></tr>";
    foreach ($settings as $setting) {
        $value = $setting['setting_value'];
        // Hide sensitive data but show if it exists
        if (strpos($setting['setting_key'], 'key') !== false || strpos($setting['setting_key'], 'secret') !== false) {
            $value = !empty($value) ? '***SET*** (length: ' . strlen($value) . ')' : '***NOT SET***';
        }
        echo "<tr><td>" . htmlspecialchars($setting['setting_key']) . "</td><td>" . htmlspecialchars($value) . "</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error checking settings: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>