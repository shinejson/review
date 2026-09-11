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

    // === AUTO-CREATE TENANT + SEND PASSWORD SETUP EMAIL (Real ID flow) ===
    // As per requirement: when quota is obtained and person registered, they should get Real ID + email to setup password
    $tenantPublicId = null;
    $tenantSetupEmailResult = null;
    $tenantId = null;
    $tenantUsername = null;

    try {
        // Check if tenant already exists with this email
        $existingTenant = null;
        $chkStmt = $conn->prepare("SELECT id, public_id FROM tenants WHERE email = ? LIMIT 1");
        if ($chkStmt) {
            $chkStmt->bind_param("s", $email);
            $chkStmt->execute();
            $chkRes = $chkStmt->get_result();
            $existingTenant = $chkRes ? $chkRes->fetch_assoc() : null;
            $chkStmt->close();
        }

        if (!$existingTenant) {
            // Generate unique username from company name
            $baseUsername = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($company_name)));
            $baseUsername = trim($baseUsername, '_');
            if (strlen($baseUsername) < 3) $baseUsername = 'tenant_' . substr(md5($email), 0, 6);
            $baseUsername = substr($baseUsername, 0, 30);
            $usernameCandidate = $baseUsername;
            $suffix = 0;
            while (true) {
                $escU = $conn->real_escape_string($usernameCandidate);
                $uRes = @$conn->query("SELECT COUNT(*) AS c FROM tenants WHERE username = '$escU' LIMIT 1");
                $uCount = $uRes ? (int)($uRes->fetch_assoc()['c'] ?? 0) : 0;
                if ($uRes) $uRes->close();
                if ($uCount === 0) break;
                $suffix++;
                $usernameCandidate = $baseUsername . '_' . $suffix;
                if ($suffix > 50) { $usernameCandidate = $baseUsername . '_' . substr(md5(uniqid()), 0, 5); break; }
            }
            $tenantUsername = $usernameCandidate;

            // Get plan price if plan_id provided
            $planPrice = 0.0;
            if (!empty($plan_id)) {
                $pRes = @$conn->query("SELECT price FROM subscription_plans WHERE id = " . (int)$plan_id . " LIMIT 1");
                if ($pRes && $pRow = $pRes->fetch_assoc()) { $planPrice = (float)$pRow['price']; $pRes->close(); }
            }

            $tenantPublicId = generateRealPublicId($conn, 'tenants', 'public_id', 'OPT-', 8);
            $setupToken = generateSecureToken(32);
            $setupExpires = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $placeholderHash = password_hash(generateSecureToken(16), PASSWORD_DEFAULT);
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d', strtotime('+1 month'));

            $tStmt = $conn->prepare(
                "INSERT INTO tenants (public_id, setup_token, setup_token_expires, company_name, email, phone, username, password, plan_id, subscription_status, subscription_price, subscription_start_date, subscription_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'trial', ?, ?, ?)"
            );
            if ($tStmt) {
                $tStmt->bind_param("sssssssisdss", $tenantPublicId, $setupToken, $setupExpires, $company_name, $email, $phone, $tenantUsername, $placeholderHash, $plan_id, $planPrice, $startDate, $endDate);
                $tStmt->execute();
                $tenantId = (int)$conn->insert_id;
                $tStmt->close();
            } else {
                // Fallback if columns missing (ensureRealIdSchema should have added them)
                $tStmt2 = $conn->prepare("INSERT INTO tenants (company_name, email, phone, username, password, plan_id, subscription_status, subscription_price, subscription_start_date, subscription_end_date) VALUES (?, ?, ?, ?, ?, ?, 'trial', ?, ?, ?)");
                if ($tStmt2) {
                    $tStmt2->bind_param("ssssisdss", $company_name, $email, $phone, $tenantUsername, $placeholderHash, $plan_id, $planPrice, $startDate, $endDate);
                    $tStmt2->execute();
                    $tenantId = (int)$conn->insert_id;
                    $tStmt2->close();
                    // Try backfill Real ID
                    if ($tenantId) {
                        @$conn->query("UPDATE tenants SET public_id = '" . $conn->real_escape_string($tenantPublicId) . "', setup_token = '" . $conn->real_escape_string($setupToken) . "', setup_token_expires = '" . $conn->real_escape_string($setupExpires) . "' WHERE id = " . $tenantId);
                    }
                }
            }

            if ($tenantId) {
                // Create primary customer entry
                $cStmt = $conn->prepare("INSERT INTO customers (tenant_id, company_name, email, phone, created_at) VALUES (?, ?, ?, ?, NOW())");
                if ($cStmt) {
                    $cStmt->bind_param("isss", $tenantId, $company_name, $email, $phone);
                    @$cStmt->execute();
                    $cStmt->close();
                }

                // Link quote to tenant
                $linkStmt = $conn->prepare("UPDATE quote_requests SET converted_tenant_id = ?, setup_email_sent = 1, status = 'converted' WHERE id = ?");
                if ($linkStmt) {
                    $linkStmt->bind_param("ii", $tenantId, $insert_id);
                    $linkStmt->execute();
                    $linkStmt->close();
                }

                // Send password setup email with Real ID
                $tenantRow = [
                    'id' => $tenantId,
                    'public_id' => $tenantPublicId,
                    'company_name' => $company_name,
                    'email' => $email,
                    'username' => $tenantUsername,
                ];
                try {
                    $tenantSetupEmailResult = sendTenantSetupEmail($conn, $tenantRow, $setupToken, true);
                } catch (Exception $e) {
                    $tenantSetupEmailResult = ['success' => false, 'message' => $e->getMessage()];
                }
            }
        } else {
            // Tenant already exists — reuse its Real ID
            $tenantId = (int)$existingTenant['id'];
            $tenantPublicId = $existingTenant['public_id'] ?? null;
            if (empty($tenantPublicId)) {
                $tenantPublicId = generateRealPublicId($conn, 'tenants', 'public_id', 'OPT-', 8);
                @$conn->query("UPDATE tenants SET public_id = '" . $conn->real_escape_string($tenantPublicId) . "' WHERE id = " . $tenantId);
            }
            // Link quote but don't overwrite status if already converted
            $linkStmt = $conn->prepare("UPDATE quote_requests SET converted_tenant_id = ? WHERE id = ?");
            if ($linkStmt) { $linkStmt->bind_param("ii", $tenantId, $insert_id); $linkStmt->execute(); $linkStmt->close(); }
        }
    } catch (Exception $e) {
        // Don't fail the quote if tenant auto-create fails — log silently
        $tenantSetupEmailResult = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Quote request submitted successfully',
        'public_id' => $quoteRow['public_id'] ?? $public_id,
        'reference_id' => $quoteRow['public_id'] ?? $public_id,
        'id' => $quoteRow['public_id'] ?? $public_id, // real id, not DB id
        'email_sent' => $emailResult ? (bool)$emailResult['success'] : false,
        'tenant_public_id' => $tenantPublicId,
        'tenant_id_real' => $tenantPublicId, // alias for frontend
        'tenant_username' => $tenantUsername,
        'tenant_setup_email_sent' => $tenantSetupEmailResult ? (bool)$tenantSetupEmailResult['success'] : false,
        'tenant_auto_created' => $tenantId ? true : false,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Error submitting quote request: ' . $conn->error
    ]);
}
?>
