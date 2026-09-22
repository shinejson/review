<?php
/**
 * Optibiz REST API - Bearer JWT Authentication Guard
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/jwt.php';
require_once RATE_ROOT_PATH . '/config/database.php';
require_once RATE_ROOT_PATH . '/includes/functions.php';

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

    if (JWT::isRevoked($conn, $token)) {
        api_send_error('Unauthorized: Access token has been revoked. Please sign in again.', 401);
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

    // Verify tenant plan grants REST API access (Enterprise tier or explicit plan entitlement)
    if (function_exists('tenantHasFeature') && !tenantHasFeature($conn, $tenantId, 'api_access')) {
        api_send_error('Forbidden: REST API access requires an Enterprise subscription. Please upgrade your plan in the workspace dashboard.', 403);
    }

    // Check if authenticated as a team member and verify active status
    $userRole = isset($payload['role']) ? (string)$payload['role'] : 'tenant_admin';
    $teamMemberId = isset($payload['team_member_id']) ? (int)$payload['team_member_id'] : 0;
    $teamPermissions = [];

    if ($userRole === 'team_member' && $teamMemberId > 0) {
        if (function_exists('ensureTeamSchema')) {
            ensureTeamSchema($conn);
        }
        $tmStmt = $conn->prepare("SELECT id, permissions, is_active FROM team_members WHERE id = ? AND tenant_id = ? LIMIT 1");
        if ($tmStmt) {
            $tmStmt->bind_param("ii", $teamMemberId, $tenantId);
            $tmStmt->execute();
            $tm = $tmStmt->get_result()->fetch_assoc();
            $tmStmt->close();
            if (!$tm || (int)$tm['is_active'] !== 1) {
                api_send_error('Forbidden: This staff account has been deactivated or removed.', 403);
            }
            if (function_exists('teamMemberParsePerms')) {
                $teamPermissions = teamMemberParsePerms($tm['permissions'] ?? '');
            } else {
                $teamPermissions = array_filter(array_map('trim', explode(',', (string)($tm['permissions'] ?? ''))));
            }
        }
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
        'role'                => $userRole,
        'team_member_id'      => $teamMemberId,
        'permissions'         => $teamPermissions,
        'subscription_status' => (string)$tenant['subscription_status'],
        'plan_id'             => (int)$tenant['plan_id'],
        'company_id'          => $companyId,
        'token_claims'        => $payload
    ];

    return $authContext;
}

/**
 * Enforce role-based or permission-based access for an API endpoint.
 * Workspace owners (tenant_admin) pass all permission checks.
 */
function api_require_permission(array $authContext, string $permission): void {
    if (($authContext['role'] ?? '') !== 'team_member') {
        return;
    }
    $perms = $authContext['permissions'] ?? [];
    if (!in_array($permission, $perms, true)) {
        api_send_error("Forbidden: Your account does not have permission to access '{$permission}'.", 403);
    }
}
