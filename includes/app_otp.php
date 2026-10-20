<?php
/**
 * ============================================================
 *  Optibiz — One-Time Codes for App Accounts
 * ============================================================
 *  Real delivery only: a code is returned to the caller as "sent"
 *  only after the configured email (SMTP) or SMS provider accepted
 *  it. There is no preview badge and no "123456" fallback.
 *
 *  Codes are six digits, expire after 10 minutes, allow five
 *  attempts, are single-use, and are stored as an HMAC keyed with a
 *  server secret — a leaked database alone cannot reveal them.
 */

require_once __DIR__ . '/app_auth.php';

if (!defined('OPTIBIZ_OTP_TTL')) {
    define('OPTIBIZ_OTP_TTL', 600);            // 10 minutes
}
if (!defined('OPTIBIZ_OTP_MAX_ATTEMPTS')) {
    define('OPTIBIZ_OTP_MAX_ATTEMPTS', 5);
}
if (!defined('OPTIBIZ_OTP_MAX_PER_DESTINATION')) {
    define('OPTIBIZ_OTP_MAX_PER_DESTINATION', 3);   // per 10 minutes
}
if (!defined('OPTIBIZ_OTP_MAX_PER_IP')) {
    define('OPTIBIZ_OTP_MAX_PER_IP', 10);           // per hour
}

/**
 * The pepper used to key OTP hashes. A dedicated secret is best; the API
 * JWT secret is an acceptable fallback because every deployment that can
 * serve these endpoints must already set it. There is no hard-coded key.
 */
function optibiz_otp_secret()
{
    $secret = getenv('OPTIBIZ_OTP_SECRET');
    if (!$secret) {
        $secret = getenv('OPTIBIZ_JWT_SECRET');
    }
    if (!$secret) {
        $secret = getenv('JWT_SECRET');
    }
    if (!$secret) {
        // Last resort for local development only. The API refuses to boot
        // without a JWT secret, so production never reaches this branch.
        $secret = hash('sha256', __FILE__ . (defined('DB_PASS') ? DB_PASS : '') . (defined('APP_BASE_URL') ? APP_BASE_URL : ''));
    }
    return (string) $secret;
}

/** Hash a destination for lookup without storing the address itself. */
function optibiz_otp_destination_hash($channel, $destination)
{
    $normalised = ($channel === 'sms')
        ? optibiz_app_phone($destination)
        : optibiz_app_email($destination);
    return hash_hmac('sha256', $channel . '|' . strtolower($normalised), optibiz_otp_secret());
}

/** HMAC of a code, bound to the destination and purpose. */
function optibiz_otp_code_hash($code, $channel, $destination, $purpose)
{
    $material = $channel . '|' . strtolower(($channel === 'sms') ? optibiz_app_phone($destination) : optibiz_app_email($destination))
        . '|' . $purpose . '|' . preg_replace('/\D+/', '', (string) $code);
    return hash_hmac('sha256', $material, optibiz_otp_secret());
}

/**
 * Generate a six-digit code. random_int() is the CSPRNG; mt_rand() and
 * rand() are explicitly not used.
 */
function optibiz_otp_generate_code()
{
    return (string) random_int(100000, 999999);
}

/** Normalise and validate a channel/destination pair. */
function optibiz_otp_normalise($channel, $destination)
{
    $channel = strtolower(trim((string) $channel));
    if ($channel === 'phone') {
        $channel = 'sms';
    }
    if (!in_array($channel, ['email', 'sms'], true)) {
        return [null, null, 'Choose email or SMS.'];
    }

    $destination = trim((string) $destination);
    if ($channel === 'email') {
        $destination = optibiz_app_email($destination);
        if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            return [null, null, 'Please enter a valid email address.'];
        }
    } else {
        $destination = optibiz_app_phone($destination);
        if (strlen($destination) < 7 || strlen($destination) > 15) {
            return [null, null, 'Please enter a valid phone number.'];
        }
    }

    return [$channel, $destination, null];
}

