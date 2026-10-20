<?php
/**
 * POST /api/v1/auth/customer/login.php
 *
 * Email or phone + password. Every failure returns the same message so
 * the endpoint cannot be used to discover which accounts exist.
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';
api_app_require_post();

$ip = api_get_client_ip();
RateLimiter::check($conn, 'app_login', $ip, 20, 600); // 20 attempts / 10 min / IP

$input = api_get_request_body();
$identifier = trim((string) ($input['email'] ?? $input['phone'] ?? $input['identifier'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    api_send_error('Enter your email or phone number and password.', 422);
}

$email = strpos($identifier, '@') !== false ? optibiz_app_email($identifier) : '';
$phone = $email === '' ? optibiz_app_phone($identifier) : '';

$user = optibiz_app_find_user($conn, $email, $phone);

if (!$user || empty($user['password_hash']) || !optibiz_login_check($conn, 'app_users', (int) $user['id'], $password, (string) $user['password_hash'])) {
    // Spend the same PBKDF2 work whether or not the account exists.
    if (!$user || empty($user['password_hash'])) {
        optibiz_password_dummy_verify($password);
    }
    api_send_error('Incorrect email/phone or password. If you created your account with Google, use “Continue with Google”.', 401);
}

if (($user['status'] ?? '') !== 'active') {
    api_send_error('This account is suspended. Contact support for help.', 403);
}

$payload = api_app_login_payload($conn, $user, $input);
api_send_success($payload, 'Signed in successfully.', 200);
