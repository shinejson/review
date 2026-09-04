<?php
require_once __DIR__ . '/config/database.php';

// Add logo column to tenants table
$conn->query("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS logo VARCHAR(500) DEFAULT NULL");
echo "Migration complete: Added 'logo' column to tenants table.\n";
