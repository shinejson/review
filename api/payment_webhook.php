<?php
/**
 * ============================================================
 *  Payment gateway webhook receiver
 * ============================================================
 *  Paste this URL into the Paystack / Flutterwave dashboard:
 *
 *      https://your-domain/api/payment_webhook.php?gateway=paystack
 *      https://your-domain/api/payment_webhook.php?gateway=flutterwave
 *
 *  Every delivery is verified before anything is written:
 *
 *    Paystack    — HMAC SHA512 of the raw body, compared against the
 *                  x-paystack-signature header.
 *    Flutterwave — the verif-hash header must equal the secret hash
 *                  saved on the integrations screen.
 *
 *  A verified `charge.success` is then re-checked against the gateway's
 *  own API (never trusted from the payload alone), recorded in the
 *  ledger as `pending` and parked for the platform owner to confirm.
 *
 *  The endpoint always answers 200 with JSON so the gateway does not
 *  retry a delivery we have already handled; every attempt is written
 *  to `payment_events` either way.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/payments.php';

header('Content-Type: application/json; charset=utf-8');

/** Lower-cased header name => value, whichever SAPI is running. */
if (!function_exists('pay_request_headers')) {
    function pay_request_headers()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        }
        return $headers;
    }
}

$respond = function ($payload) {
    echo json_encode($payload);
    exit();
};

$gateway_key = isset($_GET['gateway']) ? preg_replace('/[^a-z_]/', '', strtolower((string) $_GET['gateway'])) : '';
$gateway     = $gateway_key !== '' ? pay_gateway($conn, $gateway_key) : null;

if (!$gateway) {
    pay_log_event($conn, [
        'gateway_key' => $gateway_key,
        'event_type'  => 'webhook.unknown_gateway',
        'payload'     => 'Webhook called for an integration that is not installed.',
    ]);
    $respond(['status' => 'ignored', 'reason' => 'unknown gateway']);
}

$raw     = file_get_contents('php://input');
$headers = pay_request_headers();

// The secret keys are not in the payload, so a wrong signature means the
// delivery did not come from the provider — log it and stop.
if (!pay_webhook_signature_ok($gateway, $raw, $headers)) {
    pay_log_event($conn, [
        'gateway_key'     => $gateway_key,
        'event_type'      => 'webhook.bad_signature',
        'signature_valid' => 0,
        'payload'         => substr($raw !== '' ? $raw : '(empty body)', 0, 4000),
    ]);
    http_response_code(401);
    $respond(['status' => 'rejected', 'reason' => 'signature mismatch']);
}

$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    pay_log_event($conn, [
        'gateway_key' => $gateway_key,
        'event_type'  => 'webhook.unreadable',
        'payload'     => substr((string) $raw, 0, 2000),
    ]);
    $respond(['status' => 'ignored', 'reason' => 'unreadable payload']);
}

/* Pull the event name and the reference out of each provider's shape. */
$event = '';
$reference = '';
$data = [];

if ($gateway['driver'] === 'paystack') {
    $event     = isset($body['event']) ? (string) $body['event'] : '';
    $data      = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
    $reference = isset($data['reference']) ? (string) $data['reference'] : '';
} elseif ($gateway['driver'] === 'flutterwave') {
    $event     = isset($body['event']) ? (string) $body['event'] : (isset($body['event.type']) ? (string) $body['event.type'] : '');
    $data      = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
    $reference = isset($data['tx_ref']) ? (string) $data['tx_ref'] : (isset($data['txRef']) ? (string) $data['txRef'] : '');
}

pay_log_event($conn, [
    'gateway_key'     => $gateway_key,
    'event_type'      => 'webhook.' . ($event !== '' ? $event : 'unknown'),
    'reference'       => $reference,
    'signature_valid' => 1,
    'payload'         => json_encode(['reference' => $reference, 'event' => $event, 'data' => $data]),
]);

/* Only the success events carry money we need to record. */
$is_charge_event = ($gateway['driver'] === 'paystack' && $event === 'charge.success')
    || ($gateway['driver'] === 'flutterwave' && (stripos($event, 'charge.completed') !== false || stripos($event, 'successful') !== false));

if (!$is_charge_event) {
    $respond(['status' => 'received', 'event' => $event]);
}

if ($reference === '') {
    $respond(['status' => 'received', 'note' => 'no reference in payload']);
}

// Verify against the gateway API and record — idempotent, so a webhook
// that arrives after the browser callback changes nothing.
$result = pay_settle_gateway_payment($conn, $gateway_key, $reference, 'webhook');

$respond([
    'status'   => $result['ok'] ? ($result['duplicate'] ? 'duplicate' : 'recorded') : 'error',
    'paid'     => (bool) $result['paid'],
    'message'  => $result['message'],
]);
