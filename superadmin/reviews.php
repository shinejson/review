<?php
/**
 * ============================================================
 *  Super Admin — Reviews Moderation & Dispute Center
 * ============================================================
 *  Platform-wide review management, reported review resolution,
 *  verification badge controls, and dispute handling.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();
require_sa_permission('reviews');

/* ---------- POST Handlers ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('reviews.php');
    }

    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['rating_id'] ?? 0);

    if ($action === 'dismiss_report' && $id) {
        // Clear reported status on review
        $conn->query("UPDATE ratings SET reported = 0 WHERE id = " . $id);
        // Clear recorded report logs
        if (sa_table_exists($conn, 'reported_reviews')) {
            $conn->query("DELETE FROM reported_reviews WHERE rating_id = " . $id);
        }
        sa_flash('success', "Report dismissed. Review #{$id} is now restored to public visibility.");
        redirect('reviews.php');
    }

    if ($action === 'flag_review' && $id) {
        $conn->query("UPDATE ratings SET reported = 1 WHERE id = " . $id);
        sa_flash('warning', "Review #{$id} has been flagged and unpublished from public directories.");
        redirect('reviews.php');
    }

    if ($action === 'toggle_verify' && $id) {
        $curr = (int) sa_scalar($conn, "SELECT is_verified FROM ratings WHERE id = " . $id, 0, 'ratings');
        $new_val = $curr ? 0 : 1;
        $conn->query("UPDATE ratings SET is_verified = " . $new_val . " WHERE id = " . $id);
        sa_flash('success', "Review #{$id} marked as " . ($new_val ? 'Verified Customer' : 'Unverified') . ".");
        redirect('reviews.php');
    }

    if ($action === 'delete_review' && $id) {
        $conn->query("DELETE FROM ratings WHERE id = " . $id);
        sa_flash('success', "Review #{$id} was permanently removed.");
        redirect('reviews.php');
    }
}

/* ---------- 1-Click Server CSV Export ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reviews-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Rating', 'Customer Name', 'Customer Email', 'Comment', 'Company Profile', 'Tenant Workspace', 'Verified', 'Reported', 'Admin Reply', 'Created At']);

    $export_reviews = sa_query(
        $conn,
        "SELECT r.*, c.company_name AS profile_name, t.company_name AS tenant_name
           FROM ratings r
           LEFT JOIN customers c ON r.company_id = c.id
           LEFT JOIN tenants t ON c.tenant_id = t.id
          ORDER BY r.created_at DESC",
        ['ratings', 'customers']
    );
    foreach ($export_reviews as $er) {
        fputcsv($out, [
            $er['id'],
            $er['rating'],
            $er['customer_name'],
            $er['customer_email'],
            $er['comment'],
            $er['profile_name'] ?: 'N/A',
            $er['tenant_name'] ?: 'Independent',
            !empty($er['is_verified']) ? 'Yes' : 'No',
            !empty($er['reported']) ? 'Yes' : 'No',
            $er['admin_reply'] ?: '',
            $er['created_at'],
        ]);
    }
    fclose($out);
    exit();
}

/* ---------- Filters & Search ---------- */
$filter = isset($_GET['filter']) ? preg_replace('/[^a-z_0-9]/', '', strtolower($_GET['filter'])) : 'all';
$allowed = ['all', 'reported', 'verified', 'unverified', 'critical', 'neutral', 'positive'];
if (!in_array($filter, $allowed, true)) {
    $filter = 'all';
}
$filter_tenant = (int) ($_GET['tenant_id'] ?? 0);
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$where_clauses = [];
if ($filter === 'reported') {
    $where_clauses[] = "r.reported = 1";
} elseif ($filter === 'verified') {
    $where_clauses[] = "r.is_verified = 1";
} elseif ($filter === 'unverified') {
    $where_clauses[] = "(r.is_verified = 0 OR r.is_verified IS NULL)";
} elseif ($filter === 'critical') {
    $where_clauses[] = "r.rating <= 2";
} elseif ($filter === 'neutral') {
    $where_clauses[] = "r.rating = 3";
} elseif ($filter === 'positive') {
    $where_clauses[] = "r.rating >= 4";
}

if ($filter_tenant > 0) {
    $where_clauses[] = "c.tenant_id = " . $filter_tenant;
}

if ($q !== '') {
    $like = '%' . $conn->real_escape_string($q) . '%';
    $where_clauses[] = "(r.customer_name LIKE '{$like}' OR r.customer_email LIKE '{$like}' OR r.comment LIKE '{$like}' OR c.company_name LIKE '{$like}')";
}

$where_sql = !empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