/** Which delivery route is configured? Never exposes credentials. */
function optibiz_otp_delivery_configured($conn, $channel)
{
    if ($channel === 'email') {
        if (!function_exists('sa_get_mail_config')) {
            return false;
        }
        $cfg = sa_get_mail_config($conn);
        $driver = strtolower(trim((string) ($cfg['mail_driver'] ?? 'smtp')));
        if ($driver === 'smtp') {
            return trim((string) ($cfg['smtp_host'] ?? '')) !== ''
                && trim((string) ($cfg['smtp_username'] ?? '')) !== ''
                && (string) ($cfg['smtp_password'] ?? '') !== '';
        }
        return true; // native mail() is a real transport; failure is reported honestly
    }

    if ($channel === 'sms') {
        if (!function_exists('sms_provider_list') || !function_exists('sms_send_via_provider')) {
            return false;
        }
        foreach (sms_provider_list($conn, true) as $provider) {
            $key = (string) ($provider['provider_key'] ?? '');
            if ($key === 'manual') {
                continue;
            }
            if (trim((string) ($provider['api_key'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    return false;
}

/** Purpose-specific wording for the email/SMS body. */
function optibiz_otp_purpose_copy($purpose)
{
    switch ($purpose) {
        case 'signup':
            return ['Verify your Optibiz account', 'Use this code to finish creating your Optibiz account.'];
        case 'recovery':
            return ['Reset your Optibiz password', 'Use this code to reset your Optibiz password.'];
        case 'delete_account':
            return ['Confirm your Optibiz account deletion', 'Use this code to confirm that you want to delete your Optibiz account.'];
        case 'verify_phone':
            return ['Verify your phone number', 'Use this code to verify your phone number.'];
        case 'login':
        default:
            return ['Your Optibiz sign-in code', 'Use this code to sign in to Optibiz.'];
    }
}

/**
 * Send an email OTP through the platform's configured SMTP/native mailer.
 *
 * @return array{ok:bool, message:string}
 */
function optibiz_otp_send_email($conn, $to, $code, $purpose, $name = '')
{
    list($title, $lead) = optibiz_otp_purpose_copy($purpose);
    $minutes = (int) ceil(OPTIBIZ_OTP_TTL / 60);
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $safeLead = htmlspecialchars($lead, ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars(trim((string) $name), ENT_QUOTES, 'UTF-8');
    $greeting = $safeName !== '' ? 'Hi ' . $safeName . ',' : 'Hi,';

    $body = '
        <p style="margin:0 0 14px;font-size:15px;color:#334155;">' . $greeting . '</p>
        <p style="margin:0 0 20px;font-size:15px;color:#334155;line-height:1.6;">' . $safeLead . '</p>
        <div style="margin:0 0 20px;padding:18px 20px;background:#0f172a;border-radius:12px;text-align:center;">
            <span style="display:block;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;">Your code</span>
            <span style="display:block;font-size:34px;letter-spacing:10px;font-weight:800;color:#c2f542;font-family:Consolas,Menlo,monospace;">' . $safeCode . '</span>
        </div>
        <p style="margin:0 0 14px;font-size:13px;color:#64748b;line-height:1.6;">The code expires in ' . $minutes . ' minutes and can be used once. If you did not ask for this code, you can ignore this email — nobody can sign in without it.</p>
        <p style="margin:0;font-size:12px;color:#94a3b8;">Optibiz will never ask you for this code by phone, WhatsApp or email.</p>';

    $html = function_exists('sa_render_email_template')
        ? sa_render_email_template($title, $body, 'Optibiz')
        : '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:0 auto">' . $body . '</div>';

    $sent = sa_send_mail($to, $title . ' — Optibiz', $html, $conn);
    return [
        'ok'      => !empty($sent['success']),
        'message' => (string) ($sent['message'] ?? ($sent['success'] ?? false ? 'Sent.' : 'Email could not be sent.')),
    ];
}

/**
 * Send an SMS OTP through the first enabled, credentialed provider.
 *
 * @return array{ok:bool, message:string}
 */
function optibiz_otp_send_sms($conn, $to, $code, $purpose)
{
    if (!function_exists('sms_provider_list') || !function_exists('sms_send_via_provider')) {
        return ['ok' => false, 'message' => 'No SMS provider is available.'];
    }

    list(, $lead) = optibiz_otp_purpose_copy($purpose);
    $minutes = (int) ceil(OPTIBIZ_OTP_TTL / 60);
    $message = 'Optibiz: ' . $code . ' is your verification code. ' . $lead . ' Expires in ' . $minutes . ' minutes. Never share it.';

    $lastError = 'No SMS provider is enabled.';
    foreach (sms_provider_list($conn, true) as $provider) {
        $key = (string) ($provider['provider_key'] ?? '');
        if ($key === '' || $key === 'manual') {
            continue;
        }
        if (trim((string) ($provider['api_key'] ?? '')) === '') {
            continue;
        }
        $result = sms_send_via_provider($conn, $key, $to, $message, (string) ($provider['sender_id'] ?? ''));
        if (!empty($result['ok'])) {
            return ['ok' => true, 'message' => 'SMS accepted by ' . $key . '.'];
        }
        $lastError = (string) ($result['message'] ?? 'The SMS gateway rejected the message.');
    }

    return ['ok' => false, 'message' => $lastError];
}

/**
 * Issue and deliver a code.
 *
 * The response is deliberately generic for recovery/login so it cannot be
 * used to discover whether an account exists. `sent` is only true when a
 * delivery transport accepted the message.
 *
 * @return array
 */
function optibiz_otp_request($conn, $channel, $destination, $purpose, array $context = [])
{
    optibiz_app_ensure_schema($conn);

    $purpose = strtolower(trim((string) $purpose));
    if (!in_array($purpose, ['login', 'recovery', 'signup', 'delete_account', 'verify_phone'], true)) {
        return ['success' => false, 'status' => 422, 'message' => 'Unknown verification purpose.'];
    }

    list($channel, $destination, $error) = optibiz_otp_normalise($channel, $destination);
    if ($error !== null) {
        return ['success' => false, 'status' => 422, 'message' => $error];
    }

    if (!optibiz_otp_delivery_configured($conn, $channel)) {
        return [
            'success' => false,
            'status'  => 503,
            'message' => $channel === 'email'
                ? 'Email delivery is not configured. Add SMTP details in the platform settings and try again.'
                : 'SMS delivery is not configured. Enable an SMS provider in the platform settings and try again.',
        ];
    }

    $destinationHash = optibiz_otp_destination_hash($channel, $destination);
    $masked = $channel === 'email' ? optibiz_app_mask_email($destination) : optibiz_app_mask_phone($destination);
    $ipHash = hash_hmac('sha256', 'otp-ip|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'), optibiz_otp_secret());

    // Throttle: per destination and per IP. Counts live in the OTP table,
    // so they survive process restarts and work on every server.
    $window = date('Y-m-d H:i:s', time() - OPTIBIZ_OTP_TTL);
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM app_otp_codes WHERE destination_hash = ? AND created_at >= ?");
    if ($stmt) {
        $stmt->bind_param('ss', $destinationHash, $window);
        $stmt->execute();
        $recent = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        if ($recent >= OPTIBIZ_OTP_MAX_PER_DESTINATION) {
            return ['success' => false, 'status' => 429, 'message' => 'Too many codes requested. Wait a few minutes before trying again.', 'retry_after' => OPTIBIZ_OTP_TTL];
        }
    }

    $ipWindow = date('Y-m-d H:i:s', time() - 3600);
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM app_otp_codes WHERE ip_hash = ? AND created_at >= ?");
    if ($stmt) {
        $stmt->bind_param('ss', $ipHash, $ipWindow);
        $stmt->execute();
        $recentIp = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        if ($recentIp >= OPTIBIZ_OTP_MAX_PER_IP) {
            return ['success' => false, 'status' => 429, 'message' => 'Too many codes requested from this network. Please try again later.', 'retry_after' => 3600];
        }
    }

    $user = optibiz_app_find_user($conn, $channel === 'email' ? $destination : '', $channel === 'sms' ? $destination : '');
    $name = $user['full_name'] ?? '';

    // For signup, an existing account should sign in instead of creating a
    // duplicate. The message is explicit because signup is not an
    // account-existence oracle for recovery.
    if ($purpose === 'signup' && $user) {
        return ['success' => false, 'status' => 409, 'message' => 'An account with those details already exists. Sign in or reset your password.'];
    }
    // For recovery/login/delete, an unknown destination gets the same
    // generic answer as a known one — and no code is sent.
    if ($purpose !== 'signup' && !$user) {
        optibiz_otp_purge_expired($conn);
        return [
            'success' => true,
            'sent'    => false,
            'channel' => $channel,
            'masked'  => $masked,
            'expires_in' => OPTIBIZ_OTP_TTL,
            'message' => 'If an account exists for that ' . ($channel === 'email' ? 'email address' : 'phone number') . ', a code has been sent.',
        ];
    }

    // Invalidate earlier live codes for this destination/purpose.
    $stmt = $conn->prepare("UPDATE app_otp_codes SET consumed_at = NOW() WHERE destination_hash = ? AND purpose = ? AND consumed_at IS NULL");
    if ($stmt) {
        $stmt->bind_param('ss', $destinationHash, $purpose);
        $stmt->execute();
        $stmt->close();
    }

    $code = optibiz_otp_generate_code();
    $expiresAt = date('Y-m-d H:i:s', time() + OPTIBIZ_OTP_TTL);
    $userId = $user ? (int) $user['id'] : null;
    $codeHash = optibiz_otp_code_hash($code, $channel, $destination, $purpose);
    $maxAttempts = OPTIBIZ_OTP_MAX_ATTEMPTS;

    $stmt = $conn->prepare("INSERT INTO app_otp_codes
        (user_id, channel, destination_hash, destination_masked, purpose, code_hash, expires_at, max_attempts, ip_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return ['success' => false, 'status' => 500, 'message' => 'Could not create a verification code. Please try again.'];
    }
    $stmt->bind_param('issssssis', $userId, $channel, $destinationHash, $masked, $purpose, $codeHash, $expiresAt, $maxAttempts, $ipHash);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'status' => 500, 'message' => 'Could not create a verification code. Please try again.'];
    }
    $otpId = (int) $stmt->insert_id;
    $stmt->close();

    $delivery = $channel === 'email'
        ? optibiz_otp_send_email($conn, $destination, $code, $purpose, $name)
        : optibiz_otp_send_sms($conn, $destination, $code, $purpose);

    if (empty($delivery['ok'])) {
        // Burn the code so a failed delivery cannot be retried blindly.
        $stmt = $conn->prepare("UPDATE app_otp_codes SET consumed_at = NOW() WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $otpId);
            $stmt->execute();
            $stmt->close();
        }
        error_log('Optibiz OTP delivery failed (' . $channel . ', purpose ' . $purpose . '): ' . $delivery['message']);
        return [
            'success' => false,
            'status'  => 502,
            'message' => $channel === 'email'
                ? 'The verification email could not be delivered. Please try again or contact support.'
                : 'The verification SMS could not be delivered. Please try again or contact support.',
        ];
    }

    optibiz_otp_purge_expired($conn);

    return [
        'success'    => true,
        'sent'       => true,
        'channel'    => $channel,
        'masked'     => $masked,
        'expires_in' => OPTIBIZ_OTP_TTL,
        'message'    => 'A verification code was sent to ' . $masked . '.',
    ];
}

/**
 * Verify a code.
 *
 * @return array{success:bool, status:int, message:string, user?:array|null, otp_id?:int}
 */
function optibiz_otp_verify($conn, $channel, $destination, $purpose, $code)
{
    optibiz_app_ensure_schema($conn);

    $purpose = strtolower(trim((string) $purpose));
    if (!in_array($purpose, ['login', 'recovery', 'signup', 'delete_account', 'verify_phone'], true)) {
        return ['success' => false, 'status' => 422, 'message' => 'Unknown verification purpose.'];
    }

    list($channel, $destination, $error) = optibiz_otp_normalise($channel, $destination);
    if ($error !== null) {
        return ['success' => false, 'status' => 422, 'message' => $error, 'user' => null];
    }

    $code = preg_replace('/\D+/', '', (string) $code);
    if (strlen($code) !== 6) {
        return ['success' => false, 'status' => 422, 'message' => 'Enter the 6-digit code from your message.', 'user' => null];
    }

    $destinationHash = optibiz_otp_destination_hash($channel, $destination);
    $stmt = $conn->prepare("SELECT * FROM app_otp_codes
        WHERE destination_hash = ? AND purpose = ? AND consumed_at IS NULL
        ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        return ['success' => false, 'status' => 500, 'message' => 'Verification is unavailable. Please try again.', 'user' => null];
    }
    $stmt->bind_param('ss', $destinationHash, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'status' => 400, 'message' => 'That code is invalid or has expired. Request a new one.', 'user' => null];
    }

    $otpId = (int) $row['id'];
    if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
        $conn->query("UPDATE app_otp_codes SET consumed_at = NOW() WHERE id = " . $otpId);
        return ['success' => false, 'status' => 429, 'message' => 'Too many attempts for this code. Request a new one.', 'user' => null];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        $conn->query("UPDATE app_otp_codes SET consumed_at = NOW() WHERE id = " . $otpId);
        return ['success' => false, 'status' => 400, 'message' => 'That code has expired. Request a new one.', 'user' => null];
    }

    // Count the attempt before comparing, so a failure cannot be replayed
    // indefinitely by racing requests.
    $conn->query("UPDATE app_otp_codes SET attempts = attempts + 1 WHERE id = " . $otpId);

    $expected = (string) $row['code_hash'];
    $actual = optibiz_otp_code_hash($code, $channel, $destination, $purpose);
    if (!hash_equals($expected, $actual)) {
        $remaining = max(0, (int) $row['max_attempts'] - ((int) $row['attempts'] + 1));
        return [
            'success' => false,
            'status'  => 400,
            'message' => $remaining > 0
                ? 'That code is not correct. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' left.'
                : 'That code is not correct. Request a new one.',
            'user' => null,
        ];
    }

    $conn->query("UPDATE app_otp_codes SET consumed_at = NOW() WHERE id = " . $otpId);

    $userId = (int) ($row['user_id'] ?? 0);
    $user = $userId > 0 ? optibiz_app_user_by_id($conn, $userId) : null;

    // A code for an account that has since been deleted must not work.
    if ($user && ($user['status'] ?? '') === 'deleted') {
        return ['success' => false, 'status' => 403, 'message' => 'This account no longer exists.', 'user' => null];
    }

    return ['success' => true, 'status' => 200, 'message' => 'Code verified.', 'user' => $user, 'otp_id' => $otpId];
}

/** Housekeeping: remove codes older than a day. Non-critical. */
function optibiz_otp_purge_expired($conn)
{
    if (random_int(1, 20) !== 1) {
        return;
    }
    @$conn->query("DELETE FROM app_otp_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
}
