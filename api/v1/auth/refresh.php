<?php
/**
 * Optibiz REST API - POST /api/v1/auth/refresh.php
 * Exchange valid refresh token for a new access token
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/jwt.php';
require_once RATE_ROOT_PATH . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$input = api_get_request_body();
$refreshToken = trim($input['refresh_token'] ?? '');

if (empty($refreshToken)) {
    api_send_error('Missing refresh_token parameter.', 422);
}

$payload = JWT::verify($refreshToken, 'refresh');
if (!$payload) {
    api_send_error('Invalid or expired refresh token. Please sign in again.', 401);
}

$tenantId = (int)($payload['tenant_id'] ?? $payload['sub'] ?? 0);
if ($tenantId <= 0) {
    api_send_error('Invalid token payload.', 401);
}

// Pull the role from the refresh token. If it says team_member, verify the
// staff record still exists and is still active before issuing a new token.
$userRole  = isset($payload['role']) ? (string)$payload['role'] : 'tenant_admin';
$teamId    = isset($payload['team_member_id']) ? (int)$payload['team_member_id'] : 0;

if ($userRole === 'team_member' && $teamId > 0) {
    $tmCheck = $conn->prepare("SELECT is_active FROM team_members WHERE id = ? AND tenant_id = ? LIMIT 1");
    $tmCheck->bind_param("ii", $teamId, $tenantId);
    $tmCheck->execute();
    $tmRes = $tmCheck->get_result()->fetch_assoc();
    $tmCheck->close();
    if (!$tmRes || (int)$tmRes['is_active'] !== 1) {
        api_send_error('Forbidden: This staff account has been disabled.', 403);
    }
}

// Fetch current tenant data
$stmt = $conn->prepare("SELECT id, public_id, company_name, email, subscription_status, plan_id FROM tenants WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $tenantId);
$stmt->execute();
$res = $stmt->get_result();
$tenant = $res->fetch_assoc();
$stmt->close();

if (!$tenant || $tenant['subscription_status'] === 'cancelled') {
    api_send_error('Unauthorized: Tenant account is inactive or cancelled.', 403);
}

$companyId = 0;
$cStmt = $conn->prepare("SELECT id FROM customers WHERE tenant_id = ? LIMIT 1");
if ($cStmt) {
    $cStmt->bind_param("i", $tenantId);
    $cStmt->execute();
    $cRow = $cStmt->get_result()->fetch_assoc();
    if ($cRow) $companyId = (int)$cRow['id'];
    $cStmt->close();
}

$newAccessClaims = [
    'sub'            => (int)$tenant['id'],
    'tenant_id'      => (int)$tenant['id'],
    'company_id'     => $companyId,
    'public_id'      => (string)$tenant['public_id'],
    'email'          => (string)$tenant['email'],
    'role'           => $userRole,
    'team_member_id' => $teamId,
];

$newAccessToken  = JWT::generate($newAccessClaims, API_JWT_ACCESS_EXPIRY, 'access');
// Preserve role + team_member_id across refresh-token rotation
$newRefreshToken = JWT::generate([
    'sub'            => (int)$tenant['id'],
    'tenant_id'      => (int)$tenant['id'],
    'role'           => $userRole,
    'team_member_id' => $teamId,
], API_JWT_REFRESH_EXPIRY, 'refresh');

api_send_success([
    'tokens' => [
        'access_token'  => $newAccessToken,
        'refresh_token' => $newRefreshToken,
        'token_type'    => 'Bearer',
        'expires_in'    => API_JWT_ACCESS_EXPIRY,
    ]
], 'Token refreshed successfully', 200);
