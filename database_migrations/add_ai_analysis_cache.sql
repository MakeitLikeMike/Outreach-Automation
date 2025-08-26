-- Add AI Analysis Cache Table for Performance Optimization
-- This table stores ChatGPT analysis results to avoid repeated API calls

CREATE TABLE IF NOT EXISTS domain_ai_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    analysis_type VARCHAR(100) NOT NULL,
    structured_data JSON,
    raw_analysis TEXT,
    tokens_used INT DEFAULT 0,
    processing_time DECIMAL(10,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_domain_type (domain, analysis_type),
    INDEX idx_created_at (created_at),
    INDEX idx_analysis_type (analysis_type),
    
    UNIQUE KEY unique_domain_analysis (domain, analysis_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add generated outreach emails table
CREATE TABLE IF NOT EXISTS generated_outreach_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_domain VARCHAR(255) NOT NULL,
    user_website VARCHAR(255) NOT NULL,
    email_type ENUM('guest_post', 'collaboration', 'link_exchange', 'custom') NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    raw_email TEXT NOT NULL,
    personalization_notes JSON,
    tokens_used INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_target_domain (target_domain),
    INDEX idx_user_website (user_website),
    INDEX idx_email_type (email_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add performance metrics table
CREATE TABLE IF NOT EXISTS analysis_performance_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_type VARCHAR(100) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    processing_time DECIMAL(10,4) NOT NULL,
    tokens_used INT DEFAULT 0,
    cache_hit BOOLEAN DEFAULT FALSE,
    parallel_processing BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_analysis_type (analysis_type),
    INDEX idx_domain (domain),
    INDEX idx_created_at (created_at),
    INDEX idx_performance (processing_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample data for testing
INSERT IGNORE INTO domain_ai_analysis (domain, analysis_type, structured_data, raw_analysis, tokens_used) VALUES
('example.com', 'guest_post_suitability', '{"overall_score": 8, "recommendations": ["High quality content", "Good domain authority"]}', 'Sample analysis for example.com', 150),
('test-site.com', 'comprehensive', '{"overall_score": 7, "summary": "Good site for outreach"}', 'Comprehensive analysis for test-site.com', 200);

-- Add system settings for performance optimization
INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
('chatgpt_cache_duration', '86400', 'Cache duration for ChatGPT analysis in seconds (24 hours)'),
('parallel_processing_enabled', '1', 'Enable parallel processing for domain analysis'),
('content_fetch_timeout', '10', 'Timeout for content fetching in seconds'),
('api_request_timeout', '30', 'Timeout for API requests in seconds'),
('max_tokens_per_request', '1500', 'Maximum tokens per ChatGPT request for optimization'); 