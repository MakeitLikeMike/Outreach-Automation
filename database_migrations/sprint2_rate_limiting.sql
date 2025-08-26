-- Sprint 2: Rate Limiting and Sender Rotation Database Updates
-- This migration adds rate limiting settings and ensures proper indexes

USE outreach_automation;

-- Add rate limiting settings to system_settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('email_rate_limit_per_hour', '30', 'Maximum emails per sender per hour'),
('email_rate_limit_per_day', '500', 'Maximum emails per sender per day'),
('email_rate_limit_per_month', '10000', 'Maximum emails per sender per month'),
('sender_rotation_mode', 'balanced', 'Sender rotation algorithm: sequential, random, balanced'),
('enable_sender_rotation', 'yes', 'Enable automatic sender rotation'),
('sender_cooldown_minutes', '60', 'Minimum minutes between uses of same sender'),
('rate_limit_buffer_percentage', '10', 'Safety buffer percentage for rate limits'),
('enable_rate_limit_monitoring', 'yes', 'Enable rate limit monitoring and alerts')

ON DUPLICATE KEY UPDATE 
setting_value = VALUES(setting_value),
description = VALUES(description);

-- Ensure rate_limits table has proper structure and indexes
ALTER TABLE rate_limits 
ADD INDEX IF NOT EXISTS idx_sender_type_reset (sender_email, limit_type, reset_at),
ADD INDEX IF NOT EXISTS idx_reset_time (reset_at),
ADD INDEX IF NOT EXISTS idx_current_vs_limit (current_count, limit_value);

-- Add sender performance tracking table for advanced statistics
CREATE TABLE IF NOT EXISTS sender_performance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_email VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    emails_sent INT DEFAULT 0,
    emails_delivered INT DEFAULT 0,
    emails_bounced INT DEFAULT 0,
    replies_received INT DEFAULT 0,
    positive_replies INT DEFAULT 0,
    success_rate DECIMAL(5,2) DEFAULT 0.00,
    response_rate DECIMAL(5,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_sender_date (sender_email, date),
    INDEX idx_sender_date (sender_email, date),
    INDEX idx_date (date),
    INDEX idx_success_rate (success_rate),
    INDEX idx_response_rate (response_rate)
);

-- Add sender health status table for monitoring
CREATE TABLE IF NOT EXISTS sender_health (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_email VARCHAR(255) UNIQUE NOT NULL,
    status ENUM('healthy', 'warning', 'critical', 'suspended') DEFAULT 'healthy',
    health_score DECIMAL(5,2) DEFAULT 100.00,
    last_success_at TIMESTAMP NULL,
    last_failure_at TIMESTAMP NULL,
    consecutive_failures INT DEFAULT 0,
    total_failures_today INT DEFAULT 0,
    warning_flags JSON,
    notes TEXT,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_health_score (health_score),
    INDEX idx_checked_at (checked_at)
);

-- Add rotation queue table for fair distribution
CREATE TABLE IF NOT EXISTS sender_rotation_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_email VARCHAR(255) NOT NULL,
    priority_score DECIMAL(10,4) DEFAULT 0.0000,
    last_used_at TIMESTAMP NULL,
    next_available_at TIMESTAMP NULL,
    total_uses_today INT DEFAULT 0,
    consecutive_uses INT DEFAULT 0,
    is_available BOOLEAN DEFAULT TRUE,
    reason_unavailable VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_sender (sender_email),
    INDEX idx_priority_available (priority_score, is_available),
    INDEX idx_next_available (next_available_at),
    INDEX idx_last_used (last_used_at)
);

-- Add rate limit alerts table for monitoring
CREATE TABLE IF NOT EXISTS rate_limit_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_email VARCHAR(255) NOT NULL,
    alert_type ENUM('approaching_limit', 'limit_reached', 'limit_exceeded', 'account_suspended') NOT NULL,
    limit_type ENUM('hourly', 'daily', 'monthly') NOT NULL,
    current_count INT NOT NULL,
    limit_value INT NOT NULL,
    percentage_used DECIMAL(5,2) NOT NULL,
    message TEXT,
    is_resolved BOOLEAN DEFAULT FALSE,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sender_type (sender_email, alert_type),
    INDEX idx_created_resolved (created_at, is_resolved),
    INDEX idx_limit_type (limit_type)
);

-- Update outreach_emails table to include sender rotation tracking
ALTER TABLE outreach_emails 
ADD COLUMN IF NOT EXISTS sender_rotation_id INT NULL AFTER sender_email,
ADD COLUMN IF NOT EXISTS sender_selection_reason VARCHAR(255) NULL AFTER sender_rotation_id,
ADD COLUMN IF NOT EXISTS rate_limit_status JSON NULL AFTER sender_selection_reason,
ADD INDEX IF NOT EXISTS idx_sender_rotation (sender_rotation_id),
ADD INDEX IF NOT EXISTS idx_sender_date (sender_email, sent_at);

