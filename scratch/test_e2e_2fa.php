<?php
putenv('OPTIBIZ_JWT_SECRET=super_secret_test_key_for_e2e_2fa_verification_32bytes');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/two_factor.php';
require_once __DIR__ . '/../api/v1/config.php';
require_once __DIR__ . '/../api/v1/helpers/jwt.php';

echo "=== Starting Comprehensive 2FA End-to-End Test Suite ===\n\n";

if (!isset($conn) || !$conn) {
    echo "ERROR: Database connection not established.\n";
    exit(1);
}

tfa_ensure_schema($conn);

$testType = 'tenant';
$testUserId = 999999;

// Cleanup previous test state if any
$conn->query("DELETE FROM user_two_factor WHERE user_type = '{$testType}' AND user_id = {$testUserId}");

// 1. Generate TOTP secret and backup codes
echo "[1] Testing Secret & Backup Code Generation...\n";
$secret = tfa_generate_secret(16);
$codes = tfa_generate_backup_codes(8);
if (strlen($secret) < 16) {
    echo "FAIL: Secret length too short: $secret\n";
    exit(1);
}
if (count($codes['plain']) !== 8 || count($codes['hashed']) !== 8) {
    echo "FAIL: Expected 8 plain and hashed backup codes.\n";
    exit(1);
}
echo "    -> Generated Secret: {$secret}\n";
echo "    -> Sample Plain Backup Code: {$codes['plain'][0]}\n";

// 2. Enable TOTP in DB
echo "\n[2] Enabling TOTP for user ({$testType}, {$testUserId})...\n";
$enabled = tfa_enable($conn, $testType, $testUserId, 'totp', $secret, $codes['hashed']);
if (!$enabled) {
    echo "FAIL: tfa_enable failed.\n";
    exit(1);
}
$status = tfa_get_status($conn, $testType, $testUserId);
if (!$status['is_enabled'] || $status['method'] !== 'totp' || $status['remaining_backup_codes'] !== 8) {
    echo "FAIL: Status mismatch after enabling: " . json_encode($status) . "\n";
    exit(1);
}
echo "    -> 2FA successfully enabled in DB: method=totp, remaining_backup_codes=8\n";

// 3. Test TOTP Verification
echo "\n[3] Testing Current Time-Slice TOTP Verification...\n";
$currentTotp = tfa_compute_totp($secret);
$usedBackup = false;
$verifyOk = tfa_verify_code($conn, $testType, $testUserId, $currentTotp, $usedBackup);
if (!$verifyOk || $usedBackup) {
    echo "FAIL: Current TOTP verification failed.\n";
    exit(1);
}
echo "    -> Verified live TOTP code '{$currentTotp}' successfully!\n";

// 4. Test Rejection of Bad Code
echo "\n[4] Testing Rejection of Bad Code...\n";
$badOk = tfa_verify_code($conn, $testType, $testUserId, '000000', $usedBackup);
if ($badOk) {
    echo "FAIL: Bad code '000000' was accepted unexpectedly!\n";
    exit(1);
}
echo "    -> Correctly rejected invalid code '000000'.\n";

// 5. Test Backup Code Redemption
echo "\n[5] Testing Single-Use Backup Code Redemption...\n";
$backupToTest = $codes['plain'][0];
$backupUsed = false;
$backupOk = tfa_verify_code($conn, $testType, $testUserId, $backupToTest, $backupUsed);
if (!$backupOk || !$backupUsed) {
    echo "FAIL: Backup code verification failed.\n";
    exit(1);
}
echo "    -> Accepted backup code: {$backupToTest}\n";

$statusAfterBackup = tfa_get_status($conn, $testType, $testUserId);
if ($statusAfterBackup['remaining_backup_codes'] !== 7) {
    echo "FAIL: Expected remaining backup codes to be 7, got {$statusAfterBackup['remaining_backup_codes']}\n";
    exit(1);
}
echo "    -> Remaining backup codes correctly decremented to: {$statusAfterBackup['remaining_backup_codes']}\n";

// Test Re-use of same backup code
$reused = tfa_verify_code($conn, $testType, $testUserId, $backupToTest, $backupUsed);
if ($reused) {
    echo "FAIL: Single-use backup code was accepted a second time!\n";
    exit(1);
}
echo "    -> Reused backup code was properly rejected.\n";

