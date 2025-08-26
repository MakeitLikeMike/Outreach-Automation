-- Add DataForSEO backlinks metrics to target_domains table
-- Migration: Add real DataForSEO backlinks data fields

ALTER TABLE target_domains 
ADD COLUMN backlinks_total BIGINT DEFAULT 0 COMMENT 'Total backlinks count from DataForSEO',
ADD COLUMN referring_pages INT DEFAULT 0 COMMENT 'Referring pages count from DataForSEO',
ADD COLUMN referring_main_domains INT DEFAULT 0 COMMENT 'Main referring domains from DataForSEO',
ADD COLUMN domain_authority_rank INT DEFAULT 0 COMMENT 'DataForSEO domain authority rank (0-1000)',
ADD COLUMN broken_backlinks INT DEFAULT 0 COMMENT 'Broken backlinks count from DataForSEO',
ADD COLUMN backlink_analysis_type VARCHAR(50) DEFAULT 'unknown' COMMENT 'Type of backlink analysis performed',
ADD COLUMN backlink_last_updated TIMESTAMP NULL COMMENT 'Last time backlink data was fetched';