<?php
/**
 * ============================================================
 *  Super Admin — Financial centre
 * ============================================================
 *  Where the money is actually collected and accounted for:
 *
 *    • revenue KPIs (collected, MRR/ARR, outstanding, fees)
 *    • the approvals queue for gateway money awaiting confirmation
 *    • a renewals watchlist so nothing lapses unnoticed
 *    • invoices and the payment ledger with filters + CSV export
 *    • refunds / credit notes
 *    • the webhook & callback activity log
 *
 *  Only confirmed money counts as revenue. A payment taken by
 *  Paystack or Flutterwave arrives as `pending` and stays out of the
 *  figures until the platform owner confirms it — that is the
 *  "checkout now, activate on approval" rule the billing flow uses.
 *
 *  Printable documents live in superadmin/invoice_view.php; the
 *  gateway credentials are configured in payment_gateways.php.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/payments.php';

requireSuperAdminLogin();
require_sa_permission('finance');

pay_ensure_schema($conn);

$actor = isset($_SESSION['super_admin_username']) ? (string) $_SESSION['super_admin_username'] : 'Super Admin';

/* ============================================================
   POST handlers
   ============================================================ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('finance.php');
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    /* ---- approve gateway money and activate the plan ---- */
    if ($action === 'confirm_payment') {
        $result = pay_confirm_payment($conn, (int) ($_POST['payment_id'] ?? 0), $actor);
        sa_flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('finance.php#approvals');
    }

    /* ---- knock it back (wrong amount, fraud, duplicate) ---- */
    if ($action === 'reject_payment') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $result = pay_reject_payment($conn, (int) ($_POST['payment_id'] ?? 0), $actor, $reason);
        sa_flash($result['ok'] ? 'warning' : 'error', $result['message']);
        redirect('finance.php#approvals');
    }

    /* ---- record money that arrived outside a gateway ---- */
    if ($action === 'record_offline') {
        $tenant_id = (int) ($_POST['tenant_id'] ?? 0);
        $amount    = (float) ($_POST['amount'] ?? 0);
        $method    = trim((string) ($_POST['payment_method'] ?? 'Bank transfer'));
        $reference = trim((string) ($_POST['reference'] ?? ''));
        $months    = max(0, min(60, (int) ($_POST['months_extended'] ?? 0)));
        $notes     = trim((string) ($_POST['notes'] ?? ''));
        $invoice_id = (int) ($_POST['invoice_id'] ?? 0);

        if (!$tenant_id || $amount <= 0) {
            sa_flash('error', 'Choose a workspace and enter the amount you received.');
            redirect('finance.php');
        }

        // If the office is settling an open invoice, take the months from
        // there so the subscription gets exactly what was billed.
        if ($invoice_id) {
            $invoice = pay_invoice($conn, $invoice_id);
            if ($invoice && (int) $invoice['tenant_id'] === $tenant_id && $months === 0) {
                $months = (int) $invoice['months'];
            }
        }

        $payment_id = pay_record($conn, [
            'tenant_id'    => $tenant_id,
            'invoice_id'   => $invoice_id,
            'amount'       => $amount,
            'status'       => 'confirmed',
            'payment_method' => $method,
            'gateway_reference' => $reference,
            'transaction_ref'   => $reference,
            'months_extended'   => $months,
            'notes'        => $notes,
            'source'       => 'offline',
            'recorded_by'  => $actor,
        ]);

        if (!$payment_id) {
            sa_flash('error', 'Could not record that payment.');
            redirect('finance.php');
        }

        if ($invoice_id) {
            $invoice = pay_invoice($conn, $invoice_id);
            if ($invoice) {
                pay_invoice_apply($conn, $invoice, $actor);
            }
        } elseif ($months > 0) {
            @$conn->query(
                "UPDATE tenants
                    SET subscription_end_date = DATE_ADD(GREATEST(COALESCE(subscription_end_date, CURDATE()), CURDATE()), INTERVAL " . $months . " MONTH),
                        subscription_status = 'active'
                  WHERE id = " . $tenant_id
            );
        }

        $receipt = (string) sa_scalar($conn, "SELECT receipt_number FROM subscription_payments WHERE id = " . $payment_id, '', 'subscription_payments');
        sa_flash('success', sa_money($amount) . ' recorded. Receipt ' . $receipt . ($months > 0 ? ' — subscription extended by ' . $months . ' month' . ($months === 1 ? '' : 's') . '.' : '.'));
        redirect('finance.php');
    }

    /* ---- raise an invoice ---- */
    if ($action === 'issue_invoice') {
        $result = pay_invoice_create($conn, [
            'tenant_id' => (int) ($_POST['tenant_id'] ?? 0),
            'plan_id'   => (int) ($_POST['plan_id'] ?? 0),
            'months'    => (int) ($_POST['months'] ?? 12),
            'amount'    => isset($_POST['amount']) && $_POST['amount'] !== '' ? (float) $_POST['amount'] : null,
            'due_date'  => trim((string) ($_POST['due_date'] ?? '')),
            'notes'     => trim((string) ($_POST['notes'] ?? '')),
            'purpose'   => trim((string) ($_POST['purpose'] ?? 'renewal')),
            'issued_by' => $actor,
        ]);
        sa_flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect($result['ok'] ? 'finance.php?invoice=' . (int) $result['id'] . '#invoices' : 'finance.php#invoices');
    }

    /* ---- settle an invoice in the office ---- */
    if ($action === 'invoice_mark_paid') {
        $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
        $invoice = pay_invoice($conn, $invoice_id);
        if (!$invoice) {
            sa_flash('error', 'That invoice no longer exists.');
            redirect('finance.php#invoices');
        }
        if ($invoice['status'] === 'paid') {
            sa_flash('warning', 'Invoice ' . $invoice['invoice_number'] . ' is already paid.');
            redirect('finance.php#invoices');
        }

        $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float) $_POST['amount'] : (float) $invoice['total'];
        $method = trim((string) ($_POST['payment_method'] ?? 'Bank transfer'));
        $reference = trim((string) ($_POST['reference'] ?? ''));

        $payment_id = pay_record($conn, [
            'tenant_id'       => (int) $invoice['tenant_id'],
            'invoice_id'      => $invoice_id,
            'amount'          => $amount,
            'status'          => 'confirmed',
            'payment_method'  => $method,
            'gateway_reference' => $reference,
            'transaction_ref' => $reference,
            'currency'        => (string) $invoice['currency'],
            'months_extended' => (int) $invoice['months'],
            'source'          => 'offline',
            'recorded_by'     => $actor,
            'notes'           => 'Settled against ' . $invoice['invoice_number'],
        ]);

        if (!$payment_id) {
            sa_flash('error', 'Could not record that payment.');
            redirect('finance.php#invoices');
        }

        pay_invoice_apply($conn, $invoice, $actor);
        sa_flash('success', 'Invoice ' . $invoice['invoice_number'] . ' marked paid and the subscription updated.');
        redirect('finance.php#invoices');
    }

    /* ---- cancel an invoice ---- */
    if ($action === 'cancel_invoice') {
        $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
        $invoice = pay_invoice($conn, $invoice_id);
        if (!$invoice || $invoice['status'] === 'paid') {
            sa_flash('error', 'That invoice cannot be cancelled.');
            redirect('finance.php#invoices');
        }
        pay_invoice_set_status($conn, $invoice_id, 'cancelled');
        sa_flash('success', 'Invoice ' . $invoice['invoice_number'] . ' cancelled.');
        redirect('finance.php#invoices');
    }

    /* ---- refund / credit note ---- */
    if ($action === 'refund_payment') {
        $payment_id = (int) ($_POST['payment_id'] ?? 0);
        $result = pay_refund_create($conn, [
            'payment_id' => $payment_id,
            'amount'     => (float) ($_POST['amount'] ?? 0),
            'kind'       => ($_POST['kind'] ?? 'refund') === 'credit' ? 'credit' : 'refund',
            'reason'     => trim((string) ($_POST['reason'] ?? '')),
            'processed_by' => $actor,
        ]);
        sa_flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('finance.php#refunds');
    }

    /* ---- email an invoice to the workspace ---- */
    if ($action === 'email_invoice') {
        $invoice = pay_invoice($conn, (int) ($_POST['invoice_id'] ?? 0));
        if (!$invoice) {
            sa_flash('error', 'That invoice no longer exists.');
            redirect('finance.php#invoices');
        }
        $mailer = dirname(__DIR__) . '/includes/mailer.php';
        if (is_file($mailer)) {
            require_once $mailer;
        }
        if (!function_exists('sa_send_mail')) {
            sa_flash('error', 'Email is not available on this installation.');
            redirect('finance.php#invoices');
        }

        $site = sa_setting($conn, 'site_name', 'Optibiz');
        $body = '<p>Hello ' . sa_e($invoice['company_name']) . ',</p>'
            . '<p>Invoice <strong>' . sa_e($invoice['invoice_number']) . '</strong> for '
            . sa_e($invoice['subject']) . ' is ready.</p>'
            . '<p><strong>Amount due:</strong> ' . sa_e(pay_amount($invoice['total'], $invoice['currency']))
            . '<br><strong>Due date:</strong> ' . sa_e(sa_date($invoice['due_date'])) . '</p>'
            . '<p>Sign in to your workspace and open <em>Subscription</em> to pay online.</p>';
        $html = function_exists('sa_render_email_template')
            ? sa_render_email_template('Invoice ' . $invoice['invoice_number'], $body, $site)
            : $body;

        $sent = sa_send_mail($invoice['tenant_email'], 'Invoice ' . $invoice['invoice_number'] . ' from ' . $site, $html, $conn);
        sa_flash(!empty($sent['success']) ? 'success' : 'error',
            !empty($sent['success'])
                ? 'Invoice ' . $invoice['invoice_number'] . ' emailed to ' . $invoice['tenant_email'] . '.'
                : 'Could not send the email: ' . (isset($sent['message']) ? $sent['message'] : 'unknown error'));
        redirect('finance.php#invoices');
    }

    redirect('finance.php');
}

