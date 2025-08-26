-- Complete Database Setup for Outreach Automation System
-- This file contains all database schema and initial data setup
-- Run this file on a fresh MySQL database to set up the complete system

USE outreach_automation;

-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS outreach_automation;
USE outreach_automation;

-- ============================================================================
-- CORE TABLES
-- ============================================================================

-- Campaigns table
CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('draft', 'active', 'paused', 'completed') DEFAULT 'draft',
    keywords TEXT,
    target_countries TEXT,
    quality_filters JSON,
    owner_email VARCHAR(255),
    email_template_id INT,
    forwarded_emails INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_owner (owner_email)
);

-- Email templates table
CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    variables JSON,
    is_default VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Target domains table
CREATE TABLE IF NOT EXISTS target_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    contact_email VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected', 'contacted', 'replied') DEFAULT 'pending',
    quality_score FLOAT DEFAULT 0,
    domain_rating INT DEFAULT 0,
    organic_traffic INT DEFAULT 0,
    ranking_keywords INT DEFAULT 0,
    status_pages_200 INT DEFAULT 0,
    homepage_traffic_percentage DECIMAL(5,2) DEFAULT 0.00,
    backlink_diversity_score DECIMAL(3,2) DEFAULT 0.00,
    rejection_reason TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_campaign_status (campaign_id, status),
    INDEX idx_domain (domain),
    INDEX idx_quality_score (quality_score),
    INDEX idx_domain_rating (domain_rating)
);

-- Outreach emails table
CREATE TABLE IF NOT EXISTS outreach_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    domain_id INT NOT NULL,
    template_id INT,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    sender_email VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    status ENUM('draft', 'queued', 'sent', 'bounced', 'replied', 'failed') DEFAULT 'draft',
    sent_at TIMESTAMP NULL,
    reply_received_at TIMESTAMP NULL,
    reply_subject VARCHAR(500),
    reply_body TEXT,
    reply_classification ENUM('interested', 'not_interested', 'auto_reply', 'spam', 'unclear'),
    gmail_message_id VARCHAR(255),
    thread_id VARCHAR(255),
    classification_confidence DECIMAL(3,2),
    classification_reasoning TEXT,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
    INDEX idx_campaign (campaign_id),
    INDEX idx_status_sent (status, sent_at),
    INDEX idx_reply_classification (reply_classification, reply_received_at),
    INDEX idx_sender_email (sender_email)
);

-- ============================================================================
-- GMAIL INTEGRATION TABLES
-- ============================================================================

-- Gmail OAuth tokens table
CREATE TABLE IF NOT EXISTS gmail_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
);

-- Email queue table for rate limiting and scheduling
CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    domain_id INT,
    template_id INT,
    sender_email VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('queued', 'processing', 'sent', 'failed', 'paused', 'cancelled') DEFAULT 'queued',
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    scheduled_at TIMESTAMP NOT NULL,
    processed_at TIMESTAMP NULL,
    gmail_message_id VARCHAR(255),
    error_message TEXT,
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE SET NULL,
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_sender_date (sender_email, scheduled_at),
    INDEX idx_campaign (campaign_id)
);

-- Email replies table (for replies not linked to outreach emails)
CREATE TABLE IF NOT EXISTS email_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_email VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500),
    body TEXT,
    received_at TIMESTAMP NOT NULL,
    classification ENUM('interested', 'not_interested', 'auto_reply', 'spam', 'unclear') NOT NULL,
    confidence DECIMAL(3,2) NOT NULL,
    reasoning TEXT,
    gmail_message_id VARCHAR(255),
    thread_id VARCHAR(255),
    processed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_classification (classification),
    INDEX idx_received (received_at),
    INDEX idx_processed (processed)
);

-- Forwarding queue table
CREATE TABLE IF NOT EXISTS forwarding_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reply_id INT NOT NULL,
    campaign_owner_email VARCHAR(255) NOT NULL,
    thread_id VARCHAR(255),
    domain VARCHAR(255),
    additional_notes TEXT,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    gmail_message_id VARCHAR(255),
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
);

-- Forwarding log table
CREATE TABLE IF NOT EXISTS forwarding_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reply_id INT NOT NULL,
    campaign_owner_email VARCHAR(255) NOT NULL,
    domain VARCHAR(255),
    forward_message_id VARCHAR(255),
    forwarded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_forwarded_date (forwarded_at),
    INDEX idx_owner_email (campaign_owner_email)
);

