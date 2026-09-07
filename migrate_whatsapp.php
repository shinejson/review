<?php
/**
 * Migration: WhatsApp click-to-chat
 * Run this file once to add customers.whatsapp_number.
 *
 * New installs already have the column in database.sql, and
 * admin/company.php adds it on the first profile save, so this is
 * only needed to bring an existing database up to date by hand.
 */
require_once __DIR__ . '/config/database.php';

$checkCol = $conn->query("SHOW COLUMNS FROM customers LIKE 'whatsapp_number'");
if ($checkCol && $checkCol->num_rows > 0) {
    echo "Column 'whatsapp_number' already exists — nothing to do.\n";
} else {
    $alterSql = "ALTER TABLE customers ADD COLUMN whatsapp_number VARCHAR(30) NULL AFTER phone";
    if ($conn->query($alterSql) === TRUE) {
        echo "Column 'whatsapp_number' added to customers table successfully!\n";
    } else {
        echo "Error adding column: " . $conn->error . "\n";
    }
}

$conn->close();
echo "Migration complete!\n";
?>
