<?php
/**
 * ============================================================
 *  Super Admin — Tenants
 * ============================================================
 *  Create, search, filter, edit, change plan/status, reset the
 *  tenant login password and delete tenants.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();
require_sa_permission('tenants');

/* ---------- POST handlers (PRG: redirect after every write) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('tenants.php');
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create') {
        $company = sanitize($_POST['company_name'] ?? '');
        $email   = sanitize($_POST['email'] ?? '');
        $phone   = sanitize($_POST['phone'] ?? '');
        $plan_id = (int) ($_POST['plan_id'] ?? 0);
        $status  = in_array($_POST['subscription_status'] ?? 'trial', ['trial', 'active', 'inactive'], true)
            ? $_POST['subscription_status'] : 'trial';
        $raw_pw  = (string) ($_POST['password'] ?? '');
        $months  = max(0, (int) ($_POST['trial_months'] ?? 1));

        if ($company === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sa_flash('error', 'A company name and a valid email address are required.');
        } elseif (strlen($raw_pw) < 6) {
            sa_flash('error', 'The tenant password must be at least 6 characters.');
        } elseif ((int) sa_scalar($conn, "SELECT COUNT(*) FROM tenants WHERE email = '" . $conn->real_escape_string($email) . "'", 0, 'tenants') > 0) {
            sa_flash('error', 'A tenant with that email address already exists.');
        } else {
            $plan = sa_one($conn, "SELECT id, price FROM subscription_plans WHERE id = " . $plan_id, 'subscription_plans');
            $price = $plan ? (float) $plan['price'] : 0.0;
            $username = sa_unique_username($conn, $company);
            $hash = password_hash($raw_pw, PASSWORD_DEFAULT);
            $start = date('Y-m-d');
            $end = $months > 0 ? date('Y-m-d', strtotime('+' . $months . ' month')) : null;

            $stmt = $conn->prepare(
                "INSERT INTO tenants
                    (company_name, email, phone, username, password, plan_id,
                     subscription_status, subscription_price, subscription_start_date, subscription_end_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $end_placeholder = $end; // bound by reference, may be NULL
            $stmt->bind_param(
                "sssssisssd",
                $company, $email, $phone, $username, $hash, $plan_id,
                $status, $price, $start, $end_placeholder
            );
            $stmt->execute();

            if ($stmt->error) {
                sa_flash('error', 'Could not create the tenant: ' . $stmt->error);
            } else {
                $new_id = (int) $conn->insert_id;

                // Auto-create the tenant's own company profile in the customers table
                $co_stmt = $conn->prepare(
                    "INSERT INTO customers (tenant_id, company_name, email, phone, created_at)
                     VALUES (?, ?, ?, ?, NOW())"
                );
                $co_stmt->bind_param("isss", $new_id, $company, $email, $phone);
                $co_stmt->execute();
                $co_stmt->close();

                sa_flash('success', $company . ' was created with the login “' . $username . '”.');
            }
            $stmt->close();
        }
        redirect('tenants.php');
    }

    if ($action === 'update_status') {
        $id = (int) ($_POST['tenant_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['trial', 'active', 'inactive', 'cancelled'], true)
            ? $_POST['status'] : '';
        if ($id && $status) {
            $stmt = $conn->prepare("UPDATE tenants SET subscription_status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Subscription status updated.');
        }
        redirect('tenants.php');
    }

    if ($action === 'edit') {
        $id = (int) ($_POST['tenant_id'] ?? 0);
        $company = sanitize($_POST['company_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $plan_id = (int) ($_POST['plan_id'] ?? 0);
        $price = (float) ($_POST['subscription_price'] ?? 0);
        $status = in_array($_POST['subscription_status'] ?? '', ['trial', 'active', 'inactive', 'cancelled'], true)
            ? $_POST['subscription_status'] : 'trial';
        $auto_renew = !empty($_POST['auto_renew']) ? 1 : 0;
        $start = !empty($_POST['subscription_start_date']) ? $_POST['subscription_start_date'] : null;
        $end = !empty($_POST['subscription_end_date']) ? $_POST['subscription_end_date'] : null;

        if (!$id) {
            sa_flash('error', 'Missing tenant id.');
        } elseif ($company === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sa_flash('error', 'A company name and a valid email address are required.');
        } else {
            $dup = (int) sa_scalar(
                $conn,
                "SELECT COUNT(*) FROM tenants WHERE email = '" . $conn->real_escape_string($email) . "' AND id <> " . $id,
                0,
                'tenants'
            );
            if ($dup > 0) {
                sa_flash('error', 'Another tenant already uses that email address.');
            } else {
                $stmt = $conn->prepare(
                    "UPDATE tenants SET company_name = ?, email = ?, phone = ?, plan_id = ?,
                            subscription_status = ?, subscription_price = ?, auto_renew = ?,
                            subscription_start_date = ?, subscription_end_date = ?
                      WHERE id = ?"
                );
                $stmt->bind_param(
                    "ssssidsdssi",
                    $company, $email, $phone, $plan_id, $status, $price,
                    $auto_renew, $start, $end, $id
                );
                $stmt->execute();
                if ($stmt->error) {
                    sa_flash('error', 'Could not save the tenant: ' . $stmt->error);
                } else {
                    sa_flash('success', $company . ' was updated.');
                }
                $stmt->close();
            }
        }
        redirect('tenants.php');
    }

    if ($action === 'reset_password') {
        $id = (int) ($_POST['tenant_id'] ?? 0);
        $raw_pw = (string) ($_POST['password'] ?? '');
        if (!$id || strlen($raw_pw) < 6) {
            sa_flash('error', 'Choose a tenant and use a password of at least 6 characters.');
        } else {
            $hash = password_hash($raw_pw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE tenants SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hash, $id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Tenant password updated.');
        }
        redirect('tenants.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['tenant_id'] ?? 0);
        $name = (string) sa_scalar($conn, "SELECT company_name FROM tenants WHERE id = " . $id, '', 'tenants');
        if ($id) {
            $conn->query("DELETE FROM tenants WHERE id = " . $id);
            sa_flash('success', ($name !== '' ? $name : 'Tenant') . ' and all of its data were removed.');
        }
        redirect('tenants.php');
    }

    if ($action === 'impersonate') {
        $id = (int) ($_POST['tenant_id'] ?? 0);
        $tenant = sa_one($conn, "SELECT * FROM tenants WHERE id = " . $id, 'tenants');
        if (!$tenant) {
            sa_flash('error', 'Tenant not found.');
            redirect('tenants.php');
        }

        // Store super admin identity for clean exit
        $_SESSION['impersonator_super_admin_id'] = (int) $_SESSION['super_admin_id'];
        $_SESSION['impersonator_super_admin_name'] = $_SESSION['super_admin_username'] ?? 'Super Admin';

        // Set tenant session context
        $_SESSION['tenant_id'] = (int) $tenant['id'];
        $_SESSION['tenant_name'] = $tenant['company_name'];
        $_SESSION['tenant_logo'] = $tenant['logo'] ?? '';
        $_SESSION['tenant_username'] = $tenant['username'];
        $_SESSION['tenant_email'] = $tenant['email'];
        $_SESSION['tenant_plan_id'] = $tenant['plan_id'];
        $_SESSION['tenant_status'] = $tenant['subscription_status'];
        $_SESSION['tenant_subscription_end'] = $tenant['subscription_end_date'] ?? null;
        $_SESSION['user_type'] = 'tenant';
        $_SESSION['admin_username'] = $tenant['username'];

        redirect('../admin/index.php');
    }
}

/* ---------- 1-click server CSV export ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=tenants-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Company Name', 'Email', 'Phone', 'Username', 'Plan', 'Price/mo', 'Status', 'Profiles Used', 'Profiles Limit', 'Reviews Used', 'Reviews Limit', 'Renews On', 'Created At']);
    $export_rows = sa_query(
        $conn,
        "SELECT t.*, p.plan_name, p.max_customers, p.max_ratings,
                (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = t.id) AS customer_count,
                (SELECT COUNT(*) FROM ratings r JOIN customers c ON r.company_id = c.id WHERE c.tenant_id = t.id) AS rating_count
           FROM tenants t
           LEFT JOIN subscription_plans p ON p.id = t.plan_id
          ORDER BY t.created_at DESC",
        ['tenants', 'subscription_plans', 'customers']
    );
    foreach ($export_rows as $er) {
        fputcsv($out, [
            $er['id'],
            $er['company_name'],
            $er['email'],
            $er['phone'],
            $er['username'],
            $er['plan_name'] ?: 'No plan',
            $er['subscription_price'],
            $er['subscription_status'],
            $er['customer_count'],
            (int)($er['max_customers'] ?? 0) >= 999 ? 'Unlimited' : (int)($er['max_customers'] ?? 0),
            $er['rating_count'],
            (int)($er['max_ratings'] ?? 0) >= 9999 ? 'Unlimited' : (int)($er['max_ratings'] ?? 0),
            $er['subscription_end_date'] ?: 'N/A',
            $er['created_at'],
        ]);
    }
    fclose($out);
    exit();
}

/* ---------- read data ---------- */
$filter = isset($_GET['status']) ? preg_replace('/[^a-z_]/', '', strtolower($_GET['status'])) : 'all';
$allowed = ['all', 'active', 'trial', 'inactive', 'cancelled', 'near_limit'];
if (!in_array($filter, $allowed, true)) {
    $filter = 'all';
}
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$where = ($filter === 'all' || $filter === 'near_limit') ? '' : " WHERE t.subscription_status = '" . $filter . "'";
if ($q !== '') {
    $like = '%' . $q . '%';
    $escaped = $conn->real_escape_string($like);
    $where .= ($where === '' ? ' WHERE ' : ' AND ')
        . " (t.company_name LIKE '" . $escaped . "' OR t.email LIKE '" . $escaped
        . "' OR t.username LIKE '" . $escaped . "')";
}

