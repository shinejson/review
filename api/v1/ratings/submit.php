<?php
/**
 * Optibiz REST API - POST /api/v1/ratings/submit.php
 * Public Rating Submission with Anti-Spam Rate Limiting, IP/Device Fingerprinting & Smart Booster Routing
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/rate_limiter.php';
require_once RATE_ROOT_PATH . '/config/database.php';
require_once RATE_ROOT_PATH . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$ip = api_get_client_ip();
$input = api_get_request_body();

// Extract client device fingerprint (from header or payload)
$fingerprint = trim($_SERVER['HTTP_X_DEVICE_FINGERPRINT'] ?? $input['device_fingerprint'] ?? $input['device_id'] ?? '');
$rateIdentifier = !empty($fingerprint) ? "{$ip}_{$fingerprint}" : $ip;

// 1. Enforce Anti-Spam Rate Limiting (5 reviews / 10 minutes)
RateLimiter::check($conn, 'submit_rating', $rateIdentifier, API_RATE_LIMIT_SUBMIT_MAX, API_RATE_LIMIT_SUBMIT_WINDOW);

// 2. Validate input parameters
$companyId = (int)($input['company_id'] ?? 0);
$rating    = (int)($input['rating'] ?? 0);

if ($companyId <= 0) {
    api_send_error('Validation error: Valid company_id is required.', 422, ['company_id' => 'Invalid or missing company ID.']);
}

if ($rating < 1 || $rating > 5) {
    api_send_error('Validation error: Rating must be an integer between 1 and 5.', 422, ['rating' => 'Rating must be between 1 and 5 stars.']);
}

// 3. Verify company existence and fetch booster routing configuration
$cStmt = $conn->prepare("SELECT id, tenant_id, company_name, google_store_url, booster_enabled, booster_min_stars 
                         FROM customers WHERE id = ? LIMIT 1");
$cStmt->bind_param("i", $companyId);
$cStmt->execute();
$company = $cStmt->get_result()->fetch_assoc();
$cStmt->close();

if (!$company) {
    api_send_error('Company not found.', 404);
}

$tenantId        = (int)($company['tenant_id'] ?? 0);
$companyName     = (string)$company['company_name'];
$boosterEnabled  = isset($company['booster_enabled']) ? (int)$company['booster_enabled'] : 1;
$boosterMinStars = isset($company['booster_min_stars']) ? (int)$company['booster_min_stars'] : 4;
$googleStoreUrl  = function_exists('cleanGoogleReviewUrl') 
    ? cleanGoogleReviewUrl($company['google_store_url'] ?? '') 
    : trim((string)($company['google_store_url'] ?? ''));

// Sanitize review text
$customerName  = trim(strip_tags((string)($input['customer_name'] ?? '')));
if (empty($customerName)) {
    $customerName = 'Anonymous';
} else {
    $customerName = mb_substr($customerName, 0, 100);
}

$customerEmail = trim((string)($input['customer_email'] ?? ''));
if (!empty($customerEmail)) {
    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        api_send_error('Validation error: Invalid email format.', 422, ['customer_email' => 'Please provide a valid email address.']);
    }
    $customerEmail = mb_substr($customerEmail, 0, 100);
}

$comment = trim((string)($input['comment'] ?? ''));
if (!empty($comment)) {
    $comment = htmlspecialchars(strip_tags($comment), ENT_QUOTES, 'UTF-8');
}

// 4. Anti-Spam Duplicate Detection (prevent double-submit of same comment within 180s)
if (!empty($comment)) {
    $dupStmt = $conn->prepare("SELECT id FROM ratings 
                               WHERE company_id = ? AND rating = ? AND comment = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 180 SECOND) 
                               LIMIT 1");
    $dupStmt->bind_param("iis", $companyId, $rating, $comment);
    $dupStmt->execute();
    $dupRes = $dupStmt->get_result();
    if ($dupRes->num_rows > 0) {
        $dupStmt->close();
        api_send_error('Duplicate review detected. You have already submitted this feedback recently.', 409);
    }
    $dupStmt->close();
}

// 5. Determine Escalation & Sentiment Routing
$isEscalated = ($rating < $boosterMinStars) ? 1 : 0;
$escalationStatus = $isEscalated ? 'pending' : 'none';

// 6. Insert rating record via prepared statement
$insSql = "INSERT INTO ratings (company_id, rating, customer_name, customer_email, comment, is_escalated, escalation_status, created_at) 
           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($insSql);
$stmt->bind_param("iisssis", $companyId, $rating, $customerName, $customerEmail, $comment, $isEscalated, $escalationStatus);

if (!$stmt->execute()) {
    $stmt->close();
    api_send_error('Database error: Unable to record rating at this time.', 500);
}

$reviewId = (int)$conn->insert_id;
$stmt->close();

// 7. Log analytics event
$userAgent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$deviceType = 'mobile';
if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', $userAgent)) {
    $deviceType = 'tablet';
} elseif (!preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $userAgent)) {
    $deviceType = 'desktop';
}

$eventStmt = $conn->prepare("INSERT INTO analytics_events (tenant_id, company_id, event_type, event_category, visitor_ip, user_agent, device_type, created_at) 
                             VALUES (?, ?, 'rating_submitted', 'mobile_api', ?, ?, ?, NOW())");
if ($eventStmt) {
    $eventStmt->bind_param("iisss", $tenantId, $companyId, $ip, $userAgent, $deviceType);
    @$eventStmt->execute();
    $eventStmt->close();
}

// 8. Build Sentiment Routing Response
$routingData = [
    'routing'             => $isEscalated ? 'private_shield' : 'google_booster',
    'score'               => $rating,
    'booster_eligible'    => ($rating >= $boosterMinStars && $boosterEnabled && !empty($googleStoreUrl)),
    'google_review_url'   => ($rating >= $boosterMinStars && !empty($googleStoreUrl)) ? $googleStoreUrl : null,
    'routing_message'     => $isEscalated 
        ? "Thank you for your candid feedback. Your concerns have been privately forwarded to {$companyName}'s leadership."
        : "Thank you for your {$rating}-star rating! We would greatly appreciate it if you could share this review on Google as well.",
];

api_send_success([
    'review_id'    => $reviewId,
    'company_id'   => $companyId,
    'company_name' => $companyName,
    'rating'       => $rating,
    'created_at'   => date('Y-m-d H:i:s'),
    'routing'      => $routingData
], 'Review submitted successfully', 201);
