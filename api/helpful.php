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