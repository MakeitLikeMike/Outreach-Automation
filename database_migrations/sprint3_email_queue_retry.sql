-- Sprint 3 Migration: Add retry functionality to email queue
-- Add retry_count column to email_queue table

ALTER TABLE email_queue 
ADD COLUMN retry_count INT DEFAULT 0 AFTER status;

-- Add index for better performance on retry queries
CREATE INDEX idx_email_queue_retry_status ON email_queue(status, retry_count, scheduled_at);

-- Update existing failed emails to have retry_count = 0
UPDATE email_queue SET retry_count = 0 WHERE retry_count IS NULL;

-- Create detailed email send logs table for enhanced monitoring
CREATE TABLE IF NOT EXISTS email_send_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_email VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject TEXT,
    gmail_message_id VARCHAR(255),
    success BOOLEAN NOT NULL DEFAULT 0,
    error_message TEXT,
    error_type VARCHAR(50),
    error_code INT,
    processing_time_ms INT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sender_email (sender_email),
    INDEX idx_recipient_email (recipient_email),
    INDEX idx_sent_at (sent_at),
    INDEX idx_success_error (success, error_type)
);