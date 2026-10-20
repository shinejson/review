<?php
/**
 * Optibiz — unit tests for the security-critical foundation.
 *
 * Run:  php tests/run.php
 *
 * These tests need no database and no network. Google's JWKS is injected
 * with a locally generated RSA key so the real signature-verification
 * path (not a mock) is exercised.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Keep the suite fast; production uses 600,000.
putenv('OPTIBIZ_PBKDF2_ITERATIONS=100000');
putenv('OPTIBIZ_GOOGLE_CLIENT_IDS=test-client-id.apps.googleusercontent.com');

require_once dirname(__DIR__) . '/includes/password.php';
require_once dirname(__DIR__) . '/includes/app_auth.php';
require_once dirname(__DIR__) . '/includes/app_otp.php';
require_once dirname(__DIR__) . '/includes/google_auth.php';
require_once dirname(__DIR__) . '/includes/review_trust.php';
require_once dirname(__DIR__) . '/includes/push.php';

$pass = 0;
$fail = 0;
$failures = [];

function check($label, $condition, $detail = '')
{
    global $pass, $fail, $failures;
    if ($condition) {
        $pass++;
        echo "  [ ok ] {$label}\n";
    } else {
        $fail++;
        $failures[] = $label . ($detail !== '' ? ' — ' . $detail : '');
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function section($title)
{
    echo "\n" . $title . "\n" . str_repeat('-', strlen($title)) . "\n";
}

/* ============================================================
   1. Password hashing
   ============================================================ */
section('Password hashing (PBKDF2-HMAC-SHA256)');

$password = 'Correct-Horse-Battery-42';
$hash = optibiz_hash_password($password);

check('hash is produced', is_string($hash) && $hash !== '');
check('hash uses the versioned PBKDF2 format', is_string($hash) && preg_match('/^pbkdf2_sha256\$\d+\$[0-9a-f]{32}\$[0-9a-f]{64}$/', $hash) === 1, (string) $hash);
check('seed hash carried at least 100,000 iterations', is_string($hash) && (int) explode('$', $hash)[1] >= 100000);
check('correct password verifies', optibiz_verify_password($password, $hash));
check('wrong password is rejected', !optibiz_verify_password($password . 'x', $hash));
check('empty password is rejected', !optibiz_verify_password('', $hash));
check('malformed hash is rejected', !optibiz_verify_password($password, 'pbkdf2_sha256$nope'));

$hash2 = optibiz_hash_password($password);
check('each hash gets a fresh random salt', $hash !== $hash2 && optibiz_verify_password($password, $hash2));

check('current hash needs no rehash', !optibiz_password_needs_rehash($hash));

// Legacy bcrypt hashes must keep working and must be upgraded.
$bcrypt = password_hash($password, PASSWORD_BCRYPT);
check('legacy bcrypt still verifies', optibiz_verify_password($password, $bcrypt));
check('legacy bcrypt is flagged for rehash', optibiz_password_needs_rehash($bcrypt));

// A lower legacy iteration count must be upgraded.
$oldFormat = 'pbkdf2_sha256$10000$' . bin2hex(random_bytes(16)) . '$' . bin2hex(random_bytes(32));
check('low-iteration PBKDF2 hash is flagged for rehash', optibiz_password_needs_rehash($oldFormat));

check('dummy verify always fails', optibiz_password_dummy_verify($password) === false);
check('hashing rejects an empty value', optibiz_hash_password('') === null);

/* ============================================================
   2. Password policy
   ============================================================ */
section('Password policy');

check('strong password accepted', optibiz_password_policy_errors('Accra-River-92!', 'ama@example.com', 'Ama Mensah') === []);
check('short password rejected', in_array('Use at least 10 characters.', optibiz_password_policy_errors('short1!', '', ''), true));
check('common password rejected', count(optibiz_password_policy_errors('password123', '', '')) > 0);
check('repeated character rejected', count(optibiz_password_policy_errors('aaaaaaaaaaaa', '', '')) > 0);
check('keyboard sequence rejected', count(optibiz_password_policy_errors('1234567890ab', '', '')) > 0);
check('email local part rejected', in_array('Do not include your email address in the password.', optibiz_password_policy_errors('Ama-Mensah-92', 'ama@example.com', ''), true));
check('name rejected', in_array('Do not include your name in the password.', optibiz_password_policy_errors('Ama-Mensah-92', '', 'Ama Mensah'), true));

