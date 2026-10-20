<?php
/**
 * GET /api/v1/reviews/public.php?company_id=…
 *
 * Public review feed: only published, non-reported reviews. Reviewer
 * email addresses are never returned. Photos are returned only after a
 * moderator approved them.
 *
 * Filters: rating, min_rating, verified=1, photos=1, page, per_page
 * Sort:    newest (default), oldest, highest, lowest, helpful
 */
require_once dirname(__DIR__) . '/helpers/app_bootstrap.php';
require_once RATE_ROOT_PATH . '/includes/review_trust.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_send_error('Method Not Allowed. Use GET.', 405);
}

review_trust_ensure_schema($conn);
RateLimiter::check($conn, 'public_reviews', api_get_client_ip(), 120, 60);

$companyId = (int) ($_GET['company_id'] ?? 0);
if ($companyId <= 0) {
    api_send_error('company_id is required.', 422);
}

$stmt = $conn->prepare("SELECT id, company_name FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $companyId);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$company) {
    api_send_error('Company not found.', 404);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(50, (int) ($_GET['per_page'] ?? $_GET['limit'] ?? 10)));
$offset = ($page - 1) * $perPage;

$where = ["r.company_id = ?", "r.reported = 0", "r.moderation_status = 'published'"];
$types = 'i';
$values = [$companyId];

if (isset($_GET['rating']) && is_numeric($_GET['rating'])) {
    $where[] = 'r.rating = ?';
    $types .= 'i';
    $values[] = max(1, min(5, (int) $_GET['rating']));
}
if (isset($_GET['min_rating']) && is_numeric($_GET['min_rating'])) {
    $where[] = 'r.rating >= ?';
    $types .= 'i';
    $values[] = max(1, min(5, (int) $_GET['min_rating']));
}
if (($_GET['verified'] ?? '') === '1') {
    $where[] = 'r.is_verified = 1';
}
if (($_GET['photos'] ?? '') === '1') {
    $where[] = "r.photos_approved = 1 AND r.photos IS NOT NULL AND r.photos <> ''";
}
if (!empty($_GET['search'])) {
    $term = '%' . trim((string) $_GET['search']) . '%';
    $where[] = '(r.comment LIKE ? OR r.customer_name LIKE ?)';
    $types .= 'ss';
    $values[] = $term;
    $values[] = $term;
}

$whereSql = implode(' AND ', $where);
$sort = strtolower((string) ($_GET['sort'] ?? 'newest'));
$order = [
    'oldest'  => 'r.created_at ASC',
    'highest' => 'r.rating DESC, r.created_at DESC',
    'lowest'  => 'r.rating ASC, r.created_at DESC',
    'helpful' => 'r.helpful_count DESC, r.created_at DESC',
    'newest'  => 'r.created_at DESC',
][$sort] ?? 'r.created_at DESC';

$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM ratings r WHERE {$whereSql}");
$countStmt->bind_param($types, ...$values);
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$sql = "SELECT r.id, r.rating, r.customer_name, r.comment, r.photos, r.photos_approved,
               r.is_verified, r.verification_type, r.helpful_count, r.admin_reply, r.responded_at,
               r.category_service, r.category_cleanliness, r.category_value, r.category_speed,
               r.created_at
          FROM ratings r
         WHERE {$whereSql}
         ORDER BY {$order}
         LIMIT ? OFFSET ?";
$listTypes = $types . 'ii';
$listValues = array_merge($values, [$perPage, $offset]);
$stmt = $conn->prepare($sql);
$stmt->bind_param($listTypes, ...$listValues);
$stmt->execute();
$res = $stmt->get_result();

$reviews = [];
while ($row = $res->fetch_assoc()) {
    $reviews[] = [
        'id'                => (int) $row['id'],
        'rating'            => (int) $row['rating'],
        'customer_name'     => (string) $row['customer_name'],
        'comment'           => (string) $row['comment'],
        'photos'            => review_public_photos($row),
        'is_verified'       => !empty($row['is_verified']),
        'verification_type' => $row['verification_type'] ?: null,
        'categories'        => [
            'service'     => $row['category_service'] !== null ? (int) $row['category_service'] : null,
            'cleanliness' => $row['category_cleanliness'] !== null ? (int) $row['category_cleanliness'] : null,
            'value'       => $row['category_value'] !== null ? (int) $row['category_value'] : null,
            'speed'       => $row['category_speed'] !== null ? (int) $row['category_speed'] : null,
        ],
        'helpful_count'     => (int) $row['helpful_count'],
        'admin_reply'       => $row['admin_reply'] ?: null,
        'responded_at'      => $row['responded_at'] ?: null,
        'created_at'        => $row['created_at'],
    ];
}
$stmt->close();

// Rating summary (published reviews only).
$summaryStmt = $conn->prepare("SELECT COUNT(*) AS total, AVG(rating) AS avg_rating,
        SUM(is_verified = 1) AS verified,
        AVG(category_service) AS c_service, AVG(category_cleanliness) AS c_cleanliness,
        AVG(category_value) AS c_value, AVG(category_speed) AS c_speed
    FROM ratings WHERE company_id = ? AND reported = 0 AND moderation_status = 'published'");
$summaryStmt->bind_param('i', $companyId);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

$distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
$distStmt = $conn->prepare("SELECT rating, COUNT(*) AS cnt FROM ratings
    WHERE company_id = ? AND reported = 0 AND moderation_status = 'published' GROUP BY rating");
$distStmt->bind_param('i', $companyId);
$distStmt->execute();
$distRes = $distStmt->get_result();
while ($row = $distRes->fetch_assoc()) {
    $distribution[(int) $row['rating']] = (int) $row['cnt'];
}
$distStmt->close();

api_send_success([
    'company' => [
        'id'   => (int) $company['id'],
        'name' => (string) $company['company_name'],
    ],
    'summary' => [
        'total_reviews'     => (int) ($summary['total'] ?? 0),
        'average_rating'    => $summary['avg_rating'] !== null ? round((float) $summary['avg_rating'], 2) : null,
        'verified_reviews'  => (int) ($summary['verified'] ?? 0),
        'distribution'      => $distribution,
        'category_averages' => [
            'service'     => $summary['c_service'] !== null ? round((float) $summary['c_service'], 2) : null,
            'cleanliness' => $summary['c_cleanliness'] !== null ? round((float) $summary['c_cleanliness'], 2) : null,
            'value'       => $summary['c_value'] !== null ? round((float) $summary['c_value'], 2) : null,
            'speed'       => $summary['c_speed'] !== null ? round((float) $summary['c_speed'], 2) : null,
        ],
    ],
    'reviews'    => $reviews,
    'pagination' => [
        'current_page' => $page,
        'per_page'     => $perPage,
        'total_items'  => $total,
        'total_pages'  => (int) ceil($total / $perPage),
        'has_next'     => ($page * $perPage) < $total,
        'has_prev'     => $page > 1,
    ],
], 'Reviews retrieved.', 200);
