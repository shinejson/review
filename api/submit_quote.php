<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Collect form data
$company_name = sanitize($_POST['company_name'] ?? '');
$contact_person = sanitize($_POST['contact_person'] ?? '');
$category = !empty($_POST['category']) ? (int)$_POST['category'] : null;
$plan_id = !empty($_POST['plan_id']) ? (int)$_POST['plan_id'] : null;
$location = sanitize($_POST['location'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$phone = sanitize($_POST['phone'] ?? '');
$website = sanitize($_POST['website'] ?? '');
$num_companies = (int)($_POST['num_companies'] ?? 0);
$expected_ratings = (int)($_POST['expected_ratings'] ?? 0);
$notes = sanitize($_POST['notes'] ?? '');

// Validate required fields
if (empty($company_name) || empty($contact_person) || empty($email) || empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email address']);
    exit;
}

// Ensure tables and real ID schema exists
$conn->query("CREATE TABLE IF NOT EXISTS quote_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(32) NULL,
    converted_tenant_id INT NULL,
    setup_email_sent TINYINT(1) NOT NULL DEFAULT 0,
    setup_token VARCHAR(128) NULL,
    company_name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    website VARCHAR(255),
    category_id INT,
    plan_id INT,
    location VARCHAR(255),
    num_companies INT,
    expected_ratings INT,
    notes TEXT,
    status ENUM('pending', 'contacted', 'converted', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
    UNIQUE KEY uniq_quote_public_id (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

ensureRealIdSchema($conn);

// Generate real public ID (QTE-XXXXXXXX)
$public_id = generateRealPublicId($conn, 'quote_requests', 'public_id', 'QTE-', 8);

// Insert quote request with public_id
$stmt = $conn->prepare("INSERT INTO quote_requests (public_id, company_name, contact_person, email, phone, website, category_id, plan_id, location, num_companies, expected_ratings, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    // Fallback without prepared public_id if column issue
    $stmt = $conn->prepare("INSERT INTO quote_requests (company_name, contact_person, email, phone, website, category_id, plan_id, location, num_companies, expected_ratings, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param("sssssiisiis", $company_name, $contact_person, $email, $phone, $website, $category, $plan_id, $location, $num_companies, $expected_ratings, $notes);
} else {
    $stmt->bind_param("ssssssiisiis", $public_id, $company_name, $contact_person, $email, $phone, $website, $category, $plan_id, $location, $num_companies, $expected_ratings, $notes);
}

if ($stmt->execute()) {
    $insert_id = (int)$conn->insert_id;
    $stmt->close();

    // Fetch the inserted row for email
    $quoteRow = null;
    $qStmt = $conn->prepare("SELECT * FROM quote_requests WHERE id = ? LIMIT 1");
    if ($qStmt) {
        $qStmt->bind_param("i", $insert_id);
        $qStmt->execute();
        $res = $qStmt->get_result();
        $quoteRow = $res ? $res->fetch_assoc() : null;
        $qStmt->close();
    }
    if (!$quoteRow) {
        $quoteRow = [
            'id' => $insert_id,
            'public_id' => $public_id,
            'company_name' => $company_name,
            'contact_person' => $contact_person,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    // Send confirmation email with real ID (non-blocking, don't fail request if email fails)
    $emailResult = null;
    try {
        $emailResult = sendQuoteConfirmationEmail($conn, $quoteRow);
    } catch (Exception $e) {
        $emailResult = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Quote request submitted successfully',
        'public_id' => $quoteRow['public_id'] ?? $public_id,
        'reference_id' => $quoteRow['public_id'] ?? $public_id,
        'id' => $quoteRow['public_id'] ?? $public_id, // real id, not DB id
        'email_sent' => $emailResult ? (bool)$emailResult['success'] : false,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Error submitting quote request: ' . $conn->error
    ]);
}
?>
