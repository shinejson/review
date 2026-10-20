<?php
/**
 * POST /api/v1/auth/customer/device.php
 *
 * Body: { "device_id": "...", "platform": "android", "fcm_token": "...", "app_version": "1.2.0" }
 *
 * Registers this installation for push notifications. Tokens are stored
 * per account/device so a shared phone cannot deliver one person's
 * notifications to the next person who signs in.
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';
api_app_require_post();

$auth = api_require_app_user($conn);
$input = api_get_request_body();

if (!api_register_device($conn, (int) $auth['user']['id'], $input)) {
    api_send_error('device_id is required.', 422, ['device_id' => 'Send a stable installation id.']);
}

api_send_success(['registered' => true], 'Device registered for notifications.', 200);
