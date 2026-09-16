<?php
/**
 * ============================================================
 *  Super Admin — Support & Dispute Center
 * ============================================================
 *  1. Tenant Support & Platform Feedback (Tenant-to-Superadmin)
 *  2. Privacy-Shielded Review Dispute Moderation (Zero Customer Data Exposed)
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();
require_sa_permission('reviews');

sa_ensure_platform_feedback_schema($conn);

$activeTab = ($_GET['tab'] ?? 'support') === 'disputes' ? 'disputes' : 'support';

/* ============================================================
   POST Handlers
   ============================================================ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect("reviews.php?tab={$activeTab}");
    }

    $action = $_POST['action'] ?? '';

    /* --- Support Ticket Actions --- */
    if ($action === 'reply_ticket') {
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $admin_reply = trim($_POST['admin_reply'] ?? '');
        $status = trim($_POST['status'] ?? 'in_progress');
        $admin_notes = trim($_POST['admin_notes'] ?? '');

        $allowed_status = ['open', 'in_progress', 'resolved', 'closed'];
        if (!in_array($status, $allowed_status, true)) {
            $status = 'in_progress';
        }

        $resolved_sql = ($status === 'resolved' || $status === 'closed') ? ", resolved_at = NOW()" : "";

        $stmt = $conn->prepare("UPDATE platform_feedback SET admin_reply = ?, admin_notes = ?, status = ?, replied_at = NOW() {$resolved_sql} WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("sssi", $admin_reply, $admin_notes, $status, $ticket_id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', "Reply saved and ticket #T-{$ticket_id} updated to " . ucfirst(str_replace('_', ' ', $status)) . ".");
        } else {
            sa_flash('error', 'Failed to update ticket.');
        }
        redirect("reviews.php?tab=support");
    }

    if ($action === 'update_ticket_status') {
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'open');
        $allowed_status = ['open', 'in_progress', 'resolved', 'closed'];
        if (in_array($status, $allowed_status, true) && $ticket_id > 0) {
            $resolved_sql = ($status === 'resolved' || $status === 'closed') ? ", resolved_at = NOW()" : ", resolved_at = NULL";
            $conn->query("UPDATE platform_feedback SET status = '{$status}' {$resolved_sql} WHERE id = {$ticket_id}");
            sa_flash('success', "Ticket #T-{$ticket_id} marked as " . ucfirst(str_replace('_', ' ', $status)) . ".");
        }
        redirect("reviews.php?tab=support");
    }

    if ($action === 'delete_ticket') {
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        if ($ticket_id > 0) {
            $conn->query("DELETE FROM platform_feedback WHERE id = {$ticket_id}");
            sa_flash('success', "Support ticket #T-{$ticket_id} deleted.");
        }
        redirect("reviews.php?tab=support");
    }

    /* --- Privacy-Shielded Review Dispute Actions --- */
    if ($action === 'dismiss_dispute') {
        $id = (int)($_POST['rating_id'] ?? 0);
        if ($id > 0) {
            $conn->query("UPDATE ratings SET reported = 0 WHERE id = {$id}");
            if (sa_table_exists($conn, 'reported_reviews')) {
                $conn->query("DELETE FROM reported_reviews WHERE rating_id = {$id}");
            }
            sa_flash('success', "Dispute dismissed. Review #{$id} is restored to published status.");
        }
        redirect("reviews.php?tab=disputes");
    }

    if ($action === 'uphold_takedown') {
        $id = (int)($_POST['rating_id'] ?? 0);
        if ($id > 0) {
            $conn->query("UPDATE ratings SET reported = 1 WHERE id = {$id}");
            sa_flash('warning', "Takedown upheld. Review #{$id} remains unpublished from public directories.");
        }
        redirect("reviews.php?tab=disputes");
    }

    if ($action === 'purge_dispute') {
        $id = (int)($_POST['rating_id'] ?? 0);
        if ($id > 0) {
            $conn->query("DELETE FROM ratings WHERE id = {$id}");
            if (sa_table_exists($conn, 'reported_reviews')) {
                $conn->query("DELETE FROM reported_reviews WHERE rating_id = {$id}");
            }
            sa_flash('success', "Review #{$id} permanently removed for compliance.");
        }
        redirect("reviews.php?tab=disputes");
    }
}

