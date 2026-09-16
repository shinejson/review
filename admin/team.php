<?php
/**
 * ============================================================
 *  Admin — Team Members
 * ============================================================
 *  Lets the workspace owner (tenant) create additional user
 *  accounts that can sign into the admin panel, and assign
 *  each one module-level access (permissions).
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';
requireLogin();
ensureTeamSchema($conn);

$tenant_id = getTenantId();
$is_tenant = isTenant();
$is_admin  = isAdmin();

requireTeamAccess('team');

/**
 * Page-local helper: are these username/email already used by anyone
 * we can sign in with (another team member, a tenant, or an admin)?
 */
function teamCredentialsTaken($conn, $username, $email, $exclude_id, $tenant_id) {
    $stmt = $conn->prepare("SELECT id FROM team_members WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1");
    $stmt->bind_param("ssi", $username, $email, $exclude_id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) { $stmt->close(); return true; }
    $stmt->close();

    $t = $conn->prepare("SELECT id FROM tenants WHERE username = ? OR email = ? LIMIT 1");
    $t->bind_param("ss", $username, $email);
    $t->execute();
    if ($t->get_result()->fetch_assoc()) { $t->close(); return true; }
    $t->close();

    $a = $conn->prepare("SELECT id FROM admins WHERE username = ? OR email = ? LIMIT 1");
    $a->bind_param("ss", $username, $email);
    $a->execute();
    if ($a->get_result()->fetch_assoc()) { $a->close(); return true; }
    $a->close();

    return false;
}
$success = '';
$error   = '';
$own_member_id = (int)($_SESSION['team_member_id'] ?? 0);

