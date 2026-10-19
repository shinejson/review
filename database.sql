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
    annual_discount_percent TINYINT(3) DEFAULT 0 COMMENT 'Discount percentage for annual billing (0-100)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample subscription plans
INSERT INTO subscription_plans (plan_name, price, max_ratings, max_customers, features, annual_discount_percent) VALUES 
('Starter', 29.99, 100, 10, 'Basic analytics, Email support, 10 customers, 100 ratings/month', 10),
('Professional', 79.99, 500, 50, 'Advanced analytics, Priority support, 50 customers, 500 ratings/month, Custom branding', 15),
('Enterprise', 199.99, 9999, 999, 'Full analytics suite, 24/7 support, Unlimited customers, Unlimited ratings, API access, White label', 20);

-- Tenants table (Companies using the SaaS) — with Real Public ID + onboarding email
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(32) NULL COMMENT 'Real public ID e.g. OPT-8K2F9Q1A, not DB id',
    setup_token VARCHAR(128) NULL COMMENT 'Secure token for password setup email',
    setup_token_expires DATETIME NULL COMMENT 'Expiry for setup link (48h)',
    email_verified_at DATETIME NULL COMMENT 'When password was set via email link',
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
    UNIQUE KEY uniq_tenant_public_id (public_id),
    INDEX idx_tenant_setup_token (setup_token),
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);

-- Insert sample tenants with Real IDs (OPT-XXXXXXXX) — password: password (hashed as admin123 placeholder)
INSERT INTO tenants (public_id, company_name, email, phone, username, password, plan_id, subscription_status, subscription_price, subscription_start_date, subscription_end_date, email_verified_at) VALUES 
('OPT-A1B2C3D4', 'ABC Corporation', 'admin@abccorp.com', '555-0101', 'abc_corporation', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 'active', 79.99, '2026-01-01', '2026-12-31', NOW()),
('OPT-E5F6G7H8', 'XYZ Industries', 'admin@xyzind.com', '555-0102', 'xyz_industries', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 'active', 29.99, '2026-02-01', '2027-02-01', NOW());

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

-- Team members: staff accounts created by a tenant (workspace owner) to help run
-- their workspace. They sign in at /admin/login.php and are scoped to tenant_id
-- with module-level access stored as comma-separated keys in `permissions`.
CREATE TABLE IF NOT EXISTS team_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'staff',
    permissions VARCHAR(1000) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_team_username (username),
    UNIQUE KEY uniq_team_email (email),
    KEY idx_team_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- User sessions: one row per browser sign-in, for both panels
-- (portal = 'superadmin' for the control center, 'admin' for the tenant
-- workspace). Closing a row signs that browser out on its next request.
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_token VARCHAR(64) NOT NULL,
    portal VARCHAR(20) NOT NULL,
    user_id INT NOT NULL,
    user_label VARCHAR(120) NULL,
    user_kind VARCHAR(20) NOT NULL DEFAULT 'admin',
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NULL,
    logged_out_at TIMESTAMP NULL,
    logout_reason VARCHAR(20) NULL,
    INDEX idx_user_sessions_token (session_token),
    INDEX idx_user_sessions_user (portal, user_id, logged_out_at)
);

-- System activity logs table
-- Tracks all actions across both superadmin and admin panels
CREATE TABLE IF NOT EXISTS system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    portal VARCHAR(20) NOT NULL COMMENT 'superadmin or admin',
    tenant_id INT NULL COMMENT 'Workspace the event belongs to; NULL for platform-wide events',
    user_id INT NULL,
    user_label VARCHAR(120) NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    entity_type VARCHAR(50) NULL COMMENT 'tenant, user, subscription, backup, etc.',
    entity_id INT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_portal (portal),
    INDEX idx_logs_tenant (tenant_id),
    INDEX idx_logs_user (portal, user_id),
    INDEX idx_logs_action (action),
    INDEX idx_logs_created (created_at)
);