-- ============================================================================
-- SYSTEM TABLES
-- ============================================================================

-- Email verification table
CREATE TABLE IF NOT EXISTS email_verification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    verification_token VARCHAR(255) NOT NULL,
    status ENUM('pending', 'verified', 'failed') DEFAULT 'pending',
    verification_type ENUM('sender', 'owner', 'general') DEFAULT 'general',
    verified_at TIMESTAMP NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (verification_token),
    INDEX idx_status (status)
);

-- Rate limiting tracking table
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_email VARCHAR(255) NOT NULL,
    limit_type ENUM('hourly', 'daily', 'monthly') NOT NULL,
    current_count INT DEFAULT 0,
    limit_value INT NOT NULL,
    reset_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_sender_type (sender_email, limit_type),
    INDEX idx_reset_time (reset_at)
);

-- Background jobs table for processing
CREATE TABLE IF NOT EXISTS background_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_type ENUM('process_queue', 'fetch_replies', 'process_forwards', 'update_metrics') NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    parameters JSON,
    result JSON,
    error_message TEXT,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_status (job_type, status),
    INDEX idx_created (created_at)
);

-- System settings table
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
);

-- API logs table
CREATE TABLE IF NOT EXISTS api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_service ENUM('dataforseo', 'tomba', 'mailgun', 'gmail', 'verification') NOT NULL,
    endpoint VARCHAR(255),
    method ENUM('GET', 'POST', 'PUT', 'DELETE') DEFAULT 'GET',
    request_data JSON,
    response_data JSON,
    response_code INT,
    status_code INT,
    execution_time FLOAT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service_date (api_service, created_at),
    INDEX idx_response_code (response_code)
);

-- ============================================================================
-- FOREIGN KEY CONSTRAINTS
-- ============================================================================

-- Add foreign key constraint for email template in campaigns
ALTER TABLE campaigns ADD CONSTRAINT fk_campaign_template 
    FOREIGN KEY (email_template_id) REFERENCES email_templates(id) ON DELETE SET NULL;

-- ============================================================================
-- PERFORMANCE INDEXES
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_outreach_status_date ON outreach_emails(status, sent_at);
CREATE INDEX IF NOT EXISTS idx_outreach_classification ON outreach_emails(reply_classification, reply_received_at);
CREATE INDEX IF NOT EXISTS idx_domains_quality ON target_domains(quality_score, domain_rating);
CREATE INDEX IF NOT EXISTS idx_domains_status_campaign ON target_domains(status, campaign_id);

-- ============================================================================
-- DATABASE VIEWS
-- ============================================================================

-- Campaign performance view
CREATE OR REPLACE VIEW campaign_performance AS
SELECT 
    c.id,
    c.name,
    c.status,
    c.owner_email,
    COUNT(DISTINCT td.id) as total_domains,
    COUNT(DISTINCT CASE WHEN td.status = 'approved' THEN td.id END) as approved_domains,
    COUNT(DISTINCT CASE WHEN td.status = 'contacted' THEN td.id END) as contacted_domains,
    COUNT(DISTINCT oe.id) as emails_sent,
    COUNT(DISTINCT CASE WHEN oe.status = 'replied' THEN oe.id END) as replies_received,
    COUNT(DISTINCT CASE WHEN oe.reply_classification = 'interested' THEN oe.id END) as interested_replies,
    c.forwarded_emails,
    AVG(td.quality_score) as avg_quality_score,
    c.created_at,
    c.updated_at
FROM campaigns c
LEFT JOIN target_domains td ON c.id = td.campaign_id
LEFT JOIN outreach_emails oe ON c.id = oe.campaign_id
GROUP BY c.id;

