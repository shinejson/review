<?php
/**
 * ============================================================
 *  Workspace — Pay an invoice
 * ============================================================
 *  The tenant side of the billing flow:
 *
 *    1. they pick an integration the platform owner has switched on
 *    2. the API gateways (Paystack / Flutterwave) get a hosted checkout
 *       and the browser is sent there
 *    3. the manual profile shows the bank details and lets them declare
 *       a transfer they have already made
 *
 *  Whatever the route, the money arrives in the ledger as `pending`
 *  and the platform owner confirms it — the workspace can never
 *  activate its own plan.
 *
 *      admin/payment_checkout.php?invoice=12
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/payments.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';

requireLogin();
requireTeamAccess('subscription');

$tenant_id = getTenantId();
$is_tenant = isTenant();

pay_ensure_schema($conn);

$invoice_id = (int) ($_GET['invoice'] ?? $_POST['invoice_id'] ?? 0);
$invoice    = $invoice_id ? pay_invoice($conn, $invoice_id) : [];

if (!$invoice || (int) $invoice['tenant_id'] !== (int) $tenant_id) {
    sa_flash('error', 'That invoice could not be found for this workspace.');
    redirect('subscription.php');
}

$gateways = pay_enabled_gateways($conn);
$settled  = in_array($invoice['status'], ['paid', 'cancelled', 'refunded'], true);

/* ============================================================
   POST — kick off a payment
   ============================================================ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('payment_checkout.php?invoice=' . $invoice_id);
    }

    if ($settled) {
        sa_flash('warning', 'This invoice is already ' . strtolower(pay_status_label($invoice['status'])) . '.');
        redirect('subscription.php');
    }

    $action  = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $gateway_key = isset($_POST['gateway_key']) ? preg_replace('/[^a-z_]/', '', strtolower((string) $_POST['gateway_key'])) : '';

    /* ---- hosted checkout on the provider's page ---- */
    if ($action === 'start' && $gateway_key !== '') {
        $gateway = isset($gateways[$gateway_key]) ? $gateways[$gateway_key] : null;
        if (!$gateway) {
            sa_flash('error', 'That payment method is not available.');
            redirect('payment_checkout.php?invoice=' . $invoice_id);
        }

        // Second thoughts on a hosted page: keep the tenant in the loop
        // rather than stranding them if the gateway is unreachable.
        $callback = pay_public_base_url($conn) . '/api/payment_callback.php'
            . '?gateway=' . rawurlencode($gateway_key) . '&invoice=' . $invoice_id;

        $result = pay_start_checkout($conn, $gateway_key, $invoice, $callback);

        if ($result['ok'] && $result['url'] !== '') {
            admin_log_activity(
                $conn,
                'payment_start',
                'Started an online payment for invoice ' . $invoice['invoice_number'] . ' via ' . $gateway_key,
                'payment_invoice',
                (int) $invoice['id']
            );
            header('Location: ' . $result['url']);
            exit();
        }
        sa_flash('error', $result['message'] !== '' ? $result['message'] : 'Could not open the checkout.');
        redirect('payment_checkout.php?invoice=' . $invoice_id);
    }

    /* ---- "I have already paid" for the manual profile ---- */
    if ($action === 'manual_declared') {
        $reference = trim((string) ($_POST['reference'] ?? ''));
        $method    = trim((string) ($_POST['method'] ?? 'Bank transfer'));
        $gateway_key = $gateway_key !== '' ? $gateway_key : 'bank_transfer';

        $payment_id = pay_record($conn, [
            'tenant_id'       => (int) $tenant_id,
            'invoice_id'      => (int) $invoice['id'],
            'amount'          => (float) $invoice['total'],
            'status'          => 'pending',
            'payment_method'  => $method,
            'gateway_key'     => $gateway_key,
            'gateway_reference' => $reference,
            'transaction_ref' => $reference,
            'currency'        => (string) $invoice['currency'],
            'months_extended' => (int) $invoice['months'],
            'payer_email'     => (string) $invoice['tenant_email'],
            'payer_name'      => (string) $invoice['company_name'],
            'notes'           => 'Tenant declared a payment' . ($reference !== '' ? ' (ref ' . $reference . ')' : '') . '.',
            'source'          => 'offline',
            'recorded_by'     => 'Tenant: ' . $invoice['company_name'],
        ]);

        if (!$payment_id) {
            sa_flash('error', 'Could not log that payment. Please try again.');
            redirect('payment_checkout.php?invoice=' . $invoice_id);
        }

        pay_invoice_set_status($conn, (int) $invoice['id'], 'processing');
        pay_log_event($conn, [
            'gateway_key' => $gateway_key,
            'event_type'  => 'payment.declared',
            'reference'   => $reference,
            'invoice_id'  => (int) $invoice['id'],
            'payment_id'  => $payment_id,
            'payload'     => 'Workspace reported a manual payment.',
        ]);

        admin_log_activity(
            $conn,
            'payment_declared',
            'Reported a ' . $method . ' payment for invoice ' . $invoice['invoice_number'],
            'payment',
            (int) $payment_id
        );
        sa_flash('success', 'Thanks — we have logged your payment. The platform owner confirms it and your new period starts automatically.');
        redirect('subscription.php#billing');
    }

    redirect('payment_checkout.php?invoice=' . $invoice_id);
}