/* ============================================================
   CSV exports
   ============================================================ */
if (isset($_GET['export'])) {
    $kind = preg_replace('/[^a-z_]/', '', (string) $_GET['export']);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=optibiz-' . $kind . '-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');

    if ($kind === 'invoices') {
        fputcsv($out, ['Invoice', 'Workspace', 'Email', 'Plan', 'Purpose', 'Months', 'Amount', 'Discount', 'Tax', 'Total', 'Currency', 'Status', 'Gateway', 'Due', 'Paid', 'Issued']);
        foreach (pay_invoices($conn, [], 2000) as $inv) {
            fputcsv($out, [
                $inv['invoice_number'], $inv['company_name'], $inv['tenant_email'], $inv['plan_name'],
                $inv['purpose'], $inv['months'], $inv['amount'], $inv['discount'], $inv['tax'], $inv['total'],
                $inv['currency'], pay_status_label($inv['status']), pay_gateway_label($inv['gateway_key']),
                $inv['due_date'], $inv['paid_at'], $inv['created_at'],
            ]);
        }
    } elseif ($kind === 'summary') {
        $series = pay_revenue_series($conn, 12);
        fputcsv($out, ['Month', 'Collected', 'Refunded', 'Net', 'Payments']);
        foreach ($series['rows'] as $row) {
            fputcsv($out, [$row['key'], number_format($row['total'], 2, '.', ''), number_format($row['refunds'], 2, '.', ''),
                number_format($row['net'], 2, '.', ''), $row['count']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Integration', 'Payments', 'Collected']);
        foreach (pay_gateway_breakdown($conn) as $row) {
            fputcsv($out, [$row['label'], $row['payments'], number_format($row['total'], 2, '.', '')]);
        }
    } else {
        fputcsv($out, ['Receipt', 'Workspace', 'Email', 'Invoice', 'Amount', 'Currency', 'Fee', 'Method', 'Gateway', 'Reference', 'Channel', 'Status', 'Source', 'Months', 'Recorded by', 'Paid at', 'Created']);
        foreach (pay_ledger($conn, [], 2000) as $p) {
            fputcsv($out, [
                $p['receipt_number'], $p['company_name'], $p['tenant_email'], $p['invoice_number'],
                $p['amount'], $p['currency'], $p['fee'], $p['payment_method'],
                pay_gateway_label($p['gateway_key']), $p['gateway_reference'], $p['channel'],
                pay_status_label($p['status']), $p['source'], $p['months_extended'],
                $p['recorded_by'] ? $p['recorded_by'] : $p['verified_by'], $p['paid_at'], $p['created_at'],
            ]);
        }
    }
    fclose($out);
    exit();
}

/* ============================================================
   Filters
   ============================================================ */
$range_options = ['30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months', '0' => 'All time'];
$range = isset($_GET['range']) ? preg_replace('/[^0-9]/', '', (string) $_GET['range']) : '30';
if (!isset($range_options[$range])) {
    $range = '30';
}
$range_days = (int) $range;

$status_filter = isset($_GET['status']) ? preg_replace('/[^a-z]/', '', strtolower((string) $_GET['status'])) : '';
if (!in_array($status_filter, ['', 'pending', 'confirmed', 'failed', 'refunded'], true)) {
    $status_filter = '';
}
$gateway_filter = isset($_GET['gateway']) ? preg_replace('/[^a-z_]/', '', strtolower((string) $_GET['gateway'])) : '';
$invoice_status = isset($_GET['invoice_status']) ? preg_replace('/[^a-z]/', '', strtolower((string) $_GET['invoice_status'])) : '';
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$page     = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 15;
$offset   = ($page - 1) * $per_page;

$ledger_filters  = ['status' => $status_filter, 'gateway' => $gateway_filter, 'q' => $query];
$invoice_filters = ['status' => $invoice_status, 'q' => $query];

/* A specific invoice to highlight (after issuing one, or from a link) */
$focus_invoice = isset($_GET['invoice']) ? pay_invoice($conn, (int) $_GET['invoice']) : [];

/* ============================================================
   Data
   ============================================================ */
$summary        = pay_summary($conn, $range_days > 0 ? $range_days : 365);
$series         = pay_revenue_series($conn, 12);
$gateways       = pay_gateways($conn);
$gateway_live   = pay_enabled_gateways($conn);
$default_key    = sa_setting($conn, 'default_gateway', '');
$gateway_mix    = pay_gateway_breakdown($conn, $range_days);
$method_mix     = pay_method_breakdown($conn, $range_days);
$plan_mix       = pay_plan_breakdown($conn);

$pending_payments = pay_ledger($conn, ['status' => 'pending'], 25);
$ledger           = pay_ledger($conn, $ledger_filters, $per_page, $offset);
$ledger_total     = pay_ledger_count($conn, $ledger_filters);
$invoices         = pay_invoices($conn, $invoice_filters, $per_page, $offset);
$invoice_total    = pay_invoice_count($conn, $invoice_filters);
$refunds          = pay_refunds($conn, 20);
$events           = pay_events($conn, 12);

$tenants_dropdown = sa_query(
    $conn,
    "SELECT id, company_name, plan_id, subscription_price, subscription_end_date, subscription_status
       FROM tenants ORDER BY company_name ASC",
    'tenants'
);
$plans_dropdown = sa_query($conn, "SELECT id, plan_name, price FROM subscription_plans WHERE status = 'active' ORDER BY price ASC", 'subscription_plans');

/* Renewals due in the next 30 days — the collection worklist */
$renewals_due = sa_query(
    $conn,
    "SELECT t.id, t.company_name, t.email, t.subscription_status, t.subscription_end_date,
            t.auto_renew, t.subscription_price, p.plan_name,
            (SELECT COUNT(*) FROM payment_invoices i
              WHERE i.tenant_id = t.id AND i.status IN ('open','overdue','processing')) AS open_invoices
       FROM tenants t
       LEFT JOIN subscription_plans p ON p.id = t.plan_id
      WHERE t.subscription_status IN ('active','trial')
        AND t.subscription_end_date IS NOT NULL
        AND t.subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      ORDER BY t.subscription_end_date ASC
      LIMIT 8",
    ['tenants', 'subscription_plans']
);

$total_pages = max(1, (int) ceil(max($ledger_total, $invoice_total) / $per_page));
$money       = function ($value, $decimals = 2) {
    return sa_money($value, $decimals);
};

/* Query string helper so filters survive pagination */
$qs = function (array $overrides = []) use ($range, $status_filter, $gateway_filter, $invoice_status, $query) {
    $params = array_merge([
        'range'          => $range,
        'status'         => $status_filter,
        'gateway'        => $gateway_filter,
        'invoice_status' => $invoice_status,
        'q'              => $query,
    ], $overrides);
    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== null;
    });
    return 'finance.php?' . http_build_query($params);
};

/* ============================================================
   Page meta
   ============================================================ */
$robots       = 'noindex, nofollow';
$pageTitle    = 'Financials';
$pageHeading  = 'Financial centre';
$pageSubtitle = 'Revenue collected, invoices outstanding and every payment in one ledger.';
$activePage   = 'finance';
$BASE         = '../';
$extraCss     = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Financials</span>
        </div>
        <h2>Financial centre</h2>
        <p>
            <?php echo sa_e($money($summary['collected_total'])); ?> confirmed all time &middot;
            <?php echo sa_e(sa_num($ledger_total)); ?> payment<?php echo $ledger_total === 1 ? '' : 's'; ?> on record.
        </p>
    </div>
    <div class="sa-head-actions">
        <a class="sa-btn sa-btn-ghost" href="<?php echo sa_e(pay_public_base_url($conn)); ?>" hidden aria-hidden="true" tabindex="-1">.</a>
        <button type="button" class="sa-btn sa-btn-ghost" data-sa-export="#ledgerTable" data-sa-export-name="optibiz-payments">
            <?php echo sa_icon('download'); ?> View CSV
        </button>
        <a class="sa-btn sa-btn-ghost" href="finance.php?export=ledger">
            <?php echo sa_icon('file-text'); ?> Export ledger
        </a>
        <a class="sa-btn sa-btn-ghost" href="payment_gateways.php">
            <?php echo sa_icon('settings'); ?> Integrations
        </a>
        <button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#issueInvoiceDialog">
            <?php echo sa_icon('plus'); ?> Issue invoice
        </button>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<?php if (!$gateway_live): ?>
<div class="sa-alert sa-alert-warning" data-sa-alert>
    <?php echo sa_icon('alert'); ?>
    <div>
        <strong>No payment integration is live</strong>
        Tenants cannot pay online yet. Configure Paystack, Flutterwave or the bank-transfer profile in
        <a href="payment_gateways.php">Payment integrations</a> — meanwhile you can still issue invoices here
        and record what arrives.
    </div>
</div>
<?php endif; ?>

<!-- ============ FILTER BAR ============ -->
<form method="GET" action="finance.php" class="sa-toolbar sa-mt" role="search" aria-label="Filter financial data">
    <span class="sa-field">
        <label for="f_range">Period</label>
        <select id="f_range" name="range" class="sa-inline-select">
<?php foreach ($range_options as $value => $label): ?>
            <option value="<?php echo sa_e($value); ?>" <?php echo $range === (string) $value ? 'selected' : ''; ?>><?php echo sa_e($label); ?></option>
<?php endforeach; ?>
        </select>
    </span>

    <span class="sa-field">
        <label for="f_status">Payment status</label>
        <select id="f_status" name="status" class="sa-inline-select">
            <option value="">Any status</option>
<?php foreach (['pending', 'confirmed', 'failed', 'refunded'] as $state): ?>
            <option value="<?php echo $state; ?>" <?php echo $status_filter === $state ? 'selected' : ''; ?>><?php echo sa_e(pay_status_label($state)); ?></option>
<?php endforeach; ?>
        </select>
    </span>

    <span class="sa-field">
        <label for="f_gateway">Integration</label>
        <select id="f_gateway" name="gateway" class="sa-inline-select">
            <option value="">All integrations</option>
<?php foreach ($gateways as $key => $gw): ?>
            <option value="<?php echo sa_e($key); ?>" <?php echo $gateway_filter === $key ? 'selected' : ''; ?>><?php echo sa_e($gw['display_name']); ?></option>
<?php endforeach; ?>
        </select>
    </span>

    <span class="sa-field">
        <label for="f_invoice">Invoice status</label>
        <select id="f_invoice" name="invoice_status" class="sa-inline-select">
            <option value="">Any invoice</option>
<?php foreach (['open', 'processing', 'paid', 'overdue', 'cancelled', 'refunded', 'draft'] as $state): ?>
            <option value="<?php echo $state; ?>" <?php echo $invoice_status === $state ? 'selected' : ''; ?>><?php echo sa_e(pay_status_label($state)); ?></option>
<?php endforeach; ?>
        </select>
    </span>

    <span class="sa-field" style="flex:1;min-width:190px">
        <label for="f_q">Search</label>
        <input type="search" id="f_q" name="q" value="<?php echo sa_e($query); ?>"
               placeholder="Receipt, reference, invoice or workspace…">
    </span>

    <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('filter'); ?> Apply</button>
    <a class="sa-btn sa-btn-ghost" href="finance.php">Reset</a>
</form>

<!-- ============ KPIs ============ -->
<div class="sa-grid sa-kpis sa-anim" id="overview">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Collected <?php echo $range_days ? '(last ' . (int) $range_days . ' days)' : '(all time)'; ?></span>
            <span class="sa-kpi-icon"><?php echo sa_icon('dollar'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e($money($range_days ? $summary['collected_window'] : $summary['collected_total'])); ?></div>
        <div class="sa-kpi-note">
            <?php if ($range_days): ?>
                <?php echo sa_delta($summary['collected_delta']); ?> versus the previous <?php echo (int) $range_days; ?> days
            <?php else: ?>
                <?php echo sa_e($money($summary['collected_total'])); ?> confirmed since launch
            <?php endif; ?>
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Recurring revenue</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('trending-up'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e($money($summary['mrr'])); ?></div>
        <div class="sa-kpi-note">
            <?php echo sa_e($money($summary['arr'], 0)); ?> ARR &middot;
            <?php echo sa_e($money($summary['arpu'])); ?> per paying tenant
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Awaiting confirmation</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('clock'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($summary['pending_count'])); ?></div>
        <div class="sa-kpi-note">
            <?php echo sa_e($money($summary['pending_amount'])); ?> captured but not yet applied
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-violet);--kpi-soft:var(--sa-violet-soft);--kpi-line:var(--sa-violet-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Outstanding invoices</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('file-text'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e($money($summary['open_invoice_amount'])); ?></div>
        <div class="sa-kpi-note">
            <?php echo sa_e(sa_num($summary['open_invoices'])); ?> open &middot;
            <?php echo sa_e(sa_num($summary['overdue_count'])); ?> past due date
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-danger);--kpi-soft:var(--sa-danger-soft);--kpi-line:var(--sa-danger-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Refunded</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('refresh'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e($money($summary['refunded_total'])); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_num(count($refunds))); ?> refund/credit record(s)</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-success);--kpi-soft:var(--sa-success-soft);--kpi-line:var(--sa-success-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Invoice conversion</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('check-circle'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(number_format($summary['conversion'], 1)); ?>%</div>
        <div class="sa-kpi-note">
            <?php echo sa_e(sa_num($summary['paid_invoices'])); ?> of
            <?php echo sa_e(sa_num($summary['issued_invoices'])); ?> invoices settled
        </div>
    </article>
</div>

<!-- ============ APPROVALS QUEUE ============ -->
<?php if ($pending_payments): ?>
<section class="sa-card sa-mt" id="approvals">
    <div class="sa-card-head">
        <div>
            <h3>Payments awaiting confirmation</h3>
            <p>
                These payments were captured at the gateway. Confirming applies the plan, extends the expiry date
                and moves the money into revenue.
            </p>
        </div>
        <div class="sa-card-head-actions">
            <span class="sa-badge sa-badge-pending"><?php echo sa_e(sa_num(count($pending_payments))); ?> to review</span>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead scope="col">
                <tr>
                    <th scope="col">Received</th>
                    <th scope="col">Workspace</th>
                    <th scope="col">Invoice</th>
                    <th scope="col">Integration</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col">Reference</th>
                    <th scope="col"><span class="sa-sr-only">Review</span></th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($pending_payments as $p): ?>
                <tr>
                    <td><?php echo sa_e(pay_days_ago($p['created_at'])); ?></td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($p['company_name'])); ?></span>
                            <span class="sa-cell-text">
                                <strong><?php echo sa_e($p['company_name']); ?></strong>
                                <span><?php echo sa_e($p['tenant_email']); ?></span>
                            </span>
                        </div>
                    </td>
                    <td>
<?php if (!empty($p['invoice_id'])): ?>
                        <a class="sa-mono" href="invoice_view.php?id=<?php echo (int) $p['invoice_id']; ?>"><?php echo sa_e($p['invoice_number']); ?></a>
<?php else: ?>
                        <span class="sa-muted">—</span>
<?php endif; ?>
                    </td>
                    <td>
                        <?php echo pay_gateway_mark($p['gateway_key'], 26); ?>
                        <?php echo sa_e(pay_gateway_label($p['gateway_key'])); ?>
<?php if (!empty($p['channel'])): ?>
                        <span class="sa-hint"><?php echo sa_e(pay_channel_label($p['channel'])); ?></span>
<?php endif; ?>
                    </td>
                    <td class="num">
                        <strong><?php echo sa_e(pay_amount($p['amount'], $p['currency'])); ?></strong>
                        <span class="sa-hint">Receipt <?php echo sa_e($p['receipt_number']); ?></span>
                    </td>
                    <td><span class="sa-mono"><?php echo sa_e($p['gateway_reference']); ?></span></td>
                    <td>
                        <div class="sa-row-actions">
                            <form method="POST" action="finance.php" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="confirm_payment">
                                <input type="hidden" name="payment_id" value="<?php echo (int) $p['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-primary"
                                        data-sa-confirm="Confirm this payment and activate the subscription?">
                                    <?php echo sa_icon('check'); ?> Confirm
                                </button>
                            </form>
                            <form method="POST" action="finance.php" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="reject_payment">
                                <input type="hidden" name="payment_id" value="<?php echo (int) $p['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost"
                                        data-sa-confirm="Mark this payment as not collected? The invoice goes back to awaiting payment.">
                                    <?php echo sa_icon('x'); ?> Reject
                                </button>
                            </form>
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="finance.php?q=<?php echo urlencode($p['receipt_number']); ?>#ledger"
                               title="Open this payment in the ledger">
                                <?php echo sa_icon('search'); ?>
                            </a>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="sa-card-foot">
        <span>Money is only added to revenue once you confirm it.</span>
        <span><a href="finance.php?status=pending#ledger">See all awaiting payments</a></span>
    </div>
</section>
<?php endif; ?>

<!-- ============ CHARTS ============ -->
<div class="sa-grid sa-split-2-1 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Revenue collected</h3>
                <p>Confirmed payments per month, with refunds subtracted</p>
            </div>
            <div class="sa-card-head-actions">
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-lime);display:inline-block"></i> Collected</span>
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-info);display:inline-block"></i> Refunded</span>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_line_chart($series['labels'], [
                ['name' => 'Collected', 'values' => $series['values'], 'color' => 'lime', 'format' => 'money'],
                ['name' => 'Refunded', 'values' => $series['refunds'], 'color' => 'info', 'format' => 'money', 'dashed' => true],
            ], ['height' => 270, 'format' => 'money']); ?>
        </div>
        <div class="sa-card-foot">
            <span><?php echo sa_e($money(array_sum($series['values']))); ?> collected in the last 12 months</span>
            <span><?php echo sa_e($money(array_sum($series['refunds']))); ?> refunded</span>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Where the money comes from</h3>
                <p>Confirmed payments by integration<?php echo $range_days ? ' — last ' . (int) $range_days . ' days' : ''; ?></p>
            </div>
        </div>
        <div class="sa-card-pad">