$reviews = sa_query(
    $conn,
    "SELECT r.*, c.company_name AS profile_name, t.id AS tenant_id, t.company_name AS tenant_name,
            (SELECT COUNT(*) FROM reported_reviews rep WHERE rep.rating_id = r.id) AS report_count,
            (SELECT GROUP_CONCAT(DISTINCT rep.reason SEPARATOR ' | ') FROM reported_reviews rep WHERE rep.rating_id = r.id) AS report_reasons
       FROM ratings r
       LEFT JOIN customers c ON r.company_id = c.id
       LEFT JOIN tenants t ON c.tenant_id = t.id"
    . $where_sql . " ORDER BY r.reported DESC, r.created_at DESC LIMIT 250",
    ['ratings', 'customers']
);

/* Overall counts for metric badges & chips */
$all_count = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings", 0, 'ratings');
$reported_count = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings WHERE reported = 1", 0, 'ratings');
$verified_count = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings WHERE is_verified = 1", 0, 'ratings');
$unverified_count = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings WHERE is_verified = 0 OR is_verified IS NULL", 0, 'ratings');
$critical_count = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings WHERE rating <= 2", 0, 'ratings');
$neutral_count = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings WHERE rating = 3", 0, 'ratings');
$positive_count = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings WHERE rating >= 4", 0, 'ratings');
$avg_rating = (float) sa_scalar($conn, "SELECT AVG(rating) FROM ratings WHERE reported = 0", 0.0, 'ratings');

$tenants_list = sa_query($conn, "SELECT id, company_name FROM tenants ORDER BY company_name ASC", 'tenants');

/* ---------- Page Meta ---------- */
$robots = 'noindex, nofollow';
$pageTitle = 'Review Moderation';
$pageHeading = 'Review Moderation';
$pageSubtitle = 'Platform dispute queue and reputation governance.';
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
            <span>Reviews</span>
        </div>
        <h2>Review moderation &amp; dispute center</h2>
        <p>
            <?php echo sa_e(sa_num($all_count)); ?> platform reviews &middot;
            <strong style="color:<?php echo $reported_count > 0 ? '#ef4444' : 'inherit'; ?>"><?php echo (int)$reported_count; ?> flagged/reported</strong> &middot;
            <?php echo sa_e(sa_num($verified_count)); ?> verified &middot;
            <?php echo number_format($avg_rating, 1); ?>★ platform avg
        </p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-ghost" data-sa-export="#reviewsTable" data-sa-export-name="optibiz-reviews" title="Export visible table">
            <?php echo sa_icon('download'); ?> View CSV
        </button>
        <a class="sa-btn sa-btn-ghost" href="reviews.php?export=csv" title="Export all reviews in database">
            <?php echo sa_icon('file-text'); ?> Full DB CSV
        </a>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<!-- ============ FILTERS ============ -->
