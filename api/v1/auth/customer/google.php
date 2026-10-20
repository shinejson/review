<?php
/**
 * POST /api/v1/auth/customer/google.php
 *
 * Body: { "id_token": "<Google ID token>", "device_id": "...", "platform": "android" }
 *
 * The ID token is verified against Google's JWKS, issuer and audience in
 * includes/google_auth.php. Only then is the account created or linked.
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';
require_once RATE_ROOT_PATH . '/includes/google_auth.php';
api_app_require_post();

RateLimiter::check($conn, 'app_google', api_get_client_ip(), 30, 600);

$input = api_get_request_body();
$idToken = trim((string) ($input['id_token'] ?? $input['credential'] ?? ''));
if ($idToken === '') {
    api_send_error('A Google credential is required.', 422);
}

$verification = optibiz_google_verify_id_token($idToken);
if (empty($verification['success'])) {
    api_send_error($verification['message'], (int) ($verification['status'] ?? 401));
}
$claims = $verification['claims'];

$user = optibiz_app_user_by_google($conn, $claims['sub']);

if (!$user) {
    $existing = optibiz_app_find_user($conn, $claims['email'], '');

    if ($existing) {
        // Link only when no other Google identity is attached. Google has
        // verified this email address, so this is a safe account link.
        if (!empty($existing['google_sub']) && $existing['google_sub'] !== $claims['sub']) {
            api_send_error('This email address is already linked to a different Google account.', 409);
        }
        $stmt = $conn->prepare("UPDATE app_users
            SET google_sub = ?, email_verified_at = COALESCE(email_verified_at, NOW()),
                avatar_url = COALESCE(NULLIF(avatar_url, ''), ?),
                full_name = CASE WHEN full_name = '' OR full_name = 'Customer' THEN ? ELSE full_name END
            WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('sssi', $claims['sub'], $claims['picture'], $claims['name'], $existing['id']);
            $stmt->execute();
            $stmt->close();
        }
        $user = optibiz_app_user_by_id($conn, (int) $existing['id']);
    } else {
        $user = optibiz_app_create_user($conn, [
            'full_name'      => $claims['name'] !== '' ? $claims['name'] : 'Google user',
            'email'          => $claims['email'],
            'google_sub'     => $claims['sub'],
            'avatar_url'     => $claims['picture'],
            'email_verified' => true,
        ]);
    }
}

if (!is_array($user)) {
    api_send_error(is_string($user) ? $user : 'Could not sign in with Google.', 422);
}
if (($user['status'] ?? '') !== 'active') {
    api_send_error('This account is suspended. Contact support for help.', 403);
}

$payload = api_app_login_payload($conn, $user, $input);
api_send_success($payload, 'Signed in with Google.', 200);
