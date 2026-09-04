<?php
/**
 * Report a review
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$rating_id = (int)($_POST['rating_id'] ?? 0);
$reason = sanitize($_POST['reason'] ?? '');

if ($rating_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid review ID']);
    exit;
}

if (empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a reason']);
    exit;
}

$result = reportReview($rating_id, $reason, $conn);
echo json_encode($result);
?>