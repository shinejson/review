<?php
/**
 * Optibiz REST API - POST /api/v1/auth/login.php
 * Tenant Admin Authentication, Password Verification & JWT Issuance
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/jwt.php';
require_once dirname(__DIR__) . '/helpers/rate_limiter.php';
require_once RATE_ROOT_PATH . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$ip = api_get_client_ip();

// Rate limit login attempts to protect against credential stuffing
RateLimiter::check($conn, 'auth_login', $ip, 15, 600); // 15 attempts / 10 min

$input = api_get_request_body();
$username = trim($input['username'] ?? $input['email'] ?? $input['public_id'] ?? '');
$password = (string)($input['password'] ?? '');

if (empty($username) || empty($password)) {
    api_send_error('Missing credentials. Please provide username/email/public_id and password.', 422, [
        'username' => empty($username) ? 'Username or email is required.' : null,
        'password' => empty($password) ? 'Password is required.' : null,
    ]);
}

// 1. Authenticate against `tenants` table
$stmt = $conn->prepare("SELECT id, public_id, company_name, email, phone, username, password, plan_id, subscription_status, subscription_end_date, logo FROM tenants WHERE username = ? OR email = ? OR public_id = ? LIMIT 1");
$stmt->bind_param("sss", $username, $username, $username);
$stmt->execute();
$res = $stmt->get_result();
$tenant = $res->fetch_assoc();
$stmt->close();

$authenticatedUser = null;
$userRole = 'tenant_admin';

if ($tenant && password_verify($password, $tenant['password'])) {
    $authenticatedUser = $tenant;
} else {
    // 2. Check team_members if tenant wasn't matched
    $tmStmt = $conn->prepare("SELECT tm.id, tm.tenant_id, tm.full_name, tm.email, tm.username, tm.password, tm.role, tm.is_active, t.company_name, t.public_id, t.subscription_status, t.plan_id, t.logo 
                              FROM team_members tm 
                              JOIN tenants t ON t.id = tm.tenant_id 
                              WHERE (tm.username = ? OR tm.email = ?) LIMIT 1");
    if ($tmStmt) {
        $tmStmt->bind_param("ss", $username, $username);
        $tmStmt->execute();
        $tmRes = $tmStmt->get_result();
        $tm = $tmRes->fetch_assoc();
        $tmStmt->close();

        if ($tm && password_verify($password, $tm['password'])) {
            if ((int)$tm['is_active'] !== 1) {
                api_send_error('Forbidden: This staff account has been disabled.', 403);
            }
            $authenticatedUser = [
                'id'                  => (int)$tm['tenant_id'],
                'team_member_id'      => (int)$tm['id'],
                'public_id'           => (string)$tm['public_id'],
                'company_name'        => (string)$tm['company_name'],
                'email'               => (string)$tm['email'],
                'username'            => (string)$tm['username'],
                'subscription_status' => (string)$tm['subscription_status'],
                'plan_id'             => (int)$tm['plan_id'],
                'logo'                => (string)$tm['logo'],
            ];
            $userRole = 'team_member';
        }
    }
}

if (!$authenticatedUser) {
    api_send_error('Invalid credentials. Please verify your username and password.', 401);
}

// Fetch linked company ID in `customers` table
$companyId = 0;
$cStmt = $conn->prepare("SELECT id, google_store_url, booster_enabled FROM customers WHERE tenant_id = ? LIMIT 1");
if ($cStmt) {
    $cStmt->bind_param("i", $authenticatedUser['id']);
    $cStmt->execute();
    $cRow = $cStmt->get_result()->fetch_assoc();
    if ($cRow) {
        $companyId = (int)$cRow['id'];
    }
    $cStmt->close();
}

// Generate JWT tokens
$tokenClaims = [
    'sub'          => (int)$authenticatedUser['id'],
    'tenant_id'    => (int)$authenticatedUser['id'],
    'company_id'   => $companyId,
    'public_id'    => (string)$authenticatedUser['public_id'],
    'email'        => (string)$authenticatedUser['email'],
    'role'         => $userRole,
];

 $accessToken  = JWT::generate($tokenClaims, API_JWT_ACCESS_EXPIRY, 'access');
// Include role + team_member_id in the refresh token so we can re-issue
// correct access tokens and verify the staff member is still active.
$refreshClaims = [
    'sub'             => (int)$authenticatedUser['id'],
    'tenant_id'       => (int)$authenticatedUser['id'],
    'role'            => $userRole,
    'team_member_id'  => isset($authenticatedUser['team_member_id']) ? (int)$authenticatedUser['team_member_id'] : 0,
];
$refreshToken = JWT::generate($refreshClaims, API_JWT_REFRESH_EXPIRY, 'refresh');

api_send_success([
    'user' => [
        'id'                  => (int)$authenticatedUser['id'],
        'public_id'           => (string)$authenticatedUser['public_id'],
        'company_id'          => $companyId,
        'company_name'        => (string)$authenticatedUser['company_name'],
        'email'               => (string)$authenticatedUser['email'],
        'username'            => (string)$authenticatedUser['username'],
        'role'                => $userRole,
        'subscription_status' => (string)($authenticatedUser['subscription_status'] ?? 'active'),
        'plan_id'             => (int)($authenticatedUser['plan_id'] ?? 1),
        'logo'                => (string)($authenticatedUser['logo'] ?? ''),
    ],
    'tokens' => [
        'access_token'  => $accessToken,
        'refresh_token' => $refreshToken,
        'token_type'    => 'Bearer',
        'expires_in'    => API_JWT_ACCESS_EXPIRY,
    ]
], 'Authentication successful', 200);
