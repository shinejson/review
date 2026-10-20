<?php
/**
 * Shared bootstrap for the app-customer endpoints (auth, profile,
 * deletion). Workspace endpoints keep using api/v1/config.php directly.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/jwt.php';
require_once dirname(__DIR__) . '/helpers/rate_limiter.php';
require_once dirname(__DIR__) . '/middleware/customer_auth.php';
require_once RATE_ROOT_PATH . '/config/database.php';
require_once RATE_ROOT_PATH . '/includes/app_auth.php';
require_once RATE_ROOT_PATH . '/includes/app_otp.php';

if (!function_exists('api_app_require_post')) {
    function api_app_require_post()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            api_send_error('Method Not Allowed. Use POST.', 405);
        }
    }
}

if (!function_exists('api_app_login_payload')) {
    /**
     * Complete a successful sign-in: record it, register/refresh the
     * device, issue tokens and return the public user.
     */
    function api_app_login_payload(mysqli $conn, array $user, array $input = [])
    {
        optibiz_app_touch_login($conn, (int) $user['id']);
        if (!empty($input['device_id'])) {
            api_register_device($conn, (int) $user['id'], $input);
        }

        // Re-read so last_login_at is current in the response.
        $fresh = optibiz_app_user_by_id($conn, (int) $user['id']) ?: $user;

        return [
            'user'   => optibiz_app_public_user($fresh),
            'tokens' => optibiz_app_issue_tokens($fresh),
        ];
    }
}

if (!function_exists('api_app_issue_reset_token')) {
    /** Short-lived, single-purpose token for the password reset step. */
    function api_app_issue_reset_token(array $user)
    {
        return JWT::generate([
            'sub'       => (int) $user['id'],
            'user_type' => 'app_customer',
            'tv'        => (int) ($user['token_version'] ?? 0),
            'purpose'   => 'password_reset',
        ], 900, 'app_password_reset');
    }
}

if (!function_exists('api_app_issue_delete_token')) {
    /** Short-lived proof that the person controls the account's email/phone. */
    function api_app_issue_delete_token(array $user)
    {
        return JWT::generate([
            'sub'       => (int) $user['id'],
            'user_type' => 'app_customer',
            'tv'        => (int) ($user['token_version'] ?? 0),
            'purpose'   => 'account_deletion',
        ], 900, 'app_delete');
    }
}
