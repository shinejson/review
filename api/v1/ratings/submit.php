<?php
/**
 * POST /api/v1/ratings/submit.php
 *
 * The canonical review submission endpoint for the mobile app.
 *
 * Accepts an optional customer bearer token. Signed-in reviewers are
 * identified by account id (so one review per business per window is
 * enforceable across devices); guests must supply a name and an email or
 * phone number. A QR token from the counter stand or a receipt marks the
 * review as a verified visit.
 *
 * Review gating is deliberately not implemented: every reviewer is
 * offered the Google link when the business has one, regardless of the
 * star score. Low scores still raise a private follow-up for the owner.
 */
require_once dirname(__DIR__) . '/helpers/app_bootstrap.php';
require_once RATE_ROOT_PATH . '/includes/review_trust.php';
require_once RATE_ROOT_PATH . '/includes/push.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_send_error('Method Not Allowed. Use POST.', 405);
}

$ip = api_get_client_ip();
$input = api_get_request_body();

$fingerprint = trim((string) (
    $_SERVER['HTTP_X_DEVICE_FINGERPRINT']
    ?? $input['device_fingerprint']
    ?? $input['device_id']
    ?? ''
));
$rateIdentifier = $fingerprint !== '' ? ($ip . '_' . $fingerprint) : $ip;

// Burst guard: 5 submissions / 10 minutes for this IP+installation.
RateLimiter::check($conn, 'submit_rating', $rateIdentifier, API_RATE_LIMIT_SUBMIT_MAX, API_RATE_LIMIT_SUBMIT_WINDOW);
// Wider guard against a single network running a review farm.
RateLimiter::check($conn, 'submit_rating_ip_hour', $ip, 20, 3600);

// New columns (qr_token_id, category scores, moderation state) and the
// customer coordinates used by discovery must exist before the SELECT
// below reads them.
review_trust_ensure_schema($conn);

$companyId = (int) ($input['company_id'] ?? 0);
$rating = (int) ($input['rating'] ?? 0);

if ($companyId <= 0) {
    api_send_error('Validation error: Valid company_id is required.', 422, ['company_id' => 'Invalid or missing company ID.']);
}
if ($rating < 1 || $rating > 5) {
    api_send_error('Validation error: Rating must be an integer between 1 and 5.', 422, ['rating' => 'Rating must be between 1 and 5 stars.']);
}

$stmt = $conn->prepare("SELECT id, tenant_id, company_name, google_store_url, website, booster_enabled, booster_min_stars, latitude, longitude
    FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $companyId);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$company) {
    api_send_error('Company not found.', 404);
}

$tenantId = (int) ($company['tenant_id'] ?? 0);
$companyName = (string) $company['company_name'];
$boosterMinStars = (int) ($company['booster_min_stars'] ?? 4);
$googleStoreUrl = function_exists('cleanGoogleReviewUrl')
    ? cleanGoogleReviewUrl($company['google_store_url'] ?? '')
    : trim((string) ($company['google_store_url'] ?? ''));

// Optional customer session. A workspace token is not accepted here.
$appUser = api_optional_app_user($conn);
$userId = $appUser ? (int) $appUser['id'] : 0;

$customerName = trim(strip_tags((string) ($input['customer_name'] ?? '')));
$customerEmail = optibiz_app_email($input['customer_email'] ?? '');
$customerPhone = optibiz_app_phone($input['customer_phone'] ?? '');

if ($appUser) {
    $customerName = (string) ($appUser['full_name'] ?? '');
    $customerEmail = optibiz_app_email($appUser['email'] ?? '');
    $customerPhone = optibiz_app_phone($appUser['phone'] ?? '');
} else {
    if ($customerName === '') {
        api_send_error('Your name is required.', 422, ['customer_name' => 'Enter your name.']);
    }
    if ($customerEmail === '' && $customerPhone === '') {
        api_send_error('Add an email address or phone number so the business can follow up.', 422);
    }
    if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        api_send_error('Validation error: Invalid email format.', 422, ['customer_email' => 'Please provide a valid email address.']);
    }
}
$customerName = $customerName !== '' ? mb_substr($customerName, 0, 100) : 'Anonymous';
$customerEmail = mb_substr($customerEmail, 0, 190);
$customerPhone = mb_substr($customerPhone, 0, 30);

$comment = trim((string) ($input['comment'] ?? ''));
$comment = strip_tags($comment);
$comment = mb_substr($comment, 0, 4000);

$categories = review_categories_from_input($input);
$deviceHash = $fingerprint !== '' ? hash('sha256', 'device|' . $fingerprint) : '';
$ipHash = hash('sha256', 'ip|' . $ip);
$qrRaw = review_qr_from_input($input);
$qr = null;

if ($qrRaw !== '') {
    $qr = review_qr_verify($conn, $qrRaw, $companyId);
    if (!$qr) {
        api_send_error(
            'This QR code is invalid or has expired. Scan the code at the counter again, or submit without it.',
            422,
            ['qr_token' => 'Invalid or expired QR token for this business.']
        );
    }
}

$isVerified = $qr ? 1 : 0;
$verificationType = $qr ? ($qr['kind'] === 'visit' ? 'qr_visit' : 'qr_location') : null;