// ============================================================
// POST — create / update / toggle / delete team members
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_tenant) {
    $action    = $_POST['action'] ?? '';
    $member_id = (int)($_POST['member_id'] ?? 0);

    $member = null;
    if ($member_id > 0) {
        $m = $conn->prepare("SELECT * FROM team_members WHERE id = ? AND tenant_id = ? LIMIT 1");
        $m->bind_param("ii", $member_id, $tenant_id);
        $m->execute();
        $member = $m->get_result()->fetch_assoc();
        $m->close();
    }

    $full_name = sanitize($_POST['full_name'] ?? '');
    $username  = strtolower(trim($_POST['username'] ?? ''));
    $email     = trim($_POST['email'] ?? '');
    $role      = in_array($_POST['role'] ?? '', array_keys(teamRolePresets()), true) ? $_POST['role'] : 'staff';
    $perms     = teamMemberParsePerms($_POST['permissions'] ?? []);
    $perm_str  = implode(',', $perms);

    if ($action === 'create') {
        $password = (string)($_POST['password'] ?? '');
        if ($full_name === '' || $username === '' || $email === '' || $password === '') {
            $_SESSION['error'] = 'All fields are required to add a team member.';
        } elseif (!preg_match('/^[a-z0-9_]{3,50}$/', $username)) {
            $_SESSION['error'] = 'Username must be 3–50 characters using only letters, numbers and underscores.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $_SESSION['error'] = 'Password must be at least 6 characters.';
        } elseif (teamCredentialsTaken($conn, $username, $email, 0, $tenant_id)) {
            $_SESSION['error'] = 'That username or email is already in use. Choose a different one.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins  = $conn->prepare("INSERT INTO team_members (tenant_id, full_name, username, email, password, role, permissions) VALUES (?,?,?,?,?,?,?)");
            $ins->bind_param("issssss", $tenant_id, $full_name, $username, $email, $hash, $role, $perm_str);
            $ins->execute();
            if ($ins->error) {
                $_SESSION['error'] = 'Could not add the team member: ' . $ins->error;
            } else {
                $_SESSION['success'] = 'Team member "' . htmlspecialchars($full_name) . '" created. They can sign in with their username or email.';
                admin_log_activity($conn, 'team_create', 'Added the team member "' . $full_name . '" (' . teamRoleLabel($role) . ')', 'team_member', (int) $conn->insert_id);
            }
            $ins->close();
        }
    } elseif ($action === 'update' && $member) {
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $new_pass  = (string)($_POST['password'] ?? '');
        if ($member_id === $own_member_id && !$is_active) {
            $_SESSION['error'] = 'You cannot disable your own account.';
        } elseif ($full_name === '' || $username === '' || $email === '') {
            $_SESSION['error'] = 'Name, username and email are required.';
        } elseif (!preg_match('/^[a-z0-9_]{3,50}$/', $username)) {
            $_SESSION['error'] = 'Username must be 3–50 characters using only letters, numbers and underscores.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Please enter a valid email address.';
        } elseif ($new_pass !== '' && strlen($new_pass) < 6) {
            $_SESSION['error'] = 'New password must be at least 6 characters.';
        } elseif (teamCredentialsTaken($conn, $username, $email, $member_id, $tenant_id)) {
            $_SESSION['error'] = 'That username or email is already used by another account.';
        } else {
            if ($new_pass !== '') {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $up = $conn->prepare("UPDATE team_members SET full_name=?, username=?, email=?, role=?, permissions=?, is_active=?, password=? WHERE id=? AND tenant_id=?");
                $up->bind_param("sssssssii", $full_name, $username, $email, $role, $perm_str, $is_active, $hash, $member_id, $tenant_id);
            } else {
                $up = $conn->prepare("UPDATE team_members SET full_name=?, username=?, email=?, role=?, permissions=?, is_active=? WHERE id=? AND tenant_id=?");
                $up->bind_param("ssssssii", $full_name, $username, $email, $role, $perm_str, $is_active, $member_id, $tenant_id);
            }
            $up->execute();
            if ($up->error) {
                $_SESSION['error'] = 'Could not update the team member: ' . $up->error;
            } else {
                $_SESSION['success'] = 'Team member updated successfully.';
                admin_log_activity($conn, 'team_update', 'Updated the team member "' . $full_name . '" (' . teamRoleLabel($role) . ')', 'team_member', $member_id);
            }
            $up->close();
        }
    } elseif ($action === 'toggle' && $member) {
        if ($member_id === $own_member_id) {
            $_SESSION['error'] = 'You cannot disable your own account.';
        } else {
            $new_state = $member['is_active'] ? 0 : 1;
            $up = $conn->prepare("UPDATE team_members SET is_active=? WHERE id=? AND tenant_id=?");
            $up->bind_param("iii", $new_state, $member_id, $tenant_id);
            $up->execute();
            $up->close();
            $_SESSION['success'] = $new_state ? 'Team member enabled.' : 'Team member disabled — they can no longer sign in.';
            admin_log_activity(
                $conn,
                'team_toggle',
                ($new_state ? 'Enabled' : 'Disabled') . ' the team member "' . (string) ($member['full_name'] ?? ('#' . $member_id)) . '"',
                'team_member',
                $member_id
            );
        }
    } elseif ($action === 'delete' && $member) {
        if ($member_id === $own_member_id) {
            $_SESSION['error'] = 'You cannot delete your own account.';
        } else {
            $del = $conn->prepare("DELETE FROM team_members WHERE id=? AND tenant_id=?");
            $del->bind_param("ii", $member_id, $tenant_id);
            $del->execute();
            $del->close();
            $_SESSION['success'] = 'Team member removed.';
            admin_log_activity($conn, 'team_delete', 'Removed the team member "' . (string) ($member['full_name'] ?? ('#' . $member_id)) . '"', 'team_member', $member_id);
        }
    }

    header('Location: team.php');
    exit;
}
// ============================================================
// Load members + flash messages
// ============================================================
if (isset($_SESSION['success'])) { $success = $_SESSION['success']; unset($_SESSION['success']); }
if (isset($_SESSION['error']))   { $error   = $_SESSION['error'];   unset($_SESSION['error']); }