// 6. Test Email OTP Switch and Verification
echo "\n[6] Testing Email OTP Mode...\n";
$switched = tfa_switch_method($conn, $testType, $testUserId, 'email');
if (!$switched) {
    echo "FAIL: Failed to switch method to 'email'.\n";
    exit(1);
}
$statusEmail = tfa_get_status($conn, $testType, $testUserId);
if ($statusEmail['method'] !== 'email') {
    echo "FAIL: Expected method 'email', got {$statusEmail['method']}\n";
    exit(1);
}

// Generate OTP manually / insert mock hash into user_two_factor
$mockEmailOtp = '582914';
$mockOtpHash = password_hash($mockEmailOtp, PASSWORD_DEFAULT);
$mockExpires = date('Y-m-d H:i:s', time() + 600);
$stmt = $conn->prepare("UPDATE user_two_factor SET email_otp_hash = ?, email_otp_expires_at = ? WHERE user_type = ? AND user_id = ?");
$stmt->bind_param("sssi", $mockOtpHash, $mockExpires, $testType, $testUserId);
$stmt->execute();
$stmt->close();

$badEmailOtp = tfa_verify_email_otp($conn, $testType, $testUserId, '111111');
if ($badEmailOtp) {
    echo "FAIL: Bad email OTP accepted unexpectedly!\n";
    exit(1);
}
echo "    -> Invalid email OTP correctly rejected.\n";

$goodEmailOtp = tfa_verify_email_otp($conn, $testType, $testUserId, $mockEmailOtp);
if (!$goodEmailOtp) {
    echo "FAIL: Good email OTP failed verification.\n";
    exit(1);
}
echo "    -> Valid email OTP {$mockEmailOtp} verified successfully!\n";

$reusedEmailOtp = tfa_verify_email_otp($conn, $testType, $testUserId, $mockEmailOtp);
if ($reusedEmailOtp) {
    echo "FAIL: Email OTP was accepted after being consumed once!\n";
    exit(1);
}
echo "    -> Consumed email OTP was properly invalidated.\n";

// 7. Test Session Pending Manager
echo "\n[7] Testing Pending 2FA Login Session Flow...\n";
tfa_start_pending_session($testType, $testUserId, ['test_key' => 'test_val'], 'totp', 'user@example.com', 'Test User', 'admin');
$pending = tfa_get_pending_session();
if (!$pending || $pending['user_id'] !== $testUserId || $pending['method'] !== 'totp') {
    echo "FAIL: Pending session not matching: " . json_encode($pending) . "\n";
    exit(1);
}
echo "    -> Pending session successfully established.\n";
tfa_clear_pending_session();
$cleared = tfa_get_pending_session();
if ($cleared !== null) {
    echo "FAIL: Pending session was not cleared!\n";
    exit(1);
}
echo "    -> Pending session successfully cleared.\n";

// 8. Test API 2FA Temp Token (JWT)
echo "\n[8] Testing API 2FA JWT Flow...\n";
$tempJwt = JWT::generate([
    'sub'       => $testUserId,
    'user_id'   => $testUserId,
    'user_type' => $testType,
    'email'     => 'user@example.com',
    'name'      => 'Test User',
    'role'      => 'tenant',
], 300, '2fa_temp');

if (empty($tempJwt)) {
    echo "FAIL: Could not generate temp JWT.\n";
    exit(1);
}
$decoded = JWT::verify($tempJwt, '2fa_temp');
if (!$decoded || ($decoded['type'] ?? '') !== '2fa_temp' || (int)$decoded['user_id'] !== $testUserId) {
    echo "FAIL: Decoded JWT payload mismatch: " . json_encode($decoded) . "\n";
    exit(1);
}
echo "    -> API 2FA temp JWT generated & verified successfully.\n";

// 9. Clean up test record
echo "\n[9] Cleaning up test record...\n";
tfa_disable($conn, $testType, $testUserId);
$conn->query("DELETE FROM user_two_factor WHERE user_type = '{$testType}' AND user_id = {$testUserId}");
$statusFinal = tfa_get_status($conn, $testType, $testUserId);
if ($statusFinal['is_enabled']) {
    echo "FAIL: 2FA still marked enabled after disable.\n";
    exit(1);
}
echo "    -> Cleaned up successfully.\n";

echo "\n======================================================\n";
echo " ALL 9 END-TO-END 2FA CHECKS PASSED WITH 100% SUCCESS! \n";
echo "======================================================\n";
