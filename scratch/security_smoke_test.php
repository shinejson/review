<?php
/**
 * CLI smoke test for the security remediations. Run:
 *   php scratch/security_smoke_test.php
 * Safe to delete afterwards.
 */
putenv('OPTIBIZ_JWT_SECRET=unit-test-secret-0123456789abcdef0123456789abcdef');
putenv('APP_BASE_URL=https://optibiz.example.com');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$failures = 0;
function check($label, $ok) {
    global $failures;
    echo ($ok ? "PASS" : "FAIL") . "  {$label}\n";
    if (!$ok) { $failures++; }
}

// --- bootstrap app config + functions ---
define('RATE_ROOT_TEST', true);
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// 1. public_rate_limit: 2 allowed, 3rd blocked
$retry = public_rate_limit('smoke_test_bucket', 2, 60);
check('rate limit 1st request allowed', $retry === 0);
$retry = public_rate_limit('smoke_test_bucket', 2, 60);
check('rate limit 2nd request allowed', $retry === 0);
$retry = public_rate_limit('smoke_test_bucket', 2, 60);
check('rate limit 3rd request blocked', $retry > 0);

// 2. mintReviewEngageToken + markCustomerEngagement token binding
// create a disposable company + rating
@$conn->query("INSERT INTO customers (tenant_id, company_name, email) VALUES (1, 'SMOKE_TEST_CO', 'smoke@test.local')");
$company_id = (int)$conn->insert_id;
@$conn->query("INSERT INTO ratings (company_id, rating, customer_name, customer_email) VALUES ($company_id, 5, 'SMOKE_TESTER', 'smoke@test.local')");
$rating_id = (int)$conn->insert_id;

$token = mintReviewEngageToken($conn, $rating_id);
check('engage token minted', strlen($token) === 48);

// Mirror the real flow: named submission first creates the site_customer row,
// then the success page lets the reviewer complete follow/like.
$cid = upsertSiteCustomer($conn, 1, $company_id, 'SMOKE_TESTER', 'smoke2@test.local', '0240000000', $rating_id, 5, 'MOMO-FAKE-REF');
check('site customer created', $cid > 0);
$sc = $conn->query("SELECT is_verified, verification_type, momo_ref FROM site_customers WHERE id = $cid")->fetch_assoc();
check('momo claim stays unverified', (int)$sc['is_verified'] === 0 && $sc['momo_ref'] === 'MOMO-FAKE-REF');

$bad = markCustomerEngagement($conn, $rating_id, $company_id, 'follow', 'facebook', '', 'wrong-token-000000000000000000000000');
check('engage with wrong token rejected', $bad === false);

$bad = markCustomerEngagement($conn, $rating_id, 999999, 'follow', 'facebook', '', $token);
check('engage with wrong company rejected', $bad === false);

$ok = markCustomerEngagement($conn, $rating_id, $company_id, 'follow', 'facebook', '', $token);
check('engage with valid token accepted', is_array($ok) && (int)$ok['is_following'] === 1);

// 4. JWT: generate + verify, wrong type rejected, tampered sig rejected
require_once dirname(__DIR__) . '/api/v1/config.php';
require_once dirname(__DIR__) . '/api/v1/helpers/jwt.php';
$jwt = JWT::generate(['sub' => 1, 'tenant_id' => 1, 'role' => 'team_member', 'team_member_id' => 7], 60, 'access');
$payload = JWT::verify($jwt, 'access');
check('valid access token verifies', is_array($payload) && $payload['role'] === 'team_member' && (int)$payload['team_member_id'] === 7);
check('refresh token rejected as access', JWT::verify(JWT::generate(['sub' => 1], 60, 'refresh'), 'access') === false);

list($h, $p, $s) = explode('.', $jwt);
$parts = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
$parts['role'] = 'tenant_admin';
$evil = $h . '.' . rtrim(strtr(base64_encode(json_encode($parts)), '+/', '-_'), '=') . '.' . $s;
check('tampered token signature rejected', JWT::verify($evil, 'access') === false);

// 5. getPlatformBaseUrl: prefers APP_BASE_URL (from env, loaded in config).
//    With APP_BASE_URL set, a spoofed Host header is ignored entirely — the
//    request below proves the configured URL wins over the attacker host.
check('APP_BASE_URL preferred for email links', getPlatformBaseUrl() === 'https://optibiz.example.com');

$_SERVER['HTTP_HOST'] = 'evil-attacker.example';
$_SERVER['SCRIPT_NAME'] = '/admin/setup-password.php';
$_SERVER['HTTPS'] = 'off';
check('spoofed Host header ignored when APP_BASE_URL set', getPlatformBaseUrl() === 'https://optibiz.example.com');

// 6. spoofed upload rejected (CLI-created file fails is_uploaded_file)
$tmp = tempnam(sys_get_temp_dir(), 'up');
file_put_contents($tmp, '<?php echo "pwned";');
$res = uploadReviewPhoto(['name' => 'shell.php', 'type' => 'image/png', 'tmp_name' => $tmp, 'size' => 20, 'error' => UPLOAD_ERR_OK], 1);
check('spoofed .php upload rejected', $res === false || (isset($res['success']) && $res['success'] === false));
@unlink($tmp);

// cleanup disposable rows
@$conn->query("DELETE FROM site_customers WHERE company_id = $company_id");
@$conn->query("DELETE FROM ratings WHERE company_id = $company_id");
@$conn->query("DELETE FROM customers WHERE id = $company_id");
@$conn->query("DELETE FROM api_rate_limits WHERE reset_at IS NOT NULL AND key_hash = '" . hash('sha256', 'smoke_test_bucket|127.0.0.1') . "'");

echo $failures === 0 ? "\nALL CHECKS PASSED\n" : "\n{$failures} CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
