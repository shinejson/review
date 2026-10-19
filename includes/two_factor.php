<?php
/**
 * ============================================================
 *  Optibiz Two-Factor Authentication (2FA) Module
 * ============================================================
 *  Provides RFC 6238 Time-Based One-Time Password (TOTP) and
 *  Email-based OTP authentication, with single-use backup
 *  recovery codes and zero external runtime dependencies.
 *
 *  Supported user types:
 *    - 'tenant'      (Workspace owner)
 *    - 'team_member' (Workspace staff)
 *    - 'superadmin'  (Platform super admin)
 *    - 'admin'       (Platform admin)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/sa_helpers.php';

/* ============================================================
 *  Schema & Migration
 * ============================================================ */

/**
 * Ensures the user_two_factor table exists in the database.
 * Auto-creates it on first use.
 *
 * @param mysqli $conn
 */
function tfa_ensure_schema($conn)
{
    static $ensured = false;
    if ($ensured || !is_object($conn) || !method_exists($conn, 'query')) {
        return;
    }
    $ensured = true;

    $sql = "CREATE TABLE IF NOT EXISTS user_two_factor (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_type VARCHAR(32) NOT NULL,
        user_id INT NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        method VARCHAR(16) NOT NULL DEFAULT 'totp',
        secret VARCHAR(64) NULL,
        phone VARCHAR(30) NULL,
        backup_codes TEXT NULL,
        email_otp_hash VARCHAR(255) NULL,
        email_otp_expires_at DATETIME NULL,
        whatsapp_otp_hash VARCHAR(255) NULL,
        whatsapp_otp_expires_at DATETIME NULL,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_type_id (user_type, user_id),
        INDEX idx_user_lookup (user_type, user_id, is_enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    @$conn->query($sql);

    // Auto-heal missing columns in existing user_two_factor table
    $tfaCols = [];
    $resTfa = @$conn->query("SHOW COLUMNS FROM user_two_factor");
    if ($resTfa) {
        while ($r = $resTfa->fetch_assoc()) {
            $tfaCols[strtolower($r['Field'])] = true;
        }
    }
    if (!isset($tfaCols['phone'])) {
        @$conn->query("ALTER TABLE user_two_factor ADD COLUMN phone VARCHAR(30) NULL AFTER secret");
    }
    if (!isset($tfaCols['whatsapp_otp_hash'])) {
        @$conn->query("ALTER TABLE user_two_factor ADD COLUMN whatsapp_otp_hash VARCHAR(255) NULL AFTER email_otp_expires_at");
    }
    if (!isset($tfaCols['whatsapp_otp_expires_at'])) {
        @$conn->query("ALTER TABLE user_two_factor ADD COLUMN whatsapp_otp_expires_at DATETIME NULL AFTER whatsapp_otp_hash");
    }

    $sqlTrusted = "CREATE TABLE IF NOT EXISTS user_trusted_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_type VARCHAR(32) NOT NULL,
        user_id INT NOT NULL,
        device_token_hash VARCHAR(64) NOT NULL,
        device_name VARCHAR(255) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lookup (user_type, user_id, device_token_hash),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    @$conn->query($sqlTrusted);

    // Auto-heal phone column in super_admins table
    $resSa = @$conn->query("SHOW COLUMNS FROM super_admins LIKE 'phone'");
    if ($resSa && $resSa->num_rows === 0) {
        @$conn->query("ALTER TABLE super_admins ADD COLUMN phone VARCHAR(30) NULL AFTER email");
    }

    // Auto-heal phone column in team_members table
    $resTm = @$conn->query("SHOW COLUMNS FROM team_members LIKE 'phone'");
    if ($resTm && $resTm->num_rows === 0) {
        @$conn->query("ALTER TABLE team_members ADD COLUMN phone VARCHAR(30) NULL AFTER email");
    }
}

/* ============================================================
 *  RFC 4648 Base32 Encoding / Decoding
 * ============================================================ */

/**
 * Base32 Alphabet for RFC 4648
 */
define('TFA_BASE32_ALPHABET', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567');

/**
 * Encodes binary data into a Base32 string.
 *
 * @param string $data
 * @return string
 */
function tfa_base32_encode($data)
{
    if ($data === '') {
        return '';
    }
    $alphabet = TFA_BASE32_ALPHABET;
    $bin = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $bin .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $rem = strlen($bin) % 5;
    if ($rem > 0) {
        $bin .= str_repeat('0', 5 - $rem);
    }
    $b32 = '';
    $binLen = strlen($bin);
    for ($i = 0; $i < $binLen; $i += 5) {
        $val = bindec(substr($bin, $i, 5));
        $b32 .= $alphabet[$val];
    }
    return $b32;
}

/**
 * Decodes a Base32 string into binary data.
 *
 * @param string $b32
 * @return string
 */
function tfa_base32_decode($b32)
{
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
    if ($b32 === '') {
        return '';
    }
    $alphabet = TFA_BASE32_ALPHABET;
    $bin = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) {
            continue;
        }
        $bin .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    $binLen = strlen($bin);
    for ($i = 0; $i + 8 <= $binLen; $i += 8) {
        $bytes .= chr(bindec(substr($bin, $i, 8)));
    }
    return $bytes;
}

/**
 * Generates a random Base32 secret for TOTP.
 * Default is 16 bytes = 26 base32 characters (128 bits of entropy).
 *
 * @param int $byteLength
 * @return string
 */
function tfa_generate_secret($byteLength = 16)
{
    try {
        $bytes = random_bytes($byteLength);
    } catch (Exception $e) {
        $bytes = openssl_random_pseudo_bytes($byteLength);
    }
    return tfa_base32_encode($bytes);
}

/* ============================================================
 *  RFC 6238 TOTP (Time-Based One-Time Password)
 * ============================================================ */

/**
 * Computes a 6-digit TOTP code for a given secret and time slice.
 *
 * @param string $secret Base32 encoded secret
 * @param int|null $timeSlice 30-second window (defaults to current time / 30)
 * @return string 6-digit zero-padded string
 */
function tfa_compute_totp($secret, $timeSlice = null)
{
    if ($timeSlice === null) {
        $timeSlice = (int)floor(time() / 30);
    }
    $key = tfa_base32_decode($secret);
    // 64-bit big endian integer
    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $val = (((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF));
    return str_pad((string)($val % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Verifies a 6-digit TOTP code against a secret with time drift tolerance.
 *
 * @param string $secret
 * @param string $code
 * @param int $discrepancy Number of 30-second steps to check (1 = -30s to +30s)
 * @return bool
 */
function tfa_verify_totp($secret, $code, $discrepancy = 1)
{
    $code = trim((string)$code);
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        return false;
    }
    $currentSlice = (int)floor(time() / 30);
    for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
        $expected = tfa_compute_totp($secret, $currentSlice + $i);
        if (hash_equals($expected, $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Builds standard otpauth:// URL for QR code and Authenticator apps.
 *
 * @param string $accountLabel e.g. "admin@abccorp.com"
 * @param string $secret Base32 secret
 * @param string $issuer e.g. "Optibiz"
 * @return string
 */
function tfa_get_otpauth_url($accountLabel, $secret, $issuer = 'Optibiz')
{
    $cleanSecret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
    $cleanLabel = trim($accountLabel);
    $cleanIssuer = trim($issuer);
    return 'otpauth://totp/' . rawurlencode($cleanIssuer) . ':' . rawurlencode($cleanLabel)
        . '?secret=' . $cleanSecret
        . '&issuer=' . rawurlencode($cleanIssuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * Generates an image URL for the QR code.
 *
 * @param string $otpauthUrl
 * @param int $size
 * @return string
 */
function tfa_get_qr_url($otpauthUrl, $size = 220)
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . (int)$size . 'x' . (int)$size . '&margin=8&data=' . rawurlencode($otpauthUrl);
}

/**
 * Formats a secret key into readable 4-character chunks.
 * e.g. "JBSWY3DPEHPK3PXP" -> "JBSW Y3DP EHPK 3PXP"
 *
 * @param string $secret
 * @return string
 */
function tfa_format_secret($secret)
{
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
    return trim(chunk_split($clean, 4, ' '));
}

/* ============================================================
 *  Backup Recovery Codes
 * ============================================================ */

/**
 * Generates cryptographically secure single-use recovery codes.
 *
 * @param int $count Number of codes to generate (default 8)
 * @return array ['plain' => [...], 'hashed' => [...]]
 */
function tfa_generate_backup_codes($count = 8)
{
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < $count; $i++) {
        // Format: XXXX-XXXX (e.g. 7A8B-9C2D)
        $bytes = bin2hex(random_bytes(4));
        $code = strtoupper(substr($bytes, 0, 4) . '-' . substr($bytes, 4, 4));
        $plain[] = $code;
        $hashed[] = [
            'hash' => password_hash(str_replace('-', '', $code), PASSWORD_DEFAULT),
            'used' => false,
            'used_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
    return [
        'plain' => $plain,
        'hashed' => $hashed,
    ];
}

/* ============================================================
 *  Database Operations & Account State
 * ============================================================ */

/**
 * Fetch 2FA row for a user.
 *
 * @param mysqli $conn
 * @param string $userType 'tenant' | 'superadmin' | 'team_member' | 'admin'
 * @param int $userId
 * @return array|null
 */
function tfa_get_user_2fa($conn, $userType, $userId)
{
    tfa_ensure_schema($conn);
    $userId = (int)$userId;
    $userType = trim($userType);

    $stmt = $conn->prepare("SELECT * FROM user_two_factor WHERE user_type = ? AND user_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("si", $userType, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

/**
 * Check if 2FA is enabled for a user.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @return bool
 */
function tfa_is_enabled($conn, $userType, $userId)
{
    $row = tfa_get_user_2fa($conn, $userType, $userId);
    return !empty($row['is_enabled']) && (int)$row['is_enabled'] === 1;
}

/**
 * Get 2FA summary status for display in settings.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @return array
 */
function tfa_get_status($conn, $userType, $userId)
{
    $row = tfa_get_user_2fa($conn, $userType, $userId);
    if (!$row || (int)$row['is_enabled'] !== 1) {
        return [
            'is_enabled' => false,
            'method' => 'totp',
            'phone' => null,
            'remaining_backup_codes' => 0,
            'last_used_at' => null,
        ];
    }

    $codes = json_decode((string)($row['backup_codes'] ?? '[]'), true) ?: [];
    $remaining = 0;
    foreach ($codes as $c) {
        if (empty($c['used'])) {
            $remaining++;
        }
    }

    return [
        'is_enabled' => true,
        'method' => $row['method'] ?? 'totp',
        'phone' => $row['phone'] ?? null,
        'remaining_backup_codes' => $remaining,
        'total_backup_codes' => count($codes),
        'last_used_at' => $row['last_used_at'] ?? null,
    ];
}

/**
 * Enables 2FA for a user (saves secret/method/backup codes/phone).
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param string $method 'totp' | 'email' | 'whatsapp'
 * @param string $secret Base32 secret for TOTP (can be empty if email/whatsapp)
 * @param array $hashedBackupCodes
 * @param string|null $phone
 * @return bool
 */
function tfa_enable($conn, $userType, $userId, $method, $secret, array $hashedBackupCodes, $phone = null)
{
    tfa_ensure_schema($conn);
    $userId = (int)$userId;
    $userType = trim($userType);
    $method = in_array($method, ['totp', 'email', 'whatsapp'], true) ? $method : 'totp';
    $codesJson = json_encode($hashedBackupCodes);

    if ($phone !== null && $phone !== '') {
        $stmt = $conn->prepare("INSERT INTO user_two_factor (user_type, user_id, is_enabled, method, secret, phone, backup_codes)
            VALUES (?, ?, 1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_enabled = 1, method = VALUES(method), secret = VALUES(secret), phone = VALUES(phone), backup_codes = VALUES(backup_codes)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("sissss", $userType, $userId, $method, $secret, $phone, $codesJson);
    } else {
        $stmt = $conn->prepare("INSERT INTO user_two_factor (user_type, user_id, is_enabled, method, secret, backup_codes)
            VALUES (?, ?, 1, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_enabled = 1, method = VALUES(method), secret = VALUES(secret), backup_codes = VALUES(backup_codes)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("sisss", $userType, $userId, $method, $secret, $codesJson);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Disables 2FA for a user.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @return bool
 */
function tfa_disable($conn, $userType, $userId)
{
    tfa_ensure_schema($conn);
    $userId = (int)$userId;
    $userType = trim($userType);

    $stmt = $conn->prepare("UPDATE user_two_factor SET is_enabled = 0, secret = NULL, backup_codes = NULL, email_otp_hash = NULL, whatsapp_otp_hash = NULL WHERE user_type = ? AND user_id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("si", $userType, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Switches the active 2FA method between 'totp', 'whatsapp', and 'email'.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param string $method
 * @return bool
 */
function tfa_switch_method($conn, $userType, $userId, $method)
{
    tfa_ensure_schema($conn);
    $userId = (int)$userId;
    $userType = trim($userType);
    $method = in_array($method, ['totp', 'email', 'whatsapp'], true) ? $method : 'totp';

    $stmt = $conn->prepare("UPDATE user_two_factor SET method = ? WHERE user_type = ? AND user_id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssi", $method, $userType, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Regenerates backup recovery codes for an enabled user.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @return array|false Returns plain codes array on success, false on failure
 */
function tfa_regenerate_backup_codes($conn, $userType, $userId)
{
    tfa_ensure_schema($conn);
    $userId = (int)$userId;
    $userType = trim($userType);

    $gen = tfa_generate_backup_codes(8);
    $codesJson = json_encode($gen['hashed']);

    $stmt = $conn->prepare("UPDATE user_two_factor SET backup_codes = ? WHERE user_type = ? AND user_id = ? AND is_enabled = 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssi", $codesJson, $userType, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? $gen['plain'] : false;
}

/* ============================================================
 *  Email OTP Operations
 * ============================================================ */

/**
 * Generates and sends a 6-digit Email OTP to the user's email address.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param string $toEmail
 * @param string $displayName
 * @return array ['success' => bool, 'message' => string]
 */
function tfa_send_email_otp($conn, $userType, $userId, $toEmail, $displayName = '')
{
    tfa_ensure_schema($conn);
    $userId = (int)$userId;
    $userType = trim($userType);
    $toEmail = trim($toEmail);

    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'No valid email address found for this account.'];
    }

    // Generate 6-digit numeric OTP
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    // Upsert or update record in user_two_factor
    $stmt = $conn->prepare("INSERT INTO user_two_factor (user_type, user_id, method, email_otp_hash, email_otp_expires_at)
        VALUES (?, ?, 'email', ?, ?)
        ON DUPLICATE KEY UPDATE email_otp_hash = VALUES(email_otp_hash), email_otp_expires_at = VALUES(email_otp_expires_at)");
    if ($stmt) {
        $stmt->bind_param("siss", $userType, $userId, $hash, $expires);
        $stmt->execute();
        $stmt->close();
    }

    // Compose HTML email
    $siteName = function_exists('sa_setting') && $conn ? sa_setting($conn, 'site_name', 'Optibiz') : 'Optibiz';
    $title = 'Your Optibiz Two-Factor Verification Code';
    $greeting = $displayName ? 'Hello ' . htmlspecialchars($displayName) . ',' : 'Hello,';

    $html = '<p style="margin:0 0 16px 0;font-size:15px;color:#cbd5e1;">' . $greeting . '</p>
    <p style="margin:0 0 20px 0;font-size:15px;color:#cbd5e1;">
        Use the following one-time security code to verify your sign-in to your <strong>' . htmlspecialchars($siteName) . '</strong> account:
    </p>
    <div style="margin: 28px 0; text-align: center;">
        <div style="display:inline-block;background:#132d46;border:2px dashed #c2f542;border-radius:12px;padding:16px 36px;letter-spacing:8px;font-size:32px;font-weight:800;color:#c2f542;font-family:monospace;">
            ' . htmlspecialchars($code) . '
        </div>
    </div>
    <p style="margin:0 0 12px 0;font-size:13.5px;color:#94a3b8;text-align:center;">
        This code is valid for <strong>10 minutes</strong>. Do not share this code with anyone.
    </p>
    <p style="margin:24px 0 0 0;font-size:12px;color:#64748b;border-top:1px solid rgba(148,163,184,0.14);padding-top:16px;">
        If you did not attempt to sign in to your account, someone else may have entered your password. Please sign in and update your password immediately.
    </p>';

    $mailResult = sa_send_mail($toEmail, "$code is your Optibiz verification code", $html, $conn);
    return $mailResult;
}

/**
 * Verifies an Email OTP.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param string $code
 * @return bool
 */
function tfa_verify_email_otp($conn, $userType, $userId, $code)
{
    $code = trim((string)$code);
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        return false;
    }

    $row = tfa_get_user_2fa($conn, $userType, $userId);
    if (!$row || empty($row['email_otp_hash']) || empty($row['email_otp_expires_at'])) {
        return false;
    }

    // Check expiry
    if (strtotime($row['email_otp_expires_at']) < time()) {
        return false;
    }

    // Check code
    if (password_verify($code, $row['email_otp_hash'])) {
        // Clear used OTP and touch last_used_at
        $stmt = $conn->prepare("UPDATE user_two_factor SET email_otp_hash = NULL, email_otp_expires_at = NULL, last_used_at = NOW() WHERE user_type = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $userType, $userId);
            $stmt->execute();
            $stmt->close();
        }
        return true;
    }

    return false;
}

/* ============================================================
 *  WhatsApp 2FA & User Contact Operations
 * ============================================================ */

/**
 * Masks a phone number for privacy display (e.g. "+233 24 ••• ••18").
 *
 * @param string $phone
 * @return string
 */
function tfa_mask_phone($phone)
{
    $clean = trim((string)$phone);
    if ($clean === '') {
        return 'your phone';
    }
    $digits = preg_replace('/\D+/', '', $clean);
    $len = strlen($digits);
    if ($len < 6) {
        return htmlspecialchars($clean);
    }
    $prefix = (strpos($clean, '+') === 0) ? '+' : '';
    $head = substr($digits, 0, min(5, (int)($len / 2)));
    $tail = substr($digits, -2);
    return $prefix . $head . ' ••• ••' . $tail;
}

/**
 * Retrieves the contact profile (email, phone, name) for a user.
 *
 * @param mysqli $conn
 * @param string $userType 'tenant' | 'team_member' | 'superadmin' | 'admin'
 * @param int $userId
 * @return array ['email' => string, 'phone' => string, 'name' => string]
 */
function tfa_get_user_contact($conn, $userType, $userId)
{
    $userId = (int)$userId;
    $res = ['email' => '', 'phone' => '', 'name' => ''];
    if ($userId <= 0 || !is_object($conn)) {
        return $res;
    }

    tfa_ensure_schema($conn);

    if ($userType === 'tenant') {
        $stmt = $conn->prepare("SELECT email, phone, company_name, username FROM tenants WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $res['email'] = (string)($row['email'] ?? '');
                $res['phone'] = (string)($row['phone'] ?? '');
                $res['name']  = (string)($row['company_name'] ?: $row['username']);
            }
            $stmt->close();
        }
    } elseif ($userType === 'team_member') {
        $stmt = $conn->prepare("SELECT email, phone, full_name, username FROM team_members WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $res['email'] = (string)($row['email'] ?? '');
                $res['phone'] = (string)($row['phone'] ?? '');
                $res['name']  = (string)($row['full_name'] ?: $row['username']);
            }
            $stmt->close();
        }
    } elseif ($userType === 'superadmin') {
        $stmt = $conn->prepare("SELECT email, phone, username FROM super_admins WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $res['email'] = (string)($row['email'] ?? '');
                $res['phone'] = (string)($row['phone'] ?? '');
                $res['name']  = (string)($row['username'] ?? 'Super Admin');
            }
            $stmt->close();
        }
    } elseif ($userType === 'admin') {
        $stmt = $conn->prepare("SELECT email, phone, username FROM admins WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $res['email'] = (string)($row['email'] ?? '');
                $res['phone'] = (string)($row['phone'] ?? '');
                $res['name']  = (string)($row['username'] ?? 'Admin');
            }
            $stmt->close();
        }
    }

    // Also check if user_two_factor has a phone stored
    $tfaRow = tfa_get_user_2fa($conn, $userType, $userId);
    if (!empty($tfaRow['phone']) && empty($res['phone'])) {
        $res['phone'] = (string)$tfaRow['phone'];
    }

    return $res;
}

/**
 * Updates a user's contact information (email and/or phone) in their primary table and 2FA record.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param string|null $email
 * @param string|null $phone
 * @return bool
 */
function tfa_update_user_contact($conn, $userType, $userId, $email = null, $phone = null)
{
    $userId = (int)$userId;
    if ($userId <= 0 || !is_object($conn)) {
        return false;
    }
    tfa_ensure_schema($conn);

    if ($phone !== null) {
        $phone = trim($phone);
    }
    if ($email !== null) {
        $email = trim($email);
    }

    // Update in user_two_factor
    if ($phone !== null) {
        $stmtTfa = $conn->prepare("INSERT INTO user_two_factor (user_type, user_id, phone)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE phone = VALUES(phone)");
        if ($stmtTfa) {
            $stmtTfa->bind_param("sis", $userType, $userId, $phone);
            $stmtTfa->execute();
            $stmtTfa->close();
        }
    }

    // Update in primary table
    if ($userType === 'tenant') {
        if ($email !== null && $phone !== null) {
            $stmt = $conn->prepare("UPDATE tenants SET email = ?, phone = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("ssi", $email, $phone, $userId); $stmt->execute(); $stmt->close(); }
        } elseif ($phone !== null) {
            $stmt = $conn->prepare("UPDATE tenants SET phone = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("si", $phone, $userId); $stmt->execute(); $stmt->close(); }
        } elseif ($email !== null) {
            $stmt = $conn->prepare("UPDATE tenants SET email = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("si", $email, $userId); $stmt->execute(); $stmt->close(); }
        }
    } elseif ($userType === 'team_member') {
        if ($email !== null && $phone !== null) {
            $stmt = $conn->prepare("UPDATE team_members SET email = ?, phone = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("ssi", $email, $phone, $userId); $stmt->execute(); $stmt->close(); }
        } elseif ($phone !== null) {
            $stmt = $conn->prepare("UPDATE team_members SET phone = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("si", $phone, $userId); $stmt->execute(); $stmt->close(); }
        } elseif ($email !== null) {
            $stmt = $conn->prepare("UPDATE team_members SET email = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("si", $email, $userId); $stmt->execute(); $stmt->close(); }
        }
    } elseif ($userType === 'superadmin') {
        if ($email !== null && $phone !== null) {
            $stmt = $conn->prepare("UPDATE super_admins SET email = ?, phone = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("ssi", $email, $phone, $userId); $stmt->execute(); $stmt->close(); }
        } elseif ($phone !== null) {
            $stmt = $conn->prepare("UPDATE super_admins SET phone = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("si", $phone, $userId); $stmt->execute(); $stmt->close(); }
        } elseif ($email !== null) {
            $stmt = $conn->prepare("UPDATE super_admins SET email = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("si", $email, $userId); $stmt->execute(); $stmt->close(); }
        }
    }

    return true;
}

/**
 * Generates and sends a 6-digit WhatsApp OTP to the user's phone.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param string $phone
 * @param string $displayName
 * @return array ['success' => bool, 'message' => string, 'code' => string, 'wa_url' => string, 'phone' => string, 'masked' => string]
 */
function tfa_send_whatsapp_otp($conn, $userType, $userId, $phone, $displayName = '')
{
    tfa_ensure_schema($conn);
    $userId = (int)$userId;
    $userType = trim($userType);
    $phone = trim($phone);

    // Normalize phone digits
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 7) {
        return ['success' => false, 'message' => 'Please provide a valid phone number with country code.'];
    }

    // If starts with 0 and 10 digits, assume Ghana (+233)
    if ($digits[0] === '0' && strlen($digits) === 10) {
        $digits = '233' . substr($digits, 1);
        $phone = '+' . $digits;
    } elseif (strpos($phone, '+') !== 0) {
        $phone = '+' . $digits;
    }

    // Save phone to contact record
    tfa_update_user_contact($conn, $userType, $userId, null, $phone);

    // Generate 6-digit OTP
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    // Save in user_two_factor
    $stmt = $conn->prepare("INSERT INTO user_two_factor (user_type, user_id, method, phone, whatsapp_otp_hash, whatsapp_otp_expires_at)
        VALUES (?, ?, 'whatsapp', ?, ?, ?)
        ON DUPLICATE KEY UPDATE method = VALUES(method), phone = VALUES(phone), whatsapp_otp_hash = VALUES(whatsapp_otp_hash), whatsapp_otp_expires_at = VALUES(whatsapp_otp_expires_at)");
    if ($stmt) {
        $stmt->bind_param("sisss", $userType, $userId, $phone, $hash, $expires);
        $stmt->execute();
        $stmt->close();
    }

    $siteName = function_exists('sa_setting') && $conn ? sa_setting($conn, 'site_name', 'Optibiz') : 'Optibiz';
    $message = "Your {$siteName} two-factor verification code is: {$code}\nValid for 10 minutes. Do not share this code with anyone.";

    // Attempt dispatch via configured SMS/WhatsApp gateway if available
    if (file_exists(__DIR__ . '/sms_providers.php')) {
        require_once __DIR__ . '/sms_providers.php';
        if (function_exists('sms_provider_list')) {
            $providers = sms_provider_list($conn, true);
            if (!empty($providers)) {
                $firstProv = $providers[0]['provider_key'];
                sms_send_via_provider($conn, $firstProv, $digits, $message, $siteName);
            }
        }
    }

    // Generate direct click-to-open WhatsApp URL for mobile/desktop client
    $waUrl = 'https://wa.me/' . $digits . '?text=' . urlencode($message);

    // Store in session for seamless development / instant local testing
    if (session_status() === PHP_SESSION_NONE) {
        auth_session_start();
    }
    $_SESSION['last_whatsapp_otp'] = $code;
    $_SESSION['last_whatsapp_phone'] = $phone;

    $masked = tfa_mask_phone($phone);
    return [
        'success'  => true,
        'message'  => "6-digit verification code sent to WhatsApp at {$masked}.",
        'code'     => $code,
        'wa_url'   => $waUrl,
        'phone'    => $phone,
        'masked'   => $masked,
    ];
}

/**
 * Verifies a WhatsApp OTP.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param string $code
 * @return bool
 */
function tfa_verify_whatsapp_otp($conn, $userType, $userId, $code)
{
    $code = trim((string)$code);
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        return false;
    }

    $row = tfa_get_user_2fa($conn, $userType, $userId);
    if (!$row || empty($row['whatsapp_otp_hash']) || empty($row['whatsapp_otp_expires_at'])) {
        return false;
    }

    // Check expiry
    if (strtotime($row['whatsapp_otp_expires_at']) < time()) {
        return false;
    }

    // Check code
    if (password_verify($code, $row['whatsapp_otp_hash'])) {
        // Clear used OTP and touch last_used_at
        $stmt = $conn->prepare("UPDATE user_two_factor SET whatsapp_otp_hash = NULL, whatsapp_otp_expires_at = NULL, last_used_at = NOW() WHERE user_type = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $userType, $userId);
            $stmt->execute();
            $stmt->close();
        }
        return true;
    }

    return false;
}

/* ============================================================
 *  Backup Code Verification
 * ============================================================ */

/**
 * Verifies a single-use backup recovery code.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param string $code
 * @return bool
 */
function tfa_verify_backup_code($conn, $userType, $userId, $code)
{
    $codeClean = strtoupper(str_replace(['-', ' '], '', trim($code)));
    if (strlen($codeClean) !== 8) {
        return false;
    }

    $row = tfa_get_user_2fa($conn, $userType, $userId);
    if (!$row || empty($row['backup_codes'])) {
        return false;
    }

    $codes = json_decode((string)$row['backup_codes'], true);
    if (!is_array($codes)) {
        return false;
    }

    $matched = false;
    foreach ($codes as $idx => $item) {
        if (empty($item['used']) && !empty($item['hash'])) {
            if (password_verify($codeClean, $item['hash'])) {
                $codes[$idx]['used'] = true;
                $codes[$idx]['used_at'] = date('Y-m-d H:i:s');
                $matched = true;
                break;
            }
        }
    }

    if ($matched) {
        $updatedJson = json_encode($codes);
        $stmt = $conn->prepare("UPDATE user_two_factor SET backup_codes = ?, last_used_at = NOW() WHERE user_type = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $updatedJson, $userType, $userId);
            $stmt->execute();
            $stmt->close();
        }
        return true;
    }

    return false;
}

/* ============================================================
 *  Unified Code Verification
 * ============================================================ */

/**
 * Verifies any submitted 2FA code (TOTP, Email OTP, or Backup Code).
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param string $code
 * @param bool &$usedBackup Out-parameter: set to true if a backup code was consumed
 * @return bool
 */
function tfa_verify_code($conn, $userType, $userId, $code, &$usedBackup = false)
{
    $usedBackup = false;
    $code = trim((string)$code);
    if ($code === '') {
        return false;
    }

    $row = tfa_get_user_2fa($conn, $userType, $userId);
    if (!$row || (int)$row['is_enabled'] !== 1) {
        return false;
    }

    $method = $row['method'] ?? 'totp';

    // 1. Check primary method
    if ($method === 'totp' && !empty($row['secret'])) {
        if (tfa_verify_totp($row['secret'], $code)) {
            $stmt = $conn->prepare("UPDATE user_two_factor SET last_used_at = NOW() WHERE user_type = ? AND user_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $userType, $userId);
                $stmt->execute();
                $stmt->close();
            }
            return true;
        }
    } elseif ($method === 'email') {
        if (tfa_verify_email_otp($conn, $userType, $userId, $code)) {
            return true;
        }
    } elseif ($method === 'whatsapp') {
        if (tfa_verify_whatsapp_otp($conn, $userType, $userId, $code)) {
            return true;
        }
    }

    // 2. Cross-method fallback (e.g. if code was sent to WhatsApp or Email)
    if (!empty($row['whatsapp_otp_hash']) && tfa_verify_whatsapp_otp($conn, $userType, $userId, $code)) {
        return true;
    }
    if (!empty($row['email_otp_hash']) && tfa_verify_email_otp($conn, $userType, $userId, $code)) {
        return true;
    }

    // 3. Check backup recovery codes
    if (tfa_verify_backup_code($conn, $userType, $userId, $code)) {
        $usedBackup = true;
        return true;
    }

    return false;
}

/* ============================================================
 *  Pending Verification Session State (Login Interception)
 * ============================================================ */

/**
 * Initializes a pending 2FA login session.
 *
 * @param string $userType 'tenant' | 'superadmin' | 'team_member' | 'admin'
 * @param int $userId
 * @param array $sessionData Session variables to commit upon successful 2FA
 * @param string $method 'totp' | 'email' | 'whatsapp'
 * @param string $targetEmail
 * @param string $targetName
 * @param string $portal 'admin' | 'superadmin'
 * @param string $targetPhone
 */
function tfa_start_pending_session($userType, $userId, array $sessionData, $method, $targetEmail = '', $targetName = '', $portal = 'admin', $targetPhone = '')
{
    if (session_status() === PHP_SESSION_NONE) {
        auth_session_start();
    }

    // Never keep active authenticated session keys while 2FA is pending!
    unset($_SESSION['tenant_id'], $_SESSION['admin_id'], $_SESSION['super_admin_id']);

    $_SESSION['2fa_pending'] = [
        'user_type'    => $userType,
        'user_id'      => (int)$userId,
        'session_data' => $sessionData,
        'method'       => $method,
        'target_email' => $targetEmail,
        'target_name'  => $targetName,
        'target_phone' => $targetPhone,
        'portal'       => $portal,
        'started_at'   => time(),
        'attempts'     => 0,
        'last_resend'  => 0,
    ];
}

/**
 * Retrieves the active pending 2FA session, or null if absent/expired.
 * Pending sessions expire after 10 minutes (600 seconds).
 *
 * @param int $maxLifetimeSeconds Default 600
 * @return array|null
 */
function tfa_get_pending_session($maxLifetimeSeconds = 600)
{
    if (session_status() === PHP_SESSION_NONE) {
        auth_session_start();
    }

    if (empty($_SESSION['2fa_pending']) || !is_array($_SESSION['2fa_pending'])) {
        return null;
    }

    $pending = $_SESSION['2fa_pending'];
    if (empty($pending['started_at']) || (time() - (int)$pending['started_at']) > $maxLifetimeSeconds) {
        unset($_SESSION['2fa_pending']);
        return null;
    }

    return $pending;
}

/**
 * Clears the pending 2FA session (e.g. user cancelled or logged in).
 */
function tfa_clear_pending_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        auth_session_start();
    }
    unset($_SESSION['2fa_pending']);
}

/**
 * Masks an email for secure preview display (e.g. "a***n@abccorp.com").
 *
 * @param string $email
 * @return string
 */
function tfa_mask_email($email)
{
    $parts = explode('@', (string)$email);
    if (count($parts) !== 2) {
        return 'your email';
    }
    $name = $parts[0];
    $domain = $parts[1];

    $nameLen = strlen($name);
    if ($nameLen <= 2) {
        $maskedName = substr($name, 0, 1) . '***';
    } else {
        $maskedName = substr($name, 0, 1) . str_repeat('*', min(4, $nameLen - 2)) . substr($name, -1);
    }

    return $maskedName . '@' . $domain;
}

/**
 * Verifies a user's current password for sensitive 2FA actions.
 *
 * @param mysqli $conn
 * @param string $userType 'tenant' | 'superadmin' | 'team_member' | 'admin'
 * @param int $userId
 * @param string $password
 * @return bool
 */
function tfa_verify_user_password($conn, $userType, $userId, $password)
{
    $userId = (int)$userId;
    $password = (string)$password;
    if ($userId <= 0 || $password === '') {
        return false;
    }

    $hash = '';
    if ($userType === 'tenant') {
        $stmt = $conn->prepare("SELECT password FROM tenants WHERE id = ? LIMIT 1");
    } elseif ($userType === 'team_member') {
        $stmt = $conn->prepare("SELECT password FROM team_members WHERE id = ? LIMIT 1");
    } elseif ($userType === 'superadmin') {
        $stmt = $conn->prepare("SELECT password FROM super_admins WHERE id = ? LIMIT 1");
    } elseif ($userType === 'admin') {
        $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
    } else {
        return false;
    }

    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $hash = (string)($row['password'] ?? '');
    }
    $stmt->close();

    return ($hash !== '' && password_verify($password, $hash));
}

/**
 * Prepares or retrieves TOTP setup state (secret, QR URL, formatted key).
 *
 * @param string $accountLabel
 * @param string $issuer
 * @param bool $forceNew
 * @return array ['secret' => string, 'formatted' => string, 'otpauth_url' => string, 'qr_url' => string]
 */
function tfa_prepare_totp_setup($accountLabel, $issuer = 'Optibiz', $forceNew = false)
{
    if (session_status() === PHP_SESSION_NONE) {
        auth_session_start();
    }

    if ($forceNew || empty($_SESSION['tfa_setup_secret'])) {
        $_SESSION['tfa_setup_secret'] = tfa_generate_secret(16);
    }

    $secret = $_SESSION['tfa_setup_secret'];
    $otpauth = tfa_get_otpauth_url($accountLabel, $secret, $issuer);
    $qr = tfa_get_qr_url($otpauth, 200);
    $formatted = tfa_format_secret($secret);

    return [
        'secret'      => $secret,
        'formatted'   => $formatted,
        'otpauth_url' => $otpauth,
        'qr_url'      => $qr,
    ];
}

/* ============================================================
 *  Trusted Device Management (30-day "Trust this device" cookie)
 * ============================================================ */

/**
 * Returns the secret key used for HMAC signing of the trusted device cookie.
 *
 * @return string
 */
function tfa_get_app_secret()
{
    if (defined('API_JWT_SECRET') && strlen(API_JWT_SECRET) >= 16) {
        return API_JWT_SECRET;
    }
    $env = getenv('OPTIBIZ_JWT_SECRET') ?: getenv('APP_KEY');
    if ($env && strlen($env) >= 16) {
        return $env;
    }
    return 'optibiz_secure_device_secret_salt_32bytes_min';
}

/**
 * Checks whether the current browser device is trusted for a user.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @return bool
 */
function tfa_is_device_trusted($conn, $userType, $userId)
{
    if (empty($_COOKIE['optibiz_trusted_device'])) {
        return false;
    }

    $cookie = (string)$_COOKIE['optibiz_trusted_device'];
    $dotPos = strrpos($cookie, '.');
    if ($dotPos === false) {
        return false;
    }

    $payloadB64 = substr($cookie, 0, $dotPos);
    $signature = substr($cookie, $dotPos + 1);

    $expectedSig = hash_hmac('sha256', $payloadB64, tfa_get_app_secret());
    if (!hash_equals($expectedSig, $signature)) {
        return false;
    }

    $raw = base64_decode($payloadB64);
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['tok'])) {
        return false;
    }

    if (($data['type'] ?? '') !== $userType || (int)($data['uid'] ?? 0) !== (int)$userId) {
        return false;
    }

    // Check expiry in cookie payload
    if (!empty($data['exp']) && time() > (int)$data['exp']) {
        return false;
    }

    // Validate in DB
    tfa_ensure_schema($conn);
    $tokenHash = hash('sha256', (string)$data['tok']);
    $userId = (int)$userId;
    $userType = trim($userType);

    $stmt = $conn->prepare("SELECT id FROM user_trusted_devices WHERE user_type = ? AND user_id = ? AND device_token_hash = ? AND expires_at > NOW() LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("sis", $userType, $userId, $tokenHash);
    $stmt->execute();
    $res = $stmt->get_result();
    $isTrusted = ($res && $res->num_rows === 1);
    $stmt->close();

    return $isTrusted;
}

/**
 * Registers this device as trusted for the specified number of days (default 30).
 * Sets a cryptographically signed HttpOnly cookie and stores hash in DB.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @param int $days Default 30
 * @return bool
 */
function tfa_trust_device($conn, $userType, $userId, $days = 30)
{
    tfa_ensure_schema($conn);
    $userId = (int)$userId;
    $userType = trim($userType);
    $days = max(1, (int)$days);

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $lifetime = $days * 86400;
    $expTimestamp = time() + $lifetime;
    $expiresAt = date('Y-m-d H:i:s', $expTimestamp);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    // Save to DB using MySQL DATE_ADD(NOW(), INTERVAL ? DAY) for guaranteed timezone alignment
    $stmt = $conn->prepare("INSERT INTO user_trusted_devices (user_type, user_id, device_token_hash, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
    if ($stmt) {
        $stmt->bind_param("sisssi", $userType, $userId, $tokenHash, $ip, $ua, $days);
        $stmt->execute();
        $stmt->close();
    }

    // Clean up expired tokens for this user
    $escapedType = $conn->real_escape_string($userType);
    @$conn->query("DELETE FROM user_trusted_devices WHERE user_type = '{$escapedType}' AND user_id = {$userId} AND expires_at <= NOW()");

    // Sign and set cookie
    $payloadB64 = base64_encode(json_encode([
        'type' => $userType,
        'uid'  => $userId,
        'tok'  => $rawToken,
        'exp'  => $expTimestamp,
    ]));
    $sig = hash_hmac('sha256', $payloadB64, tfa_get_app_secret());
    $cookieVal = $payloadB64 . '.' . $sig;

    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    return setcookie('optibiz_trusted_device', $cookieVal, [
        'expires'  => $expTimestamp,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Revokes the trusted device cookie from browser.
 */
function tfa_revoke_trusted_device_cookie()
{
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    setcookie('optibiz_trusted_device', '', [
        'expires'  => time() - 86400,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Revokes all trusted devices for a user in the database.
 *
 * @param mysqli $conn
 * @param string $userType
 * @param int $userId
 * @return bool
 */
function tfa_revoke_user_devices($conn, $userType, $userId)
{
    tfa_ensure_schema($conn);
    $userId = (int)$userId;
    $userType = trim($userType);

    $stmt = $conn->prepare("DELETE FROM user_trusted_devices WHERE user_type = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $userType, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        tfa_revoke_trusted_device_cookie();
        return $ok;
    }
    return false;
}

/* ============================================================
 *  Mandatory 2FA Enrollment Pending State
 * ============================================================ */

/**
 * Starts a pending 2FA mandatory setup session.
 *
 * @param string $userType 'tenant' | 'superadmin' | 'team_member' | 'admin'
 * @param int $userId
 * @param array $sessionData
 * @param string $targetEmail
 * @param string $targetName
 * @param string $portal 'admin' | 'superadmin'
 * @param string $targetPhone
 */
function tfa_start_pending_setup_session($userType, $userId, array $sessionData, $targetEmail = '', $targetName = '', $portal = 'admin', $targetPhone = '')
{
    if (session_status() === PHP_SESSION_NONE) {
        auth_session_start();
    }

    // Never keep active authenticated session keys while 2FA setup is pending
    unset($_SESSION['tenant_id'], $_SESSION['admin_id'], $_SESSION['super_admin_id']);

    $_SESSION['2fa_setup_pending'] = [
        'user_type'    => $userType,
        'user_id'      => (int)$userId,
        'session_data' => $sessionData,
        'target_email' => $targetEmail,
        'target_name'  => $targetName,
        'target_phone' => $targetPhone,
        'portal'       => $portal,
        'started_at'   => time(),
    ];
}

/**
 * Retrieves the pending 2FA setup session if active and not expired.
 *
 * @param int $maxLifetimeSeconds Default 900 (15 min)
 * @return array|null
 */
function tfa_get_pending_setup_session($maxLifetimeSeconds = 900)
{
    if (session_status() === PHP_SESSION_NONE) {
        auth_session_start();
    }

    if (empty($_SESSION['2fa_setup_pending']) || !is_array($_SESSION['2fa_setup_pending'])) {
        return null;
    }

    $pending = $_SESSION['2fa_setup_pending'];
    if (empty($pending['started_at']) || (time() - (int)$pending['started_at']) > $maxLifetimeSeconds) {
        unset($_SESSION['2fa_setup_pending']);
        return null;
    }

    return $pending;
}

/**
 * Clears the pending 2FA setup session.
 */
function tfa_clear_pending_setup_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        auth_session_start();
    }
    unset($_SESSION['2fa_setup_pending']);
}

/**
 * Returns single initial character for user avatar pill (e.g. "T" for "Theophilus").
 *
 * @param string $name
 * @return string
 */
function tfa_get_user_initial($name)
{
    $clean = trim((string)$name);
    if ($clean === '') {
        return 'U';
    }
    return strtoupper(mb_substr($clean, 0, 1));
}


