-- Automation System Database Migration
-- This migration adds the necessary tables and columns for the full automation system

-- Add automation settings to campaigns table
ALTER TABLE campaigns 
ADD COLUMN auto_domain_analysis TINYINT(1) DEFAULT 1 COMMENT 'Automatically analyze and score domains',
ADD COLUMN auto_email_search TINYINT(1) DEFAULT 1 COMMENT 'Automatically search for contact emails',
ADD COLUMN auto_reply_monitoring TINYINT(1) DEFAULT 1 COMMENT 'Automatically monitor and classify replies',
ADD COLUMN auto_lead_forwarding TINYINT(1) DEFAULT 1 COMMENT 'Automatically forward qualified leads',
ADD COLUMN pipeline_status ENUM('created', 'processing_domains', 'analyzing_quality', 'finding_emails', 'sending_outreach', 'monitoring_replies', 'completed') DEFAULT 'created',
ADD COLUMN total_domains_scraped INT DEFAULT 0,
ADD COLUMN domains_dr_above_30 INT DEFAULT 0,
ADD COLUMN approved_domains_count INT DEFAULT 0,
ADD COLUMN emails_found_count INT DEFAULT 0,
ADD COLUMN emails_sent_count INT DEFAULT 0,
ADD COLUMN replies_received_count INT DEFAULT 0,
ADD COLUMN qualified_leads_count INT DEFAULT 0,
ADD COLUMN processing_started_at TIMESTAMP NULL,
ADD COLUMN processing_completed_at TIMESTAMP NULL;

-- Create background jobs table for automation processing
CREATE TABLE IF NOT EXISTS background_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(50) NOT NULL COMMENT 'Type of background job',
    campaign_id INT NULL,
    domain_id INT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'retrying') DEFAULT 'pending',
    priority INT DEFAULT 0 COMMENT 'Higher numbers = higher priority',
    payload JSON NULL COMMENT 'Job parameters and data',
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    error_message TEXT NULL,
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_status_priority (status, priority DESC, scheduled_at),
    INDEX idx_campaign_id (campaign_id),
    INDEX idx_job_type (job_type),
    INDEX idx_scheduled_at (scheduled_at),
    
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE
);

-- Create campaign pipeline logs for tracking progress
CREATE TABLE IF NOT EXISTS campaign_pipeline_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    pipeline_stage VARCHAR(50) NOT NULL,
    status ENUM('started', 'completed', 'failed') NOT NULL,
    message TEXT NULL,
    data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_campaign_id (campaign_id),
    INDEX idx_pipeline_stage (pipeline_stage),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);

-- Create reply monitoring table
CREATE TABLE IF NOT EXISTS reply_monitoring (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    domain_id INT NOT NULL,
    outreach_email_id INT NULL,
    sender_email VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    reply_subject VARCHAR(500) NULL,
    reply_body TEXT NULL,
    reply_classification ENUM('positive', 'negative', 'neutral', 'spam', 'unprocessed') DEFAULT 'unprocessed',
    classification_confidence FLOAT DEFAULT 0,
    is_qualified_lead TINYINT(1) DEFAULT 0,
    forwarded_to_owner TINYINT(1) DEFAULT 0,
    gmail_thread_id VARCHAR(255) NULL,
    gmail_message_id VARCHAR(255) NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    forwarded_at TIMESTAMP NULL,
    
    INDEX idx_campaign_id (campaign_id),
    INDEX idx_classification (reply_classification),
    INDEX idx_qualified_leads (is_qualified_lead),
    INDEX idx_forwarded (forwarded_to_owner),
    INDEX idx_received_at (received_at),
    
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE
);

-- Create outreach emails tracking table (enhanced)
CREATE TABLE IF NOT EXISTS outreach_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    domain_id INT NOT NULL,
    template_id INT NULL,
    sender_email VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    personalization_data JSON NULL,
    send_status ENUM('draft', 'queued', 'sent', 'failed', 'bounced') DEFAULT 'draft',
    gmail_message_id VARCHAR(255) NULL,
    gmail_thread_id VARCHAR(255) NULL,
    sent_at TIMESTAMP NULL,
    opened_at TIMESTAMP NULL,
    replied_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_campaign_id (campaign_id),
    INDEX idx_send_status (send_status),
    INDEX idx_sent_at (sent_at),
    INDEX idx_sender_email (sender_email),
    
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL
);

-- Add automation-specific columns to target_domains table
ALTER TABLE target_domains
ADD COLUMN quality_analysis_completed TINYINT(1) DEFAULT 0,
ADD COLUMN email_search_completed TINYINT(1) DEFAULT 0,
ADD COLUMN outreach_sent TINYINT(1) DEFAULT 0,
ADD COLUMN dr_rating INT DEFAULT 0 COMMENT 'Domain Rating for filtering',
ADD COLUMN approval_reason TEXT NULL COMMENT 'Reason for approval/rejection',
ADD COLUMN email_search_attempts INT DEFAULT 0,
ADD COLUMN last_email_search_at TIMESTAMP NULL,
ADD COLUMN last_outreach_sent_at TIMESTAMP NULL;

