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

// Auto-ensure booster and sentiment routing columns exist
ensureBoosterColumns($conn);

// Fetch company details and booster configuration
$c_stmt = $conn->prepare("SELECT company_name, google_store_url, booster_enabled, booster_min_stars FROM customers WHERE id = ?");
$c_stmt->bind_param("i", $company_id);
$c_stmt->execute();
$c_res = $c_stmt->get_result()->fetch_assoc();
$c_stmt->close();

$company_name      = $c_res ? $c_res['company_name'] : 'the company';
$google_store_url  = cleanGoogleReviewUrl($c_res['google_store_url'] ?? '');
$booster_enabled   = isset($c_res['booster_enabled']) ? (int)$c_res['booster_enabled'] : 1;
$booster_min_stars = isset($c_res['booster_min_stars']) ? (int)$c_res['booster_min_stars'] : 4;

// ============================================================
// BATCH MODE: The "Specific Reviews" form submits ALL question
// items at once as arrays (rating[question_id] / comment[question_id]).
// Answering an item is optional - only the items the customer
// rated are stored, each as its own anonymous review row.
// ============================================================
if (isset($_POST['rating']) && is_array($_POST['rating'])) {
    $ratings_arr  = $_POST['rating'];
    $comments_arr = (isset($_POST['comment']) && is_array($_POST['comment'])) ? $_POST['comment'] : [];

    $inserted         = 0;
    $has_low_score    = false;
    $high_score_count = 0;

    $stmt = $conn->prepare("INSERT INTO ratings (company_id, question_id, rating, customer_name, customer_email, comment, is_escalated, escalation_status) VALUES (?, ?, ?, 'Anonymous', '', ?, ?, ?)");

    foreach ($ratings_arr as $q_key => $q_rating_raw) {
        $q_id     = (int)$q_key;
        $q_rating = (int)$q_rating_raw;

        // Skip items the customer left unrated (no review is required)
        if ($q_id <= 0 || $q_rating < 1 || $q_rating > 5) {
            continue;
        }

        $q_comment          = sanitize(isset($comments_arr[$q_key]) ? $comments_arr[$q_key] : '');
        $q_is_escalated     = ($q_rating < $booster_min_stars) ? 1 : 0;
        $q_escalation_status = $q_is_escalated ? 'pending' : 'none';

        if ($q_is_escalated) {
            $has_low_score = true;
        } else {
            $high_score_count++;
        }

        $stmt->bind_param("iiisis", $company_id, $q_id, $q_rating, $q_comment, $q_is_escalated, $q_escalation_status);
        if ($stmt->execute()) {
            $inserted++;
        }
    }
    $stmt->close();

    if ($inserted > 0) {
        $batch_booster_eligible = (!$has_low_score && $high_score_count > 0 && $booster_enabled && !empty($google_store_url));
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
                <?php if ($has_low_score): ?>
                    <div class="success-icon" style="background:#fef3c7;color:#d97706;">🛡️</div>
                    <h2>Feedback Received &amp; Prioritized</h2>
                    <p><?php echo (int)$inserted; ?> responses recorded. We noticed some areas did not meet full satisfaction — your comments have been routed directly to management for review so we can improve.</p>
                <?php else: ?>
                    <div class="success-icon" style="background:#dcfce7;color:#15803d;">✓</div>
                    <h2>Thank You!</h2>
                    <p><?php echo (int)$inserted; ?> review response<?php echo $inserted > 1 ? 's' : ''; ?> submitted successfully. We appreciate your time helping <strong><?php echo htmlspecialchars($company_name); ?></strong> maintain top service quality.</p>
                <?php endif; ?>

                <?php if ($batch_booster_eligible): ?>
                    <!-- Google Booster Prompt for Batch -->
                    <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px;margin-bottom:24px;text-align:left;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                            <div style="width:32px;height:32px;border-radius:8px;background:#fff;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(0,0,0,0.04);">
                                <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                            </div>
                            <div>
                                <strong style="font-size:13.5px;color:#091a27;">Help Others Find Us on Google</strong>
                                <span style="display:block;font-size:11.5px;color:#64748b;">Takes only 10 seconds</span>
                            </div>
                        </div>
                        <p style="font-size:12.5px;color:#475569;margin:0 0 12px;line-height:1.5;">Would you also share a quick star rating on our Google Business Profile?</p>
                        <a href="<?php echo htmlspecialchars($google_store_url); ?>" target="_blank" rel="noopener noreferrer" style="display:block;text-align:center;padding:12px;background:#1a73e8;color:#fff;border-radius:8px;font-weight:700;text-decoration:none;font-size:13.5px;">
                            ⭐ Post Quick Rating on Google ↗
                        </a>
                    </div>
                <?php endif; ?>

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

// Verification metadata (MoMo reference or Receipt/Invoice upload)
$momo_ref_raw   = isset($_POST['momo_ref']) && is_string($_POST['momo_ref']) ? trim($_POST['momo_ref']) : '';
$momo_ref       = sanitize($momo_ref_raw);
$is_verified    = !empty($momo_ref) ? 1 : 0;
$verification_type = !empty($momo_ref) ? 'momo' : null;
$receipt_photo  = null;

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

// Determine sentiment escalation and Google booster eligibility
$is_escalated        = ($rating < $booster_min_stars) ? 1 : 0;
$escalation_status   = $is_escalated ? 'pending' : 'none';
$is_booster_eligible = ($rating >= $booster_min_stars) && $booster_enabled && !empty($google_store_url);

// Insert rating with verification metadata and escalation tracking
$stmt = $conn->prepare("INSERT INTO ratings (company_id, question_id, rating, customer_name, customer_email, comment, is_verified, verification_type, momo_ref, is_escalated, escalation_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iiisssissis", $company_id, $question_id, $rating, $customer_name, $customer_email, $comment, $is_verified, $verification_type, $momo_ref, $is_escalated, $escalation_status);

if ($stmt->execute()) {
    $rating_id = $conn->insert_id;
    
    // Handle receipt photo upload (confirms verified purchase)
    if (!empty($_FILES['receipt_photo']['name'])) {
        $rec_result = uploadReceiptPhoto($_FILES['receipt_photo'], $rating_id);
        if ($rec_result['success']) {
            $receipt_photo = $rec_result['path'];
            $is_verified = 1;
            $verification_type = !empty($momo_ref) ? 'momo_and_receipt' : 'receipt';
            $upd_v = $conn->prepare("UPDATE ratings SET is_verified = ?, verification_type = ?, receipt_photo = ? WHERE id = ?");
            $upd_v->bind_param("issi", $is_verified, $verification_type, $receipt_photo, $rating_id);
            $upd_v->execute();
            $upd_v->close();
        }
    }
    
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
        <title>Review Received — <?php echo htmlspecialchars($company_name); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: linear-gradient(135deg, #091a27 0%, #1e293b 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 24px 16px;
            }
            .success-card {
                background: #ffffff;
                border-radius: 24px;
                padding: 48px 38px;
                text-align: center;
                max-width: 540px;
                width: 100%;
                box-shadow: 0 25px 70px rgba(0,0,0,0.45);
            }
            .badge-verified {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: #dcfce7;
                color: #15803d;
                border: 1px solid #86efac;
                font-size: 12.5px;
                font-weight: 700;
                padding: 5px 14px;
                border-radius: 99px;
                margin-bottom: 20px;
            }
            .stars-gold {
                color: #f59e0b;
                font-size: 32px;
                letter-spacing: 4px;
                margin-bottom: 8px;
            }
            h2 {
                font-size: 26px;
                color: #091a27;
                margin-bottom: 12px;
                font-weight: 800;
                line-height: 1.25;
            }
            p.lead {
                color: #64748b;
                font-size: 15px;
                line-height: 1.6;
                margin-bottom: 24px;
            }
            .btn-group {
                display: flex;
                gap: 12px;
                justify-content: center;
                flex-wrap: wrap;
            }
            .btn {
                padding: 13px 24px;
                border-radius: 10px;
                text-decoration: none;
                font-weight: 700;
                font-size: 14px;
                transition: transform 0.2s, box-shadow 0.2s;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                cursor: pointer;
            }
            .btn:hover { transform: translateY(-2px); }
            .btn-primary {
                background: #c2f542;
                color: #091a27;
                border: none;
            }
            .btn-secondary {
                background: #f1f5f9;
                color: #334155;
                border: 1px solid #e2e8f0;
            }
            .btn-google {
                background: #1a73e8;
                color: #ffffff;
                border: none;
                width: 100%;
                font-size: 14.5px;
                padding: 14px 20px;
                box-shadow: 0 4px 14px rgba(26,115,232,0.35);
            }
            .btn-google:hover {
                background: #1557b0;
            }
        </style>
    </head>
    <body>
        <div class="success-card">

            <?php if ($is_booster_eligible): ?>
                <!-- ============================================================
                     PATH A: GOOGLE REVIEW BOOSTER (4-5 Stars)
                     Amplify positive reputation onto Google Business Profile
                     ============================================================ -->
                <div class="stars-gold"><?php echo str_repeat('★', $rating); ?></div>
                <h2>Thank You for the <?php echo (int)$rating; ?>-Star Review!</h2>
                <p class="lead">We're thrilled you had an outstanding experience with <strong><?php echo htmlspecialchars($company_name); ?></strong>.</p>

                <?php if (!empty($is_verified)): ?>
                    <div class="badge-verified">
                        <span>✓</span> Verified Customer Badge Earned!
                    </div>
                <?php endif; ?>

                <!-- Google Booster Conversion Card -->
                <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:16px;padding:22px 20px;margin-bottom:26px;text-align:left;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <div style="width:38px;height:38px;border-radius:10px;background:#ffffff;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 5px rgba(0,0,0,0.05);flex-shrink:0;">
                            <!-- Google G SVG -->
                            <svg width="22" height="22" viewBox="0 0 24 24">
                                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                            </svg>
                        </div>
                        <div>
                            <strong style="font-size:14.5px;color:#091a27;display:block;">Would you also share this on Google?</strong>
                            <span style="font-size:12px;color:#64748b;">It takes 10 seconds and helps local businesses like ours thrive!</span>
                        </div>
                    </div>

                    <?php if (!empty($comment)): ?>
                        <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:#334155;font-style:italic;position:relative;">
                            <span style="position:absolute;top:6px;right:10px;font-size:10.5px;color:#94a3b8;font-style:normal;font-weight:600;">Your submitted text</span>
                            &ldquo;<?php echo htmlspecialchars($comment); ?>&rdquo;
                        </div>
                    <?php endif; ?>

                    <button type="button" id="copyAndOpenGoogleBtn" onclick="handleGoogleBoost()" class="btn btn-google">
                        <span><?php echo !empty($comment) ? '📋 Copy Review & Post to Google ↗' : '⭐ Post Review to Google ↗'; ?></span>
                    </button>
                    <div id="copyFeedback" style="display:none;margin-top:10px;font-size:12px;color:#16a34a;font-weight:700;text-align:center;">
                        ✓ Copied to clipboard! Opening Google in new tab...
                    </div>
                </div>

                <script>
                function handleGoogleBoost() {
                    var reviewText = <?php echo json_encode($comment); ?>;
                    var googleUrl = <?php echo json_encode($google_store_url); ?>;
                    var btn = document.getElementById('copyAndOpenGoogleBtn');
                    var fb = document.getElementById('copyFeedback');

                    function doOpen() {
                        if (fb) fb.style.display = 'block';
                        btn.style.background = '#15803d';
                        btn.innerHTML = '<span>✓ Copied! Opening Google...</span>';
                        setTimeout(function() {
                            window.open(googleUrl, '_blank');
                        }, 400);
                    }

                    if (reviewText && navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(reviewText).then(doOpen).catch(function() {
                            window.open(googleUrl, '_blank');
                        });
                    } else {
                        window.open(googleUrl, '_blank');
                    }
                }
                </script>

            <?php elseif ($is_escalated): ?>
                <!-- ============================================================
                     PATH B: SENTIMENT GATING & PRIVATE ESCALATION (1-3 Stars)
                     Prevent public damage, show empathy, route to private ticket
                     ============================================================ -->
                <div style="width:72px;height:72px;border-radius:50%;background:#fef3c7;color:#d97706;font-size:34px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                    🛡️
                </div>
                <h2>We Value Your Candid Feedback</h2>
                <p class="lead">We're truly sorry that your experience with <strong><?php echo htmlspecialchars($company_name); ?></strong> didn't meet your expectations.</p>

                <!-- Private Resolution Card (No Google Link) -->
                <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:16px;padding:22px 20px;margin-bottom:26px;text-align:left;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                        <span style="font-size:16px;">⚠️</span>
                        <strong style="font-size:14px;color:#92400e;">Escalated for Private Management Resolution</strong>
                    </div>
                    <p style="font-size:13px;color:#78350f;margin:0 0 10px;line-height:1.55;">
                        Your rating and comments have been prioritized as an internal resolution ticket. Our senior leadership directly investigates every low rating so we can resolve any issues and prevent them from happening again.
                    </p>
                    <?php if (!empty($customer_email)): ?>
                        <p style="font-size:12px;color:#92400e;margin:0;line-height:1.5;background:#fef3c7;padding:8px 12px;border-radius:8px;">
                            ✉️ Our management team may follow up with you at <strong><?php echo htmlspecialchars($customer_email); ?></strong> to make things right.
                        </p>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- ============================================================
                     PATH C: STANDARD THANK YOU (Booster disabled or no Google link)
                     ============================================================ -->
                <div style="width:72px;height:72px;border-radius:50%;background:#dcfce7;color:#15803d;font-size:36px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                    ✓
                </div>
                <h2>Thank You!</h2>
                <p class="lead">Your review for <strong><?php echo htmlspecialchars($company_name); ?></strong> has been recorded successfully. We appreciate you taking the time to share your experience.</p>

                <?php if (!empty($is_verified)): ?>
                    <div class="badge-verified">
                        <span>✓</span> Verified Customer Badge Earned!
                    </div>
                <?php endif; ?>
            <?php endif; ?>

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
