<?php
/**
 * ============================================================
 *  Workspace — Subscription
 * ============================================================
 *  Shows the plan this workspace is on, how much of its
 *  allowance is used, and every other plan available. Choosing a
 *  different plan files a change request (`subscription_requests`)
 *  that the platform owner approves from the super admin panel —
 *  a tenant can never silently re-price itself.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

admin_ensure_schema($conn);

/* ============================================================
   POST handlers
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('subscription.php');
    }
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if (!$is_tenant || !$tenant_id) {
        sa_flash('error', 'Plan changes are requested from inside a workspace.');
        redirect('subscription.php');
    }

    if ($action === 'request_change') {
        $plan_id = (int) ($_POST['plan_id'] ?? 0);
        $note    = sanitize($_POST['note'] ?? '');

        $plan = admin_row($conn, "SELECT * FROM subscription_plans WHERE id = " . $plan_id . " AND status = 'active' LIMIT 1");
        if (!$plan) {
            sa_flash('error', 'That plan is not available.');
            redirect('subscription.php');
        }

        $tenant_row = admin_row($conn, "SELECT plan_id FROM tenants WHERE id = " . (int) $tenant_id . " LIMIT 1");
        $current_plan_id = (int) ($tenant_row['plan_id'] ?? 0);

        if ($current_plan_id === $plan_id) {
            sa_flash('error', 'You are already on that plan.');
            redirect('subscription.php');
        }

        $pending = admin_scalar(
            $conn,
            "SELECT COUNT(*) FROM subscription_requests
              WHERE tenant_id = " . (int) $tenant_id . " AND status = 'pending'",
            0
        );
        if ((int) $pending > 0) {
            sa_flash('error', 'You already have a plan change waiting for approval.');
            redirect('subscription.php');
        }

        $current_price = (float) admin_scalar(
            $conn,
            "SELECT price FROM subscription_plans WHERE id = " . $current_plan_id,
            0
        );
        $direction = (float) $plan['price'] > $current_price
            ? 'upgrade'
            : ((float) $plan['price'] < $current_price ? 'downgrade' : 'same');

        $stmt = $conn->prepare(
            "INSERT INTO subscription_requests (tenant_id, current_plan_id, requested_plan_id, direction, note, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        $tid = (int) $tenant_id;
        $stmt->bind_param('iiiss', $tid, $current_plan_id, $plan_id, $direction, $note);
        $ok = $stmt->execute();
        $stmt->close();

        sa_flash($ok ? 'success' : 'error', $ok
            ? ucfirst($direction) . ' to ' . $plan['plan_name'] . ' requested — our team will confirm shortly.'
            : 'Could not file the request. Please try again.');
        redirect('subscription.php');
    }

    if ($action === 'cancel_request') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $stmt = $conn->prepare(
            "UPDATE subscription_requests SET status = 'cancelled', resolved_at = NOW()
              WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $tid = (int) $tenant_id;
        $stmt->bind_param('ii', $request_id, $tid);
        $stmt->execute();
        $stmt->close();
        sa_flash('success', 'Plan change request withdrawn.');
        redirect('subscription.php');
    }

    if ($action === 'auto_renew') {
        $on = !empty($_POST['auto_renew']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE tenants SET auto_renew = ? WHERE id = ?");
        $tid = (int) $tenant_id;
        $stmt->bind_param('ii', $on, $tid);
        $stmt->execute();
        $stmt->close();
        sa_flash('success', $on ? 'Auto-renew switched on.' : 'Auto-renew switched off.');
        redirect('subscription.php');
    }

    redirect('subscription.php');
}

/* ============================================================
   Page data
   ============================================================ */
$flash = sa_take_flash();

$plans = admin_rows($conn, "SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price ASC");

$tenant = [];
$pending_request = [];
$requests = [];
$usage_companies = 0;
$usage_ratings_month = 0;
$usage_ratings_total = 0;
$days_left = null;