// One review per customer per business per window.
$recent = review_find_recent_by_customer($conn, $companyId, $userId, $customerEmail, $deviceHash);
if ($recent) {
    $days = review_window_days();
    api_send_error(
        'You have already reviewed this business in the last ' . $days . ' days. Thank you for your feedback!',
        409,
        ['code' => 'customer_review_window', 'retry_after_days' => $days]
    );
}

// Duplicate text from a different person is still suspicious, but the
// window check above is the hard block.
$abuse = review_abuse_assess($conn, $companyId, [
    'comment'     => $comment,
    'device_hash' => $deviceHash,
    'ip_hash'     => $ipHash,
    'user_id'     => $userId,
    'has_qr'      => (bool) $qr,
]);

$isEscalated = ($rating < $boosterMinStars) ? 1 : 0;
$escalationStatus = $isEscalated ? 'pending' : 'none';

// Reviews with strong fraud signals are held; medium signals stay visible
// but enter the moderation queue for a human to check.
$moderationStatus = $abuse['hide'] ? 'queued' : 'published';
$reportedFlag = $abuse['hide'] ? 1 : 0;
$abuseFlags = implode(',', $abuse['flags']);

$stmt = $conn->prepare("INSERT INTO ratings
    (company_id, rating, customer_name, customer_email, comment,
     is_verified, verification_type, category_service, category_cleanliness, category_value, category_speed,
     customer_user_id, qr_token_id, source, device_hash, ip_hash, abuse_score, abuse_flags,
     moderation_status, photos_approved, reported, is_escalated, escalation_status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, NOW())");

if (!$stmt) {
    api_send_error('Database error: Unable to record rating at this time.', 500);
}

$categoryService = $categories['service'];
$categoryCleanliness = $categories['cleanliness'];
$categoryValue = $categories['value'];
$categorySpeed = $categories['speed'];
$qrTokenId = $qr && !empty($qr['token_id']) ? (int) $qr['token_id'] : null;
$userIdOrNull = $userId > 0 ? $userId : null;
$source = $qr ? 'qr' : ($appUser ? 'app' : 'api');

$stmt->bind_param(
    'iisssisiiiiiisssissiis',
    $companyId,
    $rating,
    $customerName,
    $customerEmail,
    $comment,
    $isVerified,
    $verificationType,
    $categoryService,
    $categoryCleanliness,
    $categoryValue,
    $categorySpeed,
    $userIdOrNull,
    $qrTokenId,
    $source,
    $deviceHash,
    $ipHash,
    $abuse['score'],
    $abuseFlags,
    $moderationStatus,
    $reportedFlag,
    $isEscalated,
    $escalationStatus
);

if (!$stmt->execute()) {
    $stmt->close();
    api_send_error('Database error: Unable to record rating at this time.', 500);
}

$reviewId = (int) $conn->insert_id;
$stmt->close();

if ($qr) {
    review_qr_consume($conn, $qr);
}

if (!empty($_FILES['photos']['name'][0])) {
    review_attach_photos($conn, $reviewId, $_FILES['photos']);
}

if ($abuse['queue']) {
    review_queue_add($conn, $reviewId, 'auto', implode(',', $abuse['flags']) ?: 'abuse_signal', 'Score ' . $abuse['score']);
}

// Keep the workspace customer list in sync (existing behaviour).
if (function_exists('upsertSiteCustomer')) {
    upsertSiteCustomer(
        $conn,
        $tenantId,
        $companyId,
        $customerName,
        $customerEmail,
        $customerPhone,
        $reviewId,
        $rating,
        '',
        $isVerified,
        $verificationType
    );
}

// Analytics event (best effort).
$userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$eventStmt = $conn->prepare("INSERT INTO analytics_events
    (tenant_id, company_id, event_type, event_category, visitor_ip, user_agent, device_type, created_at)
    VALUES (?, ?, 'rating_submitted', 'mobile_api', ?, ?, 'mobile', NOW())");
if ($eventStmt) {
    $eventStmt->bind_param('iiss', $tenantId, $companyId, $ip, $userAgent);
    @$eventStmt->execute();
    $eventStmt->close();
}

// Owner alert for a low score (push is sent only when configured).
if ($isEscalated && function_exists('push_notify_new_low_review')) {
    push_notify_new_low_review($conn, $tenantId, $companyId, $reviewId, $rating, $companyName);
}

$published = $moderationStatus === 'published';

api_send_success([
    'review_id'         => $reviewId,
    'company_id'        => $companyId,
    'company_name'      => $companyName,
    'rating'            => $rating,
    'categories'        => $categories,
    'is_verified'       => (bool) $isVerified,
    'verification_type' => $verificationType,
    'published'         => $published,
    'under_review'      => $abuse['queue'],
    'created_at'        => date('Y-m-d H:i:s'),
    'routing' => [
        // Offered to every reviewer — asking only happy customers is
        // review gating and violates Google's policies.
        'google_review_url' => $googleStoreUrl !== '' ? $googleStoreUrl : null,
        'google_prompt'     => $googleStoreUrl !== '',
        'private_followup'  => (bool) $isEscalated,
        'review_gating'     => false,
        'message'           => $googleStoreUrl !== ''
            ? 'Thank you for your feedback. You can also share your honest experience on Google.'
            : 'Thank you for your feedback. The business has been notified.',
    ],
], $published ? 'Review submitted successfully' : 'Review received and is being checked', 201);
