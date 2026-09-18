<?php
/**
 * Migration: payment integrations & billing
 * ==========================================
 * Brings an existing installation up to the payments schema:
 *
 *   payment_gateways     — the integrations the platform owner configures
 *   payment_invoices     — invoices raised against a workspace
 *   subscription_payments— extended into the full money ledger
 *   payment_refunds      — refunds and credit notes
 *   payment_events       — webhook / callback audit trail
 *
 * Pages also self-heal on first load (includes/payments.php → pay_ensure_schema),
 * so running this by hand is optional — it just does the work up front and
 * prints what changed.
 *
 *      php migrate_payments.php
 *      — or open it in the browser —
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/payments.php';

header('Content-Type: text/plain; charset=utf-8');
echo "Payments migration\n==================\n\n";

/* ---- the ledger's new columns, before/after ---- */
$before = array_merge(pay_columns($conn, 'subscription_payments'), []);
pay_ensure_schema($conn);
$after  = pay_columns($conn, 'subscription_payments');

$added = array_values(array_diff($after, $before));
if ($added) {
    echo "subscription_payments — added: " . implode(', ', $added) . "\n";
} else {
    echo "subscription_payments — already up to date.\n";
}

/* ---- report on every billing table ---- */
$tables = ['payment_gateways', 'payment_invoices', 'subscription_payments', 'payment_refunds', 'payment_events'];
echo "\nTables:\n";
foreach ($tables as $table) {
    $cols = pay_columns($conn, $table);
    printf("  %-24s %s (%d columns)\n", $table, $cols ? 'ok' : 'MISSING', count($cols));
}

/* ---- make sure the three integrations are seeded ---- */
$gateways = pay_gateways($conn);
echo "\nIntegrations:\n";
foreach ($gateways as $key => $gw) {
    printf(
        "  %-16s %-9s %-6s %s\n",
        $key,
        $gw['is_enabled'] ? 'enabled' : 'off',
        $gw['mode'],
        $gw['ready'] ? 'ready' : 'needs configuring'
    );
}
if (!$gateways) {
    echo "  none — check that payment_gateways was created.\n";
}

echo "\nNext: open superadmin/payment_gateways.php and paste your API keys,\n";
echo "then switch the integration on.\n";
echo "\nMigration complete!\n";

$conn->close();
