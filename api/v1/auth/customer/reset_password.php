<?php
/**
 * POST /api/v1/auth/customer/reset_password.php
 *
 * Body: { "reset_token": "...", "new_password": "..." }
 *
 * The reset token comes from a verified email/SMS code. It is single-use,
 * expires in 15 minutes, and is bound to the account's token version.
 * A successful reset invalidates every existing session on every device.
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';
api_app_require_post();

RateLimiter::check($conn, 'app_password_reset', api_get_client_ip(), 10, 900);

$input = api_get_request_body();
$token = trim((string) ($input['reset_token'] ?? ''));
$newPassword = (string) ($input['new_password'] ?? $input['password'] ?? '');

if ($token === '' || $newPassword === '') {
    api_send_error('A reset token and a new password are required.', 422);
}

$claims = JWT::verify($token, 'app_password_reset');
if (!$claims || ($claims['user_type'] ?? '') !== 'app_customer' || ($claims['purpose'] ?? '') !== 'password_reset') {
    api_send_error('This reset link has expired. Request a new code.', 401);
}
if (JWT::isRevoked($conn, $token)) {
    api_send_error('This reset link has already been used. Request a new code.', 401);
}

$user = optibiz_app_user_by_id($conn, (int) ($claims['sub'] ?? 0));
if (!$user || ($user['status'] ?? '') !== 'active') {
    api_send_error('This account is not available. Contact support.', 401);
}
if ((int) ($claims['tv'] ?? -1) !== (int) ($user['token_version'] ?? 0)) {
    api_send_error('This reset link has expired. Request a new code.', 401);
}

$policy = optibiz_password_policy_errors($newPassword, (string) ($user['email'] ?? ''), (string) ($user['full_name'] ?? ''));
if ($policy) {
    api_send_error(implode(' ', $policy), 422, ['password' => $policy]);
}

$hash = optibiz_hash_password($newPassword);
if ($hash === null) {
    api_send_error('That password cannot be used. Please choose another one.', 422);
}

$stmt = $conn->prepare("UPDATE app_users SET password_hash = ?, token_version = token_version + 1 WHERE id = ? LIMIT 1");
if (!$stmt) {
    api_send_error('Could not reset the password. Please try again.', 500);
}
$userId = (int) $user['id'];
$stmt->bind_param('si', $hash, $userId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    api_send_error('Could not reset the password. Please try again.', 500);
}

JWT::revoke($conn, $token, 'app_password_reset', 0, (int) ($claims['exp'] ?? 0));

$fresh = optibiz_app_user_by_id($conn, $userId);
$payload = api_app_login_payload($conn, $fresh, $input);

api_send_success($payload, 'Password updated. Every other device has been signed out.', 200);
