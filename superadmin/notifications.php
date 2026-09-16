<?php
/**
 * ============================================================
 *  Super Admin — Notifications
 * ============================================================
 *  The control center's own inbox: quote requests, money waiting to
 *  be confirmed, subscriptions about to lapse, reviews flagged by a
 *  customer, workspaces that never finished setup.
 *
 *  It reads the same table as the topbar bell (includes/notifications.php),
 *  so the badge and this list can never disagree: opening a notice from
 *  the bell marks it read and hands the browser to the screen that
 *  handles it.
 *
 *  A super admin whose role is limited to part of the platform only sees
 *  the kinds of notice that part covers — notifications_type_catalog()
 *  pairs every type with the permission key that gates it.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireSuperAdminLogin($conn);

/* The control center reads the platform queue: never scoped to one
   workspace, except when the list is deliberately drilled down. */
$audience  = 'platform';
$scope_all = true;

/* ============================================================
   URL helper — every filter survives paging, sorting and actions
   ============================================================ */

function sa_notifications_url(array $overrides = [])
{
    $params = array_merge([
        'status' => $_GET['status'] ?? '',
        'type'   => $_GET['type'] ?? '',
        'tenant' => $_GET['tenant'] ?? '',
        'q'      => $_GET['q'] ?? '',
        'start'  => $_GET['start'] ?? '',
        'end'    => $_GET['end'] ?? '',
        'sort'   => $_GET['sort'] ?? '',
    ], $overrides);
    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== null && $v !== 'all' && $v !== '0';
    });
    return 'notifications.php' . ($params ? '?' . http_build_query($params) : '');
}

/* ============================================================
   Click-through — mark read, then hand the browser to the module
   ============================================================ */

if (isset($_GET['open'])) {
    $open_id = (int) $_GET['open'];
    $row = notifications_find($conn, $open_id, $audience, 0, $scope_all);
    if ($row) {
        notifications_set_read($conn, $open_id, 1, $audience, 0, $scope_all);
        $to = notifications_link($conn, $row, $audience, 0, $scope_all);
        if ($to !== '') {
            redirect($to);
        }
        sa_flash('info', 'This notice has no screen of its own — the queue behind it is listed above.');
    }
    redirect(sa_notifications_url([]));
}

/* ============================================================
   Actions
   ============================================================ */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('notifications.php');
    }

    $action   = (string) ($_POST['action'] ?? '');
    $ids      = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $back     = sa_notifications_url(['status' => (string) ($_POST['status'] ?? '')]);
    $post_type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['type'] ?? '')));

    if ($action === 'read' || $action === 'unread') {
        $n = notifications_set_read($conn, $ids, $action === 'read', $audience, 0, $scope_all);
        sa_flash($n ? 'success' : 'warning', $n
            ? ($action === 'read'
                ? 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' as read.'
                : 'Moved ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' back to unread.')
            : 'Those notifications are no longer in your inbox.');
    } elseif ($action === 'dismiss') {
        $n = notifications_remove($conn, $ids, $audience, 0, $scope_all);
        sa_flash($n ? 'success' : 'warning', $n
            ? 'Dismissed ' . $n . ' notification' . ($n === 1 ? '' : 's') . '. A dismissed notice is not filed again.'
            : 'Nothing to dismiss.');
    } elseif ($action === 'read_all') {
        $n = notifications_mark_all_read($conn, $audience, 0, $scope_all, $post_type);
        sa_flash('success', $n
            ? 'Caught up — ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' marked as read.'
            : 'You already had nothing unread.');
    } elseif ($action === 'purge') {
        $days = max(0, (int) ($_POST['days'] ?? 30));
        $n = notifications_purge_read($conn, $days, $audience, 0, $scope_all);
        sa_flash('success', $n
            ? 'Deleted ' . $n . ' read notification' . ($n === 1 ? '' : 's') . ' older than ' . $days . ' days.'
            : 'No read notifications older than ' . $days . ' days to delete.');
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
$type    = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['type'] ?? '')));
$search  = trim((string) ($_GET['q'] ?? ''));
$sort    = ($_GET['sort'] ?? '') === 'oldest' ? 'oldest' : 'recent';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 25;
$filter_tenant = (int) ($_GET['tenant'] ?? 0);

/* Only the kinds this role may read. */
$allowed_types = notifications_allowed_types($conn, $audience);
if ($type !== '' && !in_array($type, $allowed_types, true)) {
    $type = '';
}

/* Scan the platform: on the first inbox view of a session, on demand
   from "Refresh", and at most once a minute otherwise. */
