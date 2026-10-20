<?php
/**
 * Optibiz REST API - Customer (app user) bearer authentication.
 *
 * Separate from middleware/auth.php, which authenticates workspace
 * owners and staff. A workspace token must never be accepted as a
 * customer token and vice versa: the JWT `type` and `user_type` claims
 * are both checked, and the user's token_version is compared on every
 * request so password resets and deletions take effect immediately.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/jwt.php';
require_once RATE_ROOT_PATH . '/config/database.php';
require_once RATE_ROOT_PATH . '/includes/functions.php';
require_once RATE_ROOT_PATH . '/includes/app_auth.php';

if (!function_exists('api_bearer_token')) {
    function api_bearer_token()
    {
        $authHeader = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = trim((string) $_SERVER['HTTP_AUTHORIZATION']);
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = trim((string) ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }

        if ($authHeader === '' || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            return '';
        }
        return trim($m[1]);
    }
}

/**
 * Require a valid app-customer access token. Terminates with JSON on failure.
 *
 * @return array{user:array,token:string,claims:array}
 */
function api_require_app_user(mysqli $conn)
{
    $token = api_bearer_token();
    if ($token === '') {
        api_send_error('Sign in to continue.', 401);
    }

    $claims = JWT::verify($token, 'app_access');
    if (!$claims || ($claims['user_type'] ?? '') !== 'app_customer') {
        api_send_error('Your session has expired. Please sign in again.', 401);
    }
    if (JWT::isRevoked($conn, $token)) {
        api_send_error('Your session has been signed out. Please sign in again.', 401);
    }

    $user = optibiz_app_user_by_id($conn, (int) ($claims['sub'] ?? 0));
    if (!$user || ($user['status'] ?? '') !== 'active') {
        api_send_error('This account is not active. Please sign in again.', 401);
    }
    if ((int) ($claims['tv'] ?? -1) !== (int) ($user['token_version'] ?? 0)) {
        api_send_error('Your session has expired. Please sign in again.', 401);
    }

    return ['user' => $user, 'token' => $token, 'claims' => $claims];
}

/**
 * Optional authentication: returns the user when a valid token is
 * supplied, null when the request is anonymous, and exits only when a
 * token was supplied but is invalid.
 */
function api_optional_app_user(mysqli $conn)
{
    if (api_bearer_token() === '') {
        return null;
    }
    $auth = api_require_app_user($conn);
    return $auth['user'];
}

/** Record/update the device that is signed in, for push notifications. */
function api_register_device(mysqli $conn, int $userId, array $input)
{
    optibiz_app_ensure_schema($conn);

    $deviceId = trim((string) ($input['device_id'] ?? ''));
    if ($deviceId === '' || strlen($deviceId) > 200) {
        return false;
    }
    $deviceHash = hash('sha256', 'device|' . $deviceId);
    $platform = strtolower(trim((string) ($input['platform'] ?? 'other')));
    if (!in_array($platform, ['android', 'ios', 'web', 'other'], true)) {
        $platform = 'other';
    }
    $fcm = trim((string) ($input['fcm_token'] ?? ''));
    if (strlen($fcm) > 512) {
        $fcm = substr($fcm, 0, 512);
    }
    $fcmOrNull = $fcm !== '' ? $fcm : null;
    $version = substr(trim((string) ($input['app_version'] ?? '')), 0, 32);

    $stmt = $conn->prepare("INSERT INTO app_devices (user_id, device_hash, platform, fcm_token, app_version, last_seen_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE platform = VALUES(platform), fcm_token = VALUES(fcm_token),
            app_version = VALUES(app_version), last_seen_at = NOW()");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('issss', $userId, $deviceHash, $platform, $fcmOrNull, $version);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool) $ok;
}
