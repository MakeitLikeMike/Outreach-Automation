-- ============================================================================
-- OUTREACH AUTOMATION SYSTEM - MASTER DATABASE SETUP
-- ============================================================================
-- Complete database setup for Outreach Operation Automation System
-- This file contains all database schema, migrations, and seed data
-- Run this file on a fresh MySQL database to set up the complete system
--
-- Version: 1.0 (Consolidated from all migrations)
-- Created: 2025-08-07
-- ============================================================================

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

-- Target domains table (enhanced with email search functionality)
CREATE TABLE IF NOT EXISTS target_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    contact_email VARCHAR(255),
    email_search_status ENUM('pending', 'searching', 'found', 'not_found', 'failed') DEFAULT 'pending',
    email_search_attempts INT DEFAULT 0,
    last_email_search_at TIMESTAMP NULL,
    next_retry_at TIMESTAMP NULL,
    email_search_error TEXT,
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
    INDEX idx_domain_rating (domain_rating),
    INDEX idx_email_search_status (email_search_status),
    INDEX idx_next_retry (next_retry_at),
    INDEX idx_email_search_pending (status, email_search_status)
);

-- Outreach emails table (enhanced with reply classification)
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
-- EMAIL SEARCH AUTOMATION TABLES
-- ============================================================================

-- Email search queue for background processing
CREATE TABLE IF NOT EXISTS email_search_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    campaign_id INT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    attempt_count INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    last_attempt_at TIMESTAMP NULL,
    next_retry_at TIMESTAMP NULL,
    error_message TEXT,
    api_response JSON,
    processing_started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_status_priority (status, priority),
    INDEX idx_next_retry (next_retry_at),
    INDEX idx_processing (status, processing_started_at),
    INDEX idx_campaign_domain (campaign_id, domain_id)
);

-- Email search logs for detailed tracking
CREATE TABLE IF NOT EXISTS email_search_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    api_service ENUM('tomba', 'hunter', 'clearbit', 'manual') DEFAULT 'tomba',
    endpoint VARCHAR(255),
    request_data JSON,
    response_data JSON,
    response_code INT,
    execution_time_ms INT,
    emails_found INT DEFAULT 0,
    success BOOLEAN DEFAULT FALSE,
    error_message TEXT,
    credits_used DECIMAL(10,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE,
    INDEX idx_domain_api (domain_id, api_service),
    INDEX idx_success_date (success, created_at),
    INDEX idx_api_service (api_service, created_at)
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

-- Email queue table for rate limiting and scheduling (enhanced with retry logic)
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
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    next_retry_at TIMESTAMP NULL,
    scheduled_at TIMESTAMP NOT NULL,
    processed_at TIMESTAMP NULL,
    gmail_message_id VARCHAR(255),
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE SET NULL,
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_sender_date (sender_email, scheduled_at),
    INDEX idx_campaign (campaign_id),
    INDEX idx_retry_schedule (status, next_retry_at)
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
-- SYSTEM AND MONITORING TABLES
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

-- Rate limiting tracking table (enhanced for multiple APIs)
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,  -- email or API key identifier
    service ENUM('email', 'tomba', 'dataforseo', 'chatgpt') NOT NULL,
    limit_type ENUM('minute', 'hourly', 'daily', 'monthly') NOT NULL,
    current_count INT DEFAULT 0,
    limit_value INT NOT NULL,
    reset_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_identifier_service_type (identifier, service, limit_type),
    INDEX idx_reset_time (reset_at),
    INDEX idx_service (service)
);

-- API usage tracking for monitoring
CREATE TABLE IF NOT EXISTS api_usage_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_service ENUM('tomba', 'dataforseo', 'chatgpt', 'gmail') NOT NULL,
    endpoint VARCHAR(255),
    operation_type VARCHAR(100),
    request_count INT DEFAULT 1,
    success_count INT DEFAULT 0,
    error_count INT DEFAULT 0,
    total_execution_time_ms INT DEFAULT 0,
    avg_response_time_ms DECIMAL(10,2) DEFAULT 0,
    credits_used DECIMAL(10,4) DEFAULT 0,
    date DATE NOT NULL,
    hour TINYINT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_api_date_hour (api_service, endpoint, operation_type, date, hour),
    INDEX idx_service_date (api_service, date),
    INDEX idx_operation_date (operation_type, date)
);

-- Background jobs table for processing
CREATE TABLE IF NOT EXISTS background_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_type ENUM('process_queue', 'fetch_replies', 'process_forwards', 'update_metrics', 'email_search', 'domain_analysis') NOT NULL,
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

