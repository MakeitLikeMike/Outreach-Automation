# Guest Post Outreach Automation System - Installation Guide

## Prerequisites

Before setting up the system, make sure you have:

1. **Web Server** (Apache/Nginx)
2. **PHP 7.4+** with the following extensions:
   - PDO MySQL
   - cURL
   - JSON
   - OpenSSL
3. **MySQL 5.7+** or **MariaDB 10.2+**
4. **API Accounts** (optional, but recommended):
   - DataForSEO account
   - Tomba.io account
   - Mailgun account

## Installation Steps

### 1. Database Setup

1. Create a new MySQL database:
```sql
CREATE DATABASE outreach_automation;
```

2. Import the database schema:
```bash
mysql -u your_username -p outreach_automation < database_setup.sql
```

Or run the SQL commands from `database_setup.sql` in your MySQL client.

### 2. Configure Database Connection

Edit `config/database.php` and update the database credentials:

```php
private $host = 'localhost';           // Your MySQL host
private $username = 'your_username';   // Your MySQL username
private $password = 'your_password';   // Your MySQL password
private $database = 'outreach_automation'; // Database name
```

### 3. Set File Permissions

Make sure the web server can read/write to the application directory:

```bash
chmod -R 755 /path/to/outreach-automation/
chmod -R 777 /path/to/outreach-automation/assets/  # For uploaded files (if any)
```

### 4. Web Server Configuration

#### Apache (.htaccess)
Create a `.htaccess` file in the root directory:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"

# Cache static assets
<FilesMatch "\.(css|js|png|jpg|jpeg|gif|ico|svg)$">
    ExpiresActive On
    ExpiresDefault "access plus 1 month"
</FilesMatch>
```

#### Nginx
Add this to your Nginx server block:

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

location ~ \.php$ {
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}

# Security headers
add_header X-Content-Type-Options nosniff;
add_header X-Frame-Options DENY;
add_header X-XSS-Protection "1; mode=block";
```

### 5. API Configuration

1. Visit the Settings page (`/settings.php`) after installation
2. Configure your API keys:
   - **DataForSEO**: For backlink analysis and domain metrics
   - **Tomba.io**: For finding email addresses
   - **Mailgun**: For sending emails

### 6. Test the Installation

1. Open your browser and navigate to your domain
2. You should see the dashboard
3. Test the database connection by creating a new campaign
4. Test API connections in the Settings page

## API Account Setup

### DataForSEO
1. Sign up at [DataForSEO](https://dataforseo.com/)
2. Choose a plan (starts at $50/month)
3. Get your API credentials from the dashboard
4. Add them to Settings → API Configuration

### Tomba.io
1. Sign up at [Tomba.io](https://tomba.io/)
2. Get your API key and secret
3. Add them to Settings → API Configuration

### Mailgun
1. Sign up at [Mailgun](https://mailgun.com/)
2. Verify your domain
3. Get your API key and domain
4. Add them to Settings → Email Configuration

## Default Login

The system doesn't have user authentication by default. For production use, consider adding:

1. Basic HTTP authentication
2. Custom login system
3. Integration with existing authentication

## File Structure

```
outreach-automation/
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── main.js
├── classes/
│   ├── ApiIntegration.php
│   ├── Campaign.php
│   ├── EmailTemplate.php
│   └── TargetDomain.php
├── config/
│   └── database.php
├── index.php
├── campaigns.php
├── domains.php
├── templates.php
├── monitoring.php
├── settings.php
├── database_setup.sql
└── INSTALLATION_GUIDE.md
```

## Troubleshooting

### Common Issues

1. **Database Connection Error**
   - Check MySQL credentials in `config/database.php`
   - Ensure MySQL server is running
   - Verify database exists and user has permissions

2. **Permission Denied Errors**
   - Check file permissions: `chmod -R 755 /path/to/app/`
   - Ensure web server user can read files

3. **API Calls Failing**
   - Verify API keys in Settings
   - Check that cURL extension is installed: `php -m | grep curl`
   - Test API connections using the Settings page

4. **Email Sending Issues**
   - Verify Mailgun domain is verified
   - Check API key is correct
   - Ensure sender email is from verified domain

### Debug Mode

To enable debug mode, add this to the top of `index.php`:

```php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
```

## Security Considerations

1. **Database Security**
   - Use strong MySQL passwords
   - Don't use root user for the application
   - Consider encrypting sensitive data

2. **API Keys**
   - Keep API keys secure
   - Use environment variables in production
   - Regularly rotate keys

3. **Access Control**
   - Add authentication for production use
   - Restrict access to admin pages
   - Use HTTPS in production

## Performance Optimization

1. **Database**
   - Add indexes for frequently queried columns
   - Regular database maintenance
   - Consider query optimization

2. **Caching**
   - Enable PHP OPcache
   - Cache API responses
   - Use browser caching for static assets

3. **API Usage**
   - Implement rate limiting
   - Cache API responses
   - Monitor API usage and costs

## Support

For issues or questions:
1. Check the troubleshooting section above
2. Review error logs
3. Test individual components (database, APIs)
4. Check API service status pages

## Next Steps

After installation:
1. Configure your API keys in Settings
2. Create your first campaign
3. Add competitor URLs for analysis
4. Set up email templates
5. Start monitoring results

The system is now ready for use!