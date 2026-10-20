<?php
/**
 * Review trust schema: QR visits, category scores, moderation and photos.
 *
 * The runtime helper is idempotent, so this migration is simply its CLI
 * entry point. Run it once after deploying, before the new API endpoints
 * take traffic:
 *
 *   php migrations/migrate_review_trust.php
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/review_trust.php';

echo "Running review trust migration...\n";

if (!is_object($conn) || !method_exists($conn, 'query')) {
    fwrite(STDERR, "No database connection. Check config/database.php and .env.\n");
    exit(1);
}

review_trust_ensure_schema($conn);

echo "OK: ratings/customers columns and review_qr_tokens, review_moderation_queue,\n";
echo "    helpful_votes, reported_reviews are in place.\n";
