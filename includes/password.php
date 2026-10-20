<?php
/**
 * ============================================================
 *  Optibiz — Password Hashing & Policy
 * ============================================================
 *  One password format for every account type (tenants, staff,
 *  super admins and app customers):
 *
 *      pbkdf2_sha256$<iterations>$<salt-hex>$<hash-hex>
 *
 *  PBKDF2-HMAC-SHA256 with 600,000 iterations (the OWASP 2023
 *  recommendation for SHA-256) and a 128-bit random salt. The
 *  iteration count is stored with every hash, so it can be raised
 *  later and old hashes are upgraded transparently on the next
 *  successful sign-in.
 *
 *  Bcrypt and Argon2 hashes written by older releases are still
 *  accepted through password_verify(); they are re-hashed with
 *  PBKDF2 as soon as the owner signs in.
 */

if (!defined('OPTIBIZ_PASSWORD_SCHEME')) {
    define('OPTIBIZ_PASSWORD_SCHEME', 'pbkdf2_sha256');
}
if (!defined('OPTIBIZ_PBKDF2_DEFAULT_ITERATIONS')) {
    define('OPTIBIZ_PBKDF2_DEFAULT_ITERATIONS', 600000);
}
if (!defined('OPTIBIZ_PBKDF2_MIN_ITERATIONS')) {
    define('OPTIBIZ_PBKDF2_MIN_ITERATIONS', 100000);
}

/**
 * Iterations used for new hashes and for deciding whether a stored
 * hash is below the current bar.
 *
 * OPTIBIZ_PBKDF2_ITERATIONS exists so the test suite can use a cheaper
 * count; it can never fall below OPTIBIZ_PBKDF2_MIN_ITERATIONS.
 */
function optibiz_pbkdf2_iterations()
{
    $configured = getenv('OPTIBIZ_PBKDF2_ITERATIONS');
    $iterations = $configured !== false ? (int) $configured : OPTIBIZ_PBKDF2_DEFAULT_ITERATIONS;

    if ($iterations < OPTIBIZ_PBKDF2_MIN_ITERATIONS) {
        $iterations = OPTIBIZ_PBKDF2_MIN_ITERATIONS;
    }
    if ($iterations > 5000000) {
        $iterations = 5000000;
    }

    return $iterations;
}

/**
 * Hash a password with PBKDF2-HMAC-SHA256.
 *
 * Returns null for values that cannot be safely hashed (empty string,
 * NUL bytes, or a value longer than 1024 bytes). Callers must validate
 * policy before calling this; this function only guarantees that the
 * result is a well-formed, high-iteration hash.
 *
 * @param string $password
 * @return string|null
 */
function optibiz_hash_password($password)
{
    if (!is_string($password) || $password === '' || strlen($password) > 1024) {
        return null;
    }
    if (strpos($password, "\0") !== false) {
        return null;
    }

    $iterations = optibiz_pbkdf2_iterations();
    $salt = random_bytes(16);
    $hash = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);

    return OPTIBIZ_PASSWORD_SCHEME . '$' . $iterations . '$' . bin2hex($salt) . '$' . bin2hex($hash);
}

/**
 * Verify a password against any hash this platform has ever written.
 *
 * Supports the current PBKDF2 format and legacy bcrypt/Argon2 hashes.
 * Comparison is constant-time. Never throws on malformed input.
 *
 * @param string $password
 * @param string $stored
 * @return bool
 */
function optibiz_verify_password($password, $stored)
{
    if (!is_string($password) || !is_string($stored) || $stored === '' || $password === '') {
        return false;
    }

    $prefix = OPTIBIZ_PASSWORD_SCHEME . '$';
    if (strncmp($stored, $prefix, strlen($prefix)) === 0) {
        $parts = explode('$', $stored);
        if (count($parts) !== 4) {
            return false;
        }

        $iterations = (int) $parts[1];
        if ($iterations < 1 || $iterations > 5000000) {
            return false;
        }

        $salt = hex2bin($parts[2]);
        $expected = hex2bin($parts[3]);
        if ($salt === false || $expected === false || strlen($salt) < 8 || strlen($expected) < 16) {
            return false;
        }

        $actual = hash_pbkdf2('sha256', $password, $salt, $iterations, strlen($expected), true);
        return hash_equals($expected, $actual);
    }

    // Legacy hashes (bcrypt from earlier releases, Argon2 if configured).
    return password_verify($password, $stored);
}

/**
 * Does a stored hash need upgrading to the current scheme/iterations?
 */
function optibiz_password_needs_rehash($stored)
{
    if (!is_string($stored) || $stored === '') {
        return true;
    }

    $prefix = OPTIBIZ_PASSWORD_SCHEME . '$';
    if (strncmp($stored, $prefix, strlen($prefix)) !== 0) {
        return true;
    }

    $parts = explode('$', $stored);
    if (count($parts) !== 4) {
        return true;
    }

    return (int) $parts[1] < optibiz_pbkdf2_iterations();
}

