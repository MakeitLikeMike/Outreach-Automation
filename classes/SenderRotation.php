<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/GmailOAuth.php';

class SenderRotation {
    private $db;
    private $gmailOAuth;
    private $settings;
    
    // Rate limit constants (can be made configurable)
    const HOURLY_LIMIT = 30;
    const DAILY_LIMIT = 500;
    const MONTHLY_LIMIT = 10000;
    
    public function __construct() {
        $this->db = new Database();
        $this->gmailOAuth = new GmailOAuth();
        $this->loadSettings();
        $this->initializeRateLimits();
    }
    
    private function loadSettings() {
        $sql = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%sender%' OR setting_key LIKE '%email%'";
        $results = $this->db->fetchAll($sql);
        
        $this->settings = [];
        foreach ($results as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
        
        // Set defaults if not configured
        $defaults = [
            'email_rate_limit_per_hour' => self::HOURLY_LIMIT,
            'email_rate_limit_per_day' => self::DAILY_LIMIT,
            'email_rate_limit_per_month' => self::MONTHLY_LIMIT,
            'rotation_mode' => 'balanced',
            'enable_rotation' => 'yes',
            'cooldown_hours' => '1'
        ];
        
        foreach ($defaults as $key => $default) {
            if (!isset($this->settings[$key])) {
                $this->settings[$key] = $default;
            }
        }
    }
    
    private function initializeRateLimits() {
        // Ensure rate limit entries exist for all active Gmail OAuth accounts
        $activeAccounts = $this->gmailOAuth->getActiveTokens();
        
        foreach ($activeAccounts as $account) {
            $this->ensureRateLimitEntries($account['email']);
        }
        
        // Clean up old rate limits for removed accounts
        $this->cleanupOldRateLimits();
    }
    
    private function cleanupOldRateLimits() {
        // Remove rate limits for accounts that no longer exist in gmail_tokens
        $sql = "DELETE rl FROM rate_limits rl 
                LEFT JOIN gmail_tokens gt ON rl.sender_email = gt.email 
                WHERE gt.email IS NULL";
        $this->db->execute($sql);
    }
    
    private function ensureRateLimitEntries($email) {
        $limits = ['hourly', 'daily', 'monthly'];
        
        foreach ($limits as $limitType) {
            $sql = "INSERT IGNORE INTO rate_limits (sender_email, limit_type, current_count, limit_value, reset_at) VALUES (?, ?, 0, ?, ?)";
            
            $limitValue = $this->getLimitValue($limitType);
            $resetAt = $this->calculateResetTime($limitType);
            
            $this->db->execute($sql, [$email, $limitType, $limitValue, $resetAt]);
        }
    }
    
    private function getLimitValue($limitType) {
        switch ($limitType) {
            case 'hourly':
                return (int)$this->settings['email_rate_limit_per_hour'];
            case 'daily':
                return (int)$this->settings['email_rate_limit_per_day'];
            case 'monthly':
                return (int)$this->settings['email_rate_limit_per_month'];
            default:
                return self::HOURLY_LIMIT;
        }
    }
    
    private function calculateResetTime($limitType) {
        switch ($limitType) {
            case 'hourly':
                return date('Y-m-d H:i:s', strtotime('+1 hour', strtotime(date('Y-m-d H:00:00'))));
            case 'daily':
                return date('Y-m-d H:i:s', strtotime('+1 day', strtotime(date('Y-m-d 00:00:00'))));
            case 'monthly':
                return date('Y-m-d H:i:s', strtotime('+1 month', strtotime(date('Y-m-01 00:00:00'))));
            default:
                return date('Y-m-d H:i:s', strtotime('+1 hour'));
        }
    }
    
    public function getNextAvailableSender($excludeEmails = []) {
        // Reset expired rate limits first
        $this->resetExpiredLimits();
        
        // Check if rotation is enabled
        $enableRotation = ($this->settings['enable_rotation'] ?? 'yes') === 'yes';
        
        if (!$enableRotation) {
            // If rotation is disabled, just use the first available OAuth account
            $availableSenders = $this->getAvailableOAuthSenders($excludeEmails);
            if (empty($availableSenders)) {
                throw new Exception('No available Gmail OAuth senders found. Please connect at least one Gmail account.');
            }
            return $availableSenders[0];
        }
        
        // Get all available OAuth senders
        $availableSenders = $this->getAvailableOAuthSenders($excludeEmails);
        
        if (empty($availableSenders)) {
            throw new Exception('No available Gmail OAuth senders found. All connected accounts may be rate limited or inactive.');
        }
        
        // Select sender based on rotation mode
        $rotationMode = $this->settings['rotation_mode'] ?? 'balanced';
        
        switch ($rotationMode) {
            case 'sequential':
                return $this->selectSequentialSender($availableSenders);
            case 'random':
                return $this->selectRandomSender($availableSenders);
            case 'balanced':
            default:
                return $this->selectBalancedSender($availableSenders);
        }
    }
    
    private function resetExpiredLimits() {
        $sql = "UPDATE rate_limits SET current_count = 0, reset_at = ? WHERE reset_at <= NOW()";
        
        // Update each limit type with its new reset time
        $limitTypes = ['hourly', 'daily', 'monthly'];
        
        foreach ($limitTypes as $limitType) {
            $newResetTime = $this->calculateResetTime($limitType);
            $updateSql = "UPDATE rate_limits SET current_count = 0, reset_at = ? WHERE limit_type = ? AND reset_at <= NOW()";
            $this->db->execute($updateSql, [$newResetTime, $limitType]);
        }
    }
    
    private function getAvailableOAuthSenders($excludeEmails = []) {
        // Build exclusion clause
        $excludeClause = '';
        $excludeParams = [];
        
        if (!empty($excludeEmails)) {
            $placeholders = str_repeat('?,', count($excludeEmails) - 1) . '?';
            $excludeClause = "AND gt.email NOT IN ($placeholders)";
            $excludeParams = $excludeEmails;
        }
        
        // Get active Gmail OAuth accounts that are not rate limited
        $sql = "SELECT 
                    gt.email,
                    gt.expires_at,
                    gt.access_token,
                    COALESCE(rl_hourly.current_count, 0) as hourly_count,
                    COALESCE(rl_hourly.limit_value, ?) as hourly_limit,
                    COALESCE(rl_daily.current_count, 0) as daily_count,
                    COALESCE(rl_daily.limit_value, ?) as daily_limit,
                    COALESCE(rl_monthly.current_count, 0) as monthly_count,
                    COALESCE(rl_monthly.limit_value, ?) as monthly_limit,
                    COALESCE(sender_usage.last_used, '1970-01-01 00:00:00') as last_used,
                    COALESCE(sender_usage.total_sent_today, 0) as total_sent_today
                FROM gmail_tokens gt
                LEFT JOIN rate_limits rl_hourly ON gt.email = rl_hourly.sender_email AND rl_hourly.limit_type = 'hourly'
                LEFT JOIN rate_limits rl_daily ON gt.email = rl_daily.sender_email AND rl_daily.limit_type = 'daily'
                LEFT JOIN rate_limits rl_monthly ON gt.email = rl_monthly.sender_email AND rl_monthly.limit_type = 'monthly'
                LEFT JOIN (
                    SELECT 
                        sender_email,
                        MAX(created_at) as last_used,
                        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as total_sent_today
                    FROM email_queue 
                    WHERE status = 'sent' 
                    GROUP BY sender_email
                ) sender_usage ON gt.email = sender_usage.sender_email
                WHERE gt.expires_at > NOW()
                AND gt.access_token != ''
                AND (rl_hourly.current_count < rl_hourly.limit_value OR rl_hourly.current_count IS NULL)
                AND (rl_daily.current_count < rl_daily.limit_value OR rl_daily.current_count IS NULL)
                AND (rl_monthly.current_count < rl_monthly.limit_value OR rl_monthly.current_count IS NULL)
                $excludeClause
                ORDER BY gt.email";
        
        $params = array_merge([
            $this->getLimitValue('hourly'),
            $this->getLimitValue('daily'),
            $this->getLimitValue('monthly')
        ], $excludeParams);
        
        return $this->db->fetchAll($sql, $params);
    }
    
    private function selectSequentialSender($senders) {
        // Select sender that was used least recently
        usort($senders, function($a, $b) {
            return strtotime($a['last_used']) - strtotime($b['last_used']);
        });
        
        return $senders[0];
    }
    
    private function selectRandomSender($senders) {
        return $senders[array_rand($senders)];
    }
    
    private function selectBalancedSender($senders) {
        // Score each sender based on usage and availability
        $scoredSenders = [];
        
        foreach ($senders as $sender) {
            $score = $this->calculateSenderScore($sender);
            $scoredSenders[] = array_merge($sender, ['score' => $score]);
        }
        
        // Sort by score (lower is better)
        usort($scoredSenders, function($a, $b) {
            return $a['score'] - $b['score'];
        });
        
        return $scoredSenders[0];
    }
    
    private function calculateSenderScore($sender) {
        $score = 0;
        
        // Factor 1: Current usage vs limits (0-100 points)
        $hourlyUsage = ($sender['hourly_count'] / max($sender['hourly_limit'], 1)) * 100;
        $dailyUsage = ($sender['daily_count'] / max($sender['daily_limit'], 1)) * 100;
        $monthlyUsage = ($sender['monthly_count'] / max($sender['monthly_limit'], 1)) * 100;
        
        $score += max($hourlyUsage, $dailyUsage, $monthlyUsage);
        
        // Factor 2: Recent usage penalty (0-50 points)
        $lastUsed = strtotime($sender['last_used']);
        $timeSinceLastUse = time() - $lastUsed;
        $hoursSinceLastUse = $timeSinceLastUse / 3600;
        
        if ($hoursSinceLastUse < 1) {
            $score += 50; // Heavy penalty for very recent use
        } elseif ($hoursSinceLastUse < 6) {
            $score += 25; // Moderate penalty for recent use
        } elseif ($hoursSinceLastUse < 24) {
            $score += 10; // Light penalty for same-day use
        }
        // No penalty for use more than 24 hours ago
        
        // Factor 3: Token expiry proximity penalty (0-20 points)
        $expiresAt = strtotime($sender['expires_at']);
        $timeUntilExpiry = $expiresAt - time();
        $hoursUntilExpiry = $timeUntilExpiry / 3600;
        
        if ($hoursUntilExpiry < 1) {
            $score += 20; // High penalty for near-expiry tokens
        } elseif ($hoursUntilExpiry < 24) {
            $score += 10; // Moderate penalty for expiring soon
        }
        
        return $score;
    }
    
    public function recordEmailSent($senderEmail, $messageId = null, $threadId = null) {
        try {
            // Update rate limits
            $this->incrementRateLimits($senderEmail);
            
            // Log the usage for tracking
            $this->logSenderUsage($senderEmail, $messageId, $threadId);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to record email sent for $senderEmail: " . $e->getMessage());
            return false;
        }
    }
    
    private function incrementRateLimits($senderEmail) {
        $limits = ['hourly', 'daily', 'monthly'];
        
        foreach ($limits as $limitType) {
            $sql = "UPDATE rate_limits 
                    SET current_count = current_count + 1 
                    WHERE sender_email = ? AND limit_type = ?";
            
            $this->db->execute($sql, [$senderEmail, $limitType]);
        }
    }
    
    private function logSenderUsage($senderEmail, $messageId, $threadId) {
        $sql = "INSERT INTO api_logs (api_service, endpoint, method, request_data, response_data, status_code) 
                VALUES ('gmail', 'sender_rotation', 'POST', ?, ?, 200)";
        
        $request_data = json_encode([
            'sender_email' => $senderEmail,
            'action' => 'email_sent',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        $response_data = json_encode([
            'success' => true,
            'message_id' => $messageId,
            'thread_id' => $threadId
        ]);
        
        $this->db->execute($sql, [$request_data, $response_data]);
    }
    
    public function getSenderStatus($senderEmail = null) {
        if ($senderEmail) {
            return $this->getIndividualSenderStatus($senderEmail);
        } else {
            return $this->getAllSendersStatus();
        }
    }
    
    private function getIndividualSenderStatus($senderEmail) {
        $sql = "SELECT 
                    gt.email,
                    gt.expires_at,
                    CASE WHEN gt.expires_at > NOW() THEN 'active' ELSE 'expired' END as token_status,
                    rl_hourly.current_count as hourly_used,
                    rl_hourly.limit_value as hourly_limit,
                    rl_hourly.reset_at as hourly_reset,
                    rl_daily.current_count as daily_used,
                    rl_daily.limit_value as daily_limit,
                    rl_daily.reset_at as daily_reset,
                    rl_monthly.current_count as monthly_used,
                    rl_monthly.limit_value as monthly_limit,
                    rl_monthly.reset_at as monthly_reset
                FROM gmail_tokens gt
                LEFT JOIN rate_limits rl_hourly ON gt.email = rl_hourly.sender_email AND rl_hourly.limit_type = 'hourly'
                LEFT JOIN rate_limits rl_daily ON gt.email = rl_daily.sender_email AND rl_daily.limit_type = 'daily'
                LEFT JOIN rate_limits rl_monthly ON gt.email = rl_monthly.sender_email AND rl_monthly.limit_type = 'monthly'
                WHERE gt.email = ?";
        
        $result = $this->db->fetchOne($sql, [$senderEmail]);
        
        if ($result) {
            $result['is_available'] = $this->isSenderAvailable($result);
            $result['score'] = $this->calculateSenderScore($result);
        }
        
        return $result;
    }
    
    private function getAllSendersStatus() {
        $sql = "SELECT 
                    gt.email,
                    gt.expires_at,
                    CASE WHEN gt.expires_at > NOW() THEN 'active' ELSE 'expired' END as token_status,
                    rl_hourly.current_count as hourly_used,
                    rl_hourly.limit_value as hourly_limit,
                    rl_daily.current_count as daily_used,
                    rl_daily.limit_value as daily_limit,
                    rl_monthly.current_count as monthly_used,
                    rl_monthly.limit_value as monthly_limit,
                    COALESCE(sender_usage.last_used, '1970-01-01 00:00:00') as last_used,
                    COALESCE(sender_usage.total_sent_today, 0) as emails_sent_today
                FROM gmail_tokens gt
                LEFT JOIN rate_limits rl_hourly ON gt.email = rl_hourly.sender_email AND rl_hourly.limit_type = 'hourly'
                LEFT JOIN rate_limits rl_daily ON gt.email = rl_daily.sender_email AND rl_daily.limit_type = 'daily'
                LEFT JOIN rate_limits rl_monthly ON gt.email = rl_monthly.sender_email AND rl_monthly.limit_type = 'monthly'
                LEFT JOIN (
                    SELECT 
                        sender_email,
                        MAX(created_at) as last_used,
                        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as total_sent_today
                    FROM email_queue 
                    WHERE status = 'sent' 
                    GROUP BY sender_email
                ) sender_usage ON gt.email = sender_usage.sender_email
                ORDER BY gt.email";
        
        $results = $this->db->fetchAll($sql);
        
        foreach ($results as &$result) {
            $result['is_available'] = $this->isSenderAvailable($result);
            $result['score'] = $this->calculateSenderScore($result);
        }
        
        return $results;
    }
    
    private function isSenderAvailable($sender) {
        // Check if token is active
        if (strtotime($sender['expires_at']) <= time()) {
            return false;
        }
        
        // Check rate limits
        if ($sender['hourly_used'] >= $sender['hourly_limit']) return false;
        if ($sender['daily_used'] >= $sender['daily_limit']) return false;
        if ($sender['monthly_used'] >= $sender['monthly_limit']) return false;
        
        return true;
    }
    
    public function resetSenderLimits($senderEmail, $limitType = 'all') {
        if ($limitType === 'all') {
            $sql = "UPDATE rate_limits SET current_count = 0, reset_at = ? WHERE sender_email = ?";
            $this->db->execute($sql, [date('Y-m-d H:i:s', strtotime('+1 hour')), $senderEmail]);
        } else {
            $resetTime = $this->calculateResetTime($limitType);
            $sql = "UPDATE rate_limits SET current_count = 0, reset_at = ? WHERE sender_email = ? AND limit_type = ?";
            $this->db->execute($sql, [$resetTime, $senderEmail, $limitType]);
        }
    }
    
    public function addNewOAuthSender($email) {
        // Add rate limit entries for the new OAuth account
        $this->ensureRateLimitEntries($email);
        
        // Log the addition
        $this->logSenderAction($email, 'oauth_account_added', ['timestamp' => date('Y-m-d H:i:s')]);
    }
    
    public function removeOAuthSender($email) {
        // Remove rate limit entries for the removed OAuth account
        $sql = "DELETE FROM rate_limits WHERE sender_email = ?";
        $this->db->execute($sql, [$email]);
        
        // Log the removal
        $this->logSenderAction($email, 'oauth_account_removed', ['timestamp' => date('Y-m-d H:i:s')]);
    }
    
    private function logSenderAction($email, $action, $data = []) {
        $sql = "INSERT INTO api_logs (api_service, endpoint, method, request_data, response_data, status_code) 
                VALUES ('gmail', 'sender_rotation', 'POST', ?, ?, 200)";
        
        $request_data = json_encode([
            'sender_email' => $email,
            'action' => $action,
            'data' => $data
        ]);
        
        $response_data = json_encode(['success' => true]);
        
        $this->db->execute($sql, [$request_data, $response_data]);
    }
    
    public function getRotationStatistics() {
        return [
            'total_senders' => $this->getTotalSendersCount(),
            'active_senders' => $this->getActiveSendersCount(),
            'available_senders' => $this->getAvailableSendersCount(),
            'rate_limited_senders' => $this->getRateLimitedSendersCount(),
            'expired_token_senders' => $this->getExpiredTokenSendersCount(),
            'rotation_mode' => $this->settings['rotation_mode'],
            'daily_capacity' => $this->calculateDailyCapacity(),
            'current_usage_today' => $this->getCurrentUsageToday()
        ];
    }
    
    private function getTotalSendersCount() {
        $sql = "SELECT COUNT(*) as count FROM gmail_tokens";
        $result = $this->db->fetchOne($sql);
        return $result['count'] ?? 0;
    }
    
    private function getActiveSendersCount() {
        $sql = "SELECT COUNT(*) as count FROM gmail_tokens WHERE expires_at > NOW()";
        $result = $this->db->fetchOne($sql);
        return $result['count'] ?? 0;
    }
    
    private function getAvailableSendersCount() {
        return count($this->getAvailableOAuthSenders());
    }
    
    private function getRateLimitedSendersCount() {
        $sql = "SELECT COUNT(DISTINCT sender_email) as count 
                FROM rate_limits 
                WHERE current_count >= limit_value";
        $result = $this->db->fetchOne($sql);
        return $result['count'] ?? 0;
    }
    
    private function getExpiredTokenSendersCount() {
        $sql = "SELECT COUNT(*) as count FROM gmail_tokens WHERE expires_at <= NOW()";
        $result = $this->db->fetchOne($sql);
        return $result['count'] ?? 0;
    }
    
    private function calculateDailyCapacity() {
        $activeSenders = $this->getActiveSendersCount();
        $dailyLimitPerSender = (int)$this->settings['email_rate_limit_per_day'];
        return $activeSenders * $dailyLimitPerSender;
    }
    
    private function getCurrentUsageToday() {
        $sql = "SELECT COUNT(*) as count 
                FROM outreach_emails 
                WHERE status = 'sent' AND DATE(created_at) = CURDATE()";
        $result = $this->db->fetchOne($sql);
        return $result['count'] ?? 0;
    }
}
?>