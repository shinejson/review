<?php
/**
 * ============================================================
 *  Super Admin — Subscriptions
 * ============================================================
 *  Billing overview: MRR/ARR, renewals due, auto-renew switches
 *  and plan changes for every tenant.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';

requireSuperAdminLogin();
require_sa_permission('subscriptions');

admin_ensure_schema($conn);

/* ---------- POST handlers ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('subscriptions.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = (int) ($_POST['tenant_id'] ?? 0);

    /* ---- plan change requests filed from a workspace ---- */
    if ($action === 'approve_request' || $action === 'decline_request') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $request = admin_row(
            $conn,
            "SELECT * FROM subscription_requests WHERE id = " . $request_id . " AND status = 'pending' LIMIT 1"
        );
        if (!$request) {
            sa_flash('error', 'That request has already been handled.');
            redirect('subscriptions.php');
        }

        if ($action === 'decline_request') {
            $stmt = $conn->prepare("UPDATE subscription_requests SET status = 'declined', resolved_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Plan change declined.');
            redirect('subscriptions.php');
        }

        $plan = admin_row(
            $conn,
            "SELECT * FROM subscription_plans WHERE id = " . (int) $request['requested_plan_id'] . " LIMIT 1"
        );
        if (!$plan) {
            sa_flash('error', 'The requested plan no longer exists.');
            redirect('subscriptions.php');
        }

        $stmt = $conn->prepare(
            "UPDATE tenants
                SET plan_id = ?, subscription_price = ?, subscription_status = 'active'
              WHERE id = ?"
        );
        $plan_id = (int) $plan['id'];
        $price   = (float) $plan['price'];
        $tid     = (int) $request['tenant_id'];
        $stmt->bind_param('idi', $plan_id, $price, $tid);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE subscription_requests SET status = 'approved', resolved_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $stmt->close();

        sa_flash('success', 'Tenant moved to the ' . $plan['plan_name'] . ' plan.');
        redirect('subscriptions.php');
    }

    if ($action === 'auto_renew' && $id) {
        $on = !empty($_POST['auto_renew']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE tenants SET auto_renew = ? WHERE id = ?");
        $stmt->bind_param("ii", $on, $id);
        $stmt->execute();
        $stmt->close();
        sa_flash('success', $on ? 'Auto-renew switched on.' : 'Auto-renew switched off.');
        redirect('subscriptions.php');
    }

    if ($action === 'update_status' && $id) {
        $status = in_array($_POST['status'] ?? '', ['trial', 'active', 'inactive', 'cancelled'], true) ? $_POST['status'] : '';
        if ($status) {
            $stmt = $conn->prepare("UPDATE tenants SET subscription_status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Subscription marked ' . $status . '.');
        }
        redirect('subscriptions.php');
    }

    if ($action === 'extend' && $id) {
        $months = max(1, min(60, (int) ($_POST['extend_months'] ?? 12)));
        $conn->query(
            "UPDATE tenants
                SET subscription_end_date = DATE_ADD(
                        COALESCE(subscription_end_date, CURDATE()),
                        INTERVAL " . $months . " MONTH),
                    subscription_status = 'active'
              WHERE id = " . $id
        );
        sa_flash('success', 'Subscription extended by ' . $months . ' month' . ($months === 1 ? '' : 's') . '.');
        redirect('subscriptions.php');
    }

    if ($action === 'record_payment') {
        $tenant_id = (int) ($_POST['tenant_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $payment_method = sanitize($_POST['payment_method'] ?? 'Bank Wire');
        $transaction_ref = sanitize($_POST['transaction_ref'] ?? '');
        $months_extended = max(0, min(60, (int) ($_POST['months_extended'] ?? 0)));
        $notes = sanitize($_POST['notes'] ?? '');
        $recorded_by = $_SESSION['super_admin_username'] ?? 'Super Admin';

        if (!$tenant_id || $amount <= 0) {
            sa_flash('error', 'Please select a tenant and enter a valid payment amount.');
        } else {
            sa_ensure_payments_schema($conn);
            $seq = (int) sa_scalar($conn, "SELECT COUNT(*) FROM subscription_payments", 0) + 1;
            $receipt_number = 'INV-' . date('Y') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $stmt = $conn->prepare(
                "INSERT INTO subscription_payments 
                    (tenant_id, receipt_number, amount, payment_method, transaction_ref, months_extended, notes, recorded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->bind_param("isdssiss", $tenant_id, $receipt_number, $amount, $payment_method, $transaction_ref, $months_extended, $notes, $recorded_by);
            $stmt->execute();
            $payment_id = $conn->insert_id;
            $stmt->close();

            if ($months_extended > 0) {
                $conn->query(
                    "UPDATE tenants
                        SET subscription_end_date = DATE_ADD(COALESCE(subscription_end_date, CURDATE()), INTERVAL " . $months_extended . " MONTH),
                            subscription_status = 'active'
                      WHERE id = " . $tenant_id
                );
            }

            sa_flash('success', 'Payment of ' . sa_money($amount) . ' recorded successfully. Receipt #' . $receipt_number . ' generated.');
        }
        redirect('subscriptions.php');
    }
}

/* ---------- 1-Click Server CSV Export ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=subscriptions-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Company Name', 'Plan', 'Monthly Price', 'Status', 'Auto Renew', 'Profiles Used', 'Renews On', 'Joined On']);
    $sub_rows = sa_query(
        $conn,
        "SELECT t.*, p.plan_name,
                (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = t.id) AS customer_count
           FROM tenants t
           LEFT JOIN subscription_plans p ON p.id = t.plan_id
          ORDER BY t.created_at DESC",
        ['tenants', 'subscription_plans', 'customers']
    );
    foreach ($sub_rows as $sr) {
        fputcsv($out, [
            $sr['id'],
            $sr['company_name'],
            $sr['plan_name'] ?: 'No plan',
            $sr['subscription_price'],
            $sr['subscription_status'],
            !empty($sr['auto_renew']) ? 'Yes' : 'No',
            $sr['customer_count'],
            $sr['subscription_end_date'] ?: 'N/A',
            $sr['created_at'],
        ]);
    }
    fclose($out);
    exit();
}

/* ---------- filters ---------- */
$filter = isset($_GET['view']) ? preg_replace('/[^a-z_]/', '', strtolower($_GET['view'])) : 'all';
$allowed = ['all', 'active', 'trial', 'inactive', 'cancelled', 'due'];
if (!in_array($filter, $allowed, true)) {
    $filter = 'all';
}

$where = '';
if ($filter === 'due') {
    $where = " WHERE t.subscription_status IN ('active','trial')
                 AND t.subscription_end_date IS NOT NULL
                 AND t.subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($filter !== 'all') {
    $where = " WHERE t.subscription_status = '" . $filter . "'";
}

$rows = sa_query(
    $conn,
    "SELECT t.*, p.plan_name, p.price AS plan_price,
            (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = t.id) AS customer_count
       FROM tenants t
       LEFT JOIN subscription_plans p ON p.id = t.plan_id"
    . $where . "
      ORDER BY (t.subscription_end_date IS NULL), t.subscription_end_date ASC, t.company_name ASC",
    ['tenants', 'subscription_plans', 'customers']
);

$m = sa_metrics($conn);
$counts = sa_tenant_counts($conn);
$due_count = (int) sa_scalar(
    $conn,
    "SELECT COUNT(*) FROM tenants
      WHERE subscription_status IN ('active','trial')
        AND subscription_end_date IS NOT NULL
        AND subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
    0,
    'tenants'
);
$auto_renew_on = (int) sa_scalar($conn, "SELECT COUNT(*) FROM tenants WHERE auto_renew = 1", 0, 'tenants');

/* Plan changes workspaces asked for (admin/subscription.php) */
$plan_requests = admin_rows(
    $conn,
    "SELECT sr.*, t.company_name, t.email,
            p.plan_name AS requested_plan_name, p.price AS requested_price,
            cp.plan_name AS current_plan_name, cp.price AS current_price
       FROM subscription_requests sr
       LEFT JOIN tenants t ON t.id = sr.tenant_id
       LEFT JOIN subscription_plans p ON p.id = sr.requested_plan_id
       LEFT JOIN subscription_plans cp ON cp.id = sr.current_plan_id
      WHERE sr.status = 'pending'
      ORDER BY sr.created_at ASC"
);

/* Offline & Recorded Payment Ledger */
sa_ensure_payments_schema($conn);
$recent_payments = sa_query(
    $conn,
    "SELECT sp.*, t.company_name, t.email AS tenant_email
       FROM subscription_payments sp
       JOIN tenants t ON sp.tenant_id = t.id
      ORDER BY sp.created_at DESC LIMIT 15",
    ['subscription_payments', 'tenants']
);
$tenants_dropdown = sa_query($conn, "SELECT id, company_name, subscription_price FROM tenants ORDER BY company_name ASC", 'tenants');

$mrr_visible = 0.0;
foreach ($rows as $r) {
    if ($r['subscription_status'] === 'active') {
        $mrr_visible += (float) $r['subscription_price'];
    }
}

/* ---------- page meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = 'Subscriptions';
$pageHeading = 'Subscriptions';
$pageSubtitle = 'Billing status, renewals and auto-renew for every tenant.';
$activePage = 'subscriptions';
$BASE = '../';
$extraCss = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';
$searchTarget = '#subsTable';
$searchPlaceholder = 'Filter subscriptions…';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Subscriptions</span>
        </div>
        <h2>Subscriptions &amp; billing</h2>
        <p><?php echo sa_e(sa_money($m['mrr'])); ?> monthly recurring revenue across
           <?php echo sa_e(sa_num($m['paying'])); ?> paying tenants.</p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-ghost" data-sa-export="#subsTable" data-sa-export-name="optibiz-subscriptions" title="Export visible table">
            <?php echo sa_icon('download'); ?> View CSV
        </button>
        <a class="sa-btn sa-btn-ghost" href="subscriptions.php?export=csv" title="Export entire subscriptions database">
            <?php echo sa_icon('file-text'); ?> Full DB CSV
        </a>
        <button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#recordPaymentDialog">
            <?php echo sa_icon('card'); ?> Record Offline Payment
        </button>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<!-- ============ BILLING KPIs ============ -->
<div class="sa-grid sa-kpis sa-anim">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">MRR</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('dollar'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_money($m['mrr'])); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_money($m['arpu'])); ?> average per paying tenant</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">ARR</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('trending-up'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_money($m['arr'], 0)); ?></div>
        <div class="sa-kpi-note">Annualised run rate at today's MRR</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Due in 30 days</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('clock'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($due_count)); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_num($m['expired'])); ?> already past their end date</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-success);--kpi-soft:var(--sa-success-soft);--kpi-line:var(--sa-success-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Auto-renew on</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('refresh'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($auto_renew_on)); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(number_format(sa_pct($auto_renew_on, $counts['all'], 0))); ?>% of all tenants</div>
    </article>
</div>

<!-- ============ PLAN CHANGE REQUESTS ============ -->
<?php if ($plan_requests): ?>
<section class="sa-card sa-mb">
    <div class="sa-card-head">
        <div>
            <h3>Plan change requests</h3>
            <p><?php echo sa_e(sa_num(count($plan_requests))); ?> workspace<?php echo count($plan_requests) === 1 ? '' : 's'; ?>
               asked to move plan. Approving applies the new plan and price immediately.</p>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th scope="col">Tenant</th>
                    <th scope="col">From</th>
                    <th scope="col">To</th>
                    <th scope="col">Price change</th>
                    <th scope="col">Requested</th>
                    <th scope="col"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($plan_requests as $req): ?>
                <tr>
                    <td>
                        <div class="sa-cell-text">
                            <strong><?php echo sa_e($req['company_name'] ?? 'Unknown workspace'); ?></strong>
                            <span><?php echo sa_e($req['email'] ?? ''); ?></span>
                        </div>
                    </td>
                    <td><?php echo sa_e($req['current_plan_name'] ?? '—'); ?></td>
                    <td><strong><?php echo sa_e($req['requested_plan_name'] ?? '—'); ?></strong></td>
                    <td>
                        <?php echo sa_e(sa_money((float) ($req['current_price'] ?? 0))); ?>
                        &rarr;
                        <strong><?php echo sa_e(sa_money((float) ($req['requested_price'] ?? 0))); ?></strong>
                    </td>
                    <td><?php echo sa_date($req['created_at']); ?></td>
                    <td>
                        <form method="POST" style="display:inline-flex;gap:8px;align-items:center">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="request_id" value="<?php echo (int) $req['id']; ?>">
                            <button type="submit" name="action" value="approve_request" class="sa-btn sa-btn-primary sa-btn-sm">Approve</button>
                            <button type="submit" name="action" value="decline_request" class="sa-btn sa-btn-ghost sa-btn-sm">Decline</button>
                        </form>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- ============ FILTER CHIPS ============ -->
<section class="sa-card sa-mb">
    <div class="sa-filters">
        <div class="sa-chips">
<?php
$chips = [
    'all'       => ['label' => 'All', 'count' => $counts['all']],
    'active'    => ['label' => 'Active', 'count' => $counts['active']],
    'trial'     => ['label' => 'Trial', 'count' => $counts['trial']],
    'due'       => ['label' => 'Due in 30 days', 'count' => $due_count],
    'inactive'  => ['label' => 'Inactive', 'count' => $counts['inactive']],
    'cancelled' => ['label' => 'Cancelled', 'count' => $counts['cancelled']],
];
foreach ($chips as $key => $chip): ?>
            <a class="sa-chip<?php echo $filter === $key ? ' active' : ''; ?>"
               href="subscriptions.php?view=<?php echo $key; ?>"
               aria-pressed="<?php echo $filter === $key ? 'true' : 'false'; ?>">
                <?php echo sa_e($chip['label']); ?><span class="count"><?php echo (int) $chip['count']; ?></span>
            </a>
<?php endforeach; ?>
        </div>
        <span class="sa-pill" style="margin-left:auto"><?php echo sa_e(sa_money($mrr_visible)); ?> MRR in this view</span>
    </div>
</section>

<!-- ============ TABLE ============ -->
<section class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3><?php echo sa_e(ucfirst(str_replace('_', ' ', $filter))); ?> subscriptions</h3>
            <p>Sorted by the closest renewal date first</p>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table" id="subsTable" data-sa-sortable-table>
            <thead scope="col">
                <tr>
                    <th data-sa-sort="0" scope="col" aria-sort="none">Tenant</th>
                    <th data-sa-sort="1" scope="col" aria-sort="none">Plan</th>
                    <th data-sa-sort="2" data-type="num" scope="col" aria-sort="none">Price / mo</th>
                    <th data-sa-sort="3" scope="col" aria-sort="none">Status</th>
                    <th data-sa-sort="4" data-type="date" scope="col" aria-sort="none">Started</th>
                    <th data-sa-sort="5" data-type="date" scope="col" aria-sort="none">Ends</th>
                    <th data-sa-sort="6" scope="col" aria-sort="none">Renewal</th>
                    <th data-sa-sort="7" scope="col" aria-sort="none">Auto-renew</th>
                    <th data-no-export scope="col"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$rows): ?>
                <tr data-static>
                    <td colspan="9">
                        <div class="sa-empty">
                            <?php echo sa_icon('card'); ?>
                            <strong>No subscriptions in this view</strong>
                            <p>Switch the filter above, or create a tenant to start billing.</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($rows as $s): ?>
<?php
    $search_blob = strtolower(implode(' ', [
        $s['company_name'], $s['email'], $s['plan_name'], $s['subscription_status'],
    ]));
    list($renew_badge, $renew_kind) = sa_renewal_badge($s['subscription_end_date'], $s['auto_renew']);
    $days = sa_days_until($s['subscription_end_date']);
?>
                <tr data-filterable data-search="<?php echo sa_e($search_blob); ?>">
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($s['company_name'])); ?></span>
                            <span class="sa-cell-text">
                                <strong><?php echo sa_e($s['company_name']); ?></strong>
                                <span><?php echo sa_e($s['email']); ?></span>
                            </span>
                        </div>
                    </td>
                    <td><span class="sa-badge sa-badge-plan"><?php echo sa_e($s['plan_name'] ? $s['plan_name'] : 'No plan'); ?></span></td>
                    <td class="num" data-sort-value="<?php echo sa_e($s['subscription_price']); ?>" data-export-value="<?php echo sa_e($s['subscription_price']); ?>"><?php echo sa_e(sa_money($s['subscription_price'])); ?></td>
                    <td data-sort-value="<?php echo sa_e($s['subscription_status']); ?>">
                        <form method="POST" action="subscriptions.php" style="display:inline">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="tenant_id" value="<?php echo (int) $s['id']; ?>">
                            <select class="sa-inline-select" name="status" onchange="this.form.submit()" aria-label="Status for <?php echo sa_e($s['company_name']); ?>">
<?php foreach (['trial' => 'Trial', 'active' => 'Active', 'inactive' => 'Inactive', 'cancelled' => 'Cancelled'] as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>"<?php echo $s['subscription_status'] === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
<?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td data-sort-value="<?php echo sa_e($s['subscription_start_date'] ?: ''); ?>"><?php echo sa_e(sa_date($s['subscription_start_date'])); ?></td>
                    <td data-sort-value="<?php echo sa_e($s['subscription_end_date'] ?: ''); ?>"
                        data-export-value="<?php echo sa_e($s['subscription_end_date'] ?: ''); ?>">
                        <?php echo sa_e(sa_date($s['subscription_end_date'])); ?>
                    </td>
                    <td data-sort-value="<?php echo $days === null ? 99999 : (int) $days; ?>"><?php echo $renew_badge; ?></td>
                    <td>
                        <form method="POST" action="subscriptions.php" style="display:inline">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="auto_renew">
                            <input type="hidden" name="tenant_id" value="<?php echo (int) $s['id']; ?>">
                            <label class="sa-switch" title="<?php echo $s['auto_renew'] ? 'Auto-renew on' : 'Auto-renew off'; ?>">
                                <input type="checkbox" name="auto_renew" value="1" <?php echo $s['auto_renew'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                                <span class="sa-switch-track"></span>
                                <span class="sa-sr-only">Auto-renew <?php echo sa_e($s['company_name']); ?></span>
                            </label>
                        </form>
                    </td>
                    <td data-no-export>
                        <div class="sa-row-actions">
                            <form method="POST" action="subscriptions.php" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="extend">
                                <input type="hidden" name="tenant_id" value="<?php echo (int) $s['id']; ?>">
                                <input type="hidden" name="extend_months" value="12">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="Extend 12 months and mark active">
                                    <?php echo sa_icon('refresh'); ?> +12 mo
                                </button>
                            </form>
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenant_details.php?id=<?php echo (int) $s['id']; ?>" title="Open tenant">
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

    <div class="sa-empty" id="subsTableEmpty" hidden>
        <?php echo sa_icon('search'); ?>
        <strong>No matching subscriptions</strong>
        <p>Nothing in this view matches the text in the top-right filter box.</p>
    </div>

    <div class="sa-card-foot">
        <span><?php echo sa_e(sa_num(count($rows))); ?> subscription<?php echo count($rows) === 1 ? '' : 's'; ?> shown</span>
        <span>Toggles and status selects save immediately</span>
    </div>
</section>

<!-- ============ OFFLINE PAYMENTS & INVOICES ============ -->
<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <div style="display:inline-flex;align-items:center;gap:6px;margin-bottom:2px">
                <span class="sa-badge" style="background:rgba(34,197,94,0.1);color:#15803d;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:0.5px">Receipt Ledger</span>
            </div>
            <h3>Recent Recorded Payments &amp; Invoices</h3>
            <p>Manual bank wires, mobile money payments, and printable receipts issued by administrators.</p>
        </div>
        <div class="sa-card-head-actions">
            <button type="button" class="sa-btn sa-btn-sm sa-btn-primary" data-sa-open-dialog="#recordPaymentDialog">
                <?php echo sa_icon('plus'); ?> Record Payment
            </button>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table" id="paymentsTable">
            <thead scope="col">
                <tr>
                    <th scope="col">Receipt #</th>
                    <th scope="col">Tenant</th>
                    <th scope="col" class="num">Amount</th>
                    <th scope="col">Method</th>
                    <th scope="col">Reference / Memo</th>
                    <th scope="col">Extension</th>
                    <th scope="col">Recorded On</th>
                    <th scope="col"><span class="sa-sr-only">Receipt Action</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$recent_payments): ?>
                <tr>
                    <td colspan="8">
                        <div class="sa-empty">
                            <?php echo sa_icon('card'); ?>
                            <strong>No recorded payments yet</strong>
                            <p>Use the "Record Offline Payment" button to log manual wire transfers or mobile money payments and print receipts.</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($recent_payments as $pmt): ?>
                <tr>
                    <td>
                        <strong class="sa-mono" style="color:var(--sa-primary,#6366f1);font-weight:600">
                            <?php echo sa_e($pmt['receipt_number']); ?>
                        </strong>
                    </td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($pmt['company_name'])); ?></span>
                            <span class="sa-cell-text">
                                <strong><?php echo sa_e($pmt['company_name']); ?></strong>
                                <span><?php echo sa_e($pmt['tenant_email']); ?></span>
                            </span>
                        </div>
                    </td>
                    <td class="num">
                        <strong><?php echo sa_e(sa_money($pmt['amount'])); ?></strong>
                    </td>
                    <td>
                        <span class="sa-badge" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1">
                            <?php echo sa_e($pmt['payment_method']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="sa-cell-text">
                            <strong><?php echo sa_e($pmt['transaction_ref'] ?: '—'); ?></strong>
                            <span><?php echo sa_e($pmt['notes'] ?: ''); ?></span>
                        </span>
                    </td>
                    <td>
                        <?php if ((int)$pmt['months_extended'] > 0): ?>
                            <span class="sa-pill" style="color:var(--sa-success);background:rgba(34,197,94,0.1)">
                                +<?php echo (int)$pmt['months_extended']; ?> month<?php echo (int)$pmt['months_extended'] === 1 ? '' : 's'; ?>
                            </span>
                        <?php else: ?>
                            <span class="sa-faint" style="font-size:11.5px">No term extension</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-size:11.5px;color:var(--sa-muted)">
                            <?php echo sa_e(sa_date($pmt['created_at'])); ?>
                            <span style="display:block;font-size:10.5px">by <?php echo sa_e($pmt['recorded_by'] ?: 'Admin'); ?></span>
                        </span>
                    </td>
                    <td>
                        <a class="sa-btn sa-btn-sm sa-btn-ghost" href="invoice_receipt.php?id=<?php echo (int)$pmt['id']; ?>" target="_blank" title="View &amp; Print Receipt / Invoice">
                            <?php echo sa_icon('file-text'); ?> Receipt
                        </a>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ============ RECORD PAYMENT DIALOG ============ -->
<dialog class="sa-dialog" id="recordPaymentDialog" aria-labelledby="recordPaymentDialogTitle">
    <form method="POST" action="subscriptions.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="record_payment">
        <div class="sa-dialog-head">
            <div>
                <h3 id="recordPaymentDialogTitle">Record Offline Payment</h3>
                <p>Log a bank wire, mobile money transfer, or cash receipt and issue a printable invoice/receipt.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="p_tenant">Tenant / Company *</label>
                    <select id="p_tenant" name="tenant_id" required>
                        <option value="">Select a tenant…</option>
                        <?php foreach ($tenants_dropdown as $td): ?>
                            <option value="<?php echo (int) $td['id']; ?>" data-price="<?php echo (float) $td['subscription_price']; ?>">
                                <?php echo sa_e($td['company_name']); ?> (Plan: <?php echo sa_e(sa_money($td['subscription_price'])); ?>/mo)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sa-field">
                    <label for="p_amount">Payment Amount *</label>
                    <input id="p_amount" type="number" step="0.01" min="0.01" name="amount" placeholder="e.g. 150.00" required>
                </div>

                <div class="sa-field">
                    <label for="p_method">Payment Method *</label>
                    <select id="p_method" name="payment_method" required>
                        <option value="Bank Wire / Transfer">Bank Wire / Transfer</option>
                        <option value="Mobile Money (MTN / M-Pesa / Airtel)">Mobile Money (MTN / M-Pesa / Airtel)</option>
                        <option value="Cash">Cash</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Credit Card (Manual POS)">Credit Card (Manual POS)</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="sa-field">
                    <label for="p_ref">Transaction Ref / Slip #</label>
                    <input id="p_ref" type="text" name="transaction_ref" placeholder="e.g. MTN-981240, WIRE-4819">
                </div>

                <div class="sa-field">
                    <label for="p_ext">Extend Subscription By</label>
                    <select id="p_ext" name="months_extended">
                        <option value="0">Do not extend (record payment only)</option>
                        <option value="1">1 Month</option>
                        <option value="3">3 Months (Quarterly)</option>
                        <option value="6">6 Months (Half-year)</option>
                        <option value="12" selected>12 Months (1 Year)</option>
                        <option value="24">24 Months (2 Years)</option>
                    </select>
                </div>

                <div class="sa-field" style="grid-column:1/-1">
                    <label for="p_notes">Internal Notes / Payment Memo</label>
                    <textarea id="p_notes" name="notes" rows="2" placeholder="Optional notes for the invoice receipt (e.g. paid via Stanbic bank transfer, confirmed by finance)"></textarea>
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary">
                <?php echo sa_icon('check'); ?> Record Payment &amp; Issue Receipt
            </button>
        </div>
    </form>
</dialog>

<?php include __DIR__ . '/_shell_footer.php'; ?>