/**
 * Verify a password even when the account does not exist.
 *
 * Without this, a login endpoint answers noticeably faster for unknown
 * usernames than for known ones, which leaks which accounts exist. This
 * performs one PBKDF2 verification against a fixed, valid hash using the
 * iterations of the stored hash (or the current default).
 *
 * @param string $password
 * @return bool Always false; callers should treat it as a failed check.
 */
function optibiz_password_dummy_verify($password)
{
    // Public dummy hash: the plaintext behind it is unknown and irrelevant.
    // Format: pbkdf2_sha256$600000$<salt-hex>$<hash-hex>
    $dummy = 'pbkdf2_sha256$600000$e55d74481f3b674fe78e566e797500fb$'
        . 'c8ba8f0606dc5a48366864a355258fb14a766240f99049a037fff5f5188f4b4d';

    if (!is_string($password) || $password === '' || strlen($password) > 1024) {
        $password = 'not-a-real-password';
    }

    optibiz_verify_password($password, $dummy);
    return false;
}

/**
 * Human-readable policy errors for a proposed password.
 *
 * NIST SP 800-63B guidance: length over composition, screen known
 * passwords, and do not silently truncate. A 10-character minimum matches
 * most consumer apps while remaining usable on a phone keyboard.
 *
 * @param string $password
 * @param string $email    Optional, to reject the account's own email
 * @param string $name     Optional, to reject the account's own name
 * @return string[] Empty when the password is acceptable.
 */
function optibiz_password_policy_errors($password, $email = '', $name = '')
{
    $errors = [];

    if (!is_string($password) || $password === '') {
        return ['Choose a password.'];
    }

    $length = strlen($password);
    if ($length < 10) {
        $errors[] = 'Use at least 10 characters.';
    }
    if ($length > 128) {
        $errors[] = 'Use no more than 128 characters.';
    }
    if (strpos($password, "\0") !== false) {
        $errors[] = 'The password contains an unsupported character.';
    }

    $lower = strtolower($password);
    $common = [
        'password', 'password1', 'password123', '12345678', '123456789', '1234567890',
        'qwertyuiop', 'qwerty123', 'letmein', 'welcome1', 'admin123', 'administrator',
        'iloveyou', 'abc12345', 'passw0rd', 'p@ssw0rd', 'optibiz', 'optibiz123',
        'ghana2024', 'ghana2025', 'accra2024', 'accra2025', 'superadmin', 'changeme',
    ];
    if (in_array($lower, $common, true)) {
        $errors[] = 'That password is too common. Choose something unique.';
    }

    if (preg_match('/^(.)\1{9,}$/', $password)) {
        $errors[] = 'Do not repeat the same character.';
    }
    if (preg_match('/^(?:0123456789|1234567890|abcdefghij|qwertyuiop)/i', $password)) {
        $errors[] = 'Do not use a keyboard sequence.';
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $local = strtolower(strtok($email, '@'));
        if (strlen($local) >= 3 && strpos($lower, $local) !== false) {
            $errors[] = 'Do not include your email address in the password.';
        }
    }
    if ($name !== '') {
        $slugName = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));
        $slugPassword = strtolower(preg_replace('/[^a-z0-9]+/i', '', $password));
        if (strlen($slugName) >= 4 && strpos($slugPassword, $slugName) !== false) {
            $errors[] = 'Do not include your name in the password.';
        }
    }

    return $errors;
}

/**
 * Upgrade a stored hash after a successful sign-in.
 *
 * Only the account tables this platform owns are accepted; the table name
 * is never taken from user input. Failures are logged but never block the
 * sign-in that already succeeded.
 *
 * @param mysqli $conn
 * @param string $table  tenants | admins | super_admins | team_members | app_users
 * @param int    $id
 * @param string $plain  The password that just verified
 * @param string $stored The hash that was checked
 * @return bool True when a new hash was written
 */
function optibiz_password_upgrade_hash($conn, $table, $id, $plain, $stored)
{
    if (!is_object($conn) || (int) $id <= 0 || !optibiz_password_needs_rehash($stored)) {
        return false;
    }

    $columns = [
        'tenants'      => 'password',
        'admins'       => 'password',
        'super_admins' => 'password',
        'team_members' => 'password',
        'app_users'    => 'password_hash',
    ];
    if (!isset($columns[$table])) {
        return false;
    }

    $newHash = optibiz_hash_password($plain);
    if ($newHash === null) {
        return false;
    }

    $sql = 'UPDATE `' . $table . '` SET `' . $columns[$table] . '` = ? WHERE `id` = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $id = (int) $id;
    $stmt->bind_param('si', $newHash, $id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        error_log('Optibiz password rehash failed for ' . $table . '#' . $id);
    }
    return (bool) $ok;
}

/**
 * Verify and transparently upgrade in one call.
 *
 * @return bool Whether the password matched.
 */
function optibiz_login_check($conn, $table, $id, $password, $stored)
{
    if (!optibiz_verify_password($password, $stored)) {
        return false;
    }
    if (is_object($conn)) {
        optibiz_password_upgrade_hash($conn, $table, (int) $id, $password, $stored);
    }
    return true;
}