<?php
$mix_segments = [];
$palette = ['var(--sa-lime)', 'var(--sa-info)', 'var(--sa-violet)', 'var(--sa-warning)'];
foreach ($gateway_mix as $i => $row) {
    $mix_segments[] = [
        'label'   => $row['label'],
        'value'   => round($row['total'], 2),
        'display' => $money($row['total'], 0),
        'color'   => isset($palette[$i]) ? $palette[$i] : 'var(--sa-accent)',
    ];
}
echo sa_donut($mix_segments, ['value' => $money(array_sum(array_column($gateway_mix, 'total')), 0), 'label' => 'Collected'], 160);
?>
        </div>
    </section>
</div>

<div class="sa-grid sa-split-1-2 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Payment channels</h3>
                <p>Cards, mobile money and transfers</p>
            </div>
        </div>
        <div class="sa-card-pad">
<?php
$method_bars = [];
foreach ($method_mix as $row) {
    $method_bars[] = [
        'label' => $row['label'],
        'value' => round($row['total'], 2),
        'meta'  => $money($row['total'], 0) . ' · ' . sa_num($row['payments']) . ' payment' . ($row['payments'] === 1 ? '' : 's'),
    ];
}
echo $method_bars
    ? sa_bar_list($method_bars)
    : '<div class="sa-empty">' . sa_icon('card') . '<strong>No payments in this period</strong><p>Record a payment or wait for a tenant checkout.</p></div>';
