<?php
/**
 * POST /api/v1/reviews/helpful.php
 *
 * Body: { review_id }
 * Signed-in voters are counted per account; guests per IP address.
 * Returns { liked: true|false, count: n }.
 */
require_once dirname(__DIR__) . '/helpers/app_bootstrap.php';
require_once RATE_ROOT_PATH . '/includes/review_trust.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

RateLimiter::check($conn, 'review_helpful', api_get_client_ip(), 60, 3600);

$input = api_get_request_body();
$reviewId = (int) ($input['review_id'] ?? $input['rating_id'] ?? 0);
if ($reviewId <= 0) {
    api_send_error('review_id is required.', 422);
}

$auth = null;
if (function_exists('api_bearer_token') && api_bearer_token() !== '') {
    $auth = api_require_app_user($conn);
}

$result = review_toggle_helpful(
    $conn,
    $reviewId,
    $auth ? (int) $auth['user']['id'] : 0,
    api_get_client_ip()
);

if (empty($result['success'])) {
    api_send_error((string) $result['message'], 422);
}

api_send_success([
    'liked' => !empty($result['liked']),
    'count' => (int) ($result['count'] ?? 0),
], (string) $result['message'], 200);
