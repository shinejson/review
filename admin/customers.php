<?php
/**
 * ============================================================
 *  Admin — Customer List & Email Composer
 * ============================================================
 *  Shows every customer who signed up on the public rating
 *  portal (named review submission). Each customer went through
 *  the Follow + Like steps to earn their Verified badge.
 *  Workspace owners can browse the list, track engagement,
 *  mark customers verified, delete entries, export CSV and
 *  email customers in bulk.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requireTeamAccess('customers');

$tenant_id = getTenantId();
$is_tenant = isTenant();

ensureSiteCustomersTable($conn);

// Load primary company profile (for brand name in emails)
$brand_name = 'Your Business';
if ($tenant_id) {
    $cp = $conn->prepare("SELECT c.company_name FROM customers c WHERE c.tenant_id=? ORDER BY c.id ASC LIMIT 1");
    $cp->bind_param("i", $tenant_id);
    $cp->execute();
    $cp_row = $cp->get_result()->fetch_assoc();
    $cp->close();
    if ($cp_row && !empty($cp_row['company_name'])) {
        $brand_name = $cp_row['company_name'];
    } else {
        $tp = $conn->prepare("SELECT company_name FROM tenants WHERE id=? LIMIT 1");
        $tp->bind_param("i", $tenant_id);
        $tp->execute();
        $tp_row = $tp->get_result()->fetch_assoc();
        $tp->close();
        if ($tp_row && !empty($tp_row['company_name'])) {
            $brand_name = $tp_row['company_name'];
        }
    }
}

$success = '';
$error   = '';

/**
 * Collect the recipient rows for a bulk email based on the
 * requested scope (always tenant-scoped, always capped).
 */
