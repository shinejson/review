<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/two_factor.php';

echo "1. Testing Schema Creation...\n";
tfa_ensure_schema($conn);
$check = $conn->query("SHOW TABLES LIKE 'user_two_factor'");
if ($check && $check->num_rows === 1) {
    echo "PASS: user_two_factor table exists.\n";
} else {
    echo "FAIL: user_two_factor table not created.\n";
    exit(1);
}

echo "2. Testing Secret Generation and Formatting...\n";
$secret = tfa_generate_secret(16);
echo "Secret generated: " . $secret . "\n";
$formatted = tfa_format_secret($secret);
echo "Formatted: " . $formatted . "\n";
if (strlen($secret) >= 16 && strlen($formatted) > strlen($secret)) {
    echo "PASS: Secret generated and formatted.\n";
} else {
    echo "FAIL: Secret generation failed.\n";
    exit(1);
}

echo "3. Testing TOTP Verification with Live Secret...\n";
$currentCode = tfa_compute_totp($secret);
echo "Current TOTP Code: $currentCode\n";
$verifyOk = tfa_verify_totp($secret, $currentCode);
$verifyBad = tfa_verify_totp($secret, '000000');
if ($verifyOk && !$verifyBad) {
    echo "PASS: TOTP verification works correctly.\n";
} else {
    echo "FAIL: TOTP verification mismatch.\n";
    exit(1);
}

echo "4. Testing Backup Codes...\n";
$backup = tfa_generate_backup_codes(8);
if (count($backup['plain']) === 8 && count($backup['hashed']) === 8) {
    echo "PASS: 8 Backup codes generated.\n";
} else {
    echo "FAIL: Backup codes count mismatch.\n";
    exit(1);
}

echo "5. Testing DB Enable & Verification...\n";
$testUserId = 99999;
$testUserType = 'tenant';
tfa_enable($conn, $testUserType, $testUserId, 'totp', $secret, $backup['hashed']);
$status = tfa_get_status($conn, $testUserType, $testUserId);
if ($status['is_enabled'] && $status['remaining_backup_codes'] === 8) {
    echo "PASS: 2FA enabled in DB with 8 remaining codes.\n";
} else {
    echo "FAIL: Status mismatch in DB.\n";
    exit(1);
}

echo "6. Testing Backup Code Redemption...\n";
$testCodeToUse = $backup['plain'][0];
$usedBackup = false;
$codeValid = tfa_verify_code($conn, $testUserType, $testUserId, $testCodeToUse, $usedBackup);
if ($codeValid && $usedBackup) {
    echo "PASS: Backup code redeemed successfully.\n";
} else {
    echo "FAIL: Backup code redemption failed.\n";
    exit(1);
}

// Ensure the same backup code cannot be reused
$reused = tfa_verify_code($conn, $testUserType, $testUserId, $testCodeToUse, $usedBackup);
if (!$reused) {
    echo "PASS: Reusing single-use backup code was properly denied.\n";
} else {
    echo "FAIL: Backup code was reused!\n";
    exit(1);
}

// Clean up test record
tfa_disable($conn, $testUserType, $testUserId);
$conn->query("DELETE FROM user_two_factor WHERE user_type = '$testUserType' AND user_id = $testUserId");
echo "Cleaned up test record.\n";
echo "ALL TWO-FACTOR TESTS PASSED SUCCESSFULLY!\n";
