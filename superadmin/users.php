<?php
/**
 * ============================================================
 *  Super Admin — Users & roles (permission-based access)
 * ============================================================
 *  Create super admin accounts and pick exactly which areas of
 *  the control center each one may open. The first account is
 *  the platform owner: it always keeps full access and cannot
 *  be edited or deleted from this screen.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();
require_sa_permission('users');

$me         = sa_current_admin($conn);
$i_am_owner = $me && (int) $me['is_owner'] === 1;
$perm_list  = sa_permission_list();
$perm_desc  = [
    'dashboard'     => 'KPIs, trends and the live activity feed',
    'analytics'     => 'Deep-dive charts and rating insights',
    'tenants'       => 'Create and manage tenant companies',
    'subscriptions' => 'Billing status, renewals and churn',
    'plans'         => 'Plan catalogue and pricing',
    'quotes'        => 'Inbound “Get started” requests',
    'users'         => 'Add super admins and set their access',
    'settings'      => 'Platform branding and configuration',
];

if (!function_exists('users_posted_permissions')) {
    /** Whitelist the posted permission checkboxes against the catalogue. */
    function users_posted_permissions() {
        if (!isset($_POST['permissions']) || !is_array($_POST['permissions'])) return [];
        return array_values(array_intersect($_POST['permissions'], array_keys(sa_permission_list())));
    }
}

