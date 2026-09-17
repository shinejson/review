<?php
/**
 * ============================================================
 *  Admin — Workspace Notifications
 * ============================================================
 *  The inbox a workspace owner and their team see in the bell:
 *  reviews that just came in, low scores still unanswered, escalated
 *  complaints, questions on the Q&A board, invoices and the renewal
 *  date of the plan.
 *
 *  Everything here is scoped to the signed-in tenant — the same
 *  `notifications` table the control center uses, filtered by
 *  `audience = 'tenant' AND tenant_id = <this workspace>`, so one
 *  business can never read another's notices. Which *kinds* of notice
 *  appear is driven by the team member's module access: a staffer who
 *  cannot open Billing does not get billing notices in the inbox.
 *
 *  See includes/notifications.php for the writer, the scanner and the
 *  read-state helpers.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireLogin($conn);

$tenant_id = $tenant_id ?? (function_exists('getTenantId') ? getTenantId() : 0);
$is_tenant = $is_tenant ?? (function_exists('isTenant') ? isTenant() : false);
$tenant_id = (int) $tenant_id;

/* The workspace inbox is the tenant's own. A legacy platform admin who
   signs into this panel (no tenant of their own) reads the queue across
   workspaces — that is the only case where the tenant boundary is
   lifted, and it is the account that owns every workspace anyway. */
$audience  = 'tenant';
$scope_all = !$is_tenant;
$inbox_tenant = $is_tenant ? $tenant_id : 0;

function admin_notifications_url(array $overrides = [])
{
    $params = array_merge([
        'status' => $_GET['status'] ?? '',
        'type'   => $_GET['type'] ?? '',
        'q'      => $_GET['q'] ?? '',
    ], $overrides);
    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== null && $v !== 'all';
    });
    return 'notifications.php' . ($params ? '?' . http_build_query($params) : '');
}

/* ============================================================
   Click-through — mark read, then go to the module that handles it
   ============================================================ */

if (isset($_GET['open'])) {
    $open_id = (int) $_GET['open'];
    $row = notifications_find($conn, $open_id, $audience, $inbox_tenant, $scope_all);
    if ($row) {
        notifications_set_read($conn, $open_id, 1, $audience, $inbox_tenant, $scope_all);
        $to = notifications_link($conn, $row, $audience, $inbox_tenant, $scope_all);
        if ($to !== '') {
            redirect($to);
        }
    }
    redirect(admin_notifications_url([]));
}

/* ============================================================
   Actions
   ============================================================ */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('notifications.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $back = admin_notifications_url(['status' => (string) ($_POST['status'] ?? '')]);

    if ($action === 'read' || $action === 'unread') {
        $n = notifications_set_read($conn, $ids, $action === 'read', $audience, $inbox_tenant, $scope_all);
        sa_flash($n ? 'success' : 'error', $n
            ? ($action === 'read'
                ? 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' as read.'
                : 'Put ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' back on the unread pile.')
            : 'Those notifications are no longer in your inbox.');
    } elseif ($action === 'dismiss') {
        $n = notifications_remove($conn, $ids, $audience, $inbox_tenant, $scope_all);
        sa_flash($n ? 'success' : 'error', $n
            ? 'Dismissed ' . $n . ' notification' . ($n === 1 ? '' : 's') . '.'
            : 'Nothing to dismiss.');
    } elseif ($action === 'read_all') {
        $post_type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['type'] ?? '')));
        $n = notifications_mark_all_read($conn, $audience, $inbox_tenant, $scope_all, $post_type);
        sa_flash('success', $n
            ? 'All caught up — ' . $n . ' marked as read.'
            : 'You already had nothing unread.');
    } elseif ($action === 'purge') {
        $days = max(0, (int) ($_POST['days'] ?? 30));
        $n = notifications_purge_read($conn, $days, $audience, $inbox_tenant, $scope_all);
        sa_flash('success', $n
            ? 'Cleared ' . $n . ' read notification' . ($n === 1 ? '' : 's') . ' older than ' . $days . ' days.'
            : 'No read notifications older than ' . $days . ' days to clear.');
    }

    redirect($back);
}

/* ============================================================
   Filters
   ============================================================ */

