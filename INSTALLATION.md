# PragmaVet Appointment Scheduling System - Installation & Deployment Guide

## System Requirements

### Server Requirements
- **Web Server:** Apache 2.4+ (XAMPP/WAMP/LAMP)
- **PHP:** 7.4 or higher (8.0+ recommended)
- **MySQL:** 5.7+ or MariaDB 10.3+
- **Operating System:** Windows, Linux, or macOS

### Required PHP Extensions
- mysqli
- PDO
- JSON
- session
- mbstring
- openssl
- fileinfo
- zip (for backup)

### Recommended Software
- **XAMPP** (Windows) - Includes Apache, PHP, MySQL
- **Composer** - For PHP dependency management
- **Git** - Version control (optional)

---

## Installation Steps

### Step 1: Download and Extract
1. Download the PragmaVet system files
2. Extract to your web server directory:
   - **XAMPP:** `C:\xampp\htdocs\pragmavet\`
   - **WAMP:** `C:\wamp\www\pragmavet\`
   - **Linux:** `/var/www/html/pragmavet/`

### Step 2: Create Database
1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Create a new database: `vet_system`
   - Collation: `utf8mb4_general_ci`
3. Import the schema file:
   - Click on `vet_system` database
   - Go to Import tab
   - Choose file: `database/schema_complete.sql`
   - Click Go

### Step 3: Configure Database Connection
Edit `dbconnection.php`:
```php
$servername = "localhost";
$username = "root";        // Your MySQL username
$password = "";            // Your MySQL password
$dbname = "vet_system";    // Database name
```

### Step 4: Configure Application
1. **Base URL:** Update in configuration if needed
2. **Timezone:** Set in `dbconnection.php` or PHP config
3. **File Permissions:** Ensure write access for:
   - `uploads/` directory (755 or 777)
   - `backups/` directory (755 or 777)

### Step 5: Run Database Migration
Access in browser:
```
http://localhost/pragmavet/database/upgrade_complete.php
```

### Step 6: Access the Application
```
http://localhost/pragmavet/
```

### Default Admin Login
- **Email:** admin@pragmavet.com (or register a new account)
- **Password:** Set during registration

---

## Configuration

### Email Settings (PHPMailer)
Edit `includes/automation_engine.php`:
```php
$mail->Host = 'smtp.gmail.com';
$mail->Username = 'your-email@gmail.com';
$mail->Password = 'your-app-password'; // Gmail App Password
```

### Clinic Settings
Configure via admin panel at:
```
http://localhost/pragmavet/admin_settings.php
```

Key settings:
- Clinic hours (start/end)
- Appointment duration
- Buffer time between appointments
- Max appointments per day
- Auto-approve appointments
- Reminder timing
- No-show grace period
- Retention period

---

## Deployment to Production

### 1. Security Hardening
- Change default database credentials
- Enable HTTPS (SSL certificate)
- Set strong session security in `php.ini`:
  ```ini
  session.cookie_secure = 1
  session.cookie_httponly = 1
  session.use_strict_mode = 1
  ```
- Restrict file permissions:
  ```bash
  chmod 644 *.php
  chmod 755 includes/ api/ database/
  chmod 755 uploads/ backups/
  ```

### 2. Database Optimization
- Enable query caching
- Set up regular backups (cron job)
- Optimize tables monthly

### 3. Cron Jobs (Linux)
Add to crontab for automation:
```bash
# Run automations every 15 minutes
*/15 * * * * /usr/bin/php /var/www/html/pragmavet/includes/automation_engine.php

# Daily backup at 2 AM
0 2 * * * /usr/bin/php /var/www/html/pragmavet/api/backup.php action=create

# Cleanup old logs monthly
0 3 1 * * /usr/bin/php /var/www/html/pragmavet/includes/automation_engine.php archive=1
```

### 4. Performance Optimization
- Enable PHP OPcache
- Use CDN for Bootstrap/FontAwesome
- Implement Redis/Memcached for session storage
- Enable gzip compression in Apache

### 5. Monitoring
- Set up error logging
- Monitor disk space for backups
- Track failed login attempts
- Review activity logs regularly

---

## Troubleshooting

### Common Issues

**1. Database Connection Error**
- Verify MySQL is running
- Check credentials in `dbconnection.php`
- Ensure database `vet_system` exists

**2. Blank Page / White Screen**
- Enable PHP error display in `php.ini`:
  ```ini
  display_errors = On
  error_reporting = E_ALL
  ```
- Check Apache error logs

**3. 404 Not Found**
- Ensure mod_rewrite is enabled
- Check file paths in includes

**4. Session Issues**
- Clear browser cookies
- Check `session.save_path` in php.ini
- Ensure `session_start()` is called

**5. Email Not Sending**
- Verify SMTP settings
- Check if port 587 is open
- Use Gmail App Password (not regular password)

**6. Backup Fails**
- Check `backups/` directory permissions
- Ensure mysqldump is accessible
- Check disk space

---

## Directory Structure
```
pragmavet/
├── api/                    # REST API endpoints
│   ├── analytics/          # Dashboard analytics
│   ├── appointments.php    # Appointment management
│   ├── notifications.php   # Notification system
│   ├── reports.php         # Report generation
│   ├── backup.php          # Backup & restore
│   └── activity_logs.php   # Activity logs
├── assets/                 # CSS, JS, images
├── database/               # Schema & migrations
├── includes/               # Core libraries
│   ├── security.php        # Security middleware
│   ├── scheduling_engine.php # Scheduling logic
│   └── automation_engine.php # Automation
├── PHPMailer/              # Email library
├── uploads/                # User uploads
├── backups/                # Database backups
├── API_DOCUMENTATION.md    # API docs
├── INSTALLATION.md         # This guide
├── USER_MANUAL.md          # User guide
└── DATABASE_SCHEMA.md      # Schema docs
```

---

## Support
For issues and support:
- **Email:** pragmavet@gmail.com
- **Phone:** 046-8851-221
- **Address:** 0234 Unit B Palico St, Palico 4, Imus, Cavite