$first_visit = !isset($_SESSION['notif_seen_inbox']);
$synced = notifications_sync($conn, $audience, 0, isset($_GET['refresh']) || $first_visit);
$_SESSION['notif_seen_inbox'] = 1;

$base_args = [
    'audience'  => $audience,
    'scope_all' => $scope_all,
    'types'     => $allowed_types,
    'search'    => $search,
    'start'     => (string) ($_GET['start'] ?? ''),
    'end'       => (string) ($_GET['end'] ?? ''),
];
if ($type !== '') {
    $base_args['type'] = $type;
}
if ($filter_tenant > 0) {
    $base_args['tenant_id'] = $filter_tenant;
}
$list_args = array_merge($base_args, ['status' => $status]);

$total = notifications_count($conn, $list_args);
$total_pages = max(1, (int) ceil($total / $per_page));
$page = min($page, $total_pages);

$notifications = notifications_fetch($conn, array_merge($list_args, [
    'limit'  => $per_page,
    'offset' => ($page - 1) * $per_page,
    'order'  => $sort,
]));

$unread = notifications_count($conn, array_merge($base_args, ['status' => 'unread']));
$today  = notifications_count($conn, array_merge($base_args, ['status' => 'unread', 'start' => date('Y-m-d')]));
$week   = notifications_count($conn, array_merge($base_args, ['start' => date('Y-m-d', strtotime('-6 days'))]));
$oldest = notifications_fetch($conn, array_merge($base_args, [
    'status' => 'unread', 'order' => 'oldest', 'limit' => 1,
]));
$breakdown = notifications_breakdown($conn, $base_args);

/* Workspace names for the rows on this page, in one query. */
$tenant_names = notifications_tenant_names($conn, $notifications);

