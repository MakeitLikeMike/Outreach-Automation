-- ============================================================================
-- GUEST POST EVALUATION SYSTEM - DATABASE SCHEMA
-- ============================================================================
-- This migration adds guest post evaluation functionality to track
-- SEO analysis results for target websites

USE outreach_automation;

-- Guest post evaluations table
CREATE TABLE IF NOT EXISTS guest_post_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    decision ENUM('Accept', 'Reject', 'Pending') NOT NULL,
    score DECIMAL(5,2) DEFAULT 0, -- Percentage score (0-100)
    reasons JSON, -- Array of reasons for decision
    metrics JSON, -- DataForSEO metrics
    content_analysis JSON, -- Website content analysis
    target_niche VARCHAR(100),
    evaluator_version VARCHAR(20) DEFAULT '1.0',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain (domain),
    INDEX idx_decision (decision),
    INDEX idx_score (score),
    INDEX idx_created (created_at),
    INDEX idx_niche (target_niche)
);

-- Add evaluation status to existing target_domains table
ALTER TABLE target_domains 
ADD COLUMN evaluation_status ENUM('pending', 'evaluated', 'approved', 'rejected') DEFAULT 'pending' AFTER email_search_error,
ADD COLUMN evaluation_score DECIMAL(5,2) DEFAULT 0 AFTER evaluation_status,
ADD COLUMN evaluation_date TIMESTAMP NULL AFTER evaluation_score,
ADD COLUMN evaluation_reasons TEXT AFTER evaluation_date;

-- Add indexes for evaluation fields
ALTER TABLE target_domains 
ADD INDEX idx_evaluation_status (evaluation_status),
ADD INDEX idx_evaluation_score (evaluation_score),
ADD INDEX idx_evaluation_date (evaluation_date);

-- Guest post evaluation queue for batch processing
CREATE TABLE IF NOT EXISTS evaluation_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    target_niche VARCHAR(100),
    priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
    status ENUM('queued', 'processing', 'completed', 'failed') DEFAULT 'queued',
    attempt_count INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    last_attempt_at TIMESTAMP NULL,
    next_retry_at TIMESTAMP NULL,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE,
    INDEX idx_status_priority (status, priority),
    INDEX idx_next_retry (next_retry_at),
    INDEX idx_domain_status (domain, status)
);

-- Evaluation criteria weights and settings
CREATE TABLE IF NOT EXISTS evaluation_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT,
    category ENUM('quantitative', 'qualitative', 'general') DEFAULT 'general',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_key (setting_key)
);

-- Insert default evaluation settings
INSERT INTO evaluation_settings (setting_key, setting_value, category, description) VALUES
-- Quantitative thresholds
('min_domain_rating', '30', 'quantitative', 'Minimum domain rating required'),
('min_organic_traffic', '5000', 'quantitative', 'Minimum monthly organic traffic required'),
('min_backlink_quality_ratio', '0.7', 'quantitative', 'Minimum ratio of quality backlinks'),
('max_anchor_concentration', '30', 'quantitative', 'Maximum percentage for any single anchor text'),
('min_http_success_ratio', '0.8', 'quantitative', 'Minimum ratio of 200 status codes'),
('min_keyword_count', '50', 'quantitative', 'Minimum number of ranking keywords'),
('max_homepage_traffic_ratio', '0.7', 'quantitative', 'Maximum traffic concentration on homepage'),

-- Qualitative thresholds  
('min_content_word_count', '300', 'qualitative', 'Minimum word count for quality content'),
('min_quality_posts_ratio', '0.7', 'qualitative', 'Minimum ratio of quality blog posts'),
('min_contact_indicators', '3', 'qualitative', 'Minimum contact page quality indicators'),
('min_relevance_keywords', '2', 'qualitative', 'Minimum relevant keywords for niche matching'),
('max_unnatural_anchors', '5', 'qualitative', 'Maximum unnatural anchor texts allowed'),

-- Decision thresholds
('min_acceptance_score', '70', 'general', 'Minimum percentage score for acceptance'),
('min_quantitative_score', '6', 'general', 'Minimum quantitative criteria score'),
('min_qualitative_score', '4', 'general', 'Minimum qualitative criteria score'),

-- Processing settings
('evaluation_batch_size', '5', 'general', 'Number of domains to evaluate per batch'),
('evaluation_retry_delay_hours', '2', 'general', 'Hours to wait before retry on failure')

ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Performance tracking for evaluations
CREATE TABLE IF NOT EXISTS evaluation_performance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    total_evaluations INT DEFAULT 0,
    accepted_count INT DEFAULT 0,
    rejected_count INT DEFAULT 0,
    avg_score DECIMAL(5,2) DEFAULT 0,
    avg_processing_time_seconds DECIMAL(8,3) DEFAULT 0,
    api_calls_used INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_date (date),
    INDEX idx_date (date)
);

