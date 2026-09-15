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
requireTeamAccess('subscription');

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

/* ------------------------------------------------------------
   Billing: invoices, the payment ledger and the checkout options
   the platform owner has switched on.
   ------------------------------------------------------------ */
require_once dirname(__DIR__) . '/includes/payments.php';
pay_ensure_schema($conn);

$billing_invoices  = [];
$billing_payments  = [];
$billing_gateways  = [];
$billing_due       = [];
$billing_awaiting  = 0;

if ($is_tenant && $tenant_id) {
    $billing_invoices = admin_rows(
        $conn,
        "SELECT i.*, p.plan_name
           FROM payment_invoices i
           LEFT JOIN subscription_plans p ON p.id = i.plan_id
          WHERE i.tenant_id = " . (int) $tenant_id . "
          ORDER BY FIELD(i.status, 'processing', 'overdue', 'open', 'paid', 'draft', 'cancelled', 'refunded'),
                   i.id DESC
          LIMIT 20"
    );

    $billing_payments = admin_rows(
        $conn,
        "SELECT sp.*, i.invoice_number
           FROM subscription_payments sp
           LEFT JOIN payment_invoices i ON i.id = sp.invoice_id
          WHERE sp.tenant_id = " . (int) $tenant_id . "
          ORDER BY sp.created_at DESC, sp.id DESC
          LIMIT 10"
    );

    $billing_gateways = pay_enabled_gateways($conn);

    foreach ($billing_invoices as $inv) {
        if (in_array($inv['status'], ['paid', 'cancelled', 'refunded'], true)) {
            continue;
        }
        $billing_due[] = $inv;
        if ($inv['status'] === 'processing') {
            $billing_awaiting++;
        }
    }
}

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

        <?php
        // Check for subscription expiration or cancellation
        $status_param = isset($_GET['status']) ? $_GET['status'] : '';
        $show_expiration_overlay = false;
        $expiration_message = '';
        $expiration_title = '';

        if ($status_param === 'subscription_expired' || ($tenant && !empty($tenant['subscription_end_date']) && $tenant['subscription_end_date'] < date('Y-m-d'))) {
            $show_expiration_overlay = true;
            $status_text = $tenant['subscription_status'] === 'trial' ? 'Trial' : 'Subscription';
            $expiration_title = 'Your ' . $status_text . ' Has Expired';
            $expiration_message = 'Your workspace access is restricted. Please upgrade to a paid plan to continue using the platform.';
        } elseif ($status_param === 'subscription_cancelled' || ($tenant && ($tenant['subscription_status'] === 'cancelled' || $tenant['subscription_status'] === 'inactive'))) {
            $show_expiration_overlay = true;
            $expiration_title = 'Subscription ' . ucfirst($tenant['subscription_status']);
            $expiration_message = 'Your workspace is currently ' . $tenant['subscription_status'] . '. Please contact support or choose a plan below to reactivate your account.';
        }
        ?>

        <?php if ($show_expiration_overlay): ?>
        <!-- Subscription Expired Overlay -->
        <div class="subscription-expired-overlay" id="subscriptionExpiredOverlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999;">
            <!-- Background layer with blur effect -->
            <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 36, 56, 0.98);"></div>
            
            <!-- Content layer (no blur) -->
            <div style="position: relative; z-index: 10000; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px;">
                <div style="max-width: 560px; width: 100%; background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.05)); border: 2px solid rgba(239, 68, 68, 0.4); border-radius: 20px; padding: 48px 40px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.5);">
                    <!-- Lock Icon -->
                    <div style="width: 80px; height: 80px; margin: 0 auto 24px; background: rgba(239, 68, 68, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid rgba(239, 68, 68, 0.4);">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </div>

                    <!-- Title -->
                    <h2 style="color: #ef4444; font-size: 28px; font-weight: 700; margin: 0 0 16px; line-height: 1.2;">
                        <?php echo sa_e($expiration_title); ?>
                    </h2>

                    <!-- Message -->
                    <p style="color: #cbd5e1; font-size: 16px; line-height: 1.6; margin: 0 0 32px;">
                        <?php echo sa_e($expiration_message); ?>
                    </p>

                    <!-- End Date Info -->
                    <?php if (!empty($tenant['subscription_end_date'])): ?>
                    <div style="background: rgba(0,0,0,0.3); padding: 12px 20px; border-radius: 10px; margin-bottom: 32px; border: 1px solid rgba(255,255,255,0.1);">
                        <div style="color: #94a3b8; font-size: 13px; margin-bottom: 4px;">Expired On</div>
                        <div style="color: #e2e8f0; font-size: 16px; font-weight: 600;">
                            <?php echo sa_e(date('F d, Y', strtotime($tenant['subscription_end_date']))); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
                        <a href="/rate/pricing.php" style="width: 100%; background: #ef4444; color: white; padding: 14px 24px; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); text-decoration: none; display: inline-block;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                            <span style="display: inline-block; margin-right: 8px;">📋</span>
                            View Plans & Upgrade
                        </a>

                        <?php if ($tenant && !empty($tenant['email'])): ?>
                        <a href="mailto:<?php echo sa_e(sa_setting($conn, 'support_email', 'support@optibiz.com')); ?>?subject=Subscription%20Renewal%20-%20<?php echo urlencode($tenant['company_name'] ?? ''); ?>" style="width: 100%; background: rgba(255,255,255,0.1); color: #cbd5e1; padding: 14px 24px; border: 1px solid rgba(255,255,255,0.2); border-radius: 10px; font-size: 16px; font-weight: 600; text-decoration: none; display: inline-block; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.15)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                            <span style="display: inline-block; margin-right: 8px;">💬</span>
                            Contact Support
                        </a>
                        <?php endif; ?>

                        <a href="logout.php?t=<?php echo rawurlencode(auth_logout_token()); ?>" style="width: 100%; background: transparent; color: #94a3b8; padding: 14px 24px; border: 1px solid rgba(148, 163, 184, 0.3); border-radius: 10px; font-size: 15px; font-weight: 500; text-decoration: none; display: inline-block; transition: all 0.2s;" onmouseover="this.style.borderColor='rgba(148, 163, 184, 0.5)'; this.style.color='#cbd5e1'" onmouseout="this.style.borderColor='rgba(148, 163, 184, 0.3)'; this.style.color='#94a3b8'">
                            <span style="display: inline-block; margin-right: 8px;">←</span>
                            Return to Login Page
                        </a>
                    </div>

                    <!-- Help Text -->
                    <p style="color: #64748b; font-size: 13px; line-height: 1.5; margin: 0;">
                        Need help? Contact us at 
                        <a href="mailto:<?php echo sa_e(sa_setting($conn, 'support_email', 'support@optibiz.com')); ?>" style="color: #c2f542; text-decoration: none;">
                            <?php echo sa_e(sa_setting($conn, 'support_email', 'support@optibiz.com')); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>

        <!-- Blur the main content when overlay is active -->
        <style>
            <?php if ($show_expiration_overlay): ?>
            /* Blur only the page content, not the overlay */
            .admin-sidebar,
            .admin-topbar,
            .dashboard-content > *:not(script):not(style),
            .page-header,
            .grid-2col,
            .form-card,
            .data-table-card,
            .admin-pending-banner {
                filter: blur(8px) !important;
                pointer-events: none !important;
                user-select: none !important;
            }
            
            /* Ensure overlay and its contents are clickable and clear */
            .subscription-expired-overlay,
            .subscription-expired-overlay *,
            #subscriptionExpiredOverlay,
            #subscriptionExpiredOverlay * {
                filter: none !important;
                pointer-events: auto !important;
            }
            
            /* Specifically make buttons and links clickable */
            .subscription-expired-overlay a,
            .subscription-expired-overlay button,
            #subscriptionExpiredOverlay a,
            #subscriptionExpiredOverlay button {
                pointer-events: auto !important;
                cursor: pointer !important;
            }
            <?php endif; ?>
        </style>
        <style>
            .price-monthly {
                font-size: 28px;
                font-weight: 800;
                color: var(--primary-dark);
            }
            .price-monthly span {
                font-size: 14px;
                font-weight: 500;
                color: var(--text-muted);
            }
            .price-annual {
                display: block;
                margin-top: 8px;
                padding: 8px 12px;
                background: var(--accent-green-bg);
                border-radius: 8px;
                font-size: 13px;
                color: var(--accent-green-text);
                font-weight: 600;
            }
            .annual-label {
                font-weight: 700;
                margin-right: 4px;
            }
            .annual-price {
                font-weight: 800;
                margin: 0 4px;
            }
            .annual-savings {
                color: #15803d;
                font-weight: 700;
            }
        </style>
        <?php endif; ?>

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
                        <?php 
                            $plan_discount = (int)($tenant['annual_discount_percent'] ?? 0);
                            $annual_price = $plan_discount > 0 ? round($current_price * (1 - $plan_discount / 100), 2) : round($current_price * 0.8, 2);
                            $annual_total = round($annual_price * 12, 2);
                            $savings = round($current_price * 12 - $annual_total, 2);
                        ?>
                        <span class="price-monthly"><?php echo sa_e(sa_money($current_price)); ?><span>/ month</span></span>
                        <?php if ($plan_discount > 0): ?>
                        <span class="price-annual">
                            <span class="annual-label">Annual:</span>
                            <span class="annual-price"><?php echo sa_e(sa_money($annual_total)); ?>/yr</span>
                            <span class="annual-savings">Save <?php echo sa_e(sa_money($savings)); ?></span>
                        </span>
                        <?php endif; ?>
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

        <!-- Billing & invoices -->
        <div class="form-card" id="billing">
            <h3>Billing &amp; invoices</h3>
            <p class="muted" style="margin-bottom:18px;">
                Pay an invoice online, or settle it by bank transfer and tell us it is on the way.
                Your plan updates as soon as the payment is confirmed.
            </p>

            <?php if ($billing_awaiting > 0): ?>
            <div class="alert alert-success" style="margin-bottom:16px;">
                We have <?php echo (int) $billing_awaiting; ?> payment<?php echo $billing_awaiting === 1 ? '' : 's'; ?>
                waiting to be confirmed — no need to pay again.
            </div>
            <?php endif; ?>

            <?php if (!$billing_invoices): ?>
                <p class="muted">No invoices yet. Your next renewal will appear here.</p>
            <?php else: ?>
            <div class="admin-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Invoice</th>
                            <th scope="col">For</th>
                            <th scope="col">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col">Due</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($billing_invoices as $inv): ?>
                        <tr>
                            <td>
                                <strong><?php echo sa_e($inv['invoice_number']); ?></strong>
                                <div class="table-meta"><?php echo sa_e(sa_date($inv['created_at'])); ?></div>
                            </td>
                            <td>
                                <?php echo sa_e($inv['subject']); ?>
                                <div class="table-meta"><?php echo (int) $inv['months']; ?> month<?php echo (int) $inv['months'] === 1 ? '' : 's'; ?></div>
                            </td>
                            <td><?php echo sa_e(sa_money($inv['total'])); ?></td>
                            <td><?php echo sa_e(pay_status_label($inv['status'])); ?></td>
                            <td><?php echo sa_e(sa_date($inv['due_date'], 'M j, Y', '—')); ?></td>
                            <td>
                                <a class="btn btn-secondary" href="invoice_view.php?id=<?php echo (int) $inv['id']; ?>">View</a>
                                <?php if (!in_array($inv['status'], ['paid', 'cancelled', 'refunded'], true)): ?>
                                    <?php if ($billing_gateways): ?>
                                        <a class="btn btn-primary" href="payment_checkout.php?invoice=<?php echo (int) $inv['id']; ?>">Pay</a>
                                    <?php else: ?>
                                        <span class="muted">Contact us to pay</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($billing_payments): ?>
            <h4 style="margin:22px 0 10px;">Recent payments</h4>
            <div class="admin-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Receipt</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Method</th>
                            <th scope="col">Status</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($billing_payments as $pay): ?>
                        <tr>
                            <td><strong><?php echo sa_e($pay['receipt_number']); ?></strong>
                                <?php if (!empty($pay['invoice_number'])): ?>
                                <div class="table-meta"><?php echo sa_e($pay['invoice_number']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo sa_e(pay_amount($pay['amount'], $pay['currency'])); ?></td>
                            <td><?php echo sa_e($pay['payment_method']); ?></td>
                            <td><?php echo sa_e(pay_status_label($pay['status'])); ?></td>
                            <td><?php echo sa_e(sa_date($pay['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
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
                                <?php 
                                    $plan_discount = (int)($plan['annual_discount_percent'] ?? 0);
                                    $annual_price = $plan_discount > 0 ? round((float)$plan['price'] * (1 - $plan_discount / 100), 2) : round((float)$plan['price'] * 0.8, 2);
                                    $annual_total = round($annual_price * 12, 2);
                                    $savings = round((float)$plan['price'] * 12 - $annual_total, 2);
                                ?>
                                <span class="price-monthly"><?php echo sa_e(sa_money((float) $plan['price'])); ?><span>/ month</span></span>
                                <?php if ($plan_discount > 0): ?>
                                <span class="price-annual">
                                    <span class="annual-label">Annual:</span>
                                    <span class="annual-price"><?php echo sa_e(sa_money($annual_total)); ?>/yr</span>
                                    <span class="annual-savings">Save <?php echo sa_e(sa_money($savings)); ?></span>
                                </span>
                                <?php endif; ?>
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
