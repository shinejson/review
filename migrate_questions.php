<?php
/**
 * Migration: Create rating_questions table
 * Run this file once to create the table
 */
require_once __DIR__ . '/config/database.php';

$sql = "CREATE TABLE IF NOT EXISTS rating_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    question_text VARCHAR(500) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql) === TRUE) {
    echo "Table 'rating_questions' created successfully!\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

// Also add question_id to ratings table if not exists
$checkCol = $conn->query("SHOW COLUMNS FROM ratings LIKE 'question_id'");
if ($checkCol && $checkCol->num_rows === 0) {
    $alterSql = "ALTER TABLE ratings ADD COLUMN question_id INT NULL AFTER company_id";
    if ($conn->query($alterSql) === TRUE) {
        echo "Column 'question_id' added to ratings table successfully!\n";
    } else {
        echo "Error adding column: " . $conn->error . "\n";
    }
}

$conn->close();
echo "Migration complete!\n";
?>