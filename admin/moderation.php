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
   Filters
   ============================================================ */
$source_filter = isset($_GET['source']) ? strtolower(trim((string) $_GET['source'])) : 'all';
$risk_filter   = isset($_GET['risk']) ? strtolower(trim((string) $_GET['risk'])) : 'all'; // high|medium|low
$search_q      = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$allowed_sources = ['all', 'auto', 'report', 'business', 'photo'];
if (!in_array($source_filter, $allowed_sources, true)) {
    $source_filter = 'all';
}
if (!in_array($risk_filter, ['all', 'high', 'medium', 'low'], true)) {
    $risk_filter = 'all';
}

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
    } elseif ($_POST['action'] === 'bulk_decide') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        $decision = strtolower((string) ($_POST['decision'] ?? ''));
        if (!$ids || !in_array($decision, ['approve', 'remove', 'dismiss'], true)) {
            $error = 'Select at least one review and a decision.';
        } else {
            $ok = 0;
            foreach ($ids as $rid) {
                if ($rid > 0 && moderation_can_touch($conn, $rid, $tenant_id, $is_tenant)) {
                    if (review_moderation_decide($conn, $rid, $decision, 'Bulk action', 'admin', (int) ($_SESSION['admin_id'] ?? 0))) {
                        $ok++;
                    }
                }
            }
            $success = ($decision === 'approve' ? 'Published' : ($decision === 'remove' ? 'Hidden' : 'Dismissed')) . " $ok review(s).";
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
   Helper: risk label from abuse score
   ============================================================ */
function mod_risk_bucket($score) {
    $score = (int) $score;
    if ($score >= 70) return 'high';
    if ($score >= 40) return 'medium';
    return 'low';
}
function mod_stars($n) {
    $n = max(1, min(5, (int) $n));
    return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
}
function mod_source_meta($source) {
    $map = [
        'auto'     => ['label' => 'Auto flag',   'icon' => '🤖', 'tone' => 'amber'],
        'report'   => ['label' => 'User report', 'icon' => '🚩', 'tone' => 'red'],
        'business' => ['label' => 'Business',    'icon' => '🏢', 'tone' => 'blue'],
        'photo'    => ['label' => 'Photo review','icon' => '🖼️', 'tone' => 'purple'],
    ];
    return $map[$source] ?? ['label' => ucfirst($source), 'icon' => '•', 'tone' => 'slate'];
}
function mod_time_ago($dt) {
    if (!$dt) return '';
    try {
        $t = new DateTime($dt);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $t->getTimestamp();
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff/60) . 'm ago';
        if ($diff < 86400) return floor($diff/3600) . 'h ago';
        if ($diff < 604800) return floor($diff/86400) . 'd ago';
        return $t->format('M j, Y');
    } catch (\Throwable $e) {
        return (string) $dt;
    }
}

/* ============================================================
   Read — Queue
   ============================================================ */
$queue = [];
$counts = ['auto' => 0, 'report' => 0, 'business' => 0, 'photo' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

$sql = "SELECT q.id AS queue_id, q.source, q.reason, q.details, q.created_at,
               r.id AS rating_id, r.rating, r.customer_name, r.customer_email, r.comment,
               r.moderation_status, r.abuse_score, r.abuse_flags, r.is_verified, r.verification_type,
               c.id AS company_id, c.company_name,
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
if ($source_filter !== 'all') {
    $sql .= " AND q.source = ?";
    $types .= 's';
    $params[] = $source_filter;
}
if ($search_q !== '') {
    $sql .= " AND (r.customer_name LIKE ? OR r.comment LIKE ? OR c.company_name LIKE ?)";
    $types .= 'sss';
    $like = '%' . $search_q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$sql .= " ORDER BY q.created_at ASC LIMIT 200";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $row['risk'] = mod_risk_bucket($row['abuse_score']);
        $counts[$row['source']] = ($counts[$row['source']] ?? 0) + 1;
        $counts[$row['risk']]++;
        if ($risk_filter === 'all' || $row['risk'] === $risk_filter) {
            $queue[] = $row;
        }
    }
    $stmt->close();
}

/* ============================================================
   Read — Reports
   ============================================================ */
$reports = [];
$sql = "SELECT rr.id, rr.reason, rr.details, rr.created_at, rr.reporter_ip,
               r.id AS rating_id, r.rating, r.customer_name, r.comment, c.company_name
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
$sql .= " ORDER BY rr.created_at ASC LIMIT 200";
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

/* ============================================================
   Read — Recent decisions
   ============================================================ */
$recent = [];
$stats = ['approved' => 0, 'removed' => 0, 'dismissed' => 0];
$sql = "SELECT q.id, q.status, q.resolved_at, q.reason, q.source,
               r.id AS rating_id, r.rating, r.customer_name, c.company_name
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
$sql .= " ORDER BY q.resolved_at DESC LIMIT 50";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        if (isset($stats[$row['status']])) $stats[$row['status']]++;
        $recent[] = $row;
    }
    $stmt->close();
}

// 7-day throughput for stat card
$last7 = 0;
$last7_sql = "SELECT COUNT(*) AS n FROM review_moderation_queue q
    JOIN ratings r ON r.id = q.rating_id JOIN customers c ON c.id = r.company_id
    WHERE q.status <> 'open' AND q.resolved_at >= (NOW() - INTERVAL 7 DAY)";