$team_members = [];
$total_active = 0;
$total_managers = 0;
if ($is_tenant && $tenant_id) {
    $tm = $conn->prepare("SELECT * FROM team_members WHERE tenant_id = ? ORDER BY is_active DESC, full_name ASC");
    $tm->bind_param("i", $tenant_id);
    $tm->execute();
    $res_tm = $tm->get_result();
    while ($row_tm = $res_tm->fetch_assoc()) {
        $team_members[] = $row_tm;
        if ((int)$row_tm['is_active'] === 1) $total_active++;
        if ($row_tm['role'] === 'manager') $total_managers++;
    }
    $tm->close();
}

// Which member is being edited? (?edit=ID, must belong to this tenant)
$editing_id   = (int)($_GET['edit'] ?? 0);
$edit_member  = null;
if ($editing_id > 0) {
    foreach ($team_members as $tm_row) {
        if ((int)$tm_row['id'] === $editing_id) { $edit_member = $tm_row; break; }
    }
}

$perm_labels  = teamPermissionLabels();
$role_presets = teamRolePresets();
$edit_perms   = $edit_member ? teamMemberParsePerms($edit_member['permissions']) : teamMemberParsePerms($role_presets['manager']);

$BASE      = '../';
$pageTitle = 'Team Members';
$activeNav = 'team';
include __DIR__ . '/_shell.php';

if (!$is_tenant) {
    // Legacy platform admins are not tenant-scoped — nothing to manage here.
    ?>
    <div class="form-card" style="padding:28px;max-width:720px;">
        <h2 style="margin:0 0 10px;font-size:18px;">👥 Team Members</h2>
        <p class="muted" style="margin:0;line-height:1.6;">Team management is available to workspace owners. Sign in with your workspace tenant account (or view this page while operating as a tenant) to create and manage team members.</p>
    </div>
    <?php
    return;
}
?>
<!-- Page Header -->
<div class="welcome-row" style="margin-bottom:22px;">
    <div>
        <p class="eyebrow">Your Workspace &middot; Team Members</p>
        <h1 style="margin:0;">Team Members</h1>
        <p class="muted" style="margin-top:6px;">Create staff accounts and control which modules each person can access.</p>
    </div>
    <a href="#addMember" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;padding:10px 16px;text-decoration:none;">
        + Add Team Member
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success" role="alert">✓ <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error" role="alert">⚠ <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Metric Cards -->
<div class="metric-grid" style="margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon lime">👥</div>
        <span>Team Members</span>
        <strong><?php echo count($team_members); ?></strong>
        <small>Total accounts in workspace</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon green">●</div>
        <span>Active</span>
        <strong><?php echo $total_active; ?></strong>
        <small>Can sign in right now</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon purple">★</div>
        <span>Managers</span>
        <strong><?php echo $total_managers; ?></strong>
        <small>Full workspace access</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon amber">⌛</div>
        <span>Disabled</span>
        <strong><?php echo max(0, count($team_members) - $total_active); ?></strong>
        <small>Accounts currently blocked</small>
    </div>
