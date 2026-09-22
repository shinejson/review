<?php
/**
 * Optibiz REST API - POST /api/v1/auth/logout.php
 * Explicitly revokes access and/or refresh tokens server-side.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/jwt.php';
require_once RATE_ROOT_PATH . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$revokedCount = 0;
$input = api_get_request_body();
$refreshToken = trim($input['refresh_token'] ?? '');

// 1. Revoke refresh token if provided
if (!empty($refreshToken)) {
    $rPayload = JWT::verify($refreshToken, 'refresh');
    $tenantId = (int)($rPayload['tenant_id'] ?? $rPayload['sub'] ?? 0);
    $exp = (int)($rPayload['exp'] ?? (time() + API_JWT_REFRESH_EXPIRY));
    if (JWT::revoke($conn, $refreshToken, 'refresh', $tenantId, $exp)) {
        $revokedCount++;
    }
}

// 2. Revoke access token from Authorization header if present
$authHeader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
} elseif (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $authHeader = trim($headers['Authorization'] ?? $headers['authorization'] ?? '');
}

if (!empty($authHeader) && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $accessToken = $matches[1];
    $aPayload = JWT::verify($accessToken, 'access');
    $tenantId = (int)($aPayload['tenant_id'] ?? $aPayload['sub'] ?? 0);
    $exp = (int)($aPayload['exp'] ?? (time() + API_JWT_ACCESS_EXPIRY));
    if (JWT::revoke($conn, $accessToken, 'access', $tenantId, $exp)) {
        $revokedCount++;
    }
}

if ($revokedCount === 0 && empty($refreshToken) && empty($authHeader)) {
    api_send_error('No valid token provided to revoke. Provide Bearer header or refresh_token in request body.', 400);
}

api_send_success([
    'revoked' => true,
    'tokens_revoked' => $revokedCount
], 'Logged out successfully. Tokens have been revoked.', 200);
