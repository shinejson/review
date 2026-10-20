<?php
/**
 * POST /api/v1/qr/issue.php
 *
 * Workspace-authenticated QR issuance.
 * Body: { kind: "location"|"visit", label, staff_ref, ttl_hours, max_uses }
 *
 * - location: a printable, reusable, signed token for a counter stand.
 * - visit:    a single-use token to print on a receipt or send by SMS
 *             after a visit. Only its hash is stored.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/rate_limiter.php';
require_once dirname(__DIR__) . '/middleware/auth.php';
require_once RATE_ROOT_PATH . '/config/database.php';
require_once RATE_ROOT_PATH . '/includes/functions.php';
require_once RATE_ROOT_PATH . '/includes/review_trust.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$auth = api_authenticate_bearer($conn);
api_require_permission($auth, 'qr_stand');
RateLimiter::check($conn, 'qr_issue', (string) $auth['tenant_id'], 60, 3600);

$companyId = (int) $auth['company_id'];
$tenantId = (int) $auth['tenant_id'];
if ($companyId <= 0) {
    api_send_error('This workspace has no company profile yet.', 409);
}

$input = api_get_request_body();
$kind = strtolower((string) ($input['kind'] ?? 'location'));
if (!in_array($kind, ['location', 'visit'], true)) {
    api_send_error('kind must be "location" or "visit".', 422);
}

$label = trim(strip_tags((string) ($input['label'] ?? ($kind === 'location' ? 'Counter stand' : 'Receipt'))));
$staffRef = trim(strip_tags((string) ($input['staff_ref'] ?? '')));
$ttlHours = max(1, min(24 * 365, (int) ($input['ttl_hours'] ?? 72)));
$maxUses = max(1, min(10000, (int) ($input['max_uses'] ?? 1)));

review_trust_ensure_schema($conn);

if ($kind === 'location') {
    $days = max(1, min(365, (int) ($input['ttl_days'] ?? 365)));
    $token = review_qr_location_token($companyId, $tenantId, $label, $days);
    $url = getCompanyPublicRatingUrl($companyId, '', ['qv' => $token, 'src' => 'qr']);

    api_send_success([
        'kind'       => 'location',
        'token'      => $token,
        'url'        => $url,
        'label'      => $label,
        'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
        'reusable'   => true,
    ], 'Location QR issued.', 201);
}

$issued = review_qr_issue_visit_token(
    $conn,
    $companyId,
    $tenantId,
    $label,
    $staffRef,
    $ttlHours,
    $maxUses,
    'tenant',
    $tenantId
);

if (!$issued) {
    api_send_error('Could not create the visit QR.', 500);
}

api_send_success([
    'kind'       => 'visit',
    'id'         => $issued['id'],
    'token'      => $issued['token'],
    'url'        => $issued['url'],
    'label'      => $label,
    'staff_ref'  => $staffRef,
    'max_uses'   => $maxUses,
    'expires_at' => $issued['expires_at'],
    'reusable'   => $maxUses > 1,
], 'Visit QR issued.', 201);
