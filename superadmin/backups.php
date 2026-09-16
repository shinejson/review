<?php
/**
 * ============================================================
 *  Super Admin — Database Backups
 * ============================================================
 *  Create, download, and manage database backups.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';

requireSuperAdminLogin();
require_sa_permission('backups');

$pageTitle    = 'Backups';
$pageHeading  = 'Database Backups';
$pageSubtitle = 'Create and manage database backups for the platform.';
$activePage   = 'backups';
$BASE         = '../';

$backup_dir = __DIR__ . '/../backups';
if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0755, true);
}
function get_backup_list($backup_dir) {
    $backups = [];
    if (!is_dir($backup_dir)) return $backups;
    
    $files = glob($backup_dir . '/*.sql.gz');
    if (empty($files)) $files = glob($backup_dir . '/*.sql');
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $backups[] = [
                'filename' => basename($file),
                'filepath' => $file,
                'size' => filesize($file),
                'modified' => filemtime($file),
                'type' => pathinfo($file, PATHINFO_EXTENSION),
            ];
        }
    }
    
    usort($backups, function($a, $b) {
        return $b['modified'] <=> $a['modified'];
    });
    
    return $backups;
}

function format_bytes($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function get_database_config() {
    $config_file = dirname(__DIR__) . '/config/database.php';
    if (file_exists($config_file)) {
        ob_start();
        include $config_file;
        ob_end_clean();
        if (isset($host, $dbname, $username)) {
            return ['host' => $host ?? 'localhost', 'dbname' => $dbname ?? '', 'username' => $username ?? '', 'password' => $password ?? ''];
        }
    }
    return ['host' => 'localhost', 'dbname' => '', 'username' => '', 'password' => ''];
}

function create_backup_php($config, $filepath) {
    $conn = $GLOBALS['conn'];
    if (!$conn) return ['success' => false, 'message' => 'Database connection not available.'];
    
function create_backup($backup_dir) {
    $config = get_database_config();
    if (empty($config['dbname'])) return ['success' => false, 'message' => 'Database configuration not found.'];
    
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "backup_{$timestamp}.sql.gz";
    $filepath = $backup_dir . '/' . $filename;
    
    if (function_exists('exec')) {
        $host = escapeshellarg($config['host']);
        $user = escapeshellarg($config['username']);
        $pass = escapeshellarg($config['password']);
        $db = escapeshellarg($config['dbname']);
        $file = escapeshellarg($filepath);
        
        $command = "mysqldump --host={$host} --user={$user} --password={$pass} {$db} 2>&1 | gzip > {$file}";
        exec($command, $output, $return_var);
        
        if ($return_var === 0 && file_exists($filepath) && filesize($filepath) > 0) {
            sa_log_activity($GLOBALS['conn'], 'superadmin', $_SESSION['super_admin_id'] ?? null, 'backup_create', "Created: {$filename}");
            return ['success' => true, 'message' => "Backup created: {$filename}"];
        }
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        $message = 'Session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create_backup') {
            $result = create_backup($backup_dir);
            $message = $result['message'];
        } elseif ($action === 'delete_backup') {
            $filename = sanitize($_POST['filename'] ?? '');
            $filepath = $backup_dir . '/' . $filename;
            if (delete_backup($filepath)) {
                $message = "Deleted: {$filename}";
if (isset($_GET['download'])) {
    $filename = sanitize($_GET['download']);
    $filepath = $backup_dir . '/' . $filename;
    
    if (file_exists($filepath)) {
        sa_log_activity($GLOBALS['conn'], 'superadmin', $_SESSION['super_admin_id'] ?? null, 'backup_download', "Downloaded: {$filename}");
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
$backups = get_backup_list($backup_dir);
$config = get_database_config();
$has_mysqldump = function_exists('exec');

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3>Backup Management</h3>
            <p>Create SQL dumps of the entire database for disaster recovery.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_backup">
            <?php echo sa_csrf_field(); ?>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('plus'); ?> Create Backup</button>
        </form>
    </div>
    
    <?php if ($message): ?>
    <div class="sa-alert sa-alert-success"><?php echo sa_icon('check-circle'); ?><?php echo sa_e($message); ?></div>
    <?php endif; ?>
    
    <div class="sa-card-pad">
        <div class="sa-grid sa-grid-3 sa-mb">
            <div class="sa-stat">
                <div class="sa-stat-num"><?php echo count($backups); ?></div>
                <div class="sa-stat-label">Total Backups</div>
            </div>
            <div class="sa-stat">
                <div class="sa-stat-num"><?php echo format_bytes(array_sum(array_column($backups, 'size'))); ?></div>
                <div class="sa-stat-label">Total Size</div>
            </div>
            <div class="sa-stat">
                <div class="sa-stat-num"><?php echo $has_mysqldump ? 'mysqldump' : 'PHP'; ?></div>
                <div class="sa-stat-label">Method</div>
            </div>
        </div>
        
        <div class="sa-info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <div>
                <strong>Location:</strong> <?php echo sa_e($backup_dir); ?><br>
                <strong>Database:</strong> <?php echo sa_e($config['dbname'] ?? 'Unknown'); ?>
            </div>
        </div>
    </div>
</div>

<div class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>Backup List</h3><p>All database backups stored on the server</p></div>
    </div>
    
    <?php if (empty($backups)): ?>
    <div class="sa-empty">
        <?php echo sa_icon('archive'); ?>
        <strong>No backups yet</strong>
        <p>Click "Create Backup" to create your first database backup.</p>
    </div>
    <?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr><th>Filename</th><th>Size</th><th>Created</th><th>Type</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><strong><?php echo sa_e($backup['filename']); ?></strong></td>
                    <td><?php echo format_bytes($backup['size']); ?></td>
                    <td><?php echo date('Y-m-d H:i', $backup['modified']); ?></td>
                    <td>
                        <?php if ($backup['type'] === 'gz'): ?>
                            <span class="sa-badge sa-badge-success">Compressed</span>
                        <?php else: ?>
                            <span class="sa-badge sa-badge-info">SQL</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="sa-table-actions">
                            <a href="?download=<?php echo urlencode($backup['filename']); ?>" class="sa-btn sa-btn-sm sa-btn-ghost">
                                <?php echo sa_icon('download'); ?> Download
                            </a>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this backup?');">
                                <input type="hidden" name="action" value="delete_backup">
                                <input type="hidden" name="filename" value="<?php echo sa_e($backup['filename']); ?>">
                                <?php echo sa_csrf_field(); ?>
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost is-danger">
                                    <?php echo sa_icon('trash'); ?> Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>
    }
}
            } else {
                $message = "Failed to delete: {$filename}";
            }
        }
    }
}
    }
    
    return create_backup_php($config, $filepath);
}

function delete_backup($filepath) {
    if (file_exists($filepath) && unlink($filepath)) {
        sa_log_activity($GLOBALS['conn'], 'superadmin', $_SESSION['super_admin_id'] ?? null, 'backup_delete', "Deleted: " . basename($filepath));
        return true;
    }
    return false;
}
    $tables = $conn->query("SHOW TABLES");
    if (!$tables) return ['success' => false, 'message' => 'Failed to get table list.'];
    
    $sql = "-- Backup: " . date('Y-m-d H:i:s') . "\n-- DB: " . $config['dbname'] . "\n\n";
    
    while ($row = $tables->fetch_row()) {
        $table = $row[0];
        $create = $conn->query("SHOW CREATE TABLE `{$table}`");
        if ($create && $create->num_rows > 0) $sql .= $create->fetch_row()[1] . ";\n\n";
        
        $data = $conn->query("SELECT * FROM `{$table}`");
        if ($data) {
            while ($r = $data->fetch_assoc()) {
                $vals = [];
                foreach ($r as $v) $vals[] = "'" . addslashes($v) . "'";
                $sql .= "INSERT INTO `{$table}` VALUES (" . implode(',', $vals) . ");\n";
            }
        }
        $sql .= "\n";
    }
    
    $sql = gzencode($sql, 9);
    if (file_put_contents($filepath, $sql) !== false) return ['success' => true, 'message' => basename($filepath)];
    return ['success' => false, 'message' => 'Failed to write backup file.'];
}