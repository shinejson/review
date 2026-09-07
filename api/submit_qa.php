<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = sanitize($_POST['action'] ?? 'ask_question');

// 1. Upvote a question
if ($action === 'vote_helpful') {
    $question_id = (int)($_POST['question_id'] ?? 0);
    if ($question_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid question ID.']);
        exit;
    }
    $res = voteQuestionHelpful($conn, $question_id);
    echo json_encode($res);
    exit;
}

// 2. Submit a new customer question
$company_id = (int)($_POST['company_id'] ?? 0);
if ($company_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid company ID.']);
    exit;
}

// Get tenant ID from company
$t_stmt = $conn->prepare("SELECT tenant_id, company_name FROM customers WHERE id = ?");
$t_stmt->bind_param("i", $company_id);
$t_stmt->execute();
$c_row = $t_stmt->get_result()->fetch_assoc();
$t_stmt->close();

if (!$c_row) {
    echo json_encode(['success' => false, 'message' => 'Company not found.']);
    exit;
}

$tenant_id     = (int)$c_row['tenant_id'];
$cust_name     = sanitize($_POST['customer_name'] ?? '');
$cust_email    = sanitize($_POST['customer_email'] ?? '');
$cust_phone    = sanitize($_POST['customer_phone'] ?? '');
$question_text = sanitize($_POST['question_text'] ?? '');

if (trim($question_text) === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter your question.']);
    exit;
}

if (!empty($cust_email) && !filter_var($cust_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
    exit;
}

$qId = submitCommunityQuestion($conn, $company_id, $tenant_id, $cust_name, $cust_email, $question_text, $cust_phone);

if ($qId) {
    echo json_encode([
        'success' => true,
        'message' => 'Your question has been posted! Management will post an official answer shortly.',
        'question_id' => $qId
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Could not save question. Please try again.']);
}