/* ============================================================
   CSV Export (Strictly Privacy Preserved)
   ============================================================ */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');

    if ($activeTab === 'support') {
        header('Content-Disposition: attachment; filename=support-tickets-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Ticket ID', 'Workspace', 'Real ID', 'Category', 'Priority', 'Subject', 'Status', 'Admin Replied', 'Created At']);

        $export_tickets = sa_query(
            $conn,
            "SELECT pf.*, t.company_name AS tenant_name, t.public_id AS tenant_public_id
               FROM platform_feedback pf
               LEFT JOIN tenants t ON t.id = pf.tenant_id
              ORDER BY pf.created_at DESC",
            ['platform_feedback', 'tenants']
        );
        foreach ($export_tickets as $et) {
            fputcsv($out, [
                'T-' . $et['id'],
                $et['tenant_name'] ?: 'N/A',
                $et['tenant_public_id'] ?: 'N/A',
                ucwords(str_replace('_', ' ', $et['ticket_type'])),
                ucfirst($et['priority']),
                $et['subject'],
                ucfirst(str_replace('_', ' ', $et['status'])),
                !empty($et['admin_reply']) ? 'Yes' : 'No',
                $et['created_at'],
            ]);
        }
    } else {
        header('Content-Disposition: attachment; filename=review-disputes-privacy-safe-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Review Ref', 'Workspace', 'Real ID', 'Business Profile', 'Star Rating', 'Dispute Status', 'Report Reason', 'Privacy Notice', 'Reported At']);

        $export_disputes = sa_query(
            $conn,
            "SELECT r.id, r.rating, r.reported, r.created_at,
                    c.company_name AS profile_name,
                    t.company_name AS tenant_name, t.public_id AS tenant_public_id,
                    (SELECT GROUP_CONCAT(DISTINCT rep.reason SEPARATOR ' | ') FROM reported_reviews rep WHERE rep.rating_id = r.id) AS report_reasons
               FROM ratings r
               LEFT JOIN customers c ON r.company_id = c.id
               LEFT JOIN tenants t ON c.tenant_id = t.id
              WHERE r.reported = 1
              ORDER BY r.created_at DESC",
            ['ratings', 'customers', 'tenants']
        );
        foreach ($export_disputes as $ed) {
            fputcsv($out, [
                'REF-' . $ed['id'],
                $ed['tenant_name'] ?: 'N/A',
                $ed['tenant_public_id'] ?: 'N/A',
                $ed['profile_name'] ?: 'N/A',
                $ed['rating'] . ' Stars',
                $ed['reported'] ? 'Flagged / Moderation Required' : 'Dismissed',
                $ed['report_reasons'] ?: 'Flagged by workspace admin',
                '[Protected by Platform Privacy Policy — Content Redacted]',
                $ed['created_at'],
            ]);
        }
    }
    fclose($out);
    exit();
}

/* ============================================================
   Platform Counts & Metrics
   ============================================================ */
$open_tickets = (int) sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback WHERE status = 'open'", 0, 'platform_feedback');
$in_prog_tickets = (int) sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback WHERE status = 'in_progress'", 0, 'platform_feedback');
$resolved_tickets = (int) sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback WHERE status IN ('resolved', 'closed')", 0, 'platform_feedback');
$total_tickets = (int) sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback", 0, 'platform_feedback');

$flagged_disputes = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings WHERE reported = 1", 0, 'ratings');
$total_disputes = (int) sa_scalar($conn, "SELECT COUNT(*) FROM reported_reviews", 0, 'reported_reviews');

$tenants_list = sa_query($conn, "SELECT id, company_name, public_id FROM tenants ORDER BY company_name ASC", 'tenants');

/* ============================================================
   Tab 1: Support Tickets Query
   ============================================================ */
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter = isset($_GET['filter']) ? preg_replace('/[^a-z_0-9]/', '', strtolower($_GET['filter'])) : 'all';
$filter_tenant = (int) ($_GET['tenant_id'] ?? 0);

