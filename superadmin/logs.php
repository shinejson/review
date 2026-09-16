<?php
/**
<<<<<<< HEAD
 * Super Admin — Activity Logs
 * View and export system activity logs.
=======
 * ============================================================
 *  Super Admin — Activity Logs
 * ============================================================
 *  Every recorded event in the platform, from both panels:
 *
 *    · portal = 'admin'       → actions inside a workspace
 *    · portal = 'superadmin'  → platform-owner actions
 *
 *  Rows carry a tenant_id, so the list can be narrowed to one
 *  workspace — the same rows that workspace sees in
 *  admin/logs.php, next to everything else.
>>>>>>> 96a2caf8ff34731b0474c93ff850dddf4ce8d8c0
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';

requireSuperAdminLogin();
require_sa_permission('logs');

<<<<<<< HEAD
$robots       = 'noindex, nofollow';
=======
sa_logs_ensure_schema($conn);

>>>>>>> 96a2caf8ff34731b0474c93ff850dddf4ce8d8c0
$pageTitle    = 'Activity Logs';
$pageHeading  = 'Activity Logs';
$pageSubtitle = 'Every workspace and platform action, in one list.';
$activePage   = 'logs';
$BASE         = '../';
$extraCss     = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';

<<<<<<< HEAD
$filter_portal = $_GET['portal'] ?? 'all';
$filter_action = $_GET['action'] ?? '';
$filter_user   = $_GET['user'] ?? '';
$filter_start  = $_GET['start'] ?? '';
$filter_end    = $_GET['end'] ?? '';
$filter_search = $_GET['search'] ?? '';
$filter_page   = max(1, (int) ($_GET['page'] ?? 1));
$per_page      = 50;

$filters = [];
if ($filter_portal && $filter_portal !== 'all') $filters['portal'] = $filter_portal;
if ($filter_action) $filters['action'] = $filter_action;
if ($filter_user) $filters['user_id'] = (int) $filter_user;
if ($filter_start) $filters['start_date'] = $filter_start;
if ($filter_end) $filters['end_date'] = $filter_end;
if ($filter_search) $filters['search'] = $filter_search;

$logs = sa_get_logs($conn, $filter_portal, array_merge($filters, ['limit' => $per_page, 'offset' => ($filter_page - 1) * $per_page]));
$total_logs = sa_get_logs_count($conn, $filter_portal, $filters);
$total_pages = ceil($total_logs / $per_page);
$actions = sa_get_log_actions($conn, null);
$users_superadmin = sa_query($conn, "SELECT id, username FROM super_admins ORDER BY username", 'super_admins');
$users_admin = sa_query($conn, "SELECT id, username FROM admins ORDER BY username", 'admins');

if (isset($_GET['export'])) {
    if (function_exists('sa_export_logs_csv')) {
        $filename = 'activity_logs_' . date('Y-m-d_His') . '.csv';
        sa_export_logs_csv($conn, $filter_portal, $filters, $filename);
        exit;
    }
}

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-card">
    <div class="sa-card-head">
        <div><h3>Activity Logs</h3><p>Track all actions across the platform</p></div>
        <div class="sa-topbar-actions">
            <form method="GET" style="display:inline">
                <input type="hidden" name="export" value="1">
                <input type="hidden" name="portal" value="<?php echo sa_e($filter_portal); ?>">
                <input type="hidden" name="action" value="<?php echo sa_e($filter_action); ?>">
                <input type="hidden" name="user" value="<?php echo sa_e($filter_user); ?>">
                <input type="hidden" name="start" value="<?php echo sa_e($filter_start); ?>">
                <input type="hidden" name="end" value="<?php echo sa_e($filter_end); ?>">
                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost">
                    <?php echo sa_icon('download'); ?> Export CSV
                </button>
            </form>
        </div>
    </div>
    
    <div class="sa-card-pad">
        <form method="GET" class="sa-form sa-mb">
            <div class="sa-grid sa-grid-4 sa-mb">
                <div>
                    <label class="sa-field-label">Portal</label>
                    <select name="portal" class="sa-input" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_portal === 'all' ? 'selected' : ''; ?>>All Panels</option>
                        <option value="superadmin" <?php echo $filter_portal === 'superadmin' ? 'selected' : ''; ?>>Super Admin</option>
                        <option value="admin" <?php echo $filter_portal === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>
                <div>
                    <label class="sa-field-label">Action Type</label>
                    <select name="action" class="sa-input" onchange="this.form.submit()">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $act): ?>
                        <option value="<?php echo sa_e($act['action']); ?>" <?php echo $filter_action === $act['action'] ? 'selected' : ''; ?>>
                            <?php echo sa_e($act['action']); ?> (<?php echo $act['count']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="sa-field-label">Start Date</label>
                    <input type="date" name="start" class="sa-input" value="<?php echo sa_e($filter_start); ?>" onchange="this.form.submit()">
                </div>
                <div>
                    <label class="sa-field-label">End Date</label>
                    <input type="date" name="end" class="sa-input" value="<?php echo sa_e($filter_end); ?>" onchange="this.form.submit()">
                </div>
            </div>
=======
/* ============================================================
   Filters
   ============================================================ */

