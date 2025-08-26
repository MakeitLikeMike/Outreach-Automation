<?php
/**
 * IMAP Multi-Account Configuration Script
 * Run this after getting app passwords from all Gmail accounts
 */
require_once 'config/database.php';

echo "IMAP Multi-Account Configuration" . PHP_EOL;
echo "================================" . PHP_EOL;
echo PHP_EOL;

// ⚠️ IMPORTANT: Replace these with your actual app passwords
$imapAccounts = [
    [
        'email' => 'teamoutreach41@gmail.com',
        'app_password' => 'sklfxnbtyctkcalt',
        'enabled' => true,
        'primary' => true
    ],
    [
        'email' => 'jimmyrose1414@gmail.com', 
        'app_password' => 'REPLACE_WITH_ACTUAL_APP_PASSWORD', // 16 character app password
        'enabled' => false,
        'primary' => false
    ],
    [
        'email' => 'zackparker0905@gmail.com',
        'app_password' => 'REPLACE_WITH_ACTUAL_APP_PASSWORD', // 16 character app password
        'enabled' => false,
        'primary' => false
    ]
];

// Check if passwords have been updated
$needsUpdate = false;
foreach ($imapAccounts as $account) {
    if ($account['enabled'] && $account['app_password'] === 'REPLACE_WITH_ACTUAL_APP_PASSWORD') {
        $needsUpdate = true;
        break;
    }
}

if ($needsUpdate) {
    echo "❌ SETUP REQUIRED:" . PHP_EOL;
    echo "   Please update this script with your actual Gmail app passwords." . PHP_EOL;
    echo PHP_EOL;
    echo "   Steps:" . PHP_EOL;
    echo "   1. Get app passwords for all Gmail accounts (see IMAP_SETUP_GUIDE.md)" . PHP_EOL;
    echo "   2. Replace 'REPLACE_WITH_ACTUAL_APP_PASSWORD' with real passwords" . PHP_EOL;
    echo "   3. Run this script again" . PHP_EOL;
    echo PHP_EOL;
    echo "   App passwords look like: 'abcd efgh ijkl mnop' (16 characters)" . PHP_EOL;
    exit(1);
}

try {
    $db = new Database();
    
    // Configure each IMAP account
    foreach ($imapAccounts as $index => $account) {
        echo "Configuring account " . ($index + 1) . ": " . $account['email'] . PHP_EOL;
        
        // Store individual account settings
        $prefix = 'imap_account_' . ($index + 1);
        
        $settings = [
            $prefix . '_email' => $account['email'],
            $prefix . '_password' => $account['app_password'],
            $prefix . '_host' => 'imap.gmail.com',
            $prefix . '_port' => '993',
            $prefix . '_ssl' => '1',
            $prefix . '_enabled' => $account['enabled'] ? '1' : '0',
            $prefix . '_primary' => $account['primary'] ? '1' : '0'
        ];
        
        foreach ($settings as $key => $value) {
            $db->execute(
                'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', 
                [$key, $value]
            );
        }
        
        echo "  ✅ Configured: " . $account['email'] . PHP_EOL;
    }
    
    // Global IMAP settings
    $globalSettings = [
        'imap_monitoring_enabled' => '1',
        'imap_check_interval_minutes' => '15',
        'imap_batch_size' => '50',
        'imap_total_accounts' => count($imapAccounts),
        'imap_forward_to_campaign_owner' => '1', // Forward to campaign owner, not fixed email
        'imap_auto_classification' => '1',
        'imap_default_forward_email' => 'teamoutreach41@gmail.com' // Fallback if no campaign owner
    ];
    
    foreach ($globalSettings as $key => $value) {
        $db->execute(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', 
            [$key, $value]
        );
    }
    
    echo PHP_EOL;
    echo "✅ IMAP Multi-Account Configuration Complete!" . PHP_EOL;
    echo PHP_EOL;
    echo "Summary:" . PHP_EOL;
    echo "- Accounts configured: " . count($imapAccounts) . PHP_EOL;
    echo "- Monitoring enabled: Yes" . PHP_EOL;
    echo "- Check interval: 15 minutes" . PHP_EOL;
    echo "- Lead forwarding to: Campaign owner's email (from campaigns.owner_email)" . PHP_EOL;
    echo PHP_EOL;
    echo "Next steps:" . PHP_EOL;
    echo "1. Test connections: php test_imap_connections.php" . PHP_EOL;
    echo "2. Start monitoring: php start_imap_monitoring.php" . PHP_EOL;
    echo "3. Check logs: tail -f logs/imap_monitor.log" . PHP_EOL;
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . PHP_EOL;
}
?>