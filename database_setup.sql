-- Guest Post Outreach Automation System Database Schema
CREATE DATABASE IF NOT EXISTS outreach_automation;
USE outreach_automation;

-- Campaigns table
CREATE TABLE campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    competitor_urls TEXT,
    status ENUM('active', 'paused', 'completed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Target domains table
CREATE TABLE target_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT,
    domain VARCHAR(255) NOT NULL,
    referring_domains INT DEFAULT 0,
    organic_traffic INT DEFAULT 0,
    domain_rating INT DEFAULT 0,
    ranking_keywords INT DEFAULT 0,
    quality_score DECIMAL(3,2) DEFAULT 0.00,
    status ENUM('pending', 'approved', 'rejected', 'contacted') DEFAULT 'pending',
    contact_email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_campaign_status (campaign_id, status),
    INDEX idx_domain (domain)
);

-- Email templates table
CREATE TABLE email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Outreach emails table
CREATE TABLE outreach_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT,
    domain_id INT,
    template_id INT,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('draft', 'sent', 'replied', 'bounced', 'failed') DEFAULT 'draft',
    sent_at TIMESTAMP NULL,
    reply_received_at TIMESTAMP NULL,
    reply_content TEXT,
    reply_classification ENUM('interested', 'not_interested', 'auto_reply', 'spam') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES email_templates(id),
    INDEX idx_campaign_status (campaign_id, status),
    INDEX idx_reply_classification (reply_classification)
);

-- API logs table
CREATE TABLE api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_service ENUM('dataforseo', 'tomba', 'mailgun', 'gmail') NOT NULL,
    endpoint VARCHAR(255),
    request_data TEXT,
    response_data TEXT,
    status_code INT,
    credits_used INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_date (api_service, created_at)
);

-- System settings table
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default email template
INSERT INTO email_templates (name, subject, body, is_default) VALUES 
('Default Outreach', 'Guest Post Collaboration Opportunity - {DOMAIN}', 
'Hi there,

I hope this email finds you well! I''ve been following {DOMAIN} and really appreciate the quality content you publish, especially your recent posts on {TOPIC_AREA}.

I''m reaching out because I believe we could create some valuable content together. I work with high-quality websites in the {INDUSTRY} space and would love to discuss a potential guest post collaboration.

I can provide:
- Original, well-researched content tailored to your audience
- Professional writing that matches your site''s tone and style  
- Relevant topics that provide real value to your readers
- Proper attribution and links as needed

Would you be interested in exploring this opportunity? I''d be happy to share some topic ideas or previous work samples.

Looking forward to hearing from you!

Best regards,
{SENDER_NAME}', 
TRUE);

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('dataforseo_api_key', '', 'DataForSEO API Key'),
('tomba_api_key', '', 'Tomba.io API Key'),
('mailgun_api_key', '', 'Mailgun API Key'),
('mailgun_domain', '', 'Mailgun Domain'),
('sender_name', 'Outreach Team', 'Default sender name for emails'),
('sender_email', '', 'Default sender email address'),
('min_domain_rating', '30', 'Minimum domain rating threshold'),
('min_organic_traffic', '5000', 'Minimum organic traffic threshold'),
('min_referring_domains', '100', 'Minimum referring domains threshold');