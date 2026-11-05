<?php
/**
 * Auto-initializes the database schema if tables are not present yet.
 * Safe to run on every container start; it checks if 'tenants' exists and exits immediately if so.
 */
require_once __DIR__ . '/../config/database.php';

echo "[DB Init] Connecting to " . DB_HOST . ":" . DB_PORT . " / " . DB_NAME . "...\n";

if (!isset($conn) || !is_object($conn)) {
    echo "[DB Init] Warning: Database connection object not available.\n";
    exit(0);
}

// Check if tables already exist
$check = @$conn->query("SHOW TABLES LIKE 'tenants'");
if ($check && (int)$check->num_rows > 0) {
    echo "[DB Init] Schema already initialized ('tenants' table exists). Skipping.\n";
    exit(0);
}

echo "[DB Init] Initializing schema from database.sql...\n";
$sqlPath = dirname(__DIR__) . '/database.sql';
if (!file_exists($sqlPath)) {
    echo "[DB Init] database.sql not found at {$sqlPath}\n";
    exit(0);
}

$rawSql = file_get_contents($sqlPath);
if ($rawSql === false) {
    echo "[DB Init] Failed to read database.sql\n";
    exit(0);
}

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
$success = 0;
$skipped = 0;

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;

    // Skip CREATE DATABASE and USE statements
    if (preg_match('/^(?:CREATE\s+DATABASE|USE\s+)/i', $stmt)) {
        continue;
    }

    try {
        if (@$conn->query($stmt)) {
            $success++;
        } else {
            $skipped++;
        }
    } catch (Throwable $e) {
        $skipped++;
    }
}

echo "[DB Init] Schema initialization complete: {$success} statements executed, {$skipped} skipped.\n";