$raw_tenants = sa_query(
    $conn,
    "SELECT t.*, p.plan_name, p.price AS plan_price, p.max_customers, p.max_ratings,
            (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = t.id) AS customer_count,
            (SELECT COUNT(*) FROM ratings r JOIN customers c ON r.company_id = c.id WHERE c.tenant_id = t.id) AS rating_count
       FROM tenants t
       LEFT JOIN subscription_plans p ON p.id = t.plan_id"
    . $where . " ORDER BY t.created_at DESC",
    ['tenants', 'subscription_plans', 'customers']
);

$near_limit_total = 0;
$tenants = [];
foreach ($raw_tenants as $t) {
    $mc = (int) ($t['max_customers'] ?? 0);
    $mr = (int) ($t['max_ratings'] ?? 0);
    $cc = (int) ($t['customer_count'] ?? 0);
    $rc = (int) ($t['rating_count'] ?? 0);
    $cpct = $mc > 0 ? round(($cc / $mc) * 100) : 0;
    $rpct = $mr > 0 ? round(($rc / $mr) * 100) : 0;
    $is_near = ($mc > 0 && $mc < 999 && $cpct >= 80) || ($mr > 0 && $mr < 9999 && $rpct >= 80);
    if ($is_near) {
        $near_limit_total++;
    }
    $t['cust_pct'] = $cpct;
    $t['rat_pct'] = $rpct;
    $t['is_near_limit'] = $is_near;

    if ($filter === 'near_limit' && !$is_near) {
        continue;
    }
    $tenants[] = $t;
}

