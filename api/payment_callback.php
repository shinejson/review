<?php
/**
 * ============================================================
 *  Payment gateway browser callback
 * ============================================================
 *  Where the tenant lands after paying on the gateway's hosted page.
 *  Paystack appends ?reference=… and Flutterwave ?tx_ref=…&status=… to
 *  whatever `callback_url` the checkout was started with.
 *
 *  This page is deliberately outside the signed-in panels: the browser
 *  may come back with an expired session, and the reference alone must
 *  be enough to finish the job. Nothing is trusted from the query
 *  string — the reference is re-verified with the gateway API before a
 *  single row is written, and a reference can only ever settle the
 *  invoice it was issued for.
 *
 *  The result is shown as a small standalone page (the webhook covers
 *  the case where the tenant never comes back at all).
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/payments.php';

pay_ensure_schema($conn);

$gateway_key = isset($_GET['gateway']) ? preg_replace('/[^a-z_]/', '', strtolower((string) $_GET['gateway'])) : '';
$reference   = '';

foreach (['reference', 'tx_ref', 'trxref'] as $param) {
    if (!empty($_GET[$param])) {
        $reference = trim((string) $_GET[$param]);
        break;
    }
}

// Fall back to the invoice's own stored reference when the provider did
// not echo one back (some bank-transfer flows return only a status).
$invoice_id = (int) ($_GET['invoice'] ?? 0);
if ($reference === '' && $invoice_id) {
    $invoice = pay_invoice($conn, $invoice_id);
    if ($invoice && !empty($invoice['checkout_reference'])) {
        $reference   = (string) $invoice['checkout_reference'];
        $gateway_key = $gateway_key !== '' ? $gateway_key : (string) $invoice['gateway_key'];
    }
}

if ($gateway_key === '') {
    $invoice = $invoice_id ? pay_invoice($conn, $invoice_id) : [];
    if ($invoice && !empty($invoice['gateway_key'])) {
        $gateway_key = (string) $invoice['gateway_key'];
    }
}

$result  = ['ok' => false, 'paid' => false, 'recorded' => false, 'duplicate' => false, 'message' => 'We could not match this payment to an invoice.', 'invoice' => []];
if ($gateway_key !== '' && $reference !== '') {
    $result = pay_settle_gateway_payment($conn, $gateway_key, $reference, 'callback');
}
if (!$result['invoice'] && $invoice_id) {
    $result['invoice'] = pay_invoice($conn, $invoice_id);
}

$invoice   = $result['invoice'];
$site_name = sa_setting($conn, 'site_name', 'Optibiz');
$logo_url  = sa_platform_logo($conn);
// Anchors below are absolute: a relative href on /api/... would resolve
// under /api/ and 404.
$panel_base = pay_public_base_url($conn);

$paid      = !empty($result['paid']);
$duplicate = !empty($result['duplicate']);
$heading   = $paid
    ? ($duplicate ? 'This payment was already recorded' : 'Payment received — thank you')
    : 'We could not confirm that payment';
$tone      = $paid ? '#15803d' : '#b91c1c';
$soft      = $paid ? '#dcfce7' : '#fee2e2';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo sa_e($heading); ?> — <?php echo sa_e($site_name); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #eef2f7; color: #0f172a; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .card {
            width: 100%; max-width: 520px; background: #fff; border-radius: 16px;
            border: 1px solid #e2e8f0; box-shadow: 0 18px 44px rgba(15, 36, 56, 0.1);
            overflow: hidden;
        }
        .card-head {
            padding: 28px 30px 22px; border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; gap: 12px;
        }
        .card-head img { max-height: 40px; width: auto; }
        .card-head h1 { font-size: 17px; font-weight: 800; }
        .card-body { padding: 26px 30px 30px; }
        .icon {
            width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center;
            background: <?php echo $soft; ?>; color: <?php echo $tone; ?>; margin-bottom: 16px;
        }
        h2 { font-size: 19px; font-weight: 800; margin-bottom: 8px; }
        p { font-size: 13.8px; line-height: 1.65; color: #475569; }
        .summary {
            margin: 20px 0; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 14px 16px; font-size: 13.4px;
        }
        .summary div { display: flex; justify-content: space-between; gap: 12px; padding: 3px 0; }
        .summary strong { font-weight: 700; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
        .actions a {
            display: inline-flex; align-items: center; gap: 7px; text-decoration: none;
            font-size: 13.4px; font-weight: 700; padding: 11px 18px; border-radius: 9px;
            border: 1px solid #e2e8f0; color: #475569;
        }
        .actions a.is-primary { background: #0f2438; border-color: #0f2438; color: #fff; }
        .foot { padding: 16px 30px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-head">
<?php if ($logo_url): ?>
        <img src="<?php echo sa_e($logo_url); ?>" alt="<?php echo sa_e($site_name); ?>">
<?php endif; ?>
        <h1><?php echo sa_e($site_name); ?></h1>
    </div>

    <div class="card-body">
        <div class="icon">
<?php if ($paid): ?>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
<?php else: ?>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
<?php endif; ?>
        </div>

        <h2><?php echo sa_e($heading); ?></h2>
        <p>
<?php if ($paid): ?>
            Your payment was confirmed by the payment provider. A member of the team reviews it before the
            new period is applied, so your subscription dates may take a short while to update.
<?php else: ?>
            <?php echo sa_e($result['message'] !== '' ? $result['message'] : 'The provider did not confirm this payment.'); ?>
            If money left your account, nothing is lost — it will be matched to your invoice and appears in the
            workspace once confirmed.
<?php endif; ?>
        </p>

<?php if ($invoice): ?>
        <div class="summary">
            <div><span>Invoice</span><strong><?php echo sa_e($invoice['invoice_number']); ?></strong></div>
            <div><span>Workspace</span><strong><?php echo sa_e($invoice['company_name']); ?></strong></div>
            <div><span>Amount</span><strong><?php echo sa_e(sa_money($invoice['total'])); ?></strong></div>
            <div><span>Status</span><strong><?php echo sa_e(pay_status_label($invoice['status'])); ?></strong></div>
<?php if ($reference !== ''): ?>
            <div><span>Reference</span><strong style="font-family:ui-monospace,monospace"><?php echo sa_e($reference); ?></strong></div>
<?php endif; ?>
        </div>
<?php endif; ?>

        <div class="actions">
<?php if ($invoice): ?>
            <a class="is-primary" href="<?php echo sa_e($panel_base); ?>/admin/invoice_view.php?id=<?php echo (int) $invoice['id']; ?>">View the invoice</a>
<?php endif; ?>
            <a href="<?php echo sa_e($panel_base); ?>/admin/subscription.php#billing">Go to my subscription</a>
        </div>
    </div>

    <div class="foot">
        Keep this reference for your records:
        <span style="font-family:ui-monospace,monospace"><?php echo sa_e($reference !== '' ? $reference : 'n/a'); ?></span>
    </div>
</div>
</body>
</html>
