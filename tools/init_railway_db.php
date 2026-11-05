<?php
/**
 * Auto-initializes and migrates the database schema.
 * Safe to run on every container start; all operations are idempotent.
 */
require_once __DIR__ . '/../config/database.php';

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

// Ensure review_trust helper tables if file exists
$reviewTrustFile = dirname(__DIR__) . '/includes/review_trust.php';
if (file_exists($reviewTrustFile)) {
    require_once $reviewTrustFile;
    if (function_exists('review_trust_ensure_schema')) {
        review_trust_ensure_schema($conn);
        echo "[DB Init] Review trust schema ensured.\n";
    }
}

echo "[DB Init] Database initialization complete!\n";
