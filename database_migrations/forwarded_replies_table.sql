-- Create table for tracking forwarded replies
CREATE TABLE IF NOT EXISTS forwarded_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_email_id INT NOT NULL,
    campaign_id INT NOT NULL,
    reply_from_email VARCHAR(255) NOT NULL,
    reply_subject VARCHAR(500),
    forwarded_to_email VARCHAR(255) NOT NULL,
    classification VARCHAR(50),
    forwarded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_campaign_id (campaign_id),
    INDEX idx_forwarded_to (forwarded_to_email),
    INDEX idx_forwarded_at (forwarded_at),
    
    FOREIGN KEY (original_email_id) REFERENCES outreach_emails(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);