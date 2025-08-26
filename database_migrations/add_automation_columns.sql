-- Migration: Add automation support columns
-- Run this after setting up the automated outreach system

USE outreach_automation;

-- Add automation_triggered column to email_queue
ALTER TABLE email_queue 
ADD COLUMN automation_triggered TINYINT(1) DEFAULT 0 COMMENT 'Whether this email was triggered by automation',
ADD INDEX idx_automation_triggered (automation_triggered);

-- Add outreach status columns to target_domains if they don't exist
ALTER TABLE target_domains 
ADD COLUMN email_search_status ENUM('pending', 'searching', 'found', 'not_found', 'failed') DEFAULT 'pending',
ADD COLUMN email_search_attempts INT DEFAULT 0,
ADD COLUMN last_email_search_at TIMESTAMP NULL,
ADD COLUMN email_search_error TEXT NULL,
ADD COLUMN next_retry_at TIMESTAMP NULL,
ADD INDEX idx_email_search_status (email_search_status),
ADD INDEX idx_next_retry (next_retry_at);

-- Update existing domains to have proper email search status
UPDATE target_domains 
SET email_search_status = 'found' 
WHERE contact_email IS NOT NULL 
AND contact_email != '' 
AND email_search_status = 'pending';

-- Create automation_logs table for detailed tracking
CREATE TABLE IF NOT EXISTS automation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    campaign_id INT NOT NULL,
    action ENUM('domain_processed', 'email_queued', 'email_sent', 'automation_triggered') NOT NULL,
    trigger_type ENUM('immediate', 'background', 'manual') DEFAULT 'background',
    sender_email VARCHAR(255),
    recipient_email VARCHAR(255),
    queue_id INT NULL,
    success BOOLEAN DEFAULT TRUE,
    error_message TEXT NULL,
    processing_time_ms INT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_domain_id (domain_id),
    INDEX idx_campaign_id (campaign_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);

-- Add automation settings to system_settings if they don't exist
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
('enable_automated_outreach', 'yes'),
('automated_outreach_batch_size', '15'),
('automation_immediate_send', 'yes'),
('automation_quality_threshold', '70'),
('automation_max_emails_per_hour', '50');

-- Update campaigns table to track automation statistics
ALTER TABLE campaigns 
ADD COLUMN automated_emails_sent INT DEFAULT 0,
ADD COLUMN last_automation_run TIMESTAMP NULL,
ADD INDEX idx_last_automation_run (last_automation_run);

-- Create automation_statistics table for reporting
CREATE TABLE IF NOT EXISTS automation_statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    campaign_id INT NULL,
    domains_processed INT DEFAULT 0,
    emails_queued INT DEFAULT 0,
    emails_sent INT DEFAULT 0,
    success_rate DECIMAL(5,2) DEFAULT 0.00,
    processing_time_total_ms INT DEFAULT 0,
    errors_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_date_campaign (date, campaign_id),
    INDEX idx_date (date),
    INDEX idx_campaign_id (campaign_id),
    
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);

-- Add status tracking to outreach_emails table
ALTER TABLE outreach_emails 
ADD COLUMN automation_triggered TINYINT(1) DEFAULT 0,
ADD COLUMN sender_rotation_id INT NULL,
ADD INDEX idx_automation_triggered (automation_triggered);

COMMIT;