/* ============================================================
   Page
   ============================================================ */
$manual_gateway = null;
foreach ($gateways as $key => $gw) {
    if ($gw['driver'] === 'manual') {
        $manual_gateway = $gw;
        break;
    }
}
$api_gateways = array_filter($gateways, function ($gw) {
    return $gw['driver'] !== 'manual';
});

$robots    = 'noindex, nofollow';
$BASE      = '../';
$pageTitle = 'Pay invoice ' . $invoice['invoice_number'];
$activeNav = 'subscription';
include __DIR__ . '/_shell.php';
?>

<div class="page-header">
    <div>
        <h1>Pay invoice</h1>
        <p>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?> for <?php echo htmlspecialchars($invoice['company_name']); ?>.</p>
    </div>
    <a class="btn btn-secondary" href="invoice_view.php?id=<?php echo (int) $invoice['id']; ?>">View invoice</a>
</div>

<?php if ($flash = sa_take_flash()): ?>
<div class="alert alert-<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>">
    <?php echo htmlspecialchars($flash['message']); ?>
</div>
<?php endif; ?>

<div class="form-card">
    <h3 class="table-title">Amount due</h3>
    <div class="subscription-detail-row">
        <span>Invoice</span>
        <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>
    </div>
    <div class="subscription-detail-row">
        <span>Description</span>
        <strong><?php echo htmlspecialchars($invoice['subject']); ?></strong>
    </div>
    <div class="subscription-detail-row">
        <span>Term</span>
        <strong><?php echo (int) $invoice['months']; ?> month<?php echo (int) $invoice['months'] === 1 ? '' : 's'; ?></strong>
    </div>
    <div class="subscription-detail-row">
        <span>Due date</span>
        <strong><?php echo htmlspecialchars(sa_date($invoice['due_date'], 'M j, Y', 'On receipt')); ?></strong>
    </div>
    <div class="subscription-detail-row">
        <span>Total</span>
        <strong style="font-size:20px"><?php echo htmlspecialchars(sa_money($invoice['total'])); ?></strong>
    </div>
    <div class="subscription-detail-row">
        <span>Status</span>
        <strong><?php echo htmlspecialchars(pay_status_label($invoice['status'])); ?></strong>
    </div>
</div>

<?php if ($settled): ?>
<div class="form-card">
    <h3 class="table-title">Nothing left to pay</h3>
    <p class="muted">
        This invoice is <?php echo htmlspecialchars(strtolower(pay_status_label($invoice['status']))); ?>.
        <a href="subscription.php#billing">Back to your subscription</a>.
    </p>
</div>