-- Create indexes for better performance
CREATE INDEX idx_target_domains_dr ON target_domains(dr_rating);
CREATE INDEX idx_target_domains_quality_completed ON target_domains(quality_analysis_completed);
CREATE INDEX idx_target_domains_email_completed ON target_domains(email_search_completed);
CREATE INDEX idx_target_domains_outreach_sent ON target_domains(outreach_sent);

-- Insert initial background job types configuration
CREATE TABLE IF NOT EXISTS background_job_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(50) NOT NULL UNIQUE,
    description TEXT NULL,
    default_priority INT DEFAULT 0,
    max_concurrent INT DEFAULT 5 COMMENT 'Maximum concurrent jobs of this type',
    timeout_seconds INT DEFAULT 3600 COMMENT 'Job timeout in seconds',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default job types
INSERT INTO background_job_types (job_type, description, default_priority, max_concurrent, timeout_seconds) VALUES
('fetch_backlinks', 'Fetch competitor backlinks and create domain entries', 10, 3, 1800),
('analyze_domain_quality', 'Analyze domain quality and approve/reject', 8, 5, 600),
('search_contact_email', 'Search for domain contact email using Tomba', 6, 10, 300),
('generate_outreach_email', 'Generate personalized outreach email', 5, 8, 180),
('send_outreach_email', 'Send outreach email to contact', 7, 5, 120),
('monitor_gmail_replies', 'Monitor Gmail for replies and classify', 4, 3, 600),
('forward_qualified_lead', 'Forward qualified lead to campaign owner', 9, 2, 60);

-- Create campaign automation settings view
CREATE OR REPLACE VIEW campaign_automation_status AS
SELECT 
    c.id,
    c.name,
    c.pipeline_status,
    c.auto_domain_analysis,
    c.auto_email_search,
    c.auto_send,
    c.auto_reply_monitoring,
    c.auto_lead_forwarding,
    c.total_domains_scraped,
    c.domains_dr_above_30,
    c.approved_domains_count,
    c.emails_found_count,
    c.emails_sent_count,
    c.replies_received_count,
    c.qualified_leads_count,
    CASE 
        WHEN c.pipeline_status = 'completed' THEN 100
        WHEN c.pipeline_status = 'monitoring_replies' THEN 90
        WHEN c.pipeline_status = 'sending_outreach' THEN 75
        WHEN c.pipeline_status = 'finding_emails' THEN 60
        WHEN c.pipeline_status = 'analyzing_quality' THEN 45
        WHEN c.pipeline_status = 'processing_domains' THEN 25
        ELSE 0
    END as progress_percentage,
    TIMESTAMPDIFF(MINUTE, c.processing_started_at, COALESCE(c.processing_completed_at, NOW())) as processing_duration_minutes
FROM campaigns c
WHERE c.is_automated = 1;

-- Add triggers for automatic pipeline status updates
DELIMITER //

CREATE TRIGGER update_campaign_metrics_after_domain_insert
    AFTER INSERT ON target_domains
    FOR EACH ROW
BEGIN
    UPDATE campaigns 
    SET total_domains_scraped = total_domains_scraped + 1,
        domains_dr_above_30 = domains_dr_above_30 + CASE WHEN NEW.dr_rating > 30 THEN 1 ELSE 0 END
    WHERE id = NEW.campaign_id;
END//

CREATE TRIGGER update_campaign_metrics_after_domain_update
    AFTER UPDATE ON target_domains
    FOR EACH ROW
BEGIN
    -- Update approved domains count
    IF NEW.status = 'approved' AND OLD.status != 'approved' THEN
        UPDATE campaigns 
        SET approved_domains_count = approved_domains_count + 1
        WHERE id = NEW.campaign_id;
    ELSEIF OLD.status = 'approved' AND NEW.status != 'approved' THEN
        UPDATE campaigns 
        SET approved_domains_count = approved_domains_count - 1
        WHERE id = NEW.campaign_id;
    END IF;
    
    -- Update emails found count
    IF NEW.contact_email IS NOT NULL AND OLD.contact_email IS NULL THEN
        UPDATE campaigns 
        SET emails_found_count = emails_found_count + 1
        WHERE id = NEW.campaign_id;
    ELSEIF OLD.contact_email IS NOT NULL AND NEW.contact_email IS NULL THEN
        UPDATE campaigns 
        SET emails_found_count = emails_found_count - 1
        WHERE id = NEW.campaign_id;
    END IF;
END//

DELIMITER ;

-- Insert sample automation settings for existing campaigns
UPDATE campaigns 
SET auto_domain_analysis = 1,
    auto_email_search = 1,
    auto_reply_monitoring = 1,
    auto_lead_forwarding = 1,
    pipeline_status = 'created'
WHERE is_automated = 1;