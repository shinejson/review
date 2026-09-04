<?php
/**
 * Prepares a CLI/WebAssembly PHP environment so the real superadmin
 * pages can be rendered without Apache or MySQL.
 *
 * Used only by tools/render-php-preview.js (passed via
 * -d auto_prepend_file). It:
 *   - enables cookieless sessions and signs in a super admin
 *   - turns QUERY_STRING into $_GET, like a web server would
 *   - fills in the $_SERVER keys the templates read
 */

// Each harness run gets its own session directory (SA_SESSION_DIR), so a
// login performed by one case cannot leak into the next one.
$sa_tmp = getenv('SA_SESSION_DIR');
if (!$sa_tmp) {
    $sa_tmp = sys_get_temp_dir() . '/sa-php-preview-' . getmypid();
}
if (!is_dir($sa_tmp)) {
    @mkdir($sa_tmp, 0777, true);
}
ini_set('session.save_path', $sa_tmp);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
ini_set('session.use_strict_mode', '0');
ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (getenv('SA_ANONYMOUS') === '1') {
    // signed out entirely
    unset($_SESSION['super_admin_id'], $_SESSION['admin_id']);
} else {
    if (getenv('SA_NO_SUPER') !== '1') {
        $_SESSION['super_admin_id'] = 1;
    } else {
        unset($_SESSION['super_admin_id']);   // tenant admin only
    }
    $_SESSION['admin_id'] = (int) (getenv('SA_ADMIN_ID') ?: 1);
    // The tenant admin panel also supports signing in as the tenant itself
    if (getenv('SA_TENANT_ID')) {
        $_SESSION['tenant_id'] = (int) getenv('SA_TENANT_ID');
        $_SESSION['tenant_name'] = 'Volta Logistics';
        $_SESSION['user_type'] = 'tenant';
    }
}
if (empty($_SESSION['sa_csrf'])) {
    $_SESSION['sa_csrf'] = 'preview-csrf-token';
}

/* Session tracking (includes/session.php): the harness signs in through
   these variables instead of a login form, so it has to look like a
   session that login.php recorded — a known row in `user_sessions`
   (see tools/php/dataset.php), a start time inside the limits and a
   sign-out token the logout endpoints can check. */
if (!getenv('SA_ANONYMOUS')) {
    $_SESSION['session_token']        = getenv('SA_SESSION_TOKEN') ?: 'preview-session-token';
    $_SESSION['session_portal']       = getenv('SA_NO_SUPER') === '1' ? 'admin' : 'superadmin';
    $_SESSION['session_started_at']   = time() - (int) (getenv('SA_SESSION_AGE') ?: 600);
    $_SESSION['session_last_seen_at'] = time() - (int) (getenv('SA_SESSION_IDLE') ?: 30);
    $_SESSION['session_last_touch']   = time();
    $_SESSION['logout_token']         = getenv('SA_LOGOUT_TOKEN') ?: 'preview-logout-token';
}

/* SA_SESSION_DUMP=/path writes what is left of $_SESSION when the script
   finishes, which is how the suites prove a sign-out really cleared it. */
if (getenv('SA_SESSION_DUMP')) {
    register_shutdown_function(function () {
        @file_put_contents(getenv('SA_SESSION_DUMP'), json_encode(isset($_SESSION) ? $_SESSION : null));
    });
}

$sa_qs = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
if ($sa_qs !== '') {
    parse_str($sa_qs, $_GET);
}

$sa_script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/superadmin/index.php';

// POST cases: the harness sends the body as a query string
if (getenv('SA_POST') === '1') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $_GET;
    if (getenv('SA_BAD_CSRF') !== '1') {
        $_POST['csrf_token'] = $_SESSION['sa_csrf'];
    } else {
        $_POST['csrf_token'] = 'forged-token';
    }
} else {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
$_SERVER['REQUEST_URI'] = $sa_script . ($sa_qs !== '' ? '?' . $sa_qs : '');
if (!isset($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] === '') {
    $_SERVER['HTTP_HOST'] = 'localhost';
}