?>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Renewals to collect</h3>
                <p>Workspaces whose subscription ends within 30 days</p>
            </div>
            <div class="sa-card-head-actions">
                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="subscriptions.php?view=due"><?php echo sa_icon('external'); ?> Subscriptions</a>
            </div>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead scope="col">
                    <tr>
                        <th scope="col">Workspace</th>
                        <th scope="col">Plan</th>
                        <th scope="col">Ends</th>
                        <th scope="col" class="num">Price</th>
                        <th scope="col"><span class="sa-sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody>
<?php if (!$renewals_due): ?>
                    <tr data-static>
                        <td colspan="5">
                            <div class="sa-empty">
                                <?php echo sa_icon('check-circle'); ?>
                                <strong>Nothing renews in the next 30 days</strong>
                                <p>Every active workspace is paid up beyond that.</p>
                            </div>
                        </td>
                    </tr>
<?php else: ?>
<?php foreach ($renewals_due as $r): ?>
                    <tr>
                        <td>
                            <div class="sa-cell-main">
                                <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($r['company_name'])); ?></span>
                                <span class="sa-cell-text">
                                    <strong><?php echo sa_e($r['company_name']); ?></strong>
                                    <span><?php echo sa_e($r['email']); ?></span>
                                </span>
                            </div>
                        </td>
                        <td><span class="sa-badge sa-badge-plan"><?php echo sa_e($r['plan_name'] ? $r['plan_name'] : 'No plan'); ?></span></td>
                        <td>
                            <?php echo sa_e(sa_date($r['subscription_end_date'])); ?>
                            <span class="sa-hint"><?php echo (int) sa_days_until($r['subscription_end_date']); ?> day(s) left</span>
                        </td>
                        <td class="num"><?php echo sa_e($money($r['subscription_price'])); ?></td>
                        <td>
                            <div class="sa-row-actions">
