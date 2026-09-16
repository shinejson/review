<?php
/**
 * ============================================================
 *  Super Admin — Database Backups
 * ============================================================
 *  Create, download and manage whole-database backups.
 *
 *  A workspace-level equivalent for tenants lives in
 *  admin/backups.php (it exports one tenant's rows instead of
 *  dumping the entire platform).
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
$pageSubtitle = 'Create and manage whole-platform database backups.';
$activePage   = 'backups';
$BASE         = '../';

/* ============================================================
   Helpers
   ============================================================ */

if (!function_exists('sa_backup_dir')) {
    /** Where the platform keeps its dumps. */
    function sa_backup_dir()
    {
        $dir = dirname(__DIR__) . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('sa_backup_list')) {
    /** Every *.sql / *.sql.gz dump in the platform backup folder. */
    function sa_backup_list($dir)
    {
        $out   = [];
        $files = glob($dir . '/*.sql.gz');
        if (empty($files)) {
            $files = glob($dir . '/*.sql');
        }
        foreach ((array) $files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $out[] = [
                'filename' => basename($file),
                'path'     => $file,
                'size'     => (int) filesize($file),
                'modified' => (int) filemtime($file),
                'type'     => strtolower((string) pathinfo($file, PATHINFO_EXTENSION)),
            ];
        }
        usort($out, function ($a, $b) {
            return $b['modified'] <=> $a['modified'];
        });
        return $out;
    }
}

if (!function_exists('sa_backup_bytes')) {
    /** 1.42 GB / 812.0 MB / 96 B — also used by the workspace screen. */
    function sa_backup_bytes($bytes)
    {
        $bytes = (float) $bytes;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return (int) $bytes . ' B';
    }
}

if (!function_exists('sa_backup_safe_name')) {
    /** A filename may only ever be a plain dump name inside the folder. */
    function sa_backup_safe_name($name)
    {
        $name = basename(trim((string) $name));
        return preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/', $name) ? $name : '';
    }
}

if (!function_exists('sa_backup_path')) {
    /** Absolute path for a dump, or '' when it is not inside the folder. */
    function sa_backup_path($name)
    {
        $name = sa_backup_safe_name($name);
        if ($name === '') {
            return '';
        }
        $dir  = realpath(sa_backup_dir());
        $path = realpath(sa_backup_dir() . '/' . $name);
        if ($dir === false || $path === false || strpos($path, $dir) !== 0) {
            return '';
        }
        return $path;
    }
}

if (!function_exists('sa_backup_php_dump')) {
    /**
     * Fallback dump for hosts without mysqldump: walk every table and
     * write CREATE + INSERT statements.
     */
    function sa_backup_php_dump($conn, $dbname)
    {
        $sql = "-- Optibiz platform backup\n-- Database: {$dbname}\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n"
             . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        $tables = @$conn->query('SHOW TABLES');
        if (!$tables) {
            return '';
        }
        while ($row = $tables->fetch_row()) {
            $table = $row[0];

            $create = @$conn->query('SHOW CREATE TABLE `' . $table . '`');
            if ($create && $create->num_rows > 0) {
                $cols = $create->fetch_row();
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . $cols[1] . ";\n\n";
                $create->free();
            }

            $data = @$conn->query('SELECT * FROM `' . $table . '`');
            if ($data) {
                while ($r = $data->fetch_assoc()) {
                    $values = [];
                    foreach ($r as $value) {
                        $values[] = $value === null ? 'NULL' : "'" . $conn->real_escape_string((string) $value) . "'";
                    }
                    $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
                }
                $data->free();
            }
            $sql .= "\n";
        }

        return $sql . "SET FOREIGN_KEY_CHECKS=1;\n";
    }
}

if (!function_exists('sa_backup_config')) {
    /** Connection details straight from config/database.php. */
    function sa_backup_config()
    {
        $file = dirname(__DIR__) . '/config/database.php';
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $name = defined('DB_NAME') ? DB_NAME : '';
        $user = defined('DB_USER') ? DB_USER : '';
        $pass = defined('DB_PASS') ? DB_PASS : '';
        if ($name === '' && file_exists($file)) {
            ob_start();
            include $file;
            ob_end_clean();
            $host = $host ?? 'localhost';
            $name = $name ?? ($dbname ?? '');
            $user = $user ?? ($username ?? '');
            $pass = $pass ?? ($password ?? '');
        }
        return ['host' => (string) $host, 'name' => (string) $name, 'user' => (string) $user, 'pass' => (string) $pass];
    }
}


// Backwards-compatibility aliases for legacy helper names
if (!function_exists('format_bytes')) {
    function format_bytes($bytes) {
        return sa_backup_bytes($bytes);
    }
}
if (!function_exists('get_backup_list')) {
    function get_backup_list($backup_dir) {
        return sa_backup_list($backup_dir);
    }
}
if (!function_exists('create_backup')) {
    function create_backup($backup_dir) {
        return sa_backup_php_dump();
    }
}

/* ============================================================
   Actions
   ============================================================ */

$message = '';
$message_tone = 'success';

if (isset($_GET['download'])) {
    $name = sa_backup_safe_name($_GET['download']);
    $path = $name !== '' ? sa_backup_path($name) : '';

    if ($path === '' || !is_file($path)) {
        sa_flash('error', 'That backup file is not on the server anymore.');
        redirect('backups.php');
    }

    sa_log_write(
        $conn,
        'superadmin',
        'backup_download',
        'Downloaded platform backup ' . $name,
        'backup',
        null,
        null,
        (int) ($_SESSION['super_admin_id'] ?? 0) ?: null,
        (string) ($_SESSION['super_admin_username'] ?? 'Super Admin')
    );

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('backups.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $actor  = (string) ($_SESSION['super_admin_username'] ?? 'Super Admin');
    $actor_id = (int) ($_SESSION['super_admin_id'] ?? 0) ?: null;
    $dir    = sa_backup_dir();

    if ($action === 'create_backup') {
        $config  = sa_backup_config();
        $stamp   = date('Y-m-d_H-i-s');
        $gzip    = function_exists('gzencode');
        $name    = 'backup_' . $stamp . ($gzip ? '.sql.gz' : '.sql');
        $path    = $dir . '/' . $name;
        $written = false;

        if ($config['name'] !== '' && function_exists('exec')) {
            $cmd = 'mysqldump'
                 . ' --host=' . escapeshellarg($config['host'])
                 . ' --user=' . escapeshellarg($config['user'])
                 . ($config['pass'] !== '' ? ' --password=' . escapeshellarg($config['pass']) : '')
                 . ' --single-transaction --routines --skip-lock-tables'
                 . ' ' . escapeshellarg($config['name'])
                 . ' 2>&1';
            $target = $gzip ? $path : str_replace('.gz', '', $path);
            $cmd .= $gzip ? ' | gzip > ' . escapeshellarg($target) : ' > ' . escapeshellarg($target);

            $output = [];
            $status = 1;
            @exec($cmd, $output, $status);
            $written = ($status === 0 && is_file($target) && filesize($target) > 0);
            if ($written) {
                $name = basename($target);
                $path = $target;
            }
        }

        if (!$written) {
            $dump = sa_backup_php_dump($conn, $config['name'] !== '' ? $config['name'] : 'database');
            if ($dump === '') {
                $message = 'The database could not be read for a backup.';
                $message_tone = 'error';
            } else {
                $gzip = function_exists('gzencode');
                $name = 'backup_' . $stamp . ($gzip ? '.sql.gz' : '.sql');
                $path = $dir . '/' . $name;
                $bytes = $gzip ? gzencode($dump, 6) : $dump;
                $written = @file_put_contents($path, $bytes) !== false;
                if (!$written) {
                    $message = 'The backup file could not be written to ' . $dir . '.';
                    $message_tone = 'error';
                }
            }
        }

        if ($written) {
            sa_log_write(
                $conn,
                'superadmin',
                'backup_create',
                'Created platform backup ' . $name . ' (' . sa_backup_bytes(filesize($path)) . ')',
                'backup',
                null,
                null,
                $actor_id,
                $actor
            );
            $message = 'Backup created: ' . $name . ' (' . sa_backup_bytes(filesize($path)) . ')';
        }
    } elseif ($action === 'delete_backup') {
        $name = sa_backup_safe_name($_POST['filename'] ?? '');
        $path = $name !== '' ? sa_backup_path($name) : '';
        if ($path !== '' && is_file($path) && @unlink($path)) {
            sa_log_write(
                $conn,
                'superadmin',
                'backup_delete',
                'Deleted platform backup ' . $name,
                'backup',
                null,
                null,
                $actor_id,
                $actor
            );
            $message = 'Deleted: ' . $name;
        } else {
            $message = 'That backup could not be deleted.';
            $message_tone = 'error';
        }
    }
}

/* ============================================================
   Data
   ============================================================ */

$backup_dir  = sa_backup_dir();
$backups     = sa_backup_list($backup_dir);
$config      = sa_backup_config();
$total_size  = 0;
$latest      = '';
foreach ($backups as $b) {
    $total_size += $b['size'];
    if ($latest === '' || $b['modified'] > strtotime($latest)) {
        $latest = date('Y-m-d H:i:s', $b['modified']);
    }
}
$can_dump    = $config['name'] !== '';
$workspace_count = sa_scalar($conn, 'SELECT COUNT(*) AS c FROM tenants', 0, 'tenants');

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3>Backup management</h3>
            <p>Whole-database dumps for disaster recovery. Workspace-level exports live in the tenant portal.</p>
        </div>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="create_backup">
            <?php echo sa_csrf_field(); ?>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('plus'); ?> Create backup</button>
        </form>
    </div>

    <?php if ($message !== ''): ?>
    <div class="sa-card-pad" style="padding-bottom:0;">
        <div class="sa-alert sa-alert-<?php echo $message_tone === 'error' ? 'danger' : 'success'; ?>">
            <?php echo sa_icon($message_tone === 'error' ? 'alert' : 'check-circle'); ?> <?php echo sa_e($message); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php $flash = sa_take_flash(); ?>
    <?php if ($flash): ?>
    <div class="sa-card-pad" style="padding-bottom:0;">
        <div class="sa-alert sa-alert-<?php echo ($flash['type'] ?? '') === 'error' ? 'danger' : 'info'; ?>">
            <?php echo sa_icon('info'); ?> <?php echo sa_e($flash['message']); ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="sa-card-pad">
        <div class="sa-grid sa-grid-4 sa-mb">
            <div class="sa-stat">
                <div class="sa-stat-num"><?php echo count($backups); ?></div>
                <div class="sa-stat-label">Backups stored</div>
            </div>
            <div class="sa-stat">
                <div class="sa-stat-num"><?php echo sa_backup_bytes($total_size); ?></div>
                <div class="sa-stat-label">Total size</div>
            </div>
            <div class="sa-stat">
                <div class="sa-stat-num" style="font-size:18px;"><?php echo $latest !== '' ? sa_e(date('M j, Y g:ia', strtotime($latest))) : '—'; ?></div>
                <div class="sa-stat-label">Latest backup</div>
            </div>
            <div class="sa-stat">
                <div class="sa-stat-num" style="font-size:18px;"><?php echo (int) $workspace_count; ?> workspaces</div>
                <div class="sa-stat-label">Covered by each dump</div>
            </div>
        </div>

        <div class="sa-info">
            <?php echo sa_icon('info'); ?>
            <div>
                <strong>Location:</strong> <?php echo sa_e($backup_dir); ?><br>
                <strong>Database:</strong> <?php echo sa_e($config['name'] !== '' ? $config['name'] : 'unknown'); ?>
                <?php if (!$can_dump): ?>
                <br><strong>Note:</strong> the connection details could not be read, so backups fall back to a PHP dump.
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$backups): ?>
        <div class="sa-mt">
            <div class="sa-alert sa-alert-info">
                <?php echo sa_icon('alert'); ?>
                No platform backup has been taken yet. Create the first dump before the next deployment.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <h3>Backup files</h3>
            <p>Every dump in <code class="sa-mono">backups/</code>. Downloads and deletions are recorded in the activity log.</p>
        </div>
        <a class="sa-btn sa-btn-ghost sa-btn-sm" href="logs.php?action=backup_create">
            <?php echo sa_icon('activity'); ?> Backup activity
        </a>
    </div>

    <?php if (!$backups): ?>
    <div class="sa-empty">
        <?php echo sa_icon('inbox'); ?>
        <strong>No backups yet</strong>
        <p>Click “Create backup” to write the first dump to disk.</p>
    </div>
    <?php else: ?>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead>
                <tr>
                    <th scope="col">File</th>
                    <th scope="col">Size</th>
                    <th scope="col">Created</th>
                    <th scope="col">Type</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><strong class="sa-mono"><?php echo sa_e($backup['filename']); ?></strong></td>
                    <td><?php echo sa_e(sa_backup_bytes($backup['size'])); ?></td>
                    <td><?php echo sa_e(date('M j, Y g:i a', $backup['modified'])); ?></td>
                    <td>
                        <?php if ($backup['type'] === 'gz'): ?>
                        <span class="sa-badge sa-badge-info">Compressed</span>
                        <?php else: ?>
                        <span class="sa-badge sa-badge-warning">Plain SQL</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="sa-table-actions">
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="?download=<?php echo urlencode($backup['filename']); ?>">
                                <?php echo sa_icon('download'); ?> Download
                            </a>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete <?php echo sa_e($backup['filename']); ?>? This removes the file from the server.');">
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

<div class="sa-card sa-mt">
    <div class="sa-card-head">
        <div><h3>How this is used</h3><p>Restore path and tenant-facing equivalent.</p></div>
    </div>
    <div class="sa-card-pad">
        <div class="sa-info">
            <?php echo sa_icon('info'); ?>
            <div>
                <strong>Restore:</strong> download the dump and import it on the server
                (<code class="sa-mono">gunzip &lt; backup.sql.gz | mysql database</code>), or hand it to the platform team.
                <br><strong>Tenant backups:</strong> each workspace can export its own rows from
                <code class="sa-mono">admin/backups.php</code> — same idea, scoped to one tenant.
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>
