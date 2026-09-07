<?php
require_once __DIR__ . '/config/database.php';

echo "Running verification migration...\n";

// Add is_verified column
$r1 = $conn->query("SHOW COLUMNS FROM ratings LIKE 'is_verified'");
if ($r1 && $r1->num_rows > 0) {
    echo "Column 'is_verified' already exists.\n";
} else {
    $conn->query("ALTER TABLE ratings ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER responded_at");
    echo "Added column 'is_verified'.\n";
}

// Add verification_type column
$r2 = $conn->query("SHOW COLUMNS FROM ratings LIKE 'verification_type'");
if ($r2 && $r2->num_rows > 0) {
    echo "Column 'verification_type' already exists.\n";
} else {
    $conn->query("ALTER TABLE ratings ADD COLUMN verification_type VARCHAR(30) NULL AFTER is_verified");
    echo "Added column 'verification_type'.\n";
}

// Add momo_ref column
$r3 = $conn->query("SHOW COLUMNS FROM ratings LIKE 'momo_ref'");
if ($r3 && $r3->num_rows > 0) {
    echo "Column 'momo_ref' already exists.\n";
} else {
    $conn->query("ALTER TABLE ratings ADD COLUMN momo_ref VARCHAR(100) NULL AFTER verification_type");
    echo "Added column 'momo_ref'.\n";
}

// Add receipt_photo column
$r4 = $conn->query("SHOW COLUMNS FROM ratings LIKE 'receipt_photo'");
if ($r4 && $r4->num_rows > 0) {
    echo "Column 'receipt_photo' already exists.\n";
} else {
    $conn->query("ALTER TABLE ratings ADD COLUMN receipt_photo VARCHAR(255) NULL AFTER momo_ref");
    echo "Added column 'receipt_photo'.\n";
}

// Update database.sql documentation
echo "Migration finished successfully!\n";
