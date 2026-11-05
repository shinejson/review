<?php
/**
 * ============================================================
 *  Super Admin — Subscriptions Command Center
 * ============================================================
 *  Comprehensive billing overview across all 4 subscription streams:
 *  1) Workspace SaaS Platform Plans (tenants)
 *  2) Premium Company Profiles (premium_subscriptions)
 *  3) Review Widgets Pro/Agency (widget_configs)
 *  4) Sponsored & Featured Category Listings (sponsored_listings)
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/monetization.php';
require_once dirname(__DIR__) . '/includes/subscription_chart_component.php';

requireSuperAdminLogin();
require_sa_permission('subscriptions');

admin_ensure_schema($conn);
monetization_ensure_schema($conn);

/* ---------- Active Stream Selection ---------- */
$stream = isset($_GET['stream']) ? preg_replace('/[^a-z_]/', '', strtolower($_GET['stream'])) : 'plans';
$allowed_streams = ['plans', 'premium', 'widgets', 'sponsored', 'all'];
if (!in_array($stream, $allowed_streams, true)) {
    $stream = 'plans';
}

/* ---------- POST handlers ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('subscriptions.php?stream=' . urlencode($stream));
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = (int) ($_POST['tenant_id'] ?? 0);

    /* ---- 1. Workspace Plan Change Requests ---- */
    if ($action === 'approve_request' || $action === 'decline_request') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $request = admin_row(
            $conn,
            "SELECT * FROM subscription_requests WHERE id = " . $request_id . " AND status = 'pending' LIMIT 1"
        );
        if (!$request) {
            sa_flash('error', 'That request has already been handled.');
            redirect('subscriptions.php?stream=plans');
        }

        if ($action === 'decline_request') {
            $stmt = $conn->prepare("UPDATE subscription_requests SET status = 'declined', resolved_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Plan change declined.');
            redirect('subscriptions.php?stream=plans');
        }

        $plan = admin_row(
            $conn,
            "SELECT * FROM subscription_plans WHERE id = " . (int) $request['requested_plan_id'] . " LIMIT 1"
        );
        if (!$plan) {
            sa_flash('error', 'The requested plan no longer exists.');
            redirect('subscriptions.php?stream=plans');
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
        redirect('subscriptions.php?stream=plans');
    }

    /* ---- 2. Quick Edit Workspace Subscription ---- */
    if ($action === 'update_subscription') {
        $tenant_id = (int) ($_POST['tenant_id'] ?? 0);
        $plan_id = (int) ($_POST['plan_id'] ?? 0);
        $status = sanitize($_POST['subscription_status'] ?? 'active');
        $price = round((float) ($_POST['subscription_price'] ?? 0), 2);
        $start_date = sanitize($_POST['subscription_start_date'] ?? '');
        $end_date = sanitize($_POST['subscription_end_date'] ?? '');
        $auto_renew = !empty($_POST['auto_renew']) ? 1 : 0;
        
        if (!$tenant_id) {
            sa_flash('error', 'Select a tenant.');
            redirect('subscriptions.php?stream=plans');
        }
        
        $plan = admin_row($conn, "SELECT * FROM subscription_plans WHERE id = " . $plan_id . " AND status = 'active' LIMIT 1");
        if ($plan_id > 0 && !$plan) {
            sa_flash('error', 'The selected plan is not available.');
            redirect('subscriptions.php?stream=plans');
        }
        
        if (!in_array($status, ['trial', 'active', 'inactive', 'cancelled'])) {
            sa_flash('error', 'Invalid subscription status.');
            redirect('subscriptions.php?stream=plans');
        }
        
        $tenant = admin_row($conn, "SELECT * FROM tenants WHERE id = " . $tenant_id . " LIMIT 1");
        if (!$tenant) {
            sa_flash('error', 'Tenant not found.');
            redirect('subscriptions.php?stream=plans');
        }
        
        $old_plan_id = (int) $tenant['plan_id'];
        $old_price = (float) $tenant['subscription_price'];
        $old_status = $tenant['subscription_status'];
        
        $stmt = $conn->prepare("
            UPDATE tenants 
            SET plan_id = ?, subscription_status = ?, subscription_price = ?,
                subscription_start_date = ?, subscription_end_date = ?,
                auto_renew = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            "isdssii",
            $plan_id,
            $status,
            $price,
            $start_date ?: null,
            $end_date ?: null,
            $auto_renew,
            $tenant_id
        );
        
        if ($stmt->execute()) {
            $stmt->close();
            sa_log_activity($conn, 'superadmin', $_SESSION['super_admin_id'] ?? null, 'subscription_update', 
                "Updated subscription for {$tenant['company_name']}: plan #{$old_plan_id}→#{$plan_id}, status {$old_status}→{$status}, price {$old_price}→{$price}");
            
            if ($old_plan_id !== $plan_id && $plan_id > 0) {
                $direction = (float) $plan['price'] > $old_price ? 'upgrade' : 'downgrade';
                $conn->query("
                    INSERT INTO subscription_requests 
                    (tenant_id, current_plan_id, requested_plan_id, direction, status, created_at, resolved_at)
                    VALUES ({$tenant_id}, {$old_plan_id}, {$plan_id}, '{$direction}', 'approved', NOW(), NOW())
                ");
            }
            sa_flash('success', "Subscription updated for {$tenant['company_name']}.");
        } else {
            $stmt->close();
            sa_flash('error', 'Could not update the subscription.');
        }
        redirect('subscriptions.php?stream=plans');
    }

    /* ---- 3. Quick Status Update for Workspace ---- */
    if ($action === 'update_status' && $id) {
        $status = in_array($_POST['status'] ?? '', ['trial', 'active', 'inactive', 'cancelled'], true) ? $_POST['status'] : '';
        if ($status) {
            $stmt = $conn->prepare("UPDATE tenants SET subscription_status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Subscription marked ' . $status . '.');
        }
        redirect('subscriptions.php?stream=plans');
    }

    /* ---- 4. Auto-Renew Toggle for Workspace ---- */
    if ($action === 'auto_renew' && $id) {
        $ar = !empty($_POST['auto_renew']) ? 1 : 0;
        $conn->query("UPDATE tenants SET auto_renew = " . $ar . " WHERE id = " . $id);
        sa_flash('success', 'Auto-renew ' . ($ar ? 'enabled' : 'disabled') . '.');
        redirect('subscriptions.php?stream=plans');
    }

    /* ---- 5. Extend Workspace Subscription ---- */
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
        redirect('subscriptions.php?stream=plans');
    }

    /* ---- 6. Offline Payment Ledger ---- */
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
        redirect('subscriptions.php?stream=plans');
    }

    /* ---- 7. Premium Subscriptions Management ---- */
    if ($action === 'update_premium_status') {
        $sub_id = (int) ($_POST['sub_id'] ?? 0);
        $st = in_array($_POST['status'] ?? '', ['active', 'pending', 'expired', 'cancelled'], true) ? $_POST['status'] : '';
        if ($sub_id && $st) {
            $ps = sa_one($conn, "SELECT * FROM premium_subscriptions WHERE id = " . $sub_id . " LIMIT 1", 'premium_subscriptions');
            if ($ps) {
                $stmt = $conn->prepare("UPDATE premium_subscriptions SET status = ? WHERE id = ?");
                $stmt->bind_param("si", $st, $sub_id);
                $stmt->execute();
                $stmt->close();
                if ($st === 'active') {
                    $m_add = ($ps['billing_cycle'] ?? 'monthly') === 'yearly' ? 12 : 1;
                    @$conn->query("UPDATE premium_subscriptions SET starts_at = COALESCE(starts_at, NOW()), expires_at = DATE_ADD(NOW(), INTERVAL " . $m_add . " MONTH) WHERE id = " . $sub_id);
                    @$conn->query("UPDATE customers SET premium_status = 'active', premium_tier = '" . $conn->real_escape_string($ps['tier']) . "', premium_expires_at = DATE_ADD(NOW(), INTERVAL " . $m_add . " MONTH), is_claimed = 1, verified_badge = 1 WHERE id = " . (int)$ps['company_id']);
                } elseif ($st === 'expired' || $st === 'cancelled') {
                    @$conn->query("UPDATE customers SET premium_status = '" . $st . "' WHERE id = " . (int)$ps['company_id']);
                }
                sa_flash('success', 'Premium subscription #' . $sub_id . ' marked ' . $st . '.');
            }
        }
        redirect('subscriptions.php?stream=premium');
    }

    if ($action === 'extend_premium') {
        $sub_id = (int) ($_POST['sub_id'] ?? 0);
        $ext_m = max(1, min(60, (int) ($_POST['extend_months'] ?? 1)));
        if ($sub_id) {
            $ps = sa_one($conn, "SELECT * FROM premium_subscriptions WHERE id = " . $sub_id . " LIMIT 1", 'premium_subscriptions');
            if ($ps) {
                @$conn->query("UPDATE premium_subscriptions SET status = 'active', expires_at = DATE_ADD(COALESCE(expires_at, NOW()), INTERVAL " . $ext_m . " MONTH) WHERE id = " . $sub_id);
                @$conn->query("UPDATE customers SET premium_status = 'active', premium_expires_at = DATE_ADD(COALESCE(premium_expires_at, NOW()), INTERVAL " . $ext_m . " MONTH) WHERE id = " . (int)$ps['company_id']);
                sa_flash('success', 'Premium subscription extended by ' . $ext_m . ' month' . ($ext_m === 1 ? '' : 's') . '.');
            }
        }
        redirect('subscriptions.php?stream=premium');
    }

    /* ---- 8. Review Widget Subscriptions Management ---- */
    if ($action === 'update_widget_status') {
        $wid = (int) ($_POST['widget_id'] ?? 0);
        $w_st = in_array($_POST['status'] ?? '', ['active', 'pending', 'suspended', 'expired'], true) ? $_POST['status'] : '';
        if ($wid && $w_st) {
            $stmt = $conn->prepare("UPDATE widget_configs SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $w_st, $wid);
            $stmt->execute();
            $stmt->close();
            if ($w_st === 'active') {
                @$conn->query("UPDATE widget_configs SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = " . $wid . " AND (expires_at IS NULL OR expires_at < NOW())");
            }
            sa_flash('success', 'Review widget #' . $wid . ' marked ' . $w_st . '.');
        }
        redirect('subscriptions.php?stream=widgets');
    }

    if ($action === 'update_widget_tier') {
        $wid = (int) ($_POST['widget_id'] ?? 0);
        $tier = in_array($_POST['tier'] ?? '', ['free', 'pro', 'agency'], true) ? $_POST['tier'] : 'pro';
        if ($wid) {
            $stmt = $conn->prepare("UPDATE widget_configs SET tier = ? WHERE id = ?");
            $stmt->bind_param("si", $tier, $wid);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Review widget #' . $wid . ' tier changed to ' . ucfirst($tier) . '.');
        }
        redirect('subscriptions.php?stream=widgets');
    }

    if ($action === 'extend_widget') {
        $wid = (int) ($_POST['widget_id'] ?? 0);
        $days = max(1, min(365, (int) ($_POST['extend_days'] ?? 30)));
        if ($wid) {
            @$conn->query("UPDATE widget_configs SET status = 'active', expires_at = DATE_ADD(COALESCE(expires_at, NOW()), INTERVAL " . $days . " DAY) WHERE id = " . $wid);
            sa_flash('success', 'Widget subscription extended by ' . $days . ' days.');
        }
        redirect('subscriptions.php?stream=widgets');
    }

    /* ---- 9. Sponsored & Featured Placements Management ---- */
    if ($action === 'update_sponsored_status') {
        $sid = (int) ($_POST['listing_id'] ?? 0);
        $s_st = in_array($_POST['status'] ?? '', ['active', 'pending', 'expired', 'rejected', 'cancelled'], true) ? $_POST['status'] : '';
        if ($sid && $s_st) {
            if ($s_st === 'active') {
                $row = sa_one($conn, "SELECT * FROM sponsored_listings WHERE id = " . $sid . " LIMIT 1", 'sponsored_listings');
                $dur = max(7, (int) ($row['duration_days'] ?? 30));
                @$conn->query("UPDATE sponsored_listings SET status = 'active', starts_at = COALESCE(starts_at, NOW()), ends_at = DATE_ADD(NOW(), INTERVAL " . $dur . " DAY) WHERE id = " . $sid);
            } elseif ($s_st === 'expired') {
                @$conn->query("UPDATE sponsored_listings SET status = 'expired', ends_at = NOW() WHERE id = " . $sid);
            } else {
                $stmt = $conn->prepare("UPDATE sponsored_listings SET status = ? WHERE id = ?");
                $stmt->bind_param("si", $s_st, $sid);
                $stmt->execute();
                $stmt->close();
            }
            sa_flash('success', 'Sponsored listing #' . $sid . ' marked ' . $s_st . '.');
        }
        redirect('subscriptions.php?stream=sponsored');
    }

    if ($action === 'extend_sponsored') {
        $sid = (int) ($_POST['listing_id'] ?? 0);
        $days = max(1, min(365, (int) ($_POST['extend_days'] ?? 30)));
        if ($sid) {
            @$conn->query("UPDATE sponsored_listings SET status = 'active', ends_at = DATE_ADD(COALESCE(ends_at, NOW()), INTERVAL " . $days . " DAY) WHERE id = " . $sid);
            sa_flash('success', 'Sponsored placement extended by ' . $days . ' days.');
        }
        redirect('subscriptions.php?stream=sponsored');
    }
}

/* ---------- 1-Click Server CSV Export (Stream-Aware) ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    
    if ($stream === 'premium') {
        header('Content-Disposition: attachment; filename=premium-subscriptions-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Company Name', 'Tenant', 'Tier', 'Price', 'Billing Cycle', 'Status', 'Invoice ID', 'Starts At', 'Expires At', 'Created At']);
        $p_rows = sa_query($conn, "SELECT ps.*, c.company_name, t.company_name as tenant_name FROM premium_subscriptions ps JOIN customers c ON c.id=ps.company_id JOIN tenants t ON t.id=ps.tenant_id ORDER BY ps.created_at DESC", 'premium_subscriptions');
        foreach ($p_rows as $pr) {
            fputcsv($out, [$pr['id'], $pr['company_name'], $pr['tenant_name'], $pr['tier'], $pr['price'], $pr['billing_cycle'], $pr['status'], $pr['invoice_id'], $pr['starts_at'], $pr['expires_at'], $pr['created_at']]);
        }
        fclose($out);
        exit();
    } elseif ($stream === 'widgets') {
        header('Content-Disposition: attachment; filename=widget-subscriptions-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Tenant', 'Company', 'Tier', 'Status', 'Theme', 'Layout', 'Views', 'Clicks', 'Expires At', 'Created At']);
        $w_rows = sa_query($conn, "SELECT wc.*, t.company_name as tenant_name, c.company_name FROM widget_configs wc JOIN tenants t ON t.id=wc.tenant_id LEFT JOIN customers c ON c.id=wc.company_id ORDER BY wc.created_at DESC", 'widget_configs');
        foreach ($w_rows as $wr) {
            fputcsv($out, [$wr['id'], $wr['tenant_name'], $wr['company_name'] ?: 'N/A', $wr['tier'], $wr['status'], $wr['theme'], $wr['layout'], $wr['views_total'], $wr['clicks_total'], $wr['expires_at'], $wr['created_at']]);
        }
        fclose($out);
        exit();
    } elseif ($stream === 'sponsored') {
        header('Content-Disposition: attachment; filename=sponsored-subscriptions-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Company', 'Category', 'Tenant', 'Tier', 'Price', 'Status', 'Impressions', 'Clicks', 'Starts At', 'Ends At', 'Created At']);
        $s_rows = sa_query($conn, "SELECT sl.*, c.company_name, t.company_name as tenant_name, cat.name as cat_name FROM sponsored_listings sl JOIN customers c ON c.id=sl.company_id JOIN tenants t ON t.id=sl.tenant_id LEFT JOIN categories cat ON cat.id=sl.category_id ORDER BY sl.created_at DESC", 'sponsored_listings');
        foreach ($s_rows as $sr) {
            fputcsv($out, [$sr['id'], $sr['company_name'], $sr['cat_name'] ?: 'General', $sr['tenant_name'], $sr['tier'], $sr['price'], $sr['status'], $sr['impressions'], $sr['clicks'], $sr['starts_at'], $sr['ends_at'], $sr['created_at']]);
        }
        fclose($out);
        exit();
    } else {
        header('Content-Disposition: attachment; filename=workspace-subscriptions-' . date('Y-m-d') . '.csv');
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
}

/* ---------- Global Metrics & Counts ---------- */
$m = sa_metrics($conn);
$counts = sa_tenant_counts($conn);
$stats = monetization_stats($conn);

$total_premium_subs = (int) sa_scalar($conn, "SELECT COUNT(*) FROM premium_subscriptions", 0, 'premium_subscriptions');
$total_widget_subs  = (int) sa_scalar($conn, "SELECT COUNT(*) FROM widget_configs", 0, 'widget_configs');
$total_spons_subs   = (int) sa_scalar($conn, "SELECT COUNT(*) FROM sponsored_listings", 0, 'sponsored_listings');

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

/* ---------- Stream Queries & Sub-filters ---------- */
$filter = isset($_GET['view']) ? preg_replace('/[^a-z_]/', '', strtolower($_GET['view'])) : 'all';

// 1. Stream: Workspace Plans
if ($stream === 'plans') {
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
    $plan_rows = sa_query(
        $conn,
        "SELECT t.*, p.plan_name, p.price AS plan_price,
                (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = t.id) AS customer_count
           FROM tenants t
           LEFT JOIN subscription_plans p ON p.id = t.plan_id"
        . $where . "
          ORDER BY (t.subscription_end_date IS NULL), t.subscription_end_date ASC, t.company_name ASC",
        ['tenants', 'subscription_plans', 'customers']
    );
    $mrr_visible = 0.0;
    foreach ($plan_rows as $r) {
        if ($r['subscription_status'] === 'active') {
            $mrr_visible += (float) $r['subscription_price'];
        }
    }
}

// 2. Stream: Premium Profiles
if ($stream === 'premium' || $stream === 'all') {
    $prem_allowed = ['all', 'active', 'pending', 'due', 'expired', 'cancelled'];
    $prem_filter = in_array($filter, $prem_allowed, true) ? $filter : 'all';
    $p_where = "";
    if ($prem_filter === 'active') {
        $p_where = " WHERE ps.status = 'active'";
    } elseif ($prem_filter === 'pending') {
        $p_where = " WHERE ps.status = 'pending'";
    } elseif ($prem_filter === 'due') {
        $p_where = " WHERE ps.status = 'active' AND ps.expires_at IS NOT NULL AND ps.expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($prem_filter === 'expired') {
        $p_where = " WHERE ps.status = 'expired' OR (ps.expires_at IS NOT NULL AND ps.expires_at < NOW())";
    } elseif ($prem_filter === 'cancelled') {
        $p_where = " WHERE ps.status = 'cancelled'";
    }
    $premium_rows = sa_query(
        $conn,
        "SELECT ps.*, 
                c.company_name, c.slug, c.logo_url, c.verified_badge,
                t.company_name AS tenant_name, t.email AS tenant_email,
                pi.invoice_number, pi.status AS invoice_status, pi.amount AS invoice_amount
           FROM premium_subscriptions ps
           JOIN customers c ON c.id = ps.company_id
           JOIN tenants t ON t.id = ps.tenant_id
           LEFT JOIN payment_invoices pi ON pi.id = ps.invoice_id"
        . $p_where . "
          ORDER BY ps.created_at DESC LIMIT 100",
        ['premium_subscriptions', 'customers', 'tenants', 'payment_invoices']
    );
    $premium_mrr_visible = 0.0;
    foreach ($premium_rows as $pr) {
        if ($pr['status'] === 'active') {
            $price = (float)$pr['price'];
            $premium_mrr_visible += ($pr['billing_cycle'] === 'yearly' ? ($price / 12) : $price);
        }
    }
}

// 3. Stream: Review Widgets
if ($stream === 'widgets' || $stream === 'all') {
    $w_allowed = ['all', 'paid', 'active', 'pending', 'suspended', 'expired'];
    $w_filter = in_array($filter, $w_allowed, true) ? $filter : 'all';
    $w_where = "";
    if ($w_filter === 'paid') {
        $w_where = " WHERE wc.tier IN ('pro','agency')";
    } elseif ($w_filter === 'active') {
        $w_where = " WHERE wc.status = 'active'";
    } elseif ($w_filter === 'pending') {
        $w_where = " WHERE wc.status = 'pending'";
    } elseif ($w_filter === 'suspended') {
        $w_where = " WHERE wc.status = 'suspended'";
    } elseif ($w_filter === 'expired') {
        $w_where = " WHERE wc.status = 'expired'";
    }
    $widget_rows = sa_query(
        $conn,
        "SELECT wc.*, 
                c.company_name, c.slug,
                t.company_name AS tenant_name, t.email AS tenant_email,
                pi.invoice_number, pi.status AS invoice_status, pi.amount AS invoice_amount
           FROM widget_configs wc
           JOIN tenants t ON t.id = wc.tenant_id
           LEFT JOIN customers c ON c.id = wc.company_id
           LEFT JOIN payment_invoices pi ON pi.id = wc.invoice_id"
        . $w_where . "
          ORDER BY wc.created_at DESC LIMIT 100",
        ['widget_configs', 'tenants', 'customers', 'payment_invoices']
    );
    $widget_mrr_visible = 0.0;
    foreach ($widget_rows as $wr) {
        if ($wr['status'] === 'active' && in_array($wr['tier'], ['pro','agency'], true)) {
            $widget_mrr_visible += ($wr['tier'] === 'agency' ? 149 : 79);
        }
    }
}

// 4. Stream: Sponsored & Featured
if ($stream === 'sponsored' || $stream === 'all') {
    $s_allowed = ['all', 'active', 'pending', 'featured', 'sponsored', 'expired'];
    $s_filter = in_array($filter, $s_allowed, true) ? $filter : 'all';
    $s_where = "";
    if ($s_filter === 'active') {
        $s_where = " WHERE sl.status = 'active' AND sl.ends_at > NOW()";
    } elseif ($s_filter === 'pending') {
        $s_where = " WHERE sl.status = 'pending'";
    } elseif ($s_filter === 'featured') {
        $s_where = " WHERE sl.tier = 'featured'";
    } elseif ($s_filter === 'sponsored') {
        $s_where = " WHERE sl.tier = 'sponsored'";
    } elseif ($s_filter === 'expired') {
        $s_where = " WHERE sl.status = 'expired' OR (sl.ends_at IS NOT NULL AND sl.ends_at <= NOW())";
    }
    $sponsored_rows = sa_query(
        $conn,
        "SELECT sl.*,
                c.company_name, c.slug,
                t.company_name AS tenant_name, t.email AS tenant_email,
                cat.name AS category_name,
                pi.invoice_number, pi.status AS invoice_status, pi.amount AS invoice_amount
           FROM sponsored_listings sl
           JOIN customers c ON c.id = sl.company_id
           JOIN tenants t ON t.id = sl.tenant_id
           LEFT JOIN categories cat ON cat.id = sl.category_id
           LEFT JOIN payment_invoices pi ON pi.id = sl.invoice_id"
        . $s_where . "
          ORDER BY sl.created_at DESC LIMIT 100",
        ['sponsored_listings', 'customers', 'tenants', 'categories', 'payment_invoices']
    );
    $spons_rev_visible = 0.0;
    foreach ($sponsored_rows as $sr) {
        if ($sr['status'] === 'active') {
            $spons_rev_visible += (float)$sr['price'];
        }
    }
}

/* Plan changes workspaces asked for */
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
      ORDER BY sp.created_at DESC LIMIT 10",
    ['subscription_payments', 'tenants']
);
$tenants_dropdown = sa_query($conn, "SELECT id, company_name, subscription_price FROM tenants ORDER BY company_name ASC", 'tenants');
$plans_dropdown = sa_query($conn, "SELECT id, plan_name, price FROM subscription_plans WHERE status = 'active' ORDER BY price ASC", 'subscription_plans');