function collectEmailRecipients($conn, $tenant_id, $scope, $selected_ids, $cap = 100) {
    $where  = [];
    $params = [];
    $types  = '';

    $tenant_id = (int)$tenant_id;
    if ($tenant_id > 0) {
        $where[] = "sc.tenant_id = ?";
        $params[] = $tenant_id;
        $types .= 'i';
    }
    $where[] = "TRIM(IFNULL(sc.customer_email, '')) <> ''";

    if ($scope === 'verified') {
        $where[] = "sc.is_verified = 1";
    } elseif ($scope === 'in_progress') {
        $where[] = "sc.is_verified = 0";
    } elseif ($scope === 'selected') {
        // client sends a comma-separated string (or an array)
        $ids = [];
        foreach ((array)$selected_ids as $part) {
            foreach (explode(',', (string)$part) as $p) {
                $p = (int)trim($p);
                if ($p > 0) $ids[] = $p;
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "sc.id IN ($ph)";
        foreach ($ids as $id) $params[] = $id;
        $types .= str_repeat('i', count($ids));
    }

    $sql = "SELECT sc.* FROM site_customers sc
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sc.created_at DESC
            LIMIT " . max(1, (int)$cap);

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return is_array($rows) ? $rows : [];
}

// ============================================================
// POST Request Handlers
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!sa_csrf_ok()) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
    $action = $_POST['action'];

    // 1. Bulk email to customers
    if ($action === 'send_email') {
        $scope   = (string)($_POST['email_scope'] ?? 'selected');
        $ids     = $_POST['customer_ids'] ?? [];
        $subject = trim((string)($_POST['email_subject'] ?? ''));
        $body    = trim((string)($_POST['email_body'] ?? ''));

        if ($subject === '' || $body === '') {
            $error = 'Please provide both a subject line and a message before sending.';
        } else {
            $recipients = collectEmailRecipients($conn, $tenant_id, $scope, $ids, 100);

            if (empty($recipients)) {
                $error = 'No eligible recipients with an email address for that selection.';
            } else {
                $sent   = 0;
                $failed = 0;
                $last_err = '';

                foreach ($recipients as $rec) {
                    $to_email = strtolower(trim((string)$rec['customer_email']));
                    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
                        $failed++;
                        continue;
                    }

                    $first_name = strtok(trim((string)$rec['customer_name']), ' ');
                    $text = str_replace(
                        ['{name}', '{company}', '{first_name}'],
                        [$first_name, $brand_name, $first_name],
                        $body
                    );

                    $html = sa_render_email_template($subject, nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')), $brand_name);
                    $res  = sa_send_mail($to_email, $subject, $html, $conn, ['smtp_timeout' => 5]);

                    if (!empty($res['success'])) {
                        $sent++;
                    } else {
                        $failed++;
                        $last_err = $res['message'] ?? 'Unknown error';
                    }
                }

                if ($sent > 0) {
                    header('Location: customers.php?msg=sent&sent=' . (int)$sent . '&failed=' . (int)$failed . '&last_err=' . rawurlencode(mb_substr($last_err, 0, 160)));
                    exit;
                }
                $error = "Could not send the email. Last error: " . htmlspecialchars($last_err ?: 'unknown', ENT_QUOTES, 'UTF-8');
            }
        }
    }

    // 2. Toggle verified badge (manual override by the workspace)
    elseif ($action === 'toggle_verified') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        if ($cid > 0) {
            if ($tenant_id > 0) {
                $chk = $conn->prepare("SELECT id, is_verified FROM site_customers WHERE id = ? AND tenant_id = ? LIMIT 1");
                $chk->bind_param("ii", $cid, $tenant_id);
            } else {
                $chk = $conn->prepare("SELECT id, is_verified FROM site_customers WHERE id = ? LIMIT 1");
                $chk->bind_param("i", $cid);
            }
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($row) {
                $new_state = (int)$row['is_verified'] === 1 ? 0 : 1;
                $vtype     = $new_state ? 'manual' : null;

                $upd = $conn->prepare("UPDATE site_customers SET is_verified = ?, verification_type = ? WHERE id = ?");
                $upd->bind_param("isi", $new_state, $vtype, $cid);
                $upd->execute();
                $upd->close();

                // Keep the linked review badge in sync
                if ($new_state) {
                    $conn->query("UPDATE ratings SET is_verified = 1, verification_type = 'manual' WHERE id = (SELECT rating_id FROM site_customers WHERE id = $cid) AND rating_id IS NOT NULL AND (is_verified = 0 OR is_verified IS NULL)");
                } else {
                    $conn->query("UPDATE ratings SET is_verified = 0, verification_type = NULL WHERE id = (SELECT rating_id FROM site_customers WHERE id = $cid) AND rating_id IS NOT NULL AND verification_type IN ('follow_like', 'manual')");
                }

                header('Location: customers.php?msg=toggled');
                exit;
            }
            $error = 'Customer record not found.';
        }
    }

    // 3. Delete a customer record (keeps their public review)
    elseif ($action === 'delete') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        if ($cid > 0) {
            if ($tenant_id > 0) {
                $del = $conn->prepare("DELETE FROM site_customers WHERE id = ? AND tenant_id = ?");
                $del->bind_param("ii", $cid, $tenant_id);
            } else {
                $del = $conn->prepare("DELETE FROM site_customers WHERE id = ?");
                $del->bind_param("i", $cid);
            }
            $del->execute();
            $del->close();
            header('Location: customers.php?msg=deleted');
            exit;
        }
    }
    } // end CSRF-validated POST block
}

// Flash message banners
$msg    = $_GET['msg'] ?? '';
if ($msg === 'sent') {
    $sent   = (int)($_GET['sent'] ?? 0);
    $failed = (int)($_GET['failed'] ?? 0);
    $last   = (string)($_GET['last_err'] ?? '');
    $success = "Email delivered to {$sent} customer" . ($sent === 1 ? '' : 's')
             . ($failed > 0 ? " — {$failed} failed. Last error: " . $last : '.');
}
if ($msg === 'toggled') $success = 'Verification status updated. The customer\'s review badge was synced.';
if ($msg === 'deleted') $success = 'Customer record removed. Their public review is kept.';