<section class="sa-card sa-mb">
    <div class="sa-filters" style="flex-wrap:wrap;gap:12px">
        <div class="sa-chips" style="flex-wrap:wrap">
            <a class="sa-chip<?php echo $filter === 'all' ? ' active' : ''; ?>" href="reviews.php?filter=all<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>">
                All reviews <span class="count"><?php echo $all_count; ?></span>
            </a>
            <a class="sa-chip<?php echo $filter === 'reported' ? ' active' : ''; ?><?php echo $reported_count > 0 ? ' is-alert' : ''; ?>" href="reviews.php?filter=reported<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>">
                <?php echo sa_icon('shield', 'style="width:13px;height:13px;vertical-align:-1px"'); ?> Flagged / Reported <span class="count"><?php echo $reported_count; ?></span>
            </a>
            <a class="sa-chip<?php echo $filter === 'verified' ? ' active' : ''; ?>" href="reviews.php?filter=verified<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>">
                Verified customers <span class="count"><?php echo $verified_count; ?></span>
            </a>
            <a class="sa-chip<?php echo $filter === 'unverified' ? ' active' : ''; ?>" href="reviews.php?filter=unverified<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>">
                Unverified <span class="count"><?php echo $unverified_count; ?></span>
            </a>
            <a class="sa-chip<?php echo $filter === 'critical' ? ' active' : ''; ?>" href="reviews.php?filter=critical<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>" style="color:#b91c1c">
                Critical (1-2★) <span class="count"><?php echo $critical_count; ?></span>
            </a>
            <a class="sa-chip<?php echo $filter === 'positive' ? ' active' : ''; ?>" href="reviews.php?filter=positive<?php echo $filter_tenant ? '&tenant_id=' . $filter_tenant : ''; ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>" style="color:#15803d">
                Positive (4-5★) <span class="count"><?php echo $positive_count; ?></span>
            </a>
        </div>

        <form method="GET" action="reviews.php" style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="filter" value="<?php echo sa_e($filter); ?>">
            <select name="tenant_id" class="sa-inline-select" onchange="this.form.submit()" aria-label="Filter by workspace" style="max-width:180px">
                <option value="0">All workspaces</option>
                <?php foreach ($tenants_list as $titem): ?>
                    <option value="<?php echo (int) $titem['id']; ?>"<?php echo $filter_tenant === (int) $titem['id'] ? ' selected' : ''; ?>>
                        <?php echo sa_e($titem['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="sa-search" style="display:block;width:min(240px,45vw)">
                <?php echo sa_icon('search'); ?>
                <input type="search" name="q" value="<?php echo sa_e($q); ?>" placeholder="Search reviewer, comment…" aria-label="Search reviews">
            </div>
            <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost">Search</button>
            <?php if ($q !== '' || $filter !== 'all' || $filter_tenant > 0): ?>
                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="reviews.php" title="Clear filters"><?php echo sa_icon('x'); ?></a>
            <?php endif; ?>
        </form>
    </div>
</section>

<!-- ============ REVIEWS TABLE ============ -->
<section class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3>
                <?php
                $lbls = [
                    'all' => 'All platform reviews',
                    'reported' => 'Flagged &amp; reported reviews requiring moderation',
                    'verified' => 'Verified customer reviews',
                    'unverified' => 'Unverified reviews',
                    'critical' => 'Critical low-rating reviews (1-2★)',
                    'neutral' => 'Neutral reviews (3★)',
                    'positive' => 'Positive high-rating reviews (4-5★)',
                ];
                echo $lbls[$filter] ?? 'Reviews';
                ?>
            </h3>
            <p><?php echo sa_e(sa_num(count($reviews))); ?> review<?php echo count($reviews) === 1 ? '' : 's'; ?> loaded<?php echo $q !== '' ? ' matching “' . sa_e($q) . '”' : ''; ?></p>
        </div>
        <div class="sa-card-head-actions">
            <?php if ($reported_count > 0): ?>
                <span class="sa-badge" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:4px 8px;font-weight:600">
                    <?php echo sa_icon('alert', 'style="width:13px;height:13px;vertical-align:-2px"'); ?> <?php echo $reported_count; ?> require moderation
                </span>
            <?php else: ?>
                <span class="sa-pill" style="color:var(--sa-success)"><?php echo sa_icon('check'); ?> Dispute queue clear</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="sa-table-wrap">
        <table class="sa-table" id="reviewsTable" data-sa-sortable-table>
            <thead scope="col">
                <tr>
                    <th data-sa-sort="0" data-type="num" scope="col" aria-sort="none">ID</th>
                    <th data-sa-sort="1" data-type="num" scope="col" aria-sort="none">Score</th>
                    <th data-sa-sort="2" scope="col" aria-sort="none">Reviewer</th>
                    <th data-sa-sort="3" scope="col" aria-sort="none">Target profile &amp; tenant</th>
                    <th scope="col">Review feedback</th>
                    <th data-sa-sort="5" scope="col" aria-sort="none">Status</th>
                    <th data-sa-sort="6" data-type="date" scope="col" aria-sort="none">Date</th>
                    <th data-no-export scope="col"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$reviews): ?>
                <tr data-static>
                    <td colspan="8">
                        <div class="sa-empty">
                            <?php echo sa_icon('message'); ?>
                            <strong>No reviews found</strong>
                            <p><?php echo $filter === 'reported' ? 'There are no active review disputes or reported feedback at this time.' : 'Try changing your search query or filter chip.'; ?></p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($reviews as $rev): ?>
<?php
    $is_reported = !empty($rev['reported']);
    $is_ver = !empty($rev['is_verified']);
    $stars_display = str_repeat('★', (int) $rev['rating']) . str_repeat('☆', 5 - (int) $rev['rating']);
    $score_color = (int) $rev['rating'] >= 4 ? '#16a34a' : ((int) $rev['rating'] === 3 ? '#d97706' : '#dc2626');
?>
                <tr style="<?php echo $is_reported ? 'background:rgba(239, 68, 68, 0.03);' : ''; ?>">
                    <td class="num sa-faint" data-sort-value="<?php echo (int) $rev['id']; ?>">#<?php echo (int) $rev['id']; ?></td>
                    <td data-sort-value="<?php echo (int) $rev['rating']; ?>">
                        <div style="color:<?php echo $score_color; ?>;font-weight:700;font-size:14px;letter-spacing:1px;white-space:nowrap">
                            <?php echo $stars_display; ?>
                            <span style="font-size:11px;color:var(--sa-muted);margin-left:4px">(<?php echo (int) $rev['rating']; ?>.0)</span>
                        </div>
                    </td>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar" style="font-size:11px"><?php echo sa_e(sa_initials($rev['customer_name'] ?: 'Anonymous')); ?></span>
                            <span class="sa-cell-text">
                                <strong><?php echo sa_e($rev['customer_name'] ?: 'Anonymous'); ?></strong>
                                <span><?php echo sa_e($rev['customer_email'] ?: 'No email given'); ?></span>
                            </span>
                        </div>
                    </td>
                    <td>
                        <span class="sa-cell-text">
                            <strong><?php echo sa_e($rev['profile_name'] ?: 'Unknown Profile'); ?></strong>
                            <span style="color:var(--sa-primary,#6366f1);font-size:11px">
                                <?php if ($rev['tenant_id']): ?>
                                    <a href="tenant_details.php?id=<?php echo (int) $rev['tenant_id']; ?>" style="color:inherit;text-decoration:none">
                                        <?php echo sa_e($rev['tenant_name']); ?>
                                    </a>
                                <?php else: ?>
                                    Platform Direct
                                <?php endif; ?>
                            </span>
                        </span>
                    </td>
                    <td style="max-width:320px">
                        <div style="font-size:12px;line-height:1.45;color:var(--sa-text);word-break:break-word">
                            <?php echo sa_e($rev['comment'] ?: '— No written comment —'); ?>
                        </div>
                        <?php if (!empty($rev['admin_reply'])): ?>
                            <div style="font-size:11px;margin-top:4px;padding:3px 6px;background:rgba(0,0,0,0.03);border-left:2px solid var(--sa-primary,#6366f1);border-radius:2px;color:var(--sa-muted)">
                                <strong>Reply:</strong> <?php echo sa_e(mb_substr($rev['admin_reply'], 0, 80)); ?><?php echo mb_strlen($rev['admin_reply']) > 80 ? '…' : ''; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">
                            <?php if ($is_reported): ?>
                                <span class="sa-badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;font-weight:600;font-size:10.5px">
                                    <?php echo sa_icon('alert', 'style="width:11px;height:11px;vertical-align:-1px"'); ?> Flagged (<?php echo (int) $rev['report_count']; ?>)
                                </span>
                                <?php if (!empty($rev['report_reasons'])): ?>
                                    <span style="font-size:10px;color:#b91c1c;max-width:140px;line-height:1.2" title="<?php echo sa_e($rev['report_reasons']); ?>">
                                        Reason: <?php echo sa_e(mb_substr($rev['report_reasons'], 0, 30)); ?><?php echo mb_strlen($rev['report_reasons']) > 30 ? '…' : ''; ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="sa-badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-size:10.5px">
                                    Published
                                </span>
                            <?php endif; ?>

                            <?php if ($is_ver): ?>
                                <span class="sa-badge" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-size:10px">
                                    <?php echo sa_icon('check', 'style="width:10px;height:10px;vertical-align:-1px"'); ?> Verified Customer
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-sort-value="<?php echo sa_e($rev['created_at']); ?>">
                        <span style="font-size:11.5px;color:var(--sa-muted);white-space:nowrap">
                            <?php echo sa_e(sa_date($rev['created_at'])); ?>
                        </span>
                    </td>
                    <td data-no-export>
                        <div class="sa-row-actions">
                            <?php if ($is_reported): ?>
                                <form method="POST" action="reviews.php" style="display:inline" title="Dismiss reports and restore to public visibility">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="dismiss_report">
                                    <input type="hidden" name="rating_id" value="<?php echo (int) $rev['id']; ?>">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" style="color:var(--sa-success);border:1px solid currentColor" title="Dismiss report &amp; unhide">
                                        <?php echo sa_icon('check'); ?> Restore
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="reviews.php" style="display:inline" title="Flag and unpublish review from public directories">
                                    <?php echo sa_csrf_field(); ?>
                                    <input type="hidden" name="action" value="flag_review">
                                    <input type="hidden" name="rating_id" value="<?php echo (int) $rev['id']; ?>">
                                    <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" style="color:#ea580c" title="Flag &amp; unpublish review">
                                        <?php echo sa_icon('shield'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" action="reviews.php" style="display:inline" title="Toggle verified customer badge">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_verify">
                                <input type="hidden" name="rating_id" value="<?php echo (int) $rev['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="<?php echo $is_ver ? 'Remove verified badge' : 'Mark as verified customer'; ?>">
                                    <?php echo sa_icon('star'); ?>
                                </button>
                            </form>

                            <form method="POST" action="reviews.php" style="display:inline" onsubmit="return confirm('Permanently delete review #<?php echo (int) $rev['id']; ?>?');">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_review">
                                <input type="hidden" name="rating_id" value="<?php echo (int) $rev['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-danger" title="Delete review">
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

    <div class="sa-card-foot">
        <span>Showing <?php echo sa_e(sa_num(count($reviews))); ?> of <?php echo sa_e(sa_num($all_count)); ?> total platform reviews</span>
        <span>Click "Restore" on flagged reviews to dismiss false disputes and re-publish them</span>
    </div>
</section>

<?php include __DIR__ . '/_shell_footer.php'; ?>
