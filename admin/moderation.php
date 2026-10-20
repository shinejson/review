<?php
/**
 * Workspace review moderation.
 *
 * Customers report reviews; the trust layer also queues reviews on its own
 * (bursts, duplicate text, device clustering) and businesses can dispute
 * them. Everything lands in review_moderation_queue and is decided here by
 * the workspace team. Decisions never delete the row: "remove" hides it
 * from public feeds and keeps it for audit, "approve" publishes it and
 * approves its photos, "dismiss" closes the complaint without touching it.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';
require_once dirname(__DIR__) . '/includes/review_trust.php';

requireLogin();
requireTeamAccess('ratings');

$tenant_id = (int) getTenantId();
$is_tenant = isTenant();
$success = '';
$error = '';

review_trust_ensure_schema($conn);

/* ============================================================
   Actions
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!function_exists('sa_csrf_ok') || !sa_csrf_ok()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($_POST['action'] === 'decide') {
        $rating_id = (int) ($_POST['rating_id'] ?? 0);
        $decision = strtolower((string) ($_POST['decision'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($rating_id <= 0 || !in_array($decision, ['approve', 'remove', 'dismiss'], true)) {
            $error = 'Choose a valid decision.';
        } elseif (!moderation_can_touch($conn, $rating_id, $tenant_id, $is_tenant)) {
            $error = 'That review is not in your workspace.';
        } else {
            if (review_moderation_decide($conn, $rating_id, $decision, $note, 'admin', (int) ($_SESSION['admin_id'] ?? 0))) {
                $success = $decision === 'approve'
                    ? 'Review published.'
                    : ($decision === 'remove' ? 'Review hidden from the public feed.' : 'Report dismissed; the review stays as it is.');
                if (function_exists('admin_log_activity')) {
                    @admin_log_activity($conn, 'moderate_review', 'Moderation decision: ' . $decision . ' on review #' . $rating_id, 'rating', $rating_id, $tenant_id ?: null);
                }
            } else {
                $error = 'Could not save that decision.';
            }
        }
    } elseif ($_POST['action'] === 'resolve_report') {
        $report_id = (int) ($_POST['report_id'] ?? 0);
        $status = strtolower((string) ($_POST['status'] ?? ''));
        if ($report_id <= 0 || !in_array($status, ['actioned', 'dismissed'], true)) {
            $error = 'Choose a valid report outcome.';
        } else {
            $stmt = $conn->prepare("SELECT rr.id, rr.rating_id, r.company_id FROM reported_reviews rr
                JOIN ratings r ON r.id = rr.rating_id WHERE rr.id = ? LIMIT 1");
            $stmt->bind_param('i', $report_id);
            $stmt->execute();
            $report = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$report || !moderation_can_touch($conn, (int) $report['rating_id'], $tenant_id, $is_tenant)) {
                $error = 'That report is not in your workspace.';
            } else {
                $stmt = $conn->prepare("UPDATE reported_reviews SET status = ? WHERE id = ? LIMIT 1");
                $stmt->bind_param('si', $status, $report_id);
                $stmt->execute();
                $stmt->close();
                if ($status === 'actioned') {
                    review_moderation_decide($conn, (int) $report['rating_id'], 'remove', 'Report upheld', 'admin', (int) ($_SESSION['admin_id'] ?? 0));
                }
                $success = $status === 'actioned' ? 'Report upheld and review hidden.' : 'Report dismissed.';
            }
        }
    }
}

function moderation_can_touch($conn, $rating_id, $tenant_id, $is_tenant)
{
    if (!$is_tenant) {
        return true;
    }
    $stmt = $conn->prepare("SELECT r.id FROM ratings r JOIN customers c ON c.id = r.company_id
        WHERE r.id = ? AND c.tenant_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $rid = (int) $rating_id;
    $tid = (int) $tenant_id;
    $stmt->bind_param('ii', $rid, $tid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool) $row;
}

/* ============================================================
   Read
   ============================================================ */
$queue = [];
$sql = "SELECT q.id AS queue_id, q.source, q.reason, q.details, q.created_at,
               r.id AS rating_id, r.rating, r.customer_name, r.customer_email, r.comment,
               r.moderation_status, r.abuse_score, r.abuse_flags, r.is_verified, r.verification_type,
               c.company_name,
               (SELECT COUNT(*) FROM reported_reviews rr WHERE rr.rating_id = r.id AND rr.status = 'open') AS open_reports
        FROM review_moderation_queue q
        JOIN ratings r ON r.id = q.rating_id
        JOIN customers c ON c.id = r.company_id
        WHERE q.status = 'open'";
$params = [];
$types = '';
if ($is_tenant) {
    $sql .= " AND c.tenant_id = ?";
    $types .= 'i';
    $params[] = $tenant_id;
}
$sql .= " ORDER BY q.created_at ASC LIMIT 100";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $queue[] = $row;
    }
    $stmt->close();
}

