<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method');
}

$company_id = (int)($_POST['company_id'] ?? 0);

if ($company_id <= 0) {
    die('Invalid company ID provided.');
}

// ============================================================
// BATCH MODE: The "Specific Reviews" form submits ALL question
// items at once as arrays (rating[question_id] / comment[question_id]).
// Answering an item is optional - only the items the customer
// rated are stored, each as its own anonymous review row.
// ============================================================
if (isset($_POST['rating']) && is_array($_POST['rating'])) {
    $ratings_arr  = $_POST['rating'];
    $comments_arr = (isset($_POST['comment']) && is_array($_POST['comment'])) ? $_POST['comment'] : [];

    $inserted  = 0;
    $q_id      = 0;
    $q_rating  = 0;
    $q_comment = '';

    $stmt = $conn->prepare("INSERT INTO ratings (company_id, question_id, rating, customer_name, customer_email, comment) VALUES (?, ?, ?, 'Anonymous', '', ?)");

    foreach ($ratings_arr as $q_key => $q_rating_raw) {
        $q_id     = (int)$q_key;
        $q_rating = (int)$q_rating_raw;

        // Skip items the customer left unrated (no review is required)
        if ($q_id <= 0 || $q_rating < 1 || $q_rating > 5) {
            continue;
        }

        $q_comment = sanitize(isset($comments_arr[$q_key]) ? $comments_arr[$q_key] : '');

        $stmt->bind_param("iiis", $company_id, $q_id, $q_rating, $q_comment);
        if ($stmt->execute()) {
            $inserted++;
        }
    }

    if ($inserted > 0) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Review Responses Submitted Successfully</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    padding: 20px;
                }
                .success-card {
                    background: white;
                    border-radius: 20px;
                    padding: 60px 50px;
                    text-align: center;
                    max-width: 500px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                }
                .success-icon {
                    width: 80px;
                    height: 80px;
                    background: #a3e635;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0 auto 30px;
                    font-size: 40px;
                }
                h2 { font-size: 32px; color: #1e293b; margin-bottom: 15px; }
                p { color: #64748b; font-size: 16px; line-height: 1.6; margin-bottom: 30px; }
                .btn-group { display: flex; gap: 15px; justify-content: center; }
                .btn {
                    padding: 14px 28px;
                    border-radius: 8px;
                    text-decoration: none;
                    font-weight: 600;
                    font-size: 14px;
                    transition: transform 0.3s;
                }
                .btn:hover { transform: translateY(-2px); }
                .btn-primary { background: #a3e635; color: #1e293b; }
                .btn-secondary { background: #e2e8f0; color: #1e293b; }
            </style>
        </head>
        <body>
            <div class="success-card">
                <div class="success-icon">✓</div>
                <h2>Thank You!</h2>
                <p><?php echo (int)$inserted; ?> review response<?php echo $inserted > 1 ? 's' : ''; ?> submitted successfully. We appreciate you taking the time to share your feedback.</p>
                <div class="btn-group">
                    <a href="../rate/index.php?company=<?php echo $company_id; ?>#responses" class="btn btn-primary">← See your review responses</a>
                </div>
            </div>
        </body>
        </html>
        <?php
    } else {
        die('Please select a star rating for at least one review before submitting.');
    }
    exit;
}

// Single review submission
$rating         = (int)($_POST['rating'] ?? 0);
$customer_name  = is_string($_POST['customer_name'] ?? null) ? sanitize($_POST['customer_name']) : '';
$customer_email = is_string($_POST['customer_email'] ?? null) ? sanitize($_POST['customer_email']) : '';
$comment        = is_string($_POST['comment'] ?? null) ? sanitize($_POST['comment']) : '';
$question_id    = isset($_POST['question_id']) && !is_array($_POST['question_id']) && (int)$_POST['question_id'] > 0 ? (int)$_POST['question_id'] : null;
$is_question    = ($question_id !== null);

if ($rating < 1 || $rating > 5) {
    die('Please select a valid rating between 1 and 5 stars.');
}

// Name is required for general reviews. Question submissions collect no
// name or email - they are stored anonymously.
if (empty($customer_name)) {
    if ($is_question) {
        $customer_name = 'Anonymous';
    } else {
        die('Customer name is required.');
    }
}

if (!$is_question && empty($customer_email)) {
    die('Customer email address is required.');
}

// Question submissions collect no email - store empty string (column is NOT NULL)
if ($is_question) {
    $customer_email = '';
}

// Fetch company name for display
$c_stmt = $conn->prepare("SELECT company_name FROM customers WHERE id = ?");
$c_stmt->bind_param("i", $company_id);
$c_stmt->execute();
$c_res = $c_stmt->get_result()->fetch_assoc();
$company_name = $c_res ? $c_res['company_name'] : 'the company';

// Insert rating first to get the ID
$stmt = $conn->prepare("INSERT INTO ratings (company_id, question_id, rating, customer_name, customer_email, comment) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iiisss", $company_id, $question_id, $rating, $customer_name, $customer_email, $comment);

if ($stmt->execute()) {
    $rating_id = $conn->insert_id;
    
    // Handle photo uploads
    $photos = [];
    if (!empty($_FILES['photos']['name'][0])) {
        $file_count = count($_FILES['photos']['name']);
        for ($i = 0; $i < $file_count && $i < 5; $i++) {
            $file = [
                'name' => $_FILES['photos']['name'][$i],
                'type' => $_FILES['photos']['type'][$i],
                'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                'size' => $_FILES['photos']['size'][$i]
            ];
            $result = uploadReviewPhoto($file, $rating_id);
            if ($result['success']) {
                $photos[] = $result['path'];
            }
        }
        
        // Save photo paths to database
        if (!empty($photos)) {
            $photo_json = json_encode($photos);
            $conn->query("UPDATE ratings SET photos = '$photo_json' WHERE id = $rating_id");
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Review Submitted Successfully</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 20px;
            }
            .success-card {
                background: white;
                border-radius: 20px;
                padding: 60px 50px;
                text-align: center;
                max-width: 500px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .success-icon {
                width: 80px;
                height: 80px;
                background: #a3e635;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 30px;
                font-size: 40px;
            }
            h2 {
                font-size: 32px;
                color: #1e293b;
                margin-bottom: 15px;
            }
            p {
                color: #64748b;
                font-size: 16px;
                line-height: 1.6;
                margin-bottom: 30px;
            }
            .btn-group {
                display: flex;
                gap: 15px;
                justify-content: center;
            }
            .btn {
                padding: 14px 28px;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                transition: transform 0.3s;
            }
            .btn:hover {
                transform: translateY(-2px);
            }
            .btn-primary {
                background: #a3e635;
                color: #1e293b;
            }
            .btn-secondary {
                background: #e2e8f0;
                color: #1e293b;
            }
        </style>
    </head>
    <body>
        <div class="success-card">
            <div class="success-icon">✓</div>
            <h2>Thank You!</h2>
            <p>Your review has been submitted successfully. We appreciate you taking the time to share your feedback.</p>
            <div class="btn-group">
                <a href="../rate/index.php?company=<?php echo $company_id; ?>#feedbacks" class="btn btn-primary">← See your review</a>
                <a href="../companies.php" class="btn btn-secondary">Browse All Companies</a>
            </div>
        </div>
    </body>
    </html>
    <?php
} else {
    echo "Error: " . $conn->error;
}
?>