-- API logs table (enhanced with status_code column)
CREATE TABLE IF NOT EXISTS api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_service ENUM('dataforseo', 'tomba', 'mailgun', 'gmail', 'verification', 'chatgpt') NOT NULL,
    endpoint VARCHAR(255),
    method ENUM('GET', 'POST', 'PUT', 'DELETE') DEFAULT 'GET',
    request_data JSON,
    response_data JSON,
    response_code INT,
    status_code INT,
    execution_time FLOAT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service_date (api_service, created_at),
    INDEX idx_response_code (response_code),
    INDEX idx_status_code (status_code)
);

-- ============================================================================
-- CHATGPT INTEGRATION TABLES
-- ============================================================================

-- Reply classifications for ChatGPT integration
CREATE TABLE IF NOT EXISTS reply_classifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    outreach_email_id INT,
    email_reply_id INT,
    original_reply_text TEXT NOT NULL,
    classification ENUM('interested', 'not_interested', 'auto_reply', 'spam', 'unclear') NOT NULL,
    confidence_score DECIMAL(3,2) NOT NULL,
    reasoning TEXT,
    chatgpt_model VARCHAR(50) DEFAULT 'gpt-3.5-turbo',
    tokens_used INT,
    processing_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (outreach_email_id) REFERENCES outreach_emails(id) ON DELETE CASCADE,
    FOREIGN KEY (email_reply_id) REFERENCES email_replies(id) ON DELETE CASCADE,
    INDEX idx_classification (classification),
    INDEX idx_confidence (confidence_score),
    INDEX idx_created (created_at)
);

-- ============================================================================
-- FOREIGN KEY CONSTRAINTS
-- ============================================================================

-- Add foreign key constraint for email template in campaigns
ALTER TABLE campaigns ADD CONSTRAINT fk_campaign_template 
    FOREIGN KEY (email_template_id) REFERENCES email_templates(id) ON DELETE SET NULL;

-- ============================================================================
-- STORED PROCEDURES FOR EMAIL SEARCH AUTOMATION
-- ============================================================================

DELIMITER //

-- Procedure to queue a domain for email search
CREATE PROCEDURE IF NOT EXISTS QueueDomainForEmailSearch(
    IN p_domain_id INT,
    IN p_priority ENUM('high', 'medium', 'low')
)
BEGIN
    DECLARE v_domain VARCHAR(255);
    DECLARE v_campaign_id INT;
    
    -- Get domain and campaign info
    SELECT td.domain, td.campaign_id 
    INTO v_domain, v_campaign_id 
    FROM target_domains td 
    WHERE td.id = p_domain_id;
    
    -- Insert into queue if not already exists
    INSERT IGNORE INTO email_search_queue (domain_id, domain, campaign_id, priority)
    VALUES (p_domain_id, v_domain, v_campaign_id, p_priority);
    
    -- Update target domain status
    UPDATE target_domains 
    SET email_search_status = 'pending' 
    WHERE id = p_domain_id;
END //

-- Procedure to get next batch for email search processing
CREATE PROCEDURE IF NOT EXISTS GetEmailSearchBatch(
    IN p_batch_size INT DEFAULT 10
)
BEGIN
    -- Get next batch of domains to process
    SELECT esq.*, td.domain, td.campaign_id
    FROM email_search_queue esq
    JOIN target_domains td ON esq.domain_id = td.id
    WHERE esq.status = 'pending' 
       AND (esq.next_retry_at IS NULL OR esq.next_retry_at <= NOW())
       AND esq.attempt_count < esq.max_attempts
    ORDER BY 
        CASE esq.priority 
            WHEN 'high' THEN 1 
            WHEN 'medium' THEN 2 
            WHEN 'low' THEN 3 
        END,
        esq.created_at ASC
    LIMIT p_batch_size
    FOR UPDATE;
    
    -- Mark selected items as processing
    UPDATE email_search_queue esq
    JOIN target_domains td ON esq.domain_id = td.id
    SET esq.status = 'processing', 
        esq.processing_started_at = NOW(),
        td.email_search_status = 'searching'
    WHERE esq.status = 'pending' 
       AND (esq.next_retry_at IS NULL OR esq.next_retry_at <= NOW())
       AND esq.attempt_count < esq.max_attempts
    ORDER BY 
        CASE esq.priority 
            WHEN 'high' THEN 1 
            WHEN 'medium' THEN 2 
            WHEN 'low' THEN 3 
        END,
        esq.created_at ASC
    LIMIT p_batch_size;