/* ============================================================
   3. OTP crypto
   ============================================================ */
section('One-time codes');

$code = optibiz_otp_generate_code();
check('code is six digits', preg_match('/^\d{6}$/', $code) === 1, $code);
$codes = [];
for ($i = 0; $i < 50; $i++) {
    $codes[optibiz_otp_generate_code()] = true;
}
check('codes are not constant', count($codes) > 20, count($codes) . ' unique of 50');

$h1 = optibiz_otp_code_hash($code, 'email', 'ama@example.com', 'recovery');
$h2 = optibiz_otp_code_hash($code, 'email', 'ama@example.com', 'recovery');
$h3 = optibiz_otp_code_hash($code, 'email', 'ama@example.com', 'login');
$h4 = optibiz_otp_code_hash($code, 'email', 'other@example.com', 'recovery');
check('code hash is stable for the same context', $h1 === $h2);
check('code hash is bound to the purpose', $h1 !== $h3);
check('code hash is bound to the destination', $h1 !== $h4);
check('code hash is 64 hex characters', preg_match('/^[0-9a-f]{64}$/', $h1) === 1);

list($ch, $dest, $err) = optibiz_otp_normalise('phone', '024 555 0118');
check('phone is normalised to Ghana international digits', $ch === 'sms' && $dest === '233245550118', $ch . ' / ' . $dest);
list(, $mail, $err2) = optibiz_otp_normalise('email', '  AMA@Example.COM ');
check('email is normalised', $mail === 'ama@example.com', (string) $mail);
list(, , $err3) = optibiz_otp_normalise('email', 'not-an-email');
check('invalid email is rejected', $err3 !== null);

/* ============================================================
   4. Google Sign-In
   ============================================================ */
section('Google Sign-In token verification');

