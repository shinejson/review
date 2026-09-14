<?php
/**
 * ============================================================
 *  Customer Engagement Endpoint (public, post-signup)
 * ============================================================
 *  Receives "I have followed" / "I have liked" confirmations
 *  from the Get Verified Badge card on the review success
 *  page. Once BOTH steps are confirmed, the customer earns
 *  the Verified Customer badge (also applied to their review).
 *
 *  POST fields:
 *    action     = follow | like
 *    rating_id  = the review just submitted (identifies the customer)
 *    company_id = company the review belongs to
 *    platform   = (optional) social network that was followed
 *    phone      = (optional) customer phone / WhatsApp number
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action     = strtolower(trim((string)($_POST['action'] ?? '')));
$rating_id  = (int)($_POST['rating_id'] ?? 0);
$company_id = (int)($_POST['company_id'] ?? 0);
$platform   = trim((string)($_POST['platform'] ?? ''));
$phone      = trim((string)($_POST['phone'] ?? ''));

if (!in_array($action, ['follow', 'like'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

if ($platform !== '' && !preg_match('/^[a-z0-9_]{1,30}$/i', $platform)) {
    $platform = '';
}

$result = markCustomerEngagement($conn, $rating_id, $company_id, $action, $platform, $phone);

if ($result === false || $result === null) {
    echo json_encode([
        'success' => false,
        'message' => 'We could not match your signup. Please submit your review first, then complete the follow & like steps.'
    ]);
    exit;
}

echo json_encode([
    'success'           => true,
    'is_following'      => (bool)$result['is_following'],
    'is_liked'          => (bool)$result['is_liked'],
    'is_verified'       => (bool)$result['is_verified'],
    'verification_type' => $result['verification_type'],
]);