if ($is_tenant) {
    $last7_sql .= " AND c.tenant_id = " . (int) $tenant_id;
}
$r7 = $conn->query($last7_sql);
if ($r7) { $last7 = (int) ($r7->fetch_assoc()['n'] ?? 0); }

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'queue';
if (!in_array($tab, ['queue', 'reports', 'history'], true)) $tab = 'queue';

$robots = 'noindex, nofollow';
$BASE = '../';
$pageTitle = 'Review Moderation';
$activeNav = 'moderation';
include __DIR__ . '/_shell.php';

$total_queue = array_sum([$counts['auto'], $counts['report'], $counts['business'], $counts['photo']]);
?>
<style>
/* Moderation page — scoped styles (dark-mode aware via CSS vars) */
.mod-hero{background:linear-gradient(135deg,var(--navy,#0a1926) 0%,#163651 100%);color:#fff;border-radius:18px;padding:28px 30px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;gap:24px;flex-wrap:wrap;position:relative;overflow:hidden;box-shadow:0 12px 30px rgba(10,25,38,.12)}
.mod-hero::before{content:"";position:absolute;right:-40px;top:-40px;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle,rgba(194,245,66,.18) 0%,transparent 70%);pointer-events:none}
.mod-hero .eyebrow{color:#8bb0c9!important;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:1.2px;margin:0 0 10px}
.mod-hero h1{font-size:28px;margin:0 0 8px;color:#fff;letter-spacing:-.5px}
.mod-hero p{color:#a9c2d5;font-size:13.5px;max-width:560px;line-height:1.55;margin:0}
.mod-hero-actions{display:flex;gap:10px;flex-wrap:wrap;z-index:1}

.mod-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px}
.mod-stat{background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:18px 20px;display:flex;align-items:flex-start;gap:14px;box-shadow:0 2px 8px rgba(15,23,42,.03);transition:transform .15s ease,border-color .15s ease}
.mod-stat:hover{transform:translateY(-2px);border-color:var(--lime,#c2f542)}
.mod-stat-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-size:20px;flex-shrink:0}
.mod-stat-icon.is-amber{background:#fef3c7;color:#d97706}
.mod-stat-icon.is-red{background:#fee2e2;color:#dc2626}
.mod-stat-icon.is-green{background:#dcfce7;color:#15803d}
.mod-stat-icon.is-blue{background:#dbeafe;color:#2563eb}
.mod-stat-icon.is-purple{background:#ede9fe;color:#7c3aed}
.mod-stat-body{flex:1;min-width:0}
.mod-stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted,#94a3b8);margin-bottom:4px}
.mod-stat-value{font-size:26px;font-weight:800;color:var(--ink,#0f172a);line-height:1;letter-spacing:-.5px}
.mod-stat-sub{font-size:11.5px;color:var(--muted,#94a3b8);margin-top:6px}

.mod-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.mod-search{position:relative;flex:1;min-width:220px;max-width:360px}
.mod-search input{width:100%;padding:10px 14px 10px 38px;border-radius:10px;border:1px solid var(--line,#cbd5e1);background:var(--bg,#f8fafc);color:var(--ink);font-family:inherit;font-size:13px;transition:border-color .15s,box-shadow .15s}
.mod-search input:focus{outline:none;border-color:var(--lime);box-shadow:0 0 0 3px rgba(194,245,66,.15)}
.mod-search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--muted)}
.mod-filters{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.mod-filter-select{padding:9px 12px;border-radius:9px;border:1px solid var(--line,#cbd5e1);background:var(--card-bg,#fff);color:var(--ink);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
.mod-filter-select:focus{outline:none;border-color:var(--lime)}

.mod-pills{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px}
.mod-pill{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:10px;border:1px solid var(--line,#e2e8f0);background:var(--card-bg,#fff);color:var(--muted,#64748b);font-size:12.5px;font-weight:600;text-decoration:none;transition:all .15s ease;cursor:pointer;font-family:inherit}
.mod-pill:hover{background:var(--bg,#f1f5f9);color:var(--ink)}
.mod-pill.is-active{background:var(--navy,#0a1926);color:var(--lime,#c2f542);border-color:transparent;box-shadow:0 2px 8px rgba(10,25,38,.12)}
.mod-pill .count{background:rgba(148,163,184,.2);color:inherit;padding:1px 7px;border-radius:99px;font-size:11px;font-weight:800}
.mod-pill.is-active .count{background:rgba(194,245,66,.22);color:var(--lime)}

.mod-bulk-bar{display:none;align-items:center;gap:12px;padding:12px 16px;background:rgba(194,245,66,.08);border:1px solid rgba(194,245,66,.3);border-radius:12px;margin-bottom:14px;font-size:13px;font-weight:600;color:var(--ink)}
.mod-bulk-bar.is-visible{display:flex}
.mod-bulk-bar .count-selected{color:var(--lime,#c2f542);font-weight:800}
.mod-bulk-bar select{padding:7px 10px;border-radius:7px;border:1px solid var(--line);background:var(--card-bg);color:var(--ink);font-family:inherit;font-size:12px;font-weight:600}

.mod-card{background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:16px;box-shadow:0 2px 12px rgba(15,23,42,.035);margin-bottom:22px;overflow:hidden;transition:background-color .3s,border-color .3s}
.mod-card-head{padding:20px 24px;border-bottom:1px solid var(--line,#eef2f7);display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
.mod-card-head h2{font-size:16px;margin:0;color:var(--ink,#0f172a);font-weight:700;display:flex;align-items:center;gap:10px}
.mod-card-head h2 .count-badge{background:var(--bg,#f1f5f9);color:var(--muted);padding:2px 9px;border-radius:99px;font-size:11px;font-weight:800}
.mod-card-head p{margin:4px 0 0;font-size:12.5px;color:var(--muted,#64748b)}

.mod-empty{padding:60px 24px;text-align:center}
.mod-empty-icon{font-size:44px;margin-bottom:14px;opacity:.6}
.mod-empty h3{font-size:16px;margin:0 0 6px;color:var(--ink)}
.mod-empty p{font-size:13px;color:var(--muted);margin:0;max-width:420px;margin-left:auto;margin-right:auto}

/* Review cards in queue */
.mod-queue{display:flex;flex-direction:column;gap:0}
.mod-review{display:grid;grid-template-columns:auto 1fr auto;gap:18px;padding:20px 24px;border-bottom:1px solid var(--line,#eef2f7);align-items:flex-start;transition:background .15s ease}
.mod-review:last-child{border-bottom:none}
.mod-review:hover{background:var(--bg,#f8fafc)}
.mod-review-check{padding-top:4px}
.mod-review-check input{width:18px;height:18px;accent-color:var(--lime,#c2f542);cursor:pointer}
.mod-review-main{min-width:0}
.mod-review-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.mod-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#c2f542,#84cc16);color:#0a1926;display:grid;place-items:center;font-weight:800;font-size:14px;flex-shrink:0}
.mod-review-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
.mod-review-name{font-weight:700;font-size:14px;color:var(--ink)}
.mod-verified{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:99px;background:#dcfce7;color:#15803d;font-size:10.5px;font-weight:800}
.mod-stars-row{color:#f59e0b;font-size:14px;letter-spacing:1px;font-weight:700}
.mod-review-company{font-size:12px;color:var(--muted)}
.mod-review-company a{color:var(--muted);text-decoration:none}
.mod-review-company a:hover{color:var(--lime);text-decoration:underline}
.mod-review-comment{font-size:13.5px;color:var(--ink,#334155);line-height:1.6;margin:8px 0 10px;white-space:pre-wrap;word-wrap:break-word}
.mod-review-comment.is-clamped{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.mod-toggle-text{background:none;border:none;color:var(--lime,#84cc16);font-weight:700;font-size:12px;cursor:pointer;padding:0;font-family:inherit}
.mod-review-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.mod-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700}
.mod-badge.is-auto{background:#fef3c7;color:#b45309}
.mod-badge.is-report{background:#fee2e2;color:#b91c1c}
.mod-badge.is-business{background:#dbeafe;color:#1d4ed8}
.mod-badge.is-photo{background:#ede9fe;color:#6d28d9}
.mod-badge.is-risk-high{background:#fee2e2;color:#b91c1c}
.mod-badge.is-risk-medium{background:#fef3c7;color:#b45309}
.mod-badge.is-risk-low{background:#dcfce7;color:#15803d}

.mod-review-side{display:flex;flex-direction:column;gap:10px;min-width:260px;max-width:300px;width:100%}
.mod-signals{background:var(--bg,#f8fafc);border:1px solid var(--line,#e2e8f0);border-radius:10px;padding:12px 14px}
.mod-signal-row{display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:6px}
.mod-signal-row:last-child{margin-bottom:0}
.mod-signal-label{color:var(--muted);font-weight:600}
.mod-signal-val{color:var(--ink);font-weight:700}
.mod-score-track{height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin-top:4px}
.mod-score-fill{height:100%;border-radius:99px;transition:width .4s ease}
.mod-score-fill.is-low{background:linear-gradient(90deg,#22c55e,#16a34a)}
.mod-score-fill.is-medium{background:linear-gradient(90deg,#f59e0b,#d97706)}
.mod-score-fill.is-high{background:linear-gradient(90deg,#ef4444,#b91c1c)}
.mod-signal-tags{margin-top:8px;font-size:11px;color:var(--muted);word-break:break-word}

.mod-actions{display:flex;flex-direction:column;gap:8px}
.mod-note-input{width:100%;padding:9px 12px;border:1px solid var(--line,#cbd5e1);border-radius:8px;font-size:12.5px;font-family:inherit;background:var(--card-bg);color:var(--ink);transition:border-color .15s}
.mod-note-input:focus{outline:none;border-color:var(--lime);box-shadow:0 0 0 3px rgba(194,245,66,.15)}
.mod-btn-row{display:flex;gap:6px}
.mod-btn{flex:1;padding:9px 10px;border:0;border-radius:8px;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s ease;display:inline-flex;align-items:center;justify-content:center;gap:5px}
.mod-btn-approve{background:#16a34a;color:#fff}
.mod-btn-approve:hover{background:#15803d;transform:translateY(-1px);box-shadow:0 4px 12px rgba(22,163,74,.25)}
.mod-btn-remove{background:#dc2626;color:#fff}
.mod-btn-remove:hover{background:#b91c1c;transform:translateY(-1px);box-shadow:0 4px 12px rgba(220,38,38,.25)}
.mod-btn-dismiss{background:transparent;color:var(--ink);border:1px solid var(--line,#cbd5e1)}
.mod-btn-dismiss:hover{background:var(--bg,#f1f5f9);border-color:#94a3b8}

/* Reports cards */
.mod-report{display:grid;grid-template-columns:1fr auto;gap:16px;padding:18px 24px;border-bottom:1px solid var(--line,#eef2f7);align-items:flex-start}
.mod-report:last-child{border-bottom:none}
.mod-report:hover{background:var(--bg,#f8fafc)}
.mod-report-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px}
.mod-report-icon{width:34px;height:34px;border-radius:10px;background:#fee2e2;color:#b91c1c;display:grid;place-items:center;font-size:16px;flex-shrink:0}
.mod-report-title{font-weight:700;font-size:13.5px;color:var(--ink)}
.mod-report-sub{font-size:12px;color:var(--muted);margin-top:4px}
.mod-report-reason{font-size:12.5px;color:var(--ink);margin-top:8px;padding:10px 12px;background:var(--bg,#fef2f2);border-radius:8px;border-left:3px solid #dc2626}
.mod-report-actions{display:flex;flex-direction:column;gap:6px;min-width:160px}

/* History table */
.mod-history-table{width:100%;border-collapse:collapse;font-size:13px}
.mod-history-table th{padding:12px 24px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:700;background:var(--bg,#f8fafc);border-bottom:1px solid var(--line,#e2e8f0)}
.mod-history-table td{padding:14px 24px;border-bottom:1px solid var(--line,#eef2f7);color:var(--ink);vertical-align:middle}
.mod-history-table tr:last-child td{border-bottom:none}
.mod-history-table tr:hover td{background:var(--bg,#f8fafc)}
.mod-status-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:99px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px}
.mod-status-chip.approved{background:#dcfce7;color:#15803d}
.mod-status-chip.removed{background:#fee2e2;color:#b91c1c}
.mod-status-chip.dismissed{background:#f1f5f9;color:#475569}

.mod-alert{padding:14px 18px;border-radius:12px;margin-bottom:18px;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:10px}
.mod-alert-success{background:#ecfdf3;border:1px solid #abefc6;color:#05603a}
.mod-alert-danger{background:#fef3f2;border:1px solid #fecdca;color:#912018}

/* Dark mode overrides */
:root[data-theme='dark'] .mod-hero{background:linear-gradient(135deg,#0b1c2b 0%,#102b3e 100%);box-shadow:0 12px 30px rgba(0,0,0,.4)}
:root[data-theme='dark'] .mod-hero::before{background:radial-gradient(circle,rgba(194,245,66,.12) 0%,transparent 70%)}
:root[data-theme='dark'] .mod-stat{background:#0f1f2e;border-color:rgba(255,255,255,.08);box-shadow:0 4px 14px rgba(0,0,0,.2)}
:root[data-theme='dark'] .mod-card{background:#0b1c2b;border-color:rgba(255,255,255,.08);box-shadow:0 8px 24px rgba(0,0,0,.3)}
:root[data-theme='dark'] .mod-card-head{border-color:rgba(255,255,255,.08)}
:root[data-theme='dark'] .mod-review,
:root[data-theme='dark'] .mod-report{border-color:rgba(255,255,255,.06)}
:root[data-theme='dark'] .mod-review:hover,
:root[data-theme='dark'] .mod-report:hover{background:rgba(255,255,255,.03)}
:root[data-theme='dark'] .mod-signals{background:rgba(255,255,255,.03);border-color:rgba(255,255,255,.08)}
:root[data-theme='dark'] .mod-score-track{background:rgba(255,255,255,.1)}
:root[data-theme='dark'] .mod-note-input{background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.12);color:#e2e8f0}
:root[data-theme='dark'] .mod-btn-dismiss{border-color:rgba(255,255,255,.15);color:#e2e8f0}
:root[data-theme='dark'] .mod-btn-dismiss:hover{background:rgba(255,255,255,.06)}
:root[data-theme='dark'] .mod-filter-select{background:#0f1f2e;border-color:rgba(255,255,255,.1);color:#e2e8f0}
:root[data-theme='dark'] .mod-pill{background:#0f1f2e;border-color:rgba(255,255,255,.08);color:#94a3b8}
:root[data-theme='dark'] .mod-pill:hover{background:rgba(255,255,255,.05);color:#e2e8f0}
:root[data-theme='dark'] .mod-pill.is-active{background:rgba(194,245,66,.12);color:var(--lime);border-color:rgba(194,245,66,.25)}
:root[data-theme='dark'] .mod-history-table th{background:rgba(255,255,255,.03);color:#94a3b8;border-color:rgba(255,255,255,.08)}
:root[data-theme='dark'] .mod-history-table td{border-color:rgba(255,255,255,.06)}
:root[data-theme='dark'] .mod-history-table tr:hover td{background:rgba(255,255,255,.025)}
:root[data-theme='dark'] .mod-status-chip.dismissed{background:rgba(255,255,255,.07);color:#cbd5e1}
:root[data-theme='dark'] .mod-report-reason{background:rgba(220,38,38,.1);border-left-color:#dc2626;color:#fca5a5}
:root[data-theme='dark'] .mod-bulk-bar{background:rgba(194,245,66,.08);border-color:rgba(194,245,66,.25)}
:root[data-theme='dark'] .mod-avatar{background:linear-gradient(135deg,#c2f542,#a8e030);color:#0a1926}

/* Responsive */
@media (max-width: 900px){
  .mod-review{grid-template-columns:1fr;gap:14px}
  .mod-review-side{max-width:100%;min-width:0}
  .mod-report{grid-template-columns:1fr}
  .mod-hero{padding:22px}
  .mod-hero h1{font-size:22px}
  .mod-stats{grid-template-columns:repeat(2,1fr)}
}
@media (max-width: 560px){
  .mod-stats{grid-template-columns:1fr}
  .mod-btn-row{flex-direction:column}
}
</style>

<!-- ============ Hero ============ -->
<section class="mod-hero">
  <div>
    <p class="eyebrow"><?php echo $is_tenant ? 'Tenant Workspace' : 'Global Platform'; ?> · Trust &amp; Safety</p>
    <h1>Review Moderation</h1>
    <p>Reviews flagged by customers, businesses, or the automated abuse detector. Nothing is hidden until a team member reviews it and makes a call.</p>
  </div>
  <div class="mod-hero-actions">
    <a href="ratings.php" class="btn btn-secondary" style="background:rgba(255,255,255,.08);color:#e2e8f0;border:1px solid rgba(255,255,255,.15);text-decoration:none;">← All reviews</a>
    <a href="notifications.php" class="btn btn-primary" style="text-decoration:none;">Open inbox</a>
  </div>
</section>

<?php if ($success): ?>
  <div class="mod-alert mod-alert-success" role="status">
    <span style="font-size:18px;">✓</span>
    <span><?php echo htmlspecialchars($success); ?></span>
  </div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="mod-alert mod-alert-danger" role="alert">
    <span style="font-size:18px;">⚠</span>
    <span><?php echo htmlspecialchars($error); ?></span>
  </div>
<?php endif; ?>

<!-- ============ Stats ============ -->
<div class="mod-stats">
  <div class="mod-stat">
    <div class="mod-stat-icon is-amber">⏳</div>
    <div class="mod-stat-body">
      <div class="mod-stat-label">Awaiting review</div>
      <div class="mod-stat-value"><?php echo (int) $total_queue; ?></div>
      <div class="mod-stat-sub"><?php echo (int) $counts['high']; ?> high risk</div>
    </div>
  </div>
  <div class="mod-stat">
    <div class="mod-stat-icon is-red">🚩</div>
    <div class="mod-stat-body">
      <div class="mod-stat-label">Open reports</div>
      <div class="mod-stat-value"><?php echo count($reports); ?></div>
      <div class="mod-stat-sub">From customers & businesses</div>
    </div>
  </div>
  <div class="mod-stat">
    <div class="mod-stat-icon is-green">✅</div>
    <div class="mod-stat-body">
      <div class="mod-stat-label">Reviewed (7 days)</div>
      <div class="mod-stat-value"><?php echo (int) $last7; ?></div>
      <div class="mod-stat-sub"><?php echo (int) $stats['approved']; ?> approved · <?php echo (int) $stats['removed']; ?> removed</div>
    </div>
  </div>
  <div class="mod-stat">
    <div class="mod-stat-icon is-blue">🤖</div>
    <div class="mod-stat-body">
      <div class="mod-stat-label">Auto-flagged</div>
      <div class="mod-stat-value"><?php echo (int) $counts['auto']; ?></div>
      <div class="mod-stat-sub">By the trust &amp; safety engine</div>
    </div>
  </div>
</div>

<!-- ============ Tabs ============ -->
<nav class="admin-tabs" role="tablist" aria-label="Moderation sections">
  <a href="?tab=queue" class="admin-tab-btn <?php echo $tab === 'queue' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $tab === 'queue' ? 'true' : 'false'; ?>">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    Queue <span style="opacity:.7;font-weight:700">(<?php echo count($queue); ?>)</span>
  </a>
  <a href="?tab=reports" class="admin-tab-btn <?php echo $tab === 'reports' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $tab === 'reports' ? 'true' : 'false'; ?>">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
    Reports <span style="opacity:.7;font-weight:700">(<?php echo count($reports); ?>)</span>
  </a>
  <a href="?tab=history" class="admin-tab-btn <?php echo $tab === 'history' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $tab === 'history' ? 'true' : 'false'; ?>">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
    Audit log
  </a>
</nav>

<!-- ============ TAB: Queue ============ -->
<?php if ($tab === 'queue'): ?>

<form method="get" class="mod-toolbar" id="modQueueForm">
  <input type="hidden" name="tab" value="queue">
  <div class="mod-search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="text" name="q" value="<?php echo htmlspecialchars($search_q); ?>" placeholder="Search reviewer, comment, or business…" aria-label="Search queue">
  </div>
  <div class="mod-filters">
    <select name="source" class="mod-filter-select" onchange="this.form.submit()">
      <option value="all" <?php echo $source_filter === 'all' ? 'selected' : ''; ?>>All sources</option>
      <option value="auto" <?php echo $source_filter === 'auto' ? 'selected' : ''; ?>>🤖 Auto-detected</option>
      <option value="report" <?php echo $source_filter === 'report' ? 'selected' : ''; ?>>🚩 User reports</option>
      <option value="business" <?php echo $source_filter === 'business' ? 'selected' : ''; ?>>🏢 Business disputes</option>
      <option value="photo" <?php echo $source_filter === 'photo' ? 'selected' : ''; ?>>🖼️ Photo reviews</option>
    </select>
    <select name="risk" class="mod-filter-select" onchange="this.form.submit()">
      <option value="all" <?php echo $risk_filter === 'all' ? 'selected' : ''; ?>>Any risk</option>
      <option value="high" <?php echo $risk_filter === 'high' ? 'selected' : ''; ?>>High risk (≥70)</option>
      <option value="medium" <?php echo $risk_filter === 'medium' ? 'selected' : ''; ?>>Medium (40–69)</option>
      <option value="low" <?php echo $risk_filter === 'low' ? 'selected' : ''; ?>>Low (&lt;40)</option>
    </select>
  </div>
</form>

<!-- Risk/source summary pills -->
<div class="mod-pills" role="navigation" aria-label="Filter by source">
  <a href="?tab=queue&source=all&risk=<?php echo urlencode($risk_filter); ?>&q=<?php echo urlencode($search_q); ?>" class="mod-pill <?php echo $source_filter === 'all' ? 'is-active' : ''; ?>">All <span class="count"><?php echo (int) $total_queue; ?></span></a>
  <a href="?tab=queue&source=auto&risk=<?php echo urlencode($risk_filter); ?>&q=<?php echo urlencode($search_q); ?>" class="mod-pill <?php echo $source_filter === 'auto' ? 'is-active' : ''; ?>">🤖 Auto <span class="count"><?php echo (int) $counts['auto']; ?></span></a>
  <a href="?tab=queue&source=report&risk=<?php echo urlencode($risk_filter); ?>&q=<?php echo urlencode($search_q); ?>" class="mod-pill <?php echo $source_filter === 'report' ? 'is-active' : ''; ?>">🚩 Reports <span class="count"><?php echo (int) $counts['report']; ?></span></a>
  <a href="?tab=queue&source=business&risk=<?php echo urlencode($risk_filter); ?>&q=<?php echo urlencode($search_q); ?>" class="mod-pill <?php echo $source_filter === 'business' ? 'is-active' : ''; ?>">🏢 Business <span class="count"><?php echo (int) $counts['business']; ?></span></a>
  <a href="?tab=queue&source=photo&risk=<?php echo urlencode($risk_filter); ?>&q=<?php echo urlencode($search_q); ?>" class="mod-pill <?php echo $source_filter === 'photo' ? 'is-active' : ''; ?>">🖼️ Photo <span class="count"><?php echo (int) $counts['photo']; ?></span></a>
</div>

<!-- Bulk action bar -->
<form method="post" id="bulkForm">
  <?php if (function_exists('sa_csrf_field')) echo sa_csrf_field(); ?>
  <input type="hidden" name="action" value="bulk_decide">
  <div class="mod-bulk-bar" id="bulkBar">
    <span><span class="count-selected" id="bulkCount">0</span> selected</span>
    <select name="decision" id="bulkDecision">
      <option value="approve">Publish</option>
      <option value="remove">Hide</option>
      <option value="dismiss">Dismiss</option>
    </select>
    <button type="submit" class="btn btn-primary" style="padding:8px 16px;font-size:12px;">Apply to selected</button>
    <button type="button" class="mod-toggle-text" id="bulkClear" style="margin-left:auto">Clear</button>
  </div>

  <div class="mod-card">
    <div class="mod-card-head">
      <div>
        <h2>Moderation queue <span class="count-badge"><?php echo count($queue); ?></span></h2>
        <p>Automatic checks only auto-hide reviews with very high abuse scores. Everything else waits here for a human decision.</p>
      </div>
    </div>

    <?php if (!$queue): ?>
      <div class="mod-empty">
        <div class="mod-empty-icon">🎉</div>
        <h3>All caught up</h3>
        <p>Nothing is waiting for review right now. New reviews that fail automatic checks or get reported will appear here.</p>
      </div>
    <?php else: ?>
    <div class="mod-queue">
      <?php foreach ($queue as $item):
        $meta = mod_source_meta($item['source']);
        $risk = $item['risk'];
        $initials = strtoupper(substr($item['customer_name'] ?: 'A', 0, 1));
        $comment = trim((string) $item['comment']);
        $is_long = function_exists('mb_strlen') ? mb_strlen($comment) > 280 : strlen($comment) > 280;
      ?>
      <article class="mod-review">
        <div class="mod-review-check">
          <input type="checkbox" name="ids[]" value="<?php echo (int) $item['rating_id']; ?>" class="mod-bulk-check" aria-label="Select review #<?php echo (int) $item['rating_id']; ?>">
        </div>
        <div class="mod-review-main">
          <div class="mod-review-top">
            <div class="mod-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="mod-review-meta">
              <span class="mod-review-name"><?php echo htmlspecialchars($item['customer_name'] ?: 'Anonymous'); ?></span>
              <?php if ((int) $item['is_verified'] === 1): ?>
                <span class="mod-verified">✓ verified<?php echo !empty($item['verification_type']) ? ' · ' . htmlspecialchars($item['verification_type']) : ''; ?></span>
              <?php endif; ?>
              <span class="mod-stars-row"><?php echo mod_stars($item['rating']); ?></span>
            </div>
          </div>
          <div class="mod-review-company">
            on <a href="ratings.php?company_id=<?php echo (int) ($item['company_id'] ?? 0); ?>"><strong><?php echo htmlspecialchars($item['company_name']); ?></strong></a>
            · review #<?php echo (int) $item['rating_id']; ?>
            · <?php echo mod_time_ago($item['created_at']); ?>
          </div>
          <?php if ($comment !== ''): ?>
            <div class="mod-review-comment <?php echo $is_long ? 'is-clamped' : ''; ?>"><?php echo htmlspecialchars($comment); ?></div>
            <?php if ($is_long): ?>
              <button type="button" class="mod-toggle-text" data-toggle-comment>Read more</button>
            <?php endif; ?>
          <?php else: ?>
            <div class="mod-review-comment" style="color:var(--muted);font-style:italic;">No written comment — star rating only.</div>
          <?php endif; ?>
          <div class="mod-review-badges">
            <span class="mod-badge is-<?php echo htmlspecialchars($meta['tone']); ?>"><?php echo $meta['icon']; ?> <?php echo htmlspecialchars($meta['label']); ?></span>
            <span class="mod-badge is-risk-<?php echo $risk; ?>">● Risk: <?php echo ucfirst($risk); ?> (<?php echo (int) $item['abuse_score']; ?>)</span>
            <?php if ((int) $item['open_reports'] > 0): ?>
              <span class="mod-badge is-report"><?php echo (int) $item['open_reports']; ?> open report(s)</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="mod-review-side">
          <div class="mod-signals">
            <div class="mod-signal-row">
              <span class="mod-signal-label">Abuse score</span>
              <span class="mod-signal-val"><?php echo (int) $item['abuse_score']; ?>/100</span>
            </div>
            <div class="mod-score-track">
              <div class="mod-score-fill is-<?php echo $risk; ?>" style="width:<?php echo min(100, (int) $item['abuse_score']); ?>%"></div>
            </div>
            <div class="mod-signal-row" style="margin-top:10px;">
              <span class="mod-signal-label">Flag reason</span>
            </div>
            <div style="font-size:12px;color:var(--ink);font-weight:600;"><?php echo htmlspecialchars(str_replace('_', ' ', $item['reason'])); ?></div>
            <?php if (!empty($item['details'])): ?>
              <div class="mod-signal-tags"><?php echo htmlspecialchars($item['details']); ?></div>
            <?php endif; ?>
            <?php if (!empty($item['abuse_flags'])): ?>
              <div class="mod-signal-tags" style="margin-top:6px"><strong style="color:var(--ink);font-size:11px;">Signals:</strong> <?php echo htmlspecialchars($item['abuse_flags']); ?></div>
            <?php endif; ?>
          </div>

          <form method="post" class="mod-actions">
            <?php if (function_exists('sa_csrf_field')) echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="decide">
            <input type="hidden" name="rating_id" value="<?php echo (int) $item['rating_id']; ?>">
            <input type="text" name="note" placeholder="Internal note (optional — e.g. why you approved/hid)" maxlength="500" class="mod-note-input">
            <div class="mod-btn-row">
              <button type="submit" name="decision" value="approve" class="mod-btn mod-btn-approve" title="Publish review">✓ Publish</button>
              <button type="submit" name="decision" value="remove" class="mod-btn mod-btn-remove" title="Hide from public feed" onclick="return confirm('Hide this review from the public feed?')">✕ Hide</button>
              <button type="submit" name="decision" value="dismiss" class="mod-btn mod-btn-dismiss" title="Leave review as-is, close the flag">⊘ Dismiss</button>
            </div>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</form>

<!-- ============ TAB: Reports ============ -->
<?php elseif ($tab === 'reports'): ?>

<div class="mod-card">
  <div class="mod-card-head">
    <div>
      <h2>Customer &amp; business reports <span class="count-badge"><?php echo count($reports); ?></span></h2>
      <p>A report alone never hides a review. Upholding one removes the review and logs the decision against the reporter.</p>
    </div>
  </div>

  <?php if (!$reports): ?>
    <div class="mod-empty">
      <div class="mod-empty-icon">🃏</div>
      <h3>No open reports</h3>
      <p>When customers or businesses flag a review, it appears here for review.</p>
    </div>
  <?php else: ?>
  <div class="mod-queue">
    <?php foreach ($reports as $report):
      $initials = strtoupper(substr($report['customer_name'] ?: 'R', 0, 1));
    ?>
    <article class="mod-report">
      <div>
        <div class="mod-report-head">
          <div class="mod-report-icon">🚩</div>
          <div>
            <div class="mod-report-title"><?php echo htmlspecialchars($report['company_name']); ?> · review #<?php echo (int) $report['rating_id']; ?> · <span class="mod-stars-row"><?php echo mod_stars($report['rating']); ?></span></div>
            <div class="mod-report-sub">
              By <strong><?php echo htmlspecialchars($report['customer_name'] ?: 'Anonymous'); ?></strong>
              · reported <?php echo mod_time_ago($report['created_at']); ?>
              <?php if (!empty($report['reporter_ip'])): ?> · from <code style="font-size:11px;background:var(--bg);padding:1px 6px;border-radius:4px;color:var(--muted);"><?php echo htmlspecialchars($report['reporter_ip']); ?></code><?php endif; ?>
            </div>
          </div>
        </div>
        <?php if (!empty(trim((string) $report['comment']))): ?>
          <div class="mod-review-comment" style="margin:8px 0 8px 44px;font-size:13px;">
            <?php echo htmlspecialchars(trim((string) $report['comment'])); ?>
          </div>
        <?php endif; ?>
        <div class="mod-report-reason" style="margin-left:44px;">
          <strong>Reason:</strong> <?php echo htmlspecialchars(str_replace('_', ' ', $report['reason'])); ?>
          <?php if (!empty($report['details'])): ?> — <?php echo htmlspecialchars($report['details']); ?><?php endif; ?>
        </div>
      </div>
      <form method="post" class="mod-report-actions">
        <?php if (function_exists('sa_csrf_field')) echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="resolve_report">
        <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
        <button type="submit" name="status" value="actioned" class="mod-btn mod-btn-remove" onclick="return confirm('Uphold this report and hide the review?')">✕ Uphold &amp; hide</button>
        <button type="submit" name="status" value="dismissed" class="mod-btn mod-btn-dismiss">⊘ Dismiss</button>
      </form>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ============ TAB: History ============ -->
<?php else: ?>

<div class="mod-card">
  <div class="mod-card-head">
    <div>
      <h2>Recent decisions <span class="count-badge"><?php echo count($recent); ?></span></h2>
      <p>Audit trail of all moderation actions taken in this workspace. Decisions are permanent but reversible from the main reviews page.</p>
    </div>
    <div style="display:flex;gap:20px;font-size:12.5px;">
      <span><span class="mod-status-chip approved" style="margin-right:6px">Approved</span> <?php echo (int) $stats['approved']; ?></span>
      <span><span class="mod-status-chip removed" style="margin-right:6px">Removed</span> <?php echo (int) $stats['removed']; ?></span>
      <span><span class="mod-status-chip dismissed" style="margin-right:6px">Dismissed</span> <?php echo (int) $stats['dismissed']; ?></span>
    </div>
  </div>

  <?php if (!$recent): ?>
    <div class="mod-empty">
      <div class="mod-empty-icon">📋</div>
      <h3>No decisions yet</h3>
      <p>Once you publish, hide, or dismiss a review from the queue it appears here.</p>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="mod-history-table">
    <thead>
      <tr>
        <th>Decision</th>
        <th>Review</th>
        <th>Source</th>
        <th>Flag reason</th>
        <th style="text-align:right">When</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($recent as $row):
      $src_meta = mod_source_meta($row['source'] ?? 'auto');
    ?>
      <tr>
        <td><span class="mod-status-chip <?php echo htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars(ucfirst($row['status'])); ?></span></td>
        <td>
          <div style="font-weight:700"><?php echo htmlspecialchars($row['company_name']); ?></div>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">#<?php echo (int) $row['rating_id']; ?> · <?php echo (int) $row['rating']; ?>★ · <?php echo htmlspecialchars($row['customer_name'] ?: 'Anonymous'); ?></div>
        </td>
        <td><span class="mod-badge is-<?php echo htmlspecialchars($src_meta['tone']); ?>" style="font-size:10.5px"><?php echo $src_meta['icon']; ?> <?php echo htmlspecialchars($src_meta['label']); ?></span></td>
        <td style="font-size:12.5px;color:var(--muted);max-width:300px"><?php echo htmlspecialchars(str_replace('_', ' ', $row['reason'])); ?></td>
        <td style="text-align:right;font-size:12.5px;color:var(--muted);white-space:nowrap"><?php echo htmlspecialchars(mod_time_ago($row['resolved_at'])); ?><br><span style="font-size:11px;opacity:.7"><?php echo htmlspecialchars((string) $row['resolved_at']); ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<script>
// Toggle long review comments
document.querySelectorAll('[data-toggle-comment]').forEach(function(btn){
  btn.addEventListener('click', function(){
    var el = this.parentElement.querySelector('.mod-review-comment');
    if (!el) return;
    var expanded = !el.classList.contains('is-clamped');
    el.classList.toggle('is-clamped', !expanded);
    this.textContent = expanded ? 'Read more' : 'Show less';
  });
});

// Bulk selection
var checks = document.querySelectorAll('.mod-bulk-check');
var bar = document.getElementById('bulkBar');
var cnt = document.getElementById('bulkCount');
var clearBtn = document.getElementById('bulkClear');
function refreshBulk(){
  if (!bar || !cnt) return;
  var n = document.querySelectorAll('.mod-bulk-check:checked').length;
  cnt.textContent = n;
  bar.classList.toggle('is-visible', n > 0);
}
checks.forEach(function(c){ c.addEventListener('change', refreshBulk); });
if (clearBtn) clearBtn.addEventListener('click', function(){
  checks.forEach(function(c){ c.checked = false; });
  refreshBulk();
});
var bulkForm = document.getElementById('bulkForm');
if (bulkForm){
  bulkForm.addEventListener('submit', function(e){
    var decision = document.getElementById('bulkDecision').value;
    var n = document.querySelectorAll('.mod-bulk-check:checked').length;
    if (!n){ e.preventDefault(); alert('Select at least one review.'); return; }
    if (!confirm('Apply "'+decision+'" to '+n+' review(s)?')){ e.preventDefault(); }
  });
}

// Auto-submit filter form when user presses Enter in search (normal submit already works); also keep tab on URL.
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
