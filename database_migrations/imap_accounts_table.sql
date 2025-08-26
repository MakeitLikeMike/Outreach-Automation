-- Create dedicated table for IMAP sender accounts
CREATE TABLE IF NOT EXISTS imap_sender_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email_address VARCHAR(255) NOT NULL UNIQUE,
    display_name VARCHAR(255),
    app_password VARCHAR(255) NOT NULL,
    imap_host VARCHAR(255) DEFAULT 'imap.gmail.com',
    imap_port INT DEFAULT 993,
    imap_ssl TINYINT(1) DEFAULT 1,
    is_enabled TINYINT(1) DEFAULT 1,
    is_primary TINYINT(1) DEFAULT 0,
    daily_limit INT DEFAULT 75,
    current_daily_sent INT DEFAULT 0,
    last_reset_date DATE DEFAULT NULL,
    connection_status ENUM('connected', 'failed', 'untested') DEFAULT 'untested',
    last_connection_test TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email_address),
    INDEX idx_enabled (is_enabled),
    INDEX idx_primary (is_primary)
);

-- Insert existing accounts from system_settings if they exist
INSERT IGNORE INTO imap_sender_accounts (email_address, app_password, is_enabled, is_primary) 
VALUES 
    ('mikedelacruz@agileserviceph.com', IFNULL((SELECT setting_value FROM system_settings WHERE setting_key = 'gmass_smtp_password'), ''), 1, 1),
    ('jimmyrose1414@gmail.com', '', 1, 0),
    ('zackparker0905@gmail.com', '', 1, 0);