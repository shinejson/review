<?php
/**
 * Migration: create the notification inbox table.
 *
 * Optional — includes/notifications.php builds the same table on the
 * first request that needs it (the same self-healing pattern the admin
 * panel uses for its own tables). Run this once anyway when you would
 * rather have the schema in place before anyone signs in, or when the
 * app user has no CREATE privilege.
 *
 *     php migrate_notifications.php
 */
require_once dirname(__DIR__) . '/config/database.php';

$sql = "CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audience VARCHAR(20) NOT NULL DEFAULT 'tenant' COMMENT 'platform (control center) | tenant (workspace)',
    tenant_id INT NOT NULL DEFAULT 0 COMMENT '0 for platform-wide rows',
    type VARCHAR(40) NOT NULL,
    title VARCHAR(190) NOT NULL,
    message VARCHAR(500) NULL,
    link VARCHAR(255) NULL COMMENT 'relative to the panel that owns the row',
    icon VARCHAR(30) NULL,
    tone VARCHAR(20) NOT NULL DEFAULT 'info' COMMENT 'info | success | warning | danger',
    entity_type VARCHAR(40) NULL,
    entity_id INT NULL,
    dedupe_key VARCHAR(190) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    deleted_at DATETIME NULL COMMENT 'dismissed rows are kept so the sync cannot resurrect them',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notif_dedupe (dedupe_key),
    INDEX idx_notif_scope (audience, tenant_id, is_read, created_at),
    INDEX idx_notif_type (type),
    INDEX idx_notif_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql) === TRUE) {
    echo "Table 'notifications' is ready.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
    exit(1);
}

/* Backfill: file the notices the panels would have collected while the
   table did not exist, so an inbox is not empty on day one. */
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

$platform = notifications_sync($conn, 'platform', 0, true);
echo "Filed {$platform} control center notice(s).\n";

$tenants = sa_query($conn, 'SELECT id FROM tenants ORDER BY id ASC', 'tenants');
$total = 0;
foreach ($tenants as $t) {
    $total += notifications_sync($conn, 'tenant', (int) $t['id'], true);
}
echo 'Filed ' . $total . ' workspace notice(s) for ' . count($tenants) . " tenant(s).\n";

$conn->close();
echo "Migration complete!\n";