-- Create view for sender rotation dashboard
CREATE OR REPLACE VIEW sender_rotation_dashboard AS
SELECT 
    gt.email as sender_email,
    CASE WHEN gt.expires_at > NOW() THEN 'active' ELSE 'expired' END as token_status,
    COALESCE(sh.status, 'unknown') as health_status,
    COALESCE(sh.health_score, 0) as health_score,
    
    -- Rate limit information
    rl_hourly.current_count as hourly_used,
    rl_hourly.limit_value as hourly_limit,
    ROUND((rl_hourly.current_count / rl_hourly.limit_value) * 100, 2) as hourly_percentage,
    
    rl_daily.current_count as daily_used,
    rl_daily.limit_value as daily_limit,
    ROUND((rl_daily.current_count / rl_daily.limit_value) * 100, 2) as daily_percentage,
    
    rl_monthly.current_count as monthly_used,
    rl_monthly.limit_value as monthly_limit,
    ROUND((rl_monthly.current_count / rl_monthly.limit_value) * 100, 2) as monthly_percentage,
    
    -- Usage statistics
    COALESCE(sp.emails_sent, 0) as emails_sent_today,
    COALESCE(sp.success_rate, 0) as success_rate_today,
    COALESCE(sp.response_rate, 0) as response_rate_today,
    
    -- Availability
    CASE 
        WHEN gt.expires_at <= NOW() THEN FALSE
        WHEN rl_hourly.current_count >= rl_hourly.limit_value THEN FALSE
        WHEN rl_daily.current_count >= rl_daily.limit_value THEN FALSE
        WHEN rl_monthly.current_count >= rl_monthly.limit_value THEN FALSE
        WHEN COALESCE(sh.status, 'healthy') IN ('critical', 'suspended') THEN FALSE
        ELSE TRUE
    END as is_available,
    
    -- Last activity
    COALESCE(sh.last_success_at, '1970-01-01 00:00:00') as last_success,
    COALESCE(sh.last_failure_at, '1970-01-01 00:00:00') as last_failure,
    
    gt.created_at as connected_at,
    gt.updated_at as last_token_update

FROM gmail_tokens gt
LEFT JOIN sender_health sh ON gt.email = sh.sender_email
LEFT JOIN rate_limits rl_hourly ON gt.email = rl_hourly.sender_email AND rl_hourly.limit_type = 'hourly'
LEFT JOIN rate_limits rl_daily ON gt.email = rl_daily.sender_email AND rl_daily.limit_type = 'daily'  
LEFT JOIN rate_limits rl_monthly ON gt.email = rl_monthly.sender_email AND rl_monthly.limit_type = 'monthly'
LEFT JOIN sender_performance sp ON gt.email = sp.sender_email AND sp.date = CURDATE()
ORDER BY gt.email;

-- Create stored procedure for rate limit management
DELIMITER $$

CREATE OR REPLACE PROCEDURE ResetExpiredRateLimits()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE sender VARCHAR(255);
    DECLARE limit_type VARCHAR(20);
    
    -- Cursor for expired limits
    DECLARE cur CURSOR FOR 
        SELECT sender_email, limit_type 
        FROM rate_limits 
        WHERE reset_at <= NOW();
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    
    rate_limit_loop: LOOP
        FETCH cur INTO sender, limit_type;
        IF done THEN
            LEAVE rate_limit_loop;
        END IF;
        
        -- Calculate new reset time based on limit type
        SET @new_reset_time = CASE limit_type
            WHEN 'hourly' THEN DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), INTERVAL 1 HOUR)
            WHEN 'daily' THEN DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00'), INTERVAL 1 DAY)
            WHEN 'monthly' THEN DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00'), INTERVAL 1 MONTH)
            ELSE DATE_ADD(NOW(), INTERVAL 1 HOUR)
        END;
        
        -- Reset the limit
        UPDATE rate_limits 
        SET current_count = 0, 
            reset_at = @new_reset_time,
            updated_at = NOW()
        WHERE sender_email = sender 
        AND limit_type = limit_type;
        
    END LOOP;
    
    CLOSE cur;
END$$

DELIMITER ;

-- Initialize rate limits for existing Gmail accounts
INSERT INTO rate_limits (sender_email, limit_type, current_count, limit_value, reset_at)
SELECT 
    gt.email,
    'hourly' as limit_type,
    0 as current_count,
    30 as limit_value,
    DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), INTERVAL 1 HOUR) as reset_at
FROM gmail_tokens gt
WHERE NOT EXISTS (
    SELECT 1 FROM rate_limits rl 
    WHERE rl.sender_email = gt.email 
    AND rl.limit_type = 'hourly'
);

INSERT INTO rate_limits (sender_email, limit_type, current_count, limit_value, reset_at)
SELECT 
    gt.email,
    'daily' as limit_type,
    0 as current_count,
    500 as limit_value,
    DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00'), INTERVAL 1 DAY) as reset_at
FROM gmail_tokens gt
WHERE NOT EXISTS (
    SELECT 1 FROM rate_limits rl 
    WHERE rl.sender_email = gt.email 
    AND rl.limit_type = 'daily'
);

INSERT INTO rate_limits (sender_email, limit_type, current_count, limit_value, reset_at)
SELECT 
    gt.email,
    'monthly' as limit_type,
    0 as current_count,
    10000 as limit_value,
    DATE_ADD(DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00'), INTERVAL 1 MONTH) as reset_at
FROM gmail_tokens gt
WHERE NOT EXISTS (
    SELECT 1 FROM rate_limits rl 
    WHERE rl.sender_email = gt.email 
    AND rl.limit_type = 'monthly'
);

-- Initialize sender health records for existing accounts
INSERT INTO sender_health (sender_email, status, health_score)
SELECT 
    gt.email,
    'healthy' as status,
    100.00 as health_score
FROM gmail_tokens gt
WHERE NOT EXISTS (
    SELECT 1 FROM sender_health sh 
    WHERE sh.sender_email = gt.email
);

SELECT 'Sprint 2 Rate Limiting Migration Completed Successfully!' as status,
       'Rate limiting tables, indexes, and views have been created.' as message,
       'Sender rotation system is now ready for use.' as next_steps;