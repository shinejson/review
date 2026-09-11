<?php
/**
 * API: Submit a reply to a rating or review
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$rating_id  = (int)($_POST['rating_id'] ?? 0);
$company_id = (int)($_POST['company_id'] ?? 0);
$user_name  = trim(sanitize($_POST['user_name'] ?? ''));
$user_email = trim(sanitize($_POST['user_email'] ?? ''));
$reply_text = trim(sanitize($_POST['reply_text'] ?? ''));

if ($rating_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid review ID.']);
    exit;
}

if ($reply_text === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter your reply text.']);
    exit;
}

if ($user_name === '') {
    $user_name = 'Guest';
}

if (!empty($user_email) && !filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// Verify that the rating exists in DB
$check = $conn->prepare("SELECT id, company_id FROM ratings WHERE id = ? LIMIT 1");
$check->bind_param("i", $rating_id);
$check->execute();
$r_res = $check->get_result()->fetch_assoc();
$check->close();

if (!$r_res) {
    echo json_encode(['success' => false, 'message' => 'Review not found.']);
    exit;
}
$company_id = (int)$r_res['company_id'];

// Check if official response from logged in tenant or admin
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
$is_official = 0;
if (!empty($_SESSION['tenant_id']) || !empty($_SESSION['admin_id']) || !empty($_SESSION['super_admin_id'])) {
    $is_official = 1;
}

// Ensure table exists
ensureRatingRepliesTable($conn);

$stmt = $conn->prepare("INSERT INTO rating_replies (rating_id, company_id, user_name, user_email, reply_text, is_official, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("iisssi", $rating_id, $company_id, $user_name, $user_email, $reply_text, $is_official);

if ($stmt->execute()) {
    $reply_id = $stmt->insert_id;
    $stmt->close();

    // Total replies count for this rating
    $cnt_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM rating_replies WHERE rating_id = ?");
    $cnt_stmt->bind_param("i", $rating_id);
    $cnt_stmt->execute();
    $total_replies = (int)$cnt_stmt->get_result()->fetch_assoc()['cnt'];
    $cnt_stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Reply posted successfully!',
        'reply' => [
            'id' => $reply_id,
            'rating_id' => $rating_id,
            'user_name' => htmlspecialchars($user_name),
            'reply_text' => nl2br(htmlspecialchars($reply_text)),
            'is_official' => $is_official,
            'time_ago' => 'Just now',
            'created_at' => date('M j, Y, g:i A'),
            'avatar_letter' => strtoupper(substr($user_name, 0, 1))
        ],
        'total_replies' => $total_replies
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not save your reply. Please try again.']);
}