-- Email performance view
CREATE OR REPLACE VIEW email_performance AS
SELECT 
    DATE(oe.sent_at) as send_date,
    oe.campaign_id,
    c.name as campaign_name,
    COUNT(*) as emails_sent,
    COUNT(CASE WHEN oe.status = 'replied' THEN 1 END) as replies_received,
    COUNT(CASE WHEN oe.reply_classification = 'interested' THEN 1 END) as interested_replies,
    COUNT(CASE WHEN oe.reply_classification = 'not_interested' THEN 1 END) as not_interested_replies,
    COUNT(CASE WHEN oe.status = 'bounced' THEN 1 END) as bounced_emails,
    ROUND(COUNT(CASE WHEN oe.status = 'replied' THEN 1 END) * 100.0 / COUNT(*), 2) as response_rate,
    ROUND(COUNT(CASE WHEN oe.reply_classification = 'interested' THEN 1 END) * 100.0 / COUNT(CASE WHEN oe.status = 'replied' THEN 1 END), 2) as interest_rate
FROM outreach_emails oe
JOIN campaigns c ON oe.campaign_id = c.id
WHERE oe.sent_at IS NOT NULL
GROUP BY DATE(oe.sent_at), oe.campaign_id, c.name;

-- ============================================================================
-- DEFAULT SYSTEM SETTINGS
-- ============================================================================

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
-- API Settings
('dataforseo_username', '', 'DataForSEO API username'),
('dataforseo_password', '', 'DataForSEO API password'),
('tomba_api_key', '', 'Tomba.io API key for email finding'),
('mailgun_api_key', '', 'Mailgun API key for email sending'),
('mailgun_domain', '', 'Mailgun domain for email sending'),

-- Gmail Integration Settings
('gmail_client_id', '', 'Gmail OAuth Client ID'),
('gmail_client_secret', '', 'Gmail OAuth Client Secret'),
('gmail_redirect_uri', '', 'Gmail OAuth Redirect URI'),
('enable_auto_reply_monitoring', 'yes', 'Enable automatic reply monitoring'),
('reply_check_interval_minutes', '15', 'How often to check for new replies'),
('auto_forward_interested_replies', 'yes', 'Automatically forward interested replies'),

-- Quality Filter Settings
('min_domain_rating', '20', 'Minimum domain rating for approval'),
('min_organic_traffic', '1000', 'Minimum organic traffic for approval'),
('min_ranking_keywords', '100', 'Minimum ranking keywords threshold'),
('min_status_200_pages', '80', 'Minimum percentage of 200-status pages'),
('max_homepage_traffic_percentage', '70', 'Maximum percentage of traffic to homepage only'),
('min_quality_score', '60', 'Minimum overall quality score for approval'),

-- Email Settings
('email_rate_limit_per_hour', '30', 'Maximum emails per sender per hour'),
('enable_email_verification', 'no', 'Require email verification for senders'),
('default_email_delay_minutes', '15', 'Default delay between emails in minutes'),

-- System Settings
('max_domains_per_campaign', '1000', 'Maximum domains allowed per campaign'),
('enable_auto_approval', 'no', 'Enable automatic domain approval based on quality filters'),
('system_timezone', 'UTC', 'System timezone for scheduling'),
('enable_background_processing', 'yes', 'Enable background job processing')

ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================================
-- DEFAULT EMAIL TEMPLATES
-- ============================================================================

INSERT INTO email_templates (name, subject, body, variables) VALUES
('Default Outreach Template', 
 'Partnership Opportunity with {domain}', 
 'Hi there,

I hope this email finds you well. I came across {domain} and was impressed by your content, particularly your work in {industry}.

I''m reaching out because I believe there might be a great opportunity for us to collaborate. We specialize in {our_service} and think our audiences would benefit from working together.

Would you be interested in exploring a potential partnership? I''d love to discuss how we can create value for both our communities.

Looking forward to hearing from you!

Best regards,
{sender_name}
{sender_email}',
 '{"domain": "Target domain name", "industry": "Target industry", "our_service": "Your service description", "sender_name": "Your name", "sender_email": "Your email"}'),

('Follow-up Template',
 'Following up on our partnership opportunity',
 'Hi,

I wanted to follow up on my previous email about a potential partnership between our companies.

I understand you''re probably busy, but I genuinely believe we could create something valuable together. 

If you''re interested, I''d be happy to schedule a brief call to discuss the details.

Thanks for your time!

Best,
{sender_name}',
 '{"sender_name": "Your name"}');

-- ============================================================================
-- COMPLETION MESSAGE
-- ============================================================================

SELECT 'Database setup completed successfully!' as status,
       'All tables, indexes, views, and default data have been created.' as message,
       'You can now configure your API keys in the system settings and start using the application.' as next_steps;