if (!function_exists('users_perm_grid')) {
    /** Render the permission checkbox grid. $selected = allowed keys. */
    function users_perm_grid($selected = []) {
        $desc = [
            'dashboard'     => 'KPIs, trends and the live activity feed',
            'analytics'     => 'Deep-dive charts and rating insights',
            'tenants'       => 'Create and manage tenant companies',
            'subscriptions' => 'Billing status, renewals and churn',
            'plans'         => 'Plan catalogue and pricing',
            'quotes'        => 'Inbound “Get started” requests',
            'users'         => 'Add super admins and set their access',
            'settings'      => 'Platform branding and configuration',
        ];
        echo '<div class="sa-perm-grid" data-sa-perm-grid>';
        foreach (sa_permission_list() as $key => $label) {
            echo '<label class="sa-perm">'
               . '<input type="checkbox" name="permissions[]" value="' . sa_e($key) . '"'
               . (in_array($key, $selected, true) ? ' checked' : '') . '>'
               . '<span><strong>' . sa_e($label) . '</strong>'
               . '<small>' . sa_e(isset($desc[$key]) ? $desc[$key] : '') . '</small></span>'
               . '</label>';
        }
        echo '</div>';
    }
}
/* ---------- POST handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('users.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create_user') {
        $username = sanitize(isset($_POST['username']) ? $_POST['username'] : '');
        $email    = trim(sanitize(isset($_POST['email']) ? $_POST['email'] : ''));
        $password = (string) (isset($_POST['password']) ? $_POST['password'] : '');
        $perms    = users_posted_permissions();

        $errors = [];
        if ($username === '')                              $errors[] = 'Username is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'The email address is not valid.';
        if (strlen($password) < 6)                         $errors[] = 'Password must be at least 6 characters.';
        if (!$perms)                                       $errors[] = 'Tick at least one permission.';

        if ($errors) {
            sa_flash('error', implode(' ', $errors));
        } elseif ((int) sa_scalar($conn, "SELECT COUNT(*) FROM super_admins WHERE username = '"
                . $conn->real_escape_string($username) . "'", 0, 'super_admins') > 0) {
            sa_flash('error', 'The username “' . $username . '” is already taken.');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $json = json_encode($perms);
            $stmt = $conn->prepare('INSERT INTO super_admins (username, password, email, permissions) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $username, $hash, $email, $json);
            $ok = $stmt->execute();
            $stmt->close();
            sa_flash($ok ? 'success' : 'error', $ok
                ? $username . ' was created with ' . count($perms) . ' permission' . (count($perms) === 1 ? '' : 's') . '.'
                : 'Could not create the account. Please try again.');
        }
        redirect('users.php');
    }

    if ($action === 'update_user') {
        $id     = (int) (isset($_POST['user_id']) ? $_POST['user_id'] : 0);
        $target = $id > 0 ? sa_one($conn, 'SELECT * FROM super_admins WHERE id = ' . $id, 'super_admins') : null;

        if (!$target) {
            sa_flash('error', 'That account no longer exists.');
        } elseif ((int) $target['is_owner'] === 1) {
            sa_flash('error', 'The platform owner account cannot be edited here.');
        } elseif ($id === (int) $me['id']) {
            sa_flash('warning', 'Use Settings to change your own account.');
        } else {
            $username = sanitize(isset($_POST['username']) ? $_POST['username'] : '');
            $email    = trim(sanitize(isset($_POST['email']) ? $_POST['email'] : ''));
            $password = (string) (isset($_POST['password']) ? $_POST['password'] : '');
            $perms    = users_posted_permissions();

            $errors = [];
            if ($username === '')                              $errors[] = 'Username is required.';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'The email address is not valid.';
            if ($password !== '' && strlen($password) < 6)     $errors[] = 'New password must be at least 6 characters.';
            if (!$perms)                                       $errors[] = 'Tick at least one permission.';

            $taken = (int) sa_scalar($conn, "SELECT COUNT(*) FROM super_admins WHERE username = '"
                . $conn->real_escape_string($username) . "' AND id <> " . $id, 0, 'super_admins');
            if ($taken > 0) $errors[] = 'The username “' . $username . '” is already taken.';

            if ($errors) {
                sa_flash('error', implode(' ', $errors));
            } else {
                $json = json_encode($perms);
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('UPDATE super_admins SET username = ?, email = ?, password = ?, permissions = ? WHERE id = ?');
                    $stmt->bind_param('ssssi', $username, $email, $hash, $json, $id);
                } else {
                    $stmt = $conn->prepare('UPDATE super_admins SET username = ?, email = ?, permissions = ? WHERE id = ?');
                    $stmt->bind_param('sssi', $username, $email, $json, $id);
                }
                $ok = $stmt->execute();
                $stmt->close();
                sa_flash($ok ? 'success' : 'error', $ok
                    ? $username . ' was updated — ' . count($perms) . ' permission' . (count($perms) === 1 ? '' : 's') . '.'
                    : 'Could not update the account. Please try again.');
            }
        }
        redirect('users.php');
    }

    if ($action === 'delete_user') {
        $id     = (int) (isset($_POST['user_id']) ? $_POST['user_id'] : 0);
        $target = $id > 0 ? sa_one($conn, 'SELECT * FROM super_admins WHERE id = ' . $id, 'super_admins') : null;

        if (!$target) {
            sa_flash('error', 'That account no longer exists.');
        } elseif ((int) $target['is_owner'] === 1) {
            sa_flash('error', 'The platform owner account cannot be deleted.');
        } elseif ($id === (int) $me['id']) {
            sa_flash('warning', 'You cannot delete the account you are signed in with.');
        } else {
            $stmt = $conn->prepare('DELETE FROM super_admins WHERE id = ?');
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
            sa_flash($ok ? 'success' : 'error', $ok
                ? $target['username'] . ' was deleted and can no longer sign in.'
                : 'Could not delete the account. Please try again.');
        }
        redirect('users.php');
    }
}
/* ---------- data ---------- */
$users = sa_query(
    $conn,
    'SELECT id, username, email, is_owner, permissions, created_at FROM super_admins ORDER BY is_owner DESC, id ASC',
    ['super_admins']
);

/* ---------- page meta ---------- */
$robots       = 'noindex, nofollow';
$pageTitle    = 'Users & roles';
$pageHeading  = 'Users & roles';
$pageSubtitle = 'Create super admin accounts and pick exactly what each one may access.';
$activePage   = 'users';
$BASE         = '../';
$extraCss     = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <h2>Super admin accounts</h2>
        <p><?php echo sa_e(sa_num(count($users))); ?> account<?php echo count($users) === 1 ? '' : 's'; ?>
           &middot; the platform owner always keeps full access</p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#userCreateDialog">
            <?php echo sa_icon('plus'); ?> New user
        </button>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<!-- ============ ACCOUNTS TABLE ============ -->
