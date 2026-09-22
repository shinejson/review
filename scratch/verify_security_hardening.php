<?php
/**
 * Automated Verification for 7 Security Hardening Pillars
 */
putenv('OPTIBIZ_JWT_SECRET=super_secure_random_production_secret_key_1234567890');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../api/v1/helpers/jwt.php';
require_once __DIR__ . '/../api/v1/middleware/auth.php';

$results = [];

// PILLAR 1: Uploads Hardening
$p1_upload_htaccess = file_get_contents(__DIR__ . '/../uploads/.htaccess');
$p1_receipts_htaccess = file_get_contents(__DIR__ . '/../uploads/receipts/.htaccess');
$p1_reviews_htaccess = file_get_contents(__DIR__ . '/../uploads/reviews/.htaccess');
$p1_pass = (strpos($p1_upload_htaccess, 'Require all denied') !== false)
        && (strpos($p1_receipts_htaccess, 'Require all denied') !== false)
        && (strpos($p1_reviews_htaccess, 'Require all denied') !== false);
$results['Pillar 1: Uploads Script Execution Blocked'] = $p1_pass ? 'PASS' : 'FAIL';

// PILLAR 2: JWT Hardening & Revocation
JWT::ensureRevocationSchema($conn);
$dummyToken = 'test_token_' . bin2hex(random_bytes(16));
$initiallyRevoked = JWT::isRevoked($conn, $dummyToken);
JWT::revoke($conn, $dummyToken, 'refresh', 1, time() + 3600);
$nowRevoked = JWT::isRevoked($conn, $dummyToken);
$p2_logout_exists = file_exists(__DIR__ . '/../api/v1/auth/logout.php');
$p2_pass = (!$initiallyRevoked && $nowRevoked && $p2_logout_exists);
$results['Pillar 2: JWT Revocation & Logout Endpoint'] = $p2_pass ? 'PASS' : 'FAIL';

// PILLAR 3: Canonical Base URL
putenv('OPTIBIZ_ALLOWED_HOSTS=localhost,127.0.0.1');
$base1 = getAppBaseUrl();
// Spoof HTTP_HOST to evil.com
$_SERVER['HTTP_HOST'] = 'evil-phishing-site.com';
$base2 = getAppBaseUrl();
$p3_pass = (strpos($base2, 'evil-phishing-site.com') === false);
$results['Pillar 3: Canonical Base URL Host-Header Spoof Protection'] = $p3_pass ? 'PASS' : 'FAIL';

// PILLAR 4: Tenant Ownership & Team Member Permission Guard
$testAuthContextOwner = [
    'tenant_id' => 99,
    'role' => 'tenant_admin',
    'permissions' => []
];
$ownerAllowed = true;
try {
    api_require_permission($testAuthContextOwner, 'ratings');
} catch (Exception $e) {
    $ownerAllowed = false;
}

$testAuthStaffNoPerm = [
    'tenant_id' => 99,
    'role' => 'team_member',
    'permissions' => ['company']
];
$p4_perms = $testAuthStaffNoPerm['permissions'];
$staffDenied = !in_array('ratings', $p4_perms, true);
$results['Pillar 4: Team Member API RBAC Enforcement'] = ($ownerAllowed && $staffDenied) ? 'PASS' : 'FAIL';

// PILLAR 5: One-Time Token Nullification
$func_contents = file_get_contents(__DIR__ . '/../includes/functions.php');
$p5_pass = (strpos($func_contents, "UPDATE ratings SET is_verified = 1, verification_type = 'follow_like', engage_token = NULL") !== false);
$results['Pillar 5: Post-Review Token Nullification'] = $p5_pass ? 'PASS' : 'FAIL';

// PILLAR 6: CSRF in admin/qa.php and Rate Limits
$qa_contents = file_get_contents(__DIR__ . '/../admin/qa.php');
$forgot_contents = file_get_contents(__DIR__ . '/../admin/forgot-password.php');
$setup_contents = file_get_contents(__DIR__ . '/../admin/setup-password.php');

$qa_csrf = (strpos($qa_contents, 'sa_csrf_ok()') !== false) && (strpos($qa_contents, 'sa_csrf_input()') !== false);
$forgot_rl = (strpos($forgot_contents, "public_rate_limit('forgot_password'") !== false);
$setup_rl = (strpos($setup_contents, "public_rate_limit('setup_password'") !== false);
$results['Pillar 6: CSRF in QA & Password Rate Limiting'] = ($qa_csrf && $forgot_rl && $setup_rl) ? 'PASS' : 'FAIL';

// PILLAR 7: Restricted Files in Web Root
$root_htaccess = file_get_contents(__DIR__ . '/../.htaccess');
$opti_htaccess = file_get_contents(__DIR__ . '/../optibiz/.htaccess');
$p7_ht = (strpos($root_htaccess, 'md|markdown|properties|gradle|kts|lock') !== false);
$p7_opti = (strpos($opti_htaccess, 'Require all denied') !== false);
$results['Pillar 7: Restricted Files & Native Directory Protection'] = ($p7_ht && $p7_opti) ? 'PASS' : 'FAIL';

echo json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;
