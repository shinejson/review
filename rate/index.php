<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// Support ?tenant=ID routing — resolves to that tenant's first registered company.
// Also supports legacy ?company=ID routing.
if (isset($_GET['tenant']) && (int)$_GET['tenant'] > 0) {
    $tenant_lookup_id = (int)$_GET['tenant'];
    $t_resolve = $conn->prepare("SELECT id FROM customers WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
    $t_resolve->bind_param("i", $tenant_lookup_id);
    $t_resolve->execute();
    $t_row = $t_resolve->get_result()->fetch_assoc();
    $t_resolve->close();

    if ($t_row) {
        // Redirect to company-specific URL for cleaner routing
        $redirect_url = '?company=' . (int)$t_row['id'];
        if (!empty($_GET['tab'])) $redirect_url .= '&tab=' . urlencode($_GET['tab']);
        header('Location: ' . $redirect_url, true, 302);
        exit;
    }

    // Tenant has no companies yet — show a friendly holding page
    $t_info_stmt = $conn->prepare("SELECT company_name FROM tenants WHERE id = ? LIMIT 1");
    $t_info_stmt->bind_param("i", $tenant_lookup_id);
    $t_info_stmt->execute();
    $t_info = $t_info_stmt->get_result()->fetch_assoc();
    $t_info_stmt->close();
    $tenant_name = htmlspecialchars($t_info['company_name'] ?? 'This workspace');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $tenant_name; ?> — Rating Portal</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;min-height:100vh;display:grid;place-items:center;background:#0a1926;color:#e2e8f0;}
  .card{background:#0f2438;border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:48px;text-align:center;max-width:480px;width:90%;}
  .icon{font-size:48px;margin-bottom:20px;}
  h1{font-size:22px;font-weight:800;color:#f1f5f9;margin-bottom:10px;}
  p{font-size:14px;color:#94a3b8;line-height:1.6;}
  .badge{display:inline-block;margin-top:18px;padding:6px 16px;border-radius:99px;background:rgba(194,245,66,.15);color:#c2f542;font-size:12px;font-weight:700;}
</style>
</head>
<body>
<div class="card">
  <div class="icon">⭐</div>
  <h1><?php echo $tenant_name; ?></h1>
  <p>This workspace's public rating portal is not available yet.<br>The tenant needs to register their company profile first.</p>
  <span class="badge">Coming soon</span>
</div>
</body>
</html><?php
    exit;
}

$company_id = isset($_GET['company']) ? (int)$_GET['company'] : 0;

if ($company_id <= 0) {
    die('Please provide a valid company or tenant ID.');
}

$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();

if (!$company) {
    die('Company not found.');
}

// Get average rating and total counts
$avg_rating    = getAverageRating($company_id, $conn);
$total_ratings = getRatingCount($company_id, $conn);

// Get rating distribution (5 to 1 stars)
$rating_dist = [];
for ($i = 5; $i >= 1; $i--) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ratings WHERE company_id = ? AND rating = ?");
    $stmt->bind_param("ii", $company_id, $i);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $count = (int)$result['count'];
    $percentage = $total_ratings > 0 ? round(($count / $total_ratings) * 100) : 0;
    $rating_dist[$i] = ['count' => $count, 'percentage' => $percentage];
}

// Fetch ALL active rating questions created by admin/tenant
$tenant_id = (int)($company['tenant_id'] ?? 0);
$questions = [];
if ($tenant_id > 0) {
    $q_stmt = $conn->prepare("SELECT * FROM rating_questions WHERE tenant_id = ? AND is_active = 1 ORDER BY id ASC");
    $q_stmt->bind_param("i", $tenant_id);
    $q_stmt->execute();
    $q_res = $q_stmt->get_result();
    while ($qr = $q_res->fetch_assoc()) {
        $qid = (int)$qr['id'];

        // Stats for this specific question
        $stat_stmt = $conn->prepare("SELECT COUNT(*) as total_answers, AVG(rating) as avg_score FROM ratings WHERE company_id = ? AND question_id = ?");
        $stat_stmt->bind_param("ii", $company_id, $qid);
        $stat_stmt->execute();
        $stat = $stat_stmt->get_result()->fetch_assoc();
        $qr['total_answers'] = (int)($stat['total_answers'] ?? 0);
        $qr['avg_score']     = $qr['total_answers'] > 0 ? round((float)$stat['avg_score'], 1) : 0.0;

        // Recent customer answers for this question
        $ans_stmt = $conn->prepare("SELECT * FROM ratings WHERE company_id = ? AND question_id = ? ORDER BY created_at DESC LIMIT 5");
        $ans_stmt->bind_param("ii", $company_id, $qid);
        $ans_stmt->execute();
        $qr['answers'] = $ans_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $questions[] = $qr;
    }
}

// Fetch GENERAL customer reviews (no question) - shown in the "Reviews" tab
$gr_stmt = $conn->prepare("SELECT * FROM ratings WHERE company_id = ? AND (question_id IS NULL OR question_id = 0) ORDER BY created_at DESC");
$gr_stmt->bind_param("i", $company_id);
$gr_stmt->execute();
$general_reviews = $gr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch RESPONSES to the specific review items - shown in the "Review Responses" tab
$qr_stmt = $conn->prepare("SELECT r.*, rq.question_text FROM ratings r LEFT JOIN rating_questions rq ON r.question_id = rq.id WHERE r.company_id = ? AND r.question_id IS NOT NULL AND r.question_id > 0 ORDER BY r.created_at DESC");
$qr_stmt->bind_param("i", $company_id);
$qr_stmt->execute();
$question_responses = $qr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch tenant (workspace owner) branding - logo & name shown to customers
$tenant_info = null;
if ($tenant_id > 0) {
    $t_stmt = $conn->prepare("SELECT company_name, logo FROM tenants WHERE id = ? LIMIT 1");
    $t_stmt->bind_param("i", $tenant_id);
    $t_stmt->execute();
    $tenant_info = $t_stmt->get_result()->fetch_assoc();
}
$brand_name   = !empty($tenant_info['company_name']) ? $tenant_info['company_name'] : $company['company_name'];
$brand_logo   = $tenant_info['logo'] ?? '';
$brand_initials = strtoupper(substr($brand_name, 0, 2));

$pageTitle = 'Rate ' . htmlspecialchars($brand_name);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Verified Ratings &amp; Reviews</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            padding: 30px 16px 60px;
        }
        .rt-container {
            max-width: 1160px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            padding: 44px;
            box-shadow: 0 10px 40px rgba(15,23,42,0.06);
            border: 1px solid #e2e8f0;
        }
        .rt-company-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 24px;
            margin-bottom: 36px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .rt-company-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .rt-brand-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .rt-brand-logo {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            object-fit: cover;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
            background: #ffffff;
        }
        .rt-brand-fallback {
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            font-weight: 800;
            font-size: 18px;
            letter-spacing: 0.5px;
            border: none;
        }
        .rt-badge {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 99px;
            background: #dcfce7;
            color: #15803d;
            font-weight: 700;
        }
        .rt-rating-grid {
            display: grid;
            grid-template-columns: 1fr 1.25fr;
            gap: 44px;
            margin-bottom: 48px;
        }
        .rt-average-section h2, .rt-review-section h2 {
            font-size: 22px;
            color: #0f172a;
            margin-bottom: 8px;
            font-weight: 800;
        }
        .rt-subtext {
            font-size: 13.5px;
            color: #64748b;
            margin-bottom: 20px;
        }
        .rt-avg-score-box {
            display: flex;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 8px;
        }
        .rt-avg-score {
            font-size: 54px;
            color: #0f172a;
            font-weight: 800;
            line-height: 1;
        }
        .rt-avg-stars {
            font-size: 24px;
            color: #f59e0b;
        }
        .rt-total-counter {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 28px;
            font-weight: 600;
        }
        .rt-rating-bar-item {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            gap: 12px;
        }
        .rt-rating-label {
            font-weight: 700;
            color: #334155;
            width: 32px;
            font-size: 13px;
        }
        .rt-bar-container {
            flex: 1;
            height: 10px;
            background: #f1f5f9;
            border-radius: 10px;
            overflow: hidden;
        }
        .rt-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            border-radius: 10px;
            transition: width 0.4s ease;
        }
        .rt-rating-percentage {
            font-weight: 700;
            color: #64748b;
            min-width: 44px;
            text-align: right;
            font-size: 13px;
        }
        .rt-review-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 30px;
        }
        .rt-review-form-label {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
            display: block;
        }
        .rt-star-rating-input {
            display: flex;
            gap: 6px;
            margin-bottom: 20px;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }
                .rt-star-rating-input input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .rt-star-rating-input label {
            font-size: 30px;
            color: #cbd5e1;
            cursor: pointer;
            transition: color 0.15s ease, transform 0.15s ease;
        }
        .rt-star-rating-input label:hover {
            transform: scale(1.15);
        }
        .rt-star-rating-input input:checked ~ label,
        .rt-star-rating-input label:hover,
        .rt-star-rating-input label:hover ~ label {
            color: #f59e0b;
        }
        .rt-input-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        .rt-form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: #ffffff;
            color: #0f172a;
            transition: border-color 0.2s;
        }
        .rt-form-input:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,0.12);
        }
        .rt-form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 95px;
            margin-bottom: 20px;
            background: #ffffff;
            color: #0f172a;
            transition: border-color 0.2s;
        }
        .rt-form-textarea:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,0.12);
        }
        .rt-submit-btn {
            background: #10b981;
            color: white;
            padding: 13px 28px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }
        .rt-submit-btn:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(16,185,129,0.25);
        }
        /* Divided Section Navigation Tabs */
        .rt-divider-section {
            margin-top: 30px;
            padding-top: 36px;
            border-top: 1px solid #e2e8f0;
        }
        .rt-tab-nav {
            display: flex;
            gap: 12px;
            margin-bottom: 28px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 12px;
            flex-wrap: wrap;
        }
        .rt-tab-btn {
            background: transparent;
            border: none;
            padding: 10px 22px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .rt-tab-btn:hover {
            color: #0f172a;
            background: #f1f5f9;
        }
        .rt-tab-btn.is-active {
            color: #065f46;
            background: #d1fae5;
        }
        .rt-tab-panel {
            display: none;
            animation: rtFadeIn .25s ease-out;
        }
        .rt-tab-panel.is-active {
            display: block;
        }
        @keyframes rtFadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* Question Cards Styling */
        .rt-question-list {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .rt-question-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 14px 16px;
            box-shadow: 0 2px 10px rgba(15,23,42,0.03);
            transition: border-color 0.2s;
        }
        .rt-question-card:hover {
            border-color: #10b981;
        }
        .rt-question-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .rt-question-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.35;
        }
        .rt-question-stats {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .rt-chip {
            font-size: 12px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 6px;
            background: #fef3c7;
            color: #b45309;
        }
        .rt-chip-gray {
            font-size: 12px;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #475569;
        }
        /* Inline Question Answer Form */
        .rt-question-form {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 12px 14px;
            margin-top: 10px;
            margin-bottom: 10px;
        }
        /* Google-style review list (Reviews tab) */
        .rt-greview-list { display: flex; flex-direction: column; }
        .rt-greview { padding: 18px 4px; border-bottom: 1px solid #f1f5f9; }
        .rt-greview:last-child { border-bottom: none; }
        .rt-greview-top { display: flex; align-items: flex-start; gap: 12px; }
        .rt-greview-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff; display: grid; place-items: center;
            font-weight: 800; font-size: 16px; flex-shrink: 0;
        }
        .rt-greview-name { font-weight: 700; font-size: 14.5px; color: #0f172a; }
        .rt-greview-stars { font-size: 14px; color: #f59e0b; letter-spacing: 1.5px; margin-top: 2px; }
        .rt-greview-time { color: #64748b; font-size: 12.5px; }
        .rt-greview-text { color: #334155; font-size: 14px; line-height: 1.6; margin: 6px 0 0; }
        .rt-greview-norv { color: #94a3b8; font-style: italic; }
        .rt-greview-q { color: #64748b; font-size: 12px; margin-top: 8px; }
        .rt-greview .rt-reply-box { margin-top: 12px; }
        .rt-q-star-picker {
            display: flex;
            gap: 6px;
            flex-direction: row-reverse;
            justify-content: flex-start;
            margin-bottom: 12px;
        }
                .rt-q-star-picker input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .rt-q-star-picker label {
            font-size: 24px;
            color: #cbd5e1;
            cursor: pointer;
            transition: color 0.15s;
        }
        .rt-q-star-picker input:checked ~ label,
        .rt-q-star-picker label:hover,
        .rt-q-star-picker label:hover ~ label {
            color: #f59e0b;
        }
        .rt-q-answers-list {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }
        .rt-q-answer-item {
            background: #ffffff;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 10px;
            font-size: 13.5px;
        }
        /* Customer Feedback Cards */
        .rt-feedback-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .rt-feedback-item {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            padding: 22px 24px;
            border-radius: 14px;
            transition: box-shadow 0.2s;
        }
        .rt-feedback-item:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
        }
        .rt-feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .rt-feedback-author {
            font-weight: 700;
            color: #0f172a;
            font-size: 15px;
        }
        .rt-feedback-date {
            color: #94a3b8;
            font-size: 13px;
        }
        .rt-feedback-stars {
            color: #f59e0b;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .rt-feedback-text {
            color: #334155;
            line-height: 1.6;
            font-size: 14px;
        }
        .rt-reply-box {
            margin-top: 14px;
            padding: 12px 16px;
            border-radius: 8px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            font-size: 13px;
            color: #065f46;
        }
        .rt-empty {
            text-align: center;
            padding: 44px 20px;
            color: #94a3b8;
            font-size: 14px;
        }
        @media (max-width: 850px) {
            .rt-container { padding: 24px 18px; }
            .rt-rating-grid { grid-template-columns: 1fr; gap: 32px; }
            .rt-input-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="rt-container">

    <!-- Top Branding Header -->
    <header class="rt-company-header">
        <div class="rt-brand-wrap">
            <?php if (!empty($brand_logo)): ?>
                <img src="../<?php echo htmlspecialchars($brand_logo); ?>" alt="<?php echo htmlspecialchars($brand_name); ?> logo" class="rt-brand-logo">
            <?php else: ?>
                <div class="rt-brand-logo rt-brand-fallback"><?php echo htmlspecialchars($brand_initials); ?></div>
            <?php endif; ?>
            <div>
                <h1>
                    ★ Rate <?php echo htmlspecialchars($brand_name); ?>
                </h1>
                <p class="rt-subtext" style="margin-bottom:0;">
                    Official client review and rating portal &middot; <?php echo htmlspecialchars($company['category_name'] ?? 'Verified Business'); ?>
                </p>
            </div>
        </div>
        <div>
            <span class="rt-badge">✓ Verified Rating Channel</span>
        </div>
    </header>

    <!-- Top Section: Average Rating & Required General Rating Submission Form -->
    <div class="rt-rating-grid">
        
        <!-- Left: Average Rating & Distribution Bars -->
        <div class="rt-average-section">
            <h2>Average Rating</h2>
            <p class="rt-subtext">Overall customer satisfaction score</p>

            <div class="rt-avg-score-box">
                <span class="rt-avg-score"><?php echo number_format($avg_rating, 1); ?></span>
                <span class="rt-avg-stars">
                    <?php
                    $full_stars = floor($avg_rating);
                    $half_star  = ($avg_rating - $full_stars) >= 0.5;
                    for ($i = 0; $i < $full_stars; $i++) echo '★';
                    if ($half_star) echo '★';
                    for ($i = 0; $i < (5 - $full_stars - ($half_star ? 1 : 0)); $i++) echo '☆';
                    ?>
                </span>
            </div>
            <div class="rt-total-counter">
                Based on <strong><?php echo number_format($total_ratings); ?></strong> verified customer review(s)
            </div>
            
            <div class="rt-rating-bars">
                <?php foreach ($rating_dist as $star => $data): ?>
                <div class="rt-rating-bar-item">
                    <span class="rt-rating-label"><?php echo $star; ?> ★</span>
                    <div class="rt-bar-container">
                        <div class="rt-bar-fill" style="width: <?php echo $data['percentage']; ?>%"></div>
                    </div>
                    <span class="rt-rating-percentage"><?php echo $data['percentage']; ?>% (<?php echo $data['count']; ?>)</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Right: Submit Your Review (Required Email & Submit at top for General Customer Rating) -->
        <div class="rt-review-section">
            <h2>Submit Your Review</h2>
            <p class="rt-subtext">General customer rating for <?php echo htmlspecialchars($brand_name); ?></p>

            <form id="generalRatingForm" action="../api/submit_rating.php" method="POST" onsubmit="return validateGeneralForm()">
                <input type="hidden" name="company_id" value="<?php echo $company_id; ?>">
                <input type="hidden" name="question_id" value="">

                <label class="rt-review-form-label">Add Your Rating *</label>
                <div class="rt-star-rating-input">
                    <input type="radio" name="rating" value="5" id="gen_star5">
                    <label for="gen_star5" title="5 Stars">★</label>
                    <input type="radio" name="rating" value="4" id="gen_star4">
                    <label for="gen_star4" title="4 Stars">★</label>
                    <input type="radio" name="rating" value="3" id="gen_star3">
                    <label for="gen_star3" title="3 Stars">★</label>
                    <input type="radio" name="rating" value="2" id="gen_star2">
                    <label for="gen_star2" title="2 Stars">★</label>
                    <input type="radio" name="rating" value="1" id="gen_star1">
                    <label for="gen_star1" title="1 Star">★</label>
                </div>
                
                <div class="rt-input-row">
                    <div>
                        <label class="rt-review-form-label">Your Name *</label>
                        <input type="text" name="customer_name" class="rt-form-input" placeholder="e.g. John Doe" required>
                    </div>
                    <div>
                        <label class="rt-review-form-label">Email Address *</label>
                        <input type="email" name="customer_email" class="rt-form-input" placeholder="mail@example.com" required>
                    </div>
                </div>
                
                <label class="rt-review-form-label">Write Your Review *</label>
                <textarea name="comment" class="rt-form-textarea" placeholder="Share your experience working with <?php echo htmlspecialchars($brand_name); ?>..." required></textarea>
                
                <button type="submit" class="rt-submit-btn">
                    Submit Reviews
                </button>
            </form>
        </div>
    </div>
    
    <!-- ============================================================
         Bottom Section: Divided into Two
         1. Rating Questions (Questions created by admin)
         2. Customer Feedbacks (Customer reviews & testimonials)
         ============================================================ -->
    <div class="rt-divider-section">

        <!-- Navigation Tabs to Switch Between Questions and Customer Feedback -->
        <div class="rt-tab-nav" role="tablist">
            <button type="button" class="rt-tab-btn is-active" id="tabBtn-questions" role="tab" aria-selected="true" onclick="switchPublicSection('questions')">
                <span>❓</span> Specific Reviews (<?php echo count($questions); ?>)
            </button>
            <button type="button" class="rt-tab-btn" id="tabBtn-responses" role="tab" aria-selected="false" onclick="switchPublicSection('responses')">
                <span>📝</span> Review Responses (<?php echo count($question_responses); ?>)
            </button>
            <button type="button" class="rt-tab-btn" id="tabBtn-feedbacks" role="tab" aria-selected="false" onclick="switchPublicSection('feedbacks')">
                <span>💬</span> Reviews (<?php echo count($general_reviews); ?>)
            </button>
        </div>

        <!-- PANEL 1: Questions Created by Admin -->
        <div class="rt-tab-panel is-active" id="panel-questions" role="tabpanel">
            <div style="margin-bottom:20px;">
                <h3 style="font-size:20px;font-weight:800;color:#0f172a;">Specific Reviews from Administrator</h3>
                <p class="rt-subtext" style="margin-top:4px;">Share your star rating and review for each item configured by <?php echo htmlspecialchars($brand_name); ?>. No name or email needed.</p>
            </div>

            <?php if (!empty($questions)): ?>
                <form action="../api/submit_rating.php" method="POST" id="specificReviewsForm" onsubmit="return validateReviewsForm(this)">
                <input type="hidden" name="company_id" value="<?php echo $company_id; ?>">
                <div class="rt-question-list">
                    <?php foreach ($questions as $idx => $q): $q_id = (int)$q['id']; ?>
                        <article class="rt-question-card" id="question-card-<?php echo $q_id; ?>">
                            <div class="rt-question-head">
                                <div>
                                    <span class="rt-chip-gray" style="margin-bottom:6px;display:inline-block;">Review #<?php echo $idx + 1; ?></span>
                                    <h4 class="rt-question-title"><?php echo htmlspecialchars($q['question_text']); ?></h4>
                                </div>
                                <div class="rt-question-stats">
                                    <span class="rt-chip">★ <?php echo number_format($q['avg_score'], 1); ?></span>
                                    <span class="rt-chip-gray"><?php echo (int)$q['total_answers']; ?> reviews</span>
                                </div>
                            </div>

                            <!-- Star Rating & Optional Comment for this Item -->
                            <div class="rt-question-form">
                                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
                                    <label class="rt-review-form-label" style="margin:0;">Rate this specific review</label>
                                    <div class="rt-q-star-picker" style="margin-bottom:0;">
                                        <?php for ($st = 5; $st >= 1; $st--): ?>
                                            <input type="radio" name="rating[<?php echo $q_id; ?>]" value="<?php echo $st; ?>" id="q_<?php echo $q_id; ?>_star<?php echo $st; ?>">
                                            <label for="q_<?php echo $q_id; ?>_star<?php echo $st; ?>" title="<?php echo $st; ?> Stars">★</label>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <textarea name="comment[<?php echo $q_id; ?>]" class="rt-form-textarea" rows="2" placeholder="Write your review for this... (optional)" style="min-height:60px;margin-bottom:0;padding:9px 12px;font-size:13px;"></textarea>
                            </div>

                            <!-- Existing Answers to this Question -->
                            <?php if (!empty($q['answers'])): ?>
                                <div class="rt-q-answers-list">
                                    <strong style="font-size:12.5px;color:#64748b;display:block;margin-bottom:10px;text-transform:uppercase;letter-spacing:.05em;">
                                        Reviews on this question:
                                    </strong>
                                    <?php foreach ($q['answers'] as $ans): ?>
                                        <div class="rt-q-answer-item">
                                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                                                <strong style="color:#0f172a;"><?php echo htmlspecialchars($ans['customer_name']); ?></strong>
                                                <span style="color:#f59e0b;font-weight:700;">
                                                    <?php echo str_repeat('★', (int)$ans['rating']); ?>
                                                    <span style="color:#64748b;font-size:11px;font-weight:400;margin-left:4px;"><?php echo date('M d, Y', strtotime($ans['created_at'])); ?></span>
                                                </span>
                                            </div>
                                            <p style="color:#475569;margin:0;line-height:1.5;">
                                                &ldquo;<?php echo htmlspecialchars($ans['comment']); ?>&rdquo;
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                                        <?php endforeach; ?>
                </div>

                <!-- ONE general submit button for all review items -->
                <div style="display:flex;justify-content:flex-end;margin-top:14px;">
                    <button type="submit" class="rt-submit-btn" style="width:auto;padding:11px 30px;font-size:14px;">
                        Submit Review Response
                    </button>
                </div>
                </form>
            <?php else: ?>
                <div class="rt-empty">
                    <p>No specific rating questions have been created yet by the administrator.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- PANEL 2: Responses to the Specific Review Items -->
        <div class="rt-tab-panel" id="panel-responses" role="tabpanel">
            <div style="margin-bottom:20px;">
                <h3 style="font-size:20px;font-weight:800;color:#0f172a;">Review Responses</h3>
                <p class="rt-subtext" style="margin-top:4px;">Customer responses to the specific review items configured by <?php echo htmlspecialchars($brand_name); ?>.</p>
            </div>

            <?php if (!empty($question_responses)): ?>
                <div class="rt-greview-list">
                    <?php foreach ($question_responses as $rv): $rv_ts = date('M j, Y, g:i A', strtotime($rv['created_at'])); ?>
                        <div class="rt-greview">
                            <div class="rt-greview-top">
                                <div class="rt-greview-avatar"><?php echo strtoupper(substr(trim($rv['customer_name'] ?: 'A'), 0, 1)); ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                                        <span class="rt-greview-name"><?php echo htmlspecialchars($rv['customer_name'] ?: 'Anonymous'); ?></span>
                                        <span class="rt-greview-time" title="<?php echo $rv_ts; ?>"><?php echo function_exists('timeAgo') ? timeAgo($rv['created_at']) : $rv_ts; ?> &middot; <?php echo $rv_ts; ?></span>
                                    </div>
                                    <div class="rt-greview-stars">
                                        <?php
                                        for ($i = 0; $i < (int)$rv['rating']; $i++) echo '★';
                                        for ($i = (int)$rv['rating']; $i < 5; $i++) echo '☆';
                                        ?>
                                    </div>
                                    <?php if (!empty($rv['question_text'])): ?>
                                        <div class="rt-greview-q">Re: <?php echo htmlspecialchars($rv['question_text']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($rv['comment'])): ?>
                                        <p class="rt-greview-text"><?php echo htmlspecialchars($rv['comment']); ?></p>
                                    <?php else: ?>
                                        <p class="rt-greview-text rt-greview-norv">The user didn&rsquo;t write a review, and has left just a rating.</p>
                                    <?php endif; ?>

                                    <?php if (!empty($rv['admin_reply'])): ?>
                                        <div class="rt-reply-box">
                                            <strong>↪ Response from <?php echo htmlspecialchars($brand_name); ?>:</strong>
                                            <p style="margin:4px 0 0;"><?php echo htmlspecialchars($rv['admin_reply']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rt-empty">
                    <p>No review responses have been submitted yet. Answers from the Specific Reviews tab will appear here.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- PANEL 3: General Customer Feedbacks & Testimonials -->
        <div class="rt-tab-panel" id="panel-feedbacks" role="tabpanel">
            <div style="margin-bottom:20px;">
                <h3 style="font-size:20px;font-weight:800;color:#0f172a;">Customer Reviews &amp; Testimonials</h3>
                <p class="rt-subtext" style="margin-top:4px;">All general customer reviews and verified ratings for <?php echo htmlspecialchars($brand_name); ?>.</p>
            </div>

            <?php if (!empty($general_reviews)): ?>
                <div class="rt-greview-list">
                    <?php foreach ($general_reviews as $rv): $rv_ts = date('M j, Y, g:i A', strtotime($rv['created_at'])); ?>
                        <div class="rt-greview">
                            <div class="rt-greview-top">
                                <div class="rt-greview-avatar"><?php echo strtoupper(substr(trim($rv['customer_name'] ?: 'A'), 0, 1)); ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                                        <span class="rt-greview-name"><?php echo htmlspecialchars($rv['customer_name'] ?: 'Anonymous'); ?></span>
                                        <span class="rt-greview-time" title="<?php echo $rv_ts; ?>"><?php echo function_exists('timeAgo') ? timeAgo($rv['created_at']) : $rv_ts; ?> &middot; <?php echo $rv_ts; ?></span>
                                    </div>
                                    <div class="rt-greview-stars">
                                        <?php
                                        for ($i = 0; $i < (int)$rv['rating']; $i++) echo '★';
                                        for ($i = (int)$rv['rating']; $i < 5; $i++) echo '☆';
                                        ?>
                                    </div>
                                    <?php if (!empty($rv['comment'])): ?>
                                        <p class="rt-greview-text"><?php echo htmlspecialchars($rv['comment']); ?></p>
                                    <?php else: ?>
                                        <p class="rt-greview-text rt-greview-norv">The user didn&rsquo;t write a review, and has left just a rating.</p>
                                    <?php endif; ?>

                                    <!-- Official Tenant Response if present -->
                                    <?php if (!empty($rv['admin_reply'])): ?>
                                        <div class="rt-reply-box">
                                            <strong>↪ Response from <?php echo htmlspecialchars($brand_name); ?>:</strong>
                                            <p style="margin:4px 0 0;"><?php echo htmlspecialchars($rv['admin_reply']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rt-empty">
                    <p>No customer reviews have been submitted yet. Be the first to share your experience above!</p>
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<script>
/* Switch bottom sections between Specific Reviews / Review Responses / Reviews */
function switchPublicSection(sectionId) {
    var sections = ['questions', 'responses', 'feedbacks'];
    var activated = false;

    sections.forEach(function (s) {
        var btn = document.getElementById('tabBtn-' + s);
        var panel = document.getElementById('panel-' + s);
        if (!btn || !panel) return;

        var active = (s === sectionId);
        if (active) activated = true;

        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
        panel.classList.toggle('is-active', active);
    });

    if (!activated) return;

    try {
        history.replaceState(null, null, '#tab=' + sectionId);
    } catch(e) {}
}

(function() {
    var hash = location.hash.replace('#tab=', '').replace('#', '');
    if (hash === 'feedbacks' || hash === 'responses') {
        switchPublicSection(hash);
    }
})();

function validateGeneralForm() {
    var ratingSelected = document.querySelector('input[name="rating"]:checked');
    if (!ratingSelected) {
        alert('Please select a star rating (1 to 5 stars) before submitting.');
        return false;
    }
    return true;
}

function validateReviewsForm(form) {
    var anyRated = form.querySelector('input[name^="rating"]:checked');
    if (!anyRated) {
        alert('Please select a star rating for at least one review before submitting.');
        return false;
    }
    return true;
}
</script>

</body>
</html>