// ============================================================
// CSV Export
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filter = (string)($_GET['filter'] ?? 'all');
    $q      = trim((string)($_GET['q'] ?? ''));
    $rows   = getAdminSiteCustomers($conn, $tenant_id, $filter, $q, 100000);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'name', 'email', 'phone', 'company', 'rating', 'followed', 'follow_platform', 'liked', 'verified', 'verification_type', 'momo_ref', 'signed_up']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['customer_name'], $r['customer_email'], $r['customer_phone'] ?? '',
            $r['company_name'] ?? '', $r['rating_value'] ?? '',
            (int)$r['is_following'], $r['follow_platform'] ?? '',
            (int)$r['is_liked'], (int)$r['is_verified'], $r['verification_type'] ?? '',
            $r['momo_ref'] ?? '', $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// Metrics Computation
// ============================================================
$stat_sql = "SELECT COUNT(*) AS total,
        COALESCE(SUM(sc.is_verified = 1), 0) AS verified,
        COALESCE(SUM(sc.is_following = 1), 0) AS following,
        COALESCE(SUM(sc.is_liked = 1), 0) AS liked,
        COALESCE(SUM(CASE WHEN TRIM(IFNULL(sc.customer_email, '')) <> '' THEN 1 ELSE 0 END), 0) AS with_email
        FROM site_customers sc WHERE 1=1";
$stat_params = [];
$stat_types  = '';
if ($tenant_id > 0) {
    $stat_sql .= " AND sc.tenant_id = ?";
    $stat_params[] = $tenant_id;
    $stat_types .= 'i';
}
$stat_stmt = $conn->prepare($stat_sql);
if ($stat_types) {
    $stat_stmt->bind_param($stat_types, ...$stat_params);
}
$stat_stmt->execute();
$stats = $stat_stmt->get_result()->fetch_assoc();
$stat_stmt->close();

$total_c    = (int)($stats['total'] ?? 0);
$verified_c = (int)($stats['verified'] ?? 0);
$following_c = (int)($stats['following'] ?? 0);
$liked_c    = (int)($stats['liked'] ?? 0);
$email_c    = (int)($stats['with_email'] ?? 0);

// Filters and search term
$current_filter = $_GET['filter'] ?? 'all';
if (!in_array($current_filter, ['all', 'verified', 'in_progress', 'following', 'no_email'], true)) {
    $current_filter = 'all';
}
$search_term = trim((string)($_GET['q'] ?? ''));

$customers = getAdminSiteCustomers($conn, $tenant_id, $current_filter, $search_term, 500);

if ($is_tenant) {
    $public_url = getCompanyPublicRatingUrl(0, '', ['tenant' => (int)$tenant_id]);
} else {
    $public_url = getCompanyPublicRatingUrl(0, '');
}

$BASE      = '../';
$pageTitle = 'Customers';
$activeNav = 'customers';
include __DIR__ . '/_shell.php';
?>

