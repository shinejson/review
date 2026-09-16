<?php
/**
 * ============================================================
 *  Admin — Backup Status
 * ============================================================
 *  View backup status for the workspace (read-only for tenants).
 *  Super admins can manage backups from the Super Admin panel.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';

requireLogin();

$pageTitle    = 'Backup Status';
$pageHeading  = 'Backup Status';
$pageSubtitle = 'View backup history for your workspace.';
$BASE         = '../';

$tenant_id = $tenant_id ?? (function_exists('getTenantId') ? getTenantId() : 0);
$is_tenant = $is_tenant ?? (function_exists('isTenant') ? isTenant() : false);

// For this implementation, we'll show a status page
// Actual backups are managed at the superadmin level

$backup_info = [
    'status' => 'info',
    'message' => 'Database backups are managed by the platform administrator.',
    'last_backup' => null,
    'backup_count' => 0,
];

// Try to get last backup info from logs
$recent_backups = sa_get_logs($conn, 'superadmin', [
    'action' => 'backup_create',
    'limit' => 1
]);

if (!empty($recent_backups)) {
    $backup_info['last_backup'] = $recent_backups[0]['created_at'];
    $backup_info['message'] = 'Last backup created: ' . date('Y-m-d H:i:s', strtotime($recent_backups[0]['created_at']));
}

// Get total backup count
$backup_info['backup_count'] = sa_get_logs_count($conn, 'superadmin', ['action' => 'backup_create']);

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-card">
    <div class="sa-card-head">
        <div><h3>Backup Status</h3><p>Workspace backup information</p></div>
    </div>
    
    <div class="sa-card-pad">
        <?php if ($is_tenant): ?>
        <div class="sa-info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>
                <strong>Platform-managed backups</strong><br>
                Database backups are created and managed by the platform administrator (Super Admin).
                You can view backup history below.
            </div>
        </div>
        <?php endif; ?>
        
        <div class="sa-grid sa-grid-3 sa-mb">
            <div class="sa-stat">
                <div class="sa-stat-num"><?php echo $backup_info['backup_count']; ?></div>
                <div class="sa-stat-label">Total Backups</div>
            </div>
            <div class="sa-stat">
                <div class="sa-stat-num"><?php echo $backup_info['last_backup'] ? 'Available' : 'N/A'; ?></div>
                <div class="sa-stat-label">Last Backup</div>
            </div>
            <div class="sa-stat">
                <div class="sa-stat-num">
                    <?php if ($backup_info['last_backup']): ?>
                        <?php echo date('Y-m-d H:i', strtotime($backup_info['last_backup'])); ?>
                    <?php else: ?>
                        --
                    <?php endif; ?>
                </div>
                <div class="sa-stat-label">Backup Date</div>
            </div>
        </div>
        
        <div class="sa-alert sa-alert-info">
            <?php echo sa_icon('info'); ?>
            <strong><?php echo sa_e($backup_info['message']); ?></strong>
        </div>
    </div>
</div>

<div class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>Recent Backup Activity</h3></div>
    </div>
    
    <div class="sa-card-pad">
        <?php
        // Show recent backup-related logs
        $backup_logs = sa_get_logs($conn, 'superadmin', [
            'action' => ['backup_create', 'backup_delete', 'backup_download'],
            'limit' => 10
        ]);
        
        if (empty($backup_logs)): ?>
        <div class="sa-empty">
            <?php echo sa_icon('archive'); ?>
            <strong>No backup activity recorded</strong>
            <p>Backups are created by the Super Admin.</p>
        </div>
        <?php else: ?>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead>
                    <tr><th>Timestamp</th><th>Action</th><th>Details</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($backup_logs as $log): ?>
                    <tr>
                        <td class="sa-mono"><?php echo sa_e(date('Y-m-d H:i:s', strtotime($log['created_at']))); ?></td>
                        <td><code><?php echo sa_e($log['action']); ?></code></td>
                        <td><?php echo sa_e($log['description'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>Instructions</h3></div>
    </div>
    <div class="sa-card-pad">
        <div class="sa-info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>
                <strong>How to request a backup:</strong><br>
                Contact your platform administrator to create a database backup. 
                Super Admins can access the backup management page at 
                <code class="sa-mono">/superadmin/backups.php</code>.
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>