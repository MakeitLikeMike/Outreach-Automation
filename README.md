# 🚀 Outreach Automation System

Advanced guest post outreach automation system with AI-powered email generation, domain analysis, and lead management.

## 📋 System Overview

**Core Technologies:**
- **Backend**: PHP 8.2+ with MySQL 8.0+
- **Email Integration**: GMass SMTP + Direct Gmail SMTP
- **APIs**: DataForSEO, Tomba, OpenAI, Gmail
- **Background Processing**: Multi-threaded job processor
- **Frontend**: Responsive HTML5/CSS3/JavaScript

## ⚡ Key Features

- **Campaign Management** - Create and manage outreach campaigns
- **Domain Discovery** - AI-powered competitive analysis
- **Email Generation** - GPT-4 personalized outreach content
- **Bulk Email Sending** - GMass integration for deliverability
- **Reply Monitoring** - IMAP-based classification and forwarding
- **Analytics Dashboard** - Real-time performance metrics
- **Background Automation** - Hands-free campaign execution

## 🏗️ Installation

### Prerequisites
- **Windows Server 2022** (recommended: 16GB RAM, 4 vCPUs)
- **PHP 8.2+** with extensions: `mysqli`, `imap`, `curl`, `openssl`
- **MySQL 8.0+**
- **Composer** for dependency management
- **IIS/Apache** web server with SSL

### Quick Setup

1. **Clone and Install Dependencies**
   ```bash
   git clone [repository-url] /path/to/webroot
   cd /path/to/webroot
   composer install
   ```

2. **Configure Environment**
   ```bash
   # Copy and edit environment file
   cp .env.example .env
   ```

3. **Database Setup**
   ```bash
   # Run database migrations
   php run_migration.php
   ```

4. **Configure Web Server**
   - Point document root to project directory
   - Enable `.htaccess` rewrite rules
   - Configure SSL certificate

## ⚙️ Configuration

### Environment Variables (.env)
```ini
# Database Configuration
DB_HOST=localhost
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
DB_NAME=outreach_automation

# GMass Configuration
GMASS_API_KEY=your_gmass_api_key
GMASS_FROM_EMAIL=your_business@gmail.com

# API Keys
DATAFORSEO_LOGIN=your_dataforseo_login
DATAFORSEO_PASSWORD=your_dataforseo_password
TOMBA_API_KEY=your_tomba_api_key
OPENAI_API_KEY=your_openai_api_key

# IMAP Configuration
IMAP_EMAIL=your_business@gmail.com
IMAP_PASSWORD=your_gmail_app_password
```

### Required API Services
- **GMass** - Professional email sending service
- **DataForSEO** - Domain analysis and SEO metrics
- **Tomba** - Email discovery and verification
- **OpenAI** - AI content generation
- **Gmail** - IMAP monitoring and SMTP backup

## 🚀 Deployment

### Recommended Cloud Configuration
```
OS: Windows Server 2022 Standard
RAM: 16GB (minimum 8GB)
CPU: 4 vCPUs (minimum 2 vCPUs)
Storage: 100GB SSD
Providers: Linode, DigitalOcean, Azure
Monthly Cost: ~$130-150
```

### Post-Deployment Checklist
- [ ] Install and configure PHP 8.2+ with required extensions
- [ ] Setup MySQL 8.0+ with optimized configuration
- [ ] Configure IIS/Apache with SSL certificates
- [ ] Setup Windows Services for background processing
- [ ] Configure automated backups
- [ ] Test all API integrations
- [ ] Setup monitoring and log rotation

## 🔧 Background Services

### Start Background Processor
```bash
# Manual start
php run_background_processor.php

# Windows Service (recommended)
# Use NSSM to create Windows service for production
```

### Start IMAP Monitoring
```bash
php start_imap_monitoring.php
```

## 📊 Usage

### Admin Access
1. Navigate to `/admin.php`
2. Login with your credentials
3. Configure system settings and API keys

### Campaign Workflow
1. **Create Campaign** - Define target keywords and competitor URLs
2. **Configure Template** - Setup email templates and content
3. **Start Processing** - System automatically:
   - Finds relevant domains via competitive analysis
   - Analyzes domain quality and metrics
   - Discovers contact emails
   - Generates personalized outreach emails
   - Sends emails via GMass
   - Monitors replies via IMAP
   - Classifies and forwards interested leads

### Quick Outreach
- Use `/quick_outreach.php` for individual domain outreach
- Instant email generation and sending

## 📈 Monitoring

### System Dashboards
- **System Status**: `/system_status.php` - Health monitoring
- **Analytics**: `/analytics_dashboard.php` - Performance metrics  
- **Campaigns**: `/campaigns.php` - Campaign management
- **API Status**: `/api_status.php` - Integration monitoring

### Log Files (auto-generated)
- `logs/system.log` - General system events
- `logs/background_processor.log` - Background job processing
- `logs/gmass_integration.log` - Email sending events

## 🛡️ Security

### Best Practices
- Keep `.env` file secure (never commit to version control)
- Use strong database passwords and API keys
- Enable HTTPS/SSL for all web traffic
- Configure Windows Firewall appropriately
- Regular security updates for PHP and dependencies
- Monitor log files for suspicious activity

### File Permissions
```bash
# Web server readable
chmod 644 *.php

# Directories accessible
chmod 755 directories/

# Environment file secure
chmod 600 .env

# Logs directory writable
chmod 755 logs/
```

## 🔄 Maintenance

### Database Optimization
- Configure MySQL InnoDB buffer pool for available RAM
- Enable query caching and optimization
- Regular database backups (automated recommended)

### Performance Monitoring
- Monitor disk space usage (logs can grow large)
- Track API usage and rate limits
- Monitor background job processing times
- Setup alerts for system errors

### Regular Tasks
- Update dependencies: `composer update` (monthly)
- Log rotation and archival
- Database maintenance and optimization
- Security patches and updates

## 🆘 Troubleshooting

### Common Issues
1. **Database Connection Errors**
   - Check MySQL service status
   - Verify credentials in `.env` file
   - Test network connectivity

2. **Email Sending Failures**
   - Verify GMass API key and account status
   - Check sender email configuration
   - Review GMass dashboard for errors

3. **Background Job Processing**
   - Ensure PHP CLI is properly configured
   - Check Windows Services status
   - Review background processor logs

4. **API Integration Issues**
   - Verify API keys in `.env` file
   - Check API rate limits and quotas
   - Review API status dashboard

### Support Resources
- System logs: `/logs/` directory
- API status: `/api_status.php`
- System health: `/system_status.php`
- Error handling: Built-in error reporting and logging

## 📝 License
Proprietary software - All rights reserved

---

**⚠️ Important**: This system is designed for legitimate business outreach only. Ensure compliance with email marketing laws and regulations in your jurisdiction.