-- Stored procedure to queue domain for evaluation
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS QueueDomainForEvaluation(
    IN p_domain_id INT,
    IN p_target_niche VARCHAR(100),
    IN p_priority ENUM('high', 'medium', 'low')
)
BEGIN
    DECLARE v_domain VARCHAR(255);
    
    -- Get domain name
    SELECT td.domain INTO v_domain 
    FROM target_domains td 
    WHERE td.id = p_domain_id;
    
    -- Insert into evaluation queue if not already exists
    INSERT IGNORE INTO evaluation_queue (domain_id, domain, target_niche, priority)
    VALUES (p_domain_id, v_domain, p_target_niche, p_priority);
    
    -- Update target domain evaluation status
    UPDATE target_domains 
    SET evaluation_status = 'pending' 
    WHERE id = p_domain_id;
END //

-- Stored procedure to get next batch for evaluation
CREATE PROCEDURE IF NOT EXISTS GetEvaluationBatch(
    IN p_batch_size INT DEFAULT 5
)
BEGIN
    -- Get next batch of domains to evaluate
    SELECT eq.*, td.domain, td.campaign_id
    FROM evaluation_queue eq
    JOIN target_domains td ON eq.domain_id = td.id
    WHERE eq.status = 'queued' 
       AND (eq.next_retry_at IS NULL OR eq.next_retry_at <= NOW())
       AND eq.attempt_count < eq.max_attempts
    ORDER BY 
        CASE eq.priority 
            WHEN 'high' THEN 1 
            WHEN 'medium' THEN 2 
            WHEN 'low' THEN 3 
        END,
        eq.created_at ASC
    LIMIT p_batch_size
    FOR UPDATE;
    
    -- Mark selected items as processing
    UPDATE evaluation_queue eq
    SET eq.status = 'processing', 
        eq.last_attempt_at = NOW()
    WHERE eq.status = 'queued' 
       AND (eq.next_retry_at IS NULL OR eq.next_retry_at <= NOW())
       AND eq.attempt_count < eq.max_attempts
    ORDER BY 
        CASE eq.priority 
            WHEN 'high' THEN 1 
            WHEN 'medium' THEN 2 
            WHEN 'low' THEN 3 
        END,
        eq.created_at ASC
    LIMIT p_batch_size;
END //

DELIMITER ;

-- View for evaluation statistics
CREATE OR REPLACE VIEW evaluation_stats AS
SELECT 
    COUNT(*) as total_evaluations,
    SUM(CASE WHEN decision = 'Accept' THEN 1 ELSE 0 END) as accepted_count,
    SUM(CASE WHEN decision = 'Reject' THEN 1 ELSE 0 END) as rejected_count,
    ROUND(AVG(score), 2) as avg_score,
    ROUND(SUM(CASE WHEN decision = 'Accept' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as acceptance_rate,
    DATE(created_at) as evaluation_date
FROM guest_post_evaluations 
GROUP BY DATE(created_at)
ORDER BY evaluation_date DESC;

-- View for domains needing evaluation
CREATE OR REPLACE VIEW domains_needing_evaluation AS
SELECT 
    td.id,
    td.domain,
    td.campaign_id,
    c.name as campaign_name,
    td.status as domain_status,
    td.evaluation_status,
    td.evaluation_score,
    td.evaluation_date,
    eq.status as queue_status,
    eq.priority,
    eq.attempt_count,
    eq.next_retry_at,
    CASE 
        WHEN td.evaluation_status = 'pending' AND eq.status IS NULL THEN 'Ready to queue'
        WHEN eq.status = 'queued' THEN 'Queued for evaluation'
        WHEN eq.status = 'processing' THEN 'Currently evaluating'
        WHEN eq.status = 'failed' AND eq.next_retry_at <= NOW() THEN 'Ready for retry'
        WHEN eq.status = 'failed' AND eq.next_retry_at > NOW() THEN CONCAT('Retry at ', eq.next_retry_at)
        ELSE 'No action needed'
    END as action_needed
FROM target_domains td
JOIN campaigns c ON td.campaign_id = c.id
LEFT JOIN evaluation_queue eq ON td.id = eq.domain_id
WHERE td.status = 'approved' 
   AND (td.evaluation_status IN ('pending', 'evaluated') 
        OR eq.status IN ('queued', 'failed'));

SELECT 'Guest Post Evaluation System setup completed!' as status,
       'Tables, procedures, and views created successfully.' as message,
       'Use QueueDomainForEvaluation() to start evaluating domains.' as next_steps;