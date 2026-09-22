<?php
/**
 * Sign the super admin out and return to the login screen.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    auth_session_start();
}

// Close the user_sessions row in the database
if (function_exists('auth_session_logout')) {
    auth_session_logout($conn, 'user');
}

// Completely clear the session data array
$_SESSION = [];

// Expire the PHP session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $expiry = time() - 42000;
    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', [
            'expires'  => $expiry,
            'path'     => $params['path'] ?? '/',
            'domain'   => $params['domain'] ?? '',
            'secure'   => $params['secure'] ?? false,
            'httponly' => $params['httponly'] ?? true,
            'samesite' => isset($params['samesite']) ? $params['samesite'] : 'Lax',
        ]);
    } else {
        setcookie(session_name(), '', $expiry, $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }
}

// Destroy the session storage on server
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_unset();
    @session_destroy();
}

header('Location: login.php?signed_out=1');
exit();