END //

-- Procedure to update email search result
CREATE PROCEDURE IF NOT EXISTS UpdateEmailSearchResult(
    IN p_domain_id INT,
    IN p_success BOOLEAN,
    IN p_email VARCHAR(255),
    IN p_error_message TEXT,
    IN p_api_response JSON
)
BEGIN
    DECLARE v_new_status ENUM('pending', 'searching', 'found', 'not_found', 'failed');
    DECLARE v_queue_status ENUM('pending', 'processing', 'completed', 'failed');
    
    -- Determine new status based on success
    IF p_success THEN
        IF p_email IS NOT NULL AND p_email != '' THEN
            SET v_new_status = 'found';
            SET v_queue_status = 'completed';
        ELSE
            SET v_new_status = 'not_found';
            SET v_queue_status = 'completed';
        END IF;
    ELSE
        SET v_new_status = 'failed';
        SET v_queue_status = 'failed';
    END IF;
    
    -- Update target domain
    UPDATE target_domains 
    SET email_search_status = v_new_status,
        email_search_attempts = email_search_attempts + 1,
        last_email_search_at = NOW(),
        email_search_error = p_error_message,
        contact_email = COALESCE(p_email, contact_email)
    WHERE id = p_domain_id;
    
    -- Update queue
    UPDATE email_search_queue 
    SET status = v_queue_status,
        completed_at = NOW(),
        attempt_count = attempt_count + 1,
        last_attempt_at = NOW(),
        error_message = p_error_message,
        api_response = p_api_response,
        next_retry_at = CASE 
            WHEN v_queue_status = 'failed' AND attempt_count < max_attempts 
            THEN DATE_ADD(NOW(), INTERVAL POWER(2, attempt_count) HOUR)
            ELSE NULL 
        END
    WHERE domain_id = p_domain_id AND status = 'processing';
END //

DELIMITER ;

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