</div>
<!-- ============ Add / Edit Member Card ============ -->
<div class="form-card" id="addMember" style="padding:26px;margin-bottom:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--line);flex-wrap:wrap;">
        <div>
            <h3 style="margin:0;font-size:16px;"><?php echo $edit_member ? '✏️ Edit — ' . htmlspecialchars($edit_member['full_name']) : '➕ Add New Team Member'; ?></h3>
            <p class="muted" style="margin:3px 0 0;font-size:12.5px;">
                <?php echo $edit_member ? 'Update details, reset the password or change access.' : 'Create an account that can sign into this workspace with the access you choose.'; ?>
            </p>
        </div>
        <?php if ($edit_member): ?>
        <a href="team.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;font-size:12.5px;text-decoration:none;">← Back to list</a>
        <?php endif; ?>
    </div>

    <form method="POST" action="team.php">
        <input type="hidden" name="action" value="<?php echo $edit_member ? 'update' : 'create'; ?>">
        <?php if ($edit_member): ?>
        <input type="hidden" name="member_id" value="<?php echo (int)$edit_member['id']; ?>">
        <?php endif; ?>

        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:16px;">
            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input type="text" id="full_name" name="full_name" required maxlength="100"
                       placeholder="e.g. Abena Mensah"
                       value="<?php echo htmlspecialchars($edit_member['full_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" required maxlength="50"
                       placeholder="e.g. abena"
                       value="<?php echo htmlspecialchars($edit_member['username'] ?? ''); ?>">
                <small class="muted" style="display:block;margin-top:4px;">Letters, numbers and underscores.</small>
            </div>
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" required maxlength="100"
                       placeholder="abena@yourcompany.com"
                       value="<?php echo htmlspecialchars($edit_member['email'] ?? ''); ?>">
            </div>
        </div>

        <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
            <div class="form-group">
                <label for="role">Role</label>
                <select id="role" name="role" onchange="applyRolePreset(this.value)">
                    <?php foreach (teamRolePresets() as $rk => $preset): ?>
                    <option value="<?php echo $rk; ?>" <?php echo ($edit_member['role'] ?? 'manager') === $rk ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(teamRoleLabel($rk)); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="muted" style="display:block;margin-top:4px;">Choosing a role quickly pre-selects common access — you can still tick modules individually.</small>
            </div>
            <div class="form-group">
                <label for="password"><?php echo $edit_member ? 'New Password' : 'Password *'; ?></label>
                <input type="password" id="password" name="password" <?php echo $edit_member ? '' : 'required'; ?> minlength="6" maxlength="200"
                       autocomplete="new-password"
                       placeholder="<?php echo $edit_member ? 'Leave blank to keep current' : 'Minimum 6 characters'; ?>">
            </div>
        </div>

        <?php if ($edit_member): ?>
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:16px;">
            <input type="checkbox" name="is_active" value="1" <?php echo (int)$edit_member['is_active'] === 1 ? 'checked' : ''; ?>>
            Account is active (can sign in)
        </label>
        <?php endif; ?>
<div style="margin-bottom:8px;">
            <label style="font-size:13px;font-weight:700;color:var(--ink);">Module Access</label>
            <p class="muted" style="margin:2px 0 12px;font-size:12px;">Tick the modules this person can open. The Dashboard is always available.</p>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin-bottom:18px;">
            <?php foreach ($perm_labels as $pk => $plabel): ?>
            <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg, #f8fafc);font-size:13px;color:var(--ink);cursor:pointer;">
                <input type="checkbox" name="permissions[]" value="<?php echo $pk; ?>" class="perm-box"
                    <?php echo in_array($pk, $edit_perms, true) ? 'checked' : ''; ?>>
                <?php echo htmlspecialchars($plabel); ?>
            </label>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;">
                <?php echo $edit_member ? '✓ Save Changes' : '＋ Create Team Member'; ?>
            </button>
            <?php if ($edit_member): ?>
            <a href="team.php" class="btn btn-secondary" style="text-decoration:none;">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>
