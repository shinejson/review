<?php
/**
 * GET /api/v1/businesses/search.php
 *
 * Public discovery: category, minimum rating, verified-only, free-text
 * search, and distance from the customer's location.
 *
 * Query: q, category_id, category, min_rating, verified=1, lat, lng,
 *        radius_km, sort=relevance|rating|reviews|distance|newest, page, per_page
 */
require_once dirname(__DIR__) . '/helpers/app_bootstrap.php';
require_once RATE_ROOT_PATH . '/includes/review_trust.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    api_send_error('Method Not Allowed. Use GET.', 405);
}

review_trust_ensure_schema($conn);
RateLimiter::check($conn, 'business_search', api_get_client_ip(), 120, 60);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(50, (int) ($_GET['per_page'] ?? $_GET['limit'] ?? 20)));
$offset = ($page - 1) * $perPage;

$lat = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float) $_GET['lng'] : null;
$radius = isset($_GET['radius_km']) && is_numeric($_GET['radius_km']) ? (float) $_GET['radius_km'] : 0.0;
$hasLocation = $lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180;

$types = '';
$values = [];

$distanceSelect = 'NULL AS distance_km';
if ($hasLocation) {
    $distanceSelect = "(6371 * ACOS(LEAST(1.0, COS(RADIANS(?)) * COS(RADIANS(c.latitude)) * COS(RADIANS(c.longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(c.latitude))))) AS distance_km";
    $types .= 'ddd';
    $values[] = $lat;
    $values[] = $lng;
    $values[] = $lat;
}

$where = ["c.company_name <> ''"];
if (!empty($_GET['q'])) {
    $term = '%' . trim((string) $_GET['q']) . '%';
    $where[] = '(c.company_name LIKE ? OR c.description LIKE ? OR c.address LIKE ?)';
    $types .= 'sss';
    array_push($values, $term, $term, $term);
}
if (!empty($_GET['category_id']) && is_numeric($_GET['category_id'])) {
    $where[] = 'c.category_id = ?';
    $types .= 'i';
    $values[] = (int) $_GET['category_id'];
} elseif (!empty($_GET['category'])) {
    $where[] = 'cat.name = ?';
    $types .= 's';
    $values[] = trim((string) $_GET['category']);
}

$having = [];
if (isset($_GET['min_rating']) && is_numeric($_GET['min_rating'])) {
    $having[] = 'COALESCE(rs.avg_rating, 0) >= ?';
    $types .= 'd';
    $values[] = max(0, min(5, (float) $_GET['min_rating']));
}
if (($_GET['verified'] ?? '') === '1') {
    $having[] = 'COALESCE(rs.verified_reviews, 0) > 0';
}
if ($hasLocation && $radius > 0) {
    $having[] = 'distance_km IS NOT NULL AND distance_km <= ?';
    $types .= 'd';
    $values[] = $radius;
}

$whereSql = implode(' AND ', $where);
$havingSql = $having ? ' HAVING ' . implode(' AND ', $having) : '';

$baseSelect = "SELECT c.id, c.company_name, c.category_id, cat.name AS category_name,
        c.address, c.website, c.phone, c.whatsapp_number, c.description,
        c.latitude, c.longitude, t.logo,
        COALESCE(rs.total_reviews, 0) AS total_reviews,
        rs.avg_rating,
        COALESCE(rs.verified_reviews, 0) AS verified_reviews,
        {$distanceSelect}
    FROM customers c
    LEFT JOIN categories cat ON cat.id = c.category_id
    LEFT JOIN tenants t ON t.id = c.tenant_id
    LEFT JOIN (
        SELECT company_id, COUNT(*) AS total_reviews, AVG(rating) AS avg_rating,
               SUM(is_verified = 1) AS verified_reviews
          FROM ratings
         WHERE reported = 0 AND moderation_status = 'published'
         GROUP BY company_id
    ) rs ON rs.company_id = c.id
    WHERE {$whereSql}";

$countSql = "SELECT COUNT(*) AS total FROM ({$baseSelect} {$havingSql}) x";
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    $countStmt->bind_param($types, ...$values);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
} else {
    $total = 0;
}

$sort = strtolower((string) ($_GET['sort'] ?? 'relevance'));
$relevance = "COALESCE(rs.avg_rating, 0) DESC, COALESCE(rs.total_reviews, 0) DESC";
$order = [
    'rating'   => 'COALESCE(rs.avg_rating, 0) DESC, COALESCE(rs.total_reviews, 0) DESC',
    'reviews'  => 'COALESCE(rs.total_reviews, 0) DESC',
    'newest'   => 'c.created_at DESC',
    'distance' => $hasLocation ? 'distance_km IS NULL, distance_km ASC' : $relevance,
    'relevance'=> $relevance,
][$sort] ?? $relevance;

$sql = $baseSelect . $havingSql . " ORDER BY {$order} LIMIT ? OFFSET ?";
$listTypes = $types . 'ii';
$listValues = array_merge($values, [$perPage, $offset]);
$stmt = $conn->prepare($sql);
if (!$stmt) {
    api_send_error('Search is temporarily unavailable.', 500);
}
$stmt->bind_param($listTypes, ...$listValues);
$stmt->execute();
$res = $stmt->get_result();

$businesses = [];
while ($row = $res->fetch_assoc()) {
    $businesses[] = [
        'id'              => (int) $row['id'],
        'name'            => (string) $row['company_name'],
        'category'        => $row['category_name'] ?: null,
        'category_id'     => $row['category_id'] !== null ? (int) $row['category_id'] : null,
        'address'         => $row['address'] ?: null,
        'website'         => $row['website'] ?: null,
        'phone'           => $row['phone'] ?: null,
        'whatsapp'        => $row['whatsapp_number'] ?: null,
        'description'     => $row['description'] ?: null,
        'logo'            => $row['logo'] ?: null,
        'latitude'        => $row['latitude'] !== null ? (float) $row['latitude'] : null,
        'longitude'       => $row['longitude'] !== null ? (float) $row['longitude'] : null,
        'rating'          => $row['avg_rating'] !== null ? round((float) $row['avg_rating'], 2) : null,
        'total_reviews'   => (int) $row['total_reviews'],
        'verified_reviews'=> (int) $row['verified_reviews'],
        'distance_km'     => $row['distance_km'] !== null ? round((float) $row['distance_km'], 2) : null,
    ];
}
$stmt->close();

api_send_success([
    'businesses' => $businesses,
    'pagination' => [
        'current_page' => $page,
        'per_page'     => $perPage,
        'total_items'  => $total,
        'total_pages'  => (int) ceil($total / $perPage),
        'has_next'     => ($page * $perPage) < $total,
        'has_prev'     => $page > 1,
    ],
    'filters' => [
        'location_used' => $hasLocation,
        'radius_km'     => $radius > 0 ? $radius : null,
    ],
], 'Businesses retrieved.', 200);
