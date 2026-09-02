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

// Create a quote request table if doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS quote_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
)");

// Insert quote request
$stmt = $conn->prepare("INSERT INTO quote_requests (company_name, contact_person, email, phone, website, category_id, plan_id, location, num_companies, expected_ratings, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssiisiis", $company_name, $contact_person, $email, $phone, $website, $category, $plan_id, $location, $num_companies, $expected_ratings, $notes);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Quote request submitted successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Error submitting quote request: ' . $conn->error
    ]);
}
?>