-- Workspace (tenant) backups
-- One row per export produced by admin/backups.php; the file itself lives
-- in backups/tenants/<tenant_id>/. Every row is tenant-scoped so a
-- workspace can only ever list, download or delete its own files.
CREATE TABLE IF NOT EXISTS tenant_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    filename VARCHAR(190) NOT NULL,
    format ENUM('json.gz', 'json') NOT NULL DEFAULT 'json.gz',
    size_bytes BIGINT NOT NULL DEFAULT 0,
    table_count INT NOT NULL DEFAULT 0 COMMENT 'Sections included in the export',
    record_count INT NOT NULL DEFAULT 0 COMMENT 'Rows included in the export',
    created_by_label VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tb_tenant (tenant_id, created_at),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    whatsapp_number VARCHAR(30) NULL COMMENT 'Click-to-chat number shown as a WhatsApp button on the public pages',
    website VARCHAR(255),
    google_store_url VARCHAR(500) NULL COMMENT 'Google Business Profile review link',
    booster_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = route 4-5 star reviews to Google Business Profile',
    booster_min_stars TINYINT(1) NOT NULL DEFAULT 4 COMMENT 'Minimum rating threshold to trigger Google review prompt',
    address VARCHAR(500) NULL,
    google_map_url VARCHAR(1000) NULL COMMENT 'Google Maps direction / location URL',
    map_embed_code TEXT NULL COMMENT 'Google Map embed iframe code or embed URL',
    location_description TEXT NULL COMMENT 'Location directions and landmark description',
    facebook_url VARCHAR(255) NULL,
    instagram_url VARCHAR(255) NULL,
    twitter_url VARCHAR(255) NULL,
    linkedin_url VARCHAR(255) NULL,
    tiktok_url VARCHAR(255) NULL,
    youtube_url VARCHAR(255) NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

-- Insert sample customers (linked to tenants)
INSERT INTO customers (tenant_id, company_name, category_id, email, phone, whatsapp_number, website, google_store_url) VALUES 
(1, 'Tech Solutions Inc', 1, 'info@techsolutions.com', '030 245 0101', '+233 24 555 0101', 'www.techsolutions.com', 'https://search.google.com/local/writereview?placeid=ChIJN1t_tDeuEmsRUsoyG83frY4'),
(1, 'Health Care Plus', 2, 'contact@healthcareplus.com', NULL, NULL, 'www.healthcareplus.com', NULL),
(2, 'Finance Pro', 3, 'support@financepro.com', NULL, NULL, 'www.financepro.com', NULL);

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
    -- reported / photos / helpful_count are added to older installs by
    -- migrate_google_reviews.php; declaring them here too means a fresh
    -- import matches what the app queries (includes/functions.php,
    -- superadmin/reviews.php) instead of erroring until the migration runs.
    reported TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = a customer flagged it for moderation',
    responded_at TIMESTAMP NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_type VARCHAR(30) NULL,
    momo_ref VARCHAR(100) NULL,
    receipt_photo VARCHAR(255) NULL,
    is_escalated TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = flagged for private resolution (1-3 stars)',
    escalation_status VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT 'none, pending, resolved',
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

-- Signed-up customers: every named review submission "signs up" the
-- customer. After signup they confirm Follow + Like (social) to earn
-- the Verified Customer badge. Viewed / emailed from admin/customers.php.
CREATE TABLE IF NOT EXISTS site_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    company_id INT NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_email VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(30) NULL,
    rating_id INT NULL,
    rating_value TINYINT NULL,
    is_following TINYINT(1) NOT NULL DEFAULT 0,
    follow_platform VARCHAR(30) NULL,
    is_liked TINYINT(1) NOT NULL DEFAULT 0,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_type VARCHAR(30) NULL,
    momo_ref VARCHAR(100) NULL,
    last_activity_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_sc_company_email (company_id, customer_email),
    INDEX idx_sc_tenant (tenant_id),
    INDEX idx_sc_company (company_id),
    INDEX idx_sc_verified (company_id, is_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- Quote requests from the public "Get Started" wizard (index.php) — Real ID + tenant link
CREATE TABLE IF NOT EXISTS quote_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(32) NULL COMMENT 'Real public ID e.g. QTE-8K2F9Q1A, not DB id',
    converted_tenant_id INT NULL COMMENT 'Tenant created from this quote',
    setup_email_sent TINYINT(1) NOT NULL DEFAULT 0,
    setup_token VARCHAR(128) NULL,
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
    UNIQUE KEY uniq_quote_public_id (public_id),
    INDEX idx_quote_tenant (converted_tenant_id),
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
);

