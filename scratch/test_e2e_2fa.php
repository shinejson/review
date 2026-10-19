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
$conn->query("DELETE FROM user_trusted_devices WHERE user_type = '{$testType}' AND user_id = {$testUserId}");

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

// 6. Test Email OTP Mode
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

// 8. Test Mandatory 2FA Setup Pending Flow
echo "\n[8] Testing Mandatory 2FA Setup Pending Flow...\n";
tfa_start_pending_setup_session($testType, $testUserId, ['setup_key' => 'setup_val'], 'user@example.com', 'Test User', 'admin');
$pendingSetup = tfa_get_pending_setup_session();
if (!$pendingSetup || $pendingSetup['user_id'] !== $testUserId || $pendingSetup['portal'] !== 'admin') {
    echo "FAIL: Pending setup session not matching: " . json_encode($pendingSetup) . "\n";
    exit(1);
}
echo "    -> Pending setup session successfully created.\n";
tfa_clear_pending_setup_session();
$clearedSetup = tfa_get_pending_setup_session();
if ($clearedSetup !== null) {
    echo "FAIL: Pending setup session was not cleared!\n";
    exit(1);
}
echo "    -> Pending setup session successfully cleared.\n";

// 9. Test "Trust this device for 30 days" Mechanics
echo "\n[9] Testing 'Trust this device for 30 days' Cookie & DB State...\n";
// Ensure trusted device table exists
$chkTable = $conn->query("SHOW TABLES LIKE 'user_trusted_devices'");
if (!$chkTable || $chkTable->num_rows !== 1) {
    echo "FAIL: user_trusted_devices table does not exist.\n";
    exit(1);
}

// Register device as trusted for 30 days
$trustedSaved = tfa_trust_device($conn, $testType, $testUserId, 30);
// Check DB record
$qDev = $conn->query("SELECT * FROM user_trusted_devices WHERE user_type = '{$testType}' AND user_id = {$testUserId}");
if (!$qDev || $qDev->num_rows !== 1) {
    echo "FAIL: Trusted device record was not inserted in database.\n";
    exit(1);
}
$devRow = $qDev->fetch_assoc();
echo "    -> Trusted device record persisted with token hash: " . substr($devRow['device_token_hash'], 0, 16) . "...\n";
echo "    -> Device expires at: {$devRow['expires_at']}\n";

// Simulate cookie received from browser
$rawToken = 'test_raw_token_value_32_bytes_len_123';
$tokenHash = hash('sha256', $rawToken);
$futureExp = time() + (30 * 86400);
$futureDate = date('Y-m-d H:i:s', $futureExp);
$conn->query("UPDATE user_trusted_devices SET device_token_hash = '{$tokenHash}', expires_at = '{$futureDate}' WHERE id = {$devRow['id']}");

$payloadB64 = base64_encode(json_encode([
    'type' => $testType,
    'uid'  => $testUserId,
    'tok'  => $rawToken,
    'exp'  => $futureExp,
]));
$sig = hash_hmac('sha256', $payloadB64, tfa_get_app_secret());
$_COOKIE['optibiz_trusted_device'] = $payloadB64 . '.' . $sig;

// Verify device is trusted
$isTrustedNow = tfa_is_device_trusted($conn, $testType, $testUserId);
if (!$isTrustedNow) {
    echo "FAIL: tfa_is_device_trusted returned false for valid signed 30-day token!\n";
    exit(1);
}
echo "    -> Valid 30-day trusted device recognized successfully! (2FA will be skipped on this device)\n";

// 10. Test Tampered Cookie Rejection
echo "\n[10] Testing Rejection of Tampered Trusted Device Cookie...\n";
$_COOKIE['optibiz_trusted_device'] = $payloadB64 . '.invalid_tampered_signature';
$tamperedResult = tfa_is_device_trusted($conn, $testType, $testUserId);
if ($tamperedResult) {
    echo "FAIL: Tampered cookie was accepted!\n";
    exit(1);
}
echo "    -> Tampered cookie signature was properly rejected.\n";

// 11. Test Expired Device Rejection
echo "\n[11] Testing Expired Device Rejection...\n";
$conn->query("UPDATE user_trusted_devices SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = {$devRow['id']}");
$_COOKIE['optibiz_trusted_device'] = $payloadB64 . '.' . $sig; // restores valid sig but DB expired
$expiredResult = tfa_is_device_trusted($conn, $testType, $testUserId);
if ($expiredResult) {
    echo "FAIL: Expired device was accepted!\n";
    exit(1);
}
echo "    -> Expired 30-day device was properly rejected.\n";

// 12. Test Revocation of User Devices
echo "\n[12] Testing Revocation of User Devices...\n";
tfa_revoke_user_devices($conn, $testType, $testUserId);
$chkRevoked = $conn->query("SELECT COUNT(*) AS c FROM user_trusted_devices WHERE user_type = '{$testType}' AND user_id = {$testUserId}");
$revCount = (int)$chkRevoked->fetch_assoc()['c'];
if ($revCount !== 0) {
    echo "FAIL: Expected 0 devices after revoke, found {$revCount}\n";
    exit(1);
}
echo "    -> User devices successfully revoked and cleared from DB.\n";

