<?php
/**
 * Optibiz REST API - Bearer JWT Authentication Guard
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/jwt.php';
require_once RATE_ROOT_PATH . '/config/database.php';

function api_authenticate_bearer(mysqli $conn): array {
    $authHeader = '';

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = trim($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }

    if (empty($authHeader) || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        api_send_error('Unauthorized: Missing or malformed Bearer token in Authorization header.', 401);
    }

    $token = $matches[1];
    $payload = JWT::verify($token, 'access');

    if (!$payload) {
        api_send_error('Unauthorized: Invalid or expired access token.', 401);
    }

    $tenantId = (int)($payload['tenant_id'] ?? $payload['sub'] ?? 0);
    if ($tenantId <= 0) {
        api_send_error('Unauthorized: Invalid token subject.', 401);
    }

    // Verify tenant still exists in database and is not disabled
    $stmt = $conn->prepare("SELECT id, public_id, company_name, email, username, subscription_status, subscription_end_date, plan_id, logo FROM tenants WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $res = $stmt->get_result();
    $tenant = $res->fetch_assoc();
    $stmt->close();

    if (!$tenant) {
        api_send_error('Unauthorized: Tenant account no longer exists.', 401);
    }

    if ($tenant['subscription_status'] === 'cancelled') {
        api_send_error('Forbidden: Your workspace subscription has been cancelled.', 403);
    }

    // Find company profile ID in customers table linked to this tenant
    $companyId = 0;
    $cStmt = $conn->prepare("SELECT id, company_name, google_store_url, booster_enabled, booster_min_stars FROM customers WHERE tenant_id = ? LIMIT 1");
    if ($cStmt) {
        $cStmt->bind_param("i", $tenantId);
        $cStmt->execute();
        $cRes = $cStmt->get_result()->fetch_assoc();
        if ($cRes) {
            $companyId = (int)$cRes['id'];
        }
        $cStmt->close();
    }

    $authContext = [
        'tenant_id'           => (int)$tenant['id'],
        'public_id'           => (string)$tenant['public_id'],
        'company_name'        => (string)$tenant['company_name'],
        'email'               => (string)$tenant['email'],
        'username'            => (string)$tenant['username'],
        'role'                => 'tenant_admin',
        'subscription_status' => (string)$tenant['subscription_status'],
        'plan_id'             => (int)$tenant['plan_id'],
        'company_id'          => $companyId,
        'token_claims'        => $payload
    ];

    return $authContext;
}
