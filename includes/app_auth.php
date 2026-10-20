<?php
/**
 * ============================================================
 *  Optibiz — App Customer Accounts
 * ============================================================
 *  The mobile app used to keep its accounts in a local Room
 *  database. That made a review written on one phone invisible
 *  to the business on another device. These tables are now the
 *  source of truth: every account, session, device and deletion
 *  request lives on the server, and the app caches copies only.
 *
 *  Accounts can sign in with:
 *    - email + password (PBKDF2, see includes/password.php)
 *    - phone + password
 *    - a one-time code sent by email or SMS (recovery / login)
 *    - Google Sign-In (verified server-side in includes/google_auth.php)
 *
 *  Tokens are stateless JWTs with a per-user `tv` (token version)
 *  claim. Bumping app_users.token_version invalidates every access
 *  and refresh token for that account immediately — used on
 *  password reset and account deletion.
 */

require_once __DIR__ . '/password.php';
require_once __DIR__ . '/functions.php';

if (!function_exists('optibiz_app_ensure_schema')) {
    /**
     * Create the app account tables on first use. Idempotent and safe on
     * older installations; database.sql declares the same schema.
     */
    function optibiz_app_ensure_schema($conn)
    {
        static $done = false;
        if ($done || !is_object($conn)) {
            return;
        }
        $done = true;

        @$conn->query("CREATE TABLE IF NOT EXISTS app_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(32) NOT NULL,
            full_name VARCHAR(120) NOT NULL DEFAULT '',
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            password_hash VARCHAR(255) NULL,
            google_sub VARCHAR(255) NULL,
            avatar_url VARCHAR(500) NULL,
            email_verified_at DATETIME NULL,
            phone_verified_at DATETIME NULL,
            status ENUM('active','pending','suspended','deleted') NOT NULL DEFAULT 'active',
            marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
            token_version INT UNSIGNED NOT NULL DEFAULT 0,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_app_public_id (public_id),
            UNIQUE KEY uniq_app_email (email),
            UNIQUE KEY uniq_app_phone (phone),
            UNIQUE KEY uniq_app_google (google_sub),
            KEY idx_app_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS app_otp_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            channel ENUM('email','sms') NOT NULL,
            destination_hash CHAR(64) NOT NULL,
            destination_masked VARCHAR(190) NOT NULL DEFAULT '',
            purpose VARCHAR(32) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
            consumed_at DATETIME NULL,
            ip_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_otp_lookup (destination_hash, purpose, consumed_at),
            KEY idx_otp_user (user_id),
            KEY idx_otp_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS app_devices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            device_hash CHAR(64) NOT NULL,
            platform ENUM('android','ios','web','other') NOT NULL DEFAULT 'other',
            fcm_token VARCHAR(512) NULL,
            app_version VARCHAR(32) NULL,
            push_enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_app_device (user_id, device_hash),
            KEY idx_app_device_token (fcm_token(191)),
            CONSTRAINT fk_app_device_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS account_deletion_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            destination_hash CHAR(64) NOT NULL,
            channel ENUM('app','email','sms','support') NOT NULL DEFAULT 'app',
            status ENUM('requested','completed','denied') NOT NULL DEFAULT 'requested',
            reason VARCHAR(255) NULL,
            ip_hash CHAR(64) NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            KEY idx_del_status (status),
            KEY idx_del_destination (destination_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/** Lowercase and trim an email address. */
function optibiz_app_email($email)
{
    return strtolower(trim((string) $email));
}

/** Normalise a phone number to international digits (Ghana default). */
function optibiz_app_phone($phone)
{
    $defaultCountry = getenv('OPTIBIZ_DEFAULT_COUNTRY_CODE') ?: '233';
    $digits = preg_replace('/\D+/', '', (string) $phone);

    if (strncmp($digits, '00', 2) === 0) {
        $digits = substr($digits, 2);
    }
    // Local format: 024 555 0118 -> 233245550118. Only when no country
    // code is already present, so +233… is left untouched.
    if ($digits !== '' && $digits[0] === '0' && strlen($digits) >= 9 && strlen($digits) <= 11) {
        $digits = $defaultCountry . substr($digits, 1);
    }

    return $digits;
}

/** A stable, non-guessable public identifier for API responses. */
function optibiz_app_public_id()
{
    return 'USR-' . strtoupper(bin2hex(random_bytes(6)));
}

/** Mask an email for "we sent a code to …" messages. */
function optibiz_app_mask_email($email)
{
    $email = (string) $email;
    $at = strpos($email, '@');
    if ($at === false || $at < 1) {
        return 'your email';
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at);
    $keep = min(2, strlen($local));
    return substr($local, 0, $keep) . str_repeat('•', max(2, strlen($local) - $keep)) . $domain;
}

/** Mask a phone for "we sent a code to …" messages. */
function optibiz_app_mask_phone($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($digits) < 6) {
        return 'your phone';
    }
    return '+' . substr($digits, 0, 3) . ' ••• ••' . substr($digits, -2);
}

/** The public shape of an account; never includes hashes or codes. */
function optibiz_app_public_user(array $user)
{
    return [
        'id'                => (int) $user['id'],
        'public_id'         => (string) $user['public_id'],
        'name'              => (string) ($user['full_name'] ?? ''),
        'email'             => $user['email'] ?? null,
        'phone'             => $user['phone'] ?? null,
        'avatar_url'        => $user['avatar_url'] ?? null,
        'email_verified'    => !empty($user['email_verified_at']),
        'phone_verified'    => !empty($user['phone_verified_at']),
        'has_password'      => !empty($user['password_hash']),
        'google_linked'     => !empty($user['google_sub']),
        'marketing_opt_in'  => !empty($user['marketing_opt_in']),
        'created_at'        => $user['created_at'] ?? null,
        'last_login_at'     => $user['last_login_at'] ?? null,
    ];
}

/**
 * Find an account by email or phone. Deleted accounts are never returned.
 *
 * @return array|null
 */
function optibiz_app_find_user($conn, $email = '', $phone = '')
{
    optibiz_app_ensure_schema($conn);
    $email = optibiz_app_email($email);
    $phone = optibiz_app_phone($phone);

    if ($email !== '') {
        $stmt = $conn->prepare("SELECT * FROM app_users WHERE email = ? AND status <> 'deleted' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }
    if ($phone !== '') {
        $stmt = $conn->prepare("SELECT * FROM app_users WHERE phone = ? AND status <> 'deleted' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $phone);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }
    return null;
}

function optibiz_app_user_by_id($conn, $user_id)
{
    optibiz_app_ensure_schema($conn);
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM app_users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function optibiz_app_user_by_google($conn, $google_sub)
{
    optibiz_app_ensure_schema($conn);
    $google_sub = trim((string) $google_sub);
    if ($google_sub === '') {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM app_users WHERE google_sub = ? AND status <> 'deleted' LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $google_sub);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Create an account. Caller is responsible for checking that the email or
 * phone is not already in use.
 *
 * @return array|string The new row, or an error message.
 */
function optibiz_app_create_user($conn, array $data)
{
    optibiz_app_ensure_schema($conn);

    $name = trim((string) ($data['full_name'] ?? ''));
    if ($name === '') {
        $name = 'Customer';
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 120);
    } else {
        $name = substr($name, 0, 120);
    }

    $email = isset($data['email']) ? optibiz_app_email($data['email']) : '';
    $phone = isset($data['phone']) ? optibiz_app_phone($data['phone']) : '';
    if ($email === '' && $phone === '') {
        return 'An email address or phone number is required.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    if ($phone !== '' && (strlen($phone) < 7 || strlen($phone) > 15)) {
        return 'Please enter a valid phone number.';
    }

    $existing = optibiz_app_find_user($conn, $email, $phone);
    if ($existing) {
        return 'An account with those details already exists.';
    }

    $passwordHash = null;
    if (!empty($data['password'])) {
        $errors = optibiz_password_policy_errors((string) $data['password'], $email, $name);
        if ($errors) {
            return implode(' ', $errors);
        }
        $passwordHash = optibiz_hash_password((string) $data['password']);
        if ($passwordHash === null) {
            return 'That password cannot be used.';
        }
    }

    $publicId = optibiz_app_public_id();
    $googleSub = isset($data['google_sub']) && trim((string) $data['google_sub']) !== ''
        ? trim((string) $data['google_sub']) : null;
    $avatar = isset($data['avatar_url']) ? trim((string) $data['avatar_url']) : '';
    $marketing = !empty($data['marketing_opt_in']) ? 1 : 0;
    $status = 'active';
    if (!empty($data['email_verified']) && $email !== '') {
        $emailVerifiedAt = date('Y-m-d H:i:s');
    } else {
        $emailVerifiedAt = null;
    }
    $phoneVerifiedAt = !empty($data['phone_verified']) && $phone !== '' ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare("INSERT INTO app_users
        (public_id, full_name, email, phone, password_hash, google_sub, avatar_url,
         email_verified_at, phone_verified_at, status, marketing_opt_in)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return 'Could not create the account. Please try again.';
    }

    $emailOrNull = $email !== '' ? $email : null;
    $phoneOrNull = $phone !== '' ? $phone : null;
    $avatarOrNull = $avatar !== '' ? $avatar : null;
    $stmt->bind_param(
        'ssssssssssi',
        $publicId, $name, $emailOrNull, $phoneOrNull, $passwordHash, $googleSub, $avatarOrNull,
        $emailVerifiedAt, $phoneVerifiedAt, $status, $marketing
    );
    $ok = $stmt->execute();
    $newId = $stmt->insert_id;
    $err = $stmt->error;
    $stmt->close();

    if (!$ok) {
        error_log('app account create failed: ' . $err);
        return 'Could not create the account. Please try again.';
    }

    return optibiz_app_user_by_id($conn, $newId);
}

/**
 * Record a successful sign-in and return fresh tokens.
 *
 * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int}
 */
function optibiz_app_issue_tokens(array $user)
{
    $claims = [
        'sub'       => (int) $user['id'],
        'user_type' => 'app_customer',
        'role'      => 'customer',
        'tv'        => (int) ($user['token_version'] ?? 0),
        'public_id' => (string) $user['public_id'],
    ];

    $accessExpiry = defined('API_JWT_APP_ACCESS_EXPIRY') ? (int) API_JWT_APP_ACCESS_EXPIRY : 3600;
    $refreshExpiry = defined('API_JWT_APP_REFRESH_EXPIRY') ? (int) API_JWT_APP_REFRESH_EXPIRY : 2592000;

    return [
        'access_token'  => JWT::generate($claims, $accessExpiry, 'app_access'),
        'refresh_token' => JWT::generate([
            'sub'       => (int) $user['id'],
            'user_type' => 'app_customer',
            'role'      => 'customer',
            'tv'        => (int) ($user['token_version'] ?? 0),
        ], $refreshExpiry, 'app_refresh'),
        'token_type'    => 'Bearer',
        'expires_in'    => $accessExpiry,
        'refresh_expires_in' => $refreshExpiry,
    ];
}

function optibiz_app_touch_login($conn, $user_id)
{
    $stmt = $conn->prepare("UPDATE app_users SET last_login_at = NOW() WHERE id = ? LIMIT 1");
    if ($stmt) {
        $user_id = (int) $user_id;
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

/** Invalidate every access/refresh token issued to this account. */
function optibiz_app_bump_token_version($conn, $user_id)
{
    $stmt = $conn->prepare("UPDATE app_users SET token_version = token_version + 1 WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $user_id = (int) $user_id;
    $stmt->bind_param('i', $user_id);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool) $ok;
}

/**
 * Anonymise and close an account.
 *
 * Reviews are kept (they are part of the public record and of the
 * business's rating history) but every identifier attached to them is
 * removed. Nothing that can be linked back to the person survives in
 * app_users, OTPs, devices, deletion requests or review metadata.
 *
 * @return array ['success'=>bool, 'message'=>string]
 */
function optibiz_app_delete_account($conn, $user_id, $reason = '', $channel = 'app')
{
    optibiz_app_ensure_schema($conn);
    $user = optibiz_app_user_by_id($conn, (int) $user_id);
    if (!$user) {
        return ['success' => false, 'message' => 'Account not found.'];
    }
    if (($user['status'] ?? '') === 'deleted') {
        return ['success' => true, 'message' => 'This account is already deleted.'];
    }

    $email = optibiz_app_email($user['email'] ?? '');
    $phone = optibiz_app_phone($user['phone'] ?? '');
    $destination = $email !== '' ? $email : $phone;
    $destinationHash = hash('sha256', 'app-deletion|' . strtolower($destination));
    $reason = trim((string) $reason);
    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 255);
    } else {
        $reason = substr($reason, 0, 255);
    }
    $ipHash = hash('sha256', 'account-deletion|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));

    $canTransact = method_exists($conn, 'begin_transaction') && method_exists($conn, 'commit');
    if ($canTransact) {
        @$conn->begin_transaction();
    }
    try {
        // Reviews stay, but lose every identifier.
        if (function_exists('review_trust_ensure_schema')) {
            review_trust_ensure_schema($conn);
        }
        if ($email !== '') {
            $upd = $conn->prepare("UPDATE ratings SET customer_name = 'Deleted customer', customer_email = '', customer_user_id = NULL, device_hash = NULL, photos = NULL WHERE LOWER(TRIM(customer_email)) = ?");
            if ($upd) {
                $upd->bind_param('s', $email);
                $upd->execute();
                $upd->close();
            }
            $sc = $conn->prepare("UPDATE site_customers SET customer_name = 'Deleted customer', customer_email = '', customer_phone = NULL, is_verified = 0, verification_type = NULL WHERE LOWER(TRIM(customer_email)) = ?");
            if ($sc) {
                $sc->bind_param('s', $email);
                $sc->execute();
                $sc->close();
            }
        }
        if (class_exists('JWT') && !empty($_SESSION['app_refresh_token'])) {
            JWT::revoke($conn, (string) $_SESSION['app_refresh_token'], 'app_refresh', 0, 0);
        }

        $stmt = $conn->prepare("DELETE FROM app_otp_codes WHERE user_id = ?");
        if ($stmt) {
            $uid = (int) $user_id;
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM app_devices WHERE user_id = ?");
        if ($stmt) {
            $uid = (int) $user_id;
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("UPDATE app_users
            SET status = 'deleted', full_name = 'Deleted customer', email = NULL, phone = NULL,
                password_hash = NULL, google_sub = NULL, avatar_url = NULL,
                email_verified_at = NULL, phone_verified_at = NULL, marketing_opt_in = 0,
                token_version = token_version + 1, deleted_at = NOW()
            WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Could not anonymise the account.');
        }
        $uid = (int) $user_id;
        $stmt->bind_param('i', $uid);
        if (!$stmt->execute()) {
            throw new Exception('Could not anonymise the account.');
        }
        $stmt->close();

        $ins = $conn->prepare("INSERT INTO account_deletion_requests
            (user_id, destination_hash, channel, status, reason, ip_hash, completed_at)
            VALUES (?, ?, ?, 'completed', ?, ?, NOW())");
        if ($ins) {
            $ch = in_array($channel, ['app', 'email', 'sms', 'support'], true) ? $channel : 'app';
            $ins->bind_param('issss', $uid, $destinationHash, $ch, $reason, $ipHash);
            $ins->execute();
            $ins->close();
        }

        if ($canTransact) {
            @$conn->commit();
        }
    } catch (Throwable $e) {
        if ($canTransact) {
            @$conn->rollback();
        }
        error_log('app account deletion failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'The account could not be deleted. Please contact support.'];
    }

    return ['success' => true, 'message' => 'Your account and personal data have been deleted.'];
}
