<?php
/**
 * POST /api/v1/auth/customer/refresh.php
 *
 * Rotates a refresh token. The old token is revoked immediately, so a
 * replayed refresh token fails once the legitimate client has used it.
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';
api_app_require_post();

RateLimiter::check($conn, 'app_refresh', api_get_client_ip(), 60, 3600);

$input = api_get_request_body();
$token = trim((string) ($input['refresh_token'] ?? ''));
if ($token === '') {
    api_send_error('A refresh token is required.', 422);
}

$claims = JWT::verify($token, 'app_refresh');
if (!$claims || ($claims['user_type'] ?? '') !== 'app_customer') {
    api_send_error('This session has expired. Please sign in again.', 401);
}
if (JWT::isRevoked($conn, $token)) {
    api_send_error('This session has already been used. Please sign in again.', 401);
}

$user = optibiz_app_user_by_id($conn, (int) ($claims['sub'] ?? 0));
if (!$user || ($user['status'] ?? '') !== 'active') {
    api_send_error('This account is no longer active.', 401);
}
if ((int) ($claims['tv'] ?? -1) !== (int) ($user['token_version'] ?? 0)) {
    api_send_error('This session has expired. Please sign in again.', 401);
}

JWT::revoke($conn, $token, 'app_refresh', 0, (int) ($claims['exp'] ?? 0));

$fresh = optibiz_app_user_by_id($conn, (int) $user['id']) ?: $user;
api_send_success([
    'user'   => optibiz_app_public_user($fresh),
    'tokens' => optibiz_app_issue_tokens($fresh),
], 'Session refreshed.', 200);