if ($is_tenant && $tenant_id) {
    $tenant = admin_row(
        $conn,
        "SELECT t.*, p.plan_name, p.price AS plan_price, p.max_ratings, p.max_customers, p.features
           FROM tenants t
           LEFT JOIN subscription_plans p ON p.id = t.plan_id
          WHERE t.id = " . (int) $tenant_id . " LIMIT 1"
    );

    $usage_companies = (int) admin_scalar(
        $conn,
        "SELECT COUNT(*) FROM customers WHERE tenant_id = " . (int) $tenant_id,
        0
    );
    $usage_ratings_month = (int) admin_scalar(
        $conn,
        "SELECT COUNT(*) FROM ratings r JOIN customers c ON c.id = r.company_id
          WHERE c.tenant_id = " . (int) $tenant_id . "
            AND r.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
        0
    );
    $usage_ratings_total = (int) admin_scalar(
        $conn,
        "SELECT COUNT(*) FROM ratings r JOIN customers c ON c.id = r.company_id
          WHERE c.tenant_id = " . (int) $tenant_id,
        0
    );

    $pending_request = admin_row(
        $conn,
        "SELECT sr.*, p.plan_name AS requested_plan_name, p.price AS requested_price
           FROM subscription_requests sr
           LEFT JOIN subscription_plans p ON p.id = sr.requested_plan_id
          WHERE sr.tenant_id = " . (int) $tenant_id . " AND sr.status = 'pending'
          ORDER BY sr.created_at DESC LIMIT 1"
    );

    $requests = admin_rows(
        $conn,
        "SELECT sr.*, p.plan_name AS requested_plan_name, cp.plan_name AS current_plan_name
           FROM subscription_requests sr
           LEFT JOIN subscription_plans p ON p.id = sr.requested_plan_id
           LEFT JOIN subscription_plans cp ON cp.id = sr.current_plan_id
          WHERE sr.tenant_id = " . (int) $tenant_id . "
          ORDER BY sr.created_at DESC LIMIT 10"
    );

    if (!empty($tenant['subscription_end_date'])) {
        $end = strtotime((string) $tenant['subscription_end_date']);
        if ($end) {
            $days_left = (int) floor(($end - strtotime('today')) / 86400);
        }
    }
} else {
    // Global administrator: a read-only roll-up of every workspace.
    $all_tenants = admin_rows(
        $conn,
        "SELECT t.id, t.company_name, t.subscription_status, t.subscription_price,
                t.subscription_end_date, t.auto_renew, p.plan_name
           FROM tenants t
           LEFT JOIN subscription_plans p ON p.id = t.plan_id
          ORDER BY t.company_name ASC"
    );
    $pending_all = admin_rows(
        $conn,
        "SELECT sr.*, t.company_name, p.plan_name AS requested_plan_name
           FROM subscription_requests sr
           LEFT JOIN tenants t ON t.id = sr.tenant_id
           LEFT JOIN subscription_plans p ON p.id = sr.requested_plan_id
          WHERE sr.status = 'pending'
          ORDER BY sr.created_at DESC"
    );
}

$current_plan_id = (int) ($tenant['plan_id'] ?? 0);
$current_price   = (float) ($tenant['subscription_price'] ?? ($tenant['plan_price'] ?? 0));

/* ---------- page ---------- */
$robots    = 'noindex, nofollow';
$BASE      = '../';
$pageTitle = 'Subscription';
$activeNav = 'subscription';
include __DIR__ . '/_shell.php';
?>
        <div class="page-header">
            <div>
                <h1>Subscription</h1>
                <p>Your plan, what you have used this month, and the upgrades available to you.</p>
            </div>
            <a class="btn btn-secondary" href="settings.php">Billing details</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>">
                <?php echo $flash['type'] === 'error' ? '⚠' : '✓'; ?> <?php echo sa_e($flash['message']); ?>
            </div>
        <?php endif; ?>

