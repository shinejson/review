<?php
/**
 * ============================================================
 *  Admin — Workspace Backups
 * ============================================================
 *  A workspace-scoped backup screen for the tenant admin panel.
 *
 *  The platform owner backs up the whole database in the Super
 *  Admin panel; this page does the equivalent *for one tenant*:
 *  it exports everything that belongs to the signed-in workspace
 *  (branches, reviews, customers, services, team, billing, …)
 *  into a single downloadable file, and keeps the history.
 *
 *  Nothing here can ever touch another workspace: every query is
 *  filtered by the session tenant, the file lives in that tenant's
 *  own folder, and the download/delete actions reject any record
 *  that does not carry the same tenant id.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/tenant_backups.php';

requireLogin();

$tenant_id = $tenant_id ?? (function_exists('getTenantId') ? getTenantId() : 0);
$is_tenant = $is_tenant ?? (function_exists('isTenant') ? isTenant() : false);
$tenant_id = (int) $tenant_id;

requireTeamAccess('backups');

admin_ensure_schema($conn);

$BASE      = '../';
$pageTitle = 'Backups';
$activeNav = 'backups';

/* Who is acting — written into the file and into the log row. */
$actor_label = 'Workspace owner';
if (!empty($_SESSION['team_member_name'])) {
    $actor_label = (string) $_SESSION['team_member_name'] . ' (team)';
} elseif (!empty($_SESSION['tenant_name'])) {
    $actor_label = (string) $_SESSION['tenant_name'];
}
if (!empty($_SESSION['impersonator_super_admin_id'])) {
    $actor_label = 'Support session — ' . (string) ($_SESSION['impersonator_super_admin_name'] ?? 'Super Admin');
}

/* ============================================================
   Actions
   ============================================================ */

/* Download — GET so the browser can stream straight to disk. */
if (isset($_GET['download'])) {
    if (!$is_tenant || $tenant_id <= 0) {
        sa_flash('error', 'Backups belong to a workspace — sign in as the workspace to download one.');
        redirect('backups.php');
    }

    $row = tenant_backup_find($conn, $tenant_id, (int) $_GET['download']);
    if (!$row) {
        sa_flash('error', 'That backup does not exist in this workspace.');
        redirect('backups.php');
    }

    $path = tenant_backup_path($row);
    if ($path === '') {
        sa_flash('error', 'The saved file is no longer on the server. Create a fresh backup instead.');
        redirect('backups.php');
    }

    admin_log_activity(
        $conn,
        'backup_download',
        'Downloaded backup ' . $row['filename'],
        'backup',
        (int) $row['id']
    );

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
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

    if ($action === 'create_backup') {
        if (!$is_tenant || $tenant_id <= 0) {
            sa_flash('error', 'Only a workspace can create a workspace backup.');
            redirect('backups.php');
        }

        $collected = tenant_backup_collect($conn, $tenant_id);
        $payload   = tenant_backup_payload($conn, $tenant_id, $collected, $actor_label);
        $result    = tenant_backup_store($conn, $tenant_id, $payload, $actor_label);

        if (!empty($result['ok'])) {
            admin_log_activity(
                $conn,
                'backup_create',
                'Created backup ' . $result['filename'] . ' (' . tenant_backup_format_bytes($result['size']) . ', '
                    . $result['tables'] . ' sections, ' . $result['records'] . ' records)',
                'backup',
                (int) $result['id']
            );
            sa_flash('success', 'Backup created — ' . $result['records'] . ' records from '
                . $result['tables'] . ' sections (' . tenant_backup_format_bytes($result['size']) . ').');
        } else {
            sa_flash('error', $result['message'] ?? 'The backup could not be created.');
        }
        redirect('backups.php');
    }

    if ($action === 'delete_backup') {
        $row = tenant_backup_find($conn, $tenant_id, (int) ($_POST['backup_id'] ?? 0));
        if ($row && tenant_backup_delete($conn, $tenant_id, (int) $row['id'])) {
            admin_log_activity(
                $conn,
                'backup_delete',
                'Deleted backup ' . $row['filename'],
                'backup',
                (int) $row['id']
            );
            sa_flash('success', 'Deleted ' . $row['filename'] . '.');
        } else {
            sa_flash('error', 'That backup does not exist in this workspace.');
        }
        redirect('backups.php');
    }
}

/* ============================================================
   Data
   ============================================================ */

$backups   = $is_tenant && $tenant_id > 0 ? tenant_backup_list($conn, $tenant_id, 50) : [];
$summary   = tenant_backup_summary($backups);
$sections  = tenant_backup_sections();
uasort($sections, function ($a, $b) {
    return $a['order'] <=> $b['order'];
});

