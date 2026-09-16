<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';

requireLogin();
requireTeamAccess('ratings');

$tenant_id = getTenantId();
$is_tenant = isTenant();
$is_admin  = isAdmin();

$success = '';
$error   = '';

// Auto-ensure admin_reply and responded_at columns exist
$resCol = $conn->query("SHOW COLUMNS FROM ratings LIKE 'admin_reply'");
if ($resCol && $resCol->num_rows === 0) {
    @$conn->query("ALTER TABLE ratings ADD COLUMN admin_reply TEXT NULL, ADD COLUMN responded_at TIMESTAMP NULL");
}

// Auto-ensure Google review booster & sentiment routing columns exist
ensureBoosterColumns($conn);
ensureRatingQuestionsCompanyColumn($conn);

// ============================================================
// POST Request Handlers (CRUD Operations)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. CREATE RATING & REVIEW
    if ($action === 'create_rating') {
        $company_id     = (int)($_POST['company_id'] ?? 0);
        $service_id     = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
        $rating_score   = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $customer_name  = sanitize($_POST['customer_name'] ?? '');
        $customer_email = sanitize($_POST['customer_email'] ?? '');
        $comment        = sanitize($_POST['comment'] ?? '');
        $question_id    = !empty($_POST['question_id']) ? (int)$_POST['question_id'] : null;

        // Auto-assign default company if none provided
        if ($company_id <= 0) {
            if ($is_tenant) {
                $c_chk = $conn->prepare("SELECT id FROM customers WHERE tenant_id = ? LIMIT 1");
                $c_chk->bind_param("i", $tenant_id);
                $c_chk->execute();
                $c_row = $c_chk->get_result()->fetch_assoc();
                $company_id = $c_row ? (int)$c_row['id'] : 1;
            } else {
                $c_row = $conn->query("SELECT id FROM customers LIMIT 1")->fetch_assoc();
                $company_id = $c_row ? (int)$c_row['id'] : 1;
            }
        }

        if (empty($customer_name)) {
            $error = "Customer name is required.";
        } else {
            // Verify tenant ownership of target company
            $valid = true;
            if ($is_tenant) {
                $chk = $conn->prepare("SELECT id FROM customers WHERE id = ? AND tenant_id = ?");
                $chk->bind_param("ii", $company_id, $tenant_id);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $valid = false;
                    $error = "Unauthorized target company selected.";
                }
            }

            if ($valid) {
                $is_verified = !empty($_POST['is_verified']) ? 1 : 0;
                $stmt = $conn->prepare("INSERT INTO ratings (company_id, service_id, question_id, rating, customer_name, customer_email, comment, is_verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("iiisssssi", $company_id, $service_id, $question_id, $rating_score, $customer_name, $customer_email, $comment, $is_verified);
                if ($stmt->execute()) {
                    $success = "New rating & review created successfully!";
                    admin_log_activity($conn, 'review_create', 'Created a rating & review for ' . $customer_name, 'rating', (int) $conn->insert_id);
                } else {
                    $error = "Failed to create rating: " . $conn->error;
                }
            }
        }
    }

    // 2. UPDATE RATING & REVIEW (DISABLED: Reviews & reviewer names are strictly non-editable)
    elseif ($action === 'update_rating') {
        $error = "Customer reviews, reviewer names, and submitted ratings are permanently locked to preserve review authenticity and prevent tampering.";
    }

    // TOGGLE VERIFICATION STATUS (Verified Customer Badge)
    elseif ($action === 'toggle_verification') {
        $rating_id = (int)($_POST['rating_id'] ?? 0);

        if ($rating_id <= 0) {
            $error = "Invalid rating identifier.";
        } else {
            $valid = true;
            $current_val = 0;
            if ($is_tenant) {
                $chk = $conn->prepare("SELECT r.id, r.is_verified FROM ratings r JOIN customers c ON r.company_id = c.id WHERE r.id = ? AND c.tenant_id = ?");
                $chk->bind_param("ii", $rating_id, $tenant_id);
                $chk->execute();
                $res = $chk->get_result();
                if ($res->num_rows === 0) {
                    $valid = false;
                    $error = "Unauthorized: You cannot modify this rating.";
                } else {
                    $row = $res->fetch_assoc();
                    $current_val = (int)$row['is_verified'];
                }
            } else {
                $chk = $conn->query("SELECT is_verified FROM ratings WHERE id = $rating_id");
                if ($row = $chk->fetch_assoc()) {
                    $current_val = (int)$row['is_verified'];
                } else {
                    $valid = false;
                    $error = "Rating record not found.";
                }
            }

            if ($valid) {
                $new_val = $current_val ? 0 : 1;
                $stmt = $conn->prepare("UPDATE ratings SET is_verified = ? WHERE id = ?");
                $stmt->bind_param("ii", $new_val, $rating_id);
                if ($stmt->execute()) {
                    $success = $new_val ? "Review #$rating_id marked as Verified Customer (Badge active)!" : "Review #$rating_id unmarked as verified.";
                    admin_log_activity($conn, 'review_verify', $new_val ? 'Marked a review as a verified customer' : 'Removed the verified badge from a review', 'rating', $rating_id);
                } else {
                    $error = "Failed to update verification status: " . $conn->error;
                }
            }
        }
    }

    // TOGGLE RESOLUTION STATUS FOR ESCALATED COMPLAINTS
    elseif ($action === 'toggle_escalation') {
        $rating_id = (int)($_POST['rating_id'] ?? 0);

        if ($rating_id <= 0) {
            $error = "Invalid rating identifier.";
        } else {
            $valid = true;
            $current_status = 'pending';
            if ($is_tenant) {
                $chk = $conn->prepare("SELECT r.id, r.escalation_status FROM ratings r JOIN customers c ON r.company_id = c.id WHERE r.id = ? AND c.tenant_id = ?");
                $chk->bind_param("ii", $rating_id, $tenant_id);
                $chk->execute();
                $res = $chk->get_result();
                if ($res->num_rows === 0) {
                    $valid = false;
                    $error = "Unauthorized: You cannot modify this rating.";
                } else {
                    $row = $res->fetch_assoc();
                    $current_status = $row['escalation_status'] ?? 'pending';
                }
                $chk->close();
            } else {
                $chk = $conn->query("SELECT escalation_status FROM ratings WHERE id = $rating_id");
                if ($row = $chk->fetch_assoc()) {
                    $current_status = $row['escalation_status'] ?? 'pending';
                } else {
                    $valid = false;
                    $error = "Rating record not found.";
                }
            }

            if ($valid) {
                $new_status = ($current_status === 'resolved') ? 'pending' : 'resolved';
                $stmt = $conn->prepare("UPDATE ratings SET escalation_status = ? WHERE id = ?");
                $stmt->bind_param("si", $new_status, $rating_id);
                if ($stmt->execute()) {
                    $success = ($new_status === 'resolved')
                        ? "Complaint on Review #$rating_id marked as Resolved!"
                        : "Review #$rating_id reopened as Pending Resolution.";
                    admin_log_activity($conn, 'review_escalate', $new_status === 'resolved' ? 'Resolved a complaint on a review' : 'Reopened a complaint on a review', 'rating', $rating_id);
                } else {
                    $error = "Failed to update resolution status: " . $conn->error;
                }
                $stmt->close();
            }
        }
    }

    // 3. DELETE RATING & REVIEW
    elseif ($action === 'delete_rating') {
        $rating_id = (int)($_POST['rating_id'] ?? 0);

        if ($rating_id <= 0) {
            $error = "Invalid rating identifier.";
        } else {
            // Verify tenant ownership
            $valid = true;
            if ($is_tenant) {
                $chk = $conn->prepare("SELECT r.id FROM ratings r JOIN customers c ON r.company_id = c.id WHERE r.id = ? AND c.tenant_id = ?");
                $chk->bind_param("ii", $rating_id, $tenant_id);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $valid = false;
                    $error = "Unauthorized: You cannot delete this rating.";
                }
            }

            if ($valid) {
                $stmt = $conn->prepare("DELETE FROM ratings WHERE id = ?");
                $stmt->bind_param("i", $rating_id);
                if ($stmt->execute()) {
                    $success = "Rating #$rating_id deleted successfully.";
                    admin_log_activity($conn, 'review_delete', 'Deleted a review', 'rating', $rating_id);
                } else {
                    $error = "Failed to delete rating: " . $conn->error;
                }
            }
        }
    }

    // 4. POST REPLY / RESPONSE TO CUSTOMER REVIEW
    elseif ($action === 'reply_rating') {
        $rating_id   = (int)($_POST['rating_id'] ?? 0);
        $admin_reply = sanitize($_POST['admin_reply'] ?? '');

        if ($rating_id <= 0) {
            $error = "Invalid review identifier.";
        } else {
            $valid = true;
            if ($is_tenant) {
                $chk = $conn->prepare("SELECT r.id FROM ratings r JOIN customers c ON r.company_id = c.id WHERE r.id = ? AND c.tenant_id = ?");
                $chk->bind_param("ii", $rating_id, $tenant_id);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $valid = false;
                    $error = "Unauthorized: You cannot reply to this rating.";
                }
            }

            if ($valid) {
                $stmt = $conn->prepare("UPDATE ratings SET admin_reply = ?, responded_at = NOW() WHERE id = ?");
                $stmt->bind_param("si", $admin_reply, $rating_id);
                if ($stmt->execute()) {
                    $success = "Your response has been saved!";
                    admin_log_activity($conn, 'review_reply', 'Replied to a customer review', 'rating', $rating_id);
                } else {
                    $error = "Failed to save response: " . $conn->error;
                }
            }
        }
    }

    // 5. CREATE RATING QUESTION (Created by Tenant for their customers)
    if ($action === 'create_question') {
        $question_text = sanitize($_POST['question_text'] ?? '');
        $target_tenant_id = (int)($tenant_id ?: ($_SESSION['tenant_id'] ?? 1));
        $is_active = isset($_POST['is_active']) ? 1 : 1;
        $q_company_id = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;

        if (empty($question_text)) {
            $error = "Question text is required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO rating_questions (tenant_id, company_id, question_text, is_active) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iisi", $target_tenant_id, $q_company_id, $question_text, $is_active);
            if ($stmt->execute()) {
                $success = "Rating question created successfully!";
                admin_log_activity($conn, 'question_create', 'Added a rating question', 'rating_question', (int) $conn->insert_id);
            } else {
                $error = "Failed to create question: " . $conn->error;
            }
        }
    }

    // 6. UPDATE RATING QUESTION
    elseif ($action === 'update_question') {
        $question_id = (int)($_POST['question_id'] ?? 0);
        $question_text = sanitize($_POST['question_text'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $q_company_id = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;

        if ($question_id <= 0 || empty($question_text)) {
            $error = "Invalid question or missing text.";
        } else {
            $valid = true;
            if ($is_tenant) {
                $chk = $conn->prepare("SELECT id FROM rating_questions WHERE id = ? AND tenant_id = ?");
                $chk->bind_param("ii", $question_id, $tenant_id);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $valid = false;
                    $error = "Unauthorized question.";
                }
            }

            if ($valid) {
                $stmt = $conn->prepare("UPDATE rating_questions SET question_text = ?, company_id = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param("siii", $question_text, $q_company_id, $is_active, $question_id);
                if ($stmt->execute()) {
                    $success = "Question updated successfully!";
                    admin_log_activity($conn, 'question_update', 'Updated a rating question', 'rating_question', (int) ($_POST['question_id'] ?? 0));
                } else {
                    $error = "Failed to update question: " . $conn->error;
                }
            }
        }
    }

    // 7. DELETE RATING QUESTION
    elseif ($action === 'delete_question') {
        $question_id = (int)($_POST['question_id'] ?? 0);

        if ($question_id <= 0) {
            $error = "Invalid question.";
        } else {
            $valid = true;
            if ($is_tenant) {
                $chk = $conn->prepare("SELECT id FROM rating_questions WHERE id = ? AND tenant_id = ?");
                $chk->bind_param("ii", $question_id, $tenant_id);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $valid = false;
                    $error = "Unauthorized question.";
                }
            }

            if ($valid) {
                $stmt = $conn->prepare("DELETE FROM rating_questions WHERE id = ?");
                $stmt->bind_param("i", $question_id);
                if ($stmt->execute()) {
                    $success = "Question deleted successfully!";
                    admin_log_activity($conn, 'question_delete', 'Deleted a rating question', 'rating_question', (int) ($_POST['question_id'] ?? 0));
                } else {
                    $error = "Failed to delete question: " . $conn->error;
                }
            }
        }
    }
}

