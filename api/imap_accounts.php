<?php
/**
 * IMAP Sender Accounts API
 * Handles CRUD operations for IMAP sender accounts
 */
require_once '../config/database.php';

header('Content-Type: application/json');

function testImapConnection($email, $password, $host = 'imap.gmail.com', $port = 993) {
    try {
        if (!extension_loaded('imap')) {
            return ['success' => false, 'error' => 'IMAP extension not loaded'];
        }
        
        $mailbox = "{{$host}:{$port}/imap/ssl/novalidate-cert}INBOX";
        $connection = @imap_open($mailbox, $email, $password);
        
        if ($connection) {
            $status = imap_status($connection, $mailbox, SA_ALL);
            imap_close($connection);
            
            return [
                'success' => true, 
                'message' => 'Connection successful',
                'total_messages' => $status->messages ?? 0,
                'unread_messages' => $status->unseen ?? 0
            ];
        } else {
            $error = imap_last_error() ?: 'Connection failed';
            return ['success' => false, 'error' => $error];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

try {
    $db = new Database();
    $method = $_SERVER['REQUEST_METHOD'];
    
    // GET - List all IMAP accounts
    if ($method === 'GET') {
        if (isset($_GET['action']) && $_GET['action'] === 'test' && isset($_GET['id'])) {
            // Test connection for specific account
            $accountId = $_GET['id'];
            $account = $db->fetchOne("SELECT * FROM imap_sender_accounts WHERE id = ?", [$accountId]);
            
            if (!$account) {
                echo json_encode(['success' => false, 'error' => 'Account not found']);
                exit;
            }
            
            if (empty($account['app_password'])) {
                echo json_encode(['success' => false, 'error' => 'App password not configured']);
                exit;
            }
            
            $result = testImapConnection($account['email_address'], $account['app_password'], $account['imap_host'], $account['imap_port']);
            
            // Update connection status
            $status = $result['success'] ? 'connected' : 'failed';
            $db->execute("UPDATE imap_sender_accounts SET connection_status = ?, last_connection_test = NOW() WHERE id = ?", [$status, $accountId]);
            
            echo json_encode($result);
        } else {
            // List all accounts
            $accounts = $db->fetchAll("SELECT * FROM imap_sender_accounts ORDER BY is_primary DESC, email_address ASC");
            
            // Mask app passwords for security
            foreach ($accounts as &$account) {
                $account['app_password_masked'] = empty($account['app_password']) ? '' : str_repeat('*', 16);
                unset($account['app_password']); // Remove actual password from response
            }
            
            echo json_encode(['success' => true, 'accounts' => $accounts]);
        }
    }
    
    // POST - Create new account
    elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
            exit;
        }
        
        $email = trim($input['email_address'] ?? '');
        $displayName = trim($input['display_name'] ?? '');
        $appPassword = trim($input['app_password'] ?? '');
        $dailyLimit = (int)($input['daily_limit'] ?? 75);
        $isPrimary = !empty($input['is_primary']);
        
        // Validation
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Valid email address is required']);
            exit;
        }
        
        if (empty($appPassword)) {
            echo json_encode(['success' => false, 'error' => 'App password is required']);
            exit;
        }
        
        if (strlen($appPassword) !== 16) {
            echo json_encode(['success' => false, 'error' => 'Gmail app password must be exactly 16 characters']);
            exit;
        }
        
        // Check if email already exists
        $existing = $db->fetchOne("SELECT id FROM imap_sender_accounts WHERE email_address = ?", [$email]);
        if ($existing) {
            echo json_encode(['success' => false, 'error' => 'Email address already exists']);
            exit;
        }
        
        // Test connection before saving
        $connectionTest = testImapConnection($email, $appPassword);
        if (!$connectionTest['success']) {
            echo json_encode(['success' => false, 'error' => 'IMAP connection failed: ' . $connectionTest['error']]);
            exit;
        }
        
        // If setting as primary, remove primary flag from others
        if ($isPrimary) {
            $db->execute("UPDATE imap_sender_accounts SET is_primary = 0");
        }
        
        // Insert new account
        $sql = "INSERT INTO imap_sender_accounts (email_address, display_name, app_password, daily_limit, is_primary, connection_status, last_connection_test) 
                VALUES (?, ?, ?, ?, ?, 'connected', NOW())";
        
        $db->execute($sql, [$email, $displayName, $appPassword, $dailyLimit, $isPrimary ? 1 : 0]);
        $newId = $db->getConnection()->lastInsertId();
        
        echo json_encode(['success' => true, 'message' => 'IMAP account added successfully', 'id' => $newId]);
    }
    
    // PUT - Update existing account
    elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['id'])) {
            echo json_encode(['success' => false, 'error' => 'Account ID is required']);
            exit;
        }
        
        $accountId = $input['id'];
        $account = $db->fetchOne("SELECT * FROM imap_sender_accounts WHERE id = ?", [$accountId]);
        
        if (!$account) {
            echo json_encode(['success' => false, 'error' => 'Account not found']);
            exit;
        }
        
        $updates = [];
        $params = [];
        
        // Update fields if provided
        if (isset($input['display_name'])) {
            $updates[] = "display_name = ?";
            $params[] = trim($input['display_name']);
        }
        
        if (isset($input['app_password']) && !empty(trim($input['app_password']))) {
            $appPassword = trim($input['app_password']);
            if (strlen($appPassword) !== 16) {
                echo json_encode(['success' => false, 'error' => 'Gmail app password must be exactly 16 characters']);
                exit;
            }
            
            // Test new password
            $connectionTest = testImapConnection($account['email_address'], $appPassword);
            if (!$connectionTest['success']) {
                echo json_encode(['success' => false, 'error' => 'IMAP connection failed: ' . $connectionTest['error']]);
                exit;
            }
            
            $updates[] = "app_password = ?";
            $params[] = $appPassword;
            $updates[] = "connection_status = 'connected'";
            $updates[] = "last_connection_test = NOW()";
        }
        
        if (isset($input['daily_limit'])) {
            $updates[] = "daily_limit = ?";
            $params[] = (int)$input['daily_limit'];
        }
        
        if (isset($input['is_enabled'])) {
            $updates[] = "is_enabled = ?";
            $params[] = !empty($input['is_enabled']) ? 1 : 0;
        }
        
        if (isset($input['is_primary']) && !empty($input['is_primary'])) {
            // Remove primary flag from others first
            $db->execute("UPDATE imap_sender_accounts SET is_primary = 0");
            $updates[] = "is_primary = 1";
        }
        
        if (empty($updates)) {
            echo json_encode(['success' => false, 'error' => 'No fields to update']);
            exit;
        }
        
        $updates[] = "updated_at = NOW()";
        $params[] = $accountId;
        
        $sql = "UPDATE imap_sender_accounts SET " . implode(', ', $updates) . " WHERE id = ?";
        $db->execute($sql, $params);
        
        echo json_encode(['success' => true, 'message' => 'Account updated successfully']);
    }
    
    // DELETE - Remove account
    elseif ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['id'])) {
            echo json_encode(['success' => false, 'error' => 'Account ID is required']);
            exit;
        }
        
        $accountId = $input['id'];
        $account = $db->fetchOne("SELECT * FROM imap_sender_accounts WHERE id = ?", [$accountId]);
        
        if (!$account) {
            echo json_encode(['success' => false, 'error' => 'Account not found']);
            exit;
        }
        
        // Don't allow deleting if it's the only enabled account
        $enabledCount = $db->fetchOne("SELECT COUNT(*) as count FROM imap_sender_accounts WHERE is_enabled = 1")['count'];
        if ($enabledCount <= 1 && $account['is_enabled']) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete the last enabled IMAP account']);
            exit;
        }
        
        $db->execute("DELETE FROM imap_sender_accounts WHERE id = ?", [$accountId]);
        
        echo json_encode(['success' => true, 'message' => 'Account deleted successfully']);
    }
    
    else {
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>