-- Plan change requests filed from the workspace (admin/subscription.php)
-- and approved by the platform owner (superadmin/subscriptions.php).
CREATE TABLE IF NOT EXISTS subscription_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    current_plan_id INT NULL,
    requested_plan_id INT NOT NULL,
    direction ENUM('upgrade', 'downgrade', 'same') DEFAULT 'upgrade',
    note TEXT NULL,
    status ENUM('pending', 'approved', 'declined', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX idx_sub_req_tenant (tenant_id),
    INDEX idx_sub_req_status (status),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_plan_id) REFERENCES subscription_plans(id)
);

-- ------------------------------------------------------------
-- PAYMENTS & BILLING
-- ------------------------------------------------------------
-- Payment integrations the platform owner configures
-- (superadmin/payment_gateways.php). One row per provider; the
-- secret key is never shown again once saved.
CREATE TABLE IF NOT EXISTS payment_gateways (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gateway_key VARCHAR(40) NOT NULL COMMENT 'paystack | flutterwave | bank_transfer',
    display_name VARCHAR(100) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    mode ENUM('test','live') NOT NULL DEFAULT 'test',
    public_key VARCHAR(255) NULL,
    secret_key VARCHAR(255) NULL,
    webhook_secret VARCHAR(255) NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'GHS',
    bank_name VARCHAR(140) NULL COMMENT 'Manual transfers: bank the tenant pays into',
    account_name VARCHAR(140) NULL,
    account_number VARCHAR(80) NULL,
    instructions TEXT NULL COMMENT 'What the tenant should do / quote as reference',
    connection_status VARCHAR(20) NOT NULL DEFAULT 'unverified',
    connection_note VARCHAR(255) NULL,
    last_checked_at DATETIME NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_gateway_key (gateway_key),
    INDEX idx_gateway_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the three shipped integrations (switched off until configured).
INSERT INTO payment_gateways (gateway_key, display_name, is_enabled, mode, currency, instructions, connection_status, connection_note, sort_order) VALUES
('paystack', 'Paystack', 0, 'test', 'GHS', 'Cards, mobile money, bank transfer and USSD across Ghana, Nigeria, Kenya and South Africa.', 'unverified', 'Not configured yet.', 1),
('flutterwave', 'Flutterwave', 0, 'test', 'GHS', 'Cards, mobile money, bank accounts and Barion wallets in 30+ African markets.', 'unverified', 'Not configured yet.', 2),
('bank_transfer', 'Bank transfer / Manual', 0, 'test', 'GHS', 'Publish bank or mobile-money details, then confirm the transfer yourself before the plan activates.', 'unverified', 'Not configured yet.', 3);

-- Invoices raised against a workspace (renewals, upgrades, add-ons).
-- A tenant never re-prices itself: the invoice carries the amount and the
-- number of months, and only a confirmed payment applies it.
CREATE TABLE IF NOT EXISTS payment_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(40) NOT NULL,
    tenant_id INT NOT NULL,
    plan_id INT NULL,
    purpose ENUM('new','renewal','upgrade','downgrade','addon') NOT NULL DEFAULT 'renewal',
    subject VARCHAR(190) NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(8) NOT NULL DEFAULT '',
    months INT NOT NULL DEFAULT 12,
    period_start DATE NULL,
    period_end DATE NULL,
    status ENUM('draft','open','processing','paid','overdue','cancelled','refunded') NOT NULL DEFAULT 'open'
        COMMENT 'processing = money captured, waiting for the platform owner',
    gateway_key VARCHAR(40) NULL,
    checkout_reference VARCHAR(120) NULL,
    due_date DATE NULL,
    paid_at DATETIME NULL,
    issued_by VARCHAR(120) NULL,
    confirmed_by VARCHAR(120) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_invoice_number (invoice_number),
    INDEX idx_invoice_tenant (tenant_id),
    INDEX idx_invoice_status (status),
    INDEX idx_invoice_created (created_at),
    INDEX idx_invoice_reference (checkout_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The money ledger: one row per payment received, whether it was taken by
-- a gateway or recorded by hand. `status` keeps unconfirmed gateway money
-- out of the revenue figures:
--   pending   = captured by the gateway, waiting for approval
--   confirmed = counted as revenue (approved, or recorded manually)
--   failed    = declined by the gateway, or rejected during review
--   refunded  = money returned to the tenant
CREATE TABLE IF NOT EXISTS subscription_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    invoice_id INT NULL,
    receipt_number VARCHAR(32) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(8) NOT NULL DEFAULT '',
    fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Gateway processing fee, when reported',
    payment_method VARCHAR(50) NOT NULL DEFAULT 'Bank Wire',
    gateway_key VARCHAR(40) NULL,
    gateway_reference VARCHAR(120) NULL,
    transaction_ref VARCHAR(100) NULL,
    channel VARCHAR(40) NULL COMMENT 'card, mobile money, bank transfer, ussd…',
    status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
    source VARCHAR(20) NOT NULL DEFAULT 'offline' COMMENT 'gateway | offline',
    payer_name VARCHAR(140) NULL,
    payer_email VARCHAR(160) NULL,
    payer_phone VARCHAR(40) NULL,
    months_extended INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    reject_reason VARCHAR(255) NULL,
    recorded_by VARCHAR(100) NULL,
    verified_by VARCHAR(120) NULL,
    verified_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_pmt (tenant_id),
    INDEX idx_receipt_num (receipt_number),
    INDEX idx_payment_status (status),
    INDEX idx_payment_invoice (invoice_id),
    INDEX idx_payment_reference (gateway_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Refunds and credit notes issued against a payment.
CREATE TABLE IF NOT EXISTS payment_refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    payment_id INT NULL,
    invoice_id INT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(8) NOT NULL DEFAULT '',
    kind ENUM('refund','credit') NOT NULL DEFAULT 'refund',
    reason VARCHAR(255) NULL,
    status ENUM('pending','processed','rejected') NOT NULL DEFAULT 'pending',
    gateway_key VARCHAR(40) NULL,
    gateway_reference VARCHAR(120) NULL,
    requested_by VARCHAR(120) NULL,
    processed_by VARCHAR(120) NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refund_tenant (tenant_id),
    INDEX idx_refund_payment (payment_id),
    INDEX idx_refund_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit trail for every webhook and checkout callback we receive, whether
-- or not the signature checked out.
CREATE TABLE IF NOT EXISTS payment_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    gateway_key VARCHAR(40) NULL,
    event_type VARCHAR(80) NULL,
    reference VARCHAR(120) NULL,
    invoice_id INT NULL,
    payment_id INT NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    payload MEDIUMTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_gateway (gateway_key),
    INDEX idx_event_reference (reference),
    INDEX idx_event_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Social network credentials per workspace (admin/social.php).
-- One row per platform; the access token is supplied by the workspace
-- owner from the network's developer console.
CREATE TABLE IF NOT EXISTS social_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    platform VARCHAR(30) NOT NULL,
    account_name VARCHAR(150) NULL,
    account_ref VARCHAR(190) NULL,
    access_token TEXT NULL,
    status ENUM('connected', 'disabled') DEFAULT 'connected',
    last_error TEXT NULL,
    last_used_at DATETIME NULL,
    metadata TEXT NULL COMMENT 'JSON data for custom platforms',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tenant_platform (tenant_id, platform)
);

-- Every post drafted or published from a review (admin/social.php)
CREATE TABLE IF NOT EXISTS social_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    company_id INT NULL,
    rating_id INT NULL,
    platform VARCHAR(30) NOT NULL,
    content TEXT NOT NULL,
    status ENUM('draft', 'published', 'failed') DEFAULT 'draft',
    remote_id VARCHAR(190) NULL,
    remote_url VARCHAR(255) NULL,
    error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    INDEX idx_social_posts_tenant (tenant_id),
    INDEX idx_social_posts_status (status)
);

-- Customer review invitations sent via WhatsApp / SMS (admin/whatsapp_sender.php)
CREATE TABLE IF NOT EXISTS review_invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(30) NOT NULL,
    order_ref VARCHAR(100) NULL,
    template_key VARCHAR(50) NOT NULL,
    invite_message TEXT NOT NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_tenant (tenant_id),
    INDEX idx_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Public Community Questions & Official Answers (rate/index.php & admin/qa.php)
CREATE TABLE IF NOT EXISTS community_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    company_id INT NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_email VARCHAR(100) NULL,
    customer_phone VARCHAR(30) NULL,
    question_text TEXT NOT NULL,
    official_answer TEXT NULL,
    answered_by INT NULL,
    answered_at DATETIME NULL,
    helpful_count INT NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('published', 'pending', 'hidden') NOT NULL DEFAULT 'published',
    created_at DATETIME NOT NULL,
    INDEX idx_comp_status (company_id, status),
    INDEX idx_tenant (tenant_id),
    INDEX idx_pinned (is_pinned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upvotes tracking for Community Q&A
CREATE TABLE IF NOT EXISTS qa_helpful_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    voter_ip VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_qa_vote (question_id, voter_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Performance & Interaction Analytics Events
CREATE TABLE IF NOT EXISTS analytics_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    company_id INT NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    event_category VARCHAR(60) NULL,
    event_label VARCHAR(255) NULL,
    traffic_source VARCHAR(50) NOT NULL DEFAULT 'direct',
    page_url VARCHAR(255) NULL,
    referrer VARCHAR(255) NULL,
    session_id VARCHAR(64) NULL,
    visitor_ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    device_type VARCHAR(20) DEFAULT 'desktop',
    created_at DATETIME NOT NULL,
    INDEX idx_tenant_created (tenant_id, created_at),
    INDEX idx_comp_event_created (company_id, event_type, created_at),
    INDEX idx_type_created (event_type, created_at),
    INDEX idx_source (traffic_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-- In-app notification inbox, shared by both panels.
-- `audience` decides who reads a row: 'platform' is the control center
-- queue (superadmin/notifications.php) and 'tenant' is one workspace's
-- inbox (admin/notifications.php), where `tenant_id` is the boundary.
-- Rows are filed by includes/notifications.php — either from a writer or
-- from the throttled scan of the live tables — and `dedupe_key` is what
-- keeps a repeated event from piling up. Dismissed rows are kept as
-- tombstones (deleted_at) so the scan cannot resurrect them.
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audience VARCHAR(20) NOT NULL DEFAULT 'tenant' COMMENT 'platform (control center) | tenant (workspace)',
    tenant_id INT NOT NULL DEFAULT 0 COMMENT '0 for platform-wide rows',
    type VARCHAR(40) NOT NULL,
    title VARCHAR(190) NOT NULL,
    message VARCHAR(500) NULL,
    link VARCHAR(255) NULL COMMENT 'relative to the panel that owns the row',
    icon VARCHAR(30) NULL,
    tone VARCHAR(20) NOT NULL DEFAULT 'info' COMMENT 'info | success | warning | danger',
    entity_type VARCHAR(40) NULL,
    entity_id INT NULL,
    dedupe_key VARCHAR(190) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    deleted_at DATETIME NULL COMMENT 'dismissed rows are kept so the sync cannot resurrect them',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notif_dedupe (dedupe_key),
    INDEX idx_notif_scope (audience, tenant_id, is_read, created_at),
    INDEX idx_notif_type (type),
    INDEX idx_notif_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- LOGIN ATTEMPT TRACKING (Security: Block after 3 failed tries)
-- ============================================================
-- Alter team_members to add login attempt tracking
ALTER TABLE IF EXISTS team_members ADD COLUMN IF NOT EXISTS failed_login_attempts INT NOT NULL DEFAULT 0;
ALTER TABLE IF EXISTS team_members ADD COLUMN IF NOT EXISTS last_failed_attempt_at DATETIME NULL;
ALTER TABLE IF EXISTS team_members ADD COLUMN IF NOT EXISTS account_locked_until DATETIME NULL;

-- Alter tenants to add login attempt tracking
ALTER TABLE IF EXISTS tenants ADD COLUMN IF NOT EXISTS failed_login_attempts INT NOT NULL DEFAULT 0;
ALTER TABLE IF EXISTS tenants ADD COLUMN IF NOT EXISTS last_failed_attempt_at DATETIME NULL;
ALTER TABLE IF EXISTS tenants ADD COLUMN IF NOT EXISTS account_locked_until DATETIME NULL;

-- ============================================================
-- MONETIZATION ENGINE (4 Revenue Streams)
-- ============================================================
-- Extend customers with premium columns (idempotent via ADD COLUMN IF NOT EXISTS)
ALTER TABLE customers ADD COLUMN IF NOT EXISTS is_claimed TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS claimed_tenant_id INT NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS claimed_at DATETIME NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS premium_tier ENUM('none','essential','growth','authority') NOT NULL DEFAULT 'none';
ALTER TABLE customers ADD COLUMN IF NOT EXISTS premium_status ENUM('inactive','active','expired','cancelled') NOT NULL DEFAULT 'inactive';
ALTER TABLE customers ADD COLUMN IF NOT EXISTS premium_expires_at DATETIME NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS premium_price DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS custom_banner_url VARCHAR(500) NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS custom_cta_label VARCHAR(80) NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS custom_cta_url VARCHAR(500) NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS remove_competitor_ads TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS verified_badge TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS pinned_review_id INT NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS premium_badge_label VARCHAR(40) NULL;

CREATE TABLE IF NOT EXISTS premium_claims (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL, claimant_tenant_id INT NOT NULL,
  claimant_name VARCHAR(190) NULL, claimant_email VARCHAR(160) NULL, claimant_phone VARCHAR(40) NULL,
  claim_method ENUM('email_domain','phone_match','document','manual') NOT NULL DEFAULT 'email_domain',
  claim_status ENUM('pending','verified','approved','rejected') NOT NULL DEFAULT 'pending',
  verification_token VARCHAR(64) NULL, verification_code VARCHAR(12) NULL, proof_document VARCHAR(500) NULL,
  reviewer_note TEXT NULL, reviewed_by VARCHAR(120) NULL, verified_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_claim_company (company_id), INDEX idx_claim_tenant (claimant_tenant_id), INDEX idx_claim_status (claim_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS premium_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, tenant_id INT NOT NULL,
  tier ENUM('essential','growth','authority') NOT NULL DEFAULT 'essential',
  status ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00, currency VARCHAR(8) NOT NULL DEFAULT 'GHS',
  billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  invoice_id INT NULL, payment_id INT NULL, starts_at DATETIME NULL, expires_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prem_company (company_id), INDEX idx_prem_tenant (tenant_id), INDEX idx_prem_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sponsored_listings (
  id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, company_id INT NOT NULL, category_id INT NULL,
  tier ENUM('sponsored','featured') NOT NULL DEFAULT 'sponsored', badge_label VARCHAR(40) NOT NULL DEFAULT 'Sponsored',
  title VARCHAR(190) NULL, cta_label VARCHAR(60) NULL, cta_url VARCHAR(500) NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00, currency VARCHAR(8) NOT NULL DEFAULT 'GHS', duration_days INT NOT NULL DEFAULT 30,
  starts_at DATETIME NULL, ends_at DATETIME NULL,
  status ENUM('pending','active','expired','rejected','cancelled') NOT NULL DEFAULT 'pending',
  invoice_id INT NULL, payment_id INT NULL, impressions INT NOT NULL DEFAULT 0, clicks INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_spons_company (company_id), INDEX idx_spons_category (category_id), INDEX idx_spons_status (status), INDEX idx_spons_ends (ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS widget_configs (
  id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, company_id INT NOT NULL,
  widget_key VARCHAR(32) NOT NULL, tier ENUM('free','pro','agency') NOT NULL DEFAULT 'free',
  status ENUM('active','suspended','expired') NOT NULL DEFAULT 'active',
  theme ENUM('light','dark','brand') NOT NULL DEFAULT 'light', layout ENUM('card','badge','carousel','grid') NOT NULL DEFAULT 'card',
  limit_count INT NOT NULL DEFAULT 5, hide_low_rating TINYINT(1) NOT NULL DEFAULT 1, show_verified_only TINYINT(1) NOT NULL DEFAULT 0,
  custom_css TEXT NULL, allowed_domains TEXT NULL, api_key VARCHAR(64) NULL,
  views_total INT NOT NULL DEFAULT 0, clicks_total INT NOT NULL DEFAULT 0, expires_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_widget_key (widget_key),
  INDEX idx_widget_tenant (tenant_id), INDEX idx_widget_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sms_providers (
  id INT AUTO_INCREMENT PRIMARY KEY, provider_key VARCHAR(40) NOT NULL, display_name VARCHAR(100) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0, api_url VARCHAR(500) NULL, api_key VARCHAR(255) NULL, api_secret VARCHAR(255) NULL,
  sender_id VARCHAR(32) NULL, connection_status VARCHAR(20) NOT NULL DEFAULT 'unverified', connection_note VARCHAR(255) NULL,
  last_tested_at DATETIME NULL, sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_provider_key (provider_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO sms_providers (provider_key, display_name, is_enabled, api_url, sender_id, connection_status, connection_note, sort_order) VALUES
('hubtel','Hubtel SMS',0,'https://sms.hubtel.com/v1/messages/send','Optibiz','unverified','Ghana leading gateway — high deliverability for MTN, Vodafone, AirtelTigo.',1),
('mnotify','mNotify',0,'https://api.mnotify.com/api/sms/quick','Optibiz','unverified','Reliable Ghana bulk SMS with Sender ID registration.',2),
('africastalking','Africa\'s Talking',0,'https://api.africastalking.com/version1/messaging','Optibiz','unverified','Pan-African SMS & Voice, strong in Kenya, Nigeria, Ghana.',3),
('nalo','Nalo Solutions',0,'https://sms.nalosolutions.com/sms/backend/sendsms/','Optibiz','unverified','Ghana mobile money & SMS aggregator.',4),
('twilio','Twilio',0,'https://api.twilio.com/2010-04-01/Accounts','Optibiz','unverified','Global SMS fallback for international numbers.',5),
('manual','Manual / Offline',0,'','Optibiz','unverified','Log SMS sent outside platform & track review conversion.',9);
CREATE TABLE IF NOT EXISTS sms_credits_wallet (
  id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, balance INT NOT NULL DEFAULT 0, total_purchased INT NOT NULL DEFAULT 0, total_used INT NOT NULL DEFAULT 0, last_topup_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_wallet_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sms_campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, company_id INT NOT NULL, provider_key VARCHAR(40) NOT NULL DEFAULT '', campaign_name VARCHAR(190) NOT NULL, message_body TEXT NOT NULL, template_key VARCHAR(40) NULL, recipient_count INT NOT NULL DEFAULT 0, recipients_json MEDIUMTEXT NULL, audience_filter VARCHAR(40) NULL, status ENUM('draft','queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft', cost DECIMAL(10,2) NOT NULL DEFAULT 0.00, credits_used INT NOT NULL DEFAULT 0, sms_price_per DECIMAL(10,4) NOT NULL DEFAULT 0.00, invoice_id INT NULL, scheduled_at DATETIME NULL, sent_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_sms_tenant (tenant_id), INDEX idx_sms_company (company_id), INDEX idx_sms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sms_delivery_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, campaign_id INT NULL, tenant_id INT NOT NULL, company_id INT NOT NULL, provider_key VARCHAR(40) NULL, recipient_phone VARCHAR(40) NOT NULL, recipient_name VARCHAR(190) NULL, message_body TEXT NULL, provider_message_id VARCHAR(120) NULL, status ENUM('queued','sent','delivered','failed','undelivered') NOT NULL DEFAULT 'queued', failure_reason VARCHAR(255) NULL, cost DECIMAL(10,4) NOT NULL DEFAULT 0.00, sent_at DATETIME NULL, delivered_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_smslog_campaign (campaign_id), INDEX idx_smslog_tenant (tenant_id), INDEX idx_smslog_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS monetization_settings (
  id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(80) NOT NULL, setting_value TEXT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_monet_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS monetization_plans (
  id INT AUTO_INCREMENT PRIMARY KEY, plan_code VARCHAR(40) NOT NULL, plan_name VARCHAR(100) NOT NULL, stream ENUM('premium','widget','sponsored','sms') NOT NULL, tier VARCHAR(40) NOT NULL, price DECIMAL(10,2) NOT NULL DEFAULT 0.00, annual_discount_percent INT NOT NULL DEFAULT 0, features TEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_plan_code (plan_code), INDEX idx_mplan_stream (stream)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO monetization_plans (plan_code, plan_name, stream, tier, price, annual_discount_percent, features, sort_order) VALUES
('premium_essential','Essential Claim','premium','essential',149.00,15,'Claim your business profile\nRemove competitor ads\nVerified badge\nCustom banner upload',1),
('premium_growth','Growth Premium','premium','growth',299.00,20,'Everything in Essential\nPin 3 positive reviews to top\nPriority in category ranking\nCustom CTA button\nMonthly performance report',2),
('premium_authority','Authority+','premium','authority',499.00,20,'Everything in Growth\nUnlimited pinned reviews\nRemove all competitor suggestions\nFeatured category placement (1 week/mo)\nDedicated account manager',3),
('widget_pro','Widget Pro','widget','pro',79.00,15,'Unlimited widget views\n3 active widgets\nCarousel & Grid layouts\nHide low ratings filter\nCustom CSS',4),
('widget_agency','Widget Agency','widget','agency',149.00,20,'Unlimited widgets & domains\nWhite-label (no Optibiz badge)\nVerified-only filter\nPriority support\nAnalytics dashboard',5),
('sponsored_boost','Sponsored Boost','sponsored','sponsored',299.00,0,'Top 3 in category for 30 days\n\"Sponsored\" badge\nCTR analytics\nUp to 2 slots per category',6),
('sponsored_featured','Featured Crown','sponsored','featured',599.00,0,'#1 fixed position in category\nGold \"Featured\" crown badge\nHomepage spotlight (rotating)\nMax 1 slot per category\nVerified priority',7),
('sms_100','SMS Starter 100','sms','100',50.00,0,'100 SMS credits\nGHS 0.50 per SMS\nAll local providers\nDelivery reports',8),
('sms_500','SMS Growth 500','sms','500',200.00,0,'500 SMS credits\nGHS 0.40 per SMS — save 20%\nBulk CSV upload\nScheduled sends',9),
('sms_2000','SMS Scale 2000','sms','2000',600.00,0,'2,000 SMS credits\nGHS 0.30 per SMS — save 40%\nAPI automation\nDedicated sender ID*',10);
ALTER TABLE payment_invoices MODIFY purpose ENUM('new','renewal','upgrade','downgrade','addon','premium_claim','premium_renewal','widget','sponsored','sms_credits') NOT NULL DEFAULT 'renewal';
-- TWO-FACTOR AUTHENTICATION (2FA)
-- ============================================================
CREATE TABLE IF NOT EXISTS user_two_factor (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type VARCHAR(32) NOT NULL,
    user_id INT NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    method VARCHAR(16) NOT NULL DEFAULT 'totp',
    secret VARCHAR(64) NULL,
    backup_codes TEXT NULL,
    email_otp_hash VARCHAR(255) NULL,
    email_otp_expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_type_id (user_type, user_id),
    INDEX idx_user_lookup (user_type, user_id, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

