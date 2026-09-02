<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$company_id = isset($_GET['company']) ? (int)$_GET['company'] : 0;

if ($company_id === 0) {
    die('Invalid company ID');
}

$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();

if (!$company) {
    die('Company not found');
}

// Get average rating and rating distribution
$avg_rating = getAverageRating($company_id, $conn);
$total_ratings = getRatingCount($company_id, $conn);

// Get rating distribution
$rating_dist = [];
for ($i = 5; $i >= 1; $i--) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ratings WHERE company_id = ? AND rating = ?");
    $stmt->bind_param("ii", $company_id, $i);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $count = $result['count'];
    $percentage = $total_ratings > 0 ? round(($count / $total_ratings) * 100) : 0;
    $rating_dist[$i] = ['count' => $count, 'percentage' => $percentage];
}

// Get recent reviews
$reviews = $conn->query("SELECT * FROM ratings WHERE company_id = $company_id ORDER BY created_at DESC LIMIT 10");

$pageTitle = 'Rate ' . $company['company_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            padding: 30px 20px;
        }
        .rt-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 50px;
        }
        .rt-rating-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
            margin-bottom: 50px;
        }
        .rt-average-section h2 {
            font-size: 24px;
            color: #333;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .rt-avg-score {
            font-size: 48px;
            color: #333;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .rt-avg-stars {
            font-size: 24px;
            color: #ffc107;
            margin-bottom: 30px;
        }
        .rt-rating-bar-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            gap: 15px;
        }
        .rt-rating-label {
            font-weight: 600;
            color: #333;
            width: 15px;
        }
        .rt-bar-container {
            flex: 1;
            height: 8px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
        }
        .rt-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        .rt-rating-percentage {
            font-weight: 600;
            color: #666;
            min-width: 40px;
            text-align: right;
        }
        .rt-review-section h2 {
            font-size: 24px;
            color: #333;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .rt-review-form-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 12px;
            display: block;
        }
        .rt-star-rating-input {
            display: flex;
            gap: 8px;
            margin-bottom: 25px;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }
        .rt-star-rating-input input[type="radio"] { display: none; }
        .rt-star-rating-input label {
            font-size: 28px;
            color: #e0e0e0;
            cursor: pointer;
            transition: color 0.2s;
        }
        .rt-star-rating-input input:checked ~ label,
        .rt-star-rating-input label:hover,
        .rt-star-rating-input label:hover ~ label {
            color: #ffc107;
        }
        .rt-input-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .rt-form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .rt-form-input:focus {
            outline: none;
            border-color: #10b981;
        }
        .rt-form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
            margin-bottom: 25px;
        }
        .rt-form-textarea:focus {
            outline: none;
            border-color: #10b981;
        }
        .rt-submit-btn {
            background: #10b981;
            color: white;
            padding: 14px 32px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .rt-submit-btn:hover {
            background: #059669;
        }
        .rt-feedback-section {
            margin-top: 50px;
            padding-top: 40px;
            border-top: 1px solid #e0e0e0;
        }
        .rt-feedback-section h2 {
            font-size: 28px;
            color: #333;
            margin-bottom: 30px;
            font-weight: 600;
        }
        .rt-feedback-item {
            background: #f9fafb;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .rt-feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .rt-feedback-author {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }
        .rt-feedback-date {
            color: #999;
            font-size: 14px;
        }
        .rt-feedback-stars {
            color: #ffc107;
            font-size: 18px;
            margin-bottom: 12px;
        }
        .rt-feedback-text {
            color: #666;
            line-height: 1.6;
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="rt-container">
    <div class="rt-rating-grid">
        <!-- Average Rating Section -->
        <div class="rt-average-section">
            <h2>Average Rating</h2>
            <div class="rt-avg-score"><?php echo number_format($avg_rating, 1); ?></div>
            <div class="rt-avg-stars">
                <?php
                $full_stars = floor($avg_rating);
                $half_star = ($avg_rating - $full_stars) >= 0.5;
                for ($i = 0; $i < $full_stars; $i++) echo '★';
                if ($half_star) echo '★';
                for ($i = 0; $i < (5 - $full_stars - ($half_star ? 1 : 0)); $i++) echo '☆';
                ?>
            </div>
            
            <div class="rt-rating-bars">
                <?php foreach ($rating_dist as $star => $data): ?>
                <div class="rt-rating-bar-item">
                    <span class="rt-rating-label"><?php echo $star; ?></span>
                    <div class="rt-bar-container">
                        <div class="rt-bar-fill" style="width: <?php echo $data['percentage']; ?>%"></div>
                    </div>
                    <span class="rt-rating-percentage"><?php echo $data['percentage']; ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Submit Review Section -->
        <div class="rt-review-section">
            <h2>Submit Your Review</h2>
            <form id="ratingForm" action="../api/submit_rating.php" method="POST" onsubmit="return validateRatingForm()">
                <input type="hidden" name="company_id" value="<?php echo $company_id; ?>">
                
                <label class="rt-review-form-label">Add Your Rating *</label>
                <div class="rt-star-rating-input">
                    <input type="radio" name="rating" value="5" id="star5">
                    <label for="star5">★</label>
                    <input type="radio" name="rating" value="4" id="star4">
                    <label for="star4">★</label>
                    <input type="radio" name="rating" value="3" id="star3">
                    <label for="star3">★</label>
                    <input type="radio" name="rating" value="2" id="star2">
                    <label for="star2">★</label>
                    <input type="radio" name="rating" value="1" id="star1">
                    <label for="star1">★</label>
                </div>
                
                <div class="rt-input-row">
                    <input type="text" name="customer_name" class="rt-form-input" placeholder="John Doe" required>
                    <input type="email" name="customer_email" class="rt-form-input" placeholder="mail@example.com" required>
                </div>
                
                <label class="rt-review-form-label">Write Your Review *</label>
                <textarea name="comment" class="rt-form-textarea" placeholder="Write here..." required></textarea>
                
                <button type="submit" class="rt-submit-btn">Submit Reviews</button>
            </form>
        </div>
    </div>
    
    <!-- Customer Feedbacks Section -->
    <div class="rt-feedback-section">
        <h2>Customer Feedbacks</h2>
        
        <?php if ($reviews->num_rows > 0): ?>
            <?php while ($review = $reviews->fetch_assoc()): ?>
            <div class="rt-feedback-item">
                <div class="rt-feedback-header">
                    <span class="rt-feedback-author"><?php echo htmlspecialchars($review['customer_name']); ?></span>
                    <span class="rt-feedback-date">
                        <?php
                        $date_diff = time() - strtotime($review['created_at']);
                        $days_ago = floor($date_diff / (60 * 60 * 24));
                        if ($days_ago == 0) echo 'Today';
                        elseif ($days_ago == 1) echo '1 day ago';
                        elseif ($days_ago < 30) echo $days_ago . ' days ago';
                        elseif ($days_ago < 60) echo '1 month ago';
                        else echo floor($days_ago / 30) . ' months ago';
                        ?>
                    </span>
                </div>
                <div class="rt-feedback-stars">
                    <?php
                    for ($i = 0; $i < $review['rating']; $i++) echo '★';
                    for ($i = $review['rating']; $i < 5; $i++) echo '☆';
                    ?>
                </div>
                <div class="rt-feedback-text">
                    <?php echo htmlspecialchars($review['comment']); ?>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="color: #999;">No reviews yet. Be the first to review!</p>
        <?php endif; ?>
    </div>
</div>

<script src="../assets/js/main.js"></script>
</body>
</html>