-- ============================================================================
-- SPRINT 1: EMAIL SEARCH AUTOMATION - SIMPLIFIED DATABASE SCHEMA UPDATES
-- ============================================================================
-- This simplified migration adds email search automation functionality
-- without stored procedures (they can be added later)

USE outreach_automation;

-- ============================================================================
-- 1. UPDATE TARGET_DOMAINS TABLE - ADD EMAIL SEARCH TRACKING
-- ============================================================================

-- Add email search tracking columns to existing target_domains table
ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS email_search_status ENUM('pending', 'searching', 'found', 'not_found', 'failed') DEFAULT 'pending' AFTER contact_email;

ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS email_search_attempts INT DEFAULT 0 AFTER email_search_status;

ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS last_email_search_at TIMESTAMP NULL AFTER email_search_attempts;

ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS next_retry_at TIMESTAMP NULL AFTER last_email_search_at;

ALTER TABLE target_domains 
ADD COLUMN IF NOT EXISTS email_search_error TEXT AFTER next_retry_at;

-- Add indexes for efficient email search queries
ALTER TABLE target_domains 
ADD INDEX IF NOT EXISTS idx_email_search_status (email_search_status);

ALTER TABLE target_domains 
ADD INDEX IF NOT EXISTS idx_next_retry (next_retry_at);

ALTER TABLE target_domains 
ADD INDEX IF NOT EXISTS idx_email_search_pending (status, email_search_status);

-- ============================================================================
-- 2. CREATE EMAIL_SEARCH_QUEUE TABLE - BACKGROUND PROCESSING
-- ============================================================================

-- Queue table for background email search processing
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
    INDEX idx_domain_id (domain_id),
    INDEX idx_processing (status, processing_started_at)
);

-- ============================================================================
-- 3. CREATE API_USAGE_TRACKING TABLE - RATE LIMITING & MONITORING
-- ============================================================================

-- Track API usage for rate limiting and monitoring
CREATE TABLE IF NOT EXISTS api_usage_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_service ENUM('tomba', 'dataforseo', 'other') NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    request_type ENUM('email_search', 'domain_metrics', 'backlinks', 'other') NOT NULL,
    domain VARCHAR(255),
    status_code INT,
    response_time_ms INT,
    credits_used INT DEFAULT 1,
    success BOOLEAN DEFAULT FALSE,
    error_message TEXT,
    request_data JSON,
    response_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_service_date (api_service, created_at),
    INDEX idx_request_type_date (request_type, created_at),
    INDEX idx_status_success (status_code, success),
    INDEX idx_api_service_created (api_service, created_at)
);

-- ============================================================================
-- 4. CREATE EMAIL_SEARCH_LOGS TABLE - DETAILED LOGGING
-- ============================================================================

-- Detailed logging for email search operations
CREATE TABLE IF NOT EXISTS email_search_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    search_type ENUM('immediate', 'background', 'manual') NOT NULL,
    attempt_number INT NOT NULL,
    tomba_request JSON,
    tomba_response JSON,
    emails_found INT DEFAULT 0,
    selected_email VARCHAR(255),
    processing_time_ms INT,
    success BOOLEAN DEFAULT FALSE,
    error_code VARCHAR(50),
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES target_domains(id) ON DELETE CASCADE,
    INDEX idx_domain_search_type (domain_id, search_type),
    INDEX idx_success_date (success, created_at),
    INDEX idx_error_code (error_code)
);

-- ============================================================================
-- 5. UPDATE EXISTING DATA - INITIALIZE EMAIL SEARCH STATUS
-- ============================================================================

-- Set email_search_status for existing domains
UPDATE target_domains SET 
    email_search_status = CASE 
        WHEN contact_email IS NOT NULL AND contact_email != '' THEN 'found'
        WHEN status = 'approved' THEN 'pending'
        ELSE 'not_found'
    END
WHERE email_search_status = 'pending';

-- ============================================================================
-- 6. CREATE VIEWS - MONITORING & REPORTING
-- ============================================================================

-- View for email search performance monitoring
CREATE OR REPLACE VIEW email_search_performance AS
SELECT 
    DATE(created_at) as search_date,
    COUNT(*) as total_searches,
    SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) as successful_searches,
    SUM(CASE WHEN emails_found > 0 THEN 1 ELSE 0 END) as emails_found_count,
    ROUND(AVG(processing_time_ms), 2) as avg_processing_time_ms,
    ROUND((SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as success_rate_percentage
FROM email_search_logs
GROUP BY DATE(created_at)
ORDER BY search_date DESC;

-- View for domains needing email search
CREATE OR REPLACE VIEW domains_needing_email_search AS
SELECT 
    td.id,
    td.domain,
    td.campaign_id,
    c.name as campaign_name,
    td.status,
    td.email_search_status,
    td.email_search_attempts,
    td.last_email_search_at,
    td.quality_score,
    CASE 
        WHEN td.email_search_status = 'pending' AND td.status = 'approved' THEN 'Ready for search'
        WHEN td.email_search_status = 'failed' AND td.email_search_attempts < 3 THEN 'Retry needed'
        WHEN td.email_search_status = 'searching' AND td.last_email_search_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'Stuck - needs retry'
        ELSE 'No action needed'
    END as action_needed
FROM target_domains td
JOIN campaigns c ON td.campaign_id = c.id
WHERE (
    (td.status = 'approved' AND td.email_search_status = 'pending') OR
    (td.email_search_status = 'failed' AND td.email_search_attempts < 3) OR
    (td.email_search_status = 'searching' AND td.last_email_search_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
)
ORDER BY 
    CASE td.email_search_status 
        WHEN 'pending' THEN 1 
        WHEN 'failed' THEN 2 
        WHEN 'searching' THEN 3 
    END,
    td.quality_score DESC;

-- ============================================================================
-- MIGRATION COMPLETE - SIMPLIFIED VERSION
-- ============================================================================