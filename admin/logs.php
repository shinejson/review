<?php
/**
 * ============================================================
 *  Admin — Activity Log
 * ============================================================
 *  The workspace's own history: who signed in, what changed,
 *  when a backup was taken, and what the platform team did to
 *  this workspace (plan changes, support sessions, …).
 *
 *  Reads `system_logs` through includes/logging_helpers.php and is
 *  always filtered by the session tenant, so a workspace can never
 *  see another tenant's events.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';

requireLogin();

$tenant_id = $tenant_id ?? (function_exists('getTenantId') ? getTenantId() : 0);
$is_tenant = $is_tenant ?? (function_exists('isTenant') ? isTenant() : false);
$tenant_id = (int) $tenant_id;

requireTeamAccess('logs');

admin_ensure_schema($conn);
sa_logs_ensure_schema($conn);

$BASE      = '../';
$pageTitle = 'Activity Log';
$activeNav = 'logs';

/* ============================================================
   Filters
   ============================================================ */

$filter_source  = (string) ($_GET['source'] ?? 'all');   // all | workspace | platform
$filter_action  = (string) ($_GET['action'] ?? '');
$filter_user    = (string) ($_GET['user'] ?? '');
$filter_start   = (string) ($_GET['start'] ?? '');
$filter_end     = (string) ($_GET['end'] ?? '');
$filter_search  = trim((string) ($_GET['q'] ?? ''));
$filter_page    = max(1, (int) ($_GET['page'] ?? 1));
$per_page       = 40;

/* Which panel's events should be shown? */
$portal = null;                       // null → both portals
if ($filter_source === 'workspace') {
    $portal = 'admin';
} elseif ($filter_source === 'platform') {
    $portal = 'superadmin';
}

/* The tenant filter is applied here and only here — the single
   guarantee that a workspace sees nothing but its own history. */
$scope = [];
if ($is_tenant && $tenant_id > 0) {
    $scope['tenant_id'] = $tenant_id;
}

$filters = $scope;
if ($filter_action !== '') {
    $filters['action'] = $filter_action;
}
if ($filter_user !== '') {
    $filters['user_label'] = $filter_user;
}
if ($filter_start !== '') {
    $filters['start_date'] = $filter_start;
}
if ($filter_end !== '') {
    $filters['end_date'] = $filter_end;
}
if ($filter_search !== '') {
    $filters['search'] = $filter_search;
}

$total_logs  = sa_get_logs_count($conn, $portal, $filters);
$total_pages = (int) ceil($total_logs / $per_page);
$filter_page = min($filter_page, max(1, $total_pages));

$logs = sa_get_logs($conn, $portal, array_merge($filters, [
    'limit'  => $per_page,
    'offset' => ($filter_page - 1) * $per_page,
]));

$action_options = sa_get_log_actions($conn, $portal, $scope, 60);
$user_options   = sa_get_log_users($conn, $portal, $scope, 12);
$latest         = sa_get_log_latest($conn, $portal, $scope);
$last_7_days    = sa_get_logs_count($conn, $portal, array_merge($scope, [
    'start_date' => date('Y-m-d', strtotime('-6 days')),
]));
$top_action     = $action_options ? $action_options[0] : [];

/* CSV export — same filters as the screen. */
if (isset($_GET['export'])) {
    $filename = 'workspace_activity_' . date('Y-m-d_His') . '.csv';
    if (sa_export_logs_csv($conn, $portal, $filters, $filename)) {
        exit;
    }
    sa_flash('error', 'There is nothing to export with those filters.');
    redirect('logs.php');
}

function admin_query_url(array $overrides = [])
{
    $params = array_merge([
        'source' => $_GET['source'] ?? 'all',
        'action' => $_GET['action'] ?? '',
        'user'   => $_GET['user'] ?? '',
        'start'  => $_GET['start'] ?? '',
        'end'    => $_GET['end'] ?? '',
        'q'      => $_GET['q'] ?? '',
    ], $overrides);
    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== null && $v !== 'all';
    });
    return 'logs.php' . ($params ? '?' . http_build_query($params) : '');
}

include __DIR__ . '/_shell.php';
?>

<!-- Page Header -->
<div class="welcome-row" style="margin-bottom:22px;">
    <div>
        <p class="eyebrow">Workspace &middot; Activity</p>
        <h1 style="margin:0;">Activity Log</h1>
        <p class="muted" style="margin-top:6px;">
            Everything that happened in this workspace and everything the platform did to it — sign-ins, edits,
            backups and support actions.
        </p>
    </div>
    <a href="<?php echo htmlspecialchars(admin_query_url(['export' => '1'])); ?>" class="btn btn-secondary"
       style="display:inline-flex;align-items:center;gap:8px;padding:10px 16px;text-decoration:none;">
        ⤓ Export CSV
    </a>
