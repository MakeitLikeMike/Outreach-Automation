# 🚀 Linux Deployment Guide - Outreach Automation System

Moving from Windows/XAMPP to Ubuntu/Linode? This guide covers everything you need to know.

## ⚠️ **Compatibility Issues Summary**

**🔴 HIGH PRIORITY (Must Fix):**
- File paths (Windows C:/ → Linux /var/www/)
- PHP extensions installation
- Web server configuration (Apache/Nginx)
- MySQL setup and credentials
- Background processes (Windows services → cron jobs)

**🟡 MEDIUM PRIORITY (Recommended):**
- File permissions and security
- Case sensitivity checks
- Email configuration verification

## 📋 **Step-by-Step Deployment**

### **1. Linode Server Setup**
```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install LAMP stack
sudo apt install apache2 mysql-server php8.2 php8.2-fpm -y

# Enable Apache PHP module
sudo a2enmod php8.2
sudo systemctl restart apache2
```

### **2. Install Required PHP Extensions**
```bash
# Core extensions for our system
sudo apt install php8.2-mysqli php8.2-imap php8.2-curl php8.2-openssl php8.2-mbstring php8.2-xml php8.2-zip -y

# Restart Apache
sudo systemctl restart apache2

# Verify PHP installation
php -v
php -m | grep -E "(mysqli|imap|curl|openssl|mbstring)"
```

### **3. Install Composer**
```bash
# Download and install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

# Verify installation
composer --version
```

### **4. MySQL Configuration**
```bash
# Secure MySQL installation
sudo mysql_secure_installation

# Create database and user
sudo mysql -u root -p
```

```sql
CREATE DATABASE outreach_automation;
CREATE USER 'outreach_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON outreach_automation.* TO 'outreach_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### **5. Upload and Configure Project**
```bash
# Create project directory
sudo mkdir -p /var/www/outreach
cd /var/www/outreach

# Upload your files here (via SFTP/Git)
# Then restore composer dependencies
composer install

# Copy and configure environment
cp .env.example .env
nano .env
```

**Update .env for Linux:**
```ini
# Database Configuration (NEW)
DB_HOST=localhost
DB_USERNAME=outreach_user
DB_PASSWORD=your_secure_password
DB_NAME=outreach_automation

# Email Configuration (SAME)
GMASS_API_KEY=bb853e27-2ea8-4d76-8f7d-f0715bb7f138
GMASS_FROM_EMAIL=teamoutreach41@gmail.com
GMASS_FROM_NAME="Outreach Team"

# API Keys (SAME)
DATAFORSEO_LOGIN=your_dataforseo_login
DATAFORSEO_PASSWORD=your_dataforseo_password
TOMBA_API_KEY=your_tomba_api_key
OPENAI_API_KEY=your_openai_api_key

# IMAP Configuration (SAME)
IMAP_EMAIL=teamoutreach41@gmail.com
IMAP_PASSWORD=sklfxnbtyctkcalt
```

### **6. Set Proper Permissions**
```bash
# Set ownership to web server
sudo chown -R www-data:www-data /var/www/outreach/

# Set directory permissions
sudo chmod -R 755 /var/www/outreach/

# Set writable permissions for logs
sudo chmod -R 775 /var/www/outreach/logs/

# Secure environment file
sudo chmod 600 /var/www/outreach/.env
```

### **7. Import Database**
```bash
# Export from Windows first, then:
mysql -u outreach_user -p outreach_automation < database_backup.sql
```

### **8. Apache Virtual Host**
Create `/etc/apache2/sites-available/outreach.conf`:
```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/outreach
    
    <Directory /var/www/outreach>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/outreach_error.log
    CustomLog ${APACHE_LOG_DIR}/outreach_access.log combined
</VirtualHost>
```

```bash
# Enable site
sudo a2ensite outreach.conf
sudo a2enmod rewrite
sudo systemctl reload apache2
```

### **9. SSL Certificate**
```bash
# Install Certbot
sudo apt install certbot python3-certbot-apache -y

# Get SSL certificate
sudo certbot --apache -d yourdomain.com

# Verify auto-renewal
sudo certbot renew --dry-run
```

### **10. Background Processes (Cron Jobs)**
```bash
# Edit crontab
crontab -e

# Add these lines:
*/5 * * * * /usr/bin/php /var/www/outreach/run_background_processor.php
*/15 * * * * /usr/bin/php /var/www/outreach/start_imap_monitoring.php
0 2 * * * /usr/bin/php /var/www/outreach/classes/LogRotator.php
```

## 🔧 **Files That Need Updates**

### **No Changes Needed:**
- ✅ All PHP core logic
- ✅ Database queries and models
- ✅ Email templates and content
- ✅ API integrations (DataForSEO, Tomba, OpenAI)
- ✅ GMass SMTP functionality

### **Configuration Updates:**
- 🔧 `.env` file (database credentials)
- 🔧 Web server configuration (Apache vhost)
- 🔧 Background processes (cron instead of Windows services)

## ⚠️ **Common Issues & Solutions**

### **Issue: PHP Extensions Missing**
```bash
# Check what's installed
php -m

# Install missing extensions
sudo apt install php8.2-[extension-name]
sudo systemctl restart apache2
```

### **Issue: Permission Denied**
```bash
# Fix ownership
sudo chown -R www-data:www-data /var/www/outreach/

# Fix permissions
sudo chmod -R 755 /var/www/outreach/
sudo chmod -R 775 /var/www/outreach/logs/
```

### **Issue: Database Connection Failed**
1. Check MySQL service: `sudo systemctl status mysql`
2. Verify credentials in `.env`
3. Test connection: `mysql -u outreach_user -p`

### **Issue: Email Sending Fails**
1. Check PHP extensions: `php -m | grep openssl`
2. Verify SMTP settings in `.env`
3. Test from command line: `php test_email.php`

## 🚀 **Post-Deployment Testing**

### **1. Test Web Interface**
```bash
# Visit your domain
curl -I http://yourdomain.com
# Should return 200 OK
```

### **2. Test Database Connection**
```bash
php -r "
require_once '/var/www/outreach/config/database.php';
try {
    \$db = new Database();
    echo 'Database connection: SUCCESS';
} catch (Exception \$e) {
    echo 'Database connection: FAILED - ' . \$e->getMessage();
}
"
```

### **3. Test Email Sending**
```bash
php /var/www/outreach/send_test_email.php
```

### **4. Test Background Processes**
```bash
# Test background processor
php /var/www/outreach/run_background_processor.php

# Test IMAP monitoring
php /var/www/outreach/start_imap_monitoring.php
```

## 🎉 **Deployment Complete!**

Your outreach automation system should now be running on Ubuntu/Linode with:

- ✅ **Scalable Infrastructure:** Linux server with proper resources
- ✅ **SSL Security:** HTTPS encryption for all traffic
- ✅ **Background Automation:** Cron jobs handling campaigns
- ✅ **Email Integration:** GMass working seamlessly
- ✅ **Database Optimized:** MySQL configured for performance

## 📞 **Need Help?**

If you encounter issues during deployment:
1. Check Apache error logs: `sudo tail -f /var/log/apache2/error.log`
2. Check PHP error logs: `sudo tail -f /var/log/apache2/error.log`
3. Test each component individually
4. Verify all environment variables are set correctly

The system architecture remains the same - only the hosting environment changes!