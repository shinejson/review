<?php
/**
 * ============================================================
 *  Super Admin — Printable invoice / receipt
 * ============================================================
 *  Opens any invoice raised against a workspace. Paid invoices
 *  print as an official receipt with the payment history attached.
 *
 *      superadmin/invoice_view.php?id=12
 *
 *  The document itself is rendered by includes/payment_document.php
 *  so the tenant's copy is byte-for-byte the same.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/payments.php';
require_once dirname(__DIR__) . '/includes/payment_document.php';

requireSuperAdminLogin();
require_sa_permission('finance');

pay_ensure_schema($conn);

$invoice_id = (int) ($_GET['id'] ?? 0);
$invoice    = $invoice_id ? pay_invoice($conn, $invoice_id) : [];

if (!$invoice) {
    sa_flash('error', 'That invoice could not be found.');
    redirect('finance.php');
}

echo pay_render_document($conn, $invoice, [
    'back_url'   => 'finance.php#invoices',
    'back_label' => 'Back to financials',
    'show_print' => true,
]);