// ============================================================
// Fetch Companies List for Filtering and Forms
// ============================================================
if ($is_tenant) {
    $comp_stmt = $conn->prepare("SELECT id, company_name FROM customers WHERE tenant_id = ? ORDER BY company_name ASC");
    $comp_stmt->bind_param("i", $tenant_id);
    $comp_stmt->execute();
    $companies_list = $comp_stmt->get_result();
} else {
    $companies_list = $conn->query("SELECT id, company_name FROM customers ORDER BY company_name ASC");
}

$companies_cache = [];
while ($c = $companies_list->fetch_assoc()) {
    $companies_cache[] = $c;
}

$active_company_id = ($is_tenant && $tenant_id) ? getActiveCompanyId($conn, $tenant_id) : 0;

$default_company_id   = !empty($companies_cache) ? (int)$companies_cache[0]['id'] : 1;
$default_company_name = !empty($companies_cache) ? $companies_cache[0]['company_name'] : 'Tech Solutions Inc';

if ($active_company_id > 0) {
    foreach ($companies_cache as $c_item) {
        if ((int)$c_item['id'] === $active_company_id) {
            $default_company_id   = $active_company_id;
            $default_company_name = $c_item['company_name'];
            break;
        }
    }
}

if (!empty($_GET['company'])) {
    $req_cid = (int)$_GET['company'];
    foreach ($companies_cache as $c_item) {
        if ((int)$c_item['id'] === $req_cid) {
            $default_company_id = $req_cid;
            $default_company_name = $c_item['company_name'];
            break;
        }
    }
}

// ============================================================
// Fetch Rating Questions
// ============================================================
$current_tenant_id = (int)($tenant_id ?: ($_SESSION['tenant_id'] ?? 1));

// Fetch Rating Questions for current tenant (with branch name)
$questions_query = $conn->prepare("SELECT rq.*, t.company_name as tenant_name, c.company_name as branch_name 
    FROM rating_questions rq 
    JOIN tenants t ON rq.tenant_id = t.id 
    LEFT JOIN customers c ON rq.company_id = c.id 
    WHERE rq.tenant_id = ? 
    ORDER BY rq.created_at DESC");
$questions_query->bind_param("i", $current_tenant_id);
$questions_query->execute();
$rating_questions = $questions_query->get_result();

// Store questions in array for reuse
$questions_array = [];
if ($rating_questions) {
    while ($q = $rating_questions->fetch_assoc()) {
        $questions_array[] = $q;
    }
}

// ============================================================
// Filtering parameters
// ============================================================
if (isset($_GET['company_id'])) {
    if ($_GET['company_id'] === 'all' || $_GET['company_id'] === '0') {
        $company_filter = 0; // View all companies/branches
    } else {
        $company_filter = (int)$_GET['company_id'];
    }
} elseif (isset($_GET['company'])) {
    if ($_GET['company'] === 'all' || $_GET['company'] === '0') {
        $company_filter = 0;
    } else {
        $company_filter = (int)$_GET['company'];
    }
} else {
    // Default to the active company/branch in session
    $company_filter = $active_company_id;
}
$service_filter    = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
$star_filter       = isset($_GET['star']) ? (int)$_GET['star'] : 0;
$escalation_filter = isset($_GET['escalation']) ? trim($_GET['escalation']) : '';
$search_query      = isset($_GET['q']) ? trim($_GET['q']) : '';
$date_from         = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to           = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$date_preset       = isset($_GET['date_preset']) ? trim($_GET['date_preset']) : '';

// Handle date presets
if ($date_preset) {
    $today = date('Y-m-d');
    switch ($date_preset) {
        case 'today':
            $date_from = $today;
            $date_to = $today;
            break;
        case 'yesterday':
            $date_from = date('Y-m-d', strtotime('-1 day'));
            $date_to = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $date_from = date('Y-m-d', strtotime('monday this week'));
            $date_to = $today;
            break;
        case 'last_week':
            $date_from = date('Y-m-d', strtotime('monday last week'));
            $date_to = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $date_from = date('Y-m-01');
            $date_to = $today;
            break;
        case 'last_month':
            $date_from = date('Y-m-01', strtotime('first day of last month'));
            $date_to = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'last_30_days':
            $date_from = date('Y-m-d', strtotime('-30 days'));
            $date_to = $today;
            break;
        case 'last_90_days':
            $date_from = date('Y-m-d', strtotime('-90 days'));
            $date_to = $today;
            break;
    }
}

// Build Query
$where_clauses = [];
$param_types   = "";
$param_vals    = [];

if ($is_tenant) {
    $where_clauses[] = "c.tenant_id = ?";
    $param_types    .= "i";
    $param_vals[]    = $tenant_id;
}

if ($company_filter > 0) {
    $where_clauses[] = "r.company_id = ?";
    $param_types    .= "i";
    $param_vals[]    = $company_filter;
}

if ($star_filter >= 1 && $star_filter <= 5) {
    $where_clauses[] = "r.rating = ?";
    $param_types    .= "i";
    $param_vals[]    = $star_filter;
}

if ($escalation_filter === 'needs_resolution') {
    $where_clauses[] = "r.is_escalated = 1 AND r.escalation_status = 'pending'";
} elseif ($escalation_filter === 'resolved') {
    $where_clauses[] = "r.is_escalated = 1 AND r.escalation_status = 'resolved'";
} elseif ($escalation_filter === 'escalated') {
    $where_clauses[] = "r.is_escalated = 1";
}

if (!empty($search_query)) {
    $where_clauses[] = "(r.customer_name LIKE ? OR r.customer_email LIKE ? OR r.comment LIKE ?)";
    $param_types    .= "sss";
    $like_q          = "%" . $search_query . "%";
    $param_vals[]    = $like_q;
    $param_vals[]    = $like_q;
    $param_vals[]    = $like_q;
}

// Date range filtering
if (!empty($date_from)) {
    $where_clauses[] = "DATE(r.created_at) >= ?";
    $param_types    .= "s";
    $param_vals[]    = $date_from;
}

if (!empty($date_to)) {
    $where_clauses[] = "DATE(r.created_at) <= ?";
    $param_types    .= "s";
    $param_vals[]    = $date_to;
}

$sql = "SELECT r.*, c.company_name, rq.question_text 
        FROM ratings r 
        JOIN customers c ON r.company_id = c.id
        LEFT JOIN rating_questions rq ON r.question_id = rq.id";

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}
$sql .= " ORDER BY r.created_at DESC";