<?php if ((int) $r['open_invoices'] > 0): ?>
                                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="finance.php?invoice_status=open#invoices">
                                    <?php echo sa_icon('file-text'); ?> Invoice open
                                </a>
<?php else: ?>
                                <form method="POST" action="finance.php" style="display:inline">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="issue_invoice">
                                    <input type="hidden" name="tenant_id" value="<?php echo (int) $r['id']; ?>">
                                    <input type="hidden" name="months" value="12">
                                    <input type="hidden" name="purpose" value="renewal">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="Issue a 12 month renewal invoice">
                                        <?php echo sa_icon('plus'); ?> Invoice
                                    </button>
                                </form>
<?php endif; ?>
                                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenant_details.php?id=<?php echo (int) $r['id']; ?>" title="Open workspace">
                                    <?php echo sa_icon('eye'); ?>
                                </a>
                            </div>
                        </td>
                    </tr>
<?php endforeach; ?>
<?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
</div>

<!-- ============ INVOICES ============ -->
<section class="sa-card sa-mt" id="invoices">
    <div class="sa-card-head">
        <div>
            <h3>Invoices</h3>
            <p>
                <?php echo sa_e(sa_num($invoice_total)); ?> invoice<?php echo $invoice_total === 1 ? '' : 's'; ?>
                <?php echo $invoice_status !== '' ? 'with status “' . sa_e(pay_status_label($invoice_status)) . '”' : ''; ?>
                <?php echo $query !== '' ? ' matching “' . sa_e($query) . '”' : ''; ?>.
            </p>
        </div>
        <div class="sa-card-head-actions">
            <a class="sa-btn sa-btn-ghost sa-btn-sm" href="finance.php?export=invoices"><?php echo sa_icon('download'); ?> Export invoices</a>
            <button type="button" class="sa-btn sa-btn-sm sa-btn-primary" data-sa-open-dialog="#issueInvoiceDialog">
                <?php echo sa_icon('plus'); ?> Issue invoice
            </button>
        </div>
    </div>

<?php if ($focus_invoice): ?>
    <div class="sa-card-pad">
        <div class="sa-alert sa-alert-success" data-sa-alert>
            <?php echo sa_icon('check-circle'); ?>
            <div>
                <strong>Invoice <?php echo sa_e($focus_invoice['invoice_number']); ?> is ready</strong>
                <?php echo sa_e($focus_invoice['company_name']); ?> · <?php echo sa_e(pay_amount($focus_invoice['total'], $focus_invoice['currency'])); ?>
                · due <?php echo sa_e(sa_date($focus_invoice['due_date'])); ?>.
                <a href="invoice_view.php?id=<?php echo (int) $focus_invoice['id']; ?>" target="_blank" rel="noopener">Open the printable invoice</a>
                or
                <form method="POST" action="finance.php" style="display:inline">
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="action" value="email_invoice">
                    <input type="hidden" name="invoice_id" value="<?php echo (int) $focus_invoice['id']; ?>">
                    <button type="submit" class="sa-link-btn">email it to the workspace</button>
                </form>.
            </div>
        </div>
    </div>
<?php endif; ?>

    <div class="sa-table-wrap">
        <table class="sa-table" id="invoicesTable" data-sa-sortable-table>
            <thead scope="col">
                <tr>
                    <th scope="col" data-sa-sort="0" aria-sort="none">Invoice</th>
                    <th scope="col" data-sa-sort="1" aria-sort="none">Workspace</th>
                    <th scope="col" data-sa-sort="2" aria-sort="none">For</th>
                    <th scope="col" class="num" data-sa-sort="3" data-type="num" aria-sort="none">Total</th>
                    <th scope="col" data-sa-sort="4" aria-sort="none">Status</th>
                    <th scope="col" data-sa-sort="5" data-type="date" aria-sort="none">Due</th>
                    <th scope="col" data-no-export><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$invoices): ?>
                <tr data-static>
                    <td colspan="7">
                        <div class="sa-empty">
                            <?php echo sa_icon('file-text'); ?>
                            <strong>No invoices in this view</strong>
                            <p>Issue one with the button above, or clear the filters.</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($invoices as $inv): ?>
                <tr>
                    <td>
                        <a class="sa-mono" href="invoice_view.php?id=<?php echo (int) $inv['id']; ?>"><?php echo sa_e($inv['invoice_number']); ?></a>
                        <span class="sa-hint"><?php echo sa_e(sa_date($inv['created_at'])); ?></span>
                    </td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($inv['company_name'])); ?></span>
                            <span class="sa-cell-text">
                                <strong><?php echo sa_e($inv['company_name']); ?></strong>
                                <span><?php echo sa_e($inv['tenant_email']); ?></span>
                            </span>
                        </div>
                    </td>
                    <td>
                        <?php echo sa_e($inv['subject']); ?>
                        <span class="sa-hint">
                            <?php echo sa_e(ucfirst($inv['purpose'])); ?> &middot;
                            <?php echo (int) $inv['months']; ?> month<?php echo (int) $inv['months'] === 1 ? '' : 's'; ?>
                            <?php if ($inv['gateway_key']): ?> &middot; <?php echo sa_e(pay_gateway_label($inv['gateway_key'])); ?><?php endif; ?>
                        </span>
                    </td>
                    <td class="num" data-sort-value="<?php echo sa_e($inv['total']); ?>" data-export-value="<?php echo sa_e($inv['total']); ?>">
                        <strong><?php echo sa_e(pay_amount($inv['total'], $inv['currency'])); ?></strong>
