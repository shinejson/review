<?php
/**
 * Optibiz REST API - GET /api/v1/reviews/list.php
 * Paginated list of verified customer reviews with status, rating & date filters
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/middleware/auth.php';
require_once RATE_ROOT_PATH . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_send_error('Method Not Allowed. Use GET.', 405);
}

$auth = api_authenticate_bearer($conn);
$companyId = $auth['company_id'];

if ($companyId <= 0) {
    api_send_success([
        'reviews'    => [],
        'pagination' => [
            'current_page' => 1,
            'per_page'     => 15,
            'total_items'  => 0,
            'total_pages'  => 0,
            'has_next'     => false,
            'has_prev'     => false,
        ]
    ], 'No company profile assigned to this workspace', 200);
}

// 1. Parse & validate pagination parameters
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? $_GET['per_page'] ?? 15)));
$offset = ($page - 1) * $limit;

// 2. Parse filter parameters
$whereParts = ["r.company_id = ?"];
$paramTypes = "i";
$paramValues = [$companyId];

// Filter by reported status (default: reported = 0)
$reportedParam = $_GET['reported'] ?? '0';
if ($reportedParam === '0' || $reportedParam === '1') {
    $whereParts[] = "r.reported = ?";
    $paramTypes  .= "i";
    $paramValues[] = (int)$reportedParam;
}

// Filter by exact star rating
if (isset($_GET['rating']) && is_numeric($_GET['rating'])) {
    $ratingVal = max(1, min(5, (int)$_GET['rating']));
    $whereParts[] = "r.rating = ?";
    $paramTypes  .= "i";
    $paramValues[] = $ratingVal;
}

// Filter by minimum star rating
if (isset($_GET['min_rating']) && is_numeric($_GET['min_rating'])) {
    $minRatingVal = max(1, min(5, (int)$_GET['min_rating']));
    $whereParts[] = "r.rating >= ?";
    $paramTypes  .= "i";
    $paramValues[] = $minRatingVal;
}

// Filter by verified status
if (isset($_GET['is_verified']) && ($_GET['is_verified'] === '0' || $_GET['is_verified'] === '1')) {
    $whereParts[] = "r.is_verified = ?";
    $paramTypes  .= "i";
    $paramValues[] = (int)$_GET['is_verified'];
}

// Filter by date range
if (!empty($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) {
    $whereParts[] = "r.created_at >= ?";
    $paramTypes  .= "s";
    $paramValues[] = $_GET['date_from'] . ' 00:00:00';
}
if (!empty($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
    $whereParts[] = "r.created_at <= ?";
    $paramTypes  .= "s";
    $paramValues[] = $_GET['date_to'] . ' 23:59:59';
}

// Search keyword
if (!empty($_GET['search'])) {
    $searchKey = '%' . trim($_GET['search']) . '%';
    $whereParts[] = "(r.customer_name LIKE ? OR r.customer_email LIKE ? OR r.comment LIKE ?)";
    $paramTypes  .= "sss";
    $paramValues[] = $searchKey;
    $paramValues[] = $searchKey;
    $paramValues[] = $searchKey;
}

$whereClause = implode(" AND ", $whereParts);

// Sorting
$sortParam = strtolower($_GET['sort'] ?? 'newest');
switch ($sortParam) {
    case 'oldest':
        $orderClause = "ORDER BY r.created_at ASC";
        break;
    case 'highest':
        $orderClause = "ORDER BY r.rating DESC, r.created_at DESC";
        break;
    case 'lowest':
        $orderClause = "ORDER BY r.rating ASC, r.created_at DESC";
        break;
    case 'newest':
    default:
        $orderClause = "ORDER BY r.created_at DESC";
        break;
}

// 3. Count total records matching filters
$countSql = "SELECT COUNT(*) as total FROM ratings r WHERE $whereClause";
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($paramTypes, ...$paramValues);
$countStmt->execute();
$totalItems = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$totalPages = $totalItems > 0 ? (int)ceil($totalItems / $limit) : 0;

// 4. Fetch paginated records
$dataSql = "SELECT r.id, r.company_id, r.rating, r.customer_name, r.customer_email, r.comment, r.photos, 
                   r.created_at, r.admin_reply, r.responded_at, r.helpful_count, r.reported, 
                   r.is_verified, r.verification_type, r.is_escalated, r.escalation_status
            FROM ratings r
            WHERE $whereClause
            $orderClause
            LIMIT ? OFFSET ?";

$dataStmt = $conn->prepare($dataSql);
$dataParamTypes = $paramTypes . "ii";
$dataParamValues = array_merge($paramValues, [$limit, $offset]);
$dataStmt->bind_param($dataParamTypes, ...$dataParamValues);
$dataStmt->execute();
$res = $dataStmt->get_result();

$reviews = [];
while ($row = $res->fetch_assoc()) {
    // Process photos into array
    $photosArr = [];
    if (!empty($row['photos'])) {
        $decoded = json_decode($row['photos'], true);
        if (is_array($decoded)) {
            $photosArr = $decoded;
        } else {
            $photosArr = array_filter(array_map('trim', explode(',', $row['photos'])));
        }
    }

    $reviews[] = [
        'id'                => (int)$row['id'],
        'rating'            => (int)$row['rating'],
        'customer_name'     => (string)($row['customer_name'] ?: 'Anonymous'),
        'customer_email'    => (string)($row['customer_email'] ?: ''),
        'comment'           => (string)($row['comment'] ?: ''),
        'photos'            => array_values($photosArr),
        'created_at'        => (string)$row['created_at'],
        'admin_reply'       => (string)($row['admin_reply'] ?: ''),
        'responded_at'      => $row['responded_at'] ? (string)$row['responded_at'] : null,
        'helpful_count'     => (int)($row['helpful_count'] ?? 0),
        'reported'          => (bool)$row['reported'],
        'is_verified'       => (bool)$row['is_verified'],
        'verification_type' => (string)($row['verification_type'] ?: 'standard'),
        'is_escalated'      => (bool)$row['is_escalated'],
        'escalation_status' => (string)($row['escalation_status'] ?: 'none'),
    ];
}
$dataStmt->close();

api_send_success([
    'reviews'    => $reviews,
    'pagination' => [
        'current_page' => $page,
        'per_page'     => $limit,
        'total_items'  => $totalItems,
        'total_pages'  => $totalPages,
        'has_next'     => $page < $totalPages,
        'has_prev'     => $page > 1,
    ]
], 'Reviews retrieved successfully', 200);
