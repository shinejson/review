<?php
/**
 * GET|POST /api/v1/auth/customer/me.php
 *
 * Returns the signed-in account and a small review summary. The app uses
 * this on launch to confirm the cached Room user still exists on the
 * server and is not suspended.
 */
require_once dirname(__DIR__, 2) . '/helpers/app_bootstrap.php';

$auth = api_require_app_user($conn);
$user = $auth['user'];

$reviewCount = 0;
$averageGiven = null;
$email = optibiz_app_email($user['email'] ?? '');
if ($email !== '') {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating FROM ratings WHERE LOWER(TRIM(customer_email)) = ?");
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $reviewCount = (int) ($row['cnt'] ?? 0);
        $averageGiven = $row['avg_rating'] !== null ? round((float) $row['avg_rating'], 1) : null;
    }
}

api_send_success([
    'user' => optibiz_app_public_user($user),
    'stats' => [
        'reviews_written' => $reviewCount,
        'average_stars_given' => $averageGiven,
    ],
], 'Account retrieved.', 200);
