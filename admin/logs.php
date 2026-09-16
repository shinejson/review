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

if (!function_exists('sa_e')) {
    function sa_e($val) {
        return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('sa_icon')) {
    function sa_icon($name, $attrs = '') {
        $icons = [
            'download'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'info'         => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
            'inbox'        => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'arrow-left'   => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'chevron-right'=> '<polyline points="9 18 15 12 9 6"/>',
        ];
        $body = $icons[$name] ?? $icons['info'];
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' . $attrs . '>' . $body . '</svg>';
    }
}

include __DIR__ . '/_shell.php';
?>

<style>
.sa-card { background: #fff; border: 1px solid var(--line, #e2e8f0); border-radius: 12px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.sa-card-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; border-bottom: 1px solid var(--line, #e2e8f0); }
.sa-card-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--ink, #0f172a); }
.sa-card-head p { margin: 4px 0 0; font-size: 13px; color: var(--muted, #64748b); }
.sa-card-pad { padding: 20px 24px; }
.sa-mt { margin-top: 20px; }
.sa-mb { margin-bottom: 16px; }
.sa-grid { display: grid; gap: 16px; }
.sa-grid-3 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
.sa-field-label { display: block; font-size: 12px; font-weight: 600; color: var(--muted, #64748b); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
.sa-input { width: 100%; padding: 9px 12px; border: 1px solid var(--line, #cbd5e1); border-radius: 8px; font-size: 14px; background: #fff; color: var(--ink, #0f172a); }
.sa-btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid transparent; transition: all 0.15s; }
.sa-btn-sm { padding: 6px 12px; font-size: 12px; }
.sa-btn-ghost { background: transparent; border-color: var(--line, #cbd5e1); color: var(--ink, #0f172a); }
.sa-btn-ghost:hover { background: var(--bg, #f8fafc); }
.sa-info { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px; color: #166534; }
.sa-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 8px; font-size: 13px; }
.sa-alert-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
.sa-table-wrap { overflow-x: auto; }
.sa-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px; }
.sa-table th { padding: 12px 16px; background: var(--bg, #f8fafc); font-weight: 600; color: var(--muted, #64748b); border-bottom: 1px solid var(--line, #e2e8f0); }
.sa-table td { padding: 12px 16px; border-bottom: 1px solid var(--line, #e2e8f0); color: var(--ink, #0f172a); }
.sa-mono { font-family: monospace; font-size: 12px; }
.sa-small { font-size: 11.5px; color: var(--muted, #64748b); }
.sa-empty { text-align: center; padding: 40px 20px; color: var(--muted, #64748b); }
.sa-empty svg { width: 36px; height: 36px; stroke: #94a3b8; margin-bottom: 10px; }
.sa-empty strong { display: block; font-size: 15px; color: var(--ink, #0f172a); margin-bottom: 4px; }
.sa-card-foot { padding: 14px 24px; border-top: 1px solid var(--line, #e2e8f0); }
.sa-pagination { display: flex; align-items: center; justify-content: space-between; }
</style>

<div class="sa-card">
    <div class="sa-card-head">
        <div><h3>Activity Logs</h3><p>Workspace activity history</p></div>
    </div>
    
    <div class="sa-card-pad">
        <?php if ($is_tenant): ?>
        <div class="sa-info">
            <?php echo sa_icon('info'); ?>
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
                <input type="hidden" name="action" value="<?php echo sa_e($filter_action); ?>">
                <input type="hidden" name="start" value="<?php echo sa_e($filter_start); ?>">
                <input type="hidden" name="end" value="<?php echo sa_e($filter_end); ?>">
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
                <a href="?page=<?php echo $filter_page + 1; ?>&action=<?php echo urlencode($filter_action); ?>&start=<?php echo urlencode($filter_start); ?>&end=<?php echo urlencode($filter_end); ?>" class="sa-btn sa-btn-sm sa-btn-ghost">Next <?php echo sa_icon('chevron-right'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>