function b64url($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function make_google_token($privateKey, $kid, array $claims, $alg = 'RS256', $tamper = false)
{
    $header = ['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid];
    $h = b64url(json_encode($header));
    $p = b64url(json_encode($claims));
    $signature = '';
    if ($alg === 'RS256') {
        openssl_sign($h . '.' . $p, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    } else {
        $signature = hash_hmac('sha256', $h . '.' . $p, 'wrong', true);
    }
    $token = $h . '.' . $p . '.' . b64url($signature);
    if ($tamper) {
        $parts = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $payload['email'] = 'attacker@example.com';
        $parts[1] = b64url(json_encode($payload));
        $token = implode('.', $parts);
    }
    return $token;
}

// Test-only RSA key. It is public in this repository and guarded by
// nothing; it exists solely so the real verification code path can run
// offline. Never use it for anything else.
$testPrivatePem = <<<'PEM'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEAo2IzdAJh8kwAqYlnNh4fCtIWTY9TgxTlkzzDE6A8QRVUzf6G
i24ls+OzTOdEMLxQMtRyicitZhxKmvEqs4OTGUQMAsBF6MNi2oJ51166eLtrQJ+P
rBTkOtv6v2RYd5xR9qaTBAKEPRT7k3Tj38OPLud3+jmkvTp1rdrlCvmzckaWVHQ7
yfdd5cbPgcPTBABP7sKju1jmDh71C3EOmUea9+wTOwD5Fs1PSwbJoF5P+eVwFIBs
efRhaVVt6/j9FH5vb7DkXgMwuqW/rpV8znu8EbCUvdP6fpbo5tQOdKlcDdz8+l2l
rI0cQnypChnqiAHDQye7NgeqiW1gz10vD/ul7wIDAQABAoIBABbWgxG2xDaIbYPv
8HeYm0c5xE4AIwYiKtBmKxs4XTkjFbEbtcvnUCmGl8lqqatUT1444xp5F3/mpCkS
B/bYL/zVTbEpa5GUlOICtgGnEf1kA/h1tVwHqZAZWsIGcUqllQbGFFZ4UZVw3upR
lF7IE7Yp5dPvhc8WTQfJyWq7f7FmjM2Z28D6C6HLZTiB3vrqTgVyfbD5BQaGaWhH
POky+P5gKViYRsiqK7dpdojF/zi6K4Z+l1skVkHxWe1rigANAErd0DwHaDM2ZgNK
ROszay+204A9NyuCPn6kBcqujEb9+V0gv5BAvx9qLHAyhKHY7Xql8QehoyZCJp6w
T+aAiaUCgYEA3xqWLqw5+/3khWevNX3fQDZPYt1dyTYiDgFOyqtY/PUvcrVzC6ck
QD+EXBaYZNgPpWyZaQMZBpBM3z7Yp2c3qOF8wOWyZ9trfU56Y9LugMTswnq3sb8b
L+R3LHU6p69OaR0hdUCAe44ogQ4JLZnZET4GesEP7P16Dgh3JTnvdc0CgYEAu3lg
mJENJNdMO8AJ+4qvf8cdyfPdhNcI7OhLAQMYK7lsXNYVfztZN+mL6+A2AZkv1AR8
WyieiQdLHeRKcNdzPsdgVLHoB+dDgLcvH6q/meF/WsbOCTCjz02kRAOWNfCt5OD0
DY5QtcU9vmN2m26/GIsuDOCrAAa2ywieMaAxzqsCgYBIefCT1Os5S29zTw2CCRP/
XiVH7rXK6cUCW7Zm7GcKNfRmBUAMZw3FuVycNdLn37ClgrQLgzAcC8CNPXNQXp2c
RTQrzfK4/nTaziXATAOi3VY0j30hRv3j/wof7S1S0AZe61G1L3ENsk7CLfcZU0NK
8KVWN2WfDXEbIfDirQF3eQKBgQC6c/FaS4kjBu3OzyQehMQe9U3vORcrsyIxXKac
DxPPCM95VYJz0/bb4O9EXO3ZkR08ZGm50CA8cICI7jALInyAB5MLKRV4Js3ACnBX
sl51EQy0n9zyzulcl/+zlU2jwAdzBzgy80PaRJ0v6YRg+jgclO/r5tarZAIQ1DVh
bc2bmQKBgQCD/FVb9pCpyNLmv6+aVVYL6YwzEtQsWslYgzQsq7AELV9sgeJRcV1H
mXD5p6UdLybIRmJVMaZDbnjgWtv3ZPfd4YUugtNaMfZEdIGSDvl4NvIP1Dmz7dcG
7NU2pgv3zACJ33sF69DPVnhCkxLrA40jTD111yHZHUVRqEkvuliwoQ==
-----END RSA PRIVATE KEY-----
PEM;
$key = openssl_pkey_get_private($testPrivatePem);
check('test RSA key loaded', $key !== false);

$details = openssl_pkey_get_details($key);
$n = b64url($details['rsa']['n']);
$e = b64url($details['rsa']['e']);
$kid = 'test-key-1';
putenv('OPTIBIZ_GOOGLE_JWKS_JSON=' . json_encode(['keys' => [
    ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $n, 'e' => $e],
]]));

$claims = [
    'iss'            => 'https://accounts.google.com',
    'aud'            => 'test-client-id.apps.googleusercontent.com',
    'sub'            => '1234567890',
    'email'          => 'ama@example.com',
    'email_verified' => true,
    'name'           => 'Ama Mensah',
    'picture'        => 'https://example.com/ama.png',
    'iat'            => time() - 30,
    'exp'            => time() + 3600,
];

$token = make_google_token($key, $kid, $claims);
$result = optibiz_google_verify_id_token($token);
check('valid Google token accepted', !empty($result['success']), $result['message'] ?? '');
check('verified email returned', ($result['claims']['email'] ?? '') === 'ama@example.com');
check('Google subject returned', ($result['claims']['sub'] ?? '') === '1234567890');

$result = optibiz_google_verify_id_token(make_google_token($key, $kid, $claims, 'RS256', true));
check('tampered payload rejected', empty($result['success']));

$badAud = $claims;
$badAud['aud'] = 'someone-else.apps.googleusercontent.com';
check('wrong audience rejected', empty(optibiz_google_verify_id_token(make_google_token($key, $kid, $badAud))['success']));

$badIssuer = $claims;
$badIssuer['iss'] = 'https://evil.example.com';
check('wrong issuer rejected', empty(optibiz_google_verify_id_token(make_google_token($key, $kid, $badIssuer))['success']));

$expired = $claims;
$expired['exp'] = time() - 120;
check('expired token rejected', empty(optibiz_google_verify_id_token(make_google_token($key, $kid, $expired))['success']));

$unverified = $claims;
$unverified['email_verified'] = false;
check('unverified Google email rejected', empty(optibiz_google_verify_id_token(make_google_token($key, $kid, $unverified))['success']));

$none = make_google_token($key, $kid, $claims, 'none');
check('alg=none rejected', empty(optibiz_google_verify_id_token($none)['success']));

$unknownKid = make_google_token($key, 'not-in-jwks', $claims);
check('unknown signing key rejected', empty(optibiz_google_verify_id_token($unknownKid)['success']));

putenv('OPTIBIZ_GOOGLE_CLIENT_IDS');
check('unconfigured Google Sign-In returns 503', (optibiz_google_verify_id_token($token)['status'] ?? 0) === 503);


/* ============================================================
   Review trust: QR tokens, categories, window, photos
   ============================================================ */
section('Review trust');

$qr = review_qr_location_token(51, 18, 'Counter stand', 30);
check('location token has the versioned prefix', strpos($qr, 'obqv1.') === 0);
$qrOk = review_qr_location_verify($qr, 51);
check('location token verifies for its company', is_array($qrOk) && (int) $qrOk['company_id'] === 51);
check('location token is rejected for another company', review_qr_location_verify($qr, 52) === null);
check('tampered location token is rejected', review_qr_location_verify(substr($qr, 0, -2) . 'aa', 51) === null);
check('garbage token is rejected', review_qr_location_verify('not-a-token', 51) === null);

$parsed = review_qr_from_input(['qv' => '  ' . $qr . '  ']);
check('token is read from qv and trimmed', $parsed === $qr);
check('missing token reads as empty', review_qr_from_input([]) === '');

$cats = review_categories_from_input([
    'category_service' => 5,
    'categories' => ['cleanliness' => 4, 'value' => 9],
    'category_speed' => '0',
]);
check('flat category score kept', $cats['service'] === 5);
check('nested category score kept', $cats['cleanliness'] === 4);
check('out-of-range category becomes null', $cats['value'] === null);
check('zero category becomes null', $cats['speed'] === null);

putenv('OPTIBIZ_REVIEW_WINDOW_DAYS=7');
check('window honours the environment', review_window_days() === 7);
putenv('OPTIBIZ_REVIEW_WINDOW_DAYS=9999');
check('window is capped at one year', review_window_days() === 365);
putenv('OPTIBIZ_REVIEW_WINDOW_DAYS');

check('unapproved photos are never public', review_public_photos(['photos' => '["a.jpg"]', 'photos_approved' => 0]) === []);
check('approved photos are returned', review_public_photos(['photos' => '["a.jpg","b.jpg"]', 'photos_approved' => 1]) === ['a.jpg', 'b.jpg']);

section('Push notifications');
putenv('OPTIBIZ_FCM_SERVICE_JSON');
putenv('OPTIBIZ_FCM_SERVICE_JSON_RAW');
check('FCM is optional', push_fcm_config() === []);
$skipped = push_fcm_send(['token-1'], 'Title', 'Body');
check('send without credentials is a no-op', $skipped['skipped'] === true && $skipped['sent'] === 0);
check('anonymous push write is refused', push_user(null, 0, 'system', 'Title', 'Body') === 0);

/* ============================================================
   Summary
   ============================================================ */
section('Summary');
echo "  {$pass} passed, {$fail} failed\n";
if ($fail) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
}
exit($fail === 0 ? 0 : 1);