<section class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3>Accounts</h3>
            <p>Permissions control which sections appear in the sidebar and which pages each account can open.</p>
        </div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="usersTable">
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>May access</th>
                    <th>Created</th>
                    <th data-no-export></th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($users as $u):
    $is_owner_row = (int) $u['is_owner'] === 1;
    $is_me        = (int) $u['id'] === (int) $me['id'];
    $u_perms      = $is_owner_row ? array_keys($perm_list) : json_decode((string) $u['permissions'], true);
    if (!is_array($u_perms)) $u_perms = array_keys($perm_list);
    $u_perms      = array_values(array_intersect($u_perms, array_keys($perm_list)));
    $can_touch    = !$is_owner_row && !$is_me;
?>
                <tr>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:10px">
                            <span class="sa-user-avatar"><?php echo sa_e(sa_initials($u['username'])); ?></span>
                            <strong><?php echo sa_e($u['username']); ?></strong>
                        </span>
                    </td>
                    <td><?php echo $u['email'] !== '' ? sa_e($u['email']) : '<span style="color:var(--sa-faint)">&mdash;</span>'; ?></td>
                    <td>
                        <span class="sa-badge <?php echo $is_owner_row ? 'sa-badge-lime' : 'sa-badge-plan'; ?>">
                            <?php echo $is_owner_row ? 'Owner' : 'Administrator'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="sa-chips">
<?php if ($is_owner_row): ?>
                            <span class="sa-chip active">Everything</span>
<?php else: ?>
<?php foreach (array_slice($u_perms, 0, 3) as $p): ?>
                            <span class="sa-chip"><?php echo sa_e(isset($perm_list[$p]) ? $perm_list[$p] : $p); ?></span>
<?php endforeach; ?>
<?php if (count($u_perms) > 3): ?>
                            <span class="sa-chip">+<?php echo count($u_perms) - 3; ?> more</span>
<?php endif; ?>
<?php if (!$u_perms): ?>
                            <span class="sa-chip">No access yet</span>
<?php endif; ?>
<?php endif; ?>
                        </div>
                    </td>
                    <td><?php echo sa_e(sa_date($u['created_at'])); ?></td>
                    <td data-no-export>
                        <div class="sa-row-actions">
<?php if ($can_touch): ?>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" title="Edit account &amp; permissions"
                                    data-sa-open-dialog="#userEdit<?php echo (int) $u['id']; ?>">
                                <?php echo sa_icon('edit'); ?>
                            </button>
                            <form method="POST" action="users.php" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost" title="Delete account"
                                        data-sa-confirm="Delete <?php echo sa_e($u['username']); ?>? They will immediately lose access.">
                                    <?php echo sa_icon('trash'); ?>
                                </button>
                            </form>
<?php else: ?>
                            <span class="sa-badge sa-badge-plan"
                                  title="<?php echo $is_me ? 'This is you — use Settings for your own account.' : 'The owner account is protected.'; ?>">
                                <?php echo $is_me ? 'You' : 'Protected'; ?>
                            </span>