-- Email search performance view
CREATE OR REPLACE VIEW email_search_performance AS
SELECT 
    DATE(esl.created_at) as search_date,
    esl.api_service,
    COUNT(*) as total_searches,
    SUM(CASE WHEN esl.success = 1 THEN 1 ELSE 0 END) as successful_searches,
    SUM(esl.emails_found) as total_emails_found,
    AVG(esl.execution_time_ms) as avg_execution_time_ms,
    SUM(esl.credits_used) as total_credits_used,
    ROUND(SUM(CASE WHEN esl.success = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as success_rate
FROM email_search_logs esl
GROUP BY DATE(esl.created_at), esl.api_service;

-- Domains needing email search view
CREATE OR REPLACE VIEW domains_needing_email_search AS
SELECT 
    td.id,
    td.domain,
    td.campaign_id,
    c.name as campaign_name,
    td.email_search_status,
    td.email_search_attempts,
    td.last_email_search_at,
    td.next_retry_at,
    td.email_search_error,
    CASE 
        WHEN td.email_search_status = 'pending' THEN 'Never searched'
        WHEN td.email_search_status = 'failed' AND td.next_retry_at <= NOW() THEN 'Ready for retry'
        WHEN td.email_search_status = 'failed' AND td.next_retry_at > NOW() THEN CONCAT('Retry at ', td.next_retry_at)
        ELSE 'No action needed'
    END as action_needed
FROM target_domains td
JOIN campaigns c ON td.campaign_id = c.id
WHERE td.email_search_status IN ('pending', 'failed')
   OR (td.email_search_status = 'failed' AND td.next_retry_at <= NOW());

-- ============================================================================
-- DEFAULT SYSTEM SETTINGS
-- ============================================================================

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
-- API Settings
('dataforseo_login', '', 'DataForSEO API login username'),
('dataforseo_password', '', 'DataForSEO API password'),
('tomba_api_key', '', 'Tomba.io API key for email finding'),
('mailgun_api_key', '', 'Mailgun API key for email sending'),
('mailgun_domain', '', 'Mailgun domain for email sending'),
('chatgpt_api_key', '', 'OpenAI ChatGPT API key for reply classification'),
('chatgpt_model', 'gpt-3.5-turbo', 'ChatGPT model to use for classifications'),

-- Gmail Integration Settings
('gmail_client_id', '', 'Gmail OAuth Client ID'),
('gmail_client_secret', '', 'Gmail OAuth Client Secret'),
('gmail_redirect_uri', '', 'Gmail OAuth Redirect URI'),
('enable_auto_reply_monitoring', 'yes', 'Enable automatic reply monitoring'),
('reply_check_interval_minutes', '15', 'How often to check for new replies'),
('auto_forward_interested_replies', 'yes', 'Automatically forward interested replies'),

-- Email Search Settings
('enable_immediate_email_search', 'yes', 'Trigger email search immediately when domain is approved'),
('email_search_batch_size', '10', 'Number of domains to process per batch'),
('email_search_retry_max_attempts', '3', 'Maximum retry attempts for failed email searches'),
('tomba_rate_limit_per_hour', '100', 'Maximum Tomba API calls per hour'),
('email_search_background_interval', '300', 'Background email search interval in seconds'),

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
('email_queue_max_retries', '3', 'Maximum retry attempts for failed emails'),

-- System Settings
('max_domains_per_campaign', '1000', 'Maximum domains allowed per campaign'),
('enable_auto_approval', 'no', 'Enable automatic domain approval based on quality filters'),
('system_timezone', 'UTC', 'System timezone for scheduling'),
('enable_background_processing', 'yes', 'Enable background job processing'),
('auto_classify_replies', 'yes', 'Automatically classify email replies using ChatGPT'),
('reply_classification_confidence_threshold', '0.70', 'Minimum confidence threshold for auto-classification')

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
 '{"sender_name": "Your name"}'),

('Guest Post Outreach Template',
 'Guest Post Opportunity for {domain}',
 'Hi,

I''m {sender_name} from {company}. I''ve been following your work on {domain} and really appreciate your insights on {topic}.

I''d love to contribute a guest post to your site. I have some unique perspectives on {suggested_topic} that I think would be valuable for your audience.

Some potential topics I could write about:
- {topic_1}
- {topic_2}
- {topic_3}

All articles would be original, well-researched, and provide genuine value to your readers. I can also promote the content across my networks to drive additional traffic.

Would you be interested in seeing a detailed outline or sample?

Best regards,
{sender_name}
{company}
{website}',
 '{"domain": "Target domain", "sender_name": "Your name", "company": "Your company", "topic": "Relevant topic", "suggested_topic": "Main topic", "topic_1": "Topic idea 1", "topic_2": "Topic idea 2", "topic_3": "Topic idea 3", "website": "Your website"}');

-- ============================================================================
-- SAMPLE DATA FOR TESTING (Optional - uncomment if needed)
-- ============================================================================

/*
-- Sample campaign
INSERT INTO campaigns (name, description, status, keywords, target_countries, owner_email) VALUES
('Sample Marketing Campaign', 'Test campaign for marketing outreach', 'draft', 'marketing, digital, seo', 'US,UK,CA', 'admin@example.com');

-- Sample domains (using campaign ID 1)
INSERT INTO target_domains (campaign_id, domain, status, quality_score, domain_rating, organic_traffic) VALUES
(1, 'example-blog.com', 'pending', 75.5, 45, 25000),
(1, 'marketing-site.com', 'approved', 82.1, 52, 38000),
(1, 'tech-news.com', 'pending', 68.9, 38, 15000);
*/

-- ============================================================================
-- PERFORMANCE OPTIMIZATION INDEXES
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_outreach_status_date ON outreach_emails(status, sent_at);
CREATE INDEX IF NOT EXISTS idx_outreach_classification ON outreach_emails(reply_classification, reply_received_at);
CREATE INDEX IF NOT EXISTS idx_domains_quality ON target_domains(quality_score, domain_rating);
CREATE INDEX IF NOT EXISTS idx_domains_status_campaign ON target_domains(status, campaign_id);
CREATE INDEX IF NOT EXISTS idx_api_logs_service_date ON api_logs(api_service, created_at);
CREATE INDEX IF NOT EXISTS idx_email_queue_retry ON email_queue(status, next_retry_at);
CREATE INDEX IF NOT EXISTS idx_search_queue_priority ON email_search_queue(status, priority, created_at);

-- ============================================================================
-- COMPLETION MESSAGE
-- ============================================================================

SELECT 'Master database setup completed successfully!' as status,
       'All tables, indexes, views, stored procedures, and default data have been created.' as message,
       'Configure your API keys in the system settings and start using the application.' as next_steps,
       '✅ Core outreach system ready' as core_system,
       '✅ Email search automation enabled' as email_search,
       '✅ Gmail integration configured' as gmail_integration,
       '✅ ChatGPT reply classification ready' as chatgpt_integration,
       '✅ Rate limiting and monitoring active' as monitoring,
       '✅ Background processing configured' as background_jobs;