$filter_portal = (string) ($_GET['portal'] ?? 'all');   // all | superadmin | admin
$filter_action = (string) ($_GET['action'] ?? '');
$filter_tenant = (int) ($_GET['tenant'] ?? 0);
$filter_user   = (string) ($_GET['user'] ?? '');
$filter_start  = (string) ($_GET['start'] ?? '');
$filter_end    = (string) ($_GET['end'] ?? '');
$filter_search = trim((string) ($_GET['search'] ?? ''));
$filter_page   = max(1, (int) ($_GET['page'] ?? 1));
$per_page      = 50;

$portal = ($filter_portal !== 'all') ? $filter_portal : null;

$filters = [];
if ($filter_tenant > 0) {
    $filters['tenant_id'] = $filter_tenant;
}
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
>>>>>>> 96a2caf8ff34731b0474c93ff850dddf4ce8d8c0

/* Reference data for the filter bar. */
$tenants = sa_query($conn, 'SELECT id, company_name FROM tenants ORDER BY company_name ASC', 'tenants');

$total_logs  = sa_get_logs_count($conn, $portal, $filters);
$total_pages = max(1, (int) ceil($total_logs / $per_page));
$filter_page = min($filter_page, $total_pages);

$logs = sa_get_logs($conn, $portal, array_merge($filters, [
    'limit'  => $per_page,
    'offset' => ($filter_page - 1) * $per_page,
]));

/* Dropdowns: scoped to the workspace/date range, so the action list
   shows what actually happened rather than every key in the schema. */
$scope = $filters;
unset($scope['action'], $scope['user_label'], $scope['search']);
$actions = sa_get_log_actions($conn, $portal, $scope, 60);
$users   = sa_get_log_users($conn, $portal, $scope, 30);

$stats_scope = $filters;
unset($stats_scope['start_date'], $stats_scope['end_date']);
$last_7 = sa_get_logs_count($conn, $portal, array_merge($stats_scope, [
    'start_date' => date('Y-m-d', strtotime('-6 days')),
]));
$latest = sa_get_log_latest($conn, $portal, $stats_scope);

/* CSV export uses exactly the filters on screen. */
if (isset($_GET['export'])) {
    $filename = 'activity_logs_' . date('Y-m-d_His') . '.csv';
    if (sa_export_logs_csv($conn, $portal, $filters, $filename)) {
        exit;
    }
    sa_flash('error', 'There is nothing to export with those filters.');
    redirect('logs.php');
}

function sa_logs_url(array $overrides = [])
{
    $params = array_merge([
        'portal' => $_GET['portal'] ?? 'all',
        'tenant' => $_GET['tenant'] ?? '',
        'action' => $_GET['action'] ?? '',
        'user'   => $_GET['user'] ?? '',
        'start'  => $_GET['start'] ?? '',
        'end'    => $_GET['end'] ?? '',
        'search' => $_GET['search'] ?? '',
    ], $overrides);
    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== null && $v !== 'all' && $v !== '0';
    });
    return 'logs.php' . ($params ? '?' . http_build_query($params) : '');
}

