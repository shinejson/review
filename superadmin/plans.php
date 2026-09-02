<?php
/**
 * ============================================================
 *  Super Admin — Subscription plans
 * ============================================================
 *  Create plans, edit price/limits/features and switch a plan
 *  on or off without deleting it.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();

/* ---------- POST handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('plans.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create' || $action === 'edit') {
        $id = (int) ($_POST['plan_id'] ?? 0);
        $name = sanitize($_POST['plan_name'] ?? '');
        $price = round((float) ($_POST['price'] ?? 0), 2);
        $max_ratings = max(0, (int) ($_POST['max_ratings'] ?? 0));
        $max_customers = max(0, (int) ($_POST['max_customers'] ?? 0));
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        // Features arrive as one-per-line or comma separated; normalise to lines
        $raw_features = (string) ($_POST['features'] ?? '');
        $features = array_values(array_filter(array_map('sanitize', preg_split('/[\r\n,]+/', $raw_features))));
        $features_text = implode("\n", $features);

        if ($name === '') {
            sa_flash('error', 'A plan name is required.');
        } elseif ($price < 0) {
            sa_flash('error', 'The price cannot be negative.');
        } elseif ($action === 'create') {
            $stmt = $conn->prepare(
                "INSERT INTO subscription_plans (plan_name, price, max_ratings, max_customers, features, status)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("sdiiss", $name, $price, $max_ratings, $max_customers, $features_text, $status);
            $stmt->execute();
            sa_flash($stmt->error ? 'error' : 'success', $stmt->error ? 'Could not create the plan: ' . $stmt->error : $name . ' was added to the catalogue.');
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "UPDATE subscription_plans
                    SET plan_name = ?, price = ?, max_ratings = ?, max_customers = ?, features = ?, status = ?
                  WHERE id = ?"
            );
            $stmt->bind_param("sdiissi", $name, $price, $max_ratings, $max_customers, $features_text, $status, $id);
            $stmt->execute();
            sa_flash($stmt->error ? 'error' : 'success', $stmt->error ? 'Could not save the plan: ' . $stmt->error : $name . ' was updated.');
            $stmt->close();
        }
        redirect('plans.php');
    }

    if ($action === 'toggle_status') {
        $id = (int) ($_POST['plan_id'] ?? 0);
        if ($id) {
            $conn->query(
                "UPDATE subscription_plans
                    SET status = IF(status = 'active', 'inactive', 'active')
                  WHERE id = " . $id
            );
            sa_flash('success', 'Plan availability updated.');
        }
        redirect('plans.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['plan_id'] ?? 0);
        $in_use = (int) sa_scalar($conn, "SELECT COUNT(*) FROM tenants WHERE plan_id = " . $id, 0, 'tenants');
        if ($in_use > 0) {
            sa_flash('error', 'That plan is assigned to ' . $in_use . ' tenant' . ($in_use === 1 ? '' : 's') . '. Set it to inactive instead.');
        } elseif ($id) {
            $conn->query("DELETE FROM subscription_plans WHERE id = " . $id);
            sa_flash('success', 'Plan deleted.');
        }
        redirect('plans.php');
    }
}

/* ---------- data ---------- */
$plans = sa_query(
    $conn,
    "SELECT p.*,
            COUNT(t.id) AS tenant_count,
            COALESCE(SUM(CASE WHEN t.subscription_status = 'active' THEN t.subscription_price ELSE 0 END), 0) AS mrr,
            COALESCE(SUM(CASE WHEN t.subscription_status = 'trial' THEN 1 ELSE 0 END), 0) AS trial_count
       FROM subscription_plans p
       LEFT JOIN tenants t ON t.plan_id = p.id
      GROUP BY p.id
      ORDER BY p.price ASC",
    ['subscription_plans', 'tenants']
);

$total_mrr = 0.0;
$total_tenants = 0;
foreach ($plans as $p) {
    $total_mrr += (float) $p['mrr'];
    $total_tenants += (int) $p['tenant_count'];
}
$top_plan = null;
foreach ($plans as $p) {
    if ($top_plan === null || (int) $p['tenant_count'] > (int) $top_plan['tenant_count']) {
        $top_plan = $p;
    }
}

