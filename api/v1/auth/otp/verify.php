<?php
/**
 * POST /api/v1/auth/otp/verify.php
 *
 * Body: { channel, destination, purpose, code, name?, password?, device_id? }
 *
 * - login          → access + refresh tokens
 * - recovery       → a 15-minute reset token (not a session)
 * - delete_account → a 15-minute deletion token
 * - signup         → creates the account (name + password required)
 * - verify_phone   → marks the phone/email verified and signs in
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';
api_app_require_post();

RateLimiter::check($conn, 'otp_verify', api_get_client_ip(), 40, 600);

$input = api_get_request_body();
$channel = (string) ($input['channel'] ?? 'email');
$destination = (string) ($input['destination'] ?? $input['email'] ?? $input['phone'] ?? '');
$purpose = (string) ($input['purpose'] ?? 'login');
$code = (string) ($input['code'] ?? '');

$result = optibiz_otp_verify($conn, $channel, $destination, $purpose, $code);
if (empty($result['success'])) {
    api_send_error((string) ($result['message'] ?? 'That code is not valid.'), (int) ($result['status'] ?? 400));
}

$user = $result['user'] ?? null;
$purpose = strtolower($purpose);

if ($purpose === 'signup') {
    if ($user) {
        api_send_error('An account with those details already exists. Sign in instead.', 409);
    }
    list($channelNorm, $destinationNorm) = optibiz_otp_normalise($channel, $destination);
    $name = trim((string) ($input['name'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    if ($name === '' || strlen($name) < 2) {
        api_send_error('Enter your name to finish creating the account.', 422, ['name' => 'Your name is required.']);
    }
    $policy = optibiz_password_policy_errors($password, $channelNorm === 'email' ? $destinationNorm : '', $name);
    if ($policy) {
        api_send_error(implode(' ', $policy), 422, ['password' => $policy]);
    }

    $user = optibiz_app_create_user($conn, [
        'full_name'       => $name,
        'email'           => $channelNorm === 'email' ? $destinationNorm : '',
        'phone'           => $channelNorm === 'sms' ? $destinationNorm : '',
        'password'        => $password,
        'email_verified'  => $channelNorm === 'email',
        'phone_verified'  => $channelNorm === 'sms',
    ]);
    if (!is_array($user)) {
        api_send_error(is_string($user) ? $user : 'Could not create the account.', 422);
    }

    $payload = api_app_login_payload($conn, $user, $input);
    api_send_success($payload, 'Account created and verified.', 201);
}

if (!$user) {
    api_send_error('That code is no longer valid. Request a new one.', 401);
}

if ($purpose === 'recovery') {
    api_send_success([
        'reset_token' => api_app_issue_reset_token($user),
        'expires_in'  => 900,
        'user'        => optibiz_app_public_user($user),
    ], 'Code verified. Choose a new password.', 200);
}

if ($purpose === 'delete_account') {
    api_send_success([
        'delete_token' => api_app_issue_delete_token($user),
        'expires_in'   => 900,
        'user'         => optibiz_app_public_user($user),
    ], 'Code verified. Confirm deletion in the app to finish.', 200);
}

if ($purpose === 'verify_phone') {
    list($channelNorm) = optibiz_otp_normalise($channel, $destination);
    $column = $channelNorm === 'sms' ? 'phone_verified_at' : 'email_verified_at';
    $stmt = $conn->prepare("UPDATE app_users SET {$column} = NOW() WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $stmt->close();
    }
    $user = optibiz_app_user_by_id($conn, (int) $user['id']);
}

if ($purpose === 'login' || $purpose === 'verify_phone') {
    $payload = api_app_login_payload($conn, $user, $input);
    api_send_success($payload, 'Signed in successfully.', 200);
}

api_send_success(['user' => optibiz_app_public_user($user)], 'Code verified.', 200);