/* ---------- Page Meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = 'Subscriptions & Monetization';
$pageHeading = 'Subscriptions &amp; Billing';
$pageSubtitle = 'Unified billing overview across workspace plans, premium profiles, review widgets, and category sponsorships.';
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
        <h2>Subscriptions &amp; Billing Command Center</h2>
        <p><strong><?php echo sa_e(sa_money($m['total_mrr'])); ?></strong> total recurring run-rate across 
           <strong><?php echo sa_e(sa_num($m['total_paying_subs'])); ?></strong> active subscriptions &amp; placements.</p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-ghost" data-sa-export="#subsTable" data-sa-export-name="optibiz-subscriptions" title="Export visible table">
            <?php echo sa_icon('download'); ?> View CSV
        </button>
        <a class="sa-btn sa-btn-ghost" href="subscriptions.php?stream=<?php echo urlencode($stream); ?>&amp;export=csv" title="Export stream CSV">
            <?php echo sa_icon('file-text'); ?> Export Stream CSV
        </a>
        <button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#recordPaymentDialog">
            <?php echo sa_icon('card'); ?> Record Offline Payment
        </button>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<!-- ============ BILLING & SUBSCRIPTION KPIS ============ -->
<div class="sa-grid sa-kpis sa-anim" style="margin-bottom:20px;">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">TOTAL PLATFORM MRR</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('dollar'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_money($m['total_mrr'])); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_money($m['total_arr'], 0)); ?> combined annualised run rate</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">WORKSPACE PLANS</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('trending-up'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_money($m['mrr'])); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_num($m['paying'])); ?> paying tenants · <?php echo sa_e(sa_num($due_count)); ?> due soon</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:#8b5cf6;--kpi-soft:rgba(139,92,246,0.1);--kpi-line:rgba(139,92,246,0.25)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">PREMIUM PROFILES</span>
            <span class="sa-kpi-icon">★</span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_money($m['premium_mrr'])); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_num($m['premium_active'])); ?> active verified · <?php echo sa_e(sa_num($stats['premium_pending'])); ?> pending</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:#f59e0b;--kpi-soft:rgba(245,158,11,0.1);--kpi-line:rgba(245,158,11,0.25)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">WIDGETS &amp; ADS</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('layers'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_money($m['widgets_mrr'] + $m['sponsored_revenue'])); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_num($m['widgets_active'])); ?> Pro/Agency widgets · <?php echo sa_e(sa_num($m['sponsored_active'])); ?> live sponsors</div>
    </article>
</div>

<!-- ============ STREAM SELECTOR TABS ============ -->
<div class="sa-card sa-mb" style="padding:14px 18px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="font-size:12px;font-weight:700;color:var(--sa-muted);text-transform:uppercase;letter-spacing:0.5px;margin-right:4px;">Subscription Stream:</span>
            
            <a class="sa-btn <?php echo $stream === 'plans' ? 'sa-btn-primary' : 'sa-btn-ghost'; ?>" href="subscriptions.php?stream=plans" style="font-size:12.5px;">
                🏢 Workspace Plans
                <span class="sa-badge" style="margin-left:6px;"><?php echo (int) $counts['all']; ?></span>
            </a>

            <a class="sa-btn <?php echo $stream === 'premium' ? 'sa-btn-primary' : 'sa-btn-ghost'; ?>" href="subscriptions.php?stream=premium" style="font-size:12.5px;">
                ⭐ Premium Profiles
                <span class="sa-badge" style="margin-left:6px;background:rgba(139,92,246,0.15);color:#7c3aed;"><?php echo (int) $total_premium_subs; ?></span>
            </a>

            <a class="sa-btn <?php echo $stream === 'widgets' ? 'sa-btn-primary' : 'sa-btn-ghost'; ?>" href="subscriptions.php?stream=widgets" style="font-size:12.5px;">
                🧩 Review Widgets
                <span class="sa-badge" style="margin-left:6px;background:rgba(59,130,246,0.15);color:#2563eb;"><?php echo (int) $total_widget_subs; ?></span>
            </a>

            <a class="sa-btn <?php echo $stream === 'sponsored' ? 'sa-btn-primary' : 'sa-btn-ghost'; ?>" href="subscriptions.php?stream=sponsored" style="font-size:12.5px;">
                🚀 Sponsored Listings
                <span class="sa-badge" style="margin-left:6px;background:rgba(245,158,11,0.15);color:#b45309;"><?php echo (int) $total_spons_subs; ?></span>
            </a>

            <a class="sa-btn <?php echo $stream === 'all' ? 'sa-btn-primary' : 'sa-btn-ghost'; ?>" href="subscriptions.php?stream=all" style="font-size:12.5px;">
                🌐 All Streams Overview
            </a>
        </div>
        <div style="font-size:12px;color:var(--sa-muted);">
            Switch streams to inspect, activate, extend or edit subscriptions
        </div>
    </div>
</div>

<!-- ============================================================
     STREAM 1: WORKSPACE SAAS PLANS
     ============================================================ -->
<?php if ($stream === 'plans'): ?>

    <?php if ($plan_requests): ?>
    <!-- Plan Change Requests -->
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

    <!-- Filter chips for Plans -->
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
                   href="subscriptions.php?stream=plans&amp;view=<?php echo $key; ?>"
                   aria-pressed="<?php echo $filter === $key ? 'true' : 'false'; ?>">
                    <?php echo sa_e($chip['label']); ?><span class="count"><?php echo (int) $chip['count']; ?></span>
                </a>
    <?php endforeach; ?>
            </div>
            <span class="sa-pill" style="margin-left:auto"><?php echo sa_e(sa_money($mrr_visible)); ?> MRR in this view</span>
        </div>
    </section>

    <!-- Workspace Subscriptions Table -->
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3><?php echo sa_e(ucfirst(str_replace('_', ' ', $filter))); ?> workspace subscriptions</h3>
                <p>Sorted by the closest renewal date first</p>
            </div>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table" id="subsTable" data-sa-sortable-table>
                <thead>
                    <tr>
                        <th data-sa-sort="0" scope="col">Tenant</th>
                        <th data-sa-sort="1" scope="col">Plan</th>
                        <th data-sa-sort="2" data-type="num" scope="col">Price / mo</th>
                        <th data-sa-sort="3" scope="col">Status</th>
                        <th data-sa-sort="4" data-type="date" scope="col">Started</th>
                        <th data-sa-sort="5" data-type="date" scope="col">Ends</th>
                        <th data-sa-sort="6" scope="col">Renewal</th>
                        <th data-sa-sort="7" scope="col">Auto-renew</th>
                        <th data-no-export scope="col"><span class="sa-sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
    <?php if (!$plan_rows): ?>
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
    <?php foreach ($plan_rows as $s): ?>
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
                            <form method="POST" action="subscriptions.php?stream=plans" style="display:inline">
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
                            <form method="POST" action="subscriptions.php?stream=plans" style="display:inline">
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
                                <form method="POST" action="subscriptions.php?stream=plans" style="display:inline">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="extend">
                                    <input type="hidden" name="tenant_id" value="<?php echo (int) $s['id']; ?>">
                                    <input type="hidden" name="extend_months" value="12">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="Extend 12 months and mark active">
                                        <?php echo sa_icon('refresh'); ?> +12 mo
                                    </button>
                                </form>
                                <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" 
                                        data-sa-open-dialog="#quickEditDialog"
                                        data-tenant-id="<?php echo (int) $s['id']; ?>"
                                        data-company="<?php echo sa_e($s['company_name']); ?>"
                                        data-plan-id="<?php echo (int) $s['plan_id']; ?>"
                                        data-status="<?php echo sa_e($s['subscription_status']); ?>"
                                        data-price="<?php echo (float) $s['subscription_price']; ?>"
                                        data-end-date="<?php echo sa_e($s['subscription_end_date']); ?>"
                                        data-auto-renew="<?php echo $s['auto_renew'] ? '1' : '0'; ?>"
                                        title="Quick edit subscription">
                                    <?php echo sa_icon('edit'); ?> Edit
                                </button>
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
        <div class="sa-card-foot">
            <span><?php echo sa_e(sa_num(count($plan_rows))); ?> subscription<?php echo count($plan_rows) === 1 ? '' : 's'; ?> shown</span>
            <span>Toggles and status selects save immediately</span>
        </div>
    </section>

<!-- ============================================================
     STREAM 2: PREMIUM PROFILES
     ============================================================ -->
<?php elseif ($stream === 'premium'): ?>

    <!-- Filter chips for Premium Profiles -->
    <section class="sa-card sa-mb">
        <div class="sa-filters">
            <div class="sa-chips">
    <?php
    $p_chips = [
        'all'       => ['label' => 'All', 'count' => $total_premium_subs],
        'active'    => ['label' => 'Active', 'count' => $stats['premium_active']],
        'pending'   => ['label' => 'Pending Approval/Payment', 'count' => $stats['premium_pending']],
        'due'       => ['label' => 'Expiring Soon (30d)', 'count' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM premium_subscriptions WHERE status='active' AND expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)", 0, 'premium_subscriptions')],
        'expired'   => ['label' => 'Expired', 'count' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM premium_subscriptions WHERE status='expired' OR (expires_at < NOW())", 0, 'premium_subscriptions')],
        'cancelled' => ['label' => 'Cancelled', 'count' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM premium_subscriptions WHERE status='cancelled'", 0, 'premium_subscriptions')],
    ];
    foreach ($p_chips as $key => $chip): ?>
                <a class="sa-chip<?php echo $filter === $key ? ' active' : ''; ?>"
                   href="subscriptions.php?stream=premium&amp;view=<?php echo $key; ?>">
                    <?php echo sa_e($chip['label']); ?><span class="count"><?php echo (int) $chip['count']; ?></span>
                </a>
    <?php endforeach; ?>
            </div>
            <span class="sa-pill" style="margin-left:auto"><?php echo sa_e(sa_money($premium_mrr_visible)); ?> MRR in this view</span>
        </div>
    </section>

    <!-- Premium Subscriptions Table -->
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Premium Company Profile Subscriptions</h3>
                <p>Purchased on <code>admin/premium_profiles.php</code> — unlocked verified badge, custom CTA, banner and ad-removal.</p>
            </div>
            <div class="sa-card-head-actions">
                <a href="premium_claims.php" class="sa-btn sa-btn-secondary sa-btn-sm">Manage Claims &rarr;</a>
            </div>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table" id="subsTable" data-sa-sortable-table>
                <thead>
                    <tr>
                        <th data-sa-sort="0">Company &amp; Tenant</th>
                        <th data-sa-sort="1">Tier</th>
                        <th data-sa-sort="2">Billing Cycle</th>
                        <th data-sa-sort="3" class="num">Price</th>
                        <th data-sa-sort="4">Status</th>
                        <th data-sa-sort="5">Invoice</th>
                        <th data-sa-sort="6" data-type="date">Expires</th>
                        <th data-no-export><span class="sa-sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
    <?php if (!$premium_rows): ?>
                    <tr data-static>
                        <td colspan="8">
                            <div class="sa-empty">
                                <?php echo sa_icon('card'); ?>
                                <strong>No premium profile subscriptions found in this view</strong>
                                <p>When a tenant subscribes on their Premium Profiles page, it appears here immediately.</p>
                            </div>
                        </td>
                    </tr>
    <?php else: ?>
    <?php foreach ($premium_rows as $pr): ?>
    <?php
        $search_blob = strtolower(implode(' ', [
            $pr['company_name'], $pr['tenant_name'], $pr['tenant_email'], $pr['tier'], $pr['status'], $pr['billing_cycle']
        ]));
        $days = sa_days_until($pr['expires_at']);
        $tier_style = $pr['tier'] === 'authority' ? 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;' : ($pr['tier'] === 'growth' ? 'background:#ede9fe;color:#6d28d9;border:1px solid #ddd6fe;' : 'background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;');
    ?>
                    <tr data-filterable data-search="<?php echo sa_e($search_blob); ?>">
                        <td>
                            <div class="sa-cell-main">
                                <span class="sa-cell-avatar" style="background:#8b5cf6;color:#fff;">★</span>
                                <div class="sa-cell-text">
                                    <strong>
                                        <a href="../company.php?slug=<?php echo urlencode($pr['slug'] ?? ''); ?>" target="_blank" style="text-decoration:none;color:inherit;">
                                            <?php echo sa_e($pr['company_name']); ?>
                                        </a>
                                        <?php if (!empty($pr['verified_badge'])): ?><span title="Verified" style="color:#0284c7;">✓</span><?php endif; ?>
                                    </strong>
                                    <span>Tenant: <?php echo sa_e($pr['tenant_name']); ?> (<?php echo sa_e($pr['tenant_email']); ?>)</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="sa-badge" style="<?php echo $tier_style; ?>font-weight:700;text-transform:capitalize;">
                                <?php echo sa_e($pr['tier']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="sa-badge" style="background:#f1f5f9;color:#475569;text-transform:capitalize;">
                                <?php echo sa_e($pr['billing_cycle']); ?>
                            </span>
                        </td>
                        <td class="num">
                            <strong><?php echo sa_e(sa_money($pr['price'])); ?></strong>
                            <small class="sa-faint" style="display:block;"><?php echo $pr['billing_cycle'] === 'yearly' ? '/year' : '/mo'; ?></small>
                        </td>
                        <td>
                            <form method="POST" action="subscriptions.php?stream=premium" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="update_premium_status">
                                <input type="hidden" name="sub_id" value="<?php echo (int) $pr['id']; ?>">
                                <select class="sa-inline-select" name="status" onchange="this.form.submit()">
                                    <option value="pending"<?php echo $pr['status'] === 'pending' ? ' selected' : ''; ?>>Pending</option>
                                    <option value="active"<?php echo $pr['status'] === 'active' ? ' selected' : ''; ?>>Active</option>
                                    <option value="expired"<?php echo $pr['status'] === 'expired' ? ' selected' : ''; ?>>Expired</option>
                                    <option value="cancelled"<?php echo $pr['status'] === 'cancelled' ? ' selected' : ''; ?>>Cancelled</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <?php if ($pr['invoice_id']): ?>
                                <a href="finance.php?inv=<?php echo (int)$pr['invoice_id']; ?>" class="sa-mono" style="font-size:12px;text-decoration:none;font-weight:600;">
                                    #INV-<?php echo (int)$pr['invoice_id']; ?>
                                </a>
                                <?php if (($pr['invoice_status'] ?? '') === 'paid'): ?>
                                    <span class="sa-badge" style="background:#dcfce7;color:#15803d;font-size:10px;padding:1px 5px;">Paid</span>
                                <?php else: ?>
                                    <span class="sa-badge" style="background:#fef3c7;color:#92400e;font-size:10px;padding:1px 5px;">Unpaid</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="sa-faint">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pr['expires_at']): ?>
                                <strong><?php echo sa_e(sa_date($pr['expires_at'])); ?></strong>
                                <small class="sa-faint" style="display:block;">
                                    <?php if ($days === null || $days < 0): ?>
                                        <span style="color:#ef4444;font-weight:600;">Expired</span>
                                    <?php else: ?>
                                        <?php echo (int)$days; ?> days left
                                    <?php endif; ?>
                                </small>
                            <?php else: ?>
                                <span class="sa-faint">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-no-export>
                            <div class="sa-row-actions">
                                <?php if ($pr['status'] === 'pending'): ?>
                                <form method="POST" action="subscriptions.php?stream=premium" style="display:inline">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_premium_status">
                                    <input type="hidden" name="sub_id" value="<?php echo (int) $pr['id']; ?>">
                                    <input type="hidden" name="status" value="active">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-primary" title="Approve &amp; Activate">
                                        <?php echo sa_icon('check'); ?> Activate
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" action="subscriptions.php?stream=premium" style="display:inline">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="extend_premium">
                                    <input type="hidden" name="sub_id" value="<?php echo (int) $pr['id']; ?>">
                                    <input type="hidden" name="extend_months" value="1">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="Extend 1 month">
                                        +1 mo
                                    </button>
                                </form>
                                <form method="POST" action="subscriptions.php?stream=premium" style="display:inline">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="extend_premium">
                                    <input type="hidden" name="sub_id" value="<?php echo (int) $pr['id']; ?>">
                                    <input type="hidden" name="extend_months" value="12">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="Extend 1 year">
                                        +1 yr
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="sa-card-foot">
            <span><?php echo sa_e(sa_num(count($premium_rows))); ?> premium subscription<?php echo count($premium_rows) === 1 ? '' : 's'; ?> shown</span>
            <span>Activation automatically verifies profile and sets badge</span>
        </div>
    </section>

<!-- ============================================================
     STREAM 3: REVIEW WIDGETS
     ============================================================ -->
<?php elseif ($stream === 'widgets'): ?>

    <!-- Filter chips for Review Widgets -->
    <section class="sa-card sa-mb">
        <div class="sa-filters">
            <div class="sa-chips">
    <?php
    $w_chips = [
        'all'       => ['label' => 'All Widgets', 'count' => $total_widget_subs],
        'paid'      => ['label' => 'Pro & Agency Only', 'count' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM widget_configs WHERE tier IN ('pro','agency')", 0, 'widget_configs')],
        'active'    => ['label' => 'Active Subscriptions', 'count' => $stats['widgets_active']],
        'pending'   => ['label' => 'Pending Upgrades', 'count' => $stats['widgets_pending']],
        'suspended' => ['label' => 'Suspended', 'count' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM widget_configs WHERE status='suspended'", 0, 'widget_configs')],
        'expired'   => ['label' => 'Expired', 'count' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM widget_configs WHERE status='expired'", 0, 'widget_configs')],
    ];
    foreach ($w_chips as $key => $chip): ?>
                <a class="sa-chip<?php echo $filter === $key ? ' active' : ''; ?>"
                   href="subscriptions.php?stream=widgets&amp;view=<?php echo $key; ?>">
                    <?php echo sa_e($chip['label']); ?><span class="count"><?php echo (int) $chip['count']; ?></span>
                </a>
    <?php endforeach; ?>
            </div>
            <span class="sa-pill" style="margin-left:auto"><?php echo sa_e(sa_money($widget_mrr_visible)); ?> MRR in this view</span>
        </div>
    </section>

    <!-- Review Widgets Table -->
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Review Widget Subscriptions &amp; Embeds</h3>
                <p>Purchased on <code>admin/widgets.php</code> — Pro (GHS 79/mo) and Agency (GHS 149/mo) white-label review badges &amp; carousels.</p>
            </div>
            <div class="sa-card-head-actions">
                <a href="widgets.php" class="sa-btn sa-btn-secondary sa-btn-sm">Widget Management &rarr;</a>
            </div>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table" id="subsTable" data-sa-sortable-table>
                <thead>
                    <tr>
                        <th data-sa-sort="0">Tenant &amp; Company</th>
                        <th data-sa-sort="1">Tier</th>
                        <th data-sa-sort="2">Layout &amp; Theme</th>
                        <th data-sa-sort="3">Allowed Domains</th>
                        <th data-sa-sort="4">Traffic (Views / Clicks)</th>
                        <th data-sa-sort="5">Status</th>
                        <th data-sa-sort="6" data-type="date">Expires</th>
                        <th data-no-export><span class="sa-sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
    <?php if (!$widget_rows): ?>
                    <tr data-static>
                        <td colspan="8">
                            <div class="sa-empty">
                                <?php echo sa_icon('layers'); ?>
                                <strong>No widget subscriptions found in this view</strong>
                                <p>When a tenant configures or upgrades their embeddable widget, it shows here.</p>
                            </div>
                        </td>
                    </tr>
    <?php else: ?>
    <?php foreach ($widget_rows as $wr): ?>
    <?php
        $search_blob = strtolower(implode(' ', [
            $wr['tenant_name'], $wr['tenant_email'], $wr['company_name'] ?? '', $wr['tier'], $wr['status'], $wr['widget_key']
        ]));
        $days = sa_days_until($wr['expires_at']);
        $tier_style = $wr['tier'] === 'agency' ? 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;' : ($wr['tier'] === 'pro' ? 'background:#ede9fe;color:#6d28d9;border:1px solid #ddd6fe;' : 'background:#f1f5f9;color:#475569;');
    ?>
                    <tr data-filterable data-search="<?php echo sa_e($search_blob); ?>">
                        <td>
                            <div class="sa-cell-main">
                                <span class="sa-cell-avatar" style="background:#3b82f6;color:#fff;">🧩</span>
                                <div class="sa-cell-text">
                                    <strong><?php echo sa_e($wr['company_name'] ?: $wr['tenant_name']); ?></strong>
                                    <span>Tenant: <?php echo sa_e($wr['tenant_name']); ?> (<?php echo sa_e($wr['tenant_email']); ?>)</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <form method="POST" action="subscriptions.php?stream=widgets" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="update_widget_tier">
                                <input type="hidden" name="widget_id" value="<?php echo (int) $wr['id']; ?>">
                                <select class="sa-inline-select" name="tier" onchange="this.form.submit()" style="<?php echo $tier_style; ?>font-weight:700;">
                                    <option value="free"<?php echo $wr['tier'] === 'free' ? ' selected' : ''; ?>>Free</option>
                                    <option value="pro"<?php echo $wr['tier'] === 'pro' ? ' selected' : ''; ?>>Pro (GHS 79)</option>
                                    <option value="agency"<?php echo $wr['tier'] === 'agency' ? ' selected' : ''; ?>>Agency (GHS 149)</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <span class="sa-badge" style="background:#f8fafc;color:#334155;border:1px solid #e2e8f0;text-transform:capitalize;">
                                <?php echo sa_e($wr['layout']); ?> · <?php echo sa_e($wr['theme']); ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-size:12px;color:var(--sa-muted);">
                                <?php echo sa_e($wr['allowed_domains'] ? substr($wr['allowed_domains'], 0, 30) : 'Any domain (*)'); ?>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo sa_e(sa_num($wr['views_total'])); ?></strong> <span class="sa-faint">views</span> · 
                            <strong><?php echo sa_e(sa_num($wr['clicks_total'])); ?></strong> <span class="sa-faint">clicks</span>
                        </td>
                        <td>
                            <form method="POST" action="subscriptions.php?stream=widgets" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="update_widget_status">
                                <input type="hidden" name="widget_id" value="<?php echo (int) $wr['id']; ?>">
                                <select class="sa-inline-select" name="status" onchange="this.form.submit()">
                                    <option value="pending"<?php echo $wr['status'] === 'pending' ? ' selected' : ''; ?>>Pending</option>
                                    <option value="active"<?php echo $wr['status'] === 'active' ? ' selected' : ''; ?>>Active</option>
                                    <option value="suspended"<?php echo $wr['status'] === 'suspended' ? ' selected' : ''; ?>>Suspended</option>
                                    <option value="expired"<?php echo $wr['status'] === 'expired' ? ' selected' : ''; ?>>Expired</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <?php if ($wr['expires_at']): ?>
                                <strong><?php echo sa_e(sa_date($wr['expires_at'])); ?></strong>
                                <small class="sa-faint" style="display:block;">
                                    <?php if ($days === null || $days < 0): ?>
                                        <span style="color:#ef4444;font-weight:600;">Expired</span>
                                    <?php else: ?>
                                        <?php echo (int)$days; ?> days left
                                    <?php endif; ?>
                                </small>
                            <?php else: ?>
                                <span class="sa-faint">Perpetual</span>
                            <?php endif; ?>
                        </td>
                        <td data-no-export>
                            <div class="sa-row-actions">
                                <?php if ($wr['status'] === 'pending'): ?>
                                <form method="POST" action="subscriptions.php?stream=widgets" style="display:inline">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_widget_status">
                                    <input type="hidden" name="widget_id" value="<?php echo (int) $wr['id']; ?>">
                                    <input type="hidden" name="status" value="active">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-primary" title="Approve &amp; Activate">
                                        <?php echo sa_icon('check'); ?> Activate
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" action="subscriptions.php?stream=widgets" style="display:inline">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="extend_widget">
                                    <input type="hidden" name="widget_id" value="<?php echo (int) $wr['id']; ?>">
                                    <input type="hidden" name="extend_days" value="30">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="Extend 30 days">
                                        +30 days
                                    </button>
                                </form>
                                <a href="../widget.php?key=<?php echo urlencode($wr['widget_key']); ?>" target="_blank" class="sa-btn sa-btn-sm sa-btn-ghost" title="Preview Live Widget">
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
        <div class="sa-card-foot">
            <span><?php echo sa_e(sa_num(count($widget_rows))); ?> widget<?php echo count($widget_rows) === 1 ? '' : 's'; ?> shown</span>
            <span>Upgrades to Pro or Agency remove branding and unlock custom styles</span>
        </div>
    </section>

<!-- ============================================================
     STREAM 4: SPONSORED LISTINGS
     ============================================================ -->
<?php elseif ($stream === 'sponsored'): ?>

    <!-- Filter chips for Sponsored Listings -->
    <section class="sa-card sa-mb">
        <div class="sa-filters">
            <div class="sa-chips">
    <?php
    $s_chips = [
        'all'            => ['label' => 'All Placements', 'count' => $total_spons_subs],
        'active'         => ['label' => 'Live Active Ads', 'count' => $stats['sponsored_active']],
        'pending'        => ['label' => 'Pending Approval', 'count' => $stats['sponsored_pending']],
        'featured'       => ['label' => '★ Featured Tier', 'count' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM sponsored_listings WHERE tier='featured'", 0, 'sponsored_listings')],
        'sponsored'      => ['label' => 'Sponsored Tier', 'count' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM sponsored_listings WHERE tier='sponsored'", 0, 'sponsored_listings')],
        'expired'        => ['label' => 'Expired / Ended', 'count' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM sponsored_listings WHERE status='expired' OR (ends_at <= NOW())", 0, 'sponsored_listings')],
    ];
    foreach ($s_chips as $key => $chip): ?>
                <a class="sa-chip<?php echo $filter === $key ? ' active' : ''; ?>"
                   href="subscriptions.php?stream=sponsored&amp;view=<?php echo $key; ?>">
                    <?php echo sa_e($chip['label']); ?><span class="count"><?php echo (int) $chip['count']; ?></span>
                </a>
    <?php endforeach; ?>
            </div>
            <span class="sa-pill" style="margin-left:auto"><?php echo sa_e(sa_money($spons_rev_visible)); ?> Revenue in this view</span>
        </div>
    </section>

    <!-- Sponsored Listings Table -->
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Category Sponsorships &amp; Featured Placements</h3>
                <p>Purchased on <code>admin/sponsored.php</code> — Top-of-category placement: Featured (1 slot) or Sponsored (3 slots).</p>
            </div>
            <div class="sa-card-head-actions">
                <a href="sponsored.php" class="sa-btn sa-btn-secondary sa-btn-sm">Placement Manager &rarr;</a>
            </div>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table" id="subsTable" data-sa-sortable-table>
                <thead>
                    <tr>
                        <th data-sa-sort="0">Company &amp; Category</th>
                        <th data-sa-sort="1">Tier &amp; Badge</th>
                        <th data-sa-sort="2">Tenant</th>
                        <th data-sa-sort="3" class="num">Price</th>
                        <th data-sa-sort="4">Traffic (Views / Clicks)</th>
                        <th data-sa-sort="5">Status</th>
                        <th data-sa-sort="6" data-type="date">Active Period</th>
                        <th data-no-export><span class="sa-sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
    <?php if (!$sponsored_rows): ?>
                    <tr data-static>
                        <td colspan="8">
                            <div class="sa-empty">
                                <?php echo sa_icon('card'); ?>
                                <strong>No sponsored placements found in this view</strong>
                                <p>When a tenant buys category placement on their sponsored page, it appears here.</p>
                            </div>
                        </td>
                    </tr>
    <?php else: ?>
    <?php foreach ($sponsored_rows as $sr): ?>
    <?php
        $search_blob = strtolower(implode(' ', [
            $sr['company_name'], $sr['tenant_name'], $sr['tenant_email'], $sr['category_name'] ?? '', $sr['tier'], $sr['status']
        ]));
        $days = sa_days_until($sr['ends_at']);
        $tier_style = $sr['tier'] === 'featured' ? 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;' : 'background:#ede9fe;color:#6d28d9;border:1px solid #ddd6fe;';
    ?>
                    <tr data-filterable data-search="<?php echo sa_e($search_blob); ?>">
                        <td>
                            <div class="sa-cell-main">
                                <span class="sa-cell-avatar" style="background:#f59e0b;color:#fff;">🚀</span>
                                <div class="sa-cell-text">
                                    <strong>
                                        <a href="../company.php?slug=<?php echo urlencode($sr['slug'] ?? ''); ?>" target="_blank" style="text-decoration:none;color:inherit;">
                                            <?php echo sa_e($sr['company_name']); ?>
                                        </a>
                                    </strong>
                                    <span>Category: <strong><?php echo sa_e($sr['category_name'] ?: 'General Directory'); ?></strong></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="sa-badge" style="<?php echo $tier_style; ?>font-weight:700;">
                                <?php echo $sr['tier'] === 'featured' ? '★ Featured' : 'Sponsored'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="sa-cell-text">
                                <strong><?php echo sa_e($sr['tenant_name']); ?></strong>
                                <span><?php echo sa_e($sr['tenant_email']); ?></span>
                            </div>
                        </td>
                        <td class="num">
                            <strong><?php echo sa_e(sa_money($sr['price'])); ?></strong>
                            <small class="sa-faint" style="display:block;"><?php echo (int)$sr['duration_days']; ?> days</small>
                        </td>
                        <td>
                            <strong><?php echo sa_e(sa_num($sr['impressions'])); ?></strong> <span class="sa-faint">impr</span> · 
                            <strong><?php echo sa_e(sa_num($sr['clicks'])); ?></strong> <span class="sa-faint">clicks</span>
                        </td>
                        <td>
                            <form method="POST" action="subscriptions.php?stream=sponsored" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="update_sponsored_status">
                                <input type="hidden" name="listing_id" value="<?php echo (int) $sr['id']; ?>">
                                <select class="sa-inline-select" name="status" onchange="this.form.submit()">
                                    <option value="pending"<?php echo $sr['status'] === 'pending' ? ' selected' : ''; ?>>Pending</option>
                                    <option value="active"<?php echo $sr['status'] === 'active' ? ' selected' : ''; ?>>Active</option>
                                    <option value="expired"<?php echo $sr['status'] === 'expired' ? ' selected' : ''; ?>>Expired</option>
                                    <option value="rejected"<?php echo $sr['status'] === 'rejected' ? ' selected' : ''; ?>>Rejected</option>
                                    <option value="cancelled"<?php echo $sr['status'] === 'cancelled' ? ' selected' : ''; ?>>Cancelled</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <?php if ($sr['ends_at']): ?>
                                <span><?php echo sa_e(sa_date($sr['starts_at'])); ?> &rarr; <strong><?php echo sa_e(sa_date($sr['ends_at'])); ?></strong></span>
                                <small class="sa-faint" style="display:block;">
                                    <?php if ($days === null || $days < 0): ?>
                                        <span style="color:#ef4444;font-weight:600;">Ended</span>
                                    <?php else: ?>
                                        <?php echo (int)$days; ?> days left
                                    <?php endif; ?>
                                </small>
                            <?php else: ?>
                                <span class="sa-faint">Not started yet</span>
                            <?php endif; ?>
                        </td>
                        <td data-no-export>
                            <div class="sa-row-actions">
                                <?php if ($sr['status'] === 'pending'): ?>
                                <form method="POST" action="subscriptions.php?stream=sponsored" style="display:inline">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="update_sponsored_status">
                                    <input type="hidden" name="listing_id" value="<?php echo (int) $sr['id']; ?>">
                                    <input type="hidden" name="status" value="active">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-primary" title="Approve &amp; Activate">
                                        <?php echo sa_icon('check'); ?> Activate
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" action="subscriptions.php?stream=sponsored" style="display:inline">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="extend_sponsored">
                                    <input type="hidden" name="listing_id" value="<?php echo (int) $sr['id']; ?>">
                                    <input type="hidden" name="extend_days" value="30">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="Extend 30 days">
                                        +30 days
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="sa-card-foot">
            <span><?php echo sa_e(sa_num(count($sponsored_rows))); ?> sponsored listing<?php echo count($sponsored_rows) === 1 ? '' : 's'; ?> shown</span>
            <span>Featured guarantees top-1 spot with golden banner on directory category pages</span>
        </div>
    </section>

<!-- ============================================================
     STREAM 5: ALL STREAMS OVERVIEW
     ============================================================ -->
<?php else: ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
        <!-- Premium Summary -->
        <section class="sa-card">
            <div class="sa-card-head">
                <div>
                    <h3>★ Recent Premium Subscriptions</h3>
                    <p>Recent premium profile activations</p>
                </div>
                <div class="sa-card-head-actions">
                    <a href="subscriptions.php?stream=premium" class="sa-btn sa-btn-secondary sa-btn-sm">View All &rarr;</a>
                </div>
            </div>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead><tr><th>Company</th><th>Tier</th><th>Cycle</th><th>Price</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$premium_rows): ?><tr><td colspan="5" class="sa-faint" style="text-align:center;">No premium subscriptions.</td></tr><?php else: ?>
                    <?php foreach (array_slice($premium_rows, 0, 5) as $pr): ?>
                    <tr>
                        <td><strong><?php echo sa_e($pr['company_name']); ?></strong><small class="sa-faint" style="display:block;"><?php echo sa_e($pr['tenant_name']); ?></small></td>
                        <td><span class="sa-badge" style="text-transform:capitalize;"><?php echo sa_e($pr['tier']); ?></span></td>
                        <td><?php echo sa_e($pr['billing_cycle']); ?></td>
                        <td><?php echo sa_e(sa_money($pr['price'])); ?></td>
                        <td><span class="sa-badge <?php echo $pr['status'] === 'active' ? 'sa-badge-active' : 'sa-badge-pending'; ?>"><?php echo sa_e($pr['status']); ?></span></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Widgets Summary -->
        <section class="sa-card">
            <div class="sa-card-head">
                <div>
                    <h3>🧩 Recent Widget Upgrades</h3>
                    <p>Pro &amp; agency widget subscribers</p>
                </div>
                <div class="sa-card-head-actions">
                    <a href="subscriptions.php?stream=widgets" class="sa-btn sa-btn-secondary sa-btn-sm">View All &rarr;</a>
                </div>
            </div>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead><tr><th>Tenant / Company</th><th>Tier</th><th>Traffic</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$widget_rows): ?><tr><td colspan="4" class="sa-faint" style="text-align:center;">No widgets.</td></tr><?php else: ?>
                    <?php foreach (array_slice($widget_rows, 0, 5) as $wr): ?>
                    <tr>
                        <td><strong><?php echo sa_e($wr['company_name'] ?: $wr['tenant_name']); ?></strong><small class="sa-faint" style="display:block;"><?php echo sa_e($wr['tenant_name']); ?></small></td>
                        <td><span class="sa-badge" style="text-transform:capitalize;"><?php echo sa_e($wr['tier']); ?></span></td>
                        <td><?php echo sa_e(sa_num($wr['views_total'])); ?> views</td>
                        <td><span class="sa-badge <?php echo $wr['status'] === 'active' ? 'sa-badge-active' : 'sa-badge-pending'; ?>"><?php echo sa_e($wr['status']); ?></span></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Sponsored Summary -->
        <section class="sa-card">
            <div class="sa-card-head">
                <div>
                    <h3>🚀 Recent Category Placements</h3>
                    <p>Featured and sponsored advertising slots</p>
                </div>
                <div class="sa-card-head-actions">
                    <a href="subscriptions.php?stream=sponsored" class="sa-btn sa-btn-secondary sa-btn-sm">View All &rarr;</a>
                </div>
            </div>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead><tr><th>Company</th><th>Category</th><th>Tier</th><th>Price</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$sponsored_rows): ?><tr><td colspan="5" class="sa-faint" style="text-align:center;">No sponsored listings.</td></tr><?php else: ?>
                    <?php foreach (array_slice($sponsored_rows, 0, 5) as $sr): ?>
                    <tr>
                        <td><strong><?php echo sa_e($sr['company_name']); ?></strong></td>
                        <td><?php echo sa_e($sr['category_name'] ?: 'General'); ?></td>
                        <td><span class="sa-badge"><?php echo sa_e($sr['tier']); ?></span></td>
                        <td><?php echo sa_e(sa_money($sr['price'])); ?></td>
                        <td><span class="sa-badge <?php echo $sr['status'] === 'active' ? 'sa-badge-active' : 'sa-badge-pending'; ?>"><?php echo sa_e($sr['status']); ?></span></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- SaaS Workspace Plans Summary -->
        <section class="sa-card">
            <div class="sa-card-head">
                <div>
                    <h3>🏢 Workspace Platform Plans</h3>
                    <p>Core multi-tenant SaaS subscriptions</p>
                </div>
                <div class="sa-card-head-actions">
                    <a href="subscriptions.php?stream=plans" class="sa-btn sa-btn-secondary sa-btn-sm">View All &rarr;</a>
                </div>
            </div>
            <div style="padding:18px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                    <div style="padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                        <span class="sa-faint" style="font-size:11px;font-weight:700;">PAYING TENANTS</span>
                        <div style="font-size:18px;font-weight:800;"><?php echo sa_num($m['paying']); ?></div>
                    </div>
                    <div style="padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                        <span class="sa-faint" style="font-size:11px;font-weight:700;">SAAS PLAN MRR</span>
                        <div style="font-size:18px;font-weight:800;"><?php echo sa_money($m['mrr']); ?></div>
                    </div>
                </div>
                <a href="subscriptions.php?stream=plans" class="sa-btn sa-btn-primary" style="width:100%;text-align:center;display:block;">
                    Open Workspace Plans Table &rarr;
                </a>
            </div>
        </section>
    </div>

<?php endif; ?>

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
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Tenant</th>
                    <th class="num">Amount</th>
                    <th>Method</th>
                    <th>Reference / Memo</th>
                    <th>Extension</th>
                    <th>Recorded On</th>
                    <th><span class="sa-sr-only">Receipt Action</span></th>
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

<!-- ============ SUBSCRIPTION PLANS & ACCESS ENTITLEMENTS ============ -->
<section class="sa-card sa-mt" id="subscription-access-chart">
    <div class="sa-card-head">
        <div>
            <h3>Subscription packages &amp; access chart</h3>
            <p>Side-by-side breakdown of features, limits, and access controls for all subscription plans.</p>
        </div>
        <div class="sa-card-head-actions">
            <a href="plans.php" class="sa-btn sa-btn-sm sa-btn-primary">
                <?php echo sa_icon('layers'); ?> Edit catalog plans
            </a>
        </div>
    </div>
    <div style="padding: 10px 18px 24px;">
        <?php echo render_subscription_access_chart($conn, 'superadmin_plans', [
            'title' => 'SUBSCRIPTION PLANS PACKAGES PRICING TABLE',
            'subtitle' => 'Capability matrix for active subscription packages in the platform catalog.'
        ]); ?>
    </div>
</section>

<!-- ============ RECORD PAYMENT DIALOG ============ -->
<dialog class="sa-dialog" id="recordPaymentDialog" aria-labelledby="recordPaymentDialogTitle">
    <form method="POST" action="subscriptions.php?stream=<?php echo urlencode($stream); ?>" class="sa-form">
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

<!-- ============ QUICK EDIT SUBSCRIPTION DIALOG ============ -->
<dialog class="sa-dialog" id="quickEditDialog" aria-labelledby="quickEditDialogTitle">
    <form method="POST" action="subscriptions.php?stream=plans" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="update_subscription">
        <input type="hidden" name="tenant_id" id="qe_tenant_id" value="">
        <div class="sa-dialog-head">
            <div>
                <h3 id="quickEditDialogTitle">Quick Edit Subscription</h3>
                <p>Update plan, status, price and renewal settings for a workspace.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="qe_company">Tenant / Company</label>
                    <input id="qe_company" type="text" class="sa-input" readonly style="background:#f8f9fa">
                </div>

                <div class="sa-field">
                    <label for="qe_plan">Plan</label>
                    <select id="qe_plan" name="plan_id" required>
                        <option value="0">No plan (free/trial)</option>
                        <?php foreach ($plans_dropdown as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" data-price="<?php echo (float) $p['price']; ?>">
                            <?php echo sa_e($p['plan_name']); ?> — <?php echo sa_e(sa_money($p['price'])); ?>/mo
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sa-field">
                    <label for="qe_status">Status</label>
                    <select id="qe_status" name="subscription_status" required>
                        <option value="trial">Trial</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div class="sa-field">
                    <label for="qe_price">Subscription Price</label>
                    <input id="qe_price" type="number" step="0.01" min="0" name="subscription_price" placeholder="0.00">
                    <span class="sa-hint">Monthly price for this workspace</span>
                </div>

                <div class="sa-field">
                    <label for="qe_end">Subscription Ends</label>
                    <input id="qe_end" type="date" name="subscription_end_date">
                    <span class="sa-hint">Leave blank to keep current end date</span>
                </div>

                <div class="sa-field">
                    <label class="sa-checkbox-label">
                        <input type="checkbox" name="auto_renew" id="qe_auto_renew" value="1">
                        <span>Enable auto-renew</span>
                    </label>
                    <span class="sa-hint">Automatically renew when subscription ends</span>
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary">
                <?php echo sa_icon('check'); ?> Update Subscription
            </button>
        </div>
    </form>
</dialog>

<script>
/* Quick Edit Subscription Dialog */
(function() {
    var dialog = document.getElementById('quickEditDialog');
    if (!dialog) return;

    function openDialog(data) {
        document.getElementById('qe_tenant_id').value = data.tenantId;
        document.getElementById('qe_company').value = data.company;
        document.getElementById('qe_plan').value = data.planId;
        document.getElementById('qe_status').value = data.status;
        document.getElementById('qe_price').value = data.price || '';
        document.getElementById('qe_end').value = data.endDate || '';
        document.getElementById('qe_auto_renew').checked = data.autoRenew === '1';

        document.getElementById('quickEditDialogTitle').textContent = 'Edit: ' + data.company;

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
            dialog.classList.add('is-open-fallback');
        }
    }

    document.querySelectorAll('[data-sa-open-dialog="#quickEditDialog"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var data = btn.dataset;
            openDialog({
                tenantId: data.tenantId,
                company: data.company,
                planId: data.planId,
                status: data.status,
                price: data.price,
                endDate: data.endDate,
                autoRenew: data.autoRenew
            });
        });
    });

    document.getElementById('qe_plan').addEventListener('change', function() {
        var option = this.options[this.selectedIndex];
        var price = option.getAttribute('data-price');
        if (price) {
            document.getElementById('qe_price').value = price;
        }
    });
})();
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