<?php elseif (!$gateways): ?>
<div class="form-card">
    <h3 class="table-title">Online payment is not available yet</h3>
    <p class="muted">
        The platform owner has not switched on a payment method. Your invoice is still on record —
        contact them to settle it, and it will be applied to your workspace as soon as it is confirmed.
    </p>
</div>

<?php else: ?>

<?php if ($invoice['status'] === 'processing'): ?>
<div class="alert alert-success">
    We have a payment for this invoice waiting to be confirmed. Paying again is not necessary — the new
    period starts as soon as it is reviewed.
</div>
<?php endif; ?>

<?php if ($api_gateways): ?>
<div class="form-card">
    <h3 class="table-title">Pay now</h3>
    <p class="muted" style="margin-bottom:14px">You will be taken to the payment provider to complete the payment securely.</p>

<?php foreach ($api_gateways as $key => $gw): ?>
    <form method="POST" action="payment_checkout.php?invoice=<?php echo (int) $invoice['id']; ?>" style="margin-bottom:12px">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="start">
        <input type="hidden" name="gateway_key" value="<?php echo htmlspecialchars($key); ?>">
        <div class="subscription-detail-row" style="align-items:center;gap:14px">
            <span style="display:flex;align-items:center;gap:10px">
                <strong><?php echo htmlspecialchars($gw['display_name']); ?></strong>
                <span class="muted"><?php echo htmlspecialchars($gw['methods']); ?></span>
            </span>
            <button type="submit" class="btn btn-primary">
                Pay <?php echo htmlspecialchars(sa_money($invoice['total'])); ?>
            </button>
        </div>
    </form>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($manual_gateway): ?>
<div class="form-card">
    <h3 class="table-title"><?php echo htmlspecialchars($manual_gateway['display_name']); ?></h3>

<?php if (!empty($manual_gateway['instructions'])): ?>
    <p class="muted" style="white-space:pre-line;margin-bottom:12px"><?php echo htmlspecialchars($manual_gateway['instructions']); ?></p>
<?php endif; ?>

<?php if ($manual_gateway['bank_name'] !== '' || $manual_gateway['account_number'] !== ''): ?>
    <div class="subscription-detail-row">
        <span>Bank</span>
        <strong><?php echo htmlspecialchars($manual_gateway['bank_name'] !== '' ? $manual_gateway['bank_name'] : '—'); ?></strong>
    </div>
    <div class="subscription-detail-row">
        <span>Account name</span>
        <strong><?php echo htmlspecialchars($manual_gateway['account_name'] !== '' ? $manual_gateway['account_name'] : '—'); ?></strong>
    </div>
    <div class="subscription-detail-row">
        <span>Account number</span>
        <strong><?php echo htmlspecialchars($manual_gateway['account_number'] !== '' ? $manual_gateway['account_number'] : '—'); ?></strong>
    </div>
    <div class="subscription-detail-row">
        <span>Reference to quote</span>
        <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>
    </div>
<?php endif; ?>

    <form method="POST" action="payment_checkout.php?invoice=<?php echo (int) $invoice['id']; ?>" style="margin-top:16px">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="manual_declared">
        <input type="hidden" name="gateway_key" value="<?php echo htmlspecialchars($manual_gateway['gateway_key']); ?>">

        <div class="subscription-detail-row">
            <span><label for="pc_method">How did you pay?</label></span>
            <select id="pc_method" name="method">
                <option value="Bank transfer">Bank transfer</option>
                <option value="Mobile money">Mobile money</option>
                <option value="Cash">Cash</option>
                <option value="Cheque">Cheque</option>
            </select>
        </div>

        <div class="subscription-detail-row">
            <span><label for="pc_reference">Payment reference</label></span>
            <input type="text" id="pc_reference" name="reference" maxlength="100" placeholder="Slip / transaction ID">
        </div>

        <p class="muted" style="margin:12px 0">
            Mark this once the money has left your account. It is logged against the invoice and
            confirmed by the platform owner before your new period starts.
        </p>
        <button type="submit" class="btn btn-primary">I have paid <?php echo htmlspecialchars(sa_money($invoice['total'])); ?></button>
    </form>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/_shell_footer.php'; ?>
