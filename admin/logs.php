<?php
/**
 * ============================================================
 *  Admin — Activity Logs
 * ============================================================
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';

requireLogin();

$pageTitle    = 'Activity Logs';
$pageHeading  = 'Activity Logs';
$pageSubtitle = 'Workspace activity history.';
$BASE         = '../';

$tenant_id = $tenant_id ?? (function_exists('getTenantId') ? getTenantId() : 0);
$is_tenant = $is_tenant ?? (function_exists('isTenant') ? isTenant() : false);

$filter_action = $_GET['action'] ?? '';
$filter_start  = $_GET['start']  ?? '';
$filter_end    = $_GET['end']    ?? '';
$filter_page   = max(1, (int) ($_GET['page'] ?? 1));
$per_page      = 50;

$filters = [];
if ($is_tenant && $tenant_id) {
    $filters['entity_type'] = 'tenant';
    $filters['entity_id'] = $tenant_id;
}
<div class="sa-card">
    <div class="sa-card-head">
        <div><h3>Activity Logs</h3><p>Workspace activity history</p></div>
    </div>
    
    <div class="sa-card-pad">
        <?php if ($is_tenant): ?>
        <div class="sa-info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div><strong>Workspace:</strong> Tenants view their own activity</div>
        </div>
        <?php endif; ?>
        
        <?php if (!$is_tenant): ?>
        <div class="sa-alert sa-alert-info">
            <?php echo sa_icon('info'); ?>
            This view shows admin panel activity. For full platform logs, use the Super Admin panel.
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>Log Entries</h3><p><?php echo $total_logs; ?> record<?php echo $total_logs !== 1 ? 's' : ''; ?> found</p></div>
        <div class="sa-topbar-actions">
            <form method="GET" style="display:inline">
                <input type="hidden" name="export" value="1">
                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost"><?php echo sa_icon('download'); ?> Export CSV</button>
            </form>
        </div>
    </div>
    
    <div class="sa-card-pad">
        <form method="GET" class="sa-form sa-mb">
            <div class="sa-grid sa-grid-3 sa-mb">
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
        </form>
    </div>
</div>

<div class="sa-card sa-mt">
    <?php if (empty($logs)): ?>
    <div class="sa-empty">
        <?php echo sa_icon('inbox'); ?>
        <strong>No activity logs found</strong>
        <p>No activity has been recorded yet.</p>
    </div>
    <?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr><th>Timestamp</th><th>Action</th><th>Description</th><th>IP Address</th></tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="sa-mono"><?php echo sa_e(date('Y-m-d H:i:s', strtotime($log['created_at']))); ?></td>
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
                <a href="?page=<?php echo $filter_page - 1; ?>&action=<?php echo urlencode($filter_action); ?>&start=<?php echo urlencode($filter_start); ?>&end=<?php echo urlencode($filter_end); ?>" class="sa-btn sa-btn-sm sa-btn-ghost"><?php echo sa_icon('arrow-left'); ?> Previous</a>
            <?php endif; ?>
            <span class="sa-muted">Page <?php echo $filter_page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($filter_page < $total_pages): ?>
                <a href="?page=<?php echo $filter_page + 1; ?>&action=<?php echo urlencode($filter_action); ?>&start=<?php echo urlencode($filter_start); ?>&end=<?php echo urlencode($filter_end); ?>" class="sa-btn sa-btn-sm sa-btn-ghost">Next <?php echo sa_icon('arrow-left'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>
if ($filter_action) $filters['action'] = $filter_action;
if ($filter_start) $filters['start_date'] = $filter_start;
if ($filter_end) $filters['end_date'] = $filter_end;

$logs = sa_get_logs($conn, 'admin', array_merge($filters, ['limit' => $per_page, 'offset' => ($filter_page - 1) * $per_page]));
$total_logs = sa_get_logs_count($conn, 'admin', $filters);
$total_pages = ceil($total_logs / $per_page);
$actions = sa_get_log_actions($conn, 'admin');

if (isset($_GET['export'])) {
    if (function_exists('sa_export_logs_csv')) {
        $filename = 'workspace_logs_' . date('Y-m-d_His') . '.csv';
        sa_export_logs_csv($conn, 'admin', $filters, $filename);
        exit;
    }
}

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>