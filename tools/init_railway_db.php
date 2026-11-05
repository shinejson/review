<?php
/**
 * Auto-initializes and migrates the database schema.
 * Safe to run on every container start; all operations are idempotent.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/password.php';

echo "[DB Init] Connecting to " . DB_HOST . ":" . DB_PORT . " / " . DB_NAME . "...\n";

if (!isset($conn) || !is_object($conn)) {
    echo "[DB Init] Warning: Database connection object not available.\n";
    exit(0);
}

// Helper to ensure a column exists on a table
function ensure_col($conn, $table, $column, $definition) {
    try {
        $check = @$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if ($check && $check->num_rows === 0) {
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}";
            if (@$conn->query($sql)) {
                echo "[DB Init] Added column `{$column}` to `{$table}`\n";
            } else {
                echo "[DB Init] Notice: Could not add column `{$column}` to `{$table}`: " . $conn->error . "\n";
            }
        }
    } catch (Throwable $e) {
        // Ignored or table does not exist yet
    }
}

// Helper to check if a table already has rows
function table_has_rows($conn, $table) {
    try {
        $res = @$conn->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return ($res && $res->num_rows > 0);
    } catch (Throwable $e) {
        return false;
    }
}

// 1. Execute all DDL and initial seeds from database.sql
$sqlPath = dirname(__DIR__) . '/database.sql';
if (file_exists($sqlPath)) {
    echo "[DB Init] Processing database.sql schema...\n";
    $rawSql = file_get_contents($sqlPath);
    if ($rawSql !== false) {
        // Strip comments
        $lines = explode("\n", $rawSql);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, '--') === 0) continue;
            if (strpos($trimmed, '/*') === 0) continue;
            $cleanLines[] = $line;
        }
        $filteredSql = implode("\n", $cleanLines);

        // Split into statements
        $statements = explode(';', $filteredSql);
        $executed = 0;
        $errors = 0;

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;

            // Skip CREATE DATABASE and USE statements
            if (preg_match('/^(?:CREATE\s+DATABASE|USE\s+)/i', $stmt)) {
                continue;
            }

            // Prevent duplicating rows on subsequent restarts
            if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z_]+)`?/i', $stmt, $mInsert)) {
                $seedTable = strtolower($mInsert[1]);
                if (in_array($seedTable, ['subscription_plans', 'categories', 'customers', 'ratings', 'rating_questions', 'super_admins', 'tenants', 'admins'], true)) {
                    if (table_has_rows($conn, $seedTable)) {
                        // Already seeded; do not repeat insertion
                        continue;
                    }
                }
            }

            try {
                if (@$conn->query($stmt)) {
                    $executed++;
                } else {
                    $errors++;
                }
            } catch (Throwable $e) {
                $errors++;
            }
        }
        echo "[DB Init] database.sql processed: {$executed} successful, {$errors} skipped/duplicates.\n";
    }
}

// 2. Schema Reconciliation & Column Guarantees (idempotent)
echo "[DB Init] Reconciling table columns and migrations...\n";

// Tenants table columns
ensure_col($conn, 'tenants', 'logo', 'VARCHAR(500) NULL DEFAULT NULL');
ensure_col($conn, 'tenants', 'banner', 'VARCHAR(500) NULL DEFAULT NULL');
ensure_col($conn, 'tenants', 'favicon', 'VARCHAR(500) NULL DEFAULT NULL');
ensure_col($conn, 'tenants', 'failed_login_attempts', 'INT NOT NULL DEFAULT 0');
ensure_col($conn, 'tenants', 'last_failed_attempt_at', 'DATETIME NULL');
ensure_col($conn, 'tenants', 'account_locked_until', 'DATETIME NULL');

// Team members columns
ensure_col($conn, 'team_members', 'failed_login_attempts', 'INT NOT NULL DEFAULT 0');
ensure_col($conn, 'team_members', 'last_failed_attempt_at', 'DATETIME NULL');
ensure_col($conn, 'team_members', 'account_locked_until', 'DATETIME NULL');

// Ratings columns
ensure_col($conn, 'ratings', 'photos', "TEXT NULL COMMENT 'JSON array of photo paths'");
ensure_col($conn, 'ratings', 'helpful_count', 'INT DEFAULT 0');
ensure_col($conn, 'ratings', 'reported', 'TINYINT(1) DEFAULT 0');
ensure_col($conn, 'ratings', 'is_verified', 'TINYINT(1) NOT NULL DEFAULT 0');
ensure_col($conn, 'ratings', 'verification_type', 'VARCHAR(30) NULL');
ensure_col($conn, 'ratings', 'momo_ref', 'VARCHAR(100) NULL');
ensure_col($conn, 'ratings', 'receipt_photo', 'VARCHAR(255) NULL');
ensure_col($conn, 'ratings', 'question_id', 'INT NULL');
ensure_col($conn, 'ratings', 'service_id', 'INT NULL AFTER question_id');

// Customers columns
ensure_col($conn, 'customers', 'whatsapp_number', 'VARCHAR(30) NULL');
ensure_col($conn, 'customers', 'is_claimed', 'TINYINT(1) NOT NULL DEFAULT 0');
ensure_col($conn, 'customers', 'claimed_tenant_id', 'INT NULL');
ensure_col($conn, 'customers', 'claimed_at', 'DATETIME NULL');
ensure_col($conn, 'customers', 'premium_tier', "ENUM('none','essential','growth','authority') NOT NULL DEFAULT 'none'");
ensure_col($conn, 'customers', 'premium_status', "ENUM('inactive','active','expired','cancelled') NOT NULL DEFAULT 'inactive'");
ensure_col($conn, 'customers', 'premium_expires_at', 'DATETIME NULL');
ensure_col($conn, 'customers', 'premium_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
ensure_col($conn, 'customers', 'custom_banner_url', 'VARCHAR(500) NULL');
ensure_col($conn, 'customers', 'custom_cta_label', 'VARCHAR(80) NULL');
ensure_col($conn, 'customers', 'custom_cta_url', 'VARCHAR(500) NULL');
ensure_col($conn, 'customers', 'remove_competitor_ads', 'TINYINT(1) NOT NULL DEFAULT 0');
ensure_col($conn, 'customers', 'verified_badge', 'TINYINT(1) NOT NULL DEFAULT 0');
ensure_col($conn, 'customers', 'pinned_review_id', 'INT NULL');
ensure_col($conn, 'customers', 'premium_badge_label', 'VARCHAR(40) NULL');

// Ensure services table
try {
    @$conn->query("CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        description TEXT NULL,
        icon VARCHAR(80) DEFAULT 'fa-solid fa-star',
        sort_order INT NOT NULL DEFAULT 0,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_services_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Ensure admin helpers schema
$adminHelpers = dirname(__DIR__) . '/includes/admin_helpers.php';
if (file_exists($adminHelpers)) {
    require_once $adminHelpers;
    if (function_exists('admin_ensure_schema')) {
        admin_ensure_schema($conn);
    }
}

// Ensure review_trust helper tables
$reviewTrustFile = dirname(__DIR__) . '/includes/review_trust.php';
if (file_exists($reviewTrustFile)) {
    require_once $reviewTrustFile;
    if (function_exists('review_trust_ensure_schema')) {
        review_trust_ensure_schema($conn);
    }
}

// 3. Deduplication of Categories & Subscription Plans
echo "[DB Init] Cleaning up and deduplicating categories and plans...\n";
try {
    @$conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // Categories
    $catDups = @$conn->query("
        SELECT name, MIN(id) AS min_id, GROUP_CONCAT(id) AS all_ids, COUNT(*) AS cnt
        FROM categories
        GROUP BY name
        HAVING cnt > 1
    ");
    if ($catDups) {
        while ($row = $catDups->fetch_assoc()) {
            $minId = (int)$row['min_id'];
            $allIds = array_map('intval', explode(',', $row['all_ids']));
            $dupIds = array_filter($allIds, function($id) use ($minId) { return $id !== $minId; });
            if (!empty($dupIds)) {
                $idList = implode(',', $dupIds);
                @$conn->query("UPDATE customers SET category_id = {$minId} WHERE category_id IN ({$idList})");
                @$conn->query("UPDATE quote_requests SET category_id = {$minId} WHERE category_id IN ({$idList})");
                @$conn->query("DELETE FROM categories WHERE id IN ({$idList})");
            }
        }
    }
    @$conn->query("ALTER TABLE categories ADD UNIQUE KEY uniq_cat_name (name)");

    // Subscription Plans
    $planDups = @$conn->query("
        SELECT plan_name, MIN(id) AS min_id, GROUP_CONCAT(id) AS all_ids, COUNT(*) AS cnt
        FROM subscription_plans
        GROUP BY plan_name
        HAVING cnt > 1
    ");
    if ($planDups) {
        while ($row = $planDups->fetch_assoc()) {
            $minId = (int)$row['min_id'];
            $allIds = array_map('intval', explode(',', $row['all_ids']));
            $dupIds = array_filter($allIds, function($id) use ($minId) { return $id !== $minId; });
            if (!empty($dupIds)) {
                $idList = implode(',', $dupIds);
                @$conn->query("UPDATE tenants SET plan_id = {$minId} WHERE plan_id IN ({$idList})");
                @$conn->query("UPDATE subscription_requests SET requested_plan_id = {$minId} WHERE requested_plan_id IN ({$idList})");
                @$conn->query("UPDATE subscription_requests SET current_plan_id = {$minId} WHERE current_plan_id IN ({$idList})");
                @$conn->query("DELETE FROM subscription_plans WHERE id IN ({$idList})");
            }
        }
    }
    @$conn->query("ALTER TABLE subscription_plans ADD UNIQUE KEY uniq_plan_name (plan_name)");

    @$conn->query("SET FOREIGN_KEY_CHECKS = 1");
} catch (Throwable $e) {
    @$conn->query("SET FOREIGN_KEY_CHECKS = 1");
}

// 4. Ensure Super Admin and Tenant Credentials & Unlock Accounts
echo "[DB Init] Resetting default credentials and unlocking accounts...\n";
// Clear lockouts on all accounts
@$conn->query("UPDATE tenants SET failed_login_attempts = 0, account_locked_until = NULL");
@$conn->query("UPDATE team_members SET failed_login_attempts = 0, account_locked_until = NULL");

// Default passwords hashes (PBKDF2)
$superadminHash = optibiz_hash_password('superadmin123');
$adminHash      = optibiz_hash_password('admin123');

// Update superadmin password if present
if ($superadminHash) {
    $stmt = $conn->prepare("UPDATE super_admins SET password = ? WHERE username = 'superadmin'");
    if ($stmt) {
        $stmt->bind_param("s", $superadminHash);
        $stmt->execute();
        $stmt->close();
    }
}

// Update seed tenant passwords
if ($adminHash) {
    $stmt = $conn->prepare("UPDATE tenants SET password = ? WHERE username IN ('abc_corporation', 'xyz_industries')");
    if ($stmt) {
        $stmt->bind_param("s", $adminHash);
        $stmt->execute();
        $stmt->close();
    }
    $stmtA = $conn->prepare("UPDATE admins SET password = ? WHERE username = 'admin'");
    if ($stmtA) {
        $stmtA->bind_param("s", $adminHash);
        $stmtA->execute();
        $stmtA->close();
    }
}

// Ensure 'Airport West Hotel' tenant from user workspace is available
$checkHotel = @$conn->query("SELECT id FROM tenants WHERE username = 'airport_west_hotel' OR public_id = 'OPT-PJY9XXXQ' LIMIT 1");
if ($checkHotel && $checkHotel->num_rows === 0) {
    $hotelPw = $adminHash ?: optibiz_hash_password('admin123');
    $insHotel = $conn->prepare("
        INSERT INTO tenants (public_id, company_name, email, phone, username, password, plan_id, subscription_status, subscription_price, subscription_start_date, subscription_end_date, email_verified_at)
        VALUES ('OPT-PJY9XXXQ', 'Airport West Hotel', 'IT@airportwesthotel.com.gh', '+599507420', 'airport_west_hotel', ?, 1, 'active', 29.99, NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW())
    ");
    if ($insHotel) {
        $insHotel->bind_param("s", $hotelPw);
        $insHotel->execute();
        $hotelTenantId = (int)$conn->insert_id;
        $insHotel->close();

        // Also create company profile for Airport West Hotel
        $catRes = @$conn->query("SELECT id FROM categories WHERE name LIKE '%Hospitality%' OR name LIKE '%Hotel%' LIMIT 1");
        $hotelCatId = ($catRes && $catRes->num_rows > 0) ? (int)$catRes->fetch_assoc()['id'] : 1;

        $insComp = $conn->prepare("
            INSERT INTO customers (tenant_id, company_name, category_id, email, phone, whatsapp_number, website, booster_enabled, booster_min_stars, created_at)
            VALUES (?, 'Airport West Hotel', ?, 'IT@airportwesthotel.com.gh', '+599507420', '+599507420', 'www.airportwesthotel.com.gh', 1, 4, NOW())
        ");
        if ($insComp) {
            $insComp->bind_param("ii", $hotelTenantId, $hotelCatId);
            $insComp->execute();
            $insComp->close();
        }
        echo "[DB Init] Seeded 'Airport West Hotel' tenant account (OPT-PJY9XXXQ).\n";
    }
}

echo "[DB Init] Database initialization complete!\n";