$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'unread', 'read'], true)) {
    $status = 'all';
}
$type   = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['type'] ?? '')));
$search = trim((string) ($_GET['q'] ?? ''));
$page   = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;

/* Kinds this user may read, from their module access. */
$allowed_types = notifications_allowed_types($conn, $audience);
if ($type !== '' && !in_array($type, $allowed_types, true)) {
    $type = '';
}

/* Scan the workspace: first inbox view of the session, on demand from
   "Refresh", otherwise at most once a minute. */
$first_visit = !isset($_SESSION['notif_seen_inbox']);
$synced = notifications_sync($conn, $audience, $inbox_tenant, isset($_GET['refresh']) || $first_visit);
$_SESSION['notif_seen_inbox'] = 1;

$base_args = [
    'audience'  => $audience,
    'tenant_id' => $inbox_tenant,
    'scope_all' => $scope_all,
    'types'     => $allowed_types,
    'search'    => $search,
];
if ($type !== '') {
    $base_args['type'] = $type;
}
$list_args = array_merge($base_args, ['status' => $status]);

$total = notifications_count($conn, $list_args);
$total_pages = max(1, (int) ceil($total / $per_page));
$page = min($page, $total_pages);

$notifications = notifications_fetch($conn, array_merge($list_args, [
    'limit'  => $per_page,
    'offset' => ($page - 1) * $per_page,
]));

$unread = notifications_count($conn, array_merge($base_args, ['status' => 'unread']));
$today  = notifications_count($conn, array_merge($base_args, ['status' => 'unread', 'start' => date('Y-m-d')]));
$breakdown = notifications_breakdown($conn, $base_args);

$BASE      = '../';
$pageTitle = 'Notifications';
$activeNav = 'notifications';

include __DIR__ . '/_shell.php';
?>

<?php if ($flash = sa_take_flash()): ?>
<div class="alert alert-<?php echo ($flash['type'] ?? '') === 'error' ? 'error' : 'success'; ?>" role="status">
    <?php echo ($flash['type'] ?? '') === 'error' ? '⚠' : '✓'; ?> <?php echo htmlspecialchars($flash['message']); ?>
</div>
<?php endif; ?>

<div class="welcome-row">
    <div>
        <p class="eyebrow">Notifications</p>
        <h1 style="margin:0;"><?php echo $unread ? number_format($unread) . ' unread' : 'All caught up'; ?></h1>
        <p class="muted" style="margin-top:4px;">
            Reviews, questions and billing items that need someone to act — filed from what is
            actually happening in this workspace.
        </p>
    </div>
    <div style="display:flex;gap:9px;flex-wrap:wrap;">
        <a class="btn btn-secondary" style="text-decoration:none;display:inline-block;"
           href="<?php echo htmlspecialchars(admin_notifications_url(['refresh' => '1'])); ?>"
           title="Look at the workspace again right now">↻ Refresh</a>
        <?php if ($unread > 0): ?>
        <form method="post" action="notifications.php" style="display:inline;">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="read_all">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
            <button type="submit" class="btn btn-primary" style="border:none;cursor:pointer;">✓ Mark all read</button>
        </form>
        <?php endif; ?>
        <form method="post" action="notifications.php" style="display:inline;"
              onsubmit="return confirm('Delete read notifications older than 30 days?');">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="purge">
            <input type="hidden" name="days" value="30">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
            <button type="submit" class="btn btn-secondary" style="cursor:pointer;">🧹 Clean up</button>
        </form>
    </div>
</div>

<div class="metric-grid" style="margin-top:22px;">
    <article class="metric-card">
        <span>Unread</span>
        <strong><?php echo number_format($unread); ?></strong>
        <small><?php echo $unread ? 'Waiting for a reply or a decision' : 'Nothing pending right now'; ?></small>
        <div class="metric-icon lime">🔔</div>
    </article>
    <article class="metric-card">
        <span>New today</span>
        <strong><?php echo number_format($today); ?></strong>
        <small>Since midnight, unread</small>
        <div class="metric-icon amber">✦</div>
    </article>
    <article class="metric-card">
        <span>On record</span>
        <strong><?php echo number_format($total); ?></strong>
        <small>With the filters below applied</small>
        <div class="metric-icon blue">≡</div>
    </article>
    <article class="metric-card">
        <span>Last scan</span>
        <strong style="font-size:17px;"><?php echo $synced > 0 ? number_format($synced) . ' filed' : 'Just checked'; ?></strong>
        <small>
            <?php echo $synced > 0 ? 'New notices from the live workspace' : 'Auto-runs about once a minute'; ?>
        </small>
        <div class="metric-icon green">⟳</div>
    </article>
