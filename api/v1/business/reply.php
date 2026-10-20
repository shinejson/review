<?php
/**
 * POST /api/v1/business/reply.php
 *
 * Workspace-authenticated reply to one of its own reviews.
 * Body: { review_id, reply }
 *
 * The reply is stored both in rating_replies (thread) and on the rating
 * (admin_reply/responded_at) so every existing screen and the public page
 * show it without a second query.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/rate_limiter.php';
require_once dirname(__DIR__) . '/middleware/auth.php';
require_once RATE_ROOT_PATH . '/config/database.php';
require_once RATE_ROOT_PATH . '/includes/functions.php';
require_once RATE_ROOT_PATH . '/includes/push.php';
require_once RATE_ROOT_PATH . '/includes/review_trust.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$auth = api_authenticate_bearer($conn);
api_require_permission($auth, 'ratings');

$input = api_get_request_body();
$reviewId = (int) ($input['review_id'] ?? $input['rating_id'] ?? 0);
$reply = trim(strip_tags((string) ($input['reply'] ?? $input['reply_text'] ?? '')));

if ($reviewId <= 0 || $reply === '') {
    api_send_error('review_id and reply are required.', 422);
}
if (mb_strlen($reply) > 3000) {
    api_send_error('A reply can be at most 3,000 characters.', 422);
}

$companyId = (int) $auth['company_id'];
$stmt = $conn->prepare("SELECT id, company_id, customer_user_id FROM ratings WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $reviewId);
$stmt->execute();
$rating = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rating || (int) $rating['company_id'] !== $companyId) {
    api_send_error('That review does not belong to your business.', 404);
}

ensureRatingRepliesTable($conn);
$replyName = (string) ($auth['company_name'] !== '' ? $auth['company_name'] : 'Business owner');
$replyName = substr($replyName, 0, 100);
$replyEmail = (string) ($auth['email'] ?? '');

$stmt = $conn->prepare("INSERT INTO rating_replies
    (rating_id, company_id, user_name, user_email, reply_text, is_official, created_at)
    VALUES (?, ?, ?, ?, ?, 1, NOW())");
if (!$stmt) {
    api_send_error('Could not save the reply. Please try again.', 500);
}
$stmt->bind_param('iisss', $reviewId, $companyId, $replyName, $replyEmail, $reply);
$ok = $stmt->execute();
$replyId = (int) $stmt->insert_id;
$stmt->close();

if (!$ok) {
    api_send_error('Could not save the reply. Please try again.', 500);
}

$stmt = $conn->prepare("UPDATE ratings SET admin_reply = ?, responded_at = NOW() WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('si', $reply, $reviewId);
    $stmt->execute();
    $stmt->close();
}

if (!empty($rating['customer_user_id']) && function_exists('push_notify_review_reply')) {
    push_notify_review_reply($conn, (int) $rating['customer_user_id'], $reviewId, (string) $auth['company_name']);
}

api_send_success([
    'reply_id'   => $replyId,
    'review_id'  => $reviewId,
    'reply'      => $reply,
    'created_at' => date('Y-m-d H:i:s'),
], 'Reply published.', 201);