<?php endif; ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<!-- ============ CREATE USER DIALOG ============ -->
<dialog class="sa-dialog" id="userCreateDialog" aria-labelledby="userCreateDialogTitle">
    <form method="POST" action="users.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="create_user">
        <div class="sa-dialog-head">
            <div>
                <h3 id="userCreateDialogTitle">New super admin</h3>
                <p>Pick the sections this account may open.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>
        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field">
                    <label for="nu_username">Username *</label>
                    <input id="nu_username" type="text" name="username" placeholder="e.g. ops_manager" required>
                </div>
                <div class="sa-field">
                    <label for="nu_email">Email</label>
                    <input id="nu_email" type="email" name="email" placeholder="name@company.com">
                </div>
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="nu_password">Password *</label>
                    <input id="nu_password" type="text" name="password" minlength="6" placeholder="At least 6 characters" required>
                    <span class="sa-hint">Share it securely — the user can change it after signing in.</span>
                </div>
            </div>

            <div class="sa-perm-block">
                <div class="sa-perm-block-head">
                    <strong>Access permissions *</strong>
                    <label class="sa-perm-all"><input type="checkbox" data-sa-perm-all> Select all</label>
                </div>
                <?php users_perm_grid(['dashboard']); ?>
            </div>
        </div>
        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('check'); ?> Create account</button>
        </div>
    </form>
</dialog>

<?php foreach ($users as $u):
    if ((int) $u['is_owner'] === 1 || (int) $u['id'] === (int) $me['id']) continue;
    $u_perms = json_decode((string) $u['permissions'], true);
    if (!is_array($u_perms)) $u_perms = array_keys($perm_list);
    $u_perms = array_values(array_intersect($u_perms, array_keys($perm_list)));
?>
<!-- ============ EDIT DIALOG: <?php echo sa_e($u['username']); ?> ============ -->
<dialog class="sa-dialog" id="userEdit<?php echo (int) $u['id']; ?>" aria-labelledby="userEditTitle<?php echo (int) $u['id']; ?>">
    <form method="POST" action="users.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="update_user">
        <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
        <div class="sa-dialog-head">
            <div>
                <h3 id="userEditTitle<?php echo (int) $u['id']; ?>">Edit <?php echo sa_e($u['username']); ?></h3>
                <p>Update the account or change what it may access.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>
        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field">
                    <label for="eu_username<?php echo (int) $u['id']; ?>">Username *</label>
                    <input id="eu_username<?php echo (int) $u['id']; ?>" type="text" name="username" value="<?php echo sa_e($u['username']); ?>" required>
                </div>
                <div class="sa-field">
                    <label for="eu_email<?php echo (int) $u['id']; ?>">Email</label>
                    <input id="eu_email<?php echo (int) $u['id']; ?>" type="email" name="email" value="<?php echo sa_e($u['email']); ?>">
                </div>
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="eu_password<?php echo (int) $u['id']; ?>">New password</label>
                    <input id="eu_password<?php echo (int) $u['id']; ?>" type="text" name="password" minlength="6" placeholder="Leave blank to keep the current password">
                </div>
            </div>

            <div class="sa-perm-block">
                <div class="sa-perm-block-head">
                    <strong>Access permissions *</strong>
                    <label class="sa-perm-all"><input type="checkbox" data-sa-perm-all> Select all</label>
                </div>
                <?php users_perm_grid($u_perms); ?>
            </div>
        </div>
        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('save'); ?> Save changes</button>
        </div>
    </form>
</dialog>
<?php endforeach; ?>
<script>
/* Permission picker: select-all + highlight of checked cards */
(function () {
    document.querySelectorAll('[data-sa-perm-grid]').forEach(function (grid) {
        var dialog = grid.closest('.sa-dialog, form');
        var all = dialog ? dialog.querySelector('[data-sa-perm-all]') : null;
        var boxes = grid.querySelectorAll('input[type="checkbox"]');

        function refresh() {
            boxes.forEach(function (cb) {
                cb.closest('.sa-perm').classList.toggle('is-checked', cb.checked);
            });
            if (all) {
                var checked = grid.querySelectorAll('input[type="checkbox"]:checked').length;
                all.checked = checked === boxes.length;
                all.indeterminate = checked > 0 && checked < boxes.length;
            }
        }
        boxes.forEach(function (cb) { cb.addEventListener('change', refresh); });
        if (all) {
            all.addEventListener('change', function () {
                boxes.forEach(function (cb) { cb.checked = all.checked; });
                refresh();
            });
        }
        refresh();
    });
})();
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>