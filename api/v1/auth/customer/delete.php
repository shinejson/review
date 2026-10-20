<?php
/**
 * POST /api/v1/auth/customer/delete.php
 *
 * In-app account deletion (required by Google Play). Two proofs are
 * accepted:
 *   - { "password": "...", "confirmation": "DELETE" }
 *   - { "delete_token": "...", "confirmation": "DELETE" }   (from a verified
 *     email/SMS code, which is the only option for Google-only accounts)
 *
 * The account is anonymised, every session is invalidated, and reviews
 * remain in the business's history without any personal identifiers.
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';
api_app_require_post();

$input = api_get_request_body();
$deleteToken = trim((string) ($input['delete_token'] ?? ''));

// The app sends a bearer token. The public deletion page (for people who
// no longer have the app installed) sends only the short-lived token that
// a verified email/SMS code produced, which proves ownership by itself.
$auth = api_optional_app_user($conn);
if ($auth) {
    $user = $auth['user'];
} elseif ($deleteToken !== '') {
    $claims = JWT::verify($deleteToken, 'app_delete');
    if (!$claims
        || ($claims['user_type'] ?? '') !== 'app_customer'
        || ($claims['purpose'] ?? '') !== 'account_deletion'
    ) {
        api_send_error('That deletion confirmation has expired. Request a new code.', 401);
    }
    $user = optibiz_app_user_by_id($conn, (int) ($claims['sub'] ?? 0));
    if (!$user || (int) ($claims['tv'] ?? -1) !== (int) ($user['token_version'] ?? 0)) {
        api_send_error('That deletion confirmation has expired. Request a new code.', 401);
    }
} else {
    api_send_error('Sign in, or confirm with a code sent to your email or phone.', 401);
}

RateLimiter::check($conn, 'app_delete_account', api_get_client_ip(), 8, 3600);

$confirmation = strtoupper(trim((string) ($input['confirmation'] ?? '')));
if ($confirmation !== 'DELETE') {
    api_send_error('Type DELETE to confirm that you want to delete your account.', 422, [
        'confirmation' => 'Send confirmation: "DELETE".',
    ]);
}

$password = (string) ($input['password'] ?? '');
$proven = false;

if ($deleteToken !== '') {
    $claims = JWT::verify($deleteToken, 'app_delete');
    if (!$claims
        || ($claims['user_type'] ?? '') !== 'app_customer'
        || ($claims['purpose'] ?? '') !== 'account_deletion'
        || (int) ($claims['sub'] ?? 0) !== (int) $user['id']
        || (int) ($claims['tv'] ?? -1) !== (int) ($user['token_version'] ?? 0)
        || JWT::isRevoked($conn, $deleteToken)
    ) {
        api_send_error('That deletion confirmation has expired. Request a new code.', 401);
    }
    $proven = true;
} elseif ($password !== '') {
    if (empty($user['password_hash'])) {
        api_send_error('This account uses Google Sign-In. Confirm deletion with a code sent to your email or phone.', 422);
    }
    if (!optibiz_login_check($conn, 'app_users', (int) $user['id'], $password, (string) $user['password_hash'])) {
        api_send_error('That password is not correct.', 401);
    }
    $proven = true;
}

if (!$proven) {
    api_send_error('Confirm with your password or a verification code.', 422);
}

$reason = (string) ($input['reason'] ?? '');

$result = optibiz_app_delete_account($conn, (int) $user['id'], $reason, 'app');
if (empty($result['success'])) {
    api_send_error((string) ($result['message'] ?? 'Could not delete the account.'), 500);
}

if ($deleteToken !== '') {
    $claims = JWT::verify($deleteToken, 'app_delete');
    if ($claims) {
        JWT::revoke($conn, $deleteToken, 'app_delete', 0, (int) ($claims['exp'] ?? 0));
    }
}
if ($auth) {
    JWT::revoke($conn, $auth['token'], 'app_access', 0, time() + API_JWT_APP_ACCESS_EXPIRY);
}

api_send_success(null, 'Your account and personal data have been deleted.', 200);