$reports = [];
$sql = "SELECT rr.id, rr.reason, rr.details, rr.created_at, rr.reporter_ip,
               r.id AS rating_id, r.rating, r.customer_name, c.company_name
        FROM reported_reviews rr
        JOIN ratings r ON r.id = rr.rating_id
        JOIN customers c ON c.id = r.company_id
        WHERE rr.status = 'open'";
$params = [];
$types = '';
if ($is_tenant) {
    $sql .= " AND c.tenant_id = ?";
    $types .= 'i';
    $params[] = $tenant_id;
}
$sql .= " ORDER BY rr.created_at ASC LIMIT 100";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $reports[] = $row;
    }
    $stmt->close();
}

$recent = [];
$sql = "SELECT q.status, q.resolved_at, q.reason, r.id AS rating_id, r.rating, r.customer_name, c.company_name
        FROM review_moderation_queue q
        JOIN ratings r ON r.id = q.rating_id
        JOIN customers c ON c.id = r.company_id
        WHERE q.status <> 'open'";
$params = [];
$types = '';
if ($is_tenant) {
    $sql .= " AND c.tenant_id = ?";
    $types .= 'i';
    $params[] = $tenant_id;
}
$sql .= " ORDER BY q.resolved_at DESC LIMIT 8";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $recent[] = $row;
    }
    $stmt->close();
}

$robots = 'noindex, nofollow';
$BASE = '../';
$pageTitle = 'Review Moderation';
$activeNav = 'moderation';
include __DIR__ . '/_shell.php';
?>

<div class="welcome-row" style="margin-bottom:20px;">
    <div>
        <p class="eyebrow"><?php echo $is_tenant ? 'Tenant Workspace' : 'Global Platform'; ?> &middot; Trust &amp; Safety</p>
        <h1>Review Moderation</h1>
        <p class="muted">Reviews flagged by customers, by a business, or automatically by the abuse checks. Nothing is hidden until someone here decides.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="ratings.php" class="btn btn-secondary" style="text-decoration:none;">← All reviews</a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:16px;padding:12px 16px;border-radius:10px;background:#ecfdf3;border:1px solid #abefc6;color:#05603a;font-size:13.5px;">
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:16px;padding:12px 16px;border-radius:10px;background:#fef3f2;border:1px solid #fecdca;color:#912018;font-size:13.5px;">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:22px;">
    <div class="card" style="padding:16px 18px;">
        <div class="muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;">Queued reviews</div>
        <div style="font-size:26px;font-weight:800;margin-top:4px;"><?php echo count($queue); ?></div>
    </div>
    <div class="card" style="padding:16px 18px;">
        <div class="muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;">Open reports</div>
        <div style="font-size:26px;font-weight:800;margin-top:4px;"><?php echo count($reports); ?></div>
    </div>
    <div class="card" style="padding:16px 18px;">
        <div class="muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;">Decided recently</div>
        <div style="font-size:26px;font-weight:800;margin-top:4px;"><?php echo count($recent); ?></div>
    </div>
</div>

