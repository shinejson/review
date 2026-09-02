<?php
/**
 * ============================================================
 *  Super Admin — Tenant profile
 * ============================================================
 *  One tenant end to end: contact, subscription, plan usage,
 *  the companies it manages and the ratings it has collected.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();

$tenant_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($tenant_id <= 0) {
    redirect('tenants.php');
}

/* ---------- POST handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('tenant_details.php?id=' . $tenant_id);
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = (int) ($_POST['tenant_id'] ?? $tenant_id);

    if ($action === 'update_status' && $id) {
        $status = in_array($_POST['status'] ?? '', ['trial', 'active', 'inactive', 'cancelled'], true) ? $_POST['status'] : '';
        if ($status) {
            $stmt = $conn->prepare("UPDATE tenants SET subscription_status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Subscription marked ' . $status . '.');
        }
        redirect('tenant_details.php?id=' . $id);
    }

    if ($action === 'auto_renew' && $id) {
        $on = !empty($_POST['auto_renew']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE tenants SET auto_renew = ? WHERE id = ?");
        $stmt->bind_param("ii", $on, $id);
        $stmt->execute();
        $stmt->close();
        sa_flash('success', $on ? 'Auto-renew switched on.' : 'Auto-renew switched off.');
        redirect('tenant_details.php?id=' . $id);
    }

    if ($action === 'extend' && $id) {
        $months = max(1, min(60, (int) ($_POST['extend_months'] ?? 12)));
        $conn->query(
            "UPDATE tenants
                SET subscription_end_date = DATE_ADD(COALESCE(subscription_end_date, CURDATE()), INTERVAL " . $months . " MONTH),
                    subscription_status = 'active'
              WHERE id = " . $id
        );
        sa_flash('success', 'Subscription extended by ' . $months . ' month' . ($months === 1 ? '' : 's') . '.');
        redirect('tenant_details.php?id=' . $id);
    }
}

/* ---------- data ---------- */
$stmt = $conn->prepare(
    "SELECT t.*, p.plan_name, p.max_ratings, p.max_customers, p.price AS plan_price
       FROM tenants t
       LEFT JOIN subscription_plans p ON p.id = t.plan_id
      WHERE t.id = ?"
);
$tenant = null;
if ($stmt) {
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tenant = $result ? $result->fetch_assoc() : null;
    $stmt->close();
}
if (!$tenant) {
    sa_flash('error', 'That tenant no longer exists.');
    redirect('tenants.php');
}

$company_count = (int) sa_scalar($conn, "SELECT COUNT(*) FROM customers WHERE tenant_id = " . $tenant_id, 0, 'customers');
$rating_count  = (int) sa_scalar(
    $conn,
    "SELECT COUNT(*) FROM ratings r JOIN customers c ON c.id = r.company_id WHERE c.tenant_id = " . $tenant_id,
    0,
    ['ratings', 'customers']
);
$avg_rating    = (float) sa_scalar(
    $conn,
    "SELECT AVG(r.rating) FROM ratings r JOIN customers c ON c.id = r.company_id WHERE c.tenant_id = " . $tenant_id,
    0,
    ['ratings', 'customers']
);
$five_star     = (int) sa_scalar(
    $conn,
    "SELECT COUNT(*) FROM ratings r JOIN customers c ON c.id = r.company_id WHERE c.tenant_id = " . $tenant_id . " AND r.rating = 5",
    0,
    ['ratings', 'customers']
);
$ratings_30d   = (int) sa_scalar(
    $conn,
    "SELECT COUNT(*) FROM ratings r JOIN customers c ON c.id = r.company_id
      WHERE c.tenant_id = " . $tenant_id . " AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    0,
    ['ratings', 'customers']
);

