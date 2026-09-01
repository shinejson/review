# Company Rating SaaS Platform

A comprehensive multi-tenant SaaS platform for managing company ratings and reviews.

## Features

### Public Features
- **Modern Landing Page** - Professional homepage with statistics and company information
- **Company Listings** - Browse and search companies by name or category
- **Rating System** - Submit ratings (1-5 stars) with reviews
- **Real-time Statistics** - View average ratings and rating distributions
- **Customer Feedback** - Read reviews from other customers

### Tenant Features (Admin Panel)
- **Dashboard** - Overview of ratings, customers, and statistics
- **Rating Management** - View and manage all submitted ratings
- **Customer Management** - Manage companies being rated
- **Category Management** - Organize companies by categories
- **Settings** - Configure tenant-specific settings

### Super Admin Features
- **Multi-tenant Management** - Manage all tenant accounts
- **Subscription Plans** - Create and manage pricing tiers (Starter, Professional, Enterprise)
- **Subscription Management** - Track subscriptions, billing, and renewals
- **Analytics Dashboard** - Revenue tracking, usage statistics, and insights
- **Tenant Details** - View detailed information for each tenant
- **Platform Settings** - Global configuration options

## Installation

1. **Database Setup**
   ```bash
   mysql -u root -p
   ```
   Then import the database:
   ```sql
   source database.sql
   ```

2. **Configure Database Connection**
   Edit `config/database.php` with your database credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'company_rating_saas');
   ```

3. **Set Permissions**
   Ensure the web server has read/write permissions to the project directory.

4. **Access the Application**
   - Public site: `http://localhost/index.php`
   - Companies list: `http://localhost/companies.php`
   - Tenant admin: `http://localhost/admin/login.php`
   - Super admin: `http://localhost/superadmin/login.php`

## Default Credentials

### Super Admin
- Username: `superadmin`
- Password: `superadmin123`

### Tenant Admin (Sample)
- Username: `admin`
- Password: `admin123`

### Sample Tenants
- ABC Corporation: `abc_corporation` / `admin123`
- XYZ Industries: `xyz_industries` / `admin123`

## Project Structure

```
company-rating-saas/
├── config/
│   └── database.php          # Database configuration
├── includes/
│   ├── auth.php             # Authentication functions
│   ├── functions.php        # Helper functions
│   └── header.php           # Common header
├── admin/                   # Tenant admin panel
│   ├── index.php           # Admin dashboard
│   ├── login.php           # Admin login
│   ├── logout.php          # Logout handler
│   ├── ratings.php         # Manage ratings
│   ├── categories.php      # Manage categories
│   ├── customers.php       # Manage customers
│   └── settings.php        # Tenant settings
├── superadmin/             # Super admin panel
│   ├── index.php          # Super admin dashboard
│   ├── login.php          # Super admin login
│   ├── tenants.php        # Manage tenants
│   ├── subscriptions.php  # Manage subscriptions
│   ├── plans.php          # Manage pricing plans
│   ├── analytics.php      # Analytics dashboard
│   ├── tenant_details.php # Tenant details view
│   └── settings.php       # Platform settings
├── rate/
│   └── index.php          # Public rating form
├── api/
│   └── submit_rating.php  # Rating submission API
├── assets/
│   ├── css/
│   │   └── style.css      # Main stylesheet
│   ├── js/
│   │   └── main.js        # JavaScript functions
│   └── img/               # Images directory
├── index.php              # Landing page
├── companies.php          # Company listings
└── database.sql           # Database schema
```

## Database Schema

### Core Tables
- `super_admins` - Super admin users
- `tenants` - Tenant/company accounts
- `subscription_plans` - Pricing plans
- `admins` - Tenant admin users
- `customers` - Companies being rated (belongs to tenants)
- `ratings` - Customer ratings and reviews
- `categories` - Company categories
- `settings` - Platform settings

## Subscription Plans

### Starter ($29.99/month)
- 10 customers
- 100 ratings/month
- Basic analytics
- Email support

### Professional ($79.99/month)
- 50 customers
- 500 ratings/month
- Advanced analytics
- Priority support
- Custom branding

### Enterprise ($199.99/month)
- Unlimited customers
- Unlimited ratings
- Full analytics suite
- 24/7 support
- API access
- White label

## Key Features Implementation

### Multi-tenancy
- Each tenant has isolated customers and ratings
- Subscription-based access control
- Tenant-specific branding and settings

### Rating System
- 5-star rating scale
- Written reviews/comments
- Average rating calculation
- Rating distribution charts
- Real-time statistics

### Search & Filter
- Company name search
- Category filtering
- Real-time client-side filtering

## Security Features

- Password hashing with `password_hash()`
- SQL injection prevention with prepared statements
- XSS protection with `htmlspecialchars()`
- Session-based authentication
- Role-based access control (Super Admin, Tenant Admin, Public)

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

## License

Proprietary - All rights reserved

## Support

For support, please contact: admin@optibiz.com