if (!empty($param_vals)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($param_types, ...$param_vals);
    $stmt->execute();
    $ratings = $stmt->get_result();
} else {
    $ratings = $conn->query($sql);
}

// Fetch all ratings into array for multiple uses (Table + Responses feed)
$ratings_data         = [];
$total_score_sum      = 0;
$pos_count            = 0;
$pending_escalations  = 0;
$resolved_escalations = 0;
$star_counts          = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

if ($ratings && $ratings->num_rows > 0) {
    while ($row = $ratings->fetch_assoc()) {
        $ratings_data[] = $row;
        $score = (int)$row['rating'];
        $total_score_sum += $score;
        if (isset($star_counts[$score])) $star_counts[$score]++;
        if ($score >= 4) $pos_count++;
        if (!empty($row['is_escalated'])) {
            if (($row['escalation_status'] ?? '') === 'resolved') {
                $resolved_escalations++;
            } else {
                $pending_escalations++;
            }
        }
    }
}

// Total unresolved complaints for tenant (unaffected by filters)
$unresolved_badge_count = 0;
if ($is_tenant) {
    $esc_stmt = $conn->prepare("SELECT COUNT(*) cnt FROM ratings r JOIN customers c ON r.company_id = c.id WHERE c.tenant_id = ? AND r.is_escalated = 1 AND r.escalation_status = 'pending'");
    $esc_stmt->bind_param("i", $tenant_id);
    $esc_stmt->execute();
    $unresolved_badge_count = (int)($esc_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $esc_stmt->close();
} else {
    $chk_esc = $conn->query("SELECT COUNT(*) cnt FROM ratings WHERE is_escalated = 1 AND escalation_status = 'pending'");
    $unresolved_badge_count = (int)($chk_esc ? ($chk_esc->fetch_assoc()['cnt'] ?? 0) : 0);
}

$total_count = count($ratings_data);
$avg_rating  = $total_count > 0 ? round($total_score_sum / $total_count, 1) : 0.0;
$pos_pct     = $total_count > 0 ? round(($pos_count / $total_count) * 100) : 0;

$robots    = 'noindex, nofollow';
$BASE      = '../';
$pageTitle = 'Ratings & Reviews Manager';
$activeNav = 'ratings';
include __DIR__ . '/_shell.php';
?>

<!-- Page Header -->
<div class="welcome-row" style="margin-bottom:20px;">
    <div>
        <p class="eyebrow"><?php echo $is_tenant ? 'Tenant Workspace' : 'Global Platform'; ?> &middot; Rating &amp; Review Operations</p>
        <h1>Ratings &amp; Reviews Hub</h1>
        <p class="muted">Create, edit, and moderate customer ratings (CRUD) or inspect in-depth customer feedback and responses.</p>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <a href="whatsapp_sender.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;">
            💬 Ask for Reviews
        </a>
        <a href="<?php echo htmlspecialchars(getCompanyPublicRatingUrl($default_company_id, $default_company_name)); ?>" target="_blank" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;">
            ↗ View Public Portal
        </a>
        <button type="button" class="btn btn-primary" onclick="openCreateRatingModal()">
            ＋ Log Review Manually
        </button>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success" role="alert">
        ✓ <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error" role="alert">
        ⚠ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($unresolved_badge_count > 0): ?>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:22px;">⚠️</span>
            <div>
                <strong style="color:#92400e;font-size:13.5px;">Attention: You have <?php echo (int)$unresolved_badge_count; ?> unresolved customer complaint<?php echo $unresolved_badge_count > 1 ? 's' : ''; ?></strong>
                <span style="color:#78350f;font-size:12px;display:block;">Negative ratings (1–3 stars) were intercepted and gated internally to safeguard your Google Business rating.</span>
            </div>
        </div>
        <a href="ratings.php?escalation=needs_resolution" class="btn btn-secondary" style="padding:7px 14px;font-size:12px;background:#fef3c7;color:#92400e;border-color:#fde68a;font-weight:700;text-decoration:none;">
            Filter Needs Resolution &rarr;
        </a>
    </div>
<?php endif; ?>

<!-- Tab Navigation Bar -->
<nav class="admin-tabs" role="tablist" aria-label="Ratings sections">
    <button type="button" class="admin-tab-btn is-active" id="tabBtn-crud" role="tab" aria-selected="true" aria-controls="tab-crud" onclick="switchRatingTab('crud')">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        Ratings &amp; Reviews
        <span style="font-size:11px;padding:2px 7px;border-radius:99px;background:var(--bg);color:var(--ink);font-weight:700;margin-left:2px;"><?php echo $total_count; ?></span>
    </button>
    <button type="button" class="admin-tab-btn" id="tabBtn-responses" role="tab" aria-selected="false" aria-controls="tab-responses" onclick="switchRatingTab('responses')">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Customer Feedback
        <span style="font-size:11px;padding:2px 7px;border-radius:99px;background:rgba(194,245,66,.2);color:var(--lime);font-weight:700;margin-left:2px;">★ <?php echo $avg_rating; ?></span>
    </button>
    <button type="button" class="admin-tab-btn" id="tabBtn-questions" role="tab" aria-selected="false" aria-controls="tab-questions" onclick="switchRatingTab('questions')">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Rating Questions
        <span style="font-size:11px;padding:2px 7px;border-radius:99px;background:rgba(194,245,66,.12);color:var(--lime);font-weight:700;margin-left:2px;"><?php echo count($questions_array); ?></span>
    </button>
</nav>

<!-- ============================================================
     TAB 1: RATINGS & REVIEWS CRUD MANAGEMENT
     ============================================================ -->
<div class="admin-tab-panel is-active" id="tab-crud" role="tabpanel" aria-labelledby="tabBtn-crud">

    <!-- Inline Add / Edit Review Card -->
    <div class="form-card" id="ratingCrudCard" style="display:none;margin-bottom:24px;border-color:var(--lime);box-shadow:0 8px 30px rgba(0,0,0,.06);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);">
            <div>
                <h3 id="formCardTitle" style="margin:0;">Add Customer Rating &amp; Review</h3>
                <p class="muted" style="margin:3px 0 0;">Enter verified customer feedback details to register or update a review.</p>
            </div>
            <button type="button" class="btn btn-secondary" onclick="closeCreateRatingModal()" style="padding:6px 14px;font-size:12px;">
                ✕ Close
            </button>
        </div>

        <form method="POST" action="ratings.php" id="ratingForm">
            <input type="hidden" name="action" id="formAction" value="create_rating">
            <input type="hidden" name="rating_id" id="formRatingId" value="0">
            <input type="hidden" name="rating" id="formRatingScore" value="5">
            <input type="hidden" name="service_id" id="formServiceId" value="0">

            <input type="hidden" name="company_id" id="formCompanyId" value="<?php echo (int)$default_company_id; ?>">

            <div class="form-grid" style="grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:18px;margin-bottom:18px;">
                <div class="form-group">
                    <label for="formQuestionId">Rating Question (Optional)</label>
                    <select id="formQuestionId" name="question_id" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;background:var(--bg);color:var(--ink);">
                        <option value="">-- General Rating (No Specific Question) --</option>
                        <?php foreach ($questions_array as $q): ?>
                            <option value="<?php echo (int)$q['id']; ?>">
                                <?php echo htmlspecialchars($q['question_text']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted">Optionally connect this review to an admin-created question.</small>
                </div>

                <div class="form-group">
                    <label>Star Rating Score *</label>
                    <div class="admin-star-selector" id="starSelector">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button type="button" class="admin-star-opt <?php echo $s === 5 ? 'is-selected' : ''; ?>" data-score="<?php echo $s; ?>" onclick="setRatingScore(<?php echo $s; ?>)">
                                ★ <?php echo $s; ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div class="form-grid" style="grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:18px;margin-bottom:18px;">
                <div class="form-group">
                    <label for="formCustomerName">Customer Full Name *</label>
                    <input id="formCustomerName" type="text" name="customer_name" required placeholder="e.g. Sarah Jenkins">
                </div>

                <div class="form-group">
                    <label for="formCustomerEmail">Customer Email Address</label>
                    <input id="formCustomerEmail" type="email" name="customer_email" placeholder="sarah@example.com">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label for="formComment">Review Feedback / Customer Testimonial</label>
                <textarea id="formComment" name="comment" rows="3" placeholder="Provide customer comments, review notes, or client feedback..."></textarea>
            </div>

            <div class="form-group" style="margin-bottom:20px;background:var(--bg);padding:12px 14px;border-radius:8px;border:1px solid var(--line);">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:0;">
                    <input type="checkbox" name="is_verified" value="1" id="formIsVerified" style="width:16px;height:16px;accent-color:#16a34a;">
                    <span style="font-weight:700;color:var(--ink);font-size:13.5px;">Mark as Verified Customer</span>
                    <span style="font-size:11px;background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:99px;font-weight:700;">✓ Verified Badge</span>
                </label>
                <small class="muted" style="display:block;margin-top:4px;">Displays a green verified badge on public pages, widgets, and embeds.</small>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;padding-top:14px;border-top:1px solid var(--line);flex-wrap:wrap;gap:12px;">
                <span class="muted" style="font-size:12.5px;">All ratings immediately reflect in customer scores and public pages.</span>
                <div style="display:flex;gap:10px;">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateRatingModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="formSubmitBtn" style="padding:10px 24px;">Save Rating &amp; Review</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Filters & Action Bar -->
    <div class="admin-toolbar">
        <form method="GET" action="ratings.php" class="filter-form" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <select name="company_id" onchange="this.form.submit()" style="padding:9px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;font-weight:600;">
                <option value="all" <?php echo $company_filter === 0 ? 'selected' : ''; ?>>All Branches (Consolidated - <?php echo count($companies_cache); ?>)</option>
                <?php foreach ($companies_cache as $c): 
                    $is_act = ((int)$c['id'] === (int)$active_company_id);
                ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $company_filter === (int)$c['id'] ? 'selected' : ''; ?>>
                        🏢 <?php echo htmlspecialchars($c['company_name']); ?><?php echo $is_act ? ' (Active Branch)' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="star" onchange="this.form.submit()" style="padding:9px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;">
                <option value="0">All Star Ratings</option>
                <?php for ($st = 5; $st >= 1; $st--): ?>
                    <option value="<?php echo $st; ?>" <?php echo $star_filter === $st ? 'selected' : ''; ?>>
                        <?php echo str_repeat('★', $st); ?> (<?php echo $st; ?> Star)
                    </option>
                <?php endfor; ?>
            </select>

            <select name="escalation" onchange="this.form.submit()" style="padding:9px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;">
                <option value="">All Review Statuses</option>
                <option value="needs_resolution" <?php echo $escalation_filter === 'needs_resolution' ? 'selected' : ''; ?>>⚠️ Needs Resolution <?php echo $unresolved_badge_count > 0 ? "($unresolved_badge_count)" : ''; ?></option>
                <option value="resolved" <?php echo $escalation_filter === 'resolved' ? 'selected' : ''; ?>>✓ Resolved Complaints</option>
                <option value="escalated" <?php echo $escalation_filter === 'escalated' ? 'selected' : ''; ?>>All Gated Reviews</option>
            </select>

            <div style="position:relative;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search customer or feedback..." style="padding:9px 14px 9px 32px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;width:220px;">
                <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--muted);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </div>

            <!-- Date Range Filters -->
            <div style="display:flex;gap:8px;align-items:center;padding:6px 12px;border-radius:8px;background:rgba(194,245,66,.08);border:1px solid rgba(194,245,66,.3);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="From" style="padding:6px 10px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:12px;width:140px;">
                <span style="color:var(--muted);font-size:12px;">to</span>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" placeholder="To" style="padding:6px 10px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:12px;width:140px;">
                <button type="submit" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;">Apply</button>
            </div>

            <?php if ($company_filter || $star_filter || !empty($escalation_filter) || $search_query || $date_from || $date_to || $date_preset): ?>
                <a href="ratings.php" class="btn btn-secondary" style="padding:9px 14px;font-size:12px;">Reset Filters</a>
            <?php endif; ?>
        </form>

        <span class="muted" style="font-size:13px;">
            Showing <strong><?php echo $total_count; ?></strong> review record(s)
        </span>
    </div>

    <!-- Quick Date Filter Buttons -->
    <div style="display:flex;gap:8px;align-items:center;margin:16px 0;flex-wrap:wrap;">
        <span style="font-size:13px;color:var(--muted);font-weight:600;">Quick Filters:</span>
        <a href="?date_preset=today<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?><?php echo $escalation_filter ? '&escalation='.$escalation_filter : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?>" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'today' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            Today
        </a>
        <a href="?date_preset=this_week<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?><?php echo $escalation_filter ? '&escalation='.$escalation_filter : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?>" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'this_week' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            This Week
        </a>
        <a href="?date_preset=this_month<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?><?php echo $escalation_filter ? '&escalation='.$escalation_filter : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?>" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'this_month' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            This Month
        </a>
        <a href="?date_preset=last_30_days<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?><?php echo $escalation_filter ? '&escalation='.$escalation_filter : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?>" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'last_30_days' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            Last 30 Days
        </a>
        <a href="?date_preset=last_90_days<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?><?php echo $escalation_filter ? '&escalation='.$escalation_filter : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?>" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'last_90_days' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            Last 90 Days
        </a>
        <?php if ($date_from || $date_to || $date_preset): ?>
            <a href="?<?php echo $company_filter ? 'company_id='.$company_filter.'&' : ''; ?><?php echo $star_filter ? 'star='.$star_filter.'&' : ''; ?><?php echo $escalation_filter ? 'escalation='.$escalation_filter.'&' : ''; ?><?php echo $search_query ? 'q='.urlencode($search_query) : ''; ?>" 
               class="btn btn-secondary" 
               style="padding:6px 14px;font-size:12px;background:#fee;color:#b91c1c;border-color:#fca5a5;">
                Clear Date Filter
            </a>
        <?php endif; ?>
    </div>

    <!-- Data Table Card -->
    <div class="data-table-card">
        <div class="table-scroll-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Question</th>
                    <th>Score</th>
                    <th>Customer</th>
                    <th>Review & Feedback</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="text-align:right;width:130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($ratings_data)): ?>
                    <?php foreach ($ratings_data as $r): ?>
                        <tr id="rating-row-<?php echo (int)$r['id']; ?>">
                            <td class="table-title">
                                <?php echo htmlspecialchars($r['company_name']); ?>
                            </td>
                            <td class="table-subtitle" style="max-width:180px;">
                                <?php if (!empty($r['question_text'])): ?>
                                    <?php 
                                    $q_num = $r['question_id'] ?? 'N/A';
                                    $q_concat = 'Q' . $q_num . ': ' . mb_substr($r['question_text'], 0, 30) . (mb_strlen($r['question_text']) > 30 ? '...' : '');
                                    ?>
                                    <span style="color:var(--ink);font-size:12px;cursor:pointer;text-decoration:underline;" onclick="showQuestionModal('<?php echo htmlspecialchars($r['question_text'], ENT_QUOTES); ?>', <?php echo $q_num; ?>)">
                                        <?php echo htmlspecialchars($q_concat); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;background:rgba(56,189,248,.12);color:#0284c7;">General review</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="table-rating">
                                    <?php echo str_repeat('★', (int)$r['rating']); ?>
                                    <span><?php echo (int)$r['rating']; ?>.0</span>
                                </div>
                            </td>
                            <td>
                                <div class="table-title" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <?php echo htmlspecialchars($r['customer_name']); ?>
                                    <?php if (!empty($r['is_verified'])): ?>
                                        <span title="Verified Customer<?php echo !empty($r['verification_type']) ? ' via ' . htmlspecialchars($r['verification_type']) : ''; ?>" style="font-size:10.5px;font-weight:700;color:#16a34a;background:rgba(22,163,74,0.12);padding:1px 7px;border-radius:99px;display:inline-flex;align-items:center;gap:3px;">
                                            ✓ Verified
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="table-meta"><?php echo htmlspecialchars($r['customer_email'] ?: 'No email provided'); ?></div>
                                <?php if (!empty($r['momo_ref'])): ?>
                                    <div style="font-size:11px;color:var(--muted);margin-top:2px;">
                                        MoMo: <code style="background:var(--bg);color:var(--ink);padding:1px 4px;border-radius:4px;"><?php echo htmlspecialchars($r['momo_ref']); ?></code>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($r['receipt_photo'])): ?>
                                    <div style="margin-top:3px;">
                                        <a href="../<?php echo htmlspecialchars($r['receipt_photo']); ?>" target="_blank" rel="noopener" style="font-size:11px;color:#0284c7;text-decoration:underline;font-weight:600;">
                                            📎 View Receipt
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="table-text" style="max-width:250px;">
                                <?php 
                                $feedback = $r['comment'] ?: 'No written comment.';
                                $feedback_short = mb_strlen($feedback) > 80 ? mb_substr($feedback, 0, 80) . '...' : $feedback;
                                $has_more = mb_strlen($feedback) > 80;
                                ?>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span style="flex:1;min-width:0;"><?php echo htmlspecialchars($feedback_short); ?></span>
                                    <?php if ($has_more): ?>
                                        <button type="button" 
                                                onclick="showReviewModal('<?php echo htmlspecialchars($r['customer_name'], ENT_QUOTES); ?>', <?php echo (int)$r['rating']; ?>, '<?php echo htmlspecialchars($feedback, ENT_QUOTES); ?>', '<?php echo htmlspecialchars(date('M d, Y, g:i A', strtotime($r['created_at'])), ENT_QUOTES); ?>')" 
                                                class="btn-view-review"
                                                style="flex-shrink:0;padding:4px 10px;font-size:11px;font-weight:600;background:var(--bg);border:1px solid var(--line);border-radius:6px;cursor:pointer;color:#0284c7;white-space:nowrap;">
                                            View
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($r['is_escalated'])): ?>
                                    <?php if (($r['escalation_status'] ?? 'pending') === 'resolved'): ?>
                                        <div style="margin-bottom:4px;"><span style="background:#dcfce7;color:#15803d;border:1px solid #86efac;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:99px;display:inline-flex;align-items:center;gap:3px;">✓ Resolved</span></div>
                                    <?php else: ?>
                                        <div style="margin-bottom:4px;"><span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:99px;display:inline-flex;align-items:center;gap:3px;" title="Gated from Google">⚠️ Needs Resolution</span></div>
                                    <?php endif; ?>
                                <?php elseif ((int)$r['rating'] >= 4): ?>
                                    <div style="margin-bottom:4px;"><span style="background:#e8f0fe;color:#1a73e8;border:1px solid #bfdbfe;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:99px;display:inline-flex;align-items:center;gap:3px;" title="Eligible for Google boost">🚀 Google Boosted</span></div>
                                <?php endif; ?>

                                <?php if (!empty($r['admin_reply'])): ?>
                                    <span class="status-badge-replied">
                                        ✓ Replied
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge-pending">
                                        Pending reply
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="table-meta">
                                <?php echo date('M d, Y, g:i A', strtotime($r['created_at'])); ?>
                            </td>
                            <td style="text-align:right;">
                                <div class="admin-table-actions" style="justify-content:flex-end;position:relative;">
                                    <!-- Dropdown Toggle Button -->
                                    <button type="button" class="admin-sm-btn actions-dropdown-toggle" onclick="toggleActionsDropdown(this)" style="padding:6px 12px;display:inline-flex;align-items:center;gap:6px;">
                                        Actions <span style="font-size:10px;">▼</span>
                                    </button>
                                    
                                    <!-- Dropdown Menu -->
                                    <div class="actions-dropdown-menu" style="display:none;position:absolute;right:0;top:100%;margin-top:4px;background:#ffffff;border:1px solid var(--line);border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);min-width:180px;z-index:1000;">
                                        <?php if (!empty($r['is_escalated'])): ?>
                                            <form method="POST" action="ratings.php" style="margin:0;">
                                                <input type="hidden" name="action" value="toggle_escalation">
                                                <input type="hidden" name="rating_id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="dropdown-action-btn" style="<?php echo (($r['escalation_status'] ?? 'pending') === 'resolved') ? 'color:#15803d;' : 'color:#b45309;'; ?>">
                                                    <?php echo (($r['escalation_status'] ?? 'pending') === 'resolved') ? '↺ Reopen Issue' : '✓ Mark Resolved'; ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" action="ratings.php" style="margin:0;">
                                            <input type="hidden" name="action" value="toggle_verification">
                                            <input type="hidden" name="rating_id" value="<?php echo (int)$r['id']; ?>">
                                            <button type="submit" class="dropdown-action-btn" style="<?php echo !empty($r['is_verified']) ? 'color:#16a34a;' : 'color:#64748b;'; ?>">
                                                <?php echo !empty($r['is_verified']) ? '✓ Remove Verification' : '○ Mark as Verified'; ?>
                                            </button>
                                        </form>
                                        
                                        <?php if ((int)$r['rating'] >= 4 && !empty($r['comment'])): ?>
                                            <a href="social_card.php?rating_id=<?php echo (int)$r['id']; ?>" class="dropdown-action-btn" style="text-decoration:none;color:#0284c7;">
                                                🎨 Generate Social Card
                                            </a>
                                        <?php endif; ?>
                                        
                                        <button type="button" class="dropdown-action-btn" 
                                                onclick="showReviewModal('<?php echo htmlspecialchars($r['customer_name'], ENT_QUOTES); ?>', <?php echo (int)$r['rating']; ?>, '<?php echo htmlspecialchars($r['comment'] ?: 'No written comment.', ENT_QUOTES); ?>', '<?php echo htmlspecialchars(date('M d, Y, g:i A', strtotime($r['created_at'])), ENT_QUOTES); ?>'); closeAllDropdowns();"
                                                style="color:#0284c7;">
                                            👁 View Full Review
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="table-empty">
                            No ratings or reviews match your filter criteria.
                            <br><br>
                            <button type="button" class="btn btn-primary" onclick="openCreateRatingModal()">
                                ＋ Add First Rating
                            </button>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div><!-- /.table-scroll-wrap -->
    </div>
