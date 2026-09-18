<?php
/**
 * Mark a review as helpful
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Slow down vote manipulation: cap helpful votes per IP.
if (function_exists('public_rate_limit')) {
    $retry_after = public_rate_limit('helpful_vote', 30, 3600);
    if ($retry_after > 0) {
        public_rate_limit_respond($retry_after);
    }
}

$rating_id = (int)($_POST['rating_id'] ?? 0);

if ($rating_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid review ID']);
    exit;
}

$result = markHelpful($rating_id, $conn);

if ($result['success']) {
    // Get updated count
    $count = $conn->query("SELECT helpful_count FROM ratings WHERE id = $rating_id")->fetch_assoc()['helpful_count'];
    $result['count'] = $count;
}

echo json_encode($result);
?>