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

$sa_tmp = sys_get_temp_dir() . '/sa-php-preview';
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
}
if (empty($_SESSION['sa_csrf'])) {
    $_SESSION['sa_csrf'] = 'preview-csrf-token';
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
