-- ChatGPT Integration Database Tables
-- Add AI-powered domain analysis capabilities to the outreach system

-- Domain AI Analysis Table
CREATE TABLE IF NOT EXISTS domain_ai_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    analysis_type ENUM('comprehensive', 'guest_post_suitability', 'competitor_strategy', 'content_quality', 'audience_analysis') NOT NULL,
    structured_data JSON,
    raw_analysis TEXT,
    overall_score INT DEFAULT 0,
    recommendations TEXT,
    insights TEXT,
    tokens_used INT DEFAULT 0,
    processing_time_ms INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain_type (domain, analysis_type),
    INDEX idx_created_date (created_at),
    INDEX idx_score (overall_score),
    UNIQUE KEY unique_domain_analysis (domain, analysis_type)
);

-- ChatGPT API Usage Tracking
CREATE TABLE IF NOT EXISTS chatgpt_api_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(100) NOT NULL,
    model VARCHAR(50) NOT NULL,
    prompt_tokens INT NOT NULL,
    completion_tokens INT NOT NULL,
    total_tokens INT NOT NULL,
    cost_estimate DECIMAL(10,6) DEFAULT 0.000000,
    request_type ENUM('domain_analysis', 'batch_analysis', 'test_connection', 'prototype') NOT NULL,
    domain VARCHAR(255),
    success BOOLEAN DEFAULT TRUE,
    error_message TEXT,
    response_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_type (created_at, request_type),
    INDEX idx_domain (domain),
    INDEX idx_cost (cost_estimate)
);

-- AI Analysis Queue (for batch processing)
CREATE TABLE IF NOT EXISTS ai_analysis_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    analysis_type ENUM('comprehensive', 'guest_post_suitability', 'competitor_strategy', 'content_quality', 'audience_analysis') NOT NULL,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    status ENUM('queued', 'processing', 'completed', 'failed') DEFAULT 'queued',
    campaign_id INT,
    domain_id INT,
    requested_by VARCHAR(100),
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    error_message TEXT,
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE SET NULL,
    INDEX idx_status_priority (status, priority, scheduled_at),
    INDEX idx_domain_type (domain, analysis_type)
);

-- Prototype Analysis Sessions (for testing and experimentation)
CREATE TABLE IF NOT EXISTS prototype_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_name VARCHAR(255) NOT NULL,
    description TEXT,
    domains_analyzed JSON,
    analysis_types JSON,
    results JSON,
    total_tokens_used INT DEFAULT 0,
    total_cost DECIMAL(10,6) DEFAULT 0.000000,
    success_rate DECIMAL(5,2) DEFAULT 0.00,
    avg_processing_time_ms INT DEFAULT 0,
    created_by VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_date (created_at),
    INDEX idx_created_by (created_by)
);

-- Add AI analysis columns to existing target_domains table
ALTER TABLE target_domains 
ADD COLUMN ai_analysis_status ENUM('pending', 'analyzing', 'completed', 'failed') DEFAULT 'pending',
ADD COLUMN ai_overall_score INT DEFAULT 0,
ADD COLUMN ai_guest_post_score INT DEFAULT 0,
ADD COLUMN ai_content_quality_score INT DEFAULT 0,
ADD COLUMN ai_audience_alignment_score INT DEFAULT 0,
ADD COLUMN ai_priority_level ENUM('low', 'medium', 'high') DEFAULT 'medium',
ADD COLUMN ai_recommendations TEXT,
ADD COLUMN ai_last_analyzed_at TIMESTAMP NULL,
ADD INDEX idx_ai_status (ai_analysis_status),
ADD INDEX idx_ai_scores (ai_overall_score, ai_guest_post_score);

-- Create view for AI-enhanced domain analysis
CREATE OR REPLACE VIEW enhanced_domain_analysis AS
SELECT 
    td.id,
    td.domain,
    td.campaign_id,
    c.name as campaign_name,
    td.status,
    td.quality_score as technical_score,
    td.domain_rating,
    td.organic_traffic,
    td.ai_overall_score,
    td.ai_guest_post_score,
    td.ai_content_quality_score,
    td.ai_audience_alignment_score,
    td.ai_priority_level,
    td.ai_analysis_status,
    td.contact_email,
    -- Combined scoring (technical + AI)
    ROUND((td.quality_score + COALESCE(td.ai_overall_score, 0)) / 2, 1) as combined_score,
    -- AI-enhanced priority calculation
    CASE 
        WHEN td.ai_overall_score >= 8 AND td.ai_guest_post_score >= 7 THEN 'high'
        WHEN td.ai_overall_score >= 6 AND td.ai_guest_post_score >= 5 THEN 'medium'
        ELSE 'low'
    END as ai_enhanced_priority,
    td.created_at,
    td.ai_last_analyzed_at
FROM target_domains td
LEFT JOIN campaigns c ON td.campaign_id = c.id;

-- Insert ChatGPT settings into system_settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('chatgpt_api_key', '', 'OpenAI ChatGPT API key for domain analysis'),
('chatgpt_model', 'gpt-4o-mini', 'ChatGPT model to use (gpt-4o-mini, gpt-4o, gpt-4-turbo)'),
('chatgpt_max_tokens', '2000', 'Maximum tokens per ChatGPT request'),
('chatgpt_temperature', '0.3', 'ChatGPT temperature (0.0-1.0) for consistency'),
('enable_ai_analysis', 'yes', 'Enable AI-powered domain analysis'),
('ai_analysis_auto_queue', 'no', 'Automatically queue domains for AI analysis'),
('ai_batch_size', '5', 'Number of domains to analyze in batch'),
('ai_analysis_timeout', '60', 'Timeout in seconds for AI analysis'),
('ai_cost_tracking', 'yes', 'Track estimated API costs'),
('prototype_mode', 'yes', 'Enable prototype/experimental features')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_domain_ai_combined ON target_domains(domain, ai_analysis_status, ai_overall_score);
CREATE INDEX IF NOT EXISTS idx_ai_analysis_domain_date ON domain_ai_analysis(domain, created_at);

-- Success message
SELECT 'ChatGPT Integration tables created successfully!' as status,
       'Added AI analysis capabilities, usage tracking, and prototype features.' as message,
       'Configure your OpenAI API key in settings to start using AI analysis.' as next_steps;