$companies = sa_query(
    $conn,
    "SELECT c.id, c.company_name, c.email, c.phone, c.website, c.created_at,
            cat.name AS category_name,
            COUNT(r.id) AS rating_count,
            COALESCE(AVG(r.rating), 0) AS avg_rating
       FROM customers c
       LEFT JOIN categories cat ON cat.id = c.category_id
       LEFT JOIN ratings r ON r.company_id = c.id
      WHERE c.tenant_id = " . $tenant_id . "
      GROUP BY c.id, c.company_name, c.email, c.phone, c.website, c.created_at, cat.name
      ORDER BY rating_count DESC, c.company_name ASC",
    ['customers', 'categories', 'ratings']
);

$recent_ratings = sa_query(
    $conn,
    "SELECT r.id, r.rating, r.customer_name, r.customer_email, r.comment, r.created_at,
            c.company_name
       FROM ratings r
       JOIN customers c ON c.id = r.company_id
      WHERE c.tenant_id = " . $tenant_id . "
      ORDER BY r.created_at DESC
      LIMIT 8",
    ['ratings', 'customers']
);

$star_rows = sa_query(
    $conn,
    "SELECT r.rating AS rating, COUNT(*) AS cnt
       FROM ratings r JOIN customers c ON c.id = r.company_id
      WHERE c.tenant_id = " . $tenant_id . "
      GROUP BY r.rating",
    ['ratings', 'customers']
);
$star_dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
foreach ($star_rows as $r) {
    $k = (int) $r['rating'];
    if (isset($star_dist[$k])) {
        $star_dist[$k] = (int) $r['cnt'];
    }
}
krsort($star_dist);
$star_items = [];
foreach ($star_dist as $star => $count) {
    $star_items[] = [
        'label' => $star . ' star',
        'value' => $count,
        'meta' => sa_num($count) . ' · ' . number_format(sa_pct($count, $rating_count, 0)) . '%',
        'color' => $star >= 4 ? 'linear-gradient(90deg,#a8e030,#c2f542)'
            : ($star === 3 ? 'linear-gradient(90deg,#f59e0b,#fbbf24)' : 'linear-gradient(90deg,#ef4444,#f87171)'),
    ];
}

/* Usage against plan limits */
$limit_companies = (int) ($tenant['max_customers'] ?? 0);
$limit_ratings = (int) ($tenant['max_ratings'] ?? 0);
$use_companies = $limit_companies > 0 ? min(100, sa_pct($company_count, $limit_companies, 0)) : 0;
$use_ratings = $limit_ratings > 0 ? min(100, sa_pct($ratings_30d, $limit_ratings, 0)) : 0;

list($renew_badge, $renew_kind) = sa_renewal_badge($tenant['subscription_end_date'], $tenant['auto_renew']);
$days_left = sa_days_until($tenant['subscription_end_date']);

/* Lifetime value: months subscribed x price */
$months_subscribed = 1;
if (!empty($tenant['subscription_start_date'])) {
    $months_subscribed = max(1, (int) round((time() - strtotime($tenant['subscription_start_date'])) / 2592000));
}
$lifetime_value = $months_subscribed * (float) $tenant['subscription_price'];

