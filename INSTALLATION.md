# Installation Guide - Company Rating SaaS

## Prerequisites

- **PHP** >= 7.4
- **MySQL** >= 5.7 or **MariaDB** >= 10.2
- **Apache** or **Nginx** web server
- **Composer** (optional, for future dependencies)

## Step-by-Step Installation

### 1. Download & Extract

Extract the project files to your web server directory:
- **XAMPP/WAMP**: `C:/xampp/htdocs/company-rating-saas/`
- **Linux**: `/var/www/html/company-rating-saas/`
- **Mac**: `/Applications/MAMP/htdocs/company-rating-saas/`

### 2. Create Database

Open phpMyAdmin or MySQL command line:

```sql
CREATE DATABASE company_rating_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Import Database Schema

Import the `database.sql` file:

**Using phpMyAdmin:**
1. Select the `company_rating_saas` database
2. Click on "Import" tab
3. Choose `database.sql` file
4. Click "Go"

**Using MySQL command line:**
```bash
mysql -u root -p company_rating_saas < database.sql
```

### 4. Configure Database Connection

Edit `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');           // Your MySQL username
define('DB_PASS', '');               // Your MySQL password
define('DB_NAME', 'company_rating_saas');
```

### 5. Set File Permissions (Linux/Mac only)

```bash
chmod -R 755 company-rating-saas/
chmod -R 777 company-rating-saas/assets/
```

### 6. Enable Apache mod_rewrite (for clean URLs)

**Linux:**
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

**XAMPP:**
Edit `httpd.conf` and uncomment:
```
LoadModule rewrite_module modules/mod_rewrite.so
```

### 7. Configure Virtual Host (Optional)

Create a virtual host for better development experience:

**Apache:**
```apache
<VirtualHost *:80>
    ServerName company-rating.local
    DocumentRoot "C:/xampp/htdocs/company-rating-saas"
    <Directory "C:/xampp/htdocs/company-rating-saas">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add to your hosts file (`C:\Windows\System32\drivers\etc\hosts` or `/etc/hosts`):
```
127.0.0.1 company-rating.local
```

### 8. Access the Application

Open your browser and navigate to:

- **Public Site**: `http://localhost/company-rating-saas/`
- **Companies**: `http://localhost/company-rating-saas/companies.php`
- **Super Admin**: `http://localhost/company-rating-saas/superadmin/login.php`
- **Tenant Admin**: `http://localhost/company-rating-saas/admin/login.php`

## Default Login Credentials

### Super Admin
- **URL**: `/superadmin/login.php`
- **Username**: `superadmin`
- **Password**: `superadmin123`

### Sample Tenant Admin
- **URL**: `/admin/login.php`
- **Username**: `admin`
- **Password**: `admin123`

### Sample Tenants
1. **ABC Corporation**
   - Username: `abc_corporation`
   - Password: `admin123`

2. **XYZ Industries**
   - Username: `xyz_industries`
   - Password: `admin123`

## Workspace pages (tenant admin)

The workspace sidebar has three data-driven screens beyond the dashboard and
the ratings list:

| Page | What it does |
| --- | --- |
| `admin/analysis.php` | Growth and progress analysis of the responses: volume and score trends against the previous period, per-company momentum (improving / steady / slipping), keyword themes pulled from the comments, and generated next actions. Filter by company and by 30 / 90 / 180 / 365 days. |
| `admin/social.php` | Turns review comments into social posts. Pick a review, Optibiz drafts a caption for Facebook, Instagram, LinkedIn or X, then publishes through that network's API (or keeps it as a draft). Every attempt is logged in the post library. |
| `admin/subscription.php` | The workspace's own plan, live usage against its limits, all available plans and an upgrade/downgrade request. Requests appear in `superadmin/subscriptions.php`, where approving one applies the new plan and price. |

### Social network credentials

`admin/social.php` posts through the official HTTP APIs, so each network needs a
token pasted into its connection card (stored per workspace in
`social_accounts`, never displayed again in full):

- **Facebook Page** — Page ID + long-lived Page token with `pages_manage_posts`
- **Instagram Business** — IG user ID + Page token with `instagram_content_publish`
- **LinkedIn Page** — organisation URN/ID + OAuth 2 token with `w_organization_social`
- **X (Twitter)** — OAuth 2 user token with `tweet.write`

Use the **Test** button on a card to validate a token before posting. Without a
token the composer still works — save the draft, copy the text or use the share
window. The PHP `curl` extension must be enabled for live publishing.

### New tables

`subscription_requests`, `social_accounts` and `social_posts` are part of
`database.sql`. An existing installation does not need a manual migration: the
pages create the tables on first load if they are missing.

## Troubleshooting

### Database Connection Error
- Verify database credentials in `config/database.php`
- Ensure MySQL service is running
- Check if database exists and is accessible

### 404 Errors / Page Not Found
- Enable Apache `mod_rewrite` module
- Check `.htaccess` file exists in root directory
- Verify `AllowOverride All` in Apache configuration

### Permission Denied Errors
- Set proper file permissions (755 for directories, 644 for files)
- Ensure web server has read access to all files

### Blank White Screen
- Enable PHP error reporting in `php.ini`:
  ```ini
  display_errors = On
  error_reporting = E_ALL
  ```
- Check PHP error logs for specific errors

### CSS/JS Not Loading
- Clear browser cache (Ctrl+F5)
- Check browser console for 404 errors
- Verify file paths are correct

## Security Recommendations

### For Production Deployment:

1. **Change Default Passwords**
   ```sql
   UPDATE super_admins SET password = '$2y$10$...' WHERE username = 'superadmin';
   UPDATE admins SET password = '$2y$10$...' WHERE username = 'admin';
   ```

2. **Update Database Credentials**
   - Use strong MySQL passwords
   - Create dedicated database user with limited privileges

3. **Enable HTTPS**
   - Install SSL certificate
   - Uncomment HTTPS redirect in `.htaccess`

4. **Secure File Permissions**
   ```bash
   chmod 600 config/database.php
   chmod 755 -R .
   chmod 644 -R *.php
   ```

5. **Hide Sensitive Files**
   - Move `database.sql` outside web root after installation
   - Add to `.htaccess`:
   ```apache
   <Files "database.sql">
       Order allow,deny
       Deny from all
   </Files>
   ```

6. **Enable PHP Security Settings**
   ```ini
   expose_php = Off
   display_errors = Off
   log_errors = On
   ```

7. **Implement Rate Limiting**
   - Limit login attempts
   - Add CAPTCHA to public forms

8. **Regular Backups**
   - Automate database backups
   - Backup uploaded files and configuration

## Next Steps

1. **Customize Branding**
   - Update logo and colors in CSS
   - Modify company information

2. **Add More Features**
   - Email notifications for new ratings
   - CSV export functionality
   - Advanced analytics dashboard

3. **Configure Email**
   - Set up SMTP for transactional emails
   - Configure email templates

4. **SEO Optimization**
   - Add meta descriptions
   - Create sitemap.xml
   - Implement schema markup

## Support

For technical support, please refer to:
- README.md for feature documentation
- Check PHP error logs
- Review browser console for JavaScript errors

## License

Proprietary - All rights reserved