<?php if ((float) $inv['discount'] > 0): ?>
                        <span class="sa-hint">after <?php echo sa_e($money($inv['discount'])); ?> discount</span>
<?php endif; ?>
                    </td>
                    <td data-sort-value="<?php echo sa_e($inv['status']); ?>">
                        <?php echo pay_badge($inv['status']); ?>
<?php if ((int) $inv['pending_payments'] > 0): ?>
                        <span class="sa-hint">Payment to confirm</span>
<?php endif; ?>
                    </td>
                    <td data-sort-value="<?php echo sa_e($inv['due_date'] ?: ''); ?>">
                        <?php echo sa_e(sa_date($inv['due_date'])); ?>
                        <?php echo pay_due_label($inv); ?>
                    </td>
                    <td data-no-export>
                        <div class="sa-row-actions">
<?php if (in_array($inv['status'], ['open', 'overdue', 'processing'], true)): ?>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost"
                                    data-sa-open-dialog="#markPaidDialog"
                                    data-invoice-id="<?php echo (int) $inv['id']; ?>"
                                    data-invoice-number="<?php echo sa_e($inv['invoice_number']); ?>"
                                    data-invoice-total="<?php echo sa_e($inv['total']); ?>"
                                    data-invoice-months="<?php echo (int) $inv['months']; ?>"
                                    title="Record money received outside the gateway">
                                <?php echo sa_icon('dollar'); ?> Mark paid
                            </button>
<?php endif; ?>
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="invoice_view.php?id=<?php echo (int) $inv['id']; ?>" title="Printable invoice">
                                <?php echo sa_icon('file-text'); ?>
                            </a>
<?php if (!in_array($inv['status'], ['paid', 'cancelled'], true)): ?>
                            <form method="POST" action="finance.php" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="cancel_invoice">
                                <input type="hidden" name="invoice_id" value="<?php echo (int) $inv['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost"
                                        data-sa-confirm="Cancel invoice <?php echo sa_e($inv['invoice_number']); ?>?"
                                        title="Cancel this invoice"
                                        aria-label="Cancel invoice <?php echo sa_e($inv['invoice_number']); ?>">
                                    <?php echo sa_icon('x'); ?>
                                </button>
                            </form>
<?php endif; ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>

<?php if ($invoice_total > count($invoices)): ?>
    <div class="sa-card-foot">
        <span>Showing <?php echo sa_e(sa_num(count($invoices))); ?> of <?php echo sa_e(sa_num($invoice_total)); ?></span>
        <span><a href="<?php echo sa_e($qs(['page' => $page + 1])); ?>#invoices">Load more in the ledger view</a></span>
    </div>
<?php else: ?>
    <div class="sa-card-foot">
        <span><?php echo sa_e(sa_num($invoice_total)); ?> invoice<?php echo $invoice_total === 1 ? '' : 's'; ?></span>
        <span>Print any invoice from the actions column</span>
    </div>
<?php endif; ?>
</section>

<!-- ============ LEDGER ============ -->
<section class="sa-card sa-mt" id="ledger">
    <div class="sa-card-head">
        <div>
            <h3>Payment ledger</h3>
            <p>
                Every payment received — gateway or hand-recorded —
                <?php echo sa_e(sa_num($ledger_total)); ?> record<?php echo $ledger_total === 1 ? '' : 's'; ?>
                <?php echo $status_filter !== '' ? 'with status “' . sa_e(pay_status_label($status_filter)) . '”' : ''; ?>.
            </p>
        </div>
        <div class="sa-card-head-actions">
            <a class="sa-btn sa-btn-ghost sa-btn-sm" href="finance.php?export=ledger"><?php echo sa_icon('download'); ?> Export ledger</a>
            <button type="button" class="sa-btn sa-btn-sm sa-btn-primary" data-sa-open-dialog="#recordPaymentDialog">
                <?php echo sa_icon('plus'); ?> Record payment
            </button>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table" id="ledgerTable">
            <thead scope="col">
                <tr>
                    <th scope="col">Receipt</th>
                    <th scope="col">Workspace</th>
                    <th scope="col">Method</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col" class="num">Fee</th>
                    <th scope="col">Status</th>
                    <th scope="col">Reference</th>
                    <th scope="col">When</th>
                    <th scope="col"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$ledger): ?>
                <tr data-static>
                    <td colspan="9">
                        <div class="sa-empty">
                            <?php echo sa_icon('card'); ?>
                            <strong>No payments in this view</strong>
                            <p>Clear the filters, or record a payment received offline.</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($ledger as $p): ?>
                <tr>
                    <td>
                        <span class="sa-mono"><?php echo sa_e($p['receipt_number']); ?></span>
<?php if (!empty($p['invoice_number'])): ?>
                        <span class="sa-hint"><?php echo sa_e($p['invoice_number']); ?></span>
<?php endif; ?>
                    </td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($p['company_name'])); ?></span>
                            <span class="sa-cell-text">
                                <strong><?php echo sa_e($p['company_name']); ?></strong>
                                <span><?php echo sa_e($p['tenant_email']); ?></span>
                            </span>
                        </div>
                    </td>
                    <td>
<?php if (!empty($p['gateway_key'])): ?>
                        <span style="display:inline-flex;align-items:center;gap:6px">
                            <?php echo pay_gateway_mark($p['gateway_key'], 24); ?>
                            <?php echo sa_e(pay_gateway_label($p['gateway_key'])); ?>
                        </span>
<?php else: ?>
                        <?php echo sa_e($p['payment_method']); ?>
<?php endif; ?>
<?php if (!empty($p['channel'])): ?>
                        <span class="sa-hint"><?php echo sa_e(pay_channel_label($p['channel'])); ?></span>
