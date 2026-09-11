<?php
/**
 * ============================================================
 *  Super Admin — Quote requests (sales pipeline)
 *  With Real ID (QTE-XXXXXXXX) and Email Password Setup
 * ============================================================
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();
require_sa_permission('quotes');

$has_quotes_table = sa_table_exists($conn, 'quote_requests');

ensureRealIdSchema($conn);

/* ---------- POST handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('quote_requests.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = (int) ($_POST['quote_id'] ?? 0);

    if ($action === 'update_status' && $id) {
        $status = in_array($_POST['status'] ?? '', ['pending', 'contacted', 'converted', 'rejected'], true) ? $_POST['status'] : '';
        if ($status) {
            $stmt = $conn->prepare("UPDATE quote_requests SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Request marked ' . $status . '.');
        }
        redirect('quote_requests.php');
    }

    if ($action === 'convert' && $id) {
        $quote = sa_one($conn, "SELECT * FROM quote_requests WHERE id = " . $id, 'quote_requests');
        if (!$quote) {
            sa_flash('error', 'That request no longer exists.');
            redirect('quote_requests.php');
        }

        $company = sanitize($_POST['company_name'] ?? $quote['company_name']);
        $email = sanitize($_POST['email'] ?? $quote['email']);
        $phone = sanitize($_POST['phone'] ?? $quote['phone']);
        $plan_id = (int) ($_POST['plan_id'] ?? ($quote['plan_id'] ?: 0));
        $status = ($_POST['subscription_status'] ?? 'trial') === 'active' ? 'active' : 'trial';
        $months = max(0, (int) ($_POST['months'] ?? 1));

        if ($company === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sa_flash('error', 'A company name and a valid email address are required.');
            redirect('quote_requests.php?id=' . $id);
        } else {
            // If tenant with this email already exists (auto-created from public form), link & resend setup instead of error
            $existingTenant = sa_one($conn, "SELECT id, public_id, company_name, email, username FROM tenants WHERE email = '" . $conn->real_escape_string($email) . "' LIMIT 1", 'tenants');
            if ($existingTenant) {
                $tid = (int)$existingTenant['id'];
                $new_token = generateSecureToken(32);
                $new_exp = date('Y-m-d H:i:s', strtotime('+48 hours'));
                $up = $conn->prepare("UPDATE tenants SET setup_token = ?, setup_token_expires = ?, company_name = ?, phone = ?, plan_id = ? WHERE id = ?");
                if ($up) {
                    $up->bind_param("ssssii", $new_token, $new_exp, $company, $phone, $plan_id, $tid);
                    $up->execute();
                    $up->close();
                } else {
                    @$conn->query("UPDATE tenants SET setup_token = '" . $conn->real_escape_string($new_token) . "', setup_token_expires = '" . $conn->real_escape_string($new_exp) . "', company_name = '" . $conn->real_escape_string($company) . "', phone = '" . $conn->real_escape_string($phone) . "', plan_id = " . (int)$plan_id . " WHERE id = " . $tid);
                }

                $q_upd = $conn->prepare("UPDATE quote_requests SET status = 'converted', converted_tenant_id = ?, setup_email_sent = 1 WHERE id = ?");
                if ($q_upd) { $q_upd->bind_param("ii", $tid, $id); $q_upd->execute(); $q_upd->close(); }
                else { @$conn->query("UPDATE quote_requests SET status = 'converted', converted_tenant_id = " . $tid . ", setup_email_sent = 1 WHERE id = " . $id); }

                $mailRes = sendTenantSetupEmail($conn, $existingTenant, $new_token, true);
                if ($mailRes['success']) {
                    sa_flash('success', 'Quote linked to existing tenant ' . ($existingTenant['public_id'] ?? ('#'.$tid)) . ' (' . $existingTenant['company_name'] . '). Setup email resent to ' . $email . '.');
                } else {
                    sa_flash('warning', 'Quote linked to existing tenant ' . ($existingTenant['public_id'] ?? ('#'.$tid)) . ' but email failed: ' . $mailRes['message']);
                }
                redirect('quote_requests.php?id=' . $id);
            }
            $plan = sa_one($conn, "SELECT price FROM subscription_plans WHERE id = " . $plan_id, 'subscription_plans');
            $price = $plan ? (float) $plan['price'] : 0.0;
            $username = sa_unique_username($conn, $company);
            $placeholder_hash = password_hash(generateSecureToken(16), PASSWORD_DEFAULT);
            $public_id = generateRealPublicId($conn, 'tenants', 'public_id', 'OPT-', 8);
            $setup_token = generateSecureToken(32);
            $setup_expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $start = date('Y-m-d');
            $end = $months > 0 ? date('Y-m-d', strtotime('+' . $months . ' month')) : null;

            $stmt = $conn->prepare(
                "INSERT INTO tenants
                    (public_id, setup_token, setup_token_expires, company_name, email, phone, username, password, plan_id,
                     subscription_status, subscription_price, subscription_start_date, subscription_end_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $end_ref = $end;
            if ($stmt) {
                $stmt->bind_param(
                    "ssssssssissss",
                    $public_id, $setup_token, $setup_expires, $company, $email, $phone, $username, $placeholder_hash, $plan_id,
                    $status, $price, $start, $end_ref
                );
                $stmt->execute();
            } else {
                $stmt2 = $conn->prepare(
                    "INSERT INTO tenants
                        (company_name, email, phone, username, password, plan_id,
                         subscription_status, subscription_price, subscription_start_date, subscription_end_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt2->bind_param(
                    "sssssisssd",
                    $company, $email, $phone, $username, $placeholder_hash, $plan_id,
                    $status, $price, $start, $end_ref
                );
                $stmt2->execute();
                $stmt = $stmt2;
            }

            if ($stmt->error) {
                sa_flash('error', 'Could not create the tenant: ' . $stmt->error);
            } else {
                $new_id = (int) $conn->insert_id;

                if (empty($public_id) || strpos($public_id, 'OPT-') !== 0) {
                    $public_id = generateRealPublicId($conn, 'tenants', 'public_id', 'OPT-', 8);
                    @$conn->query("UPDATE tenants SET public_id = '" . $conn->real_escape_string($public_id) . "', setup_token = '" . $conn->real_escape_string($setup_token) . "', setup_token_expires = '" . $conn->real_escape_string($setup_expires) . "' WHERE id = " . $new_id);
                }

                $co_stmt = $conn->prepare(
                    "INSERT INTO customers (tenant_id, company_name, email, phone, created_at)
                     VALUES (?, ?, ?, ?, NOW())"
                );
                if ($co_stmt) {
                    $co_stmt->bind_param("isss", $new_id, $company, $email, $phone);
                    $co_stmt->execute();
                    $co_stmt->close();
                }

                $q_upd = $conn->prepare("UPDATE quote_requests SET status = 'converted', converted_tenant_id = ?, setup_email_sent = 1 WHERE id = ?");
                if ($q_upd) {
                    $q_upd->bind_param("ii", $new_id, $id);
                    $q_upd->execute();
                    $q_upd->close();
                } else {
                    $conn->query("UPDATE quote_requests SET status = 'converted' WHERE id = " . $id);
                }

                $tenantRow = [
                    'id' => $new_id,
                    'public_id' => $public_id,
                    'company_name' => $company,
                    'email' => $email,
                    'username' => $username,
                ];
                $mailRes = ['success' => false, 'message' => 'Mailer unavailable'];
                try {
                    $mailRes = sendTenantSetupEmail($conn, $tenantRow, $setup_token, true);
                } catch (Exception $e) {
                    $mailRes = ['success' => false, 'message' => $e->getMessage()];
                }

                if ($mailRes['success']) {
                    sa_flash('success', $company . ' is now a tenant with ID ' . $public_id . ' (login "' . $username . '"). Setup email sent to ' . $email . '.');
                } else {
                    sa_flash('warning', $company . ' is now a tenant with ID ' . $public_id . ' (login "' . $username . '"), but email failed: ' . $mailRes['message']);
                }
            }
            $stmt->close();
        }
        redirect('quote_requests.php');
    }

    if ($action === 'delete' && $id) {
        $conn->query("DELETE FROM quote_requests WHERE id = " . $id);
        sa_flash('success', 'Quote request deleted.');
        redirect('quote_requests.php');
    }

    if ($action === 'resend_setup' && $id) {
        $quote = sa_one($conn, "SELECT * FROM quote_requests WHERE id = " . $id, 'quote_requests');
        if (!$quote || empty($quote['converted_tenant_id'])) {
            sa_flash('error', 'No tenant linked to this quote yet.');
            redirect('quote_requests.php?id=' . $id);
        }
        $tid = (int)$quote['converted_tenant_id'];
        $tenant = sa_one($conn, "SELECT * FROM tenants WHERE id = " . $tid, 'tenants');
        if (!$tenant) {
            sa_flash('error', 'Linked tenant not found.');
            redirect('quote_requests.php?id=' . $id);
        }
        $new_token = generateSecureToken(32);
        $new_exp = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $up = $conn->prepare("UPDATE tenants SET setup_token = ?, setup_token_expires = ? WHERE id = ?");
        if ($up) { $up->bind_param("ssi", $new_token, $new_exp, $tid); $up->execute(); $up->close(); }
        $mailRes = sendTenantSetupEmail($conn, $tenant, $new_token, true);
        if ($mailRes['success']) {
            sa_flash('success', 'Setup email resent to ' . $tenant['email'] . ' for tenant ' . ($tenant['public_id'] ?? ('#'.$tid)));
        } else {
            sa_flash('error', 'Failed to resend: ' . $mailRes['message']);
        }
        redirect('quote_requests.php?id=' . $id);
    }
}

/* ---------- filters ---------- */
$filter = isset($_GET['status']) ? preg_replace('/[^a-z]/', '', strtolower($_GET['status'])) : 'all';
$allowed = ['all', 'pending', 'contacted', 'converted', 'rejected'];
if (!in_array($filter, $allowed, true)) {
    $filter = 'all';
}
$where = $filter === 'all' ? '' : " WHERE q.status = '" . $filter . "'";