</div>

<?php if ($flash = sa_take_flash()): ?>
<div class="alert alert-<?php echo ($flash['type'] ?? '') === 'error' ? 'error' : 'success'; ?>" role="alert">
    <?php echo ($flash['type'] ?? '') === 'error' ? '⚠' : '✓'; ?> <?php echo htmlspecialchars($flash['message']); ?>
</div>
<?php endif; ?>

<?php if (!$is_tenant || $tenant_id <= 0): ?>
<div class="form-card" style="padding:26px;">
    <h2 style="margin:0 0 10px;font-size:18px;">📜 Workspace activity</h2>
    <p class="muted" style="margin:0;line-height:1.6;">
        Activity is recorded per workspace, so this page works for a signed-in workspace owner (or a team member with
        the Activity Log module). Signed in as a platform administrator you would see the whole platform from
        <strong>Super Admin → Activity Logs</strong>.
    </p>
</div>
<?php else: ?>

<!-- Metric Cards -->
<div class="metric-grid" style="margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon lime">📜</div>
        <span>Events (filtered)</span>
        <strong><?php echo number_format($total_logs); ?></strong>
        <small>Matching the filters below</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon blue">🗓️</div>
        <span>Last 7 days</span>
        <strong><?php echo number_format($last_7_days); ?></strong>
        <small>Including today</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon purple">★</div>
        <span>Most common</span>
        <strong style="font-size:17px;"><?php echo $top_action ? htmlspecialchars(sa_log_label($top_action['action'])) : '—'; ?></strong>
        <small><?php echo $top_action ? (int) $top_action['count'] . ' events' : 'No activity yet'; ?></small>
    </div>
    <div class="metric-card">
        <div class="metric-icon amber">🕒</div>
        <span>Last event</span>
        <strong style="font-size:18px;"><?php echo $latest ? date('M j, Y', strtotime($latest['created_at'])) : 'No activity yet'; ?></strong>
        <small><?php echo $latest ? date('g:i a', strtotime($latest['created_at'])) . ' · ' . htmlspecialchars(sa_log_label($latest['action'])) : 'Nothing recorded so far'; ?></small>
    </div>
</div>

