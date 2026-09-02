<?php
/**
 * ============================================================
 *  Super Admin — Platform settings
 * ============================================================
 *  Branding, defaults, the super admin account and a small
 *  health panel that shows what the database actually contains.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();

/* Which settings this screen is allowed to write */
$sa_setting_fields = [
    'site_name'        => ['label' => 'Platform name', 'type' => 'text', 'hint' => 'Shown in emails, the header and the footer.'],
    'admin_email'      => ['label' => 'Admin email', 'type' => 'email', 'hint' => 'Where system notifications are sent.'],
    'support_email'    => ['label' => 'Support email', 'type' => 'email', 'hint' => 'Published to tenants as the support contact.'],
    'currency_symbol'  => ['label' => 'Currency symbol', 'type' => 'text', 'hint' => 'Used everywhere money is shown ($, €, £, GH₵…).'],
    'ratings_per_page' => ['label' => 'Ratings per page', 'type' => 'number', 'hint' => 'Pagination size on the public and tenant views.'],
    'trial_days'       => ['label' => 'Default trial length (days)', 'type' => 'number', 'hint' => 'Suggested length when creating a tenant.'],
];

/* ---------- POST handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('settings.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save_settings') {
        $saved = 0;
        $skipped = [];
        foreach ($sa_setting_fields as $key => $meta) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $value = trim((string) $_POST[$key]);
            if ($meta['type'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = $meta['label'] . ' (invalid email)';
                continue;
            }
            if ($meta['type'] === 'number') {
                $value = (string) max(0, (int) $value);
            }

            $exists = (int) sa_scalar(
                $conn,
                "SELECT COUNT(*) FROM settings WHERE setting_key = '" . $conn->real_escape_string($key) . "'",
                0,
                'settings'
            );
            if ($exists > 0) {
                $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->bind_param("ss", $value, $key);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->bind_param("ss", $key, $value);
                $stmt->execute();
                $stmt->close();
            }
            $saved++;
        }
        sa_flash($skipped ? 'warning' : 'success', $saved . ' setting' . ($saved === 1 ? '' : 's') . ' saved.'
            . ($skipped ? ' Skipped: ' . implode(', ', $skipped) . '.' : ''));
        redirect('settings.php');
    }

    if ($action === 'save_admin') {
        $username = sanitize($_POST['username'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $id = (int) ($_SESSION['super_admin_id'] ?? 0);
        if ($username === '' || !$id) {
            sa_flash('error', 'A username is required.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sa_flash('error', 'That email address does not look valid.');
        } else {
            $stmt = $conn->prepare("UPDATE super_admins SET username = ?, email = ? WHERE id = ?");
            $stmt->bind_param("ssi", $username, $email, $id);
            $stmt->execute();
            sa_flash($stmt->error ? 'error' : 'success', $stmt->error ? 'Could not save: ' . $stmt->error : 'Account details updated.');
            $stmt->close();
        }
        redirect('settings.php');
    }

    if ($action === 'change_password') {
        $id = (int) ($_SESSION['super_admin_id'] ?? 0);
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $hash = (string) sa_scalar($conn, "SELECT password FROM super_admins WHERE id = " . $id, '', 'super_admins');
        if (!$id || $hash === '' || !password_verify($current, $hash)) {
            sa_flash('error', 'Your current password is not correct.');
        } elseif (strlen($new) < 8) {
            sa_flash('error', 'The new password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            sa_flash('error', 'The two new passwords do not match.');
        } else {
            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE super_admins SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $new_hash, $id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Password changed. Use it the next time you sign in.');
        }
        redirect('settings.php');
    }
}

/* ---------- data ---------- */
$settings = [];
foreach (sa_query($conn, "SELECT setting_key, setting_value FROM settings", 'settings') as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$admin = sa_one($conn, "SELECT id, username, email, created_at FROM super_admins WHERE id = " . (int) ($_SESSION['super_admin_id'] ?? 0), 'super_admins');

$tables = ['super_admins', 'subscription_plans', 'tenants', 'admins', 'categories', 'customers', 'ratings', 'settings', 'quote_requests'];
$health = [];
foreach ($tables as $t) {
    $exists = sa_table_exists($conn, $t);
    $health[] = [
        'table' => $t,
        'exists' => $exists,
        'rows' => $exists ? (int) sa_scalar($conn, "SELECT COUNT(*) FROM `" . $t . "`", 0) : 0,
    ];
}
$missing = [];
foreach ($health as $h) {
    if (!$h['exists']) {
        $missing[] = $h['table'];
    }
}

/* ---------- page meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = 'Settings';
$pageHeading = 'Platform settings';
$pageSubtitle = 'Branding, defaults, your account and database health.';
$activePage = 'settings';
$BASE = '../';
$extraCss = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Settings</span>
        </div>
        <h2>Settings</h2>
        <p>Changes apply to the whole platform immediately.</p>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<?php if ($missing): ?>
<div class="sa-alert sa-alert-warning" data-sa-alert>
    <?php echo sa_icon('alert'); ?>
    <div>
        <strong>Missing database tables</strong>
        <?php echo sa_e(implode(', ', $missing)); ?> — import the matching block from
        <span class="sa-mono">database.sql</span> to unlock those features.
    </div>
</div>
<?php endif; ?>

<div class="sa-grid sa-split-2-1">

    <!-- ===== Platform defaults ===== -->
    <section class="sa-card">
        <form method="POST" action="settings.php" class="sa-form">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="save_settings">
            <div class="sa-card-head">
                <div>
                    <h3>Platform defaults</h3>
                    <p>Branding and the numbers the app runs on</p>
                </div>
            </div>
            <div class="sa-card-pad">
                <div class="sa-form-grid">
<?php foreach ($sa_setting_fields as $key => $meta): ?>
                    <div class="sa-field">
                        <label for="s_<?php echo sa_e($key); ?>"><?php echo sa_e($meta['label']); ?></label>
                        <input id="s_<?php echo sa_e($key); ?>" type="<?php echo sa_e($meta['type']); ?>" name="<?php echo sa_e($key); ?>"
                               value="<?php echo sa_e(isset($settings[$key]) ? $settings[$key] : ''); ?>"
                               <?php echo $meta['type'] === 'number' ? 'min="0" step="1"' : ''; ?>>
                        <span class="sa-hint"><?php echo sa_e($meta['hint']); ?></span>
                    </div>
<?php endforeach; ?>
                </div>

                <div class="sa-section-title" style="margin:24px 0 12px">Appearance</div>
                <div class="sa-flex" style="gap:14px;flex-wrap:wrap">
                    <button type="button" class="sa-btn sa-btn-ghost" data-sa-theme>
                        <?php echo sa_icon('sun'); ?> Switch to light theme
                    </button>
                    <span class="sa-muted" style="font-size:12.6px">Your choice is remembered in this browser only.</span>
                </div>
            </div>
            <div class="sa-card-foot">
                <span>Stored in the <span class="sa-mono">settings</span> table</span>
                <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('save'); ?> Save settings</button>
            </div>
        </form>
    </section>

    <!-- ===== Account ===== -->
    <div class="sa-stack">
        <section class="sa-card">
            <form method="POST" action="settings.php" class="sa-form">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="save_admin">
                <div class="sa-card-head">
                    <div>
                        <h3>Your account</h3>
                        <p>Signed in as <?php echo sa_e($admin ? $admin['username'] : 'super admin'); ?></p>
                    </div>
                    <span class="sa-user-avatar" style="width:38px;height:38px;border-radius:12px"><?php echo sa_e(sa_initials($admin ? $admin['username'] : 'SA')); ?></span>
                </div>
                <div class="sa-card-pad sa-form" style="gap:14px">
                    <div class="sa-field">
                        <label for="a_username">Username</label>
                        <input id="a_username" type="text" name="username" value="<?php echo sa_e($admin ? $admin['username'] : ''); ?>" required>
                    </div>
                    <div class="sa-field">
                        <label for="a_email">Email</label>
                        <input id="a_email" type="email" name="email" value="<?php echo sa_e($admin ? $admin['email'] : ''); ?>">
                    </div>
                    <div class="sa-field">
                        <label>Member since</label>
                        <div class="sa-muted" style="font-size:13px"><?php echo sa_e(sa_date($admin ? $admin['created_at'] : '', 'M d, Y')); ?></div>
                    </div>
                </div>
                <div class="sa-card-foot">
                    <span>Used to sign in at <span class="sa-mono">/superadmin/login.php</span></span>
                    <button type="submit" class="sa-btn sa-btn-ghost"><?php echo sa_icon('save'); ?> Save</button>
                </div>
            </form>
        </section>

        <section class="sa-card">
            <form method="POST" action="settings.php" class="sa-form">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="change_password">
                <div class="sa-card-head">
                    <div>
                        <h3>Change password</h3>
                        <p>Minimum 8 characters</p>
                    </div>
                    <span class="sa-kpi-icon" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)"><?php echo sa_icon('key'); ?></span>
                </div>
                <div class="sa-card-pad sa-form" style="gap:14px">
                    <div class="sa-field">
                        <label for="p_current">Current password</label>
                        <input id="p_current" type="password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="sa-field">
                        <label for="p_new">New password</label>
                        <input id="p_new" type="password" name="new_password" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="sa-field">
                        <label for="p_confirm">Confirm new password</label>
                        <input id="p_confirm" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                    </div>
                </div>
                <div class="sa-card-foot">
                    <span>Hashed with PHP's bcrypt</span>
                    <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('shield'); ?> Update password</button>
                </div>
            </form>
        </section>
    </div>
</div>

<!-- ===== Database health ===== -->
<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <h3>Database health</h3>
            <p>Tables found in <span class="sa-mono"><?php echo sa_e(defined('DB_NAME') ? DB_NAME : 'the configured database'); ?></span></p>
        </div>
        <div class="sa-card-head-actions">
            <span class="sa-pill"><?php echo sa_e(sa_num(count($health) - count($missing))); ?> / <?php echo sa_e(sa_num(count($health))); ?> present</span>
            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" onclick="window.location.reload()"><?php echo sa_icon('refresh'); ?> Re-check</button>
        </div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" data-sa-sortable-table>
            <thead scope="col">
                <tr>
                    <th scope="col">Table</th>
                    <th scope="col">Status</th>
                    <th data-sa-sort="2" data-type="num" scope="col" aria-sort="none">Rows</th>
                    <th scope="col">Where it is used</th>
                </tr>
            </thead>
            <tbody>
<?php
$usage = [
    'super_admins'      => 'This control center',
    'subscription_plans'=> 'Plans & pricing',
    'tenants'           => 'Tenants, subscriptions, revenue',
    'admins'            => 'Tenant admin logins',
    'categories'        => 'Company categories',
    'customers'         => 'Companies listed by tenants',
    'ratings'           => 'Ratings, analytics, engagement',
    'settings'          => 'This page',
    'quote_requests'    => 'Sales pipeline',
];
foreach ($health as $h): ?>
                <tr>
                    <td class="sa-mono"><?php echo sa_e($h['table']); ?></td>
                    <td><?php echo $h['exists']
                        ? '<span class="sa-badge sa-badge-active">Present</span>'
                        : '<span class="sa-badge sa-badge-inactive">Missing</span>'; ?></td>
                    <td class="num"><?php echo $h['exists'] ? sa_e(sa_num($h['rows'])) : '<span class="sa-faint">—</span>'; ?></td>
                    <td class="sa-muted"><?php echo sa_e(isset($usage[$h['table']]) ? $usage[$h['table']] : ''); ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="sa-card-foot">
        <span>PHP <?php echo sa_e(PHP_VERSION); ?> &middot; server time <?php echo sa_e(date('M d, Y H:i')); ?></span>
        <span>Schema lives in <span class="sa-mono">database.sql</span></span>
    </div>
</section>

<?php include __DIR__ . '/_shell_footer.php'; ?>
