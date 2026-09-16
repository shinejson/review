<?php
/**
 * Optibiz REST API - GET /api/v1/dashboard/metrics.php
 * Tenant Analytics, Review Distribution & Sentiment Trends
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/rate_limiter.php';
require_once dirname(__DIR__) . '/middleware/auth.php';
require_once RATE_ROOT_PATH . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_send_error('Method Not Allowed. Use GET.', 405);
}

// Enforce Bearer Token Authentication
$auth = api_authenticate_bearer($conn);
$tenantId  = $auth['tenant_id'];
$companyId = $auth['company_id'];

// Default zero-metrics structure
$totalRatings     = 0;
$avgRating        = 0.0;
$totalReported    = 0;
$totalVerified    = 0;
$totalEscalated   = 0;
$ratingBreakdown  = [
    '5_star' => 0,
    '4_star' => 0,
    '3_star' => 0,
    '2_star' => 0,
    '1_star' => 0,
];
$sentiment = [
    'positive' => 0, // 4-5 stars
    'neutral'  => 0, // 3 stars
    'negative' => 0  // 1-2 stars
];
$dailyTrends = [];
$boosterEventsCount = 0;

if ($companyId > 0) {
    // 1. Overall stats
    $stmt = $conn->prepare("SELECT 
        COUNT(*) as total_ratings,
        AVG(CASE WHEN reported = 0 THEN rating ELSE NULL END) as avg_score,
        SUM(CASE WHEN reported = 1 THEN 1 ELSE 0 END) as total_reported,
        SUM(CASE WHEN is_verified = 1 AND reported = 0 THEN 1 ELSE 0 END) as total_verified,
        SUM(CASE WHEN is_escalated = 1 THEN 1 ELSE 0 END) as total_escalated,
        SUM(CASE WHEN rating = 5 AND reported = 0 THEN 1 ELSE 0 END) as r5,
        SUM(CASE WHEN rating = 4 AND reported = 0 THEN 1 ELSE 0 END) as r4,
        SUM(CASE WHEN rating = 3 AND reported = 0 THEN 1 ELSE 0 END) as r3,
        SUM(CASE WHEN rating = 2 AND reported = 0 THEN 1 ELSE 0 END) as r2,
        SUM(CASE WHEN rating = 1 AND reported = 0 THEN 1 ELSE 0 END) as r1
        FROM ratings WHERE company_id = ?");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $totalRatings   = (int)($row['total_ratings'] ?? 0);
        $avgRating      = round((float)($row['avg_score'] ?? 0.0), 1);
        $totalReported  = (int)($row['total_reported'] ?? 0);
        $totalVerified  = (int)($row['total_verified'] ?? 0);
        $totalEscalated = (int)($row['total_escalated'] ?? 0);

        $r5 = (int)($row['r5'] ?? 0);
        $r4 = (int)($row['r4'] ?? 0);
        $r3 = (int)($row['r3'] ?? 0);
        $r2 = (int)($row['r2'] ?? 0);
        $r1 = (int)($row['r1'] ?? 0);

        $ratingBreakdown = [
            '5_star' => $r5,
            '4_star' => $r4,
            '3_star' => $r3,
            '2_star' => $r2,
            '1_star' => $r1,
        ];

        $sentiment = [
            'positive' => $r5 + $r4,
            'neutral'  => $r3,
            'negative' => $r2 + $r1,
        ];
    }

    // 2. Daily review trends for the last 30 days
    $trendStmt = $conn->prepare("SELECT DATE(created_at) as review_date, COUNT(*) as count, AVG(rating) as daily_avg
                                 FROM ratings 
                                 WHERE company_id = ? AND reported = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                 GROUP BY DATE(created_at)
                                 ORDER BY review_date ASC");
    if ($trendStmt) {
        $trendStmt->bind_param("i", $companyId);
        $trendStmt->execute();
        $trendRes = $trendStmt->get_result();
        while ($t = $trendRes->fetch_assoc()) {
            $dailyTrends[] = [
                'date'      => $t['review_date'],
                'count'     => (int)$t['count'],
                'daily_avg' => round((float)$t['daily_avg'], 1)
            ];
        }
        $trendStmt->close();
    }

    // 3. Analytics events (booster clicks & QR scans)
    $evtStmt = $conn->prepare("SELECT COUNT(*) as count FROM analytics_events WHERE company_id = ?");
    if ($evtStmt) {
        $evtStmt->bind_param("i", $companyId);
        $evtStmt->execute();
        $evtRes = $evtStmt->get_result()->fetch_assoc();
        $boosterEventsCount = (int)($evtRes['count'] ?? 0);
        $evtStmt->close();
    }
}

// 4. Platform benchmark averages
$platRow = $conn->query("SELECT COUNT(*) as total_plat, AVG(rating) as avg_plat FROM ratings WHERE reported = 0")->fetch_assoc();
$platformMetrics = [
    'platform_total_reviews' => (int)($platRow['total_plat'] ?? 0),
    'platform_average'       => round((float)($platRow['avg_plat'] ?? 4.8), 1),
];

api_send_success([
    'company' => [
        'tenant_id'    => $tenantId,
        'company_id'   => $companyId,
        'company_name' => $auth['company_name'],
    ],
    'overview' => [
        'total_ratings'    => $totalRatings,
        'average_score'    => $avgRating,
        'verified_reviews' => $totalVerified,
        'reported_reviews' => $totalReported,
        'pending_shields'  => $totalEscalated,
        'booster_events'   => $boosterEventsCount,
    ],
    'breakdown' => $ratingBreakdown,
    'sentiment' => $sentiment,
    'trends_30d' => $dailyTrends,
    'platform_benchmarks' => $platformMetrics,
], 'Dashboard metrics retrieved successfully', 200);