<!-- ============ Queue ============ -->
<div class="card" style="padding:20px 22px;margin-bottom:22px;">
    <h2 style="font-size:16px;margin:0 0 4px;">Queue</h2>
    <p class="muted" style="font-size:12.5px;margin:0 0 16px;">Automatic checks only hide a review when the abuse score is very high. Everything else waits here.</p>

    <?php if (!$queue): ?>
        <p class="muted" style="font-size:13.5px;margin:0;">Nothing waiting. Reviews that pass the checks publish immediately.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="text-align:left;color:#64748b;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;">
                    <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Review</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Why</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">Signals</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #e2e8f0;width:300px;">Decision</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($queue as $item): ?>
                <tr style="vertical-align:top;">
                    <td style="padding:12px 10px;border-bottom:1px solid #eef2f7;max-width:340px;">
                        <div style="font-weight:700;"><?php echo htmlspecialchars($item['customer_name'] ?: 'Anonymous'); ?> · <?php echo str_repeat('★', max(1, (int) $item['rating'])); ?></div>
                        <div class="muted" style="font-size:11.5px;margin:2px 0 6px;">
                            <?php echo htmlspecialchars($item['company_name']); ?> · review #<?php echo (int) $item['rating_id']; ?>
                            <?php if ((int) $item['is_verified'] === 1): ?> · <span style="color:#15803d;font-weight:700;">✓ verified</span><?php endif; ?>
                        </div>
                        <div style="font-size:12.5px;color:#334155;line-height:1.55;">
                            <?php
                            $text = trim((string) $item['comment']);
                            echo htmlspecialchars(function_exists('mb_substr') ? mb_substr($text, 0, 220) : substr($text, 0, 220));
                            if (strlen($text) > 220) echo '…';
                            ?>
                        </div>
                    </td>
                    <td style="padding:12px 10px;border-bottom:1px solid #eef2f7;font-size:12px;">
                        <span style="display:inline-block;padding:2px 8px;border-radius:99px;background:#eef2ff;color:#3730a3;font-weight:700;font-size:11px;"><?php echo htmlspecialchars($item['source']); ?></span>
                        <div style="margin-top:6px;color:#334155;"><?php echo htmlspecialchars(str_replace('_', ' ', $item['reason'])); ?></div>
                        <?php if (!empty($item['details'])): ?>
                            <div class="muted" style="margin-top:4px;"><?php echo htmlspecialchars($item['details']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 10px;border-bottom:1px solid #eef2f7;font-size:12px;">
                        <div>Abuse score <strong><?php echo (int) $item['abuse_score']; ?></strong></div>
                        <?php if (!empty($item['abuse_flags'])): ?>
                            <div class="muted" style="margin-top:4px;"><?php echo htmlspecialchars($item['abuse_flags']); ?></div>
                        <?php endif; ?>
                        <div class="muted" style="margin-top:4px;"><?php echo (int) $item['open_reports']; ?> open report(s)</div>
                    </td>
                    <td style="padding:12px 10px;border-bottom:1px solid #eef2f7;">
                        <form method="post" style="display:flex;flex-direction:column;gap:6px;">
                            <?php if (function_exists('sa_csrf_field')) echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="decide">
                            <input type="hidden" name="rating_id" value="<?php echo (int) $item['rating_id']; ?>">
                            <input type="text" name="note" placeholder="Internal note (optional)" maxlength="500"
                                   style="padding:7px 9px;border:1px solid #cbd5e1;border-radius:7px;font-size:12px;">
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <button type="submit" name="decision" value="approve" style="flex:1;padding:8px;border:0;border-radius:7px;background:#16a34a;color:#fff;font-weight:700;font-size:12px;cursor:pointer;">Publish</button>
                                <button type="submit" name="decision" value="remove" style="flex:1;padding:8px;border:0;border-radius:7px;background:#b42318;color:#fff;font-weight:700;font-size:12px;cursor:pointer;">Hide</button>
                                <button type="submit" name="decision" value="dismiss" style="flex:1;padding:8px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#334155;font-weight:700;font-size:12px;cursor:pointer;">Dismiss</button>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ============ Reports ============ -->
<div class="card" style="padding:20px 22px;margin-bottom:22px;">
    <h2 style="font-size:16px;margin:0 0 4px;">Customer &amp; business reports</h2>
    <p class="muted" style="font-size:12.5px;margin:0 0 16px;">A report never hides a review on its own. Upholding one hides it and records the decision.</p>

    <?php if (!$reports): ?>
        <p class="muted" style="font-size:13.5px;margin:0;">No open reports.</p>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ($reports as $report): ?>
        <div style="border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap;">
            <div style="flex:1;min-width:220px;">
                <div style="font-size:12.5px;font-weight:700;"><?php echo htmlspecialchars($report['company_name']); ?> · review #<?php echo (int) $report['rating_id']; ?> · <?php echo (int) $report['rating']; ?>★</div>
                <div class="muted" style="font-size:12px;margin-top:3px;"><?php echo htmlspecialchars(str_replace('_', ' ', $report['reason'])); ?><?php if (!empty($report['details'])): ?> — <?php echo htmlspecialchars($report['details']); ?><?php endif; ?></div>
                <div class="muted" style="font-size:11px;margin-top:4px;">Reported <?php echo htmlspecialchars($report['created_at']); ?></div>
            </div>
            <form method="post" style="display:flex;gap:6px;">
                <?php if (function_exists('sa_csrf_field')) echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="resolve_report">
                <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                <button type="submit" name="status" value="actioned" style="padding:8px 12px;border:0;border-radius:7px;background:#b42318;color:#fff;font-weight:700;font-size:12px;cursor:pointer;">Uphold &amp; hide</button>
                <button type="submit" name="status" value="dismissed" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#334155;font-weight:700;font-size:12px;cursor:pointer;">Dismiss</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ============ Recent ============ -->
<div class="card" style="padding:20px 22px;">
    <h2 style="font-size:16px;margin:0 0 12px;">Recent decisions</h2>
    <?php if (!$recent): ?>
        <p class="muted" style="font-size:13.5px;margin:0;">No decisions yet.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <tbody>
        <?php foreach ($recent as $row): ?>
            <tr>
                <td style="padding:9px 8px;border-bottom:1px solid #eef2f7;width:100px;font-weight:700;color:<?php echo $row['status'] === 'removed' ? '#b42318' : ($row['status'] === 'approved' ? '#15803d' : '#64748b'); ?>;">
                    <?php echo htmlspecialchars(ucfirst($row['status'])); ?>
                </td>
                <td style="padding:9px 8px;border-bottom:1px solid #eef2f7;">
                    <?php echo htmlspecialchars($row['company_name']); ?> · review #<?php echo (int) $row['rating_id']; ?> · <?php echo (int) $row['rating']; ?>★
                    <span class="muted">· <?php echo htmlspecialchars($row['reason']); ?></span>
                </td>
                <td style="padding:9px 8px;border-bottom:1px solid #eef2f7;text-align:right;color:#94a3b8;"><?php echo htmlspecialchars((string) $row['resolved_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>