if ($activeTab === 'support') {
    $where_clauses = [];
    if ($filter === 'open') {
        $where_clauses[] = "pf.status = 'open'";
    } elseif ($filter === 'in_progress') {
        $where_clauses[] = "pf.status = 'in_progress'";
    } elseif ($filter === 'resolved') {
        $where_clauses[] = "pf.status IN ('resolved', 'closed')";
    } elseif (in_array($filter, ['support', 'feature_request', 'bug_report', 'billing', 'general'], true)) {
        $where_clauses[] = "pf.ticket_type = '{$filter}'";
    }

    if ($filter_tenant > 0) {
        $where_clauses[] = "pf.tenant_id = " . $filter_tenant;
    }

    if ($q !== '') {
        $like = '%' . $conn->real_escape_string($q) . '%';
        $where_clauses[] = "(pf.subject LIKE '{$like}' OR pf.message LIKE '{$like}' OR t.company_name LIKE '{$like}' OR t.public_id LIKE '{$like}')";
    }

    $where_sql = !empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

    $tickets_data = sa_query(
        $conn,
        "SELECT pf.*, t.company_name AS tenant_name, t.public_id AS tenant_public_id, t.email AS tenant_email
           FROM platform_feedback pf
           LEFT JOIN tenants t ON t.id = pf.tenant_id
           {$where_sql}
          ORDER BY FIELD(pf.status, 'open', 'in_progress', 'resolved', 'closed'),
                   FIELD(pf.priority, 'urgent', 'high', 'medium', 'low'),
                   pf.created_at DESC LIMIT 200",
        ['platform_feedback', 'tenants']
    );
} else {
    /* ============================================================
       Tab 2: Privacy-Shielded Disputes Query
       (STRICT PRIVACY: NO customer names, NO emails, NO raw comments)
       ============================================================ */
    $where_clauses = [];
    if ($filter === 'reported' || $filter === 'all') {
        $where_clauses[] = "r.reported = 1";
    } elseif ($filter === 'dismissed') {
        $where_clauses[] = "r.reported = 0";
    }

    if ($filter_tenant > 0) {
        $where_clauses[] = "c.tenant_id = " . $filter_tenant;
    }

    if ($q !== '') {
        $like = '%' . $conn->real_escape_string($q) . '%';
        $where_clauses[] = "(c.company_name LIKE '{$like}' OR t.company_name LIKE '{$like}' OR t.public_id LIKE '{$like}')";
    }

    $where_sql = !empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

    $disputes_data = sa_query(
        $conn,
        "SELECT r.id, r.rating, r.reported, r.is_verified, r.created_at,
                c.company_name AS profile_name,
                t.id AS tenant_id, t.company_name AS tenant_name, t.public_id AS tenant_public_id,
                (SELECT COUNT(*) FROM reported_reviews rep WHERE rep.rating_id = r.id) AS report_count,
                (SELECT GROUP_CONCAT(DISTINCT rep.reason SEPARATOR ' | ') FROM reported_reviews rep WHERE rep.rating_id = r.id) AS report_reasons
           FROM ratings r
           LEFT JOIN customers c ON r.company_id = c.id
           LEFT JOIN tenants t ON c.tenant_id = t.id
           {$where_sql}
          ORDER BY r.reported DESC, r.created_at DESC LIMIT 150",
        ['ratings', 'customers', 'tenants']
    );
}

/* ---------- Page Meta ---------- */
$robots = 'noindex, nofollow';
$pageTitle = 'Support & Disputes';
$pageHeading = 'Support & Disputes';
$pageSubtitle = 'Tenant platform requests, bug reports, and privacy-shielded dispute moderation.';
$activePage = 'reviews';
$BASE = '../';
$extraCss = ['assets/css/superadmin.css'];
$bodyClass = 'sa-body';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Support &amp; Disputes</span>
        </div>
        <h2>Support &amp; Dispute Center</h2>
        <p>Direct communication channel with tenants &middot; Privacy-shielded dispute compliance.</p>
    </div>
    <div class="sa-head-actions">
        <a class="sa-btn sa-btn-ghost" href="reviews.php?tab=<?php echo $activeTab; ?>&export=csv" title="Export current tab as CSV">
            <?php echo sa_icon('download'); ?> Export CSV
        </a>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<!-- ============ SUMMARY METRICS ============ -->