</div>

<div class="panel" style="margin-bottom:20px;">
    <form method="get" action="notifications.php" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div style="display:flex;flex-direction:column;gap:6px;">
            <label for="f_status" style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);">Read state</label>
            <select id="f_status" name="status" style="padding:8px 10px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;">
                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All notices</option>
                <option value="unread" <?php echo $status === 'unread' ? 'selected' : ''; ?>>Unread only</option>
                <option value="read" <?php echo $status === 'read' ? 'selected' : ''; ?>>Read only</option>
            </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;">
            <label for="f_type" style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);">Kind</label>
            <select id="f_type" name="type" style="padding:8px 10px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;">
                <option value="">Every kind</option>
                <?php foreach ($allowed_types as $type_key): ?>
                <?php $type_meta = notifications_type_meta($type_key); ?>
                <option value="<?php echo htmlspecialchars($type_key); ?>" <?php echo $type === $type_key ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($type_meta['label']); ?><?php echo isset($breakdown[$type_key]) ? ' (' . (int) $breakdown[$type_key]['total'] . ')' : ''; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;flex:1 1 220px;">
            <label for="f_q" style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);">Search</label>
            <input type="search" id="f_q" name="q" value="<?php echo htmlspecialchars($search); ?>"
                   placeholder="Title or detail…" autocomplete="off"
                   style="padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;">
        </div>
        <button type="submit" class="btn btn-primary" style="padding:9px 16px;font-size:13px;">Apply</button>
        <a class="btn btn-secondary" href="notifications.php" style="padding:9px 14px;font-size:12.5px;text-decoration:none;">Reset</a>
    </form>
</div>