</div>

<!-- ============================================================
     TAB 2: CUSTOMER RESPONSES & FEEDBACK STREAM
     ============================================================ -->
<div class="admin-tab-panel" id="tab-responses" role="tabpanel" aria-labelledby="tabBtn-responses">

    <!-- KPI Response Analytics Bar -->
    <div class="metric-grid" style="margin-bottom:20px;">
        <div class="metric-card">
            <div class="metric-icon lime">💬</div>
            <span>Total Responses</span>
            <strong><?php echo number_format($total_count); ?></strong>
            <small>Client feedback records</small>
        </div>
        <div class="metric-card">
            <div class="metric-icon amber">★</div>
            <span>Average Score</span>
            <strong><?php echo $avg_rating; ?> <i>/ 5.0</i></strong>
            <small class="positive">↑ Overall customer sentiment</small>
        </div>
        <div class="metric-card">
            <div class="metric-icon green">✓</div>
            <span>Positive Rate</span>
            <strong><?php echo $pos_pct; ?>%</strong>
            <small>4 &amp; 5-star ratings share</small>
        </div>
        <div class="metric-card">
            <div class="metric-icon purple">✦</div>
            <span>Top-Tier Reviews</span>
            <strong><?php echo number_format($star_counts[5]); ?></strong>
            <small>5-star perfection scores</small>
        </div>
    </div>

    <!-- Filter Pills for Live Stream -->
    <div class="admin-toolbar" style="padding:14px 18px;background:var(--bg);border:1px solid var(--line);border-radius:12px;margin-bottom:16px;">
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;width:100%;">
            <div class="admin-filter-pills" style="flex:1;min-width:300px;">
                <span class="muted" style="font-size:12.5px;font-weight:700;margin-right:6px;">Filter by Score:</span>
                <a href="ratings.php<?php echo $company_filter ? '?company_id='.$company_filter : ''; ?><?php echo $date_from ? (strpos($_SERVER['QUERY_STRING'], '?') !== false ? '&' : '?').'date_from='.$date_from : ''; ?><?php echo $date_to ? '&date_to='.$date_to : ''; ?><?php echo $date_preset ? '&date_preset='.$date_preset : ''; ?>#tab=responses" class="admin-pill-btn <?php echo $star_filter === 0 ? 'is-active' : ''; ?>">
                    All (<?php echo $total_count; ?>)
                </a>
                <?php for ($s = 5; $s >= 1; $s--): ?>
                    <a href="ratings.php?star=<?php echo $s; ?><?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $date_from ? '&date_from='.$date_from : ''; ?><?php echo $date_to ? '&date_to='.$date_to : ''; ?><?php echo $date_preset ? '&date_preset='.$date_preset : ''; ?>#tab=responses" class="admin-pill-btn <?php echo $star_filter === $s ? 'is-active' : ''; ?>">
                        ★ <?php echo $s; ?> (<?php echo $star_counts[$s]; ?>)
                    </a>
                <?php endfor; ?>
            </div>

            <div style="display:flex;align-items:center;gap:10px;">
                <span class="status-dot">● Real-time feedback</span>
            </div>
        </div>
    </div>

    <!-- Date Range Filters for Tab 2 -->
    <div class="admin-toolbar" style="padding:14px 18px;background:var(--bg);border:1px solid var(--line);border-radius:12px;margin-bottom:16px;">
        <div style="margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid var(--line);">
            <span style="font-size:13px;font-weight:700;color:var(--ink);">📅 Date Range Filters</span>
        </div>
        <form method="GET" action="ratings.php#tab=responses" class="filter-form" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="star" value="<?php echo $star_filter; ?>">
            <input type="hidden" name="company_id" value="<?php echo $company_filter; ?>">
            <input type="hidden" name="escalation" value="<?php echo htmlspecialchars($escalation_filter); ?>">
            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_query); ?>">
            
            <select name="company_id" onchange="this.form.submit()" style="padding:9px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;">
                <option value="0">All Companies (<?php echo count($companies_cache); ?>)</option>
                <?php foreach ($companies_cache as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $company_filter === (int)$c['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Date Range Filters -->
            <div style="display:flex;gap:8px;align-items:center;padding:6px 12px;border-radius:8px;background:rgba(194,245,66,.08);border:1px solid rgba(194,245,66,.3);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="From" style="padding:6px 10px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:12px;width:140px;">
                <span style="color:var(--muted);font-size:12px;">to</span>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" placeholder="To" style="padding:6px 10px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:12px;width:140px;">
                <button type="submit" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;">Apply</button>
            </div>

            <?php if ($company_filter || $date_from || $date_to || $date_preset): ?>
                <a href="ratings.php#tab=responses" class="btn btn-secondary" style="padding:9px 14px;font-size:12px;">Reset Filters</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Quick Date Filter Buttons for Tab 2 -->
    <div style="display:flex;gap:8px;align-items:center;margin:16px 0;flex-wrap:wrap;">
        <span style="font-size:13px;color:var(--muted);font-weight:600;">Quick Filters:</span>
        <a href="?date_preset=today<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?>#tab=responses" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'today' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            Today
        </a>
        <a href="?date_preset=this_week<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?>#tab=responses" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'this_week' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            This Week
        </a>
        <a href="?date_preset=this_month<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?>#tab=responses" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'this_month' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            This Month
        </a>
        <a href="?date_preset=last_30_days<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?>#tab=responses" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'last_30_days' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            Last 30 Days
        </a>
        <a href="?date_preset=last_90_days<?php echo $company_filter ? '&company_id='.$company_filter : ''; ?><?php echo $star_filter ? '&star='.$star_filter : ''; ?>#tab=responses" 
           class="btn btn-secondary" 
           style="padding:6px 14px;font-size:12px;<?php echo $date_preset === 'last_90_days' ? 'background:var(--lime);color:var(--ink);border-color:var(--lime);font-weight:700;' : ''; ?>">
            Last 90 Days
        </a>
        <?php if ($date_from || $date_to || $date_preset): ?>
            <a href="?<?php echo $company_filter ? 'company_id='.$company_filter.'&' : ''; ?><?php echo $star_filter ? 'star='.$star_filter.'&' : ''; ?>#tab=responses" 
               class="btn btn-secondary" 
               style="padding:6px 14px;font-size:12px;background:#fee;color:#b91c1c;border-color:#fca5a5;">
                Clear Date Filter
            </a>
        <?php endif; ?>
    </div>

    <!-- Customer Response Cards Stream -->
    <div class="admin-response-stream">
        <?php if (!empty($ratings_data)): ?>
            <?php foreach ($ratings_data as $r): 
                $initial = strtoupper(substr($r['customer_name'] ?: 'C', 0, 1));
                $score = (int)$r['rating'];
                $has_reply = !empty($r['admin_reply']);
            ?>
                <article class="collapsible-card admin-response-item" id="response-card-<?php echo (int)$r['id']; ?>">
                    <div class="collapsible-header" onclick="toggleResponseCard(this)" style="cursor:pointer;">
                        <header class="admin-response-head">
                            <div class="admin-response-user">
                                <div class="mini-avatar" style="width:40px;height:40px;font-size:15px;background:linear-gradient(135deg,var(--lime),#a8e030);color:var(--navy);">
                                    <?php echo htmlspecialchars($initial); ?>
                                </div>
                                <div style="flex:1;">
                                    <strong style="font-size:15px;display:flex;align-items:center;gap:8px;color:var(--ink);flex-wrap:wrap;">
                                        <?php echo htmlspecialchars($r['customer_name']); ?>
                                        <?php if (!empty($r['is_verified'])): ?>
                                            <span style="font-size:11px;font-weight:700;color:#16a34a;background:rgba(22,163,74,0.12);padding:1px 7px;border-radius:99px;">✓ Verified</span>
                                        <?php endif; ?>
                                    </strong>
                                    <span class="muted" style="font-size:12px;"><?php echo htmlspecialchars($r['customer_email'] ?: 'Anonymous customer'); ?></span>
                                </div>
                            </div>

                            <div class="admin-response-meta">
                                <?php if (!empty($r['is_escalated'])): ?>
                                    <?php if (($r['escalation_status'] ?? 'pending') === 'resolved'): ?>
                                        <span style="font-size:11px;font-weight:700;color:#15803d;background:#dcfce7;border:1px solid #86efac;padding:3px 8px;border-radius:99px;">✓ Resolved</span>
                                    <?php else: ?>
                                        <span style="font-size:11px;font-weight:700;color:#92400e;background:#fef3c7;border:1px solid #fde68a;padding:3px 8px;border-radius:99px;" title="Gated negative review">⚠️ Needs Resolution</span>
                                    <?php endif; ?>
                                <?php elseif ($score >= 4): ?>
                                    <span style="font-size:11px;font-weight:700;color:#1a73e8;background:#e8f0fe;border:1px solid #bfdbfe;padding:3px 8px;border-radius:99px;" title="Boosted to Google">🚀 Google Boosted</span>
                                <?php endif; ?>
                                <span style="font-size:12px;padding:3px 10px;border-radius:6px;background:var(--bg);border:1px solid var(--line);font-weight:600;color:var(--ink);">
                                    ⌂ <?php echo htmlspecialchars($r['company_name']); ?>
                                </span>
                                <span class="table-rating" style="font-size:16px;">
                                    <?php echo str_repeat('★', $score); ?>
                                    <span style="font-size:13px;font-weight:800;"><?php echo $score; ?>.0</span>
                                </span>
                                <span class="muted" style="font-size:12px;">
                                    <?php echo date('M d, Y H:i', strtotime($r['created_at'])); ?>
                                </span>
                                <span class="collapse-icon" style="font-size:18px;color:#64748b;transition:transform 0.3s;margin-left:8px;">▼</span>
                            </div>
                        </header>
                    </div>

                    <div class="collapsible-content">

                    <div class="admin-response-body">
                        <?php if (!empty($r['question_text'])): ?>
                        <div style="background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.2);border-radius:8px;padding:10px 14px;margin-bottom:12px;">
                            <p style="font-size:11px;color:#0284c7;font-weight:600;margin-bottom:2px;">QUESTION ASKED:</p>
                            <p style="font-size:13px;color:#0c4a6e;font-weight:600;"><?php echo htmlspecialchars($r['question_text']); ?></p>
                        </div>
                        <?php endif; ?>
                        <div style="font-style:italic;color:var(--ink);">
                            &ldquo;<?php echo htmlspecialchars($r['comment'] ?: 'Rating submitted without written comment.'); ?>&rdquo;
                        </div>
                    </div>

                    <!-- Existing Response / Reply -->
                    <?php if ($has_reply): ?>
                        <div class="admin-reply-box">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                <strong style="color:#15803d;font-size:12.5px;display:flex;align-items:center;gap:6px;">
                                    <span>↪</span> Response from Administrator
                                </strong>
                                <small class="muted" style="font-size:11px;">
                                    <?php echo !empty($r['responded_at']) ? date('M d, Y H:i', strtotime($r['responded_at'])) : 'Recorded'; ?>
                                </small>
                            </div>
                            <div style="color:var(--ink);font-size:13.2px;">
                                <?php echo htmlspecialchars($r['admin_reply']); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Reply Form (Collapsible) -->
                    <div id="reply-form-<?php echo (int)$r['id']; ?>" style="display:none;margin-top:14px;padding:14px;border-radius:10px;background:var(--bg);border:1px solid var(--line);">
                        <form method="POST" action="ratings.php">
                            <input type="hidden" name="action" value="reply_rating">
                            <input type="hidden" name="rating_id" value="<?php echo (int)$r['id']; ?>">
                            <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:var(--ink);">
                                <?php echo $has_reply ? 'Edit Your Response:' : 'Write a Response to ' . htmlspecialchars($r['customer_name']) . ':'; ?>
                            </label>
                            <textarea name="admin_reply" rows="2" required placeholder="Thank the customer for their review or address their feedback..." style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;font-family:inherit;margin-bottom:10px;"><?php echo htmlspecialchars($r['admin_reply'] ?? ''); ?></textarea>
                            <div style="display:flex;justify-content:flex-end;gap:8px;">
                                <button type="button" class="btn btn-secondary" onclick="toggleReplyForm(<?php echo (int)$r['id']; ?>)" style="padding:6px 14px;font-size:12px;">Cancel</button>
                                <button type="submit" class="btn btn-primary" style="padding:6px 18px;font-size:12px;">Post Response</button>
                            </div>
                        </form>
                    </div>

                    <footer style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:12px;border-top:1px solid var(--line);flex-wrap:wrap;gap:10px;">
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <button type="button" class="btn btn-secondary" onclick="toggleReplyForm(<?php echo (int)$r['id']; ?>)" style="padding:6px 14px;font-size:12px;">
                                <?php echo $has_reply ? '✎ Edit Response' : '💬 Reply to Review'; ?>
                            </button>

                            <form method="POST" action="ratings.php" style="display:inline;margin:0;">
                                <input type="hidden" name="action" value="toggle_verification">
                                <input type="hidden" name="rating_id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;<?php echo !empty($r['is_verified']) ? 'color:#16a34a;' : ''; ?>" title="<?php echo !empty($r['is_verified']) ? 'Remove Verified Badge' : 'Mark as Verified Customer'; ?>">
                                    <?php echo !empty($r['is_verified']) ? '✓ Verified (Unverify)' : '○ Verify Customer'; ?>
                                </button>
                            </form>

                            <?php if (!empty($r['is_escalated'])): ?>
                                <form method="POST" action="ratings.php" style="display:inline;margin:0;">
                                    <input type="hidden" name="action" value="toggle_escalation">
                                    <input type="hidden" name="rating_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;<?php echo (($r['escalation_status'] ?? 'pending') === 'resolved') ? 'color:#15803d;border-color:rgba(21,128,61,0.4);' : 'color:#b45309;border-color:rgba(217,119,6,0.4);background:#fffbeb;'; ?>" title="<?php echo (($r['escalation_status'] ?? 'pending') === 'resolved') ? 'Reopen as pending complaint' : 'Mark issue as resolved'; ?>">
                                        <?php echo (($r['escalation_status'] ?? 'pending') === 'resolved') ? '↺ Reopen Issue' : '✓ Mark Resolved'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <div style="display:flex;gap:12px;align-items:center;">
                            <?php if ((int)$r['rating'] >= 4 && !empty($r['comment'])): ?>
                                <a href="social_card.php?rating_id=<?php echo (int)$r['id']; ?>" class="btn-link" style="font-size:12px;color:#0284c7;font-weight:700;">
                                    🎨 Social Card ↗
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo htmlspecialchars(getCompanyPublicRatingUrl($r['company_id'], $r['company_name'] ?? '')); ?>" target="_blank" rel="noopener" class="btn-link" style="font-size:12px;">
                                View Public Rating Page ↗
                            </a>
                            <button type="button" class="btn-link" style="font-size:12px;" onclick='copyQuote(<?php echo json_encode($r["comment"] ?: $r["customer_name"]." rated ".$r["rating"]." stars"); ?>)'>
                                Copy Quote
                            </button>
                        </div>
                    </footer>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="form-card" style="text-align:center;padding:50px 20px;">
                <p class="muted" style="font-size:15px;margin-bottom:16px;">No customer responses found matching your filter.</p>
                <button type="button" class="btn btn-primary" onclick="openCreateRatingModal()">
                    ＋ Log Customer Review
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     TAB 3: RATING QUESTIONS MANAGEMENT
     ============================================================ -->
<div class="admin-tab-panel" id="tab-questions" role="tabpanel" aria-labelledby="tabBtn-questions">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <div>
            <h3 style="margin:0;font-size:18px;">Rating Questions</h3>
            <p class="muted" style="margin:4px 0 0;">Create custom questions that customers will see when rating your company.</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="openQuestionModal()">
            ＋ Add Question
        </button>
    </div>

    <!-- Question Form Card -->
    <div class="form-card" id="questionFormCard" style="display:none;margin-bottom:24px;border-color:#38bdf8;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);">
            <div>
                <h3 id="questionFormTitle" style="margin:0;">Add New Question</h3>
                <p class="muted" style="margin:3px 0 0;">Create a custom rating question for your customers.</p>
            </div>
            <button type="button" class="btn btn-secondary" onclick="closeQuestionModal()" style="padding:6px 14px;font-size:12px;">
                ✕ Close
            </button>
        </div>
        <form method="POST" action="ratings.php" id="questionForm">
            <input type="hidden" name="action" id="questionFormAction" value="create_question">
            <input type="hidden" name="question_id" id="questionFormId" value="0">
            <input type="hidden" name="tenant_id" id="questionTenantId" value="<?php echo (int)$current_tenant_id; ?>">

            <div class="form-group">
                <label>Question Text *</label>
                <input type="text" name="question_text" id="questionText" placeholder="e.g., How satisfied are you with our customer service responsiveness?" required maxlength="500">
                <small class="muted">This question will appear on the public rating page when customers submit a rating.</small>
            </div>
            <?php if ($is_tenant && !empty($companies_cache)): ?>
            <div class="form-group">
                <label>Assigned Branch / Location</label>
                <select name="company_id" id="questionCompanyId" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;background:var(--bg);color:var(--ink);">
                    <option value="0">All Branches (Global)</option>
                    <?php foreach ($companies_cache as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$c['id'] === (int)$active_company_id ? 'selected' : ''; ?>>
                            🏢 <?php echo htmlspecialchars($c['company_name']); ?><?php echo (int)$c['id'] === (int)$active_company_id ? ' (Active)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">Assign to a specific branch or leave as "All Branches" to display across all locations.</small>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_active" id="questionIsActive" checked style="width:auto;">
                    <span>Active (show this question on your company rating portal)</span>
                </label>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary" id="questionSubmitBtn">Save Question</button>
                <button type="button" class="btn btn-secondary" onclick="closeQuestionModal()">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Questions List -->
    <div class="data-table-card">
        <div class="table-scroll-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rating Question</th>
                    <th style="width:140px;">Branch Scope</th>
                    <th style="width:110px;">Status</th>
                    <th style="width:130px;">Created Date</th>
                    <th style="text-align:right;width:130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($questions_array)): ?>
                    <?php foreach ($questions_array as $q): ?>
                        <tr>
                            <td class="table-title"><?php echo htmlspecialchars($q['question_text']); ?></td>
                            <td>
                                <?php if (!empty($q['branch_name'])): ?>
                                    <span style="font-size:11.5px;font-weight:700;color:var(--ink);">🏢 <?php echo htmlspecialchars($q['branch_name']); ?></span>
                                <?php else: ?>
                                    <span class="muted" style="font-size:11.5px;">All Branches</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($q['is_active']): ?>
                                    <span class="status-badge-replied">● Active</span>
                                <?php else: ?>
                                    <span class="status-badge-pending">● Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="table-subtitle"><?php echo date('M d, Y', strtotime($q['created_at'])); ?></td>
                                                        <td style="text-align:right;">
                                <button type="button" class="btn-icon btn-icon-edit" onclick='populateQuestionEdit(<?php echo json_encode($q); ?>)' title="Edit Question" style="background:var(--lime);color:#0f172a;border:none;border-radius:6px;width:32px;height:32px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:14px;margin-right:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 6.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z"></path><line x1="12" y1="12" x2="18" y2="6"></line></svg>
                                </button>
                                <form method="POST" action="ratings.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this rating question?');">
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                    <button type="submit" class="btn-icon btn-icon-delete" title="Delete Question" style="background:#ef4444;color:#ffffff;border:none;border-radius:6px;width:32px;height:32px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:14px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="table-empty">
                            No questions created yet. Click "Add Question" above to create your first rating question for customers!
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div><!-- /.table-scroll-wrap -->
    </div>
</div>

<script>
/* ============================================================
   Tab Switching with URL Hash Memory
   ============================================================ */
function switchRatingTab(tabId) {
    var buttons = document.querySelectorAll('.admin-tab-btn');
    var panels  = document.querySelectorAll('.admin-tab-panel');

    buttons.forEach(function(btn) {
        var active = (btn.id === 'tabBtn-' + tabId);
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    panels.forEach(function(panel) {
        panel.classList.toggle('is-active', panel.id === 'tab-' + tabId);
    });

    try {
        history.replaceState(null, null, '#tab=' + tabId);
    } catch (e) {}
}

(function () {
    var hash = location.hash.replace('#tab=', '').replace('#', '');
    if (hash === 'responses' || hash === 'crud' || hash === 'questions') {
        switchRatingTab(hash);
    }
})();

/* ============================================================
   CRUD Modal / Card Toggles & Population
   ============================================================ */
function openCreateRatingModal() {
    switchRatingTab('crud');
    var card = document.getElementById('ratingCrudCard');
    if (!card) return;
    card.style.display = 'block';

    document.getElementById('formCardTitle').innerText = 'Add Customer Rating & Review';
    document.getElementById('formAction').value        = 'create_rating';
    document.getElementById('formRatingId').value      = '0';
    document.getElementById('formSubmitBtn').innerText = 'Save Rating & Review';

    document.getElementById('formCompanyId').value     = '<?php echo $default_company_id; ?>';
    document.getElementById('formQuestionId').value   = '';
    document.getElementById('formCustomerName').value  = '';
    document.getElementById('formCustomerEmail').value = '';
    document.getElementById('formComment').value       = '';
    var vChk = document.getElementById('formIsVerified');
    if (vChk) vChk.checked = false;
    setRatingScore(5);

    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeCreateRatingModal() {
    var card = document.getElementById('ratingCrudCard');
    if (card) card.style.display = 'none';
}

function populateEditRating(data) {
    alert("Customer reviews, reviewer names, and feedback are permanently locked and cannot be edited to protect authentic customer proof.");
}

function setRatingScore(score) {
    var hidden = document.getElementById('formRatingScore');
    if (hidden) hidden.value = score;

    var buttons = document.querySelectorAll('.admin-star-opt');
    buttons.forEach(function(btn) {
        var s = parseInt(btn.getAttribute('data-score'), 10);
        btn.classList.toggle('is-selected', s === score);
    });
}

/* ============================================================
   Response Reply Form Toggle
   ============================================================ */
function toggleReplyForm(id) {
    var el = document.getElementById('reply-form-' + id);
    if (!el) return;
    el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
}

function copyQuote(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert('Review quote copied to clipboard!');
    }).catch(function() {
        alert('Copied: ' + text);
    });
}

/* ============================================================
   Question Modal / Form Toggles & Population
   ============================================================ */
function openQuestionModal() {
    var card = document.getElementById('questionFormCard');
    if (!card) return;
    card.style.display = 'block';

    document.getElementById('questionFormTitle').innerText = 'Add New Question';
    document.getElementById('questionFormAction').value = 'create_question';
    document.getElementById('questionFormId').value = '0';
    document.getElementById('questionSubmitBtn').innerText = 'Save Question';

    document.getElementById('questionText').value = '';
    document.getElementById('questionIsActive').checked = true;
    var tId = document.getElementById('questionTenantId');
    if (tId) tId.value = '<?php echo (int)$current_tenant_id; ?>';
    var cId = document.getElementById('questionCompanyId');
    if (cId) cId.value = '<?php echo (int)$active_company_id; ?>';

    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeQuestionModal() {
    var card = document.getElementById('questionFormCard');
    if (card) card.style.display = 'none';
}

function populateQuestionEdit(data) {
    var card = document.getElementById('questionFormCard');
    if (!card || !data) return;
    card.style.display = 'block';

    document.getElementById('questionFormTitle').innerText = 'Edit Question #' + data.id;
    document.getElementById('questionFormAction').value = 'update_question';
    document.getElementById('questionFormId').value = data.id;
    document.getElementById('questionSubmitBtn').innerText = 'Update Question';

    document.getElementById('questionText').value = data.question_text;
    document.getElementById('questionIsActive').checked = data.is_active == 1;
    var tId = document.getElementById('questionTenantId');
    if (tId) tId.value = data.tenant_id || '<?php echo (int)$current_tenant_id; ?>';
    var cId = document.getElementById('questionCompanyId');
    if (cId) cId.value = data.company_id || '0';

    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

</script>

<script>
// Collapsible response cards accordion
function toggleResponseCard(header) {
    const card = header.closest('.collapsible-card');
    const content = card.querySelector('.collapsible-content');
    const icon = header.querySelector('.collapse-icon');
    const isOpen = content.style.maxHeight && content.style.maxHeight !== '0px';
    
    // Close all other response cards
    document.querySelectorAll('.admin-response-item.collapsible-card').forEach(otherCard => {
        if (otherCard !== card) {
            const otherContent = otherCard.querySelector('.collapsible-content');
            const otherIcon = otherCard.querySelector('.collapse-icon');
            if (otherContent && otherIcon) {
                otherContent.style.maxHeight = '0';
                otherContent.style.opacity = '0';
                otherContent.style.marginTop = '0';
                otherIcon.style.transform = 'rotate(0deg)';
            }
        }
    });
    
    // Toggle current card
    if (isOpen) {
        content.style.maxHeight = '0';
        content.style.opacity = '0';
        content.style.marginTop = '0';
        icon.style.transform = 'rotate(0deg)';
    } else {
        content.style.maxHeight = content.scrollHeight + 'px';
        content.style.opacity = '1';
        content.style.marginTop = '14px';
        icon.style.transform = 'rotate(180deg)';
    }
}

// Initialize all response cards as collapsed on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.admin-response-item.collapsible-card .collapsible-content').forEach(content => {
        content.style.maxHeight = '0';
        content.style.opacity = '0';
        content.style.overflow = 'hidden';
        content.style.transition = 'max-height 0.4s ease, opacity 0.3s ease, margin-top 0.3s ease';
        content.style.marginTop = '0';
    });
});

// Toggle actions dropdown
function toggleActionsDropdown(btn) {
    var menu = btn.nextElementSibling;
    if (!menu || !menu.classList.contains('actions-dropdown-menu')) return;
    
    var isCurrentlyOpen = menu.style.display === 'block';
    
    // Close all dropdowns first
    closeAllDropdowns();
    
    // If it wasn't open before, open it now (toggle behavior)
    if (!isCurrentlyOpen) {
        menu.style.display = 'block';
    }
}

// Close all dropdowns
function closeAllDropdowns() {
    document.querySelectorAll('.actions-dropdown-menu').forEach(function(menu) {
        menu.style.display = 'none';
    });
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    // Check if click is outside any actions dropdown area
    if (!e.target.closest('.admin-table-actions')) {
        closeAllDropdowns();
    }
});

// Prevent dropdown from closing when clicking inside it
document.addEventListener('click', function(e) {
    if (e.target.closest('.actions-dropdown-menu')) {
        e.stopPropagation();
    }
}, true);

// Show question modal
function showQuestionModal(questionText, questionNum) {
    document.getElementById('questionNumber').textContent = 'Question #' + questionNum;
    document.getElementById('questionFullText').textContent = questionText;
    document.getElementById('questionModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close question modal
function closeQuestionModal() {
    document.getElementById('questionModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeQuestionModal();
        closeReviewModal();
    }
});

// Show review modal
function showReviewModal(customerName, rating, reviewText, dateText) {
    document.getElementById('reviewModalCustomer').textContent = customerName;
    document.getElementById('reviewModalRating').innerHTML = '★'.repeat(rating) + ' ' + rating + '.0';
    document.getElementById('reviewModalDate').textContent = dateText;
    document.getElementById('reviewModalText').textContent = reviewText;
    document.getElementById('reviewModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Close review modal
function closeReviewModal() {
    document.getElementById('reviewModal').style.display = 'none';
    document.body.style.overflow = '';
}
</script>

<!-- Question Modal -->
<div id="questionModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;padding:20px;overflow-y:auto;">
    <div style="max-width:600px;margin:80px auto;background:#ffffff;border-radius:16px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,0.3);position:relative;">
        <button type="button" onclick="closeQuestionModal()" style="position:absolute;top:16px;right:16px;background:transparent;border:none;font-size:24px;cursor:pointer;color:var(--muted);line-height:1;padding:4px 8px;">×</button>
        <h3 style="margin:0 0 8px;font-size:18px;color:var(--ink);font-weight:800;">Rating Question</h3>
        <p style="margin:0 0 18px;font-size:13px;color:var(--muted);" id="questionNumber"></p>
        <div style="background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:16px 18px;">
            <p style="margin:0;font-size:14px;line-height:1.6;color:var(--ink);" id="questionFullText"></p>
        </div>
        <div style="margin-top:20px;text-align:right;">
            <button type="button" onclick="closeQuestionModal()" class="btn btn-secondary" style="padding:10px 20px;">Close</button>
        </div>
    </div>
</div>

<!-- Review Feedback Modal -->
<div id="reviewModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;padding:20px;overflow-y:auto;">
    <div style="max-width:700px;margin:80px auto;background:#ffffff;border-radius:16px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,0.3);position:relative;">
        <button type="button" onclick="closeReviewModal()" style="position:absolute;top:16px;right:16px;background:transparent;border:none;font-size:24px;cursor:pointer;color:var(--muted);line-height:1;padding:4px 8px;">×</button>
        
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--line);">
            <div>
                <h3 style="margin:0 0 4px;font-size:18px;color:var(--ink);font-weight:800;" id="reviewModalCustomer"></h3>
                <p style="margin:0;font-size:13px;color:var(--muted);" id="reviewModalDate"></p>
            </div>
            <div style="color:#f59e0b;font-size:20px;font-weight:800;" id="reviewModalRating"></div>
        </div>
        
        <div style="background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:18px 20px;">
            <p style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;margin:0 0 8px;">Customer Feedback:</p>
            <p style="margin:0;font-size:14px;line-height:1.7;color:var(--ink);white-space:pre-wrap;" id="reviewModalText"></p>
        </div>
        
        <div style="margin-top:20px;text-align:right;">
            <button type="button" onclick="closeReviewModal()" class="btn btn-secondary" style="padding:10px 20px;">Close</button>
        </div>
    </div>
</div>

<!-- Actions Dropdown Styles -->
<style>
.btn-view-review:hover {
    background: #e0f2fe;
    border-color: #0284c7;
}
.actions-dropdown-toggle {
    position: relative;
}
.actions-dropdown-menu {
    animation: dropdownFadeIn 0.2s ease;
}
@keyframes dropdownFadeIn {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.dropdown-action-btn {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 16px;
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: var(--ink);
    transition: background 0.15s ease;
}
.dropdown-action-btn:hover {
    background: var(--bg);
}
.dropdown-action-btn:first-child {
    border-radius: 8px 8px 0 0;
}
.dropdown-action-btn:last-child {
    border-radius: 0 0 8px 8px;
}
</style>

<?php include __DIR__ . '/_shell_footer.php'; ?>