/* Which sections this install actually has — a table that is not
   present is skipped by the export, so the page should say so. */
$section_installed = [];
$included = 0;
if ($is_tenant && $tenant_id > 0) {
    foreach ($sections as $key => $section) {
        $ok = sa_table_exists($conn, $section['table']);
        $section_installed[$key] = $ok;
        if ($ok) {
            $included++;
        }
    }
}

include __DIR__ . '/_shell.php';
?>

<!-- Page Header -->
<div class="welcome-row" style="margin-bottom:22px;">
    <div>
        <p class="eyebrow">Workspace &middot; Data &amp; safety</p>
        <h1 style="margin:0;">Workspace Backups</h1>
        <p class="muted" style="margin-top:6px;">
            Download a copy of everything this workspace holds. Files are generated on demand and stay on this server for you to download again.
        </p>
    </div>
    <?php if ($is_tenant && $tenant_id > 0): ?>
    <form method="POST" action="backups.php" style="margin:0;">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="create_backup">
        <button type="submit" class="btn btn-primary" style="padding:10px 18px;">
            ⤓ Create New Backup
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if ($flash = sa_take_flash()): ?>
<div class="alert alert-<?php echo ($flash['type'] ?? '') === 'error' ? 'error' : 'success'; ?>" role="alert">
    <?php echo ($flash['type'] ?? '') === 'error' ? '⚠' : '✓'; ?> <?php echo htmlspecialchars($flash['message']); ?>
</div>
<?php endif; ?>

<?php if (!$is_tenant || $tenant_id <= 0): ?>
<div class="form-card" style="padding:26px;">
    <h2 style="margin:0 0 10px;font-size:18px;">🗄️ Workspace backups</h2>
    <p class="muted" style="margin:0 0 14px;line-height:1.6;">
        Backups belong to a workspace, so this screen works for a signed-in workspace owner (or a team member with
        the Backups module). You are signed in as a platform administrator — the whole database is backed up from the
        Super Admin panel instead.
    </p>
    <a href="<?php echo htmlspecialchars($BASE); ?>superadmin/backups.php" class="btn btn-secondary" style="text-decoration:none;display:inline-block;">
        Open platform backups →
    </a>
</div>
<?php else: ?>

<!-- Metric Cards -->
<div class="metric-grid" style="margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon lime">🗄️</div>
        <span>Backups saved</span>
        <strong><?php echo (int) $summary['count']; ?></strong>
        <small>Files kept for this workspace</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon blue">⇩</div>
        <span>Stored size</span>
        <strong><?php echo $summary['count'] ? tenant_backup_format_bytes($summary['size']) : '—'; ?></strong>
        <small>Across all backups</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon purple">✓</div>
        <span>Records covered</span>
        <strong><?php echo $summary['count'] ? number_format($summary['records']) : '—'; ?></strong>
        <small>Rows in the newest export</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon amber">🕒</div>
        <span>Last backup</span>
        <strong style="font-size:18px;"><?php echo $summary['latest'] !== '' ? date('M j, Y', strtotime($summary['latest'])) : 'No backup yet'; ?></strong>
        <small><?php echo $summary['latest'] !== '' ? date('g:i a', strtotime($summary['latest'])) . ' · workspace time' : 'Create the first one today'; ?></small>
    </div>
</div>

<div class="grid-2col" style="align-items:start;gap:20px;">

    <!-- What gets exported -->
    <div class="form-card" style="padding:24px;">
        <h3 style="margin:0 0 6px;font-size:16px;">📦 What a workspace backup contains</h3>
        <p class="muted" style="margin:0 0 16px;font-size:12.5px;">
            Only this workspace's rows — never another tenant's data. Passwords, setup links and API tokens are
            stripped before the file is written, so a backup is safe to store or share with your accountant.
            <br><strong id="backupSectionCount"><?php echo (int) $included; ?> sections</strong> installed in this workspace.
        </p>
        <div class="table-scroll-wrap">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr>
                        <th scope="col" style="text-align:left;">Section</th>
                        <th scope="col" style="text-align:right;">Table</th>
                        <th scope="col" style="text-align:right;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sections as $key => $section): ?>
                    <?php $installed = !empty($section_installed[$key]); ?>
                    <tr>
                        <td><?php echo htmlspecialchars($section['label']); ?></td>
                        <td style="text-align:right;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#64748b;">
                            <?php echo htmlspecialchars($section['table']); ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <?php if ($installed): ?>
                            <span class="status-badge-replied" style="font-size:10.5px;">included</span>
                            <?php else: ?>
                            <span class="status-badge-pending" style="font-size:10.5px;">not installed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Restore guidance -->
    <div class="form-card" style="padding:24px;">
        <h3 style="margin:0 0 6px;font-size:16px;">↺ Restoring a backup</h3>
        <p class="muted" style="margin:0 0 14px;font-size:12.5px;">
            Restores are handled by the platform team so that a half-applied file can never damage a live workspace.
        </p>
        <ol style="margin:0 0 16px;padding-left:18px;color:#475569;font-size:13px;line-height:1.7;">
            <li>Create a backup (or download an older one).</li>
            <li>Attach the file to a support request and say which workspace it is for.</li>
            <li>Support imports it, then confirms what was restored.</li>
        </ol>
        <div class="alert alert-success" role="note" style="margin:0;font-size:12.5px;">
            ✓ Files are stored per workspace in <code>backups/tenants/<?php echo (int) $tenant_id; ?>/</code> and only members with the Backups module can see this page.
        </div>
        <p class="muted" style="margin:14px 0 0;font-size:12px;">
            Keeping the list short? Delete backups you have already downloaded — deleting one removes the file from
            the server, and the activity log keeps the record that it happened.
        </p>
    </div>
