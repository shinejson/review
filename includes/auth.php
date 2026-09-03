<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Checks if a tenant or admin is currently logged in
 */
function isLoggedIn() {
    return (isset($_SESSION['tenant_id']) && !empty($_SESSION['tenant_id'])) ||
           (isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']));
}

/**
 * Checks if current session belongs to a Tenant
 */
function isTenant() {
    return isset($_SESSION['tenant_id']) && !empty($_SESSION['tenant_id']);
}

/**
 * Checks if current session belongs to a Global Admin
 */
function isAdmin() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Get active Tenant ID (or null if not a tenant)
 */
function getTenantId() {
    return $_SESSION['tenant_id'] ?? null;
}

/**
 * Get display name for logged-in user or company
 */
function getCurrentUserName() {
    if (isset($_SESSION['tenant_name'])) {
        return $_SESSION['tenant_name'];
    }
    if (isset($_SESSION['admin_username'])) {
        return $_SESSION['admin_username'];
    }
    return 'User';
}

/**
 * Enforce login for Admin / Tenant area
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
    
    // If tenant, verify subscription is not cancelled or inactive
    if (isTenant() && isset($_SESSION['tenant_status'])) {
        if ($_SESSION['tenant_status'] === 'inactive' || $_SESSION['tenant_status'] === 'cancelled') {
            // Can allow read-only or redirect to payment notice
        }
    }
}

/**
 * Super Admin Auth Helpers
 */
function isSuperAdminLoggedIn() {
    return isset($_SESSION['super_admin_id']) && !empty($_SESSION['super_admin_id']);
}

function requireSuperAdminLogin() {
    if (!isSuperAdminLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

/* ============================================================
 *  Super Admin — permission-based user roles
 * ============================================================
 *  Every super admin account may be limited to certain sections
 *  of the control center. The first account is the platform
 *  owner: it always keeps full access. Accounts without a saved
 *  permission list (legacy accounts) also keep full access.
 */

/**
 * Permission catalogue: key => label. Used by the sidebar and
 * the Users & roles screen.
 */
function sa_permission_list() {
    return [
        'dashboard'     => 'Dashboard',
        'analytics'     => 'Analytics',
        'tenants'       => 'Tenants',
        'subscriptions' => 'Subscriptions',
        'plans'         => 'Plans',
        'quotes'        => 'Quote requests',
        'customers'     => 'Customers',
        'categories'    => 'Categories',
        'users'         => 'Users & roles',
        'settings'      => 'Settings',
    ];
}

/**
 * Make sure the super_admins table has the permission columns
 * (auto-migration so existing installs keep working without a
 * manual SQL update). Runs at most once per request.
 */
function sa_ensure_user_schema($conn) {
    static $done = false;
    if ($done || !is_object($conn) || !method_exists($conn, 'query')) return;
    $done = true;

    $cols = [];
    $res = @$conn->query('SHOW COLUMNS FROM super_admins');
    if (!$res) return; // table not installed yet
    while ($row = $res->fetch_assoc()) { $cols[] = $row['Field']; }
    $res->close();

    $alters = [];
    if (!in_array('permissions', $cols, true)) $alters[] = 'ADD COLUMN permissions TEXT NULL';
    if (!in_array('is_owner', $cols, true))    $alters[] = 'ADD COLUMN is_owner TINYINT(1) NOT NULL DEFAULT 0';
    if ($alters) @$conn->query('ALTER TABLE super_admins ' . implode(', ', $alters));

    // The very first super admin account becomes the platform owner.
    $res = @$conn->query('SELECT COUNT(*) AS c FROM super_admins WHERE is_owner = 1');
    if ($res) {
        $row = $res->fetch_assoc();
        $res->close();
        if ((int) $row['c'] === 0) {
            @$conn->query('UPDATE super_admins SET is_owner = 1 ORDER BY id ASC LIMIT 1');
        }
    }
}

/**
 * Row for the signed-in super admin (cached per request),
 * or false when not signed in.
 */
function sa_current_admin($conn = null) {
    static $cache = null, $loaded = false;
    if ($loaded) return $cache;
    $loaded = true;
    $cache = false;

    if (!isSuperAdminLoggedIn()) return $cache;
    if ($conn === null && isset($GLOBALS['conn'])) $conn = $GLOBALS['conn'];
    if (!is_object($conn) || !method_exists($conn, 'query')) return $cache;

    sa_ensure_user_schema($conn);

    $id = (int) $_SESSION['super_admin_id'];
    $res = @$conn->query('SELECT id, username, email, is_owner, permissions FROM super_admins WHERE id = ' . $id);
    if ($res) {
        $cache = $res->fetch_assoc() ?: false;
        $res->close();
    }
    return $cache;
}

/** True when the signed-in super admin is the platform owner. */
function sa_is_owner($conn = null) {
    $user = sa_current_admin($conn);
    return $user !== false && (int) $user['is_owner'] === 1;
}

/** Permission keys granted to the signed-in super admin. */
function sa_user_permissions($conn = null) {
    $all  = array_keys(sa_permission_list());
    $user = sa_current_admin($conn);
    if (!$user) return [];
    if ((int) $user['is_owner'] === 1) return $all;

    $perms = json_decode((string) (isset($user['permissions']) ? $user['permissions'] : ''), true);
    if (!is_array($perms)) return $all; // legacy / untouched account
    return array_values(array_intersect($perms, $all));
}

/** May the signed-in super admin open the given section? */
function sa_can($permission, $conn = null) {
    return in_array($permission, sa_user_permissions($conn), true);
}

/**
 * Block the current page unless the signed-in super admin holds
 * the permission. Redirects to the dashboard, except on the
 * dashboard itself where a small "no access" notice is shown.
 */
function require_sa_permission($permission, $conn = null) {
    if (sa_can($permission, $conn)) return;

    $script = basename((string) (isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : ''));
    if ($script === 'index.php') {
        http_response_code(403);
        echo '<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;'
           . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;'
           . 'background:#0f2438;color:#94a3b8;text-align:center;padding:24px;">'
           . '<div><h2 style="color:#eef2f7;font-size:22px;margin:0 0 8px;">No access to this area</h2>'
           . '<p style="margin:0;">Your account does not include the &ldquo;'
           . htmlspecialchars($permission) . '&rdquo; permission. Ask the platform owner for access.</p></div></div>';
        exit();
    }
    header('Location: index.php');
    exit();
}
?>