// 13. Test API 2FA Temp Token (JWT)
echo "\n[13] Testing API 2FA JWT Flow...\n";
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

// 14. Test WhatsApp 2FA Flows & Phone Collection
echo "\n[14] Testing WhatsApp 2FA, Phone Masking & OTP Flow...\n";

// Test phone masking
$maskedPhone = tfa_mask_phone('+233241234567');
if (strpos($maskedPhone, '•••') === false) {
    echo "FAIL: Phone masking failed: {$maskedPhone}\n";
    exit(1);
}
echo "    -> Phone masking verified: +233241234567 -> {$maskedPhone}\n";

// Enable 2FA with method 'whatsapp'
$codesWa = tfa_generate_backup_codes(8);
$enabledWa = tfa_enable($conn, $testType, $testUserId, 'whatsapp', '', $codesWa['hashed'], '+233241234567');
if (!$enabledWa) {
    echo "FAIL: tfa_enable with whatsapp failed.\n";
    exit(1);
}
$statusWa = tfa_get_status($conn, $testType, $testUserId);
if (!$statusWa['is_enabled'] || $statusWa['method'] !== 'whatsapp') {
    echo "FAIL: WhatsApp status mismatch: " . json_encode($statusWa) . "\n";
    exit(1);
}
echo "    -> WhatsApp 2FA enabled in DB: method=whatsapp\n";

// Test tfa_send_whatsapp_otp
$sendWaRes = tfa_send_whatsapp_otp($conn, $testType, $testUserId, '+233241234567', 'Test Tenant');
if (empty($sendWaRes['success']) || empty($sendWaRes['code'])) {
    echo "FAIL: tfa_send_whatsapp_otp failed: " . json_encode($sendWaRes) . "\n";
    exit(1);
}
$waCode = $sendWaRes['code'];
echo "    -> WhatsApp OTP generated: {$waCode}, wa_url: {$sendWaRes['wa_url']}\n";

// Test invalid code rejection
$invalidWa = tfa_verify_whatsapp_otp($conn, $testType, $testUserId, '000000');
if ($invalidWa) {
    echo "FAIL: Invalid WhatsApp code '000000' accepted unexpectedly!\n";
    exit(1);
}
echo "    -> Invalid WhatsApp OTP correctly rejected.\n";

// Test valid code via unified tfa_verify_code
$usedBackupWa = false;
$validWa = tfa_verify_code($conn, $testType, $testUserId, $waCode, $usedBackupWa);
if (!$validWa || $usedBackupWa) {
    echo "FAIL: Valid WhatsApp code verification failed via tfa_verify_code.\n";
    exit(1);
}
echo "    -> Valid WhatsApp OTP verified successfully via tfa_verify_code!\n";

// Test single-use invalidation
$reusedWa = tfa_verify_code($conn, $testType, $testUserId, $waCode, $usedBackupWa);
if ($reusedWa) {
    echo "FAIL: Consumed WhatsApp OTP was accepted again!\n";
    exit(1);
}
echo "    -> Reused WhatsApp OTP properly invalidated.\n";

// Test method switching to 'email' and back to 'whatsapp'
$switchBack = tfa_switch_method($conn, $testType, $testUserId, 'email');
if (!$switchBack || tfa_get_status($conn, $testType, $testUserId)['method'] !== 'email') {
    echo "FAIL: Failed to switch from whatsapp to email.\n";
    exit(1);
}
$switchToWa = tfa_switch_method($conn, $testType, $testUserId, 'whatsapp');
if (!$switchToWa || tfa_get_status($conn, $testType, $testUserId)['method'] !== 'whatsapp') {
    echo "FAIL: Failed to switch back to whatsapp.\n";
    exit(1);
}
echo "    -> Switching to/from 'whatsapp' method verified.\n";

// 15. Clean up test record
echo "\n[15] Cleaning up test records...\n";
tfa_disable($conn, $testType, $testUserId);
$conn->query("DELETE FROM user_two_factor WHERE user_type = '{$testType}' AND user_id = {$testUserId}");
$conn->query("DELETE FROM user_trusted_devices WHERE user_type = '{$testType}' AND user_id = {$testUserId}");
unset($_COOKIE['optibiz_trusted_device']);
$statusFinal = tfa_get_status($conn, $testType, $testUserId);
if ($statusFinal['is_enabled']) {
    echo "FAIL: 2FA still marked enabled after disable.\n";
    exit(1);
}
echo "    -> Cleaned up successfully.\n";

echo "\n=========================================================\n";
echo " ALL 14 END-TO-END 2FA & 30-DAY TRUST CHECKS PASSED (100%) \n";
echo "=========================================================\n";
