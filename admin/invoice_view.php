<?php
/**
 * ============================================================
 *  Workspace — Printable invoice / receipt
 * ============================================================
 *  A tenant's own copy of an invoice raised for their workspace.
 *  Unpaid invoices offer the online checkout; paid ones print as a
 *  receipt.
 *
 *      admin/invoice_view.php?id=12
 *
 *  Scoped to the signed-in workspace: an invoice belonging to
 *  another tenant is never rendered.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/payments.php';
require_once dirname(__DIR__) . '/includes/payment_document.php';

requireLogin();
requireTeamAccess('subscription');

$tenant_id = getTenantId();
$is_tenant = isTenant();

pay_ensure_schema($conn);

$invoice_id = (int) ($_GET['id'] ?? 0);
$invoice    = $invoice_id ? pay_invoice($conn, $invoice_id) : [];

// Invoices may only be opened by the workspace they belong to. A team
// member is always scoped to their own tenant, so the same check covers
// both account types.
if (!$invoice || (int) $invoice['tenant_id'] !== (int) $tenant_id) {
    sa_flash('error', 'That invoice could not be found for this workspace.');
    redirect('subscription.php');
}

$payable = !in_array($invoice['status'], ['paid', 'cancelled', 'refunded'], true)
    && pay_enabled_gateways($conn);

echo pay_render_document($conn, $invoice, [
    'back_url'   => 'subscription.php#billing',
    'back_label' => 'Back to subscription',
    'pay_url'    => $payable ? 'payment_checkout.php?invoice=' . (int) $invoice['id'] : '',
    'show_print' => true,
]);
