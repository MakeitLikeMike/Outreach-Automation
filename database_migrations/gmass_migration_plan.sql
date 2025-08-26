-- GMass Migration Database Changes
-- Phase 1: Add new GMASS and IMAP settings

-- Add GMass API settings
INSERT INTO system_settings (setting_key, setting_value, description) 
VALUES ('gmass_api_key', '', 'GMass API key for email sending')
ON DUPLICATE KEY UPDATE description = 'GMass API key for email sending';

-- Add IMAP settings
INSERT INTO system_settings (setting_key, setting_value, description) 
VALUES ('imap_email', '', 'IMAP email address for monitoring replies')
ON DUPLICATE KEY UPDATE description = 'IMAP email address for monitoring replies';

INSERT INTO system_settings (setting_key, setting_value, description) 
VALUES ('imap_app_password', '', 'Gmail App Password for IMAP access')
ON DUPLICATE KEY UPDATE description = 'Gmail App Password for IMAP access';

INSERT INTO system_settings (setting_key, setting_value, description) 
VALUES ('imap_host', 'imap.gmail.com', 'IMAP host server')
ON DUPLICATE KEY UPDATE description = 'IMAP host server';

INSERT INTO system_settings (setting_key, setting_value, description) 
VALUES ('imap_port', '993', 'IMAP port (993 for SSL)')
ON DUPLICATE KEY UPDATE description = 'IMAP port (993 for SSL)';

INSERT INTO system_settings (setting_key, setting_value, description) 
VALUES ('imap_ssl_enabled', '1', 'Enable SSL for IMAP connection')
ON DUPLICATE KEY UPDATE description = 'Enable SSL for IMAP connection';

-- Add email tracking table for GMass integration
CREATE TABLE IF NOT EXISTS gmass_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    local_campaign_id INT NOT NULL,
    gmass_campaign_id VARCHAR(255),
    gmass_draft_id VARCHAR(255),
    status ENUM('draft', 'scheduled', 'sending', 'sent', 'failed') DEFAULT 'draft',
    sent_count INT DEFAULT 0,
    total_recipients INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (local_campaign_id) REFERENCES campaigns(id)
);

-- Add IMAP monitoring table
CREATE TABLE IF NOT EXISTS imap_processed_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) UNIQUE NOT NULL,
    subject VARCHAR(500),
    sender_email VARCHAR(255),
    received_date TIMESTAMP,
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    classification ENUM('positive', 'negative', 'neutral', 'spam', 'bounce', 'question'),
    forwarded BOOLEAN DEFAULT FALSE,
    INDEX idx_message_id (message_id),
    INDEX idx_processed_at (processed_at)
);

-- Update outreach_emails table to support GMass
ALTER TABLE outreach_emails 
ADD COLUMN gmass_message_id VARCHAR(255) AFTER id,
ADD COLUMN sent_via ENUM('gmail', 'gmass') DEFAULT 'gmail',
ADD COLUMN delivery_status VARCHAR(50),
ADD INDEX idx_gmass_message_id (gmass_message_id);

-- Phase 2: Migration tracking
CREATE TABLE IF NOT EXISTS migration_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_step VARCHAR(100),
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO migration_log (migration_step, status) VALUES 
('create_gmass_tables', 'completed'),
('add_imap_settings', 'completed'),
('backup_gmail_settings', 'pending'),
('update_email_tracking', 'pending'),
('test_gmass_integration', 'pending'),
('test_imap_connection', 'pending'),
('migrate_active_campaigns', 'pending'),
('cleanup_gmail_references', 'pending');