<style>
/* ── Customers page ── */
.cust-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff; display: grid; place-items: center;
    font-weight: 800; font-size: 13px; flex-shrink: 0;
}
.cust-badge-verified {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px; border-radius: 99px;
    background: #dcfce7; color: #15803d; border: 1px solid #86efac;
    font-size: 10.5px; font-weight: 700;
}
.cust-badge-progress {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px; border-radius: 99px;
    background: #fef3c7; color: #92400e; border: 1px solid #fde68a;
    font-size: 10.5px; font-weight: 700;
}
.cust-check { color: #059669; font-weight: 800; }
.cust-miss  { color: #cbd5e1; }
.cust-mail {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 11px; border-radius: 8px; border: 1px solid var(--line);
    background: var(--card-bg, #fff); color: var(--ink) !important;
    font-size: 11.5px; font-weight: 700; text-decoration: none;
    transition: all .15s ease;
}
.cust-mail:hover { border-color: #059669; color: #059669 !important; }
.cust-wa {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 11px; border-radius: 8px; border: 1px solid #bbf7d0;
    background: #f0fdf4; color: #15803d !important;
    font-size: 11.5px; font-weight: 700; text-decoration: none;
}
.cust-wa:hover { background: #dcfce7; }
.cust-mini-btn {
    padding: 5px 10px; border-radius: 8px; border: 1px solid var(--line);
    background: var(--card-bg, #fff); color: var(--muted) !important;
    font-size: 11.5px; font-weight: 700; cursor: pointer; text-decoration: none;
    transition: all .15s ease; font-family: inherit;
}
.cust-mini-btn:hover { border-color: var(--ink); color: var(--ink) !important; }
.cust-mini-btn.danger:hover { border-color: #ef4444; color: #ef4444 !important; background: #fef2f2; }
.cust-composer {
    background: var(--card-bg, #fff);
    border: 1.5px solid var(--line);
    border-radius: 16px;
    padding: 22px;
    margin-bottom: 24px;
    box-shadow: 0 3px 14px rgba(24,44,68,.04);
}
.cust-composer-label { font-size: 12.5px; font-weight: 700; color: var(--ink); display: block; margin-bottom: 6px; }
.cust-composer-input, .cust-composer-textarea {
    width: 100%; background: var(--bg); border: 1px solid var(--line);
    color: var(--ink); border-radius: 10px; padding: 10px 14px;
    font-size: 13.5px; font-family: inherit;
    transition: border-color .15s ease;
}
.cust-composer-input:focus, .cust-composer-textarea:focus {
    outline: none; border-color: #059669;
}
.cust-composer-textarea { resize: vertical; min-height: 110px; }
.cust-scope-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 14px; border-radius: 99px; border: 1.5px solid var(--line);
    background: var(--card-bg, #fff); color: var(--muted);
    font-size: 12px; font-weight: 700; cursor: pointer;
    transition: all .15s ease;
}
.cust-scope-pill input { accent-color: #059669; }
.cust-scope-pill.checked { border-color: #059669; background: #ecfdf5; color: #065f46; }
.cust-banner-success {
    background: #dcfce7; border: 1px solid #86efac; color: #15803d;
    padding: 12px 18px; border-radius: 12px; margin-bottom: 20px;
    font-weight: 600; display: flex; align-items: center; gap: 8px; font-size: 13.5px;
}
.cust-banner-error {
    background: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c;
    padding: 12px 18px; border-radius: 12px; margin-bottom: 20px;
    font-weight: 600; display: flex; align-items: center; gap: 8px; font-size: 13.5px;
}
:root[data-theme='dark'] .cust-composer { background: #0f1f2e; }
:root[data-theme='dark'] .cust-composer-input,
:root[data-theme='dark'] .cust-composer-textarea { background: rgba(255,255,255,.05); color: #e2e8f0; }
:root[data-theme='dark'] .cust-scope-pill { background: rgba(255,255,255,.04); color: #94a3b8; }
:root[data-theme='dark'] .cust-scope-pill.checked { background: rgba(20,83,45,.3); color: #6ee7b7; }
:root[data-theme='dark'] .cust-banner-success { background: rgba(20,83,45,.35); border-color: rgba(134,239,172,.25); color: #86efac; }
:root[data-theme='dark'] .cust-banner-error { background: rgba(127,29,29,.35); border-color: rgba(252,165,165,.2); color: #fca5a5; }
:root[data-theme='dark'] .cust-wa { background: rgba(20,83,45,.25); color: #86efac !important; border-color: rgba(134,239,172,.2); }
:root[data-theme='dark'] .cust-miss { color: #475569; }
</style>

<!-- Header Row -->
<div class="welcome-row" style="margin-bottom:24px;">
    <div>
        <p class="eyebrow">Signed-up customers &middot; Follow &amp; Like funnel</p>
        <h1 style="margin:0;">Customer List</h1>
        <p class="muted" style="margin-top:6px;">
            Every customer who submitted a named review, their follow / like progress, verified badge status — and your email list.
        </p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <a href="customers.php?export=csv<?php echo $search_term ? '&q=' . urlencode($search_term) : ''; ?>&filter=<?php echo urlencode($current_filter); ?>" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
            <span>⬇</span> Export CSV
        </a>
        <a href="<?php echo htmlspecialchars($public_url); ?>" target="_blank" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
            <span>↗</span> View Rating Portal
        </a>
    </div>
</div>

<?php if (!empty($success)): ?>
    <div class="cust-banner-success"><span>✓</span> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="cust-banner-error"><span>⚠️</span> <?php echo $error; ?></div>
<?php endif; ?>

<!-- Metrics Bar -->
<div class="metric-grid" style="margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon blue">👥</div>
        <span>Total Customers</span>
        <strong><?php echo number_format($total_c); ?></strong>
        <small>Signed up via rating portal</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon green">✓</div>
        <span>Verified Badge</span>
        <strong><?php echo number_format($verified_c); ?></strong>
        <small>Completed follow + like</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon purple">👤</div>
        <span>Following</span>
        <strong><?php echo number_format($following_c); ?></strong>
        <small>Confirmed “I have followed”</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon lime">✉️</div>
        <span>Email List</span>
        <strong><?php echo number_format($email_c); ?></strong>
        <small>Customers reachable by email</small>
    </div>
</div>

<!-- Bulk Email Composer -->
<form method="POST" action="customers.php" id="custEmailForm">
    <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="send_email">
    <div class="cust-composer">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
            <div>
                <h3 style="margin:0;font-size:16px;color:var(--ink);display:flex;align-items:center;gap:8px;">✉️ Send Email to Customers</h3>
                <p class="muted" style="margin:4px 0 0;font-size:12.5px;">
                    Promos, feedback requests, event invites… Use <strong>{name}</strong> and <strong>{company}</strong> to personalise. Max 100 recipients per send.
                </p>
            </div>
        </div>

        <label class="cust-composer-label">Who should receive it?</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
            <label class="cust-scope-pill<?php echo !isset($_POST['email_scope']) ? ' checked' : ''; ?>">
                <input type="radio" name="email_scope" value="selected" checked onchange="custScopeChanged('selected')">
                Selected on this page (<span id="custSelectedCount">0</span>)
            </label>
            <label class="cust-scope-pill">
                <input type="radio" name="email_scope" value="verified" onchange="custScopeChanged('verified')">
                All verified (<?php echo number_format($verified_c); ?>)
            </label>
            <label class="cust-scope-pill">
                <input type="radio" name="email_scope" value="in_progress" onchange="custScopeChanged('in_progress')">
                All in progress
            </label>
            <label class="cust-scope-pill">
                <input type="radio" name="email_scope" value="all" onchange="custScopeChanged('all')">
                Everyone with email (<?php echo number_format($email_c); ?>)
            </label>
        </div>

        <input type="hidden" name="customer_ids" id="custSelectedIds" value="">

        <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;margin-bottom:14px;">
            <div>
                <label class="cust-composer-label" for="email_subject">Subject <span style="color:#ef4444;">*</span></label>
                <input type="text" name="email_subject" id="email_subject" required class="cust-composer-input" placeholder="e.g. Special offer just for you, {first_name}!" maxlength="160">
            </div>
            <div>
                <label class="cust-composer-label">Preview recipient</label>
                <div class="cust-composer-input" style="display:flex;align-items:center;color:var(--muted);font-size:12.5px;">
                    <span id="custPreviewTo">Select a scope or tick customers in the table below…</span>
                </div>
            </div>
        </div>

        <label class="cust-composer-label" for="email_body">Message <span style="color:#ef4444;">*</span></label>
        <textarea name="email_body" id="email_body" required class="cust-composer-textarea" placeholder="Hi {first_name},&#10;&#10;Thank you for supporting {company}…"></textarea>

        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px;">
            <span style="font-size:12px;color:var(--muted);">🔒 Sent from your configured SMTP account. Recipients only ever receive your message — their email is not exposed to other customers.</span>
            <button type="submit" class="btn btn-primary" style="background:#059669;border-color:#059669;display:inline-flex;align-items:center;gap:8px;">
                <span>📤</span> Send Email
            </button>
        </div>
    </div>
</form>

<!-- Filters & Search -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px;">
    <div class="admin-filter-pills">
        <a href="customers.php<?php echo $search_term ? '?q=' . urlencode($search_term) : ''; ?>" class="admin-pill-btn<?php echo $current_filter === 'all' ? ' is-active' : ''; ?>">
            All <span style="opacity:.75;"><?php echo number_format($total_c); ?></span>
        </a>
        <a href="customers.php?filter=verified<?php echo $search_term ? '&q=' . urlencode($search_term) : ''; ?>" class="admin-pill-btn<?php echo $current_filter === 'verified' ? ' is-active' : ''; ?>">
            ✓ Verified <span style="opacity:.75;"><?php echo number_format($verified_c); ?></span>
        </a>
        <a href="customers.php?filter=in_progress<?php echo $search_term ? '&q=' . urlencode($search_term) : ''; ?>" class="admin-pill-btn<?php echo $current_filter === 'in_progress' ? ' is-active' : ''; ?>">
            ⏳ In progress
        </a>
        <a href="customers.php?filter=following<?php echo $search_term ? '&q=' . urlencode($search_term) : ''; ?>" class="admin-pill-btn<?php echo $current_filter === 'following' ? ' is-active' : ''; ?>">
            👤 Following <span style="opacity:.75;"><?php echo number_format($following_c); ?></span>
        </a>
        <a href="customers.php?filter=no_email<?php echo $search_term ? '&q=' . urlencode($search_term) : ''; ?>" class="admin-pill-btn<?php echo $current_filter === 'no_email' ? ' is-active' : ''; ?>">
            ✉️ No email
        </a>
    </div>

    <form method="GET" action="customers.php" style="display:flex;align-items:center;gap:8px;min-width:240px;">
        <?php if ($current_filter !== 'all'): ?>
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($current_filter); ?>">
        <?php endif; ?>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Search name, email, phone…"
               class="cust-composer-input" style="width:100%;padding:8px 14px;font-size:13px;">
        <?php if ($search_term !== ''): ?>
            <a href="customers.php<?php echo $current_filter !== 'all' ? '?filter=' . urlencode($current_filter) : ''; ?>" class="btn btn-secondary" style="padding:8px 12px;font-size:12px;text-decoration:none;flex-shrink:0;">✕</a>
        <?php endif; ?>
    </form>
</div>

<!-- Customers Table -->
<div class="data-table-card">
    <div class="table-scroll-wrap">
        <table class="data-table" id="custTable">
            <thead>
                <tr>
                    <th style="width:34px;"><input type="checkbox" id="custSelectAll" aria-label="Select all visible customers"></th>
                    <th>Customer</th>
                    <?php if (!$is_tenant || $total_c > 1): ?><th>Company</th><?php endif; ?>
                    <th>Rating</th>
                    <th>Contact</th>
                    <th>Followed</th>
                    <th>Liked</th>
                    <th>Signed up</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($customers)): ?>
                    <?php foreach ($customers as $c):
                        $c_id       = (int)$c['id'];
                        $c_name     = htmlspecialchars($c['customer_name'] ?: 'Customer');
                        $c_email    = trim((string)($c['customer_email'] ?? ''));
                        $c_phone    = trim((string)($c['customer_phone'] ?? ''));
                        $c_company  = htmlspecialchars($c['company_name'] ?? ('#' . (int)$c['company_id']));
                        $c_rating   = (int)($c['rating_value'] ?? 0);
                        $c_verified = (int)$c['is_verified'] === 1;
                        $c_following = (int)$c['is_following'] === 1;
                        $c_liked    = (int)$c['is_liked'] === 1;
                        $c_platform = trim((string)($c['follow_platform'] ?? ''));
                        $c_ts       = date('M j, Y', strtotime($c['created_at']));
                        $c_wa_digits = preg_replace('/[^0-9]/', '', $c_phone);
                        $c_mailto_subject = rawurlencode('Hi ' . strtok(trim((string)$c['customer_name']), ' ') . ', a message from ' . $brand_name);
                    ?>
                    <tr>
                        <td><input type="checkbox" class="cust-cb" data-id="<?php echo $c_id; ?>" data-email="<?php echo htmlspecialchars($c_email, ENT_QUOTES); ?>" data-name="<?php echo htmlspecialchars(strtok(trim((string)$c['customer_name']), ' '), ENT_QUOTES); ?>" aria-label="Select <?php echo $c_name; ?>"></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div class="cust-avatar"><?php echo htmlspecialchars(strtoupper(substr(trim((string)$c['customer_name']) ?: 'A', 0, 1))); ?></div>
                                <div>
                                    <div style="font-weight:700;color:var(--ink);font-size:13px;">
                                        <?php echo $c_name; ?>
                                        <?php if (!empty($c['momo_ref'])): ?><span title="MoMo ref on file" style="font-size:11px;color:var(--muted);font-weight:600;">· 💳</span><?php endif; ?>
                                    </div>
                                    <?php if ($c_verified): ?>
                                        <span class="cust-badge-verified" title="Verified via <?php echo htmlspecialchars($c['verification_type'] ?? 'badge'); ?>">✓ Verified</span>
                                    <?php else: ?>
                                        <span class="cust-badge-progress">⏳ In progress</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <?php if (!$is_tenant || $total_c > 1): ?><td style="color:var(--muted);font-size:12.5px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo $c_company; ?></td><?php endif; ?>
                        <td>
                            <?php if ($c_rating > 0): ?>
                                <span style="color:#f59e0b;font-weight:700;font-size:13px;"><?php echo str_repeat('★', min(5, $c_rating)); ?></span>
                                <span style="color:var(--muted);font-size:11.5px;margin-left:4px;"><?php echo $c_rating; ?>/5</span>
                            <?php else: ?>
                                <span class="cust-miss">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:3px;min-width:150px;">
                                <?php if ($c_email !== ''): ?>
                                    <span style="font-size:12px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($c_email, ENT_QUOTES); ?>">✉️ <?php echo htmlspecialchars($c_email); ?></span>
                                <?php else: ?>
                                    <span class="cust-miss" style="font-size:12px;">no email</span>
                                <?php endif; ?>
                                <?php if ($c_phone !== ''): ?>
                                    <span style="font-size:12px;color:var(--muted);">📞 <?php echo htmlspecialchars($c_phone); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($c_following): ?>
                                <span class="cust-check">✓</span>
                                <?php if ($c_platform !== ''): ?><span style="font-size:11px;color:var(--muted);text-transform:capitalize;"><?php echo htmlspecialchars($c_platform); ?></span><?php endif; ?>
                            <?php else: ?>
                                <span class="cust-miss">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c_liked): ?><span class="cust-check">✓</span><?php else: ?><span class="cust-miss">—</span><?php endif; ?>
                        </td>
                        <td style="color:var(--muted);font-size:12px;white-space:nowrap;" title="<?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($c['created_at']))); ?>"><?php echo $c_ts; ?></td>
                        <td>
                            <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
                                <?php if ($c_email !== ''): ?>
                                    <a class="cust-mail" href="mailto:<?php echo htmlspecialchars($c_email, ENT_QUOTES); ?>?subject=<?php echo $c_mailto_subject; ?>" title="Open your email app">✉️ Email</a>
                                <?php endif; ?>
                                <?php if ($c_wa_digits !== ''): ?>
                                    <a class="cust-wa" href="https://wa.me/<?php echo $c_wa_digits; ?>" target="_blank" rel="noopener noreferrer" title="Chat on WhatsApp">💬 WA</a>
                                <?php endif; ?>
                                <form method="POST" action="customers.php" style="display:inline;">
                                    <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_verified">
                                    <input type="hidden" name="customer_id" value="<?php echo $c_id; ?>">
                                    <button type="submit" class="cust-mini-btn" title="<?php echo $c_verified ? 'Remove verified badge' : 'Manually award verified badge'; ?>">
                                        <?php echo $c_verified ? '✕ Unverify' : '✓ Verify'; ?>
                                    </button>
                                </form>
                                <form method="POST" action="customers.php" style="display:inline;" onsubmit="return confirm('Remove this customer from the list? Their public review will be kept.');">
                                    <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="customer_id" value="<?php echo $c_id; ?>">
                                    <button type="submit" class="cust-mini-btn danger" title="Delete customer record">🗑</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="table-empty" style="padding:48px 20px;">
                            <div style="font-size:38px;margin-bottom:10px;">👥</div>
                            <div style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:6px;">No customers found</div>
                            <div style="font-size:13px;">
                                <?php if ($search_term !== ''): ?>
                                    No customers match “<?php echo htmlspecialchars($search_term); ?>”.
                                <?php elseif ($current_filter === 'verified'): ?>
                                    No customer has completed the follow + like steps yet. Share your rating portal link to start collecting!
                                <?php else: ?>
                                    Customers appear here as soon as they submit a named review on your public rating portal.
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($customers)): ?>
<p class="muted" style="margin-top:10px;font-size:12px;">
    Showing <?php echo count($customers); ?> of <?php echo number_format($total_c); ?> customer(s).
    <?php if (!$is_tenant): ?><span>Viewing all workspaces.</span><?php else: ?><span>Showing customers of your workspace only.</span><?php endif; ?>
</p>
<?php endif; ?>

<script>
function custCollectSelected() {
    var boxes = document.querySelectorAll('.cust-cb');
    var ids = [];
    boxes.forEach(function(b) { if (b.checked) ids.push(b.getAttribute('data-id')); });
    var hidden = document.getElementById('custSelectedIds');
    if (hidden) hidden.value = ids.join(',');
    var countEl = document.getElementById('custSelectedCount');
    if (countEl) countEl.textContent = ids.length;

    // Update preview recipient line
    var preview = document.getElementById('custPreviewTo');
    if (preview) {
        if (ids.length > 0) {
            var first = boxes[0];
            var sample = null;
            boxes.forEach(function(b) { if (!sample && b.checked) sample = b; });
            if (sample) {
                preview.textContent = ids.length + ' selected — first: ' + (sample.getAttribute('data-name') || 'Customer') + ' <' + (sample.getAttribute('data-email') || 'no email') + '>';
            }
        } else {
            preview.textContent = 'Tick customers below (or choose another scope).';
        }
    }
}

function custScopeChanged(scope) {
    // Highlight the active scope pill
    document.querySelectorAll('.cust-scope-pill').forEach(function(p) {
        var inp = p.querySelector('input');
        p.classList.toggle('checked', inp && inp.checked);
    });
    var preview = document.getElementById('custPreviewTo');
    if (preview && scope !== 'selected') {
        preview.textContent = 'Scope: ' + scope + ' — recipients are resolved on send (max 100).';
    }
    if (scope === 'selected') custCollectSelected();
}

document.addEventListener('DOMContentLoaded', function() {
    var selectAll = document.getElementById('custSelectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.cust-cb').forEach(function(b) { b.checked = selectAll.checked; });
            custCollectSelected();
        });
    }
    document.querySelectorAll('.cust-cb').forEach(function(b) {
        b.addEventListener('change', custCollectSelected);
    });
    custScopeChanged('selected');
});
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