<!-- Filters -->
<div class="form-card" style="padding:18px 20px;margin-bottom:22px;">
    <form method="GET" action="logs.php" class="filter-form" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
        <div class="form-group" style="flex:1 1 150px;min-width:140px;">
            <label for="log_source">Source</label>
            <select id="log_source" name="source">
                <option value="all" <?php echo $filter_source === 'all' ? 'selected' : ''; ?>>Everything</option>
                <option value="workspace" <?php echo $filter_source === 'workspace' ? 'selected' : ''; ?>>Workspace actions</option>
                <option value="platform" <?php echo $filter_source === 'platform' ? 'selected' : ''; ?>>Platform actions</option>
            </select>
        </div>
        <div class="form-group" style="flex:1 1 170px;min-width:150px;">
            <label for="log_action">Action</label>
            <select id="log_action" name="action">
                <option value="">All actions</option>
                <?php foreach ($action_options as $option): ?>
                <option value="<?php echo htmlspecialchars($option['action']); ?>" <?php echo $filter_action === $option['action'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(sa_log_label($option['action'])); ?> (<?php echo (int) $option['count']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex:1 1 160px;min-width:140px;">
            <label for="log_user">Team member</label>
            <select id="log_user" name="user">
                <option value="">Everyone</option>
                <?php foreach ($user_options as $option): ?>
                <option value="<?php echo htmlspecialchars($option['user_label']); ?>" <?php echo $filter_user === $option['user_label'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($option['user_label']); ?> (<?php echo (int) $option['count']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex:0 1 150px;min-width:130px;">
            <label for="log_start">From</label>
            <input type="date" id="log_start" name="start" value="<?php echo htmlspecialchars($filter_start); ?>">
        </div>
        <div class="form-group" style="flex:0 1 150px;min-width:130px;">
            <label for="log_end">To</label>
            <input type="date" id="log_end" name="end" value="<?php echo htmlspecialchars($filter_end); ?>">
        </div>
        <div class="form-group" style="flex:2 1 220px;min-width:180px;">
            <label for="log_q">Search</label>
            <input type="search" id="log_q" name="q" value="<?php echo htmlspecialchars($filter_search); ?>"
                   placeholder="Action, description or person…">
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary" style="padding:9px 16px;">Apply</button>
            <a href="logs.php" class="btn btn-secondary" style="padding:9px 16px;text-decoration:none;">Reset</a>
        </div>
    </form>
</div>

<!-- Log table -->
<div class="data-table-card">
    <div style="padding:18px 20px 12px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div>
            <h3 style="margin:0;font-size:16px;">📋 Log entries</h3>
            <p class="muted" style="margin:4px 0 0;font-size:12.5px;">
                <?php echo number_format($total_logs); ?> record<?php echo $total_logs === 1 ? '' : 's'; ?> found
                <?php if ($filter_search !== '' || $filter_action !== '' || $filter_user !== '' || $filter_start !== '' || $filter_end !== ''): ?>
                · <a href="logs.php" style="color:#15803d;font-weight:700;">clear filters</a>
                <?php endif; ?>
            </p>
        </div>
        <span class="status-dot">● Tenant-scoped</span>
    </div>

    <?php if (!$logs): ?>
    <div style="padding:10px 20px 26px;">
        <p class="muted" style="font-size:13px;margin:0 0 6px;">
            Nothing matches yet. Activity starts recording from the moment this screen is installed: sign-ins,
            profile edits, team changes, plan requests, backups and support actions all land here.
        </p>
    </div>
    <?php else: ?>
    <div class="table-scroll-wrap">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr>
                    <th scope="col" style="text-align:left;">When</th>
                    <th scope="col" style="text-align:left;">Source</th>
                    <th scope="col" style="text-align:left;">Who</th>
                    <th scope="col" style="text-align:left;">Action</th>
                    <th scope="col" style="text-align:left;">Details</th>
                    <th scope="col" style="text-align:left;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="white-space:nowrap;">
                        <strong style="color:var(--ink);"><?php echo htmlspecialchars(date('M j, Y', strtotime($log['created_at']))); ?></strong><br>
                        <small class="muted"><?php echo htmlspecialchars(date('g:i:s a', strtotime($log['created_at']))); ?></small>
                    </td>
                    <td>
                        <?php if (($log['portal'] ?? 'admin') === 'superadmin'): ?>
                        <span class="status-badge-pending" style="font-size:11px;">Platform</span>
                        <?php else: ?>
                        <span class="status-badge-replied" style="font-size:11px;">Workspace</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($log['user_label'] ?: 'System'); ?></td>
                    <td style="white-space:nowrap;">
                        <strong style="color:var(--ink);font-size:12.5px;"><?php echo htmlspecialchars(sa_log_label($log['action'])); ?></strong><br>
                        <small class="muted" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;">
                            <?php echo htmlspecialchars($log['action']); ?>
                        </small>
                    </td>
                    <td style="color:#475569;max-width:320px;"><?php echo htmlspecialchars((string) ($log['description'] ?? '')); ?></td>
                    <td style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;color:#64748b;">
                        <?php echo htmlspecialchars((string) ($log['ip_address'] ?? '')); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:10px;">
        <span class="muted" style="font-size:12.5px;">Page <?php echo $filter_page; ?> of <?php echo $total_pages; ?></span>
        <div style="display:flex;gap:8px;">
            <?php if ($filter_page > 1): ?>
            <a class="btn btn-secondary" style="padding:7px 14px;text-decoration:none;"
               href="<?php echo htmlspecialchars(admin_query_url(['page' => $filter_page - 1])); ?>">← Newer</a>
            <?php endif; ?>
            <?php if ($filter_page < $total_pages): ?>
            <a class="btn btn-secondary" style="padding:7px 14px;text-decoration:none;"
               href="<?php echo htmlspecialchars(admin_query_url(['page' => $filter_page + 1])); ?>">Older →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<div class="form-card" style="padding:20px 22px;margin-top:22px;">
    <h3 style="margin:0 0 8px;font-size:15px;">What gets recorded</h3>
    <p class="muted" style="margin:0;font-size:12.5px;line-height:1.7;">
        Sign-ins and sign-outs · workspace profile and password changes · branch/profile edits · team member changes ·
        services catalogue · ratings replies, verification and escalation · questions &amp; FAQ changes ·
        plan change requests and auto-renew · social account connections and posts · backups created, downloaded and deleted ·
        plus any action the platform team takes on this workspace.
    </p>
</div>

<?php endif; ?>

<?php include __DIR__ . '/_shell_footer.php'; ?>
