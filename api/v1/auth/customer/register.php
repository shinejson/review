<?php
/**
 * POST /api/v1/auth/customer/register.php
 *
 * Creates a customer account on the server (the source of truth) and
 * returns a session. The app stores the tokens, not the password.
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';
api_app_require_post();

$ip = api_get_client_ip();
RateLimiter::check($conn, 'app_register', $ip, 10, 3600); // 10 accounts / hour / IP

$input = api_get_request_body();

$name = trim((string) ($input['name'] ?? $input['full_name'] ?? ''));
$email = optibiz_app_email($input['email'] ?? '');
$phone = optibiz_app_phone($input['phone'] ?? '');
$password = (string) ($input['password'] ?? '');

if ($name === '' || strlen($name) < 2) {
    api_send_error('Enter your name.', 422, ['name' => 'Your name is required.']);
}
if ($email === '' && $phone === '') {
    api_send_error('Enter an email address or phone number.', 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_send_error('Enter a valid email address.', 422, ['email' => 'That email address is not valid.']);
}

$policyErrors = optibiz_password_policy_errors($password, $email, $name);
if ($policyErrors) {
    api_send_error(implode(' ', $policyErrors), 422, ['password' => $policyErrors]);
}

if (optibiz_app_find_user($conn, $email, $phone)) {
    api_send_error('An account with those details already exists. Sign in instead.', 409);
}

$user = optibiz_app_create_user($conn, [
    'full_name'       => $name,
    'email'           => $email,
    'phone'           => $phone,
    'password'        => $password,
    'marketing_opt_in' => !empty($input['marketing_opt_in']),
]);

if (!is_array($user)) {
    api_send_error(is_string($user) ? $user : 'Could not create the account.', 422);
}

$payload = api_app_login_payload($conn, $user, $input);
api_send_success($payload, 'Account created.', 201);
