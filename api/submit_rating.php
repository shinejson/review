<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid request method');
}

$company_id = (int)$_POST['company_id'];
$rating = (int)$_POST['rating'];
$customer_name = sanitize($_POST['customer_name']);
$customer_email = sanitize($_POST['customer_email']);
$comment = sanitize($_POST['comment']);

if ($rating < 1 || $rating > 5) {
    die('Invalid rating value');
}

$stmt = $conn->prepare("INSERT INTO ratings (company_id, rating, customer_name, customer_email, comment) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("iisss", $company_id, $rating, $customer_name, $customer_email, $comment);

if ($stmt->execute()) {
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
                <a href="../companies.php" class="btn btn-secondary">Browse Companies</a>
                <a href="../rate/index.php?company=<?php echo $company_id; ?>" class="btn btn-primary">Submit Another Review</a>
            </div>
        </div>
    </body>
    </html>
    <?php
} else {
    echo "Error: " . $conn->error;
}
?>