$pageTitle    = 'Notifications';
$pageHeading  = 'Notifications';
$pageSubtitle = 'Everything the platform is waiting on, in one queue.';
$activePage   = 'notifications';
$BASE         = '../';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-grid sa-kpis sa-anim">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Unread</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('bell'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_num($unread); ?></div>
        <div class="sa-kpi-foot"><?php echo $unread ? 'Waiting on you' : 'Your inbox is clear' ?></div>
    </article>
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">New today</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('zap'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_num($today); ?></div>
        <div class="sa-kpi-foot">Unread since midnight</div>
    </article>
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Last 7 days</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('calendar'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_num($week); ?></div>
        <div class="sa-kpi-foot">Filed and read alike</div>
    </article>
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-violet);--kpi-soft:var(--sa-violet-soft);--kpi-line:var(--sa-violet-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Oldest unread</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('clock'); ?></span>
        </div>
        <div class="sa-kpi-value" style="font-size:17px;">
            <?php echo !empty($oldest) ? sa_e(sa_time_ago($oldest[0]['created_at'])) : 'Nothing waiting'; ?>
        </div>
        <div class="sa-kpi-foot">
            <?php echo !empty($oldest) ? sa_e(notifications_type_meta($oldest[0]['type'])['label']) : 'Nothing unread right now'; ?>
        </div>
    </article>
</div>

<form method="get" action="notifications.php" class="sa-toolbar sa-card sa-mt">
    <div class="sa-field">
        <label for="f_status">Read state</label>
        <select id="f_status" name="status">
            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All notices</option>
            <option value="unread" <?php echo $status === 'unread' ? 'selected' : ''; ?>>Unread only</option>
            <option value="read" <?php echo $status === 'read' ? 'selected' : ''; ?>>Read only</option>
        </select>
    </div>
    <div class="sa-field">
        <label for="f_type">Kind</label>
        <select id="f_type" name="type">
            <option value="">Every kind</option>
            <?php foreach ($allowed_types as $type_key): ?>
            <?php $type_meta = notifications_type_meta($type_key); ?>
            <option value="<?php echo sa_e($type_key); ?>" <?php echo $type === $type_key ? 'selected' : ''; ?>>
                <?php echo sa_e($type_meta['label']); ?><?php echo isset($breakdown[$type_key]) ? ' (' . (int) $breakdown[$type_key]['total'] . ')' : ''; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="sa-field">
        <label for="f_start">From</label>
        <input type="date" id="f_start" name="start" value="<?php echo sa_e((string) ($_GET['start'] ?? '')); ?>">
    </div>
    <div class="sa-field">
        <label for="f_end">To</label>
        <input type="date" id="f_end" name="end" value="<?php echo sa_e((string) ($_GET['end'] ?? '')); ?>">
    </div>
    <div class="sa-field" style="flex:1 1 220px;">
        <label for="f_q">Search</label>
        <input type="search" id="f_q" name="q" placeholder="Title or detail…"
               value="<?php echo sa_e($search); ?>" autocomplete="off">
    </div>
    <button type="submit" class="sa-btn sa-btn-sm sa-btn-primary"><?php echo sa_icon('filter'); ?> Apply</button>
    <a class="sa-btn sa-btn-sm sa-btn-ghost" href="notifications.php">Reset</a>
</form>

<div class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <h2>Inbox</h2>
            <p>
                <?php echo sa_num($total); ?> notification<?php echo $total === 1 ? '' : 's'; ?>
                <?php echo $status === 'unread' ? 'unread' : ($status === 'read' ? 'read' : 'on record'); ?>
                <?php if ($synced > 0): ?>
                · <?php echo (int) $synced; ?> filed just now
                <?php endif; ?>
            </p>
        </div>
        <div class="sa-card-head-actions">
            <a class="sa-btn sa-btn-sm sa-btn-ghost"
               href="<?php echo sa_e(sa_notifications_url(['refresh' => '1'])); ?>"
               title="Scan the platform again right now">
                <?php echo sa_icon('refresh'); ?> Refresh
            </a>
            <?php if ($unread > 0): ?>
            <form method="post" action="notifications.php">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="read_all">
                <input type="hidden" name="type" value="<?php echo sa_e($type); ?>">
                <input type="hidden" name="status" value="<?php echo sa_e($status); ?>">
                <button type="submit" class="sa-btn sa-btn-sm sa-btn-outline">
                    <?php echo sa_icon('check'); ?> Mark all read
                </button>
            </form>
            <?php endif; ?>
            <form method="post" action="notifications.php"
                  data-sa-confirm="Delete read notifications older than 30 days? Dismissed notices go too.">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="purge">
                <input type="hidden" name="days" value="30">
                <input type="hidden" name="status" value="<?php echo sa_e($status); ?>">
                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost">
                    <?php echo sa_icon('trash'); ?> Clean up
                </button>
            </form>
        </div>
    </div>

    <div class="sa-inbox-tabs">
        <a class="sa-pill<?php echo $status === 'all' ? ' is-on' : ''; ?>"
           href="<?php echo sa_e(sa_notifications_url(['status' => '', 'page' => ''])); ?>">All</a>
        <a class="sa-pill<?php echo $status === 'unread' ? ' is-on' : ''; ?>"
           href="<?php echo sa_e(sa_notifications_url(['status' => 'unread', 'page' => ''])); ?>">Unread · <?php echo (int) $unread; ?></a>
        <a class="sa-pill<?php echo $status === 'read' ? ' is-on' : ''; ?>"
           href="<?php echo sa_e(sa_notifications_url(['status' => 'read', 'page' => ''])); ?>">Read</a>
        <a class="sa-pill<?php echo $sort === 'oldest' ? ' is-on' : ''; ?>"
           href="<?php echo sa_e(sa_notifications_url(['sort' => $sort === 'oldest' ? '' : 'oldest', 'page' => ''])); ?>"
           title="Show the longest-waiting notices first">
            <?php echo $sort === 'oldest' ? 'Oldest first' : 'Newest first'; ?>
        </a>
        <?php if ($filter_tenant > 0): ?>
        <a class="sa-pill is-on" href="<?php echo sa_e(sa_notifications_url(['tenant' => '', 'page' => ''])); ?>"
           title="Stop showing only this workspace">
            <?php echo sa_icon('x'); ?>
            <?php echo sa_e($tenant_names[$filter_tenant] ?? ('Workspace #' . $filter_tenant)); ?>
        </a>
        <?php endif; ?>
    </div>

    <?php if (empty($notifications)): ?>
    <div class="sa-empty">
        <?php echo sa_icon($status === 'unread' ? 'check-circle' : 'inbox'); ?>
        <strong><?php echo $status === 'unread' ? 'Nothing unread' : 'No notifications found'; ?></strong>
        <p>
            <?php if ($status === 'unread'): ?>
            You are caught up. New quote requests, payments and flagged reviews land here as they happen.
            <?php else: ?>
            Try a wider date range, clear the filters, or press Refresh to scan the platform again.
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <ul class="sa-inbox">
        <?php foreach ($notifications as $n): ?>
        <?php
            $meta      = notifications_type_meta($n['type']);
            $tone      = notifications_tone_class($n['tone'] !== '' ? $n['tone'] : $meta['tone']);
            $is_read   = (int) $n['is_read'] === 1;
            $link      = notifications_link($conn, $n, $audience, 0, $scope_all);
            $open_url  = 'notifications.php?open=' . (int) $n['id'];
            $row_tenant = (int) $n['tenant_id'];
            $workspace = isset($tenant_names[$row_tenant]) ? (string) $tenant_names[$row_tenant] : '';
        ?>
        <li class="sa-inbox-row<?php echo $is_read ? ' is-read' : ''; ?>">
            <span class="sa-list-icon is-<?php echo $tone; ?>" aria-hidden="true">
                <?php echo sa_icon($n['icon'] !== '' ? $n['icon'] : $meta['icon']); ?>
            </span>

            <div class="sa-inbox-main">
                <h3>
                    <?php if ($link !== ''): ?>
                    <a href="<?php echo sa_e($open_url); ?>"><?php echo sa_e($n['title']); ?></a>
                    <?php else: ?>
                    <?php echo sa_e($n['title']); ?>
                    <?php endif; ?>
                    <?php if (!$is_read): ?><span class="sa-inbox-flag">New</span><?php endif; ?>
                </h3>
                <?php if (!empty($n['message'])): ?>
                <p><?php echo sa_e($n['message']); ?></p>
                <?php endif; ?>
                <div class="sa-inbox-meta">
                    <span class="sa-pill"><?php echo sa_e($meta['label']); ?></span>
                    <?php if ($workspace !== ''): ?>
                    <a class="sa-inbox-ws" href="<?php echo sa_e(sa_notifications_url(['tenant' => $row_tenant, 'page' => ''])); ?>"
                       title="Show only notices about this workspace">
                        <?php echo sa_icon('building'); ?> <?php echo sa_e($workspace); ?>
                    </a>
                    <?php endif; ?>
                    <span title="<?php echo sa_e(date('Y-m-d H:i', strtotime((string) $n['created_at']))); ?>">
                        <?php echo sa_e(sa_time_ago($n['created_at'])); ?>
                    </span>
                </div>
            </div>

            <div class="sa-inbox-actions">
                <?php if ($link !== ''): ?>
                <a class="sa-btn sa-btn-sm sa-btn-primary" href="<?php echo sa_e($open_url); ?>">
                    <?php echo sa_icon('chevron-right'); ?> Open
                </a>
                <?php endif; ?>
                <form method="post" action="notifications.php">
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="ids[]" value="<?php echo (int) $n['id']; ?>">
                    <input type="hidden" name="status" value="<?php echo sa_e($status); ?>">
                    <button type="submit" name="action" value="<?php echo $is_read ? 'unread' : 'read'; ?>"
                            class="sa-btn sa-btn-sm sa-btn-ghost"
                            title="<?php echo $is_read ? 'Put this back on the unread pile' : 'Mark this as read'; ?>">
                        <?php echo sa_icon($is_read ? 'bell' : 'check'); ?>
                        <span><?php echo $is_read ? 'Unread' : 'Read'; ?></span>
                    </button>
                </form>
                <form method="post" action="notifications.php" data-sa-confirm="Dismiss this notice for good?">
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="ids[]" value="<?php echo (int) $n['id']; ?>">
                    <input type="hidden" name="status" value="<?php echo sa_e($status); ?>">
                    <button type="submit" name="action" value="dismiss" class="sa-btn sa-btn-sm sa-btn-ghost"
                            title="Dismiss — this notice will not be filed again">
                        <?php echo sa_icon('x'); ?><span>Dismiss</span>
                    </button>
                </form>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($total_pages > 1): ?>
    <div class="sa-card-foot">
        <div class="sa-flex sa-flex-between" style="width:100%;">
            <?php if ($page > 1): ?>
            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="<?php echo sa_e(sa_notifications_url(['page' => $page - 1])); ?>">
                <?php echo sa_icon('chevron-left'); ?> Previous
            </a>
            <?php else: ?><span></span><?php endif; ?>
            <span class="sa-muted">Page <?php echo (int) $page; ?> of <?php echo (int) $total_pages; ?></span>
            <?php if ($page < $total_pages): ?>
            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="<?php echo sa_e(sa_notifications_url(['page' => $page + 1])); ?>">
                Next <?php echo sa_icon('chevron-right'); ?>
            </a>
            <?php else: ?><span></span><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<p class="sa-inbox-note">
    <?php echo sa_icon('info'); ?>
    <span>
        Notices are filed from the live platform — reviews, invoices, quote requests, subscription
        dates — and retire themselves once the thing they asked for is done. Refresh scans again right
        now; otherwise this inbox is re-scanned about once a minute. Dismissed notices stay dismissed.
    </span>
</p>

<?php include __DIR__ . '/_shell_footer.php'; ?>
