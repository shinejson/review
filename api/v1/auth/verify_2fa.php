<?php
/**
 * Optibiz REST API - POST /api/v1/auth/verify_2fa.php
 * Verifies 2FA code (TOTP, Email OTP, or Backup Code) and issues JWT tokens
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/jwt.php';
require_once dirname(__DIR__) . '/helpers/rate_limiter.php';
require_once RATE_ROOT_PATH . '/config/database.php';
require_once RATE_ROOT_PATH . '/includes/two_factor.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$ip = api_get_client_ip();
RateLimiter::check($conn, 'auth_2fa_verify', $ip, 20, 600);

$input = api_get_request_body();
$tempToken = trim($input['temp_token'] ?? '');
$code      = trim($input['code'] ?? '');

if (empty($tempToken) || empty($code)) {
    api_send_error('Missing required fields: temp_token and code are required.', 422, [
        'temp_token' => empty($tempToken) ? 'Temporary session token is required.' : null,
        'code'       => empty($code) ? 'Verification code is required.' : null,
    ]);
}

// Verify temp token
$claims = JWT::verify($tempToken, '2fa_temp');
if (!$claims) {
    api_send_error('Invalid or expired 2FA session token. Please sign in again.', 401);
}

$userType = $claims['user_type'] ?? 'tenant';
$userId   = (int)($claims['sub'] ?? 0);

$usedBackup = false;
$verified = tfa_verify_code($conn, $userType, $userId, $code, $usedBackup);

if (!$verified) {
    api_send_error('Invalid 2FA verification code. Please check your code and try again.', 401);
}

// If team member, record last login
if ($userType === 'team_member' && !empty($claims['team_member_id'])) {
    $tmId = (int)$claims['team_member_id'];
    $ll = $conn->prepare("UPDATE team_members SET last_login_at = NOW() WHERE id = ?");
    if ($ll) {
        $ll->bind_param("i", $tmId);
        $ll->execute();
        $ll->close();
    }
}

// Generate full JWT tokens
$tokenClaims = [
    'sub'          => (int)$claims['tenant_id'],
    'tenant_id'    => (int)$claims['tenant_id'],
    'company_id'   => (int)($claims['company_id'] ?? 0),
    'public_id'    => (string)($claims['public_id'] ?? ''),
    'email'        => (string)($claims['email'] ?? ''),
    'role'         => (string)($claims['role'] ?? 'tenant_admin'),
];

$accessToken  = JWT::generate($tokenClaims, API_JWT_ACCESS_EXPIRY, 'access');
$refreshClaims = [
    'sub'             => (int)$claims['tenant_id'],
    'tenant_id'       => (int)$claims['tenant_id'],
    'role'            => (string)($claims['role'] ?? 'tenant_admin'),
    'team_member_id'  => (int)($claims['team_member_id'] ?? 0),
];
$refreshToken = JWT::generate($refreshClaims, API_JWT_REFRESH_EXPIRY, 'refresh');

api_send_success([
    'user' => [
        'id'                  => (int)$claims['tenant_id'],
        'public_id'           => (string)($claims['public_id'] ?? ''),
        'company_id'          => (int)($claims['company_id'] ?? 0),
        'company_name'        => (string)($claims['company_name'] ?? ''),
        'email'               => (string)($claims['email'] ?? ''),
        'username'            => (string)($claims['username'] ?? ''),
        'role'                => (string)($claims['role'] ?? 'tenant_admin'),
        'subscription_status' => (string)($claims['subscription_status'] ?? 'active'),
        'plan_id'             => (int)($claims['plan_id'] ?? 1),
        'logo'                => (string)($claims['logo'] ?? ''),
    ],
    'tokens' => [
        'access_token'  => $accessToken,
        'refresh_token' => $refreshToken,
        'token_type'    => 'Bearer',
        'expires_in'    => API_JWT_ACCESS_EXPIRY,
    ],
    'used_backup_code' => $usedBackup
], 'Authentication successful', 200);