<?php if ($is_tenant && $tenant): ?>

        <?php if ($pending_request): ?>
            <div class="admin-pending-banner">
                <div>
                    <strong><?php echo sa_e(ucfirst((string) $pending_request['direction'])); ?> to
                        <?php echo sa_e($pending_request['requested_plan_name'] ?? 'a new plan'); ?> is awaiting approval</strong>
                    <p>Requested <?php echo sa_e(date('M d, Y', strtotime((string) $pending_request['created_at']))); ?>.
                        We will email you as soon as it is activated.</p>
                </div>
                <form method="POST">
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="request_id" value="<?php echo (int) $pending_request['id']; ?>">
                    <button type="submit" name="action" value="cancel_request" class="btn btn-secondary">Withdraw</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="grid-2col">
            <!-- Current plan -->
            <div class="form-card">
                <h3>Current subscription</h3>

                <div class="subscription-card">
                    <div class="plan-header">
                        <span class="plan-name"><?php echo sa_e($tenant['plan_name'] ?? 'No plan assigned'); ?></span>
                        <span class="plan-status"><?php echo sa_e($tenant['subscription_status'] ?? 'unknown'); ?></span>
                    </div>
                    <div class="plan-price">
                        <?php echo sa_e(sa_money($current_price)); ?><span>/ month</span>
                    </div>
                    <p class="plan-description">
                        <?php echo sa_e($tenant['features'] ?? 'Contact us to have a plan assigned to this workspace.'); ?>
                    </p>
                </div>

                <div class="subscription-details">
                    <div class="subscription-detail-row">
                        <span>Started</span>
                        <strong><?php echo !empty($tenant['subscription_start_date'])
                            ? sa_e(date('M d, Y', strtotime((string) $tenant['subscription_start_date'])))
                            : '—'; ?></strong>
                    </div>
                    <div class="subscription-detail-row">
                        <span><?php echo !empty($tenant['auto_renew']) ? 'Renews' : 'Ends'; ?></span>
                        <strong><?php echo !empty($tenant['subscription_end_date'])
                            ? sa_e(date('M d, Y', strtotime((string) $tenant['subscription_end_date'])))
                            : '—'; ?></strong>
                    </div>
                    <div class="subscription-detail-row">
                        <span>Days remaining</span>
                        <strong>
                            <?php if ($days_left === null): ?>
                                —
                            <?php elseif ($days_left < 0): ?>
                                <?php echo admin_badge('Expired', 'bad'); ?>
                            <?php elseif ($days_left <= 14): ?>
                                <?php echo admin_badge($days_left . ' days left', 'warn'); ?>
                            <?php else: ?>
                                <?php echo sa_e(sa_num($days_left)); ?> days
                            <?php endif; ?>
                        </strong>
                    </div>
                    <div class="subscription-detail-row">
                        <span>Status</span>
                        <strong><?php echo admin_badge(ucfirst((string) ($tenant['subscription_status'] ?? 'unknown')),
                            admin_status_tone($tenant['subscription_status'] ?? '')); ?></strong>
                    </div>
                </div>

                <form method="POST" class="admin-inline-form">
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="auto_renew" value="<?php echo !empty($tenant['auto_renew']) ? '0' : '1'; ?>">
                    <span>Auto-renew is <strong><?php echo !empty($tenant['auto_renew']) ? 'on' : 'off'; ?></strong></span>
                    <button type="submit" name="action" value="auto_renew" class="btn btn-secondary">
                        Switch <?php echo !empty($tenant['auto_renew']) ? 'off' : 'on'; ?>
                    </button>
                </form>
            </div>

            <!-- Usage -->
            <div class="form-card">
                <h3>Usage against your plan</h3>
                <p class="muted" style="margin-bottom:18px;">Updated live from your workspace data.</p>

                <?php
                echo admin_meter(
                    'Companies',
                    $usage_companies,
                    (int) ($tenant['max_customers'] ?? 0),
                    $usage_companies . ' of ' . (((int) ($tenant['max_customers'] ?? 0)) > 0
                        ? number_format((int) $tenant['max_customers']) . ' allowed'
                        : 'unlimited')
                );
                echo admin_meter(
                    'Ratings this month',
                    $usage_ratings_month,
                    (int) ($tenant['max_ratings'] ?? 0),
                    'Resets on the 1st of every month'
                );
                ?>

                <div class="admin-stat-strip">
                    <div><span>Lifetime responses</span><strong><?php echo sa_e(sa_num($usage_ratings_total)); ?></strong></div>
                    <div><span>Plan price</span><strong><?php echo sa_e(sa_money($current_price)); ?></strong></div>
                    <div><span>Billing cycle</span><strong>Monthly</strong></div>
                </div>

                <?php
                $ratings_limit = (int) ($tenant['max_ratings'] ?? 0);
                $companies_limit = (int) ($tenant['max_customers'] ?? 0);
                $near_limit = ($ratings_limit > 0 && $usage_ratings_month >= $ratings_limit * 0.8)
                    || ($companies_limit > 0 && $usage_companies >= $companies_limit * 0.8);
                ?>
                <?php if ($near_limit): ?>
                    <div class="admin-insight is-warn" style="margin-top:14px;">
                        <span class="admin-insight-dot"></span>
                        <div>
                            <strong>You are close to your plan limits</strong>
                            <p>Upgrading now avoids responses being turned away at the busiest time of the month.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Plans -->
        <div class="form-card">
            <h3>Available plans</h3>
            <p class="muted" style="margin-bottom:20px;">
                Pick the plan you want — we activate it after a quick confirmation, and only bill the difference.
            </p>

            <div class="admin-plan-grid">
                <?php if ($plans): ?>
                    <?php foreach ($plans as $plan): ?>
                        <?php
                        $is_current = (int) $plan['id'] === $current_plan_id;
                        $is_upgrade = (float) $plan['price'] > $current_price;
                        $features = array_filter(array_map('trim', explode(',', (string) $plan['features'])));
                        ?>
                        <div class="admin-plan<?php echo $is_current ? ' is-current' : ''; ?><?php echo $is_upgrade ? ' is-upgrade' : ''; ?>">
                            <div class="admin-plan-head">
                                <strong><?php echo sa_e($plan['plan_name']); ?></strong>
                                <?php if ($is_current): ?>
                                    <?php echo admin_badge('Your plan', 'good'); ?>
                                <?php elseif ($is_upgrade): ?>
                                    <?php echo admin_badge('Upgrade', 'info'); ?>
                                <?php else: ?>
                                    <?php echo admin_badge('Downgrade', 'neutral'); ?>
                                <?php endif; ?>
                            </div>
                            <div class="admin-plan-price">
                                <?php echo sa_e(sa_money((float) $plan['price'])); ?><span>/ month</span>
                            </div>
                            <ul class="admin-plan-features">
                                <li><b><?php echo sa_e(sa_num((int) $plan['max_customers'])); ?></b> companies</li>
                                <li><b><?php echo sa_e(sa_num((int) $plan['max_ratings'])); ?></b> ratings per month</li>
                                <?php foreach (array_slice($features, 0, 5) as $feature): ?>
                                    <li><?php echo sa_e($feature); ?></li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if ($is_current): ?>
                                <button type="button" class="btn btn-secondary" disabled>Current plan</button>
                            <?php elseif ($pending_request): ?>
                                <button type="button" class="btn btn-secondary" disabled>Request pending</button>
                            <?php else: ?>
                                <form method="POST">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="plan_id" value="<?php echo (int) $plan['id']; ?>">
                                    <input type="hidden" name="note"
                                           value="<?php echo sa_e(($is_upgrade ? 'Upgrade' : 'Downgrade') . ' requested from the workspace'); ?>">
                                    <button type="submit" name="action" value="request_change"
                                            class="btn <?php echo $is_upgrade ? 'btn-primary' : 'btn-secondary'; ?>"
                                            onclick="return confirm('Request the <?php echo sa_e($plan['plan_name']); ?> plan?');">
                                        <?php echo $is_upgrade ? 'Upgrade to ' . sa_e($plan['plan_name']) : 'Switch to ' . sa_e($plan['plan_name']); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No plans are published yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Request history -->
        <div class="data-table-card">
            <div class="admin-card-head">
                <div>
                    <h3>Plan change history</h3>
                    <p class="muted">Every upgrade or downgrade you have asked for.</p>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Requested</th>
                            <th scope="col">From</th>
                            <th scope="col">To</th>
                            <th scope="col">Type</th>
                            <th scope="col">Status</th>
                            <th scope="col">Resolved</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($requests): ?>
                        <?php foreach ($requests as $row): ?>
                            <tr>
                                <td class="table-meta"><?php echo sa_e(date('M d, Y', strtotime((string) $row['created_at']))); ?></td>
                                <td><?php echo sa_e($row['current_plan_name'] ?? '—'); ?></td>
                                <td class="table-title"><?php echo sa_e($row['requested_plan_name'] ?? '—'); ?></td>
                                <td><?php echo sa_e(ucfirst((string) $row['direction'])); ?></td>
                                <td>
                                    <?php
                                    $tone = ['pending' => 'info', 'approved' => 'good', 'declined' => 'bad', 'cancelled' => 'neutral'];
                                    echo admin_badge(ucfirst((string) $row['status']), $tone[$row['status']] ?? 'neutral');
                                    ?>
                                </td>
                                <td class="table-meta"><?php echo !empty($row['resolved_at'])
                                    ? sa_e(date('M d, Y', strtotime((string) $row['resolved_at'])))
                                    : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="table-empty">No plan changes requested yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

