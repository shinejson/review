<?php
/**
 * GET /api/v1/notifications/list.php
 *
 * The signed-in customer's inbox: replies from businesses, moderation
 * outcomes and system notices. Newest first, cursor-paged with
 * ?after_id= for polling and ?before_id= for scrolling back.
 */
require_once dirname(__DIR__) . '/helpers/app_bootstrap.php';
require_once RATE_ROOT_PATH . '/includes/push.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_send_error('Method Not Allowed. Use GET.', 405);
}

$auth = api_require_app_user($conn);
$userId = (int) $auth['user']['id'];

RateLimiter::check($conn, 'notifications_list', (string) $userId, 120, 60);
push_ensure_schema($conn);

$limit = (int) ($_GET['limit'] ?? 20);
$limit = max(1, min(50, $limit));
$afterId = (int) ($_GET['after_id'] ?? 0);
$beforeId = (int) ($_GET['before_id'] ?? 0);
$unreadOnly = !empty($_GET['unread']);

$where = ['user_id = ?'];
$types = 'i';
$params = [$userId];

if ($afterId > 0) {
    $where[] = 'id > ?';
    $types .= 'i';
    $params[] = $afterId;
}
if ($beforeId > 0) {
    $where[] = 'id < ?';
    $types .= 'i';
    $params[] = $beforeId;
}
if ($unreadOnly) {
    $where[] = 'read_at IS NULL';
}

$sql = 'SELECT id, type, title, body, link, data_json, read_at, created_at
    FROM app_notifications WHERE ' . implode(' AND ', $where) . '
    ORDER BY id DESC LIMIT ' . ($limit + 1);

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);
}

$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'         => (int) $row['id'],
        'type'       => $row['type'],
        'title'      => $row['title'],
        'body'       => $row['body'],
        'link'       => $row['link'],
        'data'       => $row['data_json'] ? json_decode($row['data_json'], true) : null,
        'read'       => $row['read_at'] !== null,
        'created_at' => $row['created_at'],
    ];
}

$countStmt = $conn->prepare("SELECT COUNT(*) AS unread FROM app_notifications WHERE user_id = ? AND read_at IS NULL");
$countStmt->bind_param('i', $userId);
$countStmt->execute();
$unread = (int) ($countStmt->get_result()->fetch_assoc()['unread'] ?? 0);
$countStmt->close();

api_send_success(
    ['notifications' => $items, 'unread' => $unread, 'has_more' => $hasMore],
    'Notifications loaded.',
    200
);
