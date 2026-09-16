<?php
/**
 * Super Admin — Activity Logs
 * View and export system activity logs.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';

requireSuperAdminLogin();
require_sa_permission('logs');

$robots       = 'noindex, nofollow';
$pageTitle    = 'Activity Logs';
$pageHeading  = 'Activity Logs';
$pageSubtitle = 'System activity from all user panels.';
$activePage   = 'logs';
$BASE         = '../';
$extraCss     = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';

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

            <div class="sa-grid sa-grid-3">
                <div>
                    <label class="sa-field-label">User</label>
                    <select name="user" class="sa-input" onchange="this.form.submit()">
                        <option value="">All Users</option>
                        <optgroup label="Super Admins">
                            <?php foreach ($users_superadmin as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filter_user == $u['id'] ? 'selected' : ''; ?>><?php echo sa_e($u['username']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Admins">
                            <?php foreach ($users_admin as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $filter_user == $u['id'] ? 'selected' : ''; ?>><?php echo sa_e($u['username']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div style="grid-column: span 2;">
                    <label class="sa-field-label">Search</label>
                    <div class="sa-search sa-search-sm">
                        <?php echo sa_icon('search'); ?>
                        <input type="search" name="search" class="sa-input" placeholder="Search actions or descriptions..." value="<?php echo sa_e($filter_search); ?>" data-sa-search="logs">
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>Log Entries</h3><p><?php echo $total_logs; ?> record<?php echo $total_logs !== 1 ? 's' : ''; ?> found</p></div>
    </div>
    
    <?php if (empty($logs)): ?>
    <div class="sa-empty">
        <?php echo sa_icon('inbox'); ?>
        <strong>No logs found</strong>
        <p>Try adjusting your filters.</p>
    </div>
    <?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr><th>Timestamp</th><th>Portal</th><th>User</th><th>Action</th><th>Description</th><th>IP Address</th></tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <?php $user_label = $log['user_label'] ?? 'System'; if (!$user_label || $user_label === '0') $user_label = 'System'; ?>
                <tr>
                    <td class="sa-mono"><?php echo sa_e(date('Y-m-d H:i:s', strtotime($log['created_at']))); ?></td>
                    <td><?php if ($log['portal'] === 'superadmin'): ?><span class="sa-badge sa-badge-info">Super Admin</span><?php else: ?><span class="sa-badge sa-badge-warning">Admin</span><?php endif; ?></td>
                    <td><?php echo sa_e($user_label); ?></td>
                    <td><code><?php echo sa_e($log['action']); ?></code></td>
                    <td><?php echo sa_e($log['description'] ?? ''); ?></td>
                    <td class="sa-mono sa-small"><?php echo sa_e($log['ip_address'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($total_pages > 1): ?>
    <div class="sa-card-foot">
        <div class="sa-pagination">
            <?php if ($filter_page > 1): ?>
                <a href="?page=<?php echo $filter_page - 1; ?>&portal=<?php echo urlencode($filter_portal); ?>&action=<?php echo urlencode($filter_action); ?>&user=<?php echo urlencode($filter_user); ?>&start=<?php echo urlencode($filter_start); ?>&end=<?php echo urlencode($filter_end); ?>" class="sa-btn sa-btn-sm sa-btn-ghost"><?php echo sa_icon('arrow-left'); ?> Previous</a>
            <?php endif; ?>
            <span class="sa-muted">Page <?php echo $filter_page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($filter_page < $total_pages): ?>
                <a href="?page=<?php echo $filter_page + 1; ?>&portal=<?php echo urlencode($filter_portal); ?>&action=<?php echo urlencode($filter_action); ?>&user=<?php echo urlencode($filter_user); ?>&start=<?php echo urlencode($filter_start); ?>&end=<?php echo urlencode($filter_end); ?>" class="sa-btn sa-btn-sm sa-btn-ghost">Next <?php echo sa_icon('chevron-right'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>
