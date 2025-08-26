-- ============================================================================
-- SPRINT 1: EMAIL SEARCH AUTOMATION - DATABASE SCHEMA UPDATES
-- ============================================================================
-- This migration adds email search automation functionality to the existing schema
-- Run this after the main database_setup.sql file

USE outreach_automation;

-- ============================================================================
-- 1. UPDATE TARGET_DOMAINS TABLE - ADD EMAIL SEARCH TRACKING
-- ============================================================================

-- Add email search tracking columns to existing target_domains table
ALTER TABLE target_domains 
ADD COLUMN email_search_status ENUM('pending', 'searching', 'found', 'not_found', 'failed') DEFAULT 'pending' AFTER contact_email,
ADD COLUMN email_search_attempts INT DEFAULT 0 AFTER email_search_status,
ADD COLUMN last_email_search_at TIMESTAMP NULL AFTER email_search_attempts,
ADD COLUMN next_retry_at TIMESTAMP NULL AFTER last_email_search_at,
ADD COLUMN email_search_error TEXT AFTER next_retry_at;

-- Add indexes for efficient email search queries
ALTER TABLE target_domains 
ADD INDEX idx_email_search_status (email_search_status),
ADD INDEX idx_next_retry (next_retry_at),
ADD INDEX idx_email_search_pending (status, email_search_status);

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
-- 6. CREATE STORED PROCEDURES - EMAIL SEARCH OPERATIONS
-- ============================================================================

DELIMITER //

-- Procedure to queue domain for email search
CREATE PROCEDURE IF NOT EXISTS QueueDomainForEmailSearch(
    IN p_domain_id INT,
    IN p_priority ENUM('high', 'medium', 'low')
)
BEGIN
    DECLARE v_domain VARCHAR(255);
    DECLARE v_campaign_id INT;
    DECLARE v_existing_count INT;
    
    -- Get domain details
    SELECT domain, campaign_id INTO v_domain, v_campaign_id
    FROM target_domains 
    WHERE id = p_domain_id;
    
    -- Check if already queued
    SELECT COUNT(*) INTO v_existing_count
    FROM email_search_queue 
    WHERE domain_id = p_domain_id AND status IN ('pending', 'processing');
    
    -- Only queue if not already queued
    IF v_existing_count = 0 THEN
        INSERT INTO email_search_queue (
            domain_id, domain, campaign_id, priority, created_at
        ) VALUES (
            p_domain_id, v_domain, v_campaign_id, p_priority, NOW()
        );
        
        -- Update domain status to searching
        UPDATE target_domains SET 
            email_search_status = 'searching',
            last_email_search_at = NOW()
        WHERE id = p_domain_id;
    END IF;
END //

-- Procedure to get next batch of domains for email search
CREATE PROCEDURE IF NOT EXISTS GetEmailSearchBatch(
    IN p_batch_size INT DEFAULT 10
)
BEGIN
    -- Get domains ready for email search
    SELECT 
        esq.id as queue_id,
        esq.domain_id,
        esq.domain,
        esq.campaign_id,
        esq.attempt_count,
        td.status as domain_status
    FROM email_search_queue esq
    JOIN target_domains td ON esq.domain_id = td.id
    WHERE esq.status = 'pending' 
        AND (esq.next_retry_at IS NULL OR esq.next_retry_at <= NOW())
        AND esq.attempt_count < esq.max_attempts
    ORDER BY esq.priority DESC, esq.created_at ASC
    LIMIT p_batch_size;
END //

-- Procedure to update email search result
CREATE PROCEDURE IF NOT EXISTS UpdateEmailSearchResult(
    IN p_queue_id INT,
    IN p_domain_id INT,
    IN p_email_found VARCHAR(255),
    IN p_success BOOLEAN,
    IN p_error_message TEXT
)
BEGIN
    DECLARE v_new_status ENUM('pending', 'searching', 'found', 'not_found', 'failed');
    
    -- Determine new status
    IF p_success = TRUE THEN
        IF p_email_found IS NOT NULL AND p_email_found != '' THEN
            SET v_new_status = 'found';
        ELSE
            SET v_new_status = 'not_found';
        END IF;
    ELSE
        SET v_new_status = 'failed';
    END IF;
    
    -- Update target_domains table
    UPDATE target_domains SET 
        contact_email = COALESCE(p_email_found, contact_email),
        email_search_status = v_new_status,
        email_search_attempts = email_search_attempts + 1,
        last_email_search_at = NOW(),
        email_search_error = p_error_message
    WHERE id = p_domain_id;
    
    -- Update queue status
    UPDATE email_search_queue SET 
        status = 'completed',
        completed_at = NOW(),
        error_message = p_error_message
    WHERE id = p_queue_id;
END //

DELIMITER ;

-- ============================================================================
-- 7. CREATE VIEWS - MONITORING & REPORTING
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
-- 8. MIGRATION VERIFICATION
-- ============================================================================

-- Verify the migration was successful
SELECT 
    'target_domains email_search columns' as check_item,
    CASE 
        WHEN COUNT(*) = 5 THEN 'SUCCESS' 
        ELSE 'FAILED' 
    END as status
FROM information_schema.columns 
WHERE table_schema = 'outreach_automation' 
    AND table_name = 'target_domains' 
    AND column_name IN ('email_search_status', 'email_search_attempts', 'last_email_search_at', 'next_retry_at', 'email_search_error')

UNION ALL

SELECT 
    'email_search_queue table' as check_item,
    CASE 
        WHEN COUNT(*) > 0 THEN 'SUCCESS' 
        ELSE 'FAILED' 
    END as status
FROM information_schema.tables 
WHERE table_schema = 'outreach_automation' 
    AND table_name = 'email_search_queue'

UNION ALL

SELECT 
    'stored procedures' as check_item,
    CASE 
        WHEN COUNT(*) = 3 THEN 'SUCCESS' 
        ELSE 'FAILED' 
    END as status
FROM information_schema.routines 
WHERE routine_schema = 'outreach_automation' 
    AND routine_name IN ('QueueDomainForEmailSearch', 'GetEmailSearchBatch', 'UpdateEmailSearchResult');

-- Show summary of domains by email search status
SELECT 
    email_search_status,
    COUNT(*) as domain_count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM target_domains), 2) as percentage
FROM target_domains 
GROUP BY email_search_status
ORDER BY domain_count DESC;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
-- Sprint 1 database schema updates completed successfully!
-- Next steps:
-- 1. Run this migration file against your database
-- 2. Verify all checks return 'SUCCESS'
-- 3. Begin implementing EmailSearchService class
-- ============================================================================