/* ---------- page meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = 'Plans';
$pageHeading = 'Plans & pricing';
$pageSubtitle = 'What tenants can buy, and how each plan is performing.';
$activePage = 'plans';
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
            <span>Plans</span>
        </div>
        <h2>Subscription plans</h2>
        <p><?php echo sa_e(sa_num(count($plans))); ?> plans &middot;
           <?php echo sa_e(sa_money($total_mrr)); ?> MRR &middot;
           <?php echo $top_plan ? 'most popular: ' . sa_e($top_plan['plan_name']) : 'no tenants assigned yet'; ?></p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#planCreateDialog">
            <?php echo sa_icon('plus'); ?> New plan
        </button>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<?php if (!$plans): ?>
<section class="sa-card">
    <div class="sa-empty">
        <?php echo sa_icon('layers'); ?>
        <strong>No plans yet</strong>
        <p>Create at least one plan so tenants can be assigned a price and a usage limit.</p>
        <button type="button" class="sa-btn sa-btn-primary sa-mt" data-sa-open-dialog="#planCreateDialog">
            <?php echo sa_icon('plus'); ?> Create the first plan
        </button>
    </div>
</section>
<?php else: ?>
<div class="sa-plans sa-anim">
<?php foreach ($plans as $i => $p): ?>
<?php
    $features = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $p['features']))));
    $is_featured = $top_plan && (int) $p['id'] === (int) $top_plan['id'] && (int) $p['tenant_count'] > 0;
    $inactive = $p['status'] !== 'active';
?>
    <article class="sa-card sa-plan<?php echo $is_featured ? ' is-featured' : ''; ?>"<?php echo $inactive ? ' style="opacity:.72"' : ''; ?>>
        <div class="sa-plan-head">
            <div>
                <div class="sa-plan-name"><?php echo sa_e($p['plan_name']); ?></div>
                <div class="sa-plan-tag">
                    <?php echo sa_e(sa_num($p['tenant_count'])); ?> tenant<?php echo (int) $p['tenant_count'] === 1 ? '' : 's'; ?>
                    &middot; <?php echo sa_e(sa_money($p['mrr'])); ?> MRR
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
                <?php echo $inactive
                    ? '<span class="sa-badge sa-badge-inactive">Inactive</span>'
                    : '<span class="sa-badge sa-badge-active">On sale</span>'; ?>
                <?php if ($is_featured): ?><span class="sa-badge sa-badge-lime">Most popular</span><?php endif; ?>
            </div>
        </div>

        <div class="sa-plan-price">
            <strong><?php echo sa_e(sa_money($p['price'])); ?></strong>
            <span>/ month</span>
        </div>

        <div class="sa-plan-stats">
            <div class="sa-plan-stat">
                <strong><?php echo (int) $p['max_ratings'] >= 9999 ? '∞' : sa_e(sa_num($p['max_ratings'])); ?></strong>
                <span>Ratings / mo</span>
            </div>
            <div class="sa-plan-stat">
                <strong><?php echo (int) $p['max_customers'] >= 999 ? '∞' : sa_e(sa_num($p['max_customers'])); ?></strong>
                <span>Companies</span>
            </div>
            <div class="sa-plan-stat">
                <strong><?php echo sa_e(sa_num($p['trial_count'])); ?></strong>
                <span>On trial</span>
            </div>
        </div>

        <div>
            <div class="sa-section-title" style="margin:0 0 11px">Included</div>
<?php if (!$features): ?>
            <p class="sa-muted" style="font-size:12.8px">No feature list saved for this plan yet.</p>
<?php else: ?>
            <ul class="sa-features">
<?php foreach ($features as $f): ?>
                <li><?php echo sa_icon('check'); ?> <?php echo sa_e($f); ?></li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
        </div>

        <div class="sa-plan-foot">
            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" style="flex:1"
                    data-sa-edit-plan
                    data-id="<?php echo (int) $p['id']; ?>"
                    data-name="<?php echo sa_e($p['plan_name']); ?>"
                    data-price="<?php echo sa_e($p['price']); ?>"
                    data-ratings="<?php echo (int) $p['max_ratings']; ?>"
                    data-customers="<?php echo (int) $p['max_customers']; ?>"
                    data-status="<?php echo sa_e($p['status']); ?>"
                    data-features="<?php echo sa_e(implode("\n", $features)); ?>">
                <?php echo sa_icon('edit'); ?> Edit
            </button>
            <form method="POST" action="plans.php" style="display:inline">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="plan_id" value="<?php echo (int) $p['id']; ?>">
                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="<?php echo $inactive ? 'Put back on sale' : 'Hide from new tenants'; ?>">
                    <?php echo sa_icon($inactive ? 'check-circle' : 'eye'); ?>
                    <?php echo $inactive ? 'Activate' : 'Deactivate'; ?>
                </button>
            </form>
<?php if ((int) $p['tenant_count'] === 0): ?>
            <form method="POST" action="plans.php" style="display:inline"
                  onsubmit="return confirm('Delete the <?php echo sa_e(addslashes($p['plan_name'])); ?> plan?');">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="plan_id" value="<?php echo (int) $p['id']; ?>">
                <button type="submit" class="sa-btn sa-btn-sm sa-btn-danger" title="Delete plan"><?php echo sa_icon('trash'); ?></button>
            </form>
<?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============ PLAN FORM (create + edit share one dialog) ============ -->
<dialog class="sa-dialog" id="planCreateDialog">
    <form method="POST" action="plans.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" id="pf_action" value="create">
        <input type="hidden" name="plan_id" id="pf_id" value="">
        <div class="sa-dialog-head">
            <div>
                <h3 id="pf_title">Create a plan</h3>
                <p id="pf_subtitle">Prices are monthly. Limits are enforced per tenant.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field">
                    <label for="pf_name">Plan name *</label>
                    <input id="pf_name" type="text" name="plan_name" placeholder="e.g. Growth" required>
                </div>
                <div class="sa-field">
                    <label for="pf_price">Price / month *</label>
                    <input id="pf_price" type="number" step="0.01" min="0" name="price" placeholder="49.00" required>
                </div>
                <div class="sa-field">
                    <label for="pf_ratings">Ratings per month</label>
                    <input id="pf_ratings" type="number" min="0" name="max_ratings" value="100">
                    <span class="sa-hint">Use 9999 for unlimited.</span>
                </div>
                <div class="sa-field">
                    <label for="pf_customers">Company limit</label>
                    <input id="pf_customers" type="number" min="0" name="max_customers" value="10">
                    <span class="sa-hint">Use 999 for unlimited.</span>
                </div>
                <div class="sa-field">
                    <label for="pf_status">Availability</label>
                    <select id="pf_status" name="status">
                        <option value="active">On sale</option>
                        <option value="inactive">Hidden from new tenants</option>
                    </select>
                </div>
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="pf_features">Features</label>
                    <textarea id="pf_features" name="features" placeholder="One feature per line, e.g.&#10;Advanced analytics&#10;Priority support&#10;Custom branding"></textarea>
                    <span class="sa-hint">Commas also work as separators.</span>
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary" id="pf_submit"><?php echo sa_icon('check'); ?> Create plan</button>
        </div>
    </form>
</dialog>

<script>
/* Reuse the plan dialog for editing (generic open/close lives in superadmin.js) */
(function () {
    function open(sel) {
        var d = document.querySelector(sel);
        if (!d) { return; }
        if (typeof d.showModal === 'function') { d.showModal(); }
        else { d.setAttribute('open', ''); d.classList.add('is-open-fallback'); }
    }

    document.querySelectorAll('[data-sa-edit-plan]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.dataset;
            document.getElementById('pf_action').value = 'edit';
            document.getElementById('pf_id').value = d.id;
            document.getElementById('pf_name').value = d.name;
            document.getElementById('pf_price').value = d.price;
            document.getElementById('pf_ratings').value = d.ratings;
            document.getElementById('pf_customers').value = d.customers;
            document.getElementById('pf_status').value = d.status;
            document.getElementById('pf_features').value = d.features;
            document.getElementById('pf_title').textContent = 'Edit plan';
            document.getElementById('pf_subtitle').textContent = 'Changes apply to every tenant on this plan.';
            document.getElementById('pf_submit').innerHTML = 'Save plan';
            open('#planCreateDialog');
        });
    });

    document.querySelectorAll('[data-sa-open-dialog="#planCreateDialog"]').forEach(function (opener) {
        opener.addEventListener('click', function () {
            document.getElementById('pf_action').value = 'create';
            document.getElementById('pf_id').value = '';
            document.getElementById('pf_name').value = '';
            document.getElementById('pf_price').value = '';
            document.getElementById('pf_ratings').value = '100';
            document.getElementById('pf_customers').value = '10';
            document.getElementById('pf_status').value = 'active';
            document.getElementById('pf_features').value = '';
            document.getElementById('pf_title').textContent = 'Create a plan';
            document.getElementById('pf_subtitle').textContent = 'Prices are monthly. Limits are enforced per tenant.';
            document.getElementById('pf_submit').textContent = 'Create plan';
        });
    });
})();
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
