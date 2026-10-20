<?php
/**
 * POST /api/v1/business/dispute.php
 *
 * A business can dispute a review it believes is fake, not about a real
 * visit, or contains personal data. Disputes enter the same moderation
 * queue as customer reports; the review stays visible until a moderator
 * decides, so a business cannot bury criticism by disputing it.
 *
 * Body: { review_id, reason, details }
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/rate_limiter.php';
require_once dirname(__DIR__) . '/middleware/auth.php';
require_once RATE_ROOT_PATH . '/config/database.php';
require_once RATE_ROOT_PATH . '/includes/functions.php';
require_once RATE_ROOT_PATH . '/includes/review_trust.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$auth = api_authenticate_bearer($conn);
api_require_permission($auth, 'ratings');
RateLimiter::check($conn, 'business_dispute', (string) $auth['tenant_id'], 10, 3600);

$input = api_get_request_body();
$reviewId = (int) ($input['review_id'] ?? $input['rating_id'] ?? 0);
$reason = strtolower(trim((string) ($input['reason'] ?? '')));
$details = trim(strip_tags((string) ($input['details'] ?? '')));
$allowed = ['not_a_customer', 'factually_incorrect', 'personal_info', 'conflict_of_interest', 'policy_violation', 'other'];

if ($reviewId <= 0) {
    api_send_error('review_id is required.', 422);
}
if (!in_array($reason, $allowed, true)) {
    api_send_error('Choose a valid dispute reason.', 422, ['reason' => $allowed]);
}

$companyId = (int) $auth['company_id'];
$stmt = $conn->prepare("SELECT id, company_id FROM ratings WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $reviewId);
$stmt->execute();
$rating = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rating || (int) $rating['company_id'] !== $companyId) {
    api_send_error('That review does not belong to your business.', 404);
}

review_trust_ensure_schema($conn);

$stmt = $conn->prepare("SELECT id FROM reported_reviews
    WHERE rating_id = ? AND reporter_ip = ? AND status = 'open' LIMIT 1");
$marker = 'business:' . (int) $auth['tenant_id'];
$stmt->bind_param('is', $reviewId, $marker);
$stmt->execute();
$exists = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($exists) {
    api_send_error('A dispute for this review is already open.', 409);
}

$stmt = $conn->prepare("INSERT INTO reported_reviews (rating_id, reason, details, reporter_ip, reporter_user_id)
    VALUES (?, ?, ?, ?, NULL)");
$stmt->bind_param('isss', $reviewId, $reason, $details, $marker);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    api_send_error('Could not file the dispute. Please try again.', 500);
}

review_queue_add($conn, $reviewId, 'business', $reason, $details);

api_send_success(['disputed' => true], 'Your dispute has been sent to moderation. The review remains visible while it is reviewed.', 201);