<?php endif; ?>
                    </td>
                    <td class="num">
                        <strong><?php echo sa_e(pay_amount($p['amount'], $p['currency'])); ?></strong>
                        <?php if ((int) $p['months_extended'] > 0): ?>
                        <span class="sa-hint">+<?php echo (int) $p['months_extended']; ?> month<?php echo (int) $p['months_extended'] === 1 ? '' : 's'; ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?php echo (float) $p['fee'] > 0 ? sa_e(pay_amount($p['fee'], $p['currency'])) : '—'; ?></td>
                    <td><?php echo pay_badge($p['status']); ?></td>
                    <td><span class="sa-mono"><?php echo sa_e($p['gateway_reference'] !== '' ? $p['gateway_reference'] : ($p['transaction_ref'] ? $p['transaction_ref'] : '—')); ?></span></td>
                    <td>
                        <?php echo sa_e(sa_date($p['created_at'])); ?>
                        <span class="sa-hint"><?php echo sa_e(pay_days_ago($p['created_at'])); ?></span>
                    </td>
                    <td>
                        <div class="sa-row-actions">
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="invoice_receipt.php?id=<?php echo (int) $p['id']; ?>" title="Printable receipt">
                                <?php echo sa_icon('file-text'); ?>
                            </a>
<?php if ($p['status'] === 'confirmed'): ?>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost"
                                    data-sa-open-dialog="#refundDialog"
                                    data-payment-id="<?php echo (int) $p['id']; ?>"
                                    data-payment-receipt="<?php echo sa_e($p['receipt_number']); ?>"
                                    data-payment-amount="<?php echo sa_e($p['amount']); ?>"
                                    title="Refund or credit this payment">
                                <?php echo sa_icon('refresh'); ?>
                            </button>
<?php endif; ?>
<?php if ($p['status'] === 'pending'): ?>
                            <form method="POST" action="finance.php" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="confirm_payment">
                                <input type="hidden" name="payment_id" value="<?php echo (int) $p['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-primary"
                                        title="Confirm this payment and apply it to the invoice"
                                        aria-label="Confirm payment <?php echo sa_e($p['receipt_number']); ?>">
                                    <?php echo sa_icon('check'); ?>
                                </button>
                            </form>
<?php endif; ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="sa-card-foot">
        <span>
            Page <?php echo (int) $page; ?> of <?php echo (int) $total_pages; ?> &middot;
            <?php echo sa_e(sa_num($ledger_total)); ?> record<?php echo $ledger_total === 1 ? '' : 's'; ?>
        </span>
        <span class="sa-row-actions">
<?php if ($page > 1): ?>
            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="<?php echo sa_e($qs(['page' => $page - 1])); ?>#ledger">
                <?php echo sa_icon('chevron-left'); ?> Previous
            </a>
<?php endif; ?>
<?php if ($page < $total_pages): ?>
            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="<?php echo sa_e($qs(['page' => $page + 1])); ?>#ledger">
                Next <?php echo sa_icon('chevron-right'); ?>
            </a>
<?php endif; ?>
        </span>
    </div>
</section>

<!-- ============ REFUNDS & ACTIVITY ============ -->
<div class="sa-grid sa-split-2-1 sa-mt">
    <section class="sa-card" id="refunds">
        <div class="sa-card-head">
            <div>
                <h3>Refunds &amp; credit notes</h3>
                <p>Money returned to tenants, netted out of the revenue figures</p>
            </div>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead scope="col">
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Workspace</th>
                        <th scope="col">Kind</th>
                        <th scope="col" class="num">Amount</th>
                        <th scope="col">Reason</th>
                        <th scope="col">By</th>
                    </tr>
                </thead>
                <tbody>
<?php if (!$refunds): ?>
                    <tr data-static>
                        <td colspan="6">
                            <div class="sa-empty">
                                <?php echo sa_icon('check-circle'); ?>
                                <strong>No refunds recorded</strong>
                                <p>Nothing has been paid back to a tenant.</p>
                            </div>
                        </td>
                    </tr>
<?php else: ?>
<?php foreach ($refunds as $r): ?>
                    <tr>
                        <td><?php echo sa_e(sa_date($r['created_at'])); ?></td>
                        <td><?php echo sa_e($r['company_name']); ?></td>
                        <td><span class="sa-badge <?php echo $r['kind'] === 'credit' ? 'sa-badge-info' : 'sa-badge-trial'; ?>"><?php echo sa_e(ucfirst($r['kind'])); ?></span></td>
                        <td class="num"><?php echo sa_e(pay_amount($r['amount'], $r['currency'])); ?></td>
                        <td><?php echo sa_e(pay_trim($r['reason'] !== '' ? $r['reason'] : '—', 60)); ?></td>
                        <td><?php echo sa_e($r['processed_by'] !== '' ? $r['processed_by'] : '—'); ?></td>
                    </tr>
<?php endforeach; ?>
<?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="sa-card" id="activity">
        <div class="sa-card-head">
            <div>
                <h3>Activity log</h3>
                <p>Webhooks, callbacks and confirmations</p>
            </div>
        </div>
        <div class="sa-card-pad">
<?php if (!$events): ?>
            <div class="sa-empty">
                <?php echo sa_icon('activity'); ?>
                <strong>No activity yet</strong>
                <p>Gateway callbacks land here as tenants pay.</p>
            </div>
<?php else: ?>
            <div class="sa-list">
<?php foreach ($events as $event): ?>
                <div class="sa-list-item">
                    <span class="sa-list-icon <?php echo !empty($event['signature_valid']) ? 'is-success' : 'is-warning'; ?>">
                        <?php echo sa_icon(!empty($event['signature_valid']) ? 'check' : 'alert'); ?>
                    </span>
                    <span class="sa-list-body">
                        <strong><?php echo sa_e($event['event_type']); ?></strong>
                        <span><?php echo sa_e(pay_gateway_label($event['gateway_key'])); ?>
                            <?php if ($event['reference']): ?>· <span class="sa-mono"><?php echo sa_e($event['reference']); ?></span><?php endif; ?>
                        </span>
                    </span>
                    <span class="sa-list-side">
                        <strong><?php echo sa_e(sa_time_ago($event['created_at'])); ?></strong>
                    </span>
                </div>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </div>
        <div class="sa-card-foot">
            <span>Last <?php echo sa_e(sa_num(count($events))); ?> event(s)</span>
            <a href="payment_gateways.php#gw-paystack">Integration setup</a>
        </div>
    </section>
</div>

<!-- ============================================================
     DIALOGS
     ============================================================ -->

<dialog class="sa-dialog" id="issueInvoiceDialog" aria-labelledby="issueInvoiceTitle">
    <form method="POST" action="finance.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="issue_invoice">
        <div class="sa-dialog-head">
            <div>
                <h3 id="issueInvoiceTitle">Issue an invoice</h3>
                <p>Raise a bill for a workspace. They pay it online from their subscription page, or you settle it here.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="i_tenant">Workspace *</label>
                    <select id="i_tenant" name="tenant_id" required>
                        <option value="">Choose a workspace…</option>
