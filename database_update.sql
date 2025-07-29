-- Database Update Script for Outreach Automation
-- Run this SQL script to add the missing columns to your campaigns table

-- Add owner_email column to campaigns table
ALTER TABLE campaigns ADD COLUMN owner_email VARCHAR(255) DEFAULT NULL;

-- Add forwarded_emails column to campaigns table
ALTER TABLE campaigns ADD COLUMN forwarded_emails INT DEFAULT 0;

-- Add index for better performance on owner_email lookups
CREATE INDEX idx_campaigns_owner_email ON campaigns(owner_email);

-- Update existing campaigns to have 0 forwarded emails (this is safe as it's the default)
UPDATE campaigns SET forwarded_emails = 0 WHERE forwarded_emails IS NULL;

-- Show the updated table structure
DESCRIBE campaigns;