$plans = sa_query($conn, "SELECT id, plan_name, price FROM subscription_plans WHERE status = 'active' ORDER BY price ASC", 'subscription_plans');
$counts = sa_tenant_counts($conn);
$counts['near_limit'] = $near_limit_total;
$m = sa_metrics($conn);

/* ---------- page meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = 'Tenants';
$pageHeading = 'Tenants';
$pageSubtitle = 'Every company subscribed to the Optibiz platform.';
$activePage = 'tenants';
$BASE = '../';
$extraCss = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';
$searchTarget = '#tenantsTable';
$searchPlaceholder = 'Filter tenants…';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Tenants</span>
        </div>
        <h2>Manage tenants</h2>
        <p><?php echo sa_e(sa_num($counts['all'])); ?> companies &middot;
           <?php echo sa_e(sa_num($counts['active'])); ?> active &middot;
           <?php echo sa_e(sa_num($counts['trial'])); ?> on trial &middot;
           <?php echo sa_e(sa_money($m['mrr'])); ?> MRR</p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-ghost" data-sa-export="#tenantsTable" data-sa-export-name="optibiz-tenants" title="Export visible table to CSV">
            <?php echo sa_icon('download'); ?> View CSV
        </button>
        <a class="sa-btn sa-btn-ghost" href="tenants.php?export=csv" title="Download entire database records">
            <?php echo sa_icon('file-text'); ?> Full DB CSV
        </a>
        <button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#tenantCreateDialog">
            <?php echo sa_icon('plus'); ?> New tenant
        </button>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<!-- ============ FILTERS ============ -->
<section class="sa-card sa-mb">
    <div class="sa-filters">
        <div class="sa-chips">
<?php foreach ([
    'all' => 'All tenants', 'active' => 'Active', 'trial' => 'Trial',
    'inactive' => 'Inactive', 'cancelled' => 'Cancelled',
    'near_limit' => 'Near Limit / Upsell',
] as $key => $label): ?>
            <a class="sa-chip<?php echo $filter === $key ? ' active' : ''; ?><?php echo $key === 'near_limit' && !empty($counts['near_limit']) ? ' is-alert' : ''; ?>"
               href="tenants.php?status=<?php echo $key; ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>"
               aria-pressed="<?php echo $filter === $key ? 'true' : 'false'; ?>">
                <?php echo sa_e($label); ?><span class="count"><?php echo (int) ($counts[$key] ?? 0); ?></span>
            </a>
<?php endforeach; ?>
        </div>

        <form method="GET" action="tenants.php" style="margin-left:auto;display:flex;gap:8px;align-items:center">
            <input type="hidden" name="status" value="<?php echo sa_e($filter); ?>">
            <div class="sa-search" style="display:block;width:min(280px,52vw)">
                <?php echo sa_icon('search'); ?>
                <input type="search" name="q" value="<?php echo sa_e($q); ?>" placeholder="Search name, email or login…" aria-label="Search tenants">
            </div>
            <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost">Search</button>
<?php if ($q !== '' || $filter !== 'all'): ?>
            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenants.php" title="Clear filters"><?php echo sa_icon('x'); ?></a>
<?php endif; ?>
        </form>
    </div>
</section>

<!-- ============ TABLE ============ -->
<section class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3><?php echo sa_e(ucfirst(str_replace('_', ' ', $filter))); ?> tenants</h3>
            <p><?php echo sa_e(sa_num(count($tenants))); ?> result<?php echo count($tenants) === 1 ? '' : 's'; ?><?php echo $q !== '' ? ' for “' . sa_e($q) . '”' : ''; ?></p>
        </div>
        <div class="sa-card-head-actions">
            <span class="sa-pill"><?php echo sa_icon('users'); ?> <?php echo sa_e(sa_num($m['customers_total'])); ?> companies managed</span>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table" id="tenantsTable" data-sa-sortable-table>
            <thead scope="col">
                <tr>
                    <th data-sa-sort="0" data-type="num" scope="col" aria-sort="none">ID</th>
                    <th data-sa-sort="1" scope="col" aria-sort="none">Company</th>
                    <th data-sa-sort="2" scope="col" aria-sort="none">Contact</th>
                    <th data-sa-sort="3" scope="col" aria-sort="none">Plan</th>
                    <th data-sa-sort="4" data-type="num" scope="col" aria-sort="none">Price / mo</th>
                    <th data-sa-sort="5" scope="col" aria-sort="none">Status</th>
                    <th data-sa-sort="6" data-type="num" scope="col" aria-sort="none">Quota usage</th>
                    <th data-sa-sort="7" data-type="date" scope="col" aria-sort="none">Renews</th>
                    <th data-sa-sort="8" data-type="date" scope="col" aria-sort="none">Joined</th>
                    <th data-no-export scope="col"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$tenants): ?>
                <tr data-static>
                    <td colspan="10">
                        <div class="sa-empty">
                            <?php echo sa_icon('building'); ?>
                            <strong>No tenants found</strong>
                            <p><?php echo $q !== '' || $filter !== 'all'
                                ? 'Try clearing the search box or switching the status filter.'
                                : 'Create your first tenant to get the platform going.'; ?></p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($tenants as $t): ?>
<?php
    $search_blob = strtolower(implode(' ', [
        $t['id'], $t['company_name'], $t['email'], $t['username'], $t['phone'],
        $t['plan_name'], $t['subscription_status'],
    ]));
    list($renew_badge, $renew_kind) = sa_renewal_badge($t['subscription_end_date'], $t['auto_renew']);
?>
                <tr data-filterable data-search="<?php echo sa_e($search_blob); ?>">
                    <td class="num sa-faint" data-sort-value="<?php echo (int) $t['id']; ?>">#<?php echo (int) $t['id']; ?></td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($t['company_name'])); ?></span>
                            <span class="sa-cell-text">
                                <strong><?php echo sa_e($t['company_name']); ?></strong>
                                <span>login: <?php echo sa_e($t['username']); ?></span>
                            </span>
                        </div>
                    </td>
                    <td>
                        <span class="sa-cell-text">
                            <strong style="font-weight:500"><?php echo sa_e($t['email']); ?></strong>
                            <span><?php echo sa_e($t['phone'] ? $t['phone'] : 'No phone'); ?></span>
                        </span>
                    </td>
                    <td><span class="sa-badge sa-badge-plan"><?php echo sa_e($t['plan_name'] ? $t['plan_name'] : 'No plan'); ?></span></td>
                    <td class="num" data-sort-value="<?php echo sa_e($t['subscription_price']); ?>" data-export-value="<?php echo sa_e($t['subscription_price']); ?>"><?php echo sa_e(sa_money($t['subscription_price'])); ?></td>
                    <td data-sort-value="<?php echo sa_e($t['subscription_status']); ?>">
                        <form method="POST" action="tenants.php" style="display:inline">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="tenant_id" value="<?php echo (int) $t['id']; ?>">
                            <select class="sa-inline-select" name="status" onchange="this.form.submit()" aria-label="Status for <?php echo sa_e($t['company_name']); ?>">
<?php foreach (['trial' => 'Trial', 'active' => 'Active', 'inactive' => 'Inactive', 'cancelled' => 'Cancelled'] as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>"<?php echo $t['subscription_status'] === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
<?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td data-sort-value="<?php echo (int) $t['customer_count']; ?>">
                        <div style="font-size:11px;display:flex;flex-direction:column;gap:3px;min-width:120px">
                            <div>
                                <div style="display:flex;justify-content:space-between;color:var(--sa-muted);font-size:10.5px">
                                    <span>Profiles</span>
                                    <span><strong><?php echo (int)$t['customer_count']; ?></strong>/<?php echo (int)($t['max_customers'] ?? 0) >= 999 ? '∞' : (int)($t['max_customers'] ?? 0); ?></span>
                                </div>
                                <?php if ((int)($t['max_customers'] ?? 0) > 0 && (int)($t['max_customers'] ?? 0) < 999): ?>
                                <div style="height:4px;background:rgba(0,0,0,0.08);border-radius:2px;overflow:hidden;margin-top:2px">
                                    <div style="height:100%;width:<?php echo min(100, $t['cust_pct']); ?>%;background:<?php echo $t['cust_pct'] >= 90 ? '#ef4444' : ($t['cust_pct'] >= 75 ? '#f59e0b' : '#6366f1'); ?>;border-radius:2px"></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div style="display:flex;justify-content:space-between;color:var(--sa-muted);font-size:10.5px">
                                    <span>Reviews</span>
                                    <span><strong><?php echo (int)$t['rating_count']; ?></strong>/<?php echo (int)($t['max_ratings'] ?? 0) >= 9999 ? '∞' : (int)($t['max_ratings'] ?? 0); ?></span>
                                </div>
                                <?php if ((int)($t['max_ratings'] ?? 0) > 0 && (int)($t['max_ratings'] ?? 0) < 9999): ?>
                                <div style="height:4px;background:rgba(0,0,0,0.08);border-radius:2px;overflow:hidden;margin-top:2px">
                                    <div style="height:100%;width:<?php echo min(100, $t['rat_pct']); ?>%;background:<?php echo $t['rat_pct'] >= 90 ? '#ef4444' : ($t['rat_pct'] >= 75 ? '#f59e0b' : '#0284c7'); ?>;border-radius:2px"></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($t['is_near_limit'])): ?>
                                <span class="sa-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:10px;padding:1px 5px;margin-top:2px;align-self:flex-start">Upsell Alert</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-sort-value="<?php echo sa_e($t['subscription_end_date'] ?: ''); ?>"><?php echo $renew_badge; ?></td>
                    <td data-sort-value="<?php echo sa_e($t['created_at']); ?>"><?php echo sa_e(sa_date($t['created_at'])); ?></td>
                    <td data-no-export>
                        <div class="sa-row-actions">
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenant_details.php?id=<?php echo (int) $t['id']; ?>" title="Open <?php echo sa_e($t['company_name']); ?>">
                                <?php echo sa_icon('eye'); ?>
                            </a>
                            <form method="POST" action="tenants.php" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="impersonate">
                                <input type="hidden" name="tenant_id" value="<?php echo (int) $t['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" style="color:var(--sa-primary, #6366f1)" title="Log in as <?php echo sa_e($t['company_name']); ?> (Support Mode)">
                                    <?php echo sa_icon('external'); ?>
                                </button>
                            </form>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" title="Edit tenant"
                                    data-sa-edit-tenant
                                    data-id="<?php echo (int) $t['id']; ?>"
                                    data-company="<?php echo sa_e($t['company_name']); ?>"
                                    data-email="<?php echo sa_e($t['email']); ?>"
                                    data-phone="<?php echo sa_e($t['phone']); ?>"
                                    data-plan="<?php echo (int) $t['plan_id']; ?>"
                                    data-price="<?php echo sa_e($t['subscription_price']); ?>"
                                    data-status="<?php echo sa_e($t['subscription_status']); ?>"
                                    data-renew="<?php echo (int) $t['auto_renew']; ?>"
                                    data-start="<?php echo sa_e($t['subscription_start_date']); ?>"
                                    data-end="<?php echo sa_e($t['subscription_end_date']); ?>">
                                <?php echo sa_icon('edit'); ?>
                            </button>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" title="Reset tenant password"
                                    data-sa-password-tenant data-id="<?php echo (int) $t['id']; ?>" data-company="<?php echo sa_e($t['company_name']); ?>">
                                <?php echo sa_icon('key'); ?>
                            </button>
                            <form method="POST" action="tenants.php" style="display:inline"
                                  onsubmit="return confirm('Delete <?php echo sa_e(addslashes($t['company_name'])); ?> and all of its companies, ratings and history? This cannot be undone.');">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="tenant_id" value="<?php echo (int) $t['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-danger" title="Delete tenant"><?php echo sa_icon('trash'); ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="sa-empty" id="tenantsTableEmpty" hidden>
        <?php echo sa_icon('search'); ?>
        <strong>No matching tenants</strong>
        <p>Nothing in this table matches the text in the top-right filter box.</p>
    </div>

    <div class="sa-card-foot">
        <span>Showing <?php echo sa_e(sa_num(count($tenants))); ?> of <?php echo sa_e(sa_num($counts['all'])); ?> tenants</span>
        <span>Status changes save instantly &middot; use <?php echo sa_icon('key', 'style="width:12px;height:12px;vertical-align:-2px"'); ?> to reset a tenant login</span>
    </div>
</section>

<!-- ============ CREATE TENANT DIALOG ============ -->
<dialog class="sa-dialog" id="tenantCreateDialog" aria-labelledby="tenantCreateDialogTitle">
    <form method="POST" action="tenants.php" class="sa-form" id="tenantCreateForm">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="sa-dialog-head">
            <div>
                <h3 id="tenantCreateDialogTitle">Create a tenant</h3>
                <p>This also creates the login the company will use at <span class="sa-mono">/admin/login.php</span>.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field">
                    <label for="c_company">Company name *</label>
                    <input id="c_company" type="text" name="company_name" placeholder="e.g. Accra Consulting Ltd" required>
                </div>
                <div class="sa-field">
                    <label for="c_email">Billing email *</label>
                    <input id="c_email" type="email" name="email" placeholder="billing@company.com" required>
                </div>
                <div class="sa-field">
                    <label for="c_phone">Phone</label>
                    <input id="c_phone" type="tel" name="phone" placeholder="+233 …">
                </div>
                <div class="sa-field">
                    <label for="c_plan">Plan</label>
                    <select id="c_plan" name="plan_id">
<?php foreach ($plans as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>"><?php echo sa_e($p['plan_name'] . ' — ' . sa_money($p['price']) . '/mo'); ?></option>
<?php endforeach; ?>
<?php if (!$plans): ?>
                        <option value="0">No active plans</option>
<?php endif; ?>
                    </select>
                </div>
                <div class="sa-field">
                    <label for="c_status">Starts as</label>
                    <select id="c_status" name="subscription_status">
                        <option value="trial">Trial</option>
                        <option value="active">Active (paying)</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="sa-field">
                    <label for="c_months">Subscription length (months)</label>
                    <input id="c_months" type="number" name="trial_months" min="0" max="60" value="1">
                    <span class="sa-hint">0 leaves the end date open.</span>
                </div>
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="c_password">Temporary password *</label>
                    <input id="c_password" type="text" name="password" minlength="6" placeholder="At least 6 characters" required>
                    <span class="sa-hint">The tenant login name is generated from the company name.</span>
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('check'); ?> Create tenant</button>
        </div>
    </form>
</dialog>

<!-- ============ EDIT TENANT DIALOG ============ -->
<dialog class="sa-dialog" id="tenantEditDialog" aria-labelledby="tenantEditDialogTitle">
    <form method="POST" action="tenants.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="tenant_id" id="e_id" value="">
        <div class="sa-dialog-head">
            <div>
                <h3 id="tenantEditDialogTitle">Edit tenant</h3>
                <p id="e_subtitle">Update the company record, plan and billing dates.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field">
                    <label for="e_company">Company name *</label>
                    <input id="e_company" type="text" name="company_name" required>
                </div>
                <div class="sa-field">
                    <label for="e_email">Email *</label>
                    <input id="e_email" type="email" name="email" required>
                </div>
                <div class="sa-field">
                    <label for="e_phone">Phone</label>
                    <input id="e_phone" type="tel" name="phone">
                </div>
                <div class="sa-field">
                    <label for="e_plan">Plan</label>
                    <select id="e_plan" name="plan_id">
<?php foreach ($plans as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" data-price="<?php echo sa_e($p['price']); ?>"><?php echo sa_e($p['plan_name']); ?></option>
<?php endforeach; ?>
                        <option value="0">No plan</option>
                    </select>
                </div>
                <div class="sa-field">
                    <label for="e_price">Price / month</label>
                    <input id="e_price" type="number" step="0.01" min="0" name="subscription_price" value="0">
                </div>
                <div class="sa-field">
                    <label for="e_status">Status</label>
                    <select id="e_status" name="subscription_status">
<?php foreach (['trial' => 'Trial', 'active' => 'Active', 'inactive' => 'Inactive', 'cancelled' => 'Cancelled'] as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"><?php echo $lbl; ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <div class="sa-field">
                    <label for="e_start">Start date</label>
                    <input id="e_start" type="date" name="subscription_start_date">
                </div>
                <div class="sa-field">
                    <label for="e_end">End date</label>
                    <input id="e_end" type="date" name="subscription_end_date">
                </div>
                <div class="sa-field" style="grid-column:1/-1">
                    <label class="sa-switch">
                        <input type="checkbox" name="auto_renew" id="e_renew" value="1">
                        <span class="sa-switch-track"></span>
                        <span class="sa-switch-text">Auto-renew at the end date</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('save'); ?> Save changes</button>
        </div>
    </form>
</dialog>

<!-- ============ RESET PASSWORD DIALOG ============ -->
<dialog class="sa-dialog" id="tenantPasswordDialog" style="width:min(460px,calc(100vw - 32px))" aria-labelledby="tenantPasswordDialogTitle">
    <form method="POST" action="tenants.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="tenant_id" id="p_id" value="">
        <div class="sa-dialog-head">
            <div>
                <h3 id="tenantPasswordDialogTitle">Reset tenant password</h3>
                <p id="p_subtitle">Set a new login password for this tenant.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>
        <div class="sa-dialog-body">
            <div class="sa-field">
                <label for="p_password">New password</label>
                <input id="p_password" type="text" name="password" minlength="6" placeholder="At least 6 characters" required>
                <span class="sa-hint">Share it with the tenant over a secure channel.</span>
            </div>
        </div>
        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('key'); ?> Update password</button>
        </div>
    </form>
</dialog>

<script>
/* Edit / password prefill for the tenants page.
   Generic dialog open+close is handled by assets/js/superadmin.js. */
(function () {
    function openDialog(sel) {
        var d = document.querySelector(sel);
        if (!d) { return; }
        if (typeof d.showModal === 'function') { d.showModal(); }
        else { d.setAttribute('open', ''); d.classList.add('is-open-fallback'); }
    }

    document.querySelectorAll('[data-sa-edit-tenant]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.dataset;
            document.getElementById('e_id').value = d.id;
            document.getElementById('e_company').value = d.company;
            document.getElementById('e_email').value = d.email;
            document.getElementById('e_phone').value = d.phone;
            document.getElementById('e_plan').value = d.plan;
            document.getElementById('e_price').value = d.price;
            document.getElementById('e_status').value = d.status;
            document.getElementById('e_start').value = d.start || '';
            document.getElementById('e_end').value = d.end || '';
            document.getElementById('e_renew').checked = d.renew === '1';
            document.getElementById('e_subtitle').textContent = 'Editing tenant #' + d.id + ' — ' + d.company;
            openDialog('#tenantEditDialog');
        });
    });

    document.getElementById('e_plan').addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        if (opt && opt.getAttribute('data-price')) {
            document.getElementById('e_price').value = opt.getAttribute('data-price');
        }
    });

    document.querySelectorAll('[data-sa-password-tenant]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('p_id').value = btn.dataset.id;
            document.getElementById('p_subtitle').textContent = 'Set a new login password for ' + btn.dataset.company + '.';
            openDialog('#tenantPasswordDialog');
        });
    });
})();
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