<?php foreach ($tenants_dropdown as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>">
                            <?php echo sa_e($t['company_name']); ?> — <?php echo sa_e($money($t['subscription_price'])); ?>/mo,
                            ends <?php echo sa_e(sa_date($t['subscription_end_date'], 'M j, Y', 'no date')); ?>
                        </option>
<?php endforeach; ?>
                    </select>
                </div>

                <div class="sa-field">
                    <label for="i_plan">Plan</label>
                    <select id="i_plan" name="plan_id">
                        <option value="0">Keep the workspace's current plan</option>
<?php foreach ($plans_dropdown as $plan): ?>
                        <option value="<?php echo (int) $plan['id']; ?>">
                            <?php echo sa_e($plan['plan_name']); ?> — <?php echo sa_e($money($plan['price'])); ?>/mo
                        </option>
<?php endforeach; ?>
                    </select>
                </div>

                <div class="sa-field">
                    <label for="i_months">Months covered *</label>
                    <input type="number" id="i_months" name="months" min="1" max="36" value="12" required>
                    <span class="sa-hint">Confirmation extends the subscription by this many months.</span>
                </div>

                <div class="sa-field">
                    <label for="i_amount">Amount override</label>
                    <input type="number" id="i_amount" name="amount" step="0.01" min="0" placeholder="Leave blank to use the plan price">
                    <span class="sa-hint">Blank = plan price × months.</span>
                </div>

                <div class="sa-field">
                    <label for="i_due">Due date</label>
                    <input type="date" id="i_due" name="due_date" value="<?php echo sa_e(date('Y-m-d', strtotime('+14 days'))); ?>">
                </div>

                <div class="sa-field">
                    <label for="i_purpose">Reason</label>
                    <select id="i_purpose" name="purpose">
                        <option value="renewal">Renewal</option>
                        <option value="upgrade">Upgrade</option>
                        <option value="new">New subscription</option>
                        <option value="addon">Add-on</option>
                    </select>
                </div>

                <div class="sa-field" style="grid-column:1/-1">
                    <label for="i_notes">Notes for the tenant</label>
                    <textarea id="i_notes" name="notes" rows="2" style="width:100%" placeholder="Optional"></textarea>
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('file-text'); ?> Issue invoice</button>
        </div>
    </form>
</dialog>

<dialog class="sa-dialog" id="recordPaymentDialog" aria-labelledby="recordPaymentTitle">
    <form method="POST" action="finance.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="record_offline">
        <div class="sa-dialog-head">
            <div>
                <h3 id="recordPaymentTitle">Record a payment</h3>
                <p>Log money that arrived by bank transfer, mobile money or cash. It counts as revenue immediately.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="p_tenant">Workspace *</label>
                    <select id="p_tenant" name="tenant_id" required>
                        <option value="">Choose a workspace…</option>
<?php foreach ($tenants_dropdown as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>"><?php echo sa_e($t['company_name']); ?></option>
<?php endforeach; ?>
                    </select>
                </div>

                <div class="sa-field">
                    <label for="p_amount">Amount received *</label>
                    <input type="number" id="p_amount" name="amount" step="0.01" min="0.01" required placeholder="0.00">
                </div>

                <div class="sa-field">
                    <label for="p_method">Method *</label>
                    <select id="p_method" name="payment_method" required>
                        <option value="Bank transfer">Bank transfer</option>
                        <option value="Mobile money">Mobile money</option>
                        <option value="Cash">Cash</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Card (POS)">Card (POS)</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="sa-field">
                    <label for="p_reference">Reference</label>
                    <input type="text" id="p_reference" name="reference" maxlength="100" placeholder="Bank slip or transaction ID">
                </div>

                <div class="sa-field">
                    <label for="p_months">Extend subscription by (months)</label>
                    <input type="number" id="p_months" name="months_extended" min="0" max="60" value="12">
                    <span class="sa-hint">Use 0 when the money is for an add-on or a top-up.</span>
                </div>

                <div class="sa-field" style="grid-column:1/-1">
                    <label for="p_notes">Notes</label>
                    <textarea id="p_notes" name="notes" rows="2" style="width:100%" placeholder="Optional"></textarea>
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('check'); ?> Record payment</button>
        </div>
    </form>
</dialog>

<dialog class="sa-dialog" id="markPaidDialog" aria-labelledby="markPaidTitle">
    <form method="POST" action="finance.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="invoice_mark_paid">
        <input type="hidden" name="invoice_id" id="mp_invoice_id" value="">
        <div class="sa-dialog-head">
            <div>
                <h3 id="markPaidTitle">Mark invoice paid</h3>
                <p id="mp_subtitle">Settle this invoice and activate the subscription.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field">
                    <label for="mp_amount">Amount received</label>
                    <input type="number" id="mp_amount" name="amount" step="0.01" min="0" placeholder="Invoice total">
                    <span class="sa-hint">Leave blank to settle the full invoice total.</span>
                </div>
                <div class="sa-field">
                    <label for="mp_method">Method</label>
                    <select id="mp_method" name="payment_method">
                        <option value="Bank transfer">Bank transfer</option>
                        <option value="Mobile money">Mobile money</option>
                        <option value="Cash">Cash</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="mp_reference">Reference</label>
                    <input type="text" id="mp_reference" name="reference" maxlength="100" placeholder="Slip or transaction ID">
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('check-circle'); ?> Mark paid &amp; activate</button>
        </div>
    </form>
</dialog>

<dialog class="sa-dialog" id="refundDialog" aria-labelledby="refundTitle">
    <form method="POST" action="finance.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="refund_payment">
        <input type="hidden" name="payment_id" id="rf_payment_id" value="">
        <div class="sa-dialog-head">
            <div>
                <h3 id="refundTitle">Refund or credit</h3>
                <p id="rf_subtitle">Money leaves the account, so this is recorded as processed immediately.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field">
                    <label for="rf_kind">Type</label>
                    <select id="rf_kind" name="kind">
                        <option value="refund">Refund to the tenant</option>
                        <option value="credit">Credit note on their account</option>
                    </select>
                </div>
                <div class="sa-field">
                    <label for="rf_amount">Amount</label>
                    <input type="number" id="rf_amount" name="amount" step="0.01" min="0.01" required placeholder="0.00">
                </div>
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="rf_reason">Reason</label>
                    <textarea id="rf_reason" name="reason" rows="2" style="width:100%" placeholder="Why is this being refunded?"></textarea>
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-danger"
                    data-sa-confirm="Record this refund? The amount is removed from revenue.">
                <?php echo sa_icon('refresh'); ?> Record refund
            </button>
        </div>
    </form>
</dialog>

<script>
/* The dialogs are shared by every row: stash ids/amounts from the trigger
   button onto the form fields when the dialog opens. */
(function () {
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-sa-open-dialog]');
        if (!trigger) return;
        var target = trigger.getAttribute('data-sa-open-dialog');

        if (target === '#markPaidDialog') {
            var id = trigger.getAttribute('data-invoice-id') || '';
            document.getElementById('mp_invoice_id').value = id;
            var total = trigger.getAttribute('data-invoice-total') || '';
            document.getElementById('mp_amount').value = total;
            document.getElementById('mp_subtitle').textContent =
                'Invoice ' + (trigger.getAttribute('data-invoice-number') || '') + ' — ' +
                (trigger.getAttribute('data-invoice-months') || '0') + ' month(s) will be applied.';
        }

        if (target === '#refundDialog') {
            document.getElementById('rf_payment_id').value = trigger.getAttribute('data-payment-id') || '';
            document.getElementById('rf_amount').value = trigger.getAttribute('data-payment-amount') || '';
            document.getElementById('rf_subtitle').textContent =
                'Receipt ' + (trigger.getAttribute('data-payment-receipt') || '') +
                ' — refunds are netted out of reported revenue.';
        }

        if (target === '#issueInvoiceDialog') {
            var tenant = trigger.getAttribute('data-tenant-id');
            if (tenant) document.getElementById('i_tenant').value = tenant;
        }
    });
})();
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