<div class="sa-grid sa-kpis sa-anim" style="grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Open Tickets</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('inbox'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo (int) $open_tickets; ?></div>
        <div class="sa-kpi-foot">
            <span class="sa-kpi-note">Awaiting Superadmin action</span>
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">In Progress</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('activity'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo (int) $in_prog_tickets; ?></div>
        <div class="sa-kpi-foot">
            <span class="sa-kpi-note">Actively being worked on</span>
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-danger);--kpi-soft:var(--sa-danger-soft);--kpi-line:var(--sa-danger-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Review Disputes</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('shield'); ?></span>
        </div>
        <div class="sa-kpi-value" style="color:<?php echo $flagged_disputes > 0 ? '#ef4444' : 'inherit'; ?>"><?php echo (int) $flagged_disputes; ?></div>
        <div class="sa-kpi-foot">
            <span class="sa-kpi-note"><?php echo $flagged_disputes > 0 ? 'Takedown requests pending' : 'Queue clear'; ?></span>
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-success);--kpi-soft:var(--sa-success-soft);--kpi-line:var(--sa-success-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Resolved Tickets</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('check'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo (int) $resolved_tickets; ?></div>
        <div class="sa-kpi-foot">
            <span class="sa-kpi-note"><?php echo (int)$total_tickets; ?> total platform requests</span>
        </div>
    </article>
</div>

<!-- ============ TAB NAVIGATION ============ -->
<div style="display:flex;gap:12px;border-bottom:2px solid var(--sa-line);margin-bottom:20px;padding-bottom:2px">
    <a href="reviews.php?tab=support" class="sa-btn <?php echo $activeTab === 'support' ? 'sa-btn-primary' : 'sa-btn-ghost'; ?>" style="border-radius:8px 8px 0 0;font-weight:700;display:inline-flex;align-items:center;gap:8px">
        <?php echo sa_icon('message'); ?> 
        <span>Tenant Support &amp; Feedback</span>
        <?php if ($open_tickets > 0): ?>
            <span class="sa-badge" style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:800;"><?php echo $open_tickets; ?></span>
        <?php endif; ?>
    </a>
    <a href="reviews.php?tab=disputes" class="sa-btn <?php echo $activeTab === 'disputes' ? 'sa-btn-primary' : 'sa-btn-ghost'; ?>" style="border-radius:8px 8px 0 0;font-weight:700;display:inline-flex;align-items:center;gap:8px">
        <?php echo sa_icon('shield'); ?> 
        <span>Privacy-Shielded Disputes</span>
        <?php if ($flagged_disputes > 0): ?>
            <span class="sa-badge" style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:800;"><?php echo $flagged_disputes; ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if ($activeTab === 'support'): ?>
<!-- ============================================================
     TAB 1: TENANT SUPPORT & PLATFORM FEEDBACK
     ============================================================ -->
<section class="sa-card sa-mb">
    <div class="sa-filters" style="flex-wrap:wrap;gap:12px">
        <div class="sa-chips" style="flex-wrap:wrap">
            <a class="sa-chip<?php echo $filter === 'all' ? ' active' : ''; ?>" href="reviews.php?tab=support&filter=all<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?>">
                All <span class="count"><?php echo $total_tickets; ?></span>
            </a>
            <a class="sa-chip<?php echo $filter === 'open' ? ' active' : ''; ?><?php echo $open_tickets > 0 ? ' is-alert' : ''; ?>" href="reviews.php?tab=support&filter=open<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?>">
                Open <span class="count"><?php echo $open_tickets; ?></span>
            </a>
            <a class="sa-chip<?php echo $filter === 'in_progress' ? ' active' : ''; ?>" href="reviews.php?tab=support&filter=in_progress<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?>">
                In Progress <span class="count"><?php echo $in_prog_tickets; ?></span>
            </a>
            <a class="sa-chip<?php echo $filter === 'resolved' ? ' active' : ''; ?>" href="reviews.php?tab=support&filter=resolved<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?>">
                Resolved <span class="count"><?php echo $resolved_tickets; ?></span>
            </a>
            <span style="border-right:1px solid var(--sa-line);height:22px;align-self:center;margin:0 4px"></span>
            <a class="sa-chip<?php echo $filter === 'bug_report' ? ' active' : ''; ?>" href="reviews.php?tab=support&filter=bug_report<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?>">
                🐞 Bugs
            </a>
            <a class="sa-chip<?php echo $filter === 'feature_request' ? ' active' : ''; ?>" href="reviews.php?tab=support&filter=feature_request<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?>">
                💡 Features
            </a>
        </div>

        <form method="GET" action="reviews.php" style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="tab" value="support">
            <input type="hidden" name="filter" value="<?php echo sa_e($filter); ?>">
            <select name="tenant_id" class="sa-inline-select" onchange="this.form.submit()" aria-label="Filter by workspace" style="max-width:190px">
                <option value="0">All workspaces</option>
                <?php foreach ($tenants_list as $titem): ?>
                    <option value="<?php echo (int) $titem['id']; ?>"<?php echo $filter_tenant === (int) $titem['id'] ? ' selected' : ''; ?>>
                        <?php echo sa_e($titem['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="sa-search" style="display:block;width:min(240px,45vw)">
                <?php echo sa_icon('search'); ?>
                <input type="search" name="q" value="<?php echo sa_e($q); ?>" placeholder="Search subject, workspace…" aria-label="Search tickets">
            </div>
            <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost">Search</button>
            <?php if ($q !== '' || $filter !== 'all' || $filter_tenant > 0): ?>
                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="reviews.php?tab=support" title="Clear filters"><?php echo sa_icon('x'); ?></a>
            <?php endif; ?>
        </form>
    </div>
</section>

<section class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3>Tenant Support Requests &amp; Feedback</h3>
            <p><?php echo count($tickets_data); ?> request<?php echo count($tickets_data) === 1 ? '' : 's'; ?> displayed<?php echo $q !== '' ? ' matching “' . sa_e($q) . '”' : ''; ?></p>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table" id="ticketsTable" data-sa-sortable-table>
            <thead>
                <tr>
                    <th data-sa-sort="0" data-type="num" scope="col" style="width:70px">ID</th>
                    <th data-sa-sort="1" scope="col" style="min-width:180px">Tenant Workspace</th>
                    <th data-sa-sort="2" scope="col" style="width:130px">Category</th>
                    <th data-sa-sort="3" scope="col" style="width:100px">Priority</th>
                    <th scope="col" style="min-width:260px">Subject &amp; Message</th>
                    <th data-sa-sort="5" scope="col" style="width:120px">Status</th>
                    <th data-sa-sort="6" data-type="date" scope="col" style="width:110px">Date</th>
                    <th data-no-export scope="col" style="width:90px;text-align:right"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (empty($tickets_data)): ?>
                <tr data-static>
                    <td colspan="8">
                        <div class="sa-empty">
                            <?php echo sa_icon('message'); ?>
                            <strong>No support tickets found</strong>
                            <p>When tenants submit platform feedback, bug reports, or questions from their portal, they will appear here.</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($tickets_data as $tk): ?>
<?php
    $priority_styles = [
        'urgent' => 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;font-weight:700',
        'high'   => 'background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-weight:700',
        'medium' => 'background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe',
        'low'    => 'background:#f8fafc;color:#475569;border:1px solid #e2e8f0',
    ];
    $status_badges = [
        'open'        => '<span class="sa-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-weight:700">Open</span>',
        'in_progress' => '<span class="sa-badge" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-weight:700">In Progress</span>',
        'resolved'    => '<span class="sa-badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-weight:700">✓ Resolved</span>',
        'closed'      => '<span class="sa-badge" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1">Closed</span>',
    ];
    $cat_labels = [
        'support'         => '🛠️ Support',
        'feature_request' => '💡 Feature',
        'bug_report'      => '🐞 Bug Report',
        'billing'         => '💳 Billing',
        'general'         => '💬 General',
    ];
?>
                <tr>
                    <td class="num sa-faint" data-sort-value="<?php echo (int) $tk['id']; ?>">#T-<?php echo (int) $tk['id']; ?></td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($tk['tenant_name'] ?: 'Workspace')); ?></span>
                            <div class="sa-cell-text">
                                <a href="tenant_details.php?id=<?php echo (int) $tk['tenant_id']; ?>" style="font-weight:700;color:inherit;text-decoration:none;">
                                    <?php echo sa_e($tk['tenant_name'] ?: 'Workspace #' . $tk['tenant_id']); ?>
                                </a>
                                <?php if (!empty($tk['tenant_public_id'])): ?>
                                    <span class="sa-badge" style="font-family:monospace;font-size:10px;padding:1px 5px;background:rgba(99,102,241,0.08);color:#4338ca;border:1px solid rgba(99,102,241,0.2);margin-left:4px;border-radius:4px;"><?php echo sa_e($tk['tenant_public_id']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="sa-badge" style="font-size:11px;font-weight:600;background:rgba(99,102,241,0.06);color:var(--sa-primary,#6366f1);border:1px solid rgba(99,102,241,0.18)">
                            <?php echo $cat_labels[$tk['ticket_type']] ?? 'Support'; ?>
                        </span>
                    </td>
                    <td>
                        <span class="sa-badge" style="font-size:10.5px;<?php echo $priority_styles[$tk['priority']] ?? $priority_styles['medium']; ?>">
                            <?php echo ucfirst($tk['priority']); ?>
                        </span>
                    </td>
                    <td>
                        <div style="max-width:340px">
                            <strong style="display:block;color:var(--sa-heading,#0f172a);margin-bottom:2px;"><?php echo sa_e($tk['subject']); ?></strong>
                            <span class="sa-muted" style="font-size:12px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?php echo sa_e($tk['message']); ?>
                            </span>
                            <?php if (!empty($tk['admin_reply'])): ?>
                                <span style="display:inline-block;font-size:10.5px;color:#16a34a;font-weight:600;margin-top:3px;">
                                    ✓ Replied (<?php echo sa_time_ago($tk['replied_at']); ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?php echo $status_badges[$tk['status']] ?? $status_badges['open']; ?></td>
                    <td data-sort-value="<?php echo sa_e($tk['created_at']); ?>">
                        <span style="font-size:11.5px;color:var(--sa-muted);white-space:nowrap;">
                            <?php echo sa_e(sa_date($tk['created_at'])); ?>
                        </span>
                    </td>
                    <td data-no-export style="text-align:right">
                        <div class="sa-row-actions" style="justify-content:flex-end">
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-primary" 
                                    onclick="openTicketModal(<?php echo htmlspecialchars(json_encode($tk), ENT_QUOTES, 'UTF-8'); ?>)"
                                    title="View ticket &amp; reply">
                                Reply
                            </button>
                            <form method="POST" action="reviews.php?tab=support" style="display:inline" onsubmit="return confirm('Delete ticket #T-<?php echo (int) $tk['id']; ?>?');">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_ticket">
                                <input type="hidden" name="ticket_id" value="<?php echo (int) $tk['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" style="color:var(--sa-danger)" title="Delete ticket">
                                    <?php echo sa_icon('trash'); ?>
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
</section>

<!-- ============ TICKET REPLY MODAL ============ -->
<div id="ticketModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:var(--sa-card-bg,#fff);border:1px solid var(--sa-line,#e2e8f0);border-radius:14px;max-width:680px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;border-bottom:1px solid var(--sa-line);padding-bottom:12px;">
            <div>
                <span id="modalTicketId" style="font-family:monospace;font-size:12px;font-weight:800;color:var(--sa-primary,#6366f1)">#T-0</span>
                <h3 id="modalSubject" style="font-size:18px;margin:2px 0 0;font-weight:800;">Ticket Subject</h3>
                <span id="modalTenant" style="font-size:12px;color:var(--sa-muted);">Workspace</span>
            </div>
            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" onclick="closeTicketModal()"><?php echo sa_icon('x'); ?></button>
        </div>

        <div style="background:var(--sa-surface-2,#f8fafc);border:1px solid var(--sa-line);border-radius:8px;padding:14px;margin-bottom:18px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--sa-muted);margin-bottom:6px;">Tenant Message:</div>
            <div id="modalMessage" style="font-size:13.5px;color:var(--sa-body,#334155);white-space:pre-wrap;line-height:1.5;"></div>
        </div>

        <form method="POST" action="reviews.php?tab=support">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="reply_ticket">
            <input type="hidden" id="modalInputId" name="ticket_id" value="0">

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Update Status:</label>
                <select id="modalStatus" name="status" class="sa-inline-select" style="width:100%;padding:8px 10px;border-radius:6px;">
                    <option value="in_progress">⏳ In Progress (Investigating)</option>
                    <option value="resolved">✓ Resolved (Completed)</option>
                    <option value="open">Awaiting Action (Open)</option>
                    <option value="closed">Closed</option>
                </select>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Superadmin Reply (Visible to Tenant):</label>
                <textarea id="modalReply" name="admin_reply" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid var(--sa-line);font-size:13.5px;font-family:inherit;" placeholder="Type your response to the tenant here..."></textarea>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--sa-muted);">Internal Staff Notes (Private to Superadmins):</label>
                <textarea id="modalNotes" name="admin_notes" rows="2" style="width:100%;padding:8px 10px;border-radius:6px;border:1px solid var(--sa-line);font-size:12.5px;font-family:inherit;" placeholder="Internal engineering notes or tracking references..."></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="sa-btn sa-btn-ghost" onclick="closeTicketModal()">Cancel</button>
                <button type="submit" class="sa-btn sa-btn-primary" style="font-weight:700;">Save &amp; Send Reply</button>
            </div>
        </form>
    </div>
</div>

<script>
function openTicketModal(ticket) {
    document.getElementById('modalTicketId').textContent = '#T-' + ticket.id;
    document.getElementById('modalInputId').value = ticket.id;
    document.getElementById('modalSubject').textContent = ticket.subject;
    document.getElementById('modalTenant').textContent = (ticket.tenant_name || 'Workspace #' + ticket.tenant_id) + (ticket.tenant_public_id ? ' (' + ticket.tenant_public_id + ')' : '');
    document.getElementById('modalMessage').textContent = ticket.message;
    document.getElementById('modalReply').value = ticket.admin_reply || '';
    document.getElementById('modalNotes').value = ticket.admin_notes || '';
    document.getElementById('modalStatus').value = ticket.status || 'in_progress';
    document.getElementById('ticketModal').style.display = 'flex';
}
function closeTicketModal() {
    document.getElementById('ticketModal').style.display = 'none';
}
</script>

<?php else: ?>
<!-- ============================================================
     TAB 2: PRIVACY-SHIELDED REVIEW DISPUTES
     (ZERO Customer Names, Emails, or Feedback Text Exposed)
     ============================================================ -->
<div class="sa-card sa-mb" style="background:#f8fafc;border-left:4px solid var(--sa-primary,#6366f1);padding:14px 18px;">
    <div style="display:flex;align-items:flex-start;gap:12px;">
        <span style="font-size:20px;">🛡️</span>
        <div>
            <strong style="font-size:13.5px;color:var(--sa-heading,#0f172a);display:block;margin-bottom:2px;">Privacy Shield Active — Tenant Feedback Confidentiality Protected</strong>
            <p style="font-size:12.5px;color:var(--sa-muted,#64748b);margin:0;line-height:1.45;">
                In compliance with platform privacy policies, Superadmins do not have access to read end-customers' private review comments or personal contact details.
                Only compliance takedown requests and dispute metadata are managed below.
            </p>
        </div>
    </div>
</div>

<section class="sa-card sa-mb">
    <div class="sa-filters" style="flex-wrap:wrap;gap:12px">
        <div class="sa-chips">
            <a class="sa-chip<?php echo $filter === 'reported' || $filter === 'all' ? ' active' : ''; ?><?php echo $flagged_disputes > 0 ? ' is-alert' : ''; ?>" href="reviews.php?tab=disputes&filter=reported<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?>">
                <?php echo sa_icon('shield', 'style="width:13px;height:13px;vertical-align:-1px"'); ?> Flagged Disputes <span class="count"><?php echo $flagged_disputes; ?></span>
            </a>
            <a class="sa-chip<?php echo $filter === 'dismissed' ? ' active' : ''; ?>" href="reviews.php?tab=disputes&filter=dismissed<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?>">
                Dismissed / Published
            </a>
        </div>

        <form method="GET" action="reviews.php" style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="tab" value="disputes">
            <input type="hidden" name="filter" value="<?php echo sa_e($filter); ?>">
            <select name="tenant_id" class="sa-inline-select" onchange="this.form.submit()" aria-label="Filter by workspace" style="max-width:190px">
                <option value="0">All workspaces</option>
                <?php foreach ($tenants_list as $titem): ?>
                    <option value="<?php echo (int) $titem['id']; ?>"<?php echo $filter_tenant === (int) $titem['id'] ? ' selected' : ''; ?>>
                        <?php echo sa_e($titem['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="sa-search" style="display:block;width:min(240px,45vw)">
                <?php echo sa_icon('search'); ?>
                <input type="search" name="q" value="<?php echo sa_e($q); ?>" placeholder="Search company, workspace…" aria-label="Search disputes">
            </div>
            <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost">Search</button>
            <?php if ($q !== '' || $filter !== 'reported' || $filter_tenant > 0): ?>
                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="reviews.php?tab=disputes" title="Clear filters"><?php echo sa_icon('x'); ?></a>
            <?php endif; ?>
        </form>
    </div>
</section>

<section class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3>Review Dispute &amp; Takedown Queue</h3>
            <p><?php echo count($disputes_data); ?> dispute item<?php echo count($disputes_data) === 1 ? '' : 's'; ?> loaded</p>
        </div>
        <div class="sa-card-head-actions">
            <?php if ($flagged_disputes > 0): ?>
                <span class="sa-badge" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 8px;font-weight:700">
                    <?php echo sa_icon('alert', 'style="width:13px;height:13px;vertical-align:-2px"'); ?> <?php echo $flagged_disputes; ?> require moderation
                </span>
            <?php else: ?>
                <span class="sa-pill" style="color:var(--sa-success)"><?php echo sa_icon('check'); ?> Dispute queue clear</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table" id="disputesTable" data-sa-sortable-table>
            <thead>
                <tr>
                    <th data-sa-sort="0" data-type="num" scope="col" style="width:75px">Ref #</th>
                    <th data-sa-sort="1" scope="col" style="min-width:180px">Tenant Workspace</th>
                    <th data-sa-sort="2" scope="col" style="width:160px">Target Profile</th>
                    <th data-sa-sort="3" scope="col" style="width:140px">Customer Identity</th>
                    <th data-sa-sort="4" data-type="num" scope="col" style="width:100px">Rating</th>
                    <th scope="col" style="min-width:240px">Customer Feedback</th>
                    <th scope="col" style="min-width:180px">Reported Reason</th>
                    <th data-sa-sort="7" data-type="date" scope="col" style="width:105px">Date</th>
                    <th data-no-export scope="col" style="width:140px;text-align:right"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (empty($disputes_data)): ?>
                <tr data-static>
                    <td colspan="9">
                        <div class="sa-empty">
                            <?php echo sa_icon('shield'); ?>
                            <strong>No dispute records found</strong>
                            <p>All reviews are currently compliant with platform guidelines.</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($disputes_data as $disp): ?>
<?php
    $is_rep = !empty($disp['reported']);
    $stars_display = str_repeat('★', (int) $disp['rating']) . str_repeat('☆', 5 - (int) $disp['rating']);
    $score_color = (int) $disp['rating'] >= 4 ? '#16a34a' : ((int) $disp['rating'] === 3 ? '#d97706' : '#dc2626');
?>
                <tr style="<?php echo $is_rep ? 'background:rgba(239, 68, 68, 0.03);' : ''; ?>">
                    <td class="num sa-faint" data-sort-value="<?php echo (int) $disp['id']; ?>">#<?php echo (int) $disp['id']; ?></td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($disp['tenant_name'] ?: 'Tenant')); ?></span>
                            <div class="sa-cell-text">
                                <a href="tenant_details.php?id=<?php echo (int) $disp['tenant_id']; ?>" style="font-weight:700;color:inherit;text-decoration:none;">
                                    <?php echo sa_e($disp['tenant_name'] ?: 'Workspace #' . $disp['tenant_id']); ?>
                                </a>
                                <?php if (!empty($disp['tenant_public_id'])): ?>
                                    <span class="sa-badge" style="font-family:monospace;font-size:10px;padding:1px 5px;background:rgba(99,102,241,0.08);color:#4338ca;border:1px solid rgba(99,102,241,0.2);margin-left:4px;border-radius:4px;"><?php echo sa_e($disp['tenant_public_id']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span style="font-weight:600;font-size:12.5px;color:var(--sa-heading);">
                            <?php echo sa_e($disp['profile_name'] ?: 'Direct Profile'); ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <span style="font-size:12px;font-weight:600;color:var(--sa-heading);">Customer #<?php echo (int)$disp['id']; ?></span>
                            <span class="sa-badge" style="background:#f1f5f9;color:#64748b;font-size:9.5px;padding:1px 5px;align-self:flex-start;">
                                🔒 Redacted
                            </span>
                        </div>
                    </td>
                    <td data-sort-value="<?php echo (int) $disp['rating']; ?>">
                        <div style="color:<?php echo $score_color; ?>;font-weight:700;font-size:13px;letter-spacing:1px;white-space:nowrap">
                            <?php echo $stars_display; ?>
                        </div>
                    </td>
                    <td>
                        <div style="background:#f8fafc;border:1px dashed var(--sa-line,#cbd5e1);border-radius:6px;padding:6px 10px;font-size:11.5px;color:var(--sa-muted);display:inline-flex;align-items:center;gap:6px;">
                            <span>🔒</span>
                            <em>Confidential — Hidden by privacy policy</em>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:3px;">
                            <span style="font-size:12px;color:#991b1b;font-weight:600;">
                                <?php echo sa_e($disp['report_reasons'] ?: 'Flagged for moderation'); ?>
                            </span>
                            <?php if ($disp['report_count'] > 1): ?>
                                <span class="sa-badge" style="background:#fee2e2;color:#991b1b;font-size:10px;padding:1px 5px;align-self:flex-start;">
                                    <?php echo (int) $disp['report_count']; ?> reports
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-sort-value="<?php echo sa_e($disp['created_at']); ?>">
                        <span style="font-size:11.5px;color:var(--sa-muted);white-space:nowrap;">
                            <?php echo sa_e(sa_date($disp['created_at'])); ?>
                        </span>
                    </td>
                    <td data-no-export style="text-align:right">
                        <div class="sa-row-actions" style="justify-content:flex-end">
                            <?php if ($is_rep): ?>
                                <form method="POST" action="reviews.php?tab=disputes" style="display:inline" title="Dismiss dispute &amp; restore to public directory">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="dismiss_dispute">
                                    <input type="hidden" name="rating_id" value="<?php echo (int) $disp['id']; ?>">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" style="color:var(--sa-success);border:1px solid currentColor" title="Dismiss dispute &amp; keep published">
                                        <?php echo sa_icon('check'); ?> Dismiss
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="reviews.php?tab=disputes" style="display:inline" title="Uphold takedown &amp; unpublish from directory">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="uphold_takedown">
                                    <input type="hidden" name="rating_id" value="<?php echo (int) $disp['id']; ?>">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" style="color:#ea580c" title="Uphold takedown">
                                        <?php echo sa_icon('shield'); ?> Unpublish
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" action="reviews.php?tab=disputes" style="display:inline" onsubmit="return confirm('Permanently purge review #<?php echo (int) $disp['id']; ?> for compliance?');">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="purge_dispute">
                                <input type="hidden" name="rating_id" value="<?php echo (int) $disp['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-danger" title="Purge review">
                                    <?php echo sa_icon('trash'); ?>
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
</section>

<?php endif; ?>

<?php include __DIR__ . '/_shell_footer.php'; ?>
