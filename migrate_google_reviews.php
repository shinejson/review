<?php
/**
 * Migration: Add Google Reviews-like features
 */
require_once __DIR__ . '/config/database.php';

// Add photos column (JSON array of photo paths)
$check = $conn->query("SHOW COLUMNS FROM ratings LIKE 'photos'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE ratings ADD COLUMN photos TEXT NULL COMMENT 'JSON array of photo paths' AFTER comment");
    echo "Added 'photos' column\n";
}

// Add helpful_count column
$check = $conn->query("SHOW COLUMNS FROM ratings LIKE 'helpful_count'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE ratings ADD COLUMN helpful_count INT DEFAULT 0 AFTER admin_reply");
    echo "Added 'helpful_count' column\n";
}

// Add reported column
$check = $conn->query("SHOW COLUMNS FROM ratings LIKE 'reported'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE ratings ADD COLUMN reported TINYINT(1) DEFAULT 0 AFTER helpful_count");
    echo "Added 'reported' column\n";
}

// Create helpful_votes table to track who voted
$conn->query("CREATE TABLE IF NOT EXISTS helpful_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rating_id INT NOT NULL,
    voter_ip VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (rating_id, voter_ip),
    FOREIGN KEY (rating_id) REFERENCES ratings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Created 'helpful_votes' table\n";

// Create reported_reviews table
$conn->query("CREATE TABLE IF NOT EXISTS reported_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rating_id INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    reporter_ip VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rating_id) REFERENCES ratings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Created 'reported_reviews' table\n";

$conn->close();
echo "Migration complete!\n";
?>