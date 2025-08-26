-- Background Jobs Table Migration
-- This migration adds the background_jobs table required for automated processing

-- Create background_jobs table if it doesn't exist
CREATE TABLE IF NOT EXISTS background_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(50) NOT NULL,
    campaign_id INT NULL,
    domain_id INT NULL,
    payload JSON NULL,
    priority INT DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_job_type (job_type),
    INDEX idx_campaign (campaign_id),
    INDEX idx_priority (priority),
    
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE
);

-- Add automation columns to campaigns table if they don't exist
ALTER TABLE campaigns 
ADD COLUMN IF NOT EXISTS pipeline_status ENUM('created', 'processing_domains', 'analyzing_quality', 'finding_emails', 'sending_outreach', 'monitoring_replies', 'completed') DEFAULT 'created';

ALTER TABLE campaigns 
ADD COLUMN IF NOT EXISTS processing_started_at TIMESTAMP NULL;

ALTER TABLE campaigns 
ADD COLUMN IF NOT EXISTS processing_completed_at TIMESTAMP NULL;

ALTER TABLE campaigns 
ADD COLUMN IF NOT EXISTS is_automated BOOLEAN DEFAULT FALSE;

ALTER TABLE campaigns 
ADD COLUMN IF NOT EXISTS auto_domain_analysis BOOLEAN DEFAULT TRUE;

ALTER TABLE campaigns 
ADD COLUMN IF NOT EXISTS auto_email_search BOOLEAN DEFAULT TRUE;

ALTER TABLE campaigns 
ADD COLUMN IF NOT EXISTS auto_reply_monitoring BOOLEAN DEFAULT TRUE;

ALTER TABLE campaigns 
ADD COLUMN IF NOT EXISTS auto_lead_forwarding BOOLEAN DEFAULT TRUE;

-- Add email search columns to target_domains if they don't exist
ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS email_search_status ENUM('pending', 'searching', 'found', 'failed', 'not_found') DEFAULT 'pending';

ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS email_search_attempts INT DEFAULT 0;

ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS last_email_search_at TIMESTAMP NULL;

ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS email_search_error TEXT NULL;

ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS next_retry_at TIMESTAMP NULL;

-- Insert initial system settings for automation
INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
('enable_automation_pipeline', 'yes', 'Enable automated campaign processing pipeline'),
('enable_automated_outreach', 'yes', 'Enable legacy automated outreach processing'),
('automated_outreach_batch_size', '15', 'Number of domains to process per automated batch'),
('background_processor_enabled', 'yes', 'Enable background job processing'),
('email_search_batch_size', '10', 'Number of domains to search for emails per batch'),
('immediate_processing_enabled', 'yes', 'Enable immediate processing after campaign creation');

-- Create index for better performance
CREATE INDEX IF NOT EXISTS idx_background_jobs_processing ON background_jobs (status, scheduled_at, priority);

SELECT 'Background jobs table and automation columns created successfully!' as status;