function sa_logs_tenant_name($tenants, $tenant_id)
{
    foreach ($tenants as $t) {
        if ((int) $t['id'] === (int) $tenant_id) {
            return $t['company_name'];
        }
    }
    return '';
}

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-grid sa-kpis sa-anim">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Events (filtered)</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('activity'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo number_format($total_logs); ?></div>
        <div class="sa-kpi-foot">Matching the filters below</div>
    </article>
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Last 7 days</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('clock'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo number_format($last_7); ?></div>
        <div class="sa-kpi-foot"><?php echo $filter_tenant > 0 ? 'In this workspace' : 'Across the platform'; ?></div>
    </article>
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-violet);--kpi-soft:var(--sa-violet-soft);--kpi-line:var(--sa-violet-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Workspaces</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('building'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo number_format(count($tenants)); ?></div>
        <div class="sa-kpi-foot">Each keeps its own log</div>
    </article>
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Last event</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('bell'); ?></span>
        </div>
        <div class="sa-kpi-value" style="font-size:17px;">
            <?php echo $latest ? sa_e(date('M j, g:i a', strtotime($latest['created_at']))) : 'No activity yet'; ?>
        </div>
        <div class="sa-kpi-foot"><?php echo $latest ? sa_e(sa_log_label($latest['action'])) : 'Nothing recorded so far'; ?></div>
    </article>
</div>

<form method="GET" action="logs.php" class="sa-toolbar sa-mt">
    <div class="sa-field">
        <label for="f_portal">Portal</label>
        <select id="f_portal" name="portal">
            <option value="all" <?php echo $filter_portal === 'all' ? 'selected' : ''; ?>>All panels</option>
            <option value="superadmin" <?php echo $filter_portal === 'superadmin' ? 'selected' : ''; ?>>Super Admin</option>
            <option value="admin" <?php echo $filter_portal === 'admin' ? 'selected' : ''; ?>>Workspace admin</option>
        </select>
    </div>
    <div class="sa-field">
        <label for="f_tenant">Workspace</label>
        <select id="f_tenant" name="tenant">
            <option value="">All workspaces</option>
            <?php foreach ($tenants as $t): ?>
            <option value="<?php echo (int) $t['id']; ?>" <?php echo $filter_tenant === (int) $t['id'] ? 'selected' : ''; ?>>
                <?php echo sa_e($t['company_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="sa-field">
        <label for="f_action">Action</label>
        <select id="f_action" name="action">
            <option value="">All actions</option>
            <?php foreach ($actions as $act): ?>
            <option value="<?php echo sa_e($act['action']); ?>" <?php echo $filter_action === $act['action'] ? 'selected' : ''; ?>>
                <?php echo sa_e(sa_log_label($act['action'])); ?> (<?php echo (int) $act['count']; ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="sa-field">
        <label for="f_user">User</label>
        <select id="f_user" name="user">
            <option value="">Everyone</option>
            <?php foreach ($users as $u): ?>
            <option value="<?php echo sa_e($u['user_label']); ?>" <?php echo $filter_user === $u['user_label'] ? 'selected' : ''; ?>>
                <?php echo sa_e($u['user_label']); ?> (<?php echo (int) $u['count']; ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="sa-field">
        <label for="f_start">From</label>
        <input type="date" id="f_start" name="start" value="<?php echo sa_e($filter_start); ?>">
    </div>
    <div class="sa-field">
        <label for="f_end">To</label>
        <input type="date" id="f_end" name="end" value="<?php echo sa_e($filter_end); ?>">
    </div>
    <div class="sa-field" style="flex:1 1 220px;">
        <label for="f_search">Search</label>
        <input type="search" id="f_search" name="search" placeholder="Action, description or user…"
               value="<?php echo sa_e($filter_search); ?>">
    </div>
    <button type="submit" class="sa-btn sa-btn-sm sa-btn-primary"><?php echo sa_icon('filter'); ?> Apply</button>
    <a class="sa-btn sa-btn-sm sa-btn-ghost" href="logs.php">Reset</a>
    <a class="sa-btn sa-btn-sm sa-btn-ghost" href="<?php echo sa_e(sa_logs_url(['export' => '1'])); ?>">
        <?php echo sa_icon('download'); ?> Export CSV
    </a>
</form>

<div class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <h3>Log entries</h3>
            <p>
                <?php echo number_format($total_logs); ?> record<?php echo $total_logs === 1 ? '' : 's'; ?> found
                <?php if ($filter_tenant > 0): ?>
                — <?php echo sa_e(sa_logs_tenant_name($tenants, $filter_tenant)); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($filter_tenant > 0): ?>
        <div class="sa-card-head-actions">
            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenants.php?id=<?php echo (int) $filter_tenant; ?>">
                <?php echo sa_icon('building'); ?> Open workspace
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($logs)): ?>
    <div class="sa-empty">
        <?php echo sa_icon('inbox'); ?>
        <strong>No logs found</strong>
        <p>Try a wider date range, or clear the filters.</p>
    </div>
    <?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th scope="col">Timestamp</th>
                    <th scope="col">Portal</th>
                    <th scope="col">Workspace</th>
                    <th scope="col">User</th>
                    <th scope="col">Action</th>
                    <th scope="col">Description</th>
                    <th scope="col">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <?php
                    $user_label  = (string) ($log['user_label'] ?? '');
                    if ($user_label === '' || $user_label === '0') {
                        $user_label = 'System';
                    }
                    $row_tenant  = isset($log['tenant_id']) ? (int) $log['tenant_id'] : 0;
                    $tenant_name = $row_tenant > 0 ? sa_logs_tenant_name($tenants, $row_tenant) : '';
                ?>
                <tr>
                    <td class="sa-mono" style="white-space:nowrap;font-size:12px;">
                        <?php echo sa_e(date('Y-m-d H:i:s', strtotime($log['created_at']))); ?>
                    </td>
                    <td>
                        <?php if (($log['portal'] ?? '') === 'superadmin'): ?>
                        <span class="sa-badge sa-badge-info">Super Admin</span>
                        <?php else: ?>
                        <span class="sa-badge sa-badge-lime">Workspace</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row_tenant > 0): ?>
                        <a href="logs.php?tenant=<?php echo $row_tenant; ?>" title="Show only this workspace">
                            <?php echo sa_e($tenant_name !== '' ? $tenant_name : 'Tenant #' . $row_tenant); ?>
                        </a>
                        <?php else: ?>
                        <span class="sa-faint">Platform-wide</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo sa_e($user_label); ?></td>
                    <td>
                        <?php echo sa_e(sa_log_label($log['action'])); ?><br>
                        <code class="sa-mono sa-faint" style="font-size:11.5px;"><?php echo sa_e($log['action']); ?></code>
                    </td>
                    <td><?php echo sa_e($log['description'] ?? ''); ?></td>
                    <td class="sa-mono sa-faint" style="font-size:12px;"><?php echo sa_e($log['ip_address'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="sa-card-foot">
        <div class="sa-flex sa-flex-between" style="width:100%;">
            <?php if ($filter_page > 1): ?>
            <a href="<?php echo sa_e(sa_logs_url(['page' => $filter_page - 1])); ?>" class="sa-btn sa-btn-sm sa-btn-ghost">
                <?php echo sa_icon('arrow-left'); ?> Newer
            </a>
            <?php else: ?><span></span><?php endif; ?>
            <span class="sa-muted">Page <?php echo $filter_page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($filter_page < $total_pages): ?>
<<<<<<< HEAD
                <a href="?page=<?php echo $filter_page + 1; ?>&portal=<?php echo urlencode($filter_portal); ?>&action=<?php echo urlencode($filter_action); ?>&user=<?php echo urlencode($filter_user); ?>&start=<?php echo urlencode($filter_start); ?>&end=<?php echo urlencode($filter_end); ?>" class="sa-btn sa-btn-sm sa-btn-ghost">Next <?php echo sa_icon('chevron-right'); ?></a>
            <?php endif; ?>
=======
            <a href="<?php echo sa_e(sa_logs_url(['page' => $filter_page + 1])); ?>" class="sa-btn sa-btn-sm sa-btn-ghost">
                Older <?php echo sa_icon('arrow-left'); ?>
            </a>
            <?php else: ?><span></span><?php endif; ?>
>>>>>>> 96a2caf8ff34731b0474c93ff850dddf4ce8d8c0
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<div class="sa-alert sa-alert-info sa-mt">
    <?php echo sa_icon('info'); ?>
    <span>
        A workspace reads its own rows in <code class="sa-mono">admin/logs.php</code> — its own actions plus anything
        the platform did to it. This page shows those rows next to every other workspace's.
    </span>
</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>
