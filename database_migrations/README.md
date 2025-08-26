# Sprint 1: Email Search Automation - Database Migration

## Overview
This migration adds comprehensive email search automation functionality to the outreach automation system. It implements immediate email search triggers, background processing, retry logic, and monitoring capabilities.

## What's Added

### 1. Enhanced Target Domains Table
The `target_domains` table gets new columns for email search tracking:
- `email_search_status` - Track search progress (pending, searching, found, not_found, failed)
- `email_search_attempts` - Count of search attempts (max 3)
- `last_email_search_at` - Timestamp of last search attempt
- `next_retry_at` - When to retry failed searches
- `email_search_error` - Store error messages for debugging

### 2. Email Search Queue Table
New `email_search_queue` table for background processing:
- Queue management for batch processing
- Priority levels (high, medium, low)
- Retry logic with exponential backoff
- Processing status tracking
- API response storage

### 3. API Usage Tracking Table
New `api_usage_tracking` table for monitoring:
- Track all API calls (Tomba, DataForSEO, etc.)
- Response times and success rates
- Credits usage tracking
- Rate limiting support

### 4. Email Search Logs Table
New `email_search_logs` table for detailed logging:
- Complete audit trail of email searches
- Request/response data storage
- Performance metrics
- Error analysis

### 5. Stored Procedures
Three new stored procedures for efficient operations:
- `QueueDomainForEmailSearch` - Add domains to search queue
- `GetEmailSearchBatch` - Get next batch for processing
- `UpdateEmailSearchResult` - Update search results

### 6. Monitoring Views
Two new views for monitoring and reporting:
- `email_search_performance` - Daily performance metrics
- `domains_needing_email_search` - Domains requiring action

## How to Run Migration

### Option 1: Using the Migration Runner (Recommended)
```bash
# From the project root directory:
php run_migration.php
```

### Option 2: Using the Batch File (Windows)
```cmd
# Double-click run_migration.bat
# Or from command line:
run_migration.bat
```

### Option 3: Manual SQL Execution
```sql
-- Run the SQL file directly in your MySQL client:
SOURCE database_migrations/sprint1_email_search_automation.sql;
```

## Verification

After running the migration, the script will automatically verify:
- ✅ All new columns added to target_domains
- ✅ All new tables created successfully
- ✅ All stored procedures created
- ✅ Current domain status distribution

## Data Migration

The migration automatically initializes existing data:
- Domains with existing emails → `email_search_status = 'found'`
- Approved domains without emails → `email_search_status = 'pending'`
- Other domains → `email_search_status = 'not_found'`

## Rollback

To rollback this migration (if needed):
```sql
-- Remove added columns from target_domains
ALTER TABLE target_domains 
DROP COLUMN email_search_status,
DROP COLUMN email_search_attempts,
DROP COLUMN last_email_search_at,
DROP COLUMN next_retry_at,
DROP COLUMN email_search_error;

-- Drop new tables
DROP TABLE IF EXISTS email_search_queue;
DROP TABLE IF EXISTS api_usage_tracking;
DROP TABLE IF EXISTS email_search_logs;

-- Drop stored procedures
DROP PROCEDURE IF EXISTS QueueDomainForEmailSearch;
DROP PROCEDURE IF EXISTS GetEmailSearchBatch;
DROP PROCEDURE IF EXISTS UpdateEmailSearchResult;

-- Drop views
DROP VIEW IF EXISTS email_search_performance;
DROP VIEW IF EXISTS domains_needing_email_search;
```

## Next Steps

After successful migration:
1. ✅ Database schema updated
2. 🔄 Create EmailSearchService class
3. 🔄 Implement immediate triggers
4. 🔄 Add background processing
5. 🔄 Create monitoring dashboard

## Troubleshooting

### Common Issues

**Error: "Table already exists"**
- This is normal if running migration multiple times
- The migration runner will skip existing elements

**Error: "Access denied"**
- Ensure your database user has CREATE, ALTER, and INSERT privileges
- Check database connection in config/database.php

**Error: "Unknown column"**
- Make sure you're running against the correct database
- Verify the base outreach_automation schema exists

### Getting Help

If you encounter issues:
1. Check the error messages carefully
2. Verify database permissions
3. Ensure PHP PDO MySQL extension is installed
4. Review the migration logs for specific failures

## Performance Impact

This migration is designed to be lightweight:
- New columns use efficient data types
- Indexes added for query optimization
- No data loss or corruption risk
- Can be run on live systems with minimal downtime

## Schema Changes Summary

| Table | Change Type | Details |
|-------|-------------|---------|
| target_domains | ADD COLUMNS | 5 new email search tracking columns |
| target_domains | ADD INDEXES | 3 new indexes for search performance |
| email_search_queue | CREATE TABLE | Background processing queue |
| api_usage_tracking | CREATE TABLE | API monitoring and rate limiting |
| email_search_logs | CREATE TABLE | Detailed operation logging |
| stored_procedures | CREATE | 3 procedures for queue management |
| views | CREATE | 2 monitoring/reporting views |