/* ---------- page meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = $tenant['company_name'];
$pageHeading = $tenant['company_name'];
$pageSubtitle = 'Tenant #' . $tenant_id . ' · ' . ($tenant['plan_name'] ? $tenant['plan_name'] : 'No plan');
$activePage = 'tenants';
$BASE = '../';
$extraCss = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <a href="tenants.php">Tenants</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span><?php echo sa_e($tenant['company_name']); ?></span>
        </div>
        <h2 class="sa-flex" style="gap:12px">
            <span class="sa-cell-avatar" style="width:42px;height:42px;border-radius:13px;font-size:14px"><?php echo sa_e(sa_initials($tenant['company_name'])); ?></span>
            <?php echo sa_e($tenant['company_name']); ?>
            <?php echo sa_status_badge($tenant['subscription_status']); ?>
        </h2>
        <p>Customer since <?php echo sa_e(sa_date($tenant['created_at'])); ?> &middot; <?php echo sa_e(sa_num($company_count)); ?> companies &middot; <?php echo sa_e(sa_num($rating_count)); ?> ratings</p>
    </div>
    <div class="sa-head-actions">
        <a class="sa-btn sa-btn-ghost" href="tenants.php"><?php echo sa_icon('arrow-left'); ?> All tenants</a>
        <form method="POST" action="tenant_details.php?id=<?php echo (int) $tenant_id; ?>" style="display:inline">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="extend">
            <input type="hidden" name="tenant_id" value="<?php echo (int) $tenant_id; ?>">
            <input type="hidden" name="extend_months" value="12">
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('refresh'); ?> Extend 12 months</button>
        </form>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<!-- ============ KPIs ============ -->
<div class="sa-grid sa-kpis sa-anim">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Monthly revenue</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('dollar'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_money($tenant['subscription_price'])); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_money($lifetime_value, 0)); ?> lifetime value over <?php echo (int) $months_subscribed; ?> month<?php echo $months_subscribed === 1 ? '' : 's'; ?></div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Companies managed</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('users'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($company_count)); ?></div>
        <div class="sa-kpi-note"><?php echo $limit_companies > 0
            ? sa_e(sa_num($limit_companies)) . ' allowed by the ' . sa_e($tenant['plan_name'] ? $tenant['plan_name'] : 'plan')
            : 'Unlimited on this plan'; ?></div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Ratings collected</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('star'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($rating_count)); ?></div>
        <div class="sa-kpi-note"><?php echo $rating_count > 0 ? sa_stars($avg_rating) : 'No ratings yet'; ?></div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:<?php echo $renew_kind === 'expired' || $renew_kind === 'due' ? 'var(--sa-danger)' : 'var(--sa-success)'; ?>;--kpi-soft:<?php echo $renew_kind === 'expired' || $renew_kind === 'due' ? 'var(--sa-danger-soft)' : 'var(--sa-success-soft)'; ?>;--kpi-line:<?php echo $renew_kind === 'expired' || $renew_kind === 'due' ? 'var(--sa-danger-line)' : 'var(--sa-success-line)'; ?>">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Renewal</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('calendar'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo $days_left === null ? '—' : ($days_left < 0 ? sa_e(abs($days_left) . 'd') : sa_e($days_left . 'd')); ?></div>
        <div class="sa-kpi-note"><?php echo $renew_badge; ?> <?php echo sa_e(sa_date($tenant['subscription_end_date'], 'M d, Y')); ?></div>
    </article>
</div>

<!-- ============ PROFILE + SUBSCRIPTION ============ -->
<div class="sa-grid sa-cols-3">
    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Company information</h3><p>Primary contact for this tenant</p></div>
        </div>
        <div class="sa-card-pad">
            <dl class="sa-kv">
                <div class="sa-kv-row"><dt>Company</dt><dd><?php echo sa_e($tenant['company_name']); ?></dd></div>
                <div class="sa-kv-row"><dt>Email</dt><dd><a href="mailto:<?php echo sa_e($tenant['email']); ?>" style="color:var(--sa-accent);text-decoration:none"><?php echo sa_e($tenant['email']); ?></a></dd></div>
                <div class="sa-kv-row"><dt>Phone</dt><dd><?php echo sa_e($tenant['phone'] ? $tenant['phone'] : '—'); ?></dd></div>
                <div class="sa-kv-row"><dt>Tenant login</dt><dd class="sa-mono"><?php echo sa_e($tenant['username']); ?></dd></div>
                <div class="sa-kv-row"><dt>Created</dt><dd><?php echo sa_e(sa_date($tenant['created_at'], 'M d, Y H:i')); ?></dd></div>
            </dl>
        </div>
        <div class="sa-card-foot">
            <span>Login at <span class="sa-mono"><?php echo sa_e($BASE); ?>admin/login.php</span></span>
            <a href="tenants.php">Manage all tenants <?php echo sa_icon('chevron-right'); ?></a>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Subscription</h3><p>Plan, billing dates and renewal</p></div>
        </div>
        <div class="sa-card-pad">
            <dl class="sa-kv">
                <div class="sa-kv-row"><dt>Plan</dt><dd><span class="sa-badge sa-badge-plan"><?php echo sa_e($tenant['plan_name'] ? $tenant['plan_name'] : 'No plan'); ?></span></dd></div>
                <div class="sa-kv-row"><dt>Price</dt><dd><?php echo sa_e(sa_money($tenant['subscription_price'])); ?> / month</dd></div>
                <div class="sa-kv-row"><dt>Status</dt><dd><?php echo sa_status_badge($tenant['subscription_status']); ?></dd></div>
                <div class="sa-kv-row"><dt>Started</dt><dd><?php echo sa_e(sa_date($tenant['subscription_start_date'])); ?></dd></div>
                <div class="sa-kv-row"><dt>Ends</dt><dd><?php echo sa_e(sa_date($tenant['subscription_end_date'])); ?></dd></div>
            </dl>

            <form method="POST" action="tenant_details.php?id=<?php echo (int) $tenant_id; ?>" class="sa-mt">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="auto_renew">
                <input type="hidden" name="tenant_id" value="<?php echo (int) $tenant_id; ?>">
                <label class="sa-switch">
                    <input type="checkbox" name="auto_renew" value="1" <?php echo $tenant['auto_renew'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                    <span class="sa-switch-track"></span>
                    <span class="sa-switch-text">Auto-renew this subscription</span>
                </label>
            </form>

            <form method="POST" action="tenant_details.php?id=<?php echo (int) $tenant_id; ?>" class="sa-mt sa-flex" style="gap:8px">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="tenant_id" value="<?php echo (int) $tenant_id; ?>">
                <select class="sa-inline-select" name="status" aria-label="Subscription status">
<?php foreach (['trial' => 'Trial', 'active' => 'Active', 'inactive' => 'Inactive', 'cancelled' => 'Cancelled'] as $val => $lbl): ?>
                    <option value="<?php echo $val; ?>"<?php echo $tenant['subscription_status'] === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
<?php endforeach; ?>
                </select>
                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost"><?php echo sa_icon('save'); ?> Update</button>
            </form>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Plan usage</h3><p>How much of the allowance is used</p></div>
        </div>
        <div class="sa-card-pad">
            <div class="sa-bars">
                <div class="sa-bar-row">
                    <div class="sa-bar-head">
                        <strong>Companies</strong>
                        <span><?php echo sa_e(sa_num($company_count)); ?> of <?php echo $limit_companies >= 999 ? '∞' : sa_e(sa_num($limit_companies)); ?></span>
                    </div>
                    <div class="sa-bar-track"><i class="sa-bar-fill" style="--w:<?php echo sa_e($use_companies); ?>%;<?php echo $use_companies >= 90 ? '--bar:linear-gradient(90deg,#ef4444,#f87171)' : ''; ?>"></i></div>
                </div>
                <div class="sa-bar-row">
                    <div class="sa-bar-head">
                        <strong>Ratings this month</strong>
                        <span><?php echo sa_e(sa_num($ratings_30d)); ?> of <?php echo $limit_ratings >= 9999 ? '∞' : sa_e(sa_num($limit_ratings)); ?></span>
                    </div>
                    <div class="sa-bar-track"><i class="sa-bar-fill" style="--w:<?php echo sa_e($use_ratings); ?>%;<?php echo $use_ratings >= 90 ? '--bar:linear-gradient(90deg,#ef4444,#f87171)' : ''; ?>"></i></div>
                </div>
            </div>

            <div class="sa-section-title" style="margin:22px 0 11px">Score breakdown</div>
            <?php echo sa_bar_list($star_items); ?>
        </div>
        <div class="sa-card-foot">
            <span><?php echo sa_e(number_format(sa_pct($five_star, $rating_count, 0))); ?>% five-star</span>
            <a href="plans.php">Change plan <?php echo sa_icon('chevron-right'); ?></a>
        </div>
    </section>
</div>

<!-- ============ COMPANIES ============ -->
<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <h3>Companies managed by this tenant</h3>
            <p><?php echo sa_e(sa_num($company_count)); ?> entr<?php echo $company_count === 1 ? 'y' : 'ies'; ?> in the directory</p>
        </div>
        <div class="sa-card-head-actions">
            <span class="sa-pill"><?php echo sa_e(sa_num($rating_count)); ?> ratings total</span>
        </div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="companiesTable" data-sa-sortable-table>
            <thead scope="col">
                <tr>
                    <th data-sa-sort="0" scope="col" aria-sort="none">Company</th>
                    <th data-sa-sort="1" scope="col" aria-sort="none">Category</th>
                    <th data-sa-sort="2" scope="col" aria-sort="none">Website</th>
                    <th data-sa-sort="3" data-type="num" scope="col" aria-sort="none">Ratings</th>
                    <th data-sa-sort="4" data-type="num" scope="col" aria-sort="none">Average</th>
                    <th data-sa-sort="5" data-type="date" scope="col" aria-sort="none">Added</th>
                </tr>
            </thead>
            <tbody>
<?php if (!$companies): ?>
                <tr data-static>
                    <td colspan="6">
                        <div class="sa-empty">
                            <?php echo sa_icon('users'); ?>
                            <strong>No companies yet</strong>
                            <p>The tenant has not added any companies to rate. They can do it from their own admin panel.</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($companies as $c): ?>
                <tr>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($c['company_name'])); ?></span>
                            <span class="sa-cell-text">
                                <strong><?php echo sa_e($c['company_name']); ?></strong>
                                <span><?php echo sa_e($c['email'] ? $c['email'] : 'No email'); ?></span>
                            </span>
                        </div>
                    </td>
                    <td><span class="sa-badge sa-badge-info"><?php echo sa_e($c['category_name'] ? $c['category_name'] : 'Uncategorised'); ?></span></td>
                    <td><?php echo $c['website']
                        ? '<a href="' . sa_e(strpos($c['website'], 'http') === 0 ? $c['website'] : 'https://' . $c['website']) . '" target="_blank" rel="noopener" style="color:var(--sa-accent);text-decoration:none">' . sa_e($c['website']) . '</a>'
                        : '<span class="sa-faint">—</span>'; ?></td>
                    <td class="num" data-sort-value="<?php echo (int) $c['rating_count']; ?>"><?php echo sa_e(sa_num($c['rating_count'])); ?></td>
                    <td data-sort-value="<?php echo sa_e($c['avg_rating']); ?>"><?php echo (int) $c['rating_count'] > 0 ? sa_stars($c['avg_rating']) : '<span class="sa-faint">—</span>'; ?></td>
                    <td data-sort-value="<?php echo sa_e($c['created_at']); ?>"><?php echo sa_e(sa_date($c['created_at'])); ?></td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ============ RECENT RATINGS ============ -->
<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <h3>Latest ratings</h3>
            <p>Most recent feedback collected for this tenant</p>
        </div>
    </div>
<?php if (!$recent_ratings): ?>
    <div class="sa-empty">
        <?php echo sa_icon('star'); ?>
        <strong>No ratings yet</strong>
        <p>Share the public rating link for one of this tenant's companies to start collecting feedback.</p>
    </div>
<?php else: ?>
    <div class="sa-list">
<?php foreach ($recent_ratings as $r): ?>
        <div class="sa-list-item" style="align-items:flex-start">
            <span class="sa-list-icon is-warning"><?php echo sa_icon('star'); ?></span>
            <span class="sa-list-body">
                <strong><?php echo sa_e($r['company_name']); ?> &middot; <?php echo sa_e($r['customer_name']); ?></strong>
                <span><?php echo sa_e($r['comment'] !== '' && $r['comment'] !== null ? $r['comment'] : 'No comment left'); ?></span>
            </span>
            <span class="sa-list-side">
                <?php echo sa_stars($r['rating'], false); ?>
                <strong style="margin-top:4px"><?php echo sa_time_ago($r['created_at']); ?></strong>
            </span>
        </div>
<?php endforeach; ?>
    </div>
<?php endif; ?>
</section>

<?php include __DIR__ . '/_shell_footer.php'; ?>