</div>

<!-- Backup history -->
<div class="data-table-card" style="margin-top:24px;">
    <div style="padding:18px 20px 12px;">
        <h3 style="margin:0;font-size:16px;">🗂️ Backup history</h3>
        <p class="muted" style="margin:4px 0 0;font-size:12.5px;">
            <?php echo count($backups); ?> backup<?php echo count($backups) === 1 ? '' : 's'; ?> for this workspace
        </p>
    </div>

    <?php if (!$backups): ?>
    <div style="padding:10px 20px 26px;">
        <p class="muted" style="font-size:13px;margin:0;">
            No backups yet. Click <strong>Create New Backup</strong> and the first export is ready in a moment.
        </p>
    </div>
    <?php else: ?>
    <div class="table-scroll-wrap">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr>
                    <th scope="col" style="text-align:left;">Created</th>
                    <th scope="col" style="text-align:left;">File</th>
                    <th scope="col" style="text-align:right;">Sections</th>
                    <th scope="col" style="text-align:right;">Records</th>
                    <th scope="col" style="text-align:right;">Size</th>
                    <th scope="col" style="text-align:left;">Created by</th>
                    <th scope="col" style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                <?php $file_ok = tenant_backup_path($backup) !== ''; ?>
                <tr>
                    <td style="white-space:nowrap;">
                        <strong style="color:var(--ink);"><?php echo htmlspecialchars(date('M j, Y', strtotime($backup['created_at']))); ?></strong><br>
                        <small class="muted"><?php echo htmlspecialchars(date('g:i a', strtotime($backup['created_at']))); ?></small>
                    </td>
                    <td>
                        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#475569;">
                            <?php echo htmlspecialchars($backup['filename']); ?>
                        </span>
                        <?php if (!empty($backup['format'])): ?>
                        <span class="status-badge-replied" style="font-size:10.5px;margin-left:6px;"><?php echo htmlspecialchars($backup['format']); ?></span>
                        <?php endif; ?>
                        <?php if (!$file_ok): ?>
                        <span class="status-badge-pending" style="font-size:10.5px;margin-left:6px;">file missing</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;"><?php echo (int) ($backup['table_count'] ?? 0); ?></td>
                    <td style="text-align:right;"><?php echo number_format((int) ($backup['record_count'] ?? 0)); ?></td>
                    <td style="text-align:right;white-space:nowrap;"><?php echo htmlspecialchars(tenant_backup_format_bytes((int) ($backup['size_bytes'] ?? 0))); ?></td>
                    <td><?php echo htmlspecialchars((string) ($backup['created_by_label'] ?? 'Workspace')); ?></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <?php if ($file_ok): ?>
                        <a href="backups.php?download=<?php echo (int) $backup['id']; ?>" class="btn btn-secondary"
                           style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;font-size:12px;text-decoration:none;">
                            Download
                        </a>
                        <?php endif; ?>
                        <form method="POST" action="backups.php" style="display:inline-block;margin-left:4px;"
                              onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($backup['filename'])); ?>? The file is removed from the server.');">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_backup">
                            <input type="hidden" name="backup_id" value="<?php echo (int) $backup['id']; ?>">
                            <button type="submit" class="btn"
                                    style="padding:6px 12px;font-size:12px;background:transparent;border:1px solid #fecaca;color:#b91c1c;border-radius:6px;cursor:pointer;">
                                Delete
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/_shell_footer.php'; ?>