<?php else: ?>

        <!-- Global administrator view -->
        <div class="form-card">
            <h3>Workspace subscriptions</h3>
            <p class="muted" style="margin-bottom:6px;">
                You are signed in as a platform administrator, so this page lists every workspace instead of one plan.
                Approvals and billing changes live in the super admin panel.
            </p>
        </div>

        <?php if (!empty($pending_all)): ?>
            <div class="form-card">
                <h3>Plan changes awaiting approval</h3>
                <?php foreach ($pending_all as $row): ?>
                    <div class="admin-insight is-info">
                        <span class="admin-insight-dot"></span>
                        <div>
                            <strong><?php echo sa_e($row['company_name'] ?? 'Workspace'); ?>
                                → <?php echo sa_e($row['requested_plan_name'] ?? 'new plan'); ?></strong>
                            <p><?php echo sa_e(ucfirst((string) $row['direction'])); ?> requested
                                <?php echo sa_e(date('M d, Y', strtotime((string) $row['created_at']))); ?>.</p>
                        </div>
                    </div>
                <?php endforeach; ?>
                <a class="btn btn-primary" href="<?php echo $BASE; ?>superadmin/subscriptions.php">Review in super admin</a>
            </div>
        <?php endif; ?>

        <div class="data-table-card">
            <div class="admin-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Workspace</th>
                            <th scope="col">Plan</th>
                            <th scope="col">Price</th>
                            <th scope="col">Status</th>
                            <th scope="col">Renews</th>
                            <th scope="col">Auto-renew</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($all_tenants)): ?>
                        <?php foreach ($all_tenants as $row): ?>
                            <tr>
                                <td class="table-title"><?php echo sa_e($row['company_name']); ?></td>
                                <td><?php echo sa_e($row['plan_name'] ?? '—'); ?></td>
                                <td><?php echo sa_e(sa_money((float) $row['subscription_price'])); ?></td>
                                <td><?php echo admin_badge(ucfirst((string) $row['subscription_status']),
                                    admin_status_tone($row['subscription_status'])); ?></td>
                                <td class="table-meta"><?php echo !empty($row['subscription_end_date'])
                                    ? sa_e(date('M d, Y', strtotime((string) $row['subscription_end_date'])))
                                    : '—'; ?></td>
                                <td><?php echo !empty($row['auto_renew']) ? 'On' : 'Off'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="table-empty">No workspaces yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

<?php endif; ?>
<?php include __DIR__ . '/_shell_footer.php'; ?>