<div class="data-table-card">
    <div class="notif-card">
        <div class="notif-tabs">
            <a class="notif-tab<?php echo $status === 'all' ? ' is-on' : ''; ?>"
               href="<?php echo htmlspecialchars(admin_notifications_url(['status' => '', 'page' => null])); ?>">All</a>
            <a class="notif-tab<?php echo $status === 'unread' ? ' is-on' : ''; ?>"
               href="<?php echo htmlspecialchars(admin_notifications_url(['status' => 'unread', 'page' => null])); ?>">
                <span aria-hidden="true" class="notif-tab-star">★</span>
                <?php echo 'Unread'; ?><?php echo $unread > 0 ? ' · ' . (int) $unread : ''; ?>
            </a>
            <a class="notif-tab<?php echo $status === 'read' ? ' is-on' : ''; ?>"
               href="<?php echo htmlspecialchars(admin_notifications_url(['status' => 'read', 'page' => null])); ?>">Read</a>
        </div>

        <?php if (empty($notifications)): ?>
        <div class="empty-state" style="padding:44px 20px;">
            <div style="font-size:26px;margin-bottom:8px;"><?php echo $status === 'unread' ? '✓' : '📭'; ?></div>
            <strong style="display:block;font-size:14px;color:var(--ink);margin-bottom:5px;">
                <?php echo $status === 'unread' ? 'Nothing unread' : 'No notifications found'; ?>
            </strong>
            <span>
                <?php if ($status === 'unread'): ?>
                You are caught up. New reviews, questions and invoices land here as they happen.
                <?php else: ?>
                Clear the filters, or press Refresh to look at the workspace again.
                <?php endif; ?>
            </span>
        </div>
        <?php else: ?>
        <ul class="notif-list">
            <?php foreach ($notifications as $n): ?>
            <?php
                $meta     = notifications_type_meta($n['type']);
                $tone     = notifications_tone_class($n['tone'] !== '' ? $n['tone'] : $meta['tone']);
                $is_read  = (int) $n['is_read'] === 1;
                $link     = notifications_link($conn, $n, $audience, $inbox_tenant, $scope_all);
                $open_url = 'notifications.php?open=' . (int) $n['id'];
            ?>
            <li class="notif-item<?php echo $is_read ? ' is-read' : ''; ?>">
                <span class="notif-icon is-<?php echo $tone; ?>" aria-hidden="true">
                    <?php echo notifications_icon($n['icon'] !== '' ? $n['icon'] : $meta['icon'], 17); ?>
                </span>
                <div class="notif-body">
                    <h3>
                        <?php if ($link !== ''): ?>
                        <a href="<?php echo htmlspecialchars($open_url); ?>"><?php echo htmlspecialchars($n['title']); ?></a>
                        <?php else: ?>
                        <?php echo htmlspecialchars($n['title']); ?>
                        <?php endif; ?>
                        <?php if (!$is_read): ?><span class="notif-new">New</span><?php endif; ?>
                    </h3>
                    <?php if (!empty($n['message'])): ?>
                    <p><?php echo htmlspecialchars($n['message']); ?></p>
                    <?php endif; ?>
                    <div class="notif-meta">
                        <span class="notif-chip"><?php echo htmlspecialchars($meta['label']); ?></span>
                        <?php if ($scope_all && !empty($n['tenant_id'])): ?>
                        <span>Workspace #<?php echo (int) $n['tenant_id']; ?></span>
                        <?php endif; ?>
                        <span title="<?php echo htmlspecialchars(date('D, M j Y, g:i a', strtotime((string) $n['created_at']))); ?>">
                            <?php echo htmlspecialchars(sa_time_ago($n['created_at'])); ?>
                        </span>
                        <?php if ($link !== ''): ?>
                        <a href="<?php echo htmlspecialchars($link); ?>" title="Open <?php echo htmlspecialchars($link); ?>">Go to it →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="notif-actions">
                    <?php if ($link !== ''): ?>
                    <a class="btn btn-primary" style="padding:6px 13px;font-size:12px;text-decoration:none;"
                       href="<?php echo htmlspecialchars($open_url); ?>">Open</a>
                    <?php endif; ?>
                    <form method="post" action="notifications.php">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="ids[]" value="<?php echo (int) $n['id']; ?>">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                        <button type="submit" name="action" value="<?php echo $is_read ? 'unread' : 'read'; ?>"
                                class="btn btn-secondary" style="padding:6px 11px;font-size:12px;"
                                title="<?php echo $is_read ? 'Put this back on the unread pile' : 'Mark this as read'; ?>">
                            <?php echo $is_read ? 'Mark as unread' : 'Mark as read'; ?>
                        </button>
                    </form>
                    <form method="post" action="notifications.php"
                          onsubmit="return confirm('Dismiss this notice for good? It will not come back.');">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="ids[]" value="<?php echo (int) $n['id']; ?>">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                        <button type="submit" name="action" value="dismiss" class="btn btn-danger"
                                style="padding:6px 11px;font-size:12px;border:1px solid var(--line);border-radius:8px;"
                                title="Dismiss — this notice will not be filed again">Dismiss</button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($total_pages > 1): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-top:1px solid var(--line);">
            <?php if ($page > 1): ?>
            <a class="btn btn-secondary" style="padding:6px 12px;font-size:12px;text-decoration:none;"
               href="<?php echo htmlspecialchars(admin_notifications_url(['page' => $page - 1])); ?>">← Previous</a>
            <?php else: ?><span></span><?php endif; ?>
            <span style="font-size:12px;color:var(--muted);">Page <?php echo (int) $page; ?> of <?php echo (int) $total_pages; ?></span>
            <?php if ($page < $total_pages): ?>
            <a class="btn btn-secondary" style="padding:6px 12px;font-size:12px;text-decoration:none;"
               href="<?php echo htmlspecialchars(admin_notifications_url(['page' => $page + 1])); ?>">Next →</a>
            <?php else: ?><span></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<p class="notif-note">
    💡 Notices are filed from live data: a review that gets a reply, a question that gets an answer or an
    invoice that is paid retires its own notice. Dismissing keeps a notice away for good, and
    “Clean up” deletes read notices older than 30 days. Team members only see the kinds of notice
    their module access covers.
</p>

<?php include __DIR__ . '/_shell_footer.php'; ?>
