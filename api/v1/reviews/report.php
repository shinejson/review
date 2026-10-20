<?php
/**
 * POST /api/v1/reviews/report.php
 *
 * Body: { review_id, reason, details }
 *
 * Reasons: spam, fake, conflict_of_interest, offensive, personal_info,
 *          wrong_business, other. Reports are moderated; a single report
 *          never hides a review (that would enable mass-report takedowns).
 */
require_once dirname(__DIR__) . '/helpers/app_bootstrap.php';
require_once RATE_ROOT_PATH . '/includes/review_trust.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

RateLimiter::check($conn, 'review_report', api_get_client_ip(), 10, 3600);

$input = api_get_request_body();
$reviewId = (int) ($input['review_id'] ?? $input['rating_id'] ?? 0);
$reason = strtolower(trim((string) ($input['reason'] ?? '')));
$allowed = ['spam', 'fake', 'conflict_of_interest', 'offensive', 'personal_info', 'wrong_business', 'other'];

if ($reviewId <= 0) {
    api_send_error('review_id is required.', 422);
}
if (!in_array($reason, $allowed, true)) {
    api_send_error('Choose a valid reason for the report.', 422, ['reason' => $allowed]);
}

$auth = null;
if (function_exists('api_bearer_token') && api_bearer_token() !== '') {
    $auth = api_require_app_user($conn);
}
$userId = $auth ? (int) $auth['user']['id'] : 0;

$result = review_report(
    $conn,
    $reviewId,
    $reason,
    (string) ($input['details'] ?? ''),
    $userId,
    api_get_client_ip(),
    $auth['user']['email'] ?? ''
);

if (empty($result['success'])) {
    api_send_error((string) $result['message'], 422);
}

api_send_success(['reported' => true], (string) $result['message'], 200);
