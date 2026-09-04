-- Create database
CREATE DATABASE IF NOT EXISTS company_rating_saas;
USE company_rating_saas;

-- Super Admins table
CREATE TABLE IF NOT EXISTS super_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    permissions TEXT NULL,
    is_owner TINYINT(1) NOT NULL DEFAULT 0
);

-- Insert default super admin (password: superadmin123). The first account
-- is the platform owner: it always keeps every permission.
INSERT INTO super_admins (username, password, email, is_owner) VALUES 
('superadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin@example.com', 1);

-- Subscription Plans table
CREATE TABLE IF NOT EXISTS subscription_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_name VARCHAR(100) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    max_ratings INT NOT NULL,
    max_customers INT NOT NULL,
    features TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample subscription plans
INSERT INTO subscription_plans (plan_name, price, max_ratings, max_customers, features) VALUES 
('Starter', 29.99, 100, 10, 'Basic analytics, Email support, 10 customers, 100 ratings/month'),
('Professional', 79.99, 500, 50, 'Advanced analytics, Priority support, 50 customers, 500 ratings/month, Custom branding'),
('Enterprise', 199.99, 9999, 999, 'Full analytics suite, 24/7 support, Unlimited customers, Unlimited ratings, API access, White label');

-- Tenants table (Companies using the SaaS)
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    plan_id INT,
    subscription_status ENUM('trial', 'active', 'inactive', 'cancelled') DEFAULT 'trial',
    subscription_price DECIMAL(10, 2) DEFAULT 0,
    subscription_start_date DATE,
    subscription_end_date DATE,
    auto_renew BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);

-- Insert sample tenants
INSERT INTO tenants (company_name, email, phone, username, password, plan_id, subscription_status, subscription_price, subscription_start_date, subscription_end_date) VALUES 
('ABC Corporation', 'admin@abccorp.com', '555-0101', 'abc_corporation', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 'active', 79.99, '2026-01-01', '2026-12-31'),
('XYZ Industries', 'admin@xyzind.com', '555-0102', 'xyz_industries', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 'active', 29.99, '2026-02-01', '2027-02-01');

-- Admins table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin (password: admin123)
INSERT INTO admins (username, password, email) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com');

-- Categories table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample categories
INSERT INTO categories (name) VALUES 
('Technology'),
('Healthcare'),
('Finance'),
('Retail'),
('Manufacturing');

-- Customers (Companies) table - belongs to tenants
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    category_id INT,
    email VARCHAR(100),
    phone VARCHAR(20),
    website VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

-- Insert sample customers (linked to tenants)
INSERT INTO customers (tenant_id, company_name, category_id, email, website) VALUES 
(1, 'Tech Solutions Inc', 1, 'info@techsolutions.com', 'www.techsolutions.com'),
(1, 'Health Care Plus', 2, 'contact@healthcareplus.com', 'www.healthcareplus.com'),
(2, 'Finance Pro', 3, 'support@financepro.com', 'www.financepro.com');

-- Rating questions created by tenant / admin
CREATE TABLE IF NOT EXISTS rating_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    question_text VARCHAR(500) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ratings table
CREATE TABLE IF NOT EXISTS ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    question_id INT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    customer_name VARCHAR(100) NOT NULL,
    customer_email VARCHAR(100) NOT NULL,
    comment TEXT,
    admin_reply TEXT NULL,
    responded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES rating_questions(id) ON DELETE SET NULL
);

-- Insert sample rating questions
INSERT INTO rating_questions (tenant_id, question_text, is_active) VALUES
(1, 'How satisfied are you with the speed and responsiveness of our technical support team?', 1),
(1, 'How would you rate the quality and reliability of our technology solutions?', 1),
(1, 'How likely are you to recommend Tech Solutions Inc to colleagues or other businesses?', 1);

-- Insert sample ratings
INSERT INTO ratings (company_id, question_id, rating, customer_name, customer_email, comment) VALUES 
(1, NULL, 5, 'John Doe', 'john@example.com', 'Excellent service!'),
(1, NULL, 4, 'Jane Smith', 'jane@example.com', 'Very good experience.'),
(1, 1, 5, 'Michael Scott', 'michael@dundermifflin.com', 'Tech support team answered in under 2 minutes and fixed everything!'),
(2, NULL, 5, 'Bob Johnson', 'bob@example.com', 'Outstanding care!'),
(3, NULL, 3, 'Alice Brown', 'alice@example.com', 'Good but could be better.');

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO settings (setting_key, setting_value) VALUES 
('site_name', 'Company Rating SaaS'),
('admin_email', 'admin@example.com'),
('ratings_per_page', '10');

-- Quote requests from the public "Get Started" wizard (index.php)
CREATE TABLE IF NOT EXISTS quote_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    website VARCHAR(255),
    category_id INT,
    plan_id INT,
    location VARCHAR(255),
    num_companies INT,
    expected_ratings INT,
    notes TEXT,
    status ENUM('pending', 'contacted', 'converted', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);
