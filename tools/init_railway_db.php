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

// Remove CREATE DATABASE and USE statements so everything goes into current DB_NAME
$cleanSql = preg_replace('/CREATE DATABASE IF NOT EXISTS [^;]+;/i', '', $rawSql);
$cleanSql = preg_replace('/USE [^;]+;/i', '', $cleanSql);

if ($conn->multi_query($cleanSql)) {
    $count = 0;
    do {
        $count++;
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    if ($conn->errno) {
        echo "[DB Init] Completed with warnings/notice: " . $conn->error . "\n";
    } else {
        echo "[DB Init] Successfully imported schema ({$count} queries executed).\n";
    }
} else {
    echo "[DB Init] Error executing schema: " . $conn->error . "\n";
}
