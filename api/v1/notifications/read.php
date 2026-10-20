<?php
/**
 * POST /api/v1/notifications/read.php
 *
 * Body: { ids: [1,2,3] } or { all: true }
 * Marks notifications as read. Only the caller's own rows are touched.
 */
require_once dirname(__DIR__) . '/helpers/app_bootstrap.php';
require_once RATE_ROOT_PATH . '/includes/push.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$auth = api_require_app_user($conn);
$userId = (int) $auth['user']['id'];
RateLimiter::check($conn, 'notifications_read', (string) $userId, 120, 60);
push_ensure_schema($conn);

$input = api_get_request_body();
$all = !empty($input['all']);
$ids = [];
if (isset($input['ids']) && is_array($input['ids'])) {
    foreach ($input['ids'] as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
}

if (!$all && !$ids) {
    api_send_error('Send ids, or all: true.', 422);
}

if ($all) {
    $stmt = $conn->prepare("UPDATE app_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
    $stmt->bind_param('i', $userId);
} else {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = 'i' . str_repeat('i', count($ids));
    $params = array_merge([$userId], array_values($ids));
    $stmt = $conn->prepare("UPDATE app_notifications SET read_at = NOW()
        WHERE user_id = ? AND read_at IS NULL AND id IN ($placeholders)");
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$updated = (int) $stmt->affected_rows;
$stmt->close();

api_send_success(['updated' => $updated], 'Notifications marked as read.', 200);
