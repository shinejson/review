<?php
/**
 * POST /api/v1/auth/otp/request.php
 *
 * Body: { "channel": "email"|"sms", "destination": "...", "purpose": "login"|"recovery"|"delete_account"|"signup"|"verify_phone" }
 *
 * Sends a real code through the configured SMTP/SMS transport. The
 * response never reveals whether a recovery destination belongs to an
 * account: known and unknown addresses get the same message.
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';
api_app_require_post();

RateLimiter::check($conn, 'otp_request', api_get_client_ip(), 20, 3600);

$input = api_get_request_body();
$channel = (string) ($input['channel'] ?? 'email');
$destination = (string) ($input['destination'] ?? $input['email'] ?? $input['phone'] ?? '');
$purpose = (string) ($input['purpose'] ?? 'recovery');

$result = optibiz_otp_request($conn, $channel, $destination, $purpose);

if (empty($result['success'])) {
    api_send_error(
        (string) ($result['message'] ?? 'Could not send a verification code.'),
        (int) ($result['status'] ?? 500),
        isset($result['retry_after']) ? ['retry_after' => (int) $result['retry_after']] : null
    );
}

// Same wording for existing and non-existing accounts.
$genericMessage = 'If an account exists for those details, a verification code has been sent.';
api_send_success([
    'channel'    => $result['channel'] ?? $channel,
    'masked'     => $result['masked'] ?? '',
    'expires_in' => $result['expires_in'] ?? OPTIBIZ_OTP_TTL,
    'purpose'    => $purpose,
], $genericMessage, 200);