$quotes = sa_query(
    $conn,
    "SELECT q.*, p.plan_name, c.name AS category_name
       FROM quote_requests q
       LEFT JOIN subscription_plans p ON p.id = q.plan_id
       LEFT JOIN categories c ON c.id = q.category_id"
    . $where . "
      ORDER BY FIELD(q.status,'pending','contacted','converted','rejected'), q.created_at DESC",
    ['quote_requests', 'subscription_plans', 'categories']
);

$status_counts = ['all' => 0, 'pending' => 0, 'contacted' => 0, 'converted' => 0, 'rejected' => 0];
foreach (sa_query($conn, "SELECT status, COUNT(*) AS c FROM quote_requests GROUP BY status", 'quote_requests') as $row) {
    if (isset($status_counts[$row['status']])) {
        $status_counts[$row['status']] = (int) $row['c'];
    }
    $status_counts['all'] += (int) $row['c'];
}

$plans = sa_query($conn, "SELECT id, plan_name, price FROM subscription_plans WHERE status = 'active' ORDER BY price ASC", 'subscription_plans');

$selected = null;
$selected_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($selected_id) {
    foreach ($quotes as $q) {
        if ((int) $q['id'] === $selected_id) {
            $selected = $q;
            break;
        }
    }
    if (!$selected) {
        $selected = sa_one($conn, "SELECT q.*, p.plan_name, c.name AS category_name
             FROM quote_requests q
             LEFT JOIN subscription_plans p ON p.id = q.plan_id
             LEFT JOIN categories c ON c.id = q.category_id
            WHERE q.id = " . $selected_id, ['quote_requests', 'subscription_plans', 'categories']);
    }
}

$converted_value = 0.0;
foreach (sa_query(
    $conn,
    "SELECT q.id, p.price FROM quote_requests q
       LEFT JOIN subscription_plans p ON p.id = q.plan_id
      WHERE q.status = 'converted'",
    ['quote_requests', 'subscription_plans']
) as $row) {
    $converted_value += (float) $row['price'];
}
$conversion_rate = $status_counts['all'] > 0 ? sa_pct($status_counts['converted'], $status_counts['all'], 0) : 0;

/* ---------- page meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = 'Quote requests';
$pageHeading = 'Quote requests';
$pageSubtitle = 'Inbound leads from the public website — Real IDs + email onboarding.';
$activePage = 'quotes';
$BASE = '../';
$extraCss = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';
$searchTarget = $has_quotes_table ? '#quotesTable' : '';
$searchPlaceholder = 'Filter by Real ID, company, email…';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Quote requests</span>
        </div>
        <h2>Sales pipeline — Real IDs</h2>
        <p><?php echo sa_e(sa_num($status_counts['all'])); ?> requests &middot;
           <?php echo sa_e(sa_num($status_counts['pending'])); ?> waiting &middot;
           <?php echo sa_e(number_format($conversion_rate, 0)); ?>% converted &middot; IDs like <span class="sa-mono" style="background:rgba(194,245,66,0.15);padding:2px 6px;border-radius:6px;">QTE-8K2F9Q1A</span></p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-ghost" data-sa-export="#quotesTable" data-sa-export-name="optibiz-quote-requests">
            <?php echo sa_icon('download'); ?> Export CSV
        </button>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<?php if (!$has_quotes_table): ?>
<section class="sa-card">
    <div class="sa-empty">
        <?php echo sa_icon('alert'); ?>
        <strong>The quote_requests table is missing</strong>
        <p>Run the <span class="sa-mono">quote_requests</span> block at the end of <span class="sa-mono">database.sql</span>.</p>
    </div>
</section>
<?php else: ?>

<div class="sa-grid sa-kpis sa-anim">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Awaiting reply</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('inbox'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($status_counts['pending'])); ?></div>
        <div class="sa-kpi-note">New leads that nobody has contacted yet</div>
    </article>
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">In conversation</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('message'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($status_counts['contacted'])); ?></div>
        <div class="sa-kpi-note">Contacted, not closed yet</div>
    </article>
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-success);--kpi-soft:var(--sa-success-soft);--kpi-line:var(--sa-success-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Converted</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('check-circle'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($status_counts['converted'])); ?></div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_money($converted_value)); ?> MRR won</div>
    </article>
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-danger);--kpi-soft:var(--sa-danger-soft);--kpi-line:var(--sa-danger-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Rejected</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('x'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($status_counts['rejected'])); ?></div>
        <div class="sa-kpi-note">Not a fit, spam or duplicates</div>
    </article>
</div>

<section class="sa-card sa-mb">
    <div class="sa-filters">
        <div class="sa-chips">
<?php foreach ([
    'all' => 'All requests', 'pending' => 'Pending', 'contacted' => 'Contacted',
    'converted' => 'Converted', 'rejected' => 'Rejected',
] as $key => $label): ?>
            <a class="sa-chip<?php echo $filter === $key ? ' active' : ''; ?>"
               href="quote_requests.php?status=<?php echo $key; ?>"
               aria-pressed="<?php echo $filter === $key ? 'true' : 'false'; ?>">
                <?php echo sa_e($label); ?><span class="count"><?php echo (int) $status_counts[$key]; ?></span>
            </a>
<?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3><?php echo sa_e(ucfirst($filter)); ?> requests — Real IDs</h3>
            <p><?php echo sa_e(sa_num(count($quotes))); ?> result<?php echo count($quotes) === 1 ? '' : 's'; ?> &middot; Showing <span class="sa-mono">QTE-XXXXXXXX</span> not DB id</p>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table" id="quotesTable" data-sa-sortable-table>
            <thead>
                <tr>
                    <th data-sa-sort="0" scope="col">Real ID</th>
                    <th data-sa-sort="1" scope="col">Company</th>
                    <th data-sa-sort="2" scope="col">Contact</th>
                    <th data-sa-sort="3" scope="col">Category</th>
                    <th data-sa-sort="4" scope="col">Interested in</th>
                    <th data-sa-sort="5" data-type="num" scope="col">Volume</th>
                    <th data-sa-sort="6" scope="col">Status</th>
                    <th data-sa-sort="7" data-type="date" scope="col">Received</th>
                    <th data-no-export scope="col"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$quotes): ?>
                <tr data-static>
                    <td colspan="9">
                        <div class="sa-empty">
                            <?php echo sa_icon('inbox'); ?>
                            <strong>No quote requests</strong>
                            <p><?php echo $filter === 'all'
                                ? 'Submissions from the public “Get started” form will appear here.'
                                : 'Nothing with this status right now.'; ?></p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($quotes as $q): ?>
<?php
    $realQuoteId = $q['public_id'] ?? ('QTE-' . str_pad((string)$q['id'], 6, '0', STR_PAD_LEFT));
    $search_blob = strtolower(implode(' ', [
        $realQuoteId,
        $q['company_name'], $q['contact_person'], $q['email'], $q['phone'],
        $q['location'], $q['plan_name'], $q['category_name'], $q['status'],
    ]));
?>
                <tr data-filterable data-search="<?php echo sa_e($search_blob); ?>">
                    <td>
                        <span class="sa-badge" style="font-family:monospace;background:rgba(194,245,66,0.15);color:#3f6212;border:1px solid rgba(132,204,22,0.3);font-weight:800;letter-spacing:0.8px;padding:4px 10px;border-radius:8px;"><?php echo sa_e($realQuoteId); ?></span>
                    </td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($q['company_name'])); ?></span>
                            <span class="sa-cell-text">
                                <strong><?php echo sa_e($q['company_name']); ?></strong>
                                <span><?php echo sa_e($q['location'] ? $q['location'] : 'Location not given'); ?></span>
                            </span>
                        </div>
                    </td>
                    <td>
                        <span class="sa-cell-text">
                            <strong style="font-weight:500"><?php echo sa_e($q['contact_person']); ?></strong>
                            <span><?php echo sa_e($q['email']); ?></span>
                        </span>
                    </td>
                    <td><span class="sa-badge sa-badge-info"><?php echo sa_e($q['category_name'] ? $q['category_name'] : 'Uncategorised'); ?></span></td>
                    <td><span class="sa-badge sa-badge-plan"><?php echo sa_e($q['plan_name'] ? $q['plan_name'] : 'No plan picked'); ?></span></td>
                    <td class="num" data-sort-value="<?php echo (int) $q['expected_ratings']; ?>">
                        <?php echo sa_e(sa_num($q['num_companies'])); ?> cos ·
                        <?php echo sa_e(sa_num($q['expected_ratings'])); ?> ratings
                    </td>
                    <td data-sort-value="<?php echo sa_e($q['status']); ?>">
                        <form method="POST" action="quote_requests.php" style="display:inline">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="quote_id" value="<?php echo (int) $q['id']; ?>">
                            <select class="sa-inline-select" name="status" onchange="this.form.submit()" aria-label="Status for <?php echo sa_e($q['company_name']); ?>">
<?php foreach (['pending' => 'Pending', 'contacted' => 'Contacted', 'converted' => 'Converted', 'rejected' => 'Rejected'] as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>"<?php echo $q['status'] === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
<?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td data-sort-value="<?php echo sa_e($q['created_at']); ?>"><?php echo sa_e(sa_date($q['created_at'])); ?></td>
                    <td data-no-export>
                        <div class="sa-row-actions">
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="quote_requests.php?id=<?php echo (int) $q['id']; ?>" title="View details">
                                <?php echo sa_icon('eye'); ?>
                            </a>
<?php if ($q['status'] !== 'converted'): ?>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-primary" title="Convert to tenant — will email password setup link"
                                    data-sa-convert
                                    data-id="<?php echo (int) $q['id']; ?>"
                                    data-company="<?php echo sa_e($q['company_name']); ?>"
                                    data-email="<?php echo sa_e($q['email']); ?>"
                                    data-phone="<?php echo sa_e($q['phone']); ?>"
                                    data-plan="<?php echo (int) $q['plan_id']; ?>">
                                <?php echo sa_icon('zap'); ?> Convert
                            </button>
<?php endif; ?>
                            <form method="POST" action="quote_requests.php" style="display:inline"
                                  onsubmit="return confirm('Delete the request from <?php echo sa_e(addslashes($q['company_name'])); ?>?');">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="quote_id" value="<?php echo (int) $q['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-danger" title="Delete request"><?php echo sa_icon('trash'); ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="sa-empty" id="quotesTableEmpty" hidden>
        <?php echo sa_icon('search'); ?>
        <strong>No matching requests</strong>
        <p>Nothing in the pipeline matches that text.</p>
    </div>
</section>

<?php if ($selected): ?>
<?php
    $selRealId = $selected['public_id'] ?? ('QTE-' . str_pad((string)$selected['id'], 6, '0', STR_PAD_LEFT));
    $selTenant = null;
    if (!empty($selected['converted_tenant_id'])) {
        $selTenant = sa_one($conn, "SELECT id, public_id, company_name, email FROM tenants WHERE id = " . (int)$selected['converted_tenant_id'], 'tenants');
    }
?>
<div class="sa-grid sa-split-2-1 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3><?php echo sa_e($selected['company_name']); ?> <span style="font-family:monospace;background:rgba(194,245,66,0.15);color:#3f6212;border:1px solid rgba(132,204,22,0.3);font-weight:800;letter-spacing:0.8px;padding:3px 10px;border-radius:8px;font-size:13px;margin-left:8px;"><?php echo sa_e($selRealId); ?></span></h3>
                <p>Received <?php echo sa_e(sa_date($selected['created_at'], 'M d, Y H:i')); ?> &middot; <?php echo sa_time_ago($selected['created_at']); ?></p>
            </div>
            <div class="sa-card-head-actions">
                <?php echo sa_status_badge($selected['status']); ?>
                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="quote_requests.php"><?php echo sa_icon('x'); ?> Close</a>
            </div>
        </div>
        <div class="sa-card-pad">
            <dl class="sa-kv">
                <div class="sa-kv-row"><dt>Real Quota ID</dt><dd><span class="sa-mono" style="font-weight:800;color:#65a30d;"><?php echo sa_e($selRealId); ?></span></dd></div>
                <div class="sa-kv-row"><dt>Contact person</dt><dd><?php echo sa_e($selected['contact_person']); ?></dd></div>
                <div class="sa-kv-row"><dt>Email</dt><dd><a href="mailto:<?php echo sa_e($selected['email']); ?>" style="color:var(--sa-accent);text-decoration:none"><?php echo sa_e($selected['email']); ?></a></dd></div>
                <div class="sa-kv-row"><dt>Phone</dt><dd><?php echo sa_e($selected['phone'] ? $selected['phone'] : '—'); ?></dd></div>
                <div class="sa-kv-row"><dt>Website</dt><dd><?php echo sa_e($selected['website'] ? $selected['website'] : '—'); ?></dd></div>
                <div class="sa-kv-row"><dt>Location</dt><dd><?php echo sa_e($selected['location'] ? $selected['location'] : '—'); ?></dd></div>
                <div class="sa-kv-row"><dt>Category</dt><dd><?php echo sa_e($selected['category_name'] ? $selected['category_name'] : '—'); ?></dd></div>
                <div class="sa-kv-row"><dt>Plan of interest</dt><dd><?php echo sa_e($selected['plan_name'] ? $selected['plan_name'] : '—'); ?></dd></div>
                <div class="sa-kv-row"><dt>Companies to list</dt><dd><?php echo sa_e(sa_num($selected['num_companies'])); ?></dd></div>
                <div class="sa-kv-row"><dt>Expected ratings / month</dt><dd><?php echo sa_e(sa_num($selected['expected_ratings'])); ?></dd></div>
<?php if ($selTenant): ?>
                <div class="sa-kv-row"><dt>Converted to Tenant</dt><dd><span class="sa-mono" style="font-weight:800;color:#2563eb;"><?php echo sa_e($selTenant['public_id'] ?? ('#'.$selTenant['id'])); ?></span> — <?php echo sa_e($selTenant['company_name']); ?> (<?php echo sa_e($selTenant['email']); ?>)</dd></div>
<?php endif; ?>
            </dl>

            <div class="sa-section-title" style="margin:20px 0 10px">Notes</div>
            <p class="sa-muted" style="font-size:13.4px;line-height:1.65">
                <?php echo $selected['notes'] ? nl2br(sa_e($selected['notes'])) : 'No notes were left with this request.'; ?>
            </p>
        </div>
        <div class="sa-card-foot">
            <span>Pipeline value: <?php echo sa_e($selected['plan_name'] ? sa_money(sa_scalar($conn, "SELECT price FROM subscription_plans WHERE id = " . (int) $selected['plan_id'], 0, 'subscription_plans')) : '—'); ?> / month</span>
            <a href="mailto:<?php echo sa_e($selected['email']); ?>?subject=<?php echo rawurlencode('Your Optibiz enquiry — Ref ' . $selRealId); ?>" class="sa-btn sa-btn-sm sa-btn-ghost"><?php echo sa_icon('mail'); ?> Reply by email</a>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div><h3>Move it forward</h3><p>Convert — tenant gets Real ID + setup email</p></div>
        </div>
        <div class="sa-card-pad sa-stack" style="gap:12px">
<?php if ($selected['status'] !== 'converted'): ?>
            <div style="padding:12px 14px;background:rgba(194,245,66,0.08);border:1px solid rgba(194,245,66,0.2);border-radius:10px;font-size:12.5px;line-height:1.5;color:#3f6212;">
                <strong>New flow:</strong> When you convert, the tenant will receive a <strong>Real Account ID</strong> (e.g. OPT-8K2F9Q1A) and an email to set up their password. No manual password needed.
            </div>
            <button type="button" class="sa-btn sa-btn-primary sa-btn-block"
                    data-sa-convert
                    data-id="<?php echo (int) $selected['id']; ?>"
                    data-company="<?php echo sa_e($selected['company_name']); ?>"
                    data-email="<?php echo sa_e($selected['email']); ?>"
                    data-phone="<?php echo sa_e($selected['phone']); ?>"
                    data-plan="<?php echo (int) $selected['plan_id']; ?>">
                <?php echo sa_icon('zap'); ?> Convert to tenant & send setup email
            </button>
<?php else: ?>
            <div class="sa-alert sa-alert-success" style="margin:0">
                <?php echo sa_icon('check-circle'); ?>
                <div><strong>Already converted</strong> This request (<?php echo sa_e($selRealId); ?>) has been turned into a tenant<?php echo $selTenant ? ' — ' . sa_e($selTenant['public_id'] ?? '') : ''; ?>.</div>
            </div>
<?php if ($selTenant): ?>
            <form method="POST" action="quote_requests.php" class="sa-form" style="gap:10px">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="resend_setup">
                <input type="hidden" name="quote_id" value="<?php echo (int) $selected['id']; ?>">
                <button type="submit" class="sa-btn sa-btn-ghost sa-btn-block"><?php echo sa_icon('mail'); ?> Resend password setup email</button>
            </form>
<?php endif; ?>
<?php endif; ?>

            <form method="POST" action="quote_requests.php" class="sa-form" style="gap:10px">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="quote_id" value="<?php echo (int) $selected['id']; ?>">
                <div class="sa-field">
                    <label for="detail_status">Pipeline stage</label>
                    <select id="detail_status" class="sa-select" name="status">
<?php foreach (['pending' => 'Pending', 'contacted' => 'Contacted', 'converted' => 'Converted', 'rejected' => 'Rejected'] as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"<?php echo $selected['status'] === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
<?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="sa-btn sa-btn-ghost sa-btn-block"><?php echo sa_icon('save'); ?> Save stage</button>
            </form>

            <form method="POST" action="quote_requests.php"
                  onsubmit="return confirm('Delete this request permanently?');">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="quote_id" value="<?php echo (int) $selected['id']; ?>">
                <button type="submit" class="sa-btn sa-btn-danger sa-btn-block"><?php echo sa_icon('trash'); ?> Delete request</button>
            </form>
        </div>
    </section>
</div>
<?php endif; ?>

<!-- ============ CONVERT DIALOG ============ -->
<dialog class="sa-dialog" id="convertDialog" aria-labelledby="convertDialogTitle">
    <form method="POST" action="quote_requests.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="convert">
        <input type="hidden" name="quote_id" id="cv_id" value="">
        <div class="sa-dialog-head">
            <div>
                <h3 id="convertDialogTitle">Convert to a tenant</h3>
                <p id="cv_subtitle">Creates the tenant with a Real ID and sends password setup email.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>
        <div class="sa-dialog-body">
            <div style="margin-bottom:16px;padding:12px 14px;background:rgba(194,245,66,0.08);border:1px solid rgba(194,245,66,0.2);border-radius:10px;font-size:13px;line-height:1.5;color:#3f6212;">
                <strong>What happens on convert:</strong><br>
                • Generates Real Tenant ID (e.g. OPT-8K2F9Q1A) not database ID<br>
                • Creates login username from company name<br>
                • Sends email with secure link to set password (valid 48h)<br>
                • Tenant can then sign in at /admin/login.php
            </div>
            <div class="sa-form-grid">
                <div class="sa-field">
                    <label for="cv_company">Company name *</label>
                    <input id="cv_company" type="text" name="company_name" required>
                </div>
                <div class="sa-field">
                    <label for="cv_email">Email * (will receive setup link)</label>
                    <input id="cv_email" type="email" name="email" required>
                </div>
                <div class="sa-field">
                    <label for="cv_phone">Phone</label>
                    <input id="cv_phone" type="tel" name="phone">
                </div>
                <div class="sa-field">
                    <label for="cv_plan">Plan</label>
                    <select id="cv_plan" name="plan_id">
<?php foreach ($plans as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>"><?php echo sa_e($p['plan_name'] . ' — ' . sa_money($p['price']) . '/mo'); ?></option>
<?php endforeach; ?>
<?php if (!$plans): ?>
                        <option value="0">No active plans</option>
<?php endif; ?>
                    </select>
                </div>
                <div class="sa-field">
                    <label for="cv_status">Starts as</label>
                    <select id="cv_status" name="subscription_status">
                        <option value="trial">Trial</option>
                        <option value="active">Active (paying)</option>
                    </select>
                </div>
                <div class="sa-field">
                    <label for="cv_months">Length (months)</label>
                    <input id="cv_months" type="number" name="months" min="0" max="60" value="1">
                    <span class="sa-hint">0 = no expiry. Email link always 48h.</span>
                </div>
            </div>
        </div>
        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('mail'); ?> Create & email setup link</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    function open(sel) {
        var d = document.querySelector(sel);
        if (!d) { return; }
        if (typeof d.showModal === 'function') { d.showModal(); }
        else { d.setAttribute('open', ''); d.classList.add('is-open-fallback'); }
    }
    document.querySelectorAll('[data-sa-convert]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = btn.dataset;
            document.getElementById('cv_id').value = d.id;
            document.getElementById('cv_company').value = d.company;
            document.getElementById('cv_email').value = d.email;
            document.getElementById('cv_phone').value = d.phone;
            document.getElementById('cv_plan').value = d.plan || '';
            document.getElementById('cv_subtitle').textContent =
                'Converting quote ' + (d.company || '') + ' — Real ID will be generated & setup email sent to ' + (d.email || '');
            open('#convertDialog');
        });
    });
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/_shell_footer.php'; ?>