<!-- ============ Members List ============ -->
<div class="form-card" style="padding:22px;">
    <h3 style="margin:0 0 6px;font-size:16px;">📋 Current Team Members</h3>
    <p class="muted" style="margin:0 0 16px;font-size:12.5px;">Every member signs in at <strong>/admin/login.php</strong> using their username or email.</p>

    <?php if (!count($team_members)): ?>
    <p class="muted" style="font-size:13px;padding:14px 0;">No team members yet — add the first person with the form above.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="text-align:left;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:0.4px;border-bottom:1px solid var(--line);">
                <th style="padding:8px 10px;">Member</th>
                <th style="padding:8px 10px;">Username</th>
                <th style="padding:8px 10px;">Role</th>
                <th style="padding:8px 10px;">Access</th>
                <th style="padding:8px 10px;">Status</th>
                <th style="padding:8px 10px;">Last login</th>
                <th style="padding:8px 10px;text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($team_members as $tm_row):
                $perms = teamMemberParsePerms($tm_row['permissions']);
                $membership_label = ($tm_row['role'] === 'manager' && count($perms) === count($perm_labels))
                    ? 'All modules'
                    : (count($perms) ? implode(', ', array_map(function($pk) use ($perm_labels) { return $perm_labels[$pk]; }, $perms)) : 'Dashboard only');
            ?>
            <tr style="border-bottom:1px solid var(--line);vertical-align:middle;">
                <td style="padding:10px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="width:34px;height:34px;border-radius:9px;background:var(--primary-dark);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;">
                            <?php echo htmlspecialchars(strtoupper(mb_substr($tm_row['full_name'], 0, 1))); ?>
                        </span>
                        <span>
                            <strong style="color:var(--ink);display:block;"><?php echo htmlspecialchars($tm_row['full_name']); ?></strong>
                            <small class="muted" style="font-size:11px;"><?php echo htmlspecialchars($tm_row['email']); ?></small>
                        </span>
                    </div>
                </td>
                <td style="padding:10px;font-family:monospace;font-size:12px;color:#475569;"><?php echo htmlspecialchars($tm_row['username']); ?></td>
                <td style="padding:10px;">
                    <span style="font-size:11px;font-weight:700;padding:3px 9px;border-radius:99px;background:<?php echo $tm_row['role'] === 'manager' ? 'rgba(194,245,66,0.18)' : 'rgba(148,163,184,0.14)'; ?>;color:<?php echo $tm_row['role'] === 'manager' ? '#3f6212' : '#475569'; ?>;">
                        <?php echo htmlspecialchars(teamRoleLabel($tm_row['role'])); ?>
                    </span>
                </td>
                <td style="padding:10px;color:#64748b;font-size:12px;max-width:280px;"><?php echo htmlspecialchars($membership_label); ?></td>
                <td style="padding:10px;">
                    <?php if ((int)$tm_row['is_active'] === 1): ?>
                    <span class="status-badge-replied" style="font-size:11px;">● Active</span>
                    <?php else: ?>
                    <span class="status-badge-pending" style="font-size:11px;">● Disabled</span>
                    <?php endif; ?>
                </td>
                <td style="padding:10px;color:#64748b;font-size:12px;">
                    <?php echo !empty($tm_row['last_login_at']) ? date('M j, Y g:ia', strtotime($tm_row['last_login_at'])) : 'Never'; ?>
                </td>
                <td style="padding:10px;text-align:right;white-space:nowrap;">
                    <a href="team.php?edit=<?php echo (int)$tm_row['id']; ?>" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;font-size:12px;text-decoration:none;">Edit</a>
                    <?php if ((int)$tm_row['id'] !== $own_member_id): ?>
                    <form method="POST" action="team.php" style="display:inline-block;margin-left:4px;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="member_id" value="<?php echo (int)$tm_row['id']; ?>">
                        <button type="submit" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;cursor:pointer;">
                            <?php echo (int)$tm_row['is_active'] === 1 ? 'Disable' : 'Enable'; ?>
                        </button>
                    </form>
                    <form method="POST" action="team.php" style="display:inline-block;margin-left:4px;" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($tm_row['full_name'])); ?> from the team?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="member_id" value="<?php echo (int)$tm_row['id']; ?>">
                        <button type="submit" class="btn" style="padding:6px 12px;font-size:12px;background:transparent;border:1px solid #fecaca;color:#b91c1c;border-radius:6px;cursor:pointer;">Delete</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<script>
    (function () {
        'use strict';
        // Role presets -> permission checkboxes
        var presets = <?php echo json_encode($role_presets); ?>;
        function applyRolePreset(role) {
            var boxes = document.querySelectorAll('.perm-box');
            var chosen = presets[role] || [];
            for (var i = 0; i < boxes.length; i++) {
                boxes[i].checked = chosen.indexOf(boxes[i].value) !== -1;
            }
        }
        var roleSel = document.getElementById('role');
        if (roleSel) {
            roleSel.addEventListener('change', function () { applyRolePreset(roleSel.value); });
        }
    })();
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>