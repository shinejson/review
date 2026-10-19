<?php
/**
 * ============================================================
 *  Optibiz — Monetization Engine (4 Revenue Streams)
 * ============================================================
 *  Covers the 4 monetization models:
 *
 *   1) B2B Premium Profiles  — pay to claim, remove ads, pin reviews, custom banner
 *   2) Review Generation Widgets — embeddable subscription snippets
 *   3) Lead Generation & Advertising — Sponsored/Featured top-of-category
 *   4) Automated Review Collection — SMS via local providers (Hubtel, mNotify, etc.)
 *
 *  Each stream has: definition, entitlement check, checkout helper,
 *  and super-admin management utilities. Self-healing schema included.
 */

if (!function_exists('monetization_ensure_schema')) {
    function monetization_ensure_schema($conn)
    {
        static $done = false;
        if ($done || !is_object($conn) || !method_exists($conn, 'query')) return;
        $done = true;

        // --- extend customers with premium columns ---
        if (function_exists('pay_columns')) {
            if (pay_columns($conn, 'customers')) {
                pay_add_columns($conn, 'customers', [
                    'is_claimed'            => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'claimed_tenant_id'     => 'INT NULL',
                    'claimed_at'            => 'DATETIME NULL',
                    'premium_tier'          => "ENUM('none','essential','growth','authority') NOT NULL DEFAULT 'none'",
                    'premium_status'        => "ENUM('inactive','active','expired','cancelled') NOT NULL DEFAULT 'inactive'",
                    'premium_expires_at'    => 'DATETIME NULL',
                    'premium_price'         => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'custom_banner_url'     => 'VARCHAR(500) NULL',
                    'custom_cta_label'      => 'VARCHAR(80) NULL',
                    'custom_cta_url'        => 'VARCHAR(500) NULL',
                    'remove_competitor_ads' => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'verified_badge'        => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'pinned_review_id'      => 'INT NULL',
                    'premium_badge_label'   => 'VARCHAR(40) NULL',
                ]);
            }
        } else {
            @$conn->query("ALTER TABLE customers ADD COLUMN IF NOT EXISTS is_claimed TINYINT(1) NOT NULL DEFAULT 0");
            @$conn->query("ALTER TABLE customers ADD COLUMN IF NOT EXISTS premium_tier ENUM('none','essential','growth','authority') NOT NULL DEFAULT 'none'");
            @$conn->query("ALTER TABLE customers ADD COLUMN IF NOT EXISTS premium_status ENUM('inactive','active','expired','cancelled') NOT NULL DEFAULT 'inactive'");
        }

        // Premium claims workflow
        @$conn->query("CREATE TABLE IF NOT EXISTS premium_claims (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            claimant_tenant_id INT NOT NULL,
            claimant_name VARCHAR(190) NULL,
            claimant_email VARCHAR(160) NULL,
            claimant_phone VARCHAR(40) NULL,
            claim_method ENUM('email_domain','phone_match','document','manual') NOT NULL DEFAULT 'email_domain',
            claim_status ENUM('pending','verified','approved','rejected') NOT NULL DEFAULT 'pending',
            verification_token VARCHAR(64) NULL,
            verification_code VARCHAR(12) NULL,
            proof_document VARCHAR(500) NULL,
            reviewer_note TEXT NULL,
            reviewed_by VARCHAR(120) NULL,
            verified_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_claim_company (company_id),
            INDEX idx_claim_tenant (claimant_tenant_id),
            INDEX idx_claim_status (claim_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Premium subscriptions ledger
        @$conn->query("CREATE TABLE IF NOT EXISTS premium_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            tenant_id INT NOT NULL,
            tier ENUM('essential','growth','authority') NOT NULL DEFAULT 'essential',
            status ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(8) NOT NULL DEFAULT 'GHS',
            billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
            invoice_id INT NULL,
            payment_id INT NULL,
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_prem_company (company_id),
            INDEX idx_prem_tenant (tenant_id),
            INDEX idx_prem_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Sponsored listings
        @$conn->query("CREATE TABLE IF NOT EXISTS sponsored_listings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            company_id INT NOT NULL,
            category_id INT NULL,
            tier ENUM('sponsored','featured') NOT NULL DEFAULT 'sponsored',
            badge_label VARCHAR(40) NOT NULL DEFAULT 'Sponsored',
            title VARCHAR(190) NULL,
            cta_label VARCHAR(60) NULL,
            cta_url VARCHAR(500) NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(8) NOT NULL DEFAULT 'GHS',
            duration_days INT NOT NULL DEFAULT 30,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            status ENUM('pending','active','expired','rejected','cancelled') NOT NULL DEFAULT 'pending',
            invoice_id INT NULL,
            payment_id INT NULL,
            impressions INT NOT NULL DEFAULT 0,
            clicks INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_spons_company (company_id),
            INDEX idx_spons_category (category_id),
            INDEX idx_spons_status (status),
            INDEX idx_spons_ends (ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Widget configs
        @$conn->query("CREATE TABLE IF NOT EXISTS widget_configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            company_id INT NOT NULL,
            widget_key VARCHAR(32) NOT NULL,
            tier ENUM('free','pro','agency') NOT NULL DEFAULT 'free',
            status ENUM('active','suspended','expired') NOT NULL DEFAULT 'active',
            theme ENUM('light','dark','brand') NOT NULL DEFAULT 'light',
            layout ENUM('card','badge','carousel','grid') NOT NULL DEFAULT 'card',
            limit_count INT NOT NULL DEFAULT 5,
            hide_low_rating TINYINT(1) NOT NULL DEFAULT 1,
            show_verified_only TINYINT(1) NOT NULL DEFAULT 0,
            custom_css TEXT NULL,
            allowed_domains TEXT NULL,
            api_key VARCHAR(64) NULL,
            views_total INT NOT NULL DEFAULT 0,
            clicks_total INT NOT NULL DEFAULT 0,
            invoice_id INT NULL,
            payment_id INT NULL,
            expires_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_widget_key (widget_key),
            INDEX idx_widget_tenant (tenant_id),
            INDEX idx_widget_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // migrate older installs that missed invoice_id/payment_id
        @$conn->query("ALTER TABLE widget_configs ADD COLUMN IF NOT EXISTS invoice_id INT NULL AFTER clicks_total");
        @$conn->query("ALTER TABLE widget_configs ADD COLUMN IF NOT EXISTS payment_id INT NULL AFTER invoice_id");

        // SMS providers
        @$conn->query("CREATE TABLE IF NOT EXISTS sms_providers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider_key VARCHAR(40) NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            api_url VARCHAR(500) NULL,
            api_key VARCHAR(255) NULL,
            api_secret VARCHAR(255) NULL,
            sender_id VARCHAR(32) NULL,
            connection_status VARCHAR(20) NOT NULL DEFAULT 'unverified',
            connection_note VARCHAR(255) NULL,
            last_tested_at DATETIME NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_provider_key (provider_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed SMS providers
        $seed = (int)@sa_scalar($conn, "SELECT COUNT(*) FROM sms_providers", 0, 'sms_providers');
        if ($seed === 0) {
            $providers = monetization_sms_catalog();
            foreach ($providers as $key => $meta) {
                $name = $conn->real_escape_string($meta['label']);
                $note = $conn->real_escape_string($meta['summary']);
                $url = $conn->real_escape_string($meta['api_url']);
                $order = (int)$meta['order'];
                @$conn->query("INSERT INTO sms_providers (provider_key, display_name, is_enabled, api_url, sender_id, connection_status, connection_note, sort_order) VALUES ('".$conn->real_escape_string($key)."','".$name."',0,'".$url."','Optibiz','unverified','".$note."',".$order.")");
            }
        }

        // SMS credits wallet
        @$conn->query("CREATE TABLE IF NOT EXISTS sms_credits_wallet (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            balance INT NOT NULL DEFAULT 0,
            total_purchased INT NOT NULL DEFAULT 0,
            total_used INT NOT NULL DEFAULT 0,
            last_topup_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_wallet_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // SMS campaigns
        @$conn->query("CREATE TABLE IF NOT EXISTS sms_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL,
            company_id INT NOT NULL,
            provider_key VARCHAR(40) NOT NULL DEFAULT '',
            campaign_name VARCHAR(190) NOT NULL,
            message_body TEXT NOT NULL,
            template_key VARCHAR(40) NULL,
            recipient_count INT NOT NULL DEFAULT 0,
            recipients_json MEDIUMTEXT NULL,
            audience_filter VARCHAR(40) NULL,
            status ENUM('draft','queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
            cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            credits_used INT NOT NULL DEFAULT 0,
            sms_price_per DECIMAL(10,4) NOT NULL DEFAULT 0.00,
            invoice_id INT NULL,
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sms_tenant (tenant_id),
            INDEX idx_sms_company (company_id),
            INDEX idx_sms_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // SMS delivery log
        @$conn->query("CREATE TABLE IF NOT EXISTS sms_delivery_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NULL,
            tenant_id INT NOT NULL,
            company_id INT NOT NULL,
            provider_key VARCHAR(40) NULL,
            recipient_phone VARCHAR(40) NOT NULL,
            recipient_name VARCHAR(190) NULL,
            message_body TEXT NULL,
            provider_message_id VARCHAR(120) NULL,
            status ENUM('queued','sent','delivered','failed','undelivered') NOT NULL DEFAULT 'queued',
            failure_reason VARCHAR(255) NULL,
            cost DECIMAL(10,4) NOT NULL DEFAULT 0.00,
            sent_at DATETIME NULL,
            delivered_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_smslog_campaign (campaign_id),
            INDEX idx_smslog_tenant (tenant_id),
            INDEX idx_smslog_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Monetization settings
        @$conn->query("CREATE TABLE IF NOT EXISTS monetization_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(80) NOT NULL,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_monet_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Premium addon plans (if subscription_plans not flexible)
        @$conn->query("CREATE TABLE IF NOT EXISTS monetization_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_code VARCHAR(40) NOT NULL,
            plan_name VARCHAR(100) NOT NULL,
            stream ENUM('premium','widget','sponsored','sms') NOT NULL,
            tier VARCHAR(40) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            annual_discount_percent INT NOT NULL DEFAULT 0,
            features TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_plan_code (plan_code),
            INDEX idx_mplan_stream (stream)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed monetization plans
        $planSeed = (int)@sa_scalar($conn, "SELECT COUNT(*) FROM monetization_plans", 0, 'monetization_plans');
        if ($planSeed === 0) {
            $defaults = monetization_default_plans();
            foreach ($defaults as $pl) {
                $code = $conn->real_escape_string($pl['code']);
                $name = $conn->real_escape_string($pl['name']);
                $stream = $conn->real_escape_string($pl['stream']);
                $tier = $conn->real_escape_string($pl['tier']);
                $price = number_format((float)$pl['price'], 2, '.', '');
                $disc = (int)$pl['annual_discount'];
                $feat = $conn->real_escape_string($pl['features']);
                $order = (int)$pl['order'];
                @$conn->query("INSERT INTO monetization_plans (plan_code, plan_name, stream, tier, price, annual_discount_percent, features, sort_order) VALUES ('$code','$name','$stream','$tier',$price,$disc,'$feat',$order)");
            }
        }

        // Extend payment invoices purpose enum
        $cols = pay_columns($conn, 'payment_invoices');
        if ($cols) {
            $purpose_def = @sa_scalar($conn, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_invoices' AND COLUMN_NAME='purpose'", '', 'payment_invoices');
            if ($purpose_def && strpos($purpose_def, 'premium') === false) {
                @$conn->query("ALTER TABLE payment_invoices MODIFY purpose ENUM('new','renewal','upgrade','downgrade','addon','premium_claim','premium_renewal','widget','sponsored','sms_credits') NOT NULL DEFAULT 'renewal'");
            }
        }
    }
}

if (!function_exists('monetization_default_plans')) {
    function monetization_default_plans()
    {
        return [
            // Premium Profiles - B2B claimable
            ['code'=>'premium_essential','name'=>'Essential Claim','stream'=>'premium','tier'=>'essential','price'=>149,'annual_discount'=>15,'features'=>"Claim your business profile\nRemove competitor ads\nVerified badge\nCustom banner upload",'order'=>1],
            ['code'=>'premium_growth','name'=>'Growth Premium','stream'=>'premium','tier'=>'growth','price'=>299,'annual_discount'=>20,'features'=>"Everything in Essential\nPin 3 positive reviews to top\nPriority in category ranking\nCustom CTA button\nMonthly performance report",'order'=>2],
            ['code'=>'premium_authority','name'=>'Authority+','stream'=>'premium','tier'=>'authority','price'=>499,'annual_discount'=>20,'features'=>"Everything in Growth\nUnlimited pinned reviews\nRemove all competitor suggestions\nFeatured category placement (1 week/mo)\nDedicated account manager",'order'=>3],
            // Widgets
            ['code'=>'widget_pro','name'=>'Widget Pro','stream'=>'widget','tier'=>'pro','price'=>79,'annual_discount'=>15,'features'=>"Unlimited widget views\n3 active widgets\nCarousel & Grid layouts\nHide low ratings filter\nCustom CSS",'order'=>4],
            ['code'=>'widget_agency','name'=>'Widget Agency','stream'=>'widget','tier'=>'agency','price'=>149,'annual_discount'=>20,'features'=>"Unlimited widgets & domains\nWhite-label (no Optibiz badge)\nVerified-only filter\nPriority support\nAnalytics dashboard",'order'=>5],
            // Sponsored
            ['code'=>'sponsored_boost','name'=>'Sponsored Boost','stream'=>'sponsored','tier'=>'sponsored','price'=>299,'annual_discount'=>0,'features'=>"Top 3 in category for 30 days\n\"Sponsored\" badge\nCTR analytics\nUp to 2 slots per category",'order'=>6],
            ['code'=>'sponsored_featured','name'=>'Featured Crown','stream'=>'sponsored','tier'=>'featured','price'=>599,'annual_discount'=>0,'features'=>"#1 fixed position in category\nGold \"Featured\" crown badge\nHomepage spotlight (rotating)\nMax 1 slot per category\nVerified priority",'order'=>7],
            // SMS
            ['code'=>'sms_100','name'=>'SMS Starter 100','stream'=>'sms','tier'=>'100','price'=>50,'annual_discount'=>0,'features'=>"100 SMS credits\nGHS 0.50 per SMS\nAll local providers\nDelivery reports",'order'=>8],
            ['code'=>'sms_500','name'=>'SMS Growth 500','stream'=>'sms','tier'=>'500','price'=>200,'annual_discount'=>0,'features'=>"500 SMS credits\nGHS 0.40 per SMS — save 20%\nBulk CSV upload\nScheduled sends",'order'=>9],
            ['code'=>'sms_2000','name'=>'SMS Scale 2000','stream'=>'sms','tier'=>'2000','price'=>600,'annual_discount'=>0,'features'=>"2,000 SMS credits\nGHS 0.30 per SMS — save 40%\nAPI automation\nDedicated sender ID*", 'order'=>10],
        ];
    }
}

if (!function_exists('monetization_sms_catalog')) {
    function monetization_sms_catalog()
    {
        return [
            'hubtel' => ['label'=>'Hubtel SMS','summary'=>'Ghana leading gateway — high deliverability for MTN, Vodafone, AirtelTigo.','region'=>'Ghana','api_url'=>'https://sms.hubtel.com/v1/messages/send','order'=>1],
            'mnotify' => ['label'=>'mNotify','summary'=>'Reliable Ghana bulk SMS with Sender ID registration.','region'=>'Ghana','api_url'=>'https://api.mnotify.com/api/sms/quick','order'=>2],
            'africastalking' => ['label'=>"Africa's Talking",'summary'=>'Pan-African SMS & Voice, strong in Kenya, Nigeria, Ghana.','region'=>'Africa','api_url'=>'https://api.africastalking.com/version1/messaging','order'=>3],
            'nalo' => ['label'=>'Nalo Solutions','summary'=>'Ghana mobile money & SMS aggregator.','region'=>'Ghana','api_url'=>'https://sms.nalosolutions.com/sms/backend/sendsms/','order'=>4],
            'twilio' => ['label'=>'Twilio','summary'=>'Global SMS fallback for international numbers.','region'=>'Global','api_url'=>'https://api.twilio.com/2010-04-01/Accounts','order'=>5],
            'manual' => ['label'=>'Manual / Offline','summary'=>'Log SMS sent outside platform & track review conversion.','region'=>'Anywhere','api_url'=>'','order'=>9],
        ];
    }
}

if (!function_exists('monetization_premium_tiers')) {
    function monetization_premium_tiers()
    {
        return [
            'essential' => ['label'=>'Essential','price'=>149,'color'=>'#0ea5e9','badge'=>'Verified','benefits'=>['Claim profile','Remove competitor ads','Verified badge','Custom banner']],
            'growth'    => ['label'=>'Growth','price'=>299,'color'=>'#8b5cf6','badge'=>'Premium','benefits'=>['Pin 3 reviews','Priority ranking','Custom CTA','Monthly report']],
            'authority' => ['label'=>'Authority+','price'=>499,'color'=>'#f59e0b','badge'=>'Authority','benefits'=>['Unlimited pins','No competitor ads anywhere','Featured slot 1wk/mo','Dedicated manager']],
        ];
    }
}

if (!function_exists('monetization_widget_tiers')) {
    function monetization_widget_tiers()
    {
        return [
            'free'   => ['label'=>'Free','price'=>0,'limit'=>1,'views'=>'1,000/mo'],
            'pro'    => ['label'=>'Pro','price'=>79,'limit'=>3,'views'=>'Unlimited'],
            'agency' => ['label'=>'Agency','price'=>149,'limit'=>99,'views'=>'Unlimited + White-label'],
        ];
    }
}

if (!function_exists('is_premium_company')) {
    function is_premium_company($conn, $company_id)
    {
        if (!$conn || !$company_id) return false;
        monetization_ensure_schema($conn);
        $row = sa_one($conn, "SELECT premium_status, premium_expires_at, premium_tier FROM customers WHERE id=".(int)$company_id." LIMIT 1", 'customers');
        if (!$row) return false;
        if (($row['premium_status'] ?? '') !== 'active') return false;
        if (!empty($row['premium_expires_at']) && strtotime($row['premium_expires_at']) < time()) {
            // auto-expire
            @$conn->query("UPDATE customers SET premium_status='expired' WHERE id=".(int)$company_id);
            return false;
        }
        return $row['premium_tier'] ?? false;
    }
}

if (!function_exists('get_premium_profile')) {
    function get_premium_profile($conn, $company_id)
    {
        monetization_ensure_schema($conn);
        return sa_one($conn, "SELECT * FROM customers WHERE id=".(int)$company_id." LIMIT 1", 'customers');
    }
}

if (!function_exists('get_sponsored_for_category')) {
    function get_sponsored_for_category($conn, $category_id, $limit=3)
    {
        monetization_ensure_schema($conn);
        if (!$conn) return [];
        $cid = (int)$category_id;
        $sql = "SELECT s.*, c.company_name, c.category_id as comp_cat FROM sponsored_listings s JOIN customers c ON c.id=s.company_id WHERE s.status='active' AND s.ends_at > NOW()";
        if ($cid) $sql .= " AND (s.category_id=".$cid." OR s.category_id IS NULL)";
        $sql .= " ORDER BY FIELD(s.tier,'featured','sponsored'), s.starts_at DESC LIMIT ".max(1,(int)$limit);
        return sa_query($conn, $sql, 'sponsored_listings');
    }
}

if (!function_exists('get_active_sponsored_company_ids')) {
    function get_active_sponsored_company_ids($conn)
    {
        $rows = sa_query($conn, "SELECT company_id FROM sponsored_listings WHERE status='active' AND ends_at > NOW()", 'sponsored_listings');
        $out=[]; foreach($rows as $r) $out[(int)$r['company_id']]=true;
        return $out;
    }
}

if (!function_exists('monetization_increment_impression')) {
    function monetization_increment_impression($conn, $sponsored_id)
    {
        if (!$conn || !$sponsored_id) return;
        @$conn->query("UPDATE sponsored_listings SET impressions=impressions+1 WHERE id=".(int)$sponsored_id);
    }
}

if (!function_exists('get_widget_config')) {
    function get_widget_config($conn, $tenant_id, $company_id=0)
    {
        monetization_ensure_schema($conn);
        $tid=(int)$tenant_id; $cid=(int)$company_id;
        $sql = "SELECT * FROM widget_configs WHERE tenant_id=".$tid;
        if ($cid) $sql .= " AND company_id=".$cid;
        $sql .= " ORDER BY id DESC LIMIT 1";
        return sa_one($conn, $sql, 'widget_configs');
    }
}

if (!function_exists('has_widget_access')) {
    function has_widget_access($conn, $tenant_id)
    {
        monetization_ensure_schema($conn);
        $tid=(int)$tenant_id;
        // check active widget subscription or tenant plan feature
        $row = sa_one($conn, "SELECT * FROM widget_configs WHERE tenant_id=".$tid." AND status='active' AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1", 'widget_configs');
        if ($row) return true;
        // also allow if plan has widget feature — check subscription_plans.features
        if (function_exists('tenantHasFeature')) {
            // Check if tenant has widget feature via plan
            $has = tenantHasFeature($conn, $tid, 'widget_pro');
            if ($has) return true;
            // Enterprise always gets it
            $has2 = tenantHasFeature($conn, $tid, 'ads_pixel');
            if ($has2) return true;
        }
        // free tier: allow 1 widget with limits
        return true; // freemium — allow but with watermark/limits
    }
}

if (!function_exists('get_sms_wallet')) {
    function get_sms_wallet($conn, $tenant_id)
    {
        monetization_ensure_schema($conn);
        $tid=(int)$tenant_id;
        $w = sa_one($conn, "SELECT * FROM sms_credits_wallet WHERE tenant_id=".$tid." LIMIT 1", 'sms_credits_wallet');
        if (!$w) {
            @$conn->query("INSERT IGNORE INTO sms_credits_wallet (tenant_id,balance) VALUES (".$tid.",0)");
            $w = sa_one($conn, "SELECT * FROM sms_credits_wallet WHERE tenant_id=".$tid." LIMIT 1", 'sms_credits_wallet');
        }
        return $w ?: ['balance'=>0,'total_purchased'=>0,'total_used'=>0];
    }
}

if (!function_exists('monetization_stats')) {
    function monetization_stats($conn)
    {
        monetization_ensure_schema($conn);
        return [
            'premium_active' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM customers WHERE premium_status='active'",0,'customers'),
            'premium_claims_pending' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM premium_claims WHERE claim_status='pending'",0,'premium_claims'),
            'premium_mrr' => (float)sa_scalar($conn, "SELECT COALESCE(SUM(premium_price),0) FROM customers WHERE premium_status='active'",0,'customers'),
            'sponsored_active' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM sponsored_listings WHERE status='active' AND ends_at > NOW()",0,'sponsored_listings'),
            'sponsored_pending' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM sponsored_listings WHERE status='pending'",0,'sponsored_listings'),
            'sponsored_revenue' => (float)sa_scalar($conn, "SELECT COALESCE(SUM(price),0) FROM sponsored_listings WHERE status='active'",0,'sponsored_listings'),
            'widgets_active' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM widget_configs WHERE status='active'",0,'widget_configs'),
            'widgets_views' => (int)sa_scalar($conn, "SELECT COALESCE(SUM(views_total),0) FROM widget_configs",0,'widget_configs'),
            'sms_credits_sold' => (int)sa_scalar($conn, "SELECT COALESCE(SUM(total_purchased),0) FROM sms_credits_wallet",0,'sms_credits_wallet'),
            'sms_credits_balance' => (int)sa_scalar($conn, "SELECT COALESCE(SUM(balance),0) FROM sms_credits_wallet",0,'sms_credits_wallet'),
            'sms_campaigns' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM sms_campaigns",0,'sms_campaigns'),
            'sms_sent' => (int)sa_scalar($conn, "SELECT COALESCE(SUM(credits_used),0) FROM sms_campaigns WHERE status='sent'",0,'sms_campaigns'),
        ];
    }
}

if (!function_exists('monetization_on_invoice_paid')) {
    /**
     * Activation hook — called when a payment's invoice moves to paid/confirmed.
     * Finds any pending monetization rows tied to this invoice_id and activates them.
     * Purpose-agnostic: works for premium, sponsored, widget, sms_credits regardless
     * of how the invoice was labeled. Safe to call multiple times (idempotent).
     */
    function monetization_on_invoice_paid($conn, $invoice_id, $payment_id=0)
    {
        if (!$conn || !$invoice_id) return;
        monetization_ensure_schema($conn);
        $iid = (int)$invoice_id;
        // Load invoice to read notes (contains stream metadata) and tenant
        $inv = sa_one($conn, "SELECT * FROM payment_invoices WHERE id=".$iid." LIMIT 1", 'payment_invoices');
        if (!$inv) return;
        $tenant_id = (int)($inv['tenant_id'] ?? 0);
        $notes = $inv['notes'] ?? '';
        $notes_data = @json_decode($notes, true); if (!is_array($notes_data)) $notes_data = [];

        // 1) Premium subscriptions pending for this invoice
        $rows = sa_query($conn, "SELECT * FROM premium_subscriptions WHERE invoice_id=".$iid." AND status='pending'", 'premium_subscriptions');
        foreach ($rows as $r) {
            $cid = (int)$r['company_id']; $tier = $r['tier'] ?? 'essential';
            @$conn->query("UPDATE premium_subscriptions SET status='active', starts_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=".(int)$r['id']);
            // push to customers table
            $tier_esc = $conn->real_escape_string($tier);
            $price = (float)($r['price'] ?? 0);
            @$conn->query("UPDATE customers SET premium_tier='".$tier_esc."', premium_status='active', premium_expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY), premium_price=".$price.", is_claimed=1, claimed_tenant_id=".(int)$r['tenant_id'].", claimed_at=NOW(), verified_badge=1 WHERE id=".$cid);
        }

        // 2) Sponsored listings pending for this invoice
        $spRows = sa_query($conn, "SELECT * FROM sponsored_listings WHERE invoice_id=".$iid." AND status='pending'", 'sponsored_listings');
        foreach ($spRows as $s) {
            $dur = max(7, (int)($s['duration_days'] ?? 30));
            @$conn->query("UPDATE sponsored_listings SET status='active', starts_at=NOW(), ends_at=DATE_ADD(NOW(), INTERVAL ".$dur." DAY) WHERE id=".(int)$s['id']);
        }
        // Fallback: sponsored invoice without linkage (older sponsored flow)
        if (empty($spRows) && $purpose==='sponsored' && $tenant_id) {
            $tierFromNotes = strtolower(trim($notes_data['tier'] ?? ''));
            $pendingSpon = sa_query($conn, "SELECT * FROM sponsored_listings WHERE tenant_id=".$tenant_id." AND status='pending' ORDER BY id DESC LIMIT 3", 'sponsored_listings');
            foreach ($pendingSpon as $psp) {
                if ($tierFromNotes && strtolower($psp['tier'] ?? '') !== $tierFromNotes) continue;
                $dur = max(7, (int)($psp['duration_days'] ?? 30));
                @$conn->query("UPDATE sponsored_listings SET status='active', invoice_id=".$iid.", starts_at=NOW(), ends_at=DATE_ADD(NOW(), INTERVAL ".$dur." DAY) WHERE id=".(int)$psp['id']);
                break;
            }
        }

        // 3) Widget configs pending for this invoice (Pro/Agency upgrade)
        $wRows = sa_query($conn, "SELECT * FROM widget_configs WHERE invoice_id=".$iid." AND status IN ('pending','suspended')", 'widget_configs');
        foreach ($wRows as $w) {
            @$conn->query("UPDATE widget_configs SET status='active', expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=".(int)$w['id']);
        }
        // Fallback: widget invoice created row as pending without direct invoice_id link (older widget flow)
        if (empty($wRows) && $purpose==='widget' && $tenant_id) {
            $tierFromNotes = strtolower(trim($notes_data['tier'] ?? 'pro'));
            $pendingWidgets = sa_query($conn, "SELECT * FROM widget_configs WHERE tenant_id=".$tenant_id." AND status IN ('pending','suspended') ORDER BY id DESC LIMIT 3", 'widget_configs');
            foreach ($pendingWidgets as $pw) {
                // match tier if notes specifies it, else take first pending
                if ($tierFromNotes && strtolower($pw['tier'] ?? '') !== $tierFromNotes) continue;
                @$conn->query("UPDATE widget_configs SET status='active', invoice_id=".$iid.", expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=".(int)$pw['id']);
                break;
            }
            // if still none matched, activate the newest pending regardless of tier
            if (!empty($pendingWidgets) && count($pendingWidgets)===1 && empty($wRows)) {
                // already handled first iteration; safe
            }
        }

        // 4) SMS credits — purchase tied to invoice (GHS credits packages)
        $purpose = strtolower($inv['purpose'] ?? '');
        if (strpos($purpose,'sms')!==false || !empty($notes_data['stream']) && $notes_data['stream']==='sms' || !empty($notes_data['credits'])) {
            $credits = 0;
            if (!empty($notes_data['credits'])) $credits = (int)$notes_data['credits'];
            elseif (!empty($notes_data['sms_credits'])) $credits = (int)$notes_data['sms_credits'];
            elseif (!empty($inv['months'])) {
                // fallback: derive from amount if credits not explicit — use price_per heuristics
            }
            // fallback mapping from amount: GHS 50=>100, 200=>500, 600=>2000
            if ($credits <= 0) {
                $amt = (float)($inv['total'] ?? $inv['amount'] ?? 0);
                if ($amt >= 550) $credits = 2000;
                elseif ($amt >= 150) $credits = 500;
                elseif ($amt >= 30) $credits = 100;
                else $credits = (int)round($amt / 0.5); // 0.50 per SMS fallback
            }
            if ($credits > 0 && $tenant_id) {
                // ensure wallet exists
                $w = sa_one($conn, "SELECT * FROM sms_credits_wallet WHERE tenant_id=".$tenant_id." LIMIT 1", 'sms_credits_wallet');
                if (!$w) @$conn->query("INSERT IGNORE INTO sms_credits_wallet (tenant_id,balance,total_purchased,total_used) VALUES (".$tenant_id.",0,0,0)");
                @$conn->query("UPDATE sms_credits_wallet SET balance = balance + ".(int)$credits.", total_purchased = total_purchased + ".(int)$credits.", last_topup_at=NOW() WHERE tenant_id=".$tenant_id);
            }
        }

        // 5) Generic sponsored queue: if invoice was for sponsored but listing row already created via admin flow, the above already activated.

        // 6) Mark invoice paid if not already (pay_invoice_apply may have done it)
        if (($inv['status'] ?? '') !== 'paid') {
            @$conn->query("UPDATE payment_invoices SET status='paid', paid_at=NOW(), updated_at=NOW() WHERE id=".$iid);
        }
        // Log the activation for audit
        if (function_exists('pay_log_event')) {
            @pay_log_event($conn, ['gateway_key'=>($inv['gateway_key'] ?? 'manual'),'event_type'=>'monetization.activated','reference'=>($inv['invoice_number'] ?? ''),'invoice_id'=>$iid,'payment_id'=>(int)$payment_id,'payload'=>'Monetization fulfilled for invoice '.$iid.' (purpose: '.($inv['purpose'] ?? '').')']);
        }
    }
}

if (!function_exists('monetization_settings_get')) {
    function monetization_settings_get($conn, $key, $default='')
    {
        monetization_ensure_schema($conn);
        $k = $conn->real_escape_string($key);
        $row = sa_one($conn, "SELECT setting_value FROM monetization_settings WHERE setting_key='$k' LIMIT 1", 'monetization_settings');
        return $row ? $row['setting_value'] : $default;
    }
    function monetization_settings_set($conn, $key, $value)
    {
        monetization_ensure_schema($conn);
        $k = $conn->real_escape_string($key);
        $v = $conn->real_escape_string((string)$value);
        @$conn->query("INSERT INTO monetization_settings (setting_key, setting_value) VALUES ('$k','$v') ON DUPLICATE KEY UPDATE setting_value='$v'");
        return true;
    }
}
