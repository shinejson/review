<?php
/**
 * ============================================================
 *  Admin — Public Community Q&A Management Console
 * ============================================================
 *  Allows tenants and administrators to manage pre-purchase
 *  customer questions, compose verified official answers,
 *  curate featured FAQs, and respond directly via WhatsApp.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/logging_helpers.php';

requireLogin();
requireTeamAccess('qa');

$tenant_id = getTenantId();
$is_tenant = isTenant();

ensureQaTable($conn);

// Load active company profile for this tenant
$company_profile = null;
if ($tenant_id) {
    $active_company_id = getActiveCompanyId($conn, $tenant_id);
    $company_profile   = getActiveCompanyProfile($conn, $tenant_id);
    $company_id        = (int)($company_profile['id'] ?? $active_company_id);
} else {
    $c_res = $conn->query("SELECT * FROM customers ORDER BY id ASC LIMIT 1");
    $company_profile = $c_res ? $c_res->fetch_assoc() : null;
    $company_id = (int)($company_profile['id'] ?? 1);
}

$brand_name   = !empty($company_profile['company_name']) ? $company_profile['company_name'] : 'Your Business';
$brand_phone  = $company_profile['phone'] ?? ($company_profile['whatsapp_number'] ?? '');

$success = '';
$error   = '';

// ============================================================
// POST Request Handlers
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Answer or Edit Answer to a Customer Question
    if ($action === 'answer_question') {
        $qid        = (int)($_POST['question_id'] ?? 0);
        $answer     = trim($_POST['official_answer'] ?? '');
        $pin_status = !empty($_POST['is_pinned']) ? 1 : 0;
        $admin_id   = (int)($_SESSION['admin_id'] ?? 1);

        if ($qid <= 0 || $answer === '') {
            $error = 'Please provide an official answer for this inquiry.';
        } else {
            $ok = answerCommunityQuestion($conn, $qid, $tenant_id, $answer, $admin_id);
            if ($ok) {
                if ($pin_status) {
                    $conn->query("UPDATE community_questions SET is_pinned = 1 WHERE id = $qid");
                }
                admin_log_activity($conn, 'qa_answer', 'Answered a customer question', 'community_question', $qid);
                header('Location: qa.php?msg=answered');
                exit;
            } else {
                $error = 'Failed to record official response. Please check permissions.';
            }
        }
    }

    // 2. Toggle Pin (Featured FAQ)
    elseif ($action === 'toggle_pin') {
        $qid = (int)($_POST['question_id'] ?? 0);
        if ($qid > 0) {
            togglePinQuestion($conn, $qid, $tenant_id);
            admin_log_activity($conn, 'qa_pin', 'Pinned or unpinned a customer question', 'community_question', $qid);
            header('Location: qa.php?msg=pin_toggled');
            exit;
        }
    }

    // 3. Create Pre-Emptive Management FAQ
    elseif ($action === 'create_faq') {
        $question = trim($_POST['question_text'] ?? '');
        $answer   = trim($_POST['official_answer'] ?? '');
        $pinned   = !empty($_POST['is_pinned']) ? 1 : 0;
        $admin_id = (int)($_SESSION['admin_id'] ?? 1);

        if ($question === '' || $answer === '') {
            $error = 'Both Question and Official Answer are required to publish an FAQ.';
        } else {
            $ok = createPreEmptiveFaq($conn, $tenant_id, $company_id, $question, $answer, $pinned, $admin_id);
            if ($ok) {
                admin_log_activity($conn, 'qa_faq', 'Published the FAQ "' . $question . '"', 'community_question', (int) $conn->insert_id);
                header('Location: qa.php?msg=faq_created');
                exit;
            } else {
                $error = 'Failed to create pre-emptive FAQ. Please try again.';
            }
        }
    }

    // 4. Delete Question
    elseif ($action === 'delete_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        if ($qid > 0) {
            deleteCommunityQuestion($conn, $qid, $tenant_id);
            admin_log_activity($conn, 'qa_delete', 'Deleted a customer question', 'community_question', $qid);
            header('Location: qa.php?msg=deleted');
            exit;
        }
    }
}

// Flash message banners
$msg = $_GET['msg'] ?? '';
if ($msg === 'answered')     $success = 'Official management answer published successfully!';
if ($msg === 'pin_toggled')  $success = 'Featured FAQ pin status updated!';
if ($msg === 'faq_created')  $success = 'Pre-emptive FAQ published to your public rating portal!';
if ($msg === 'deleted')      $success = 'Question removed successfully.';

// ============================================================
// Metrics Computation
// ============================================================
$stat_sql = "SELECT 
    COUNT(*) AS total_questions,
    SUM(CASE WHEN (official_answer IS NULL OR TRIM(official_answer) = '') THEN 1 ELSE 0 END) AS unanswered_count,
    SUM(CASE WHEN (official_answer IS NOT NULL AND TRIM(official_answer) != '') THEN 1 ELSE 0 END) AS answered_count,
    SUM(CASE WHEN is_pinned = 1 THEN 1 ELSE 0 END) AS pinned_count,
    COALESCE(SUM(helpful_count), 0) AS total_helpful
FROM community_questions WHERE 1=1";
if ($company_id > 0) {
    $stat_sql .= " AND company_id = " . (int)$company_id;
} elseif ($tenant_id > 0) {
    $stat_sql .= " AND tenant_id = " . (int)$tenant_id;
}
$stat_res = $conn->query($stat_sql);
$stats = $stat_res ? $stat_res->fetch_assoc() : [];

$total_q      = (int)($stats['total_questions'] ?? 0);
$unanswered_q = (int)($stats['unanswered_count'] ?? 0);
$answered_q   = (int)($stats['answered_count'] ?? 0);
$pinned_q     = (int)($stats['pinned_count'] ?? 0);
$helpful_q    = (int)($stats['total_helpful'] ?? 0);

// Filters and search term
$current_filter = $_GET['filter'] ?? 'all';
$search_term    = trim($_GET['q'] ?? '');

$questions = getAdminCommunityQuestions($conn, $tenant_id, $current_filter, $search_term, $company_id);

// Public rating page link with tenant name
if ($company_id > 0) {
    $public_qa_url = getCompanyPublicRatingUrl($company_id, $brand_name) . '#tab=qa';
} else {
    $public_qa_url = getCompanyPublicRatingUrl(0, $brand_name, ['tenant' => (int)$tenant_id]) . '#tab=qa';
}

$BASE      = '../';
$pageTitle = 'Community Q&A';
$activeNav = 'qa';
include __DIR__ . '/_shell.php';
?>

<style>
/* ── QA Page: Semantic classes using CSS variables ── */

/* Flash banners */
.qa-banner-success {
    background: #dcfce7; border: 1px solid #86efac; color: #15803d;
    padding: 12px 18px; border-radius: 12px; margin-bottom: 20px;
    font-weight: 600; display: flex; align-items: center; gap: 8px;
}
.qa-banner-error {
    background: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c;
    padding: 12px 18px; border-radius: 12px; margin-bottom: 20px;
    font-weight: 600; display: flex; align-items: center; gap: 8px;
}

/* Needs-answer card highlight */
.qa-card-unanswered { border-color: #fcd34d !important; background: #fffdf5 !important; }
.qa-card-pinned     { border-color: #fde68a !important; }
.qa-card-normal     { border-color: var(--line) !important; background: var(--card-bg, #fff) !important; }

/* Card question text */
.qa-question-text {
    font-size: 16px; font-weight: 800; color: var(--ink); line-height: 1.45;
}

/* Official answer block */
.qa-answer-block {
    background: #f8fafc; border: 1px solid #e2e8f0;
    border-left: 4px solid #059669;
    border-radius: 0 12px 12px 0;
    padding: 16px 18px; margin-bottom: 16px;
}
.qa-answer-label { font-size: 12.5px; font-weight: 700; color: #065f46; }
.qa-answer-text  { font-size: 14px; color: #334155; line-height: 1.6; }

/* FAQ creation box */
.qa-faq-box {
    background: var(--bg); border: 2px dashed var(--line) !important;
}

/* Seed template buttons */
.qa-seed-btn {
    cursor: pointer;
    border: 1px solid var(--line) !important;
    background: var(--card-bg, #fff) !important;
    color: var(--ink) !important;
}

/* Form inputs */
.qa-form-input {
    background: var(--bg);
    border: 1px solid var(--line);
    color: var(--ink);
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 14px;
    width: 100%;
    font-family: inherit;
    transition: border-color .15s ease, background .15s ease;
}
.qa-form-input::placeholder { color: var(--muted); }
.qa-form-input:focus { outline: none; border-color: #059669; background: var(--card-bg, #fff); }

/* Search input */
.qa-search-input {
    background: var(--bg);
    border: 1px solid var(--line);
    color: var(--ink);
    border-radius: 10px;
    padding: 8px 14px;
    font-size: 13px;
    width: 100%;
    font-family: inherit;
}
.qa-search-input::placeholder { color: var(--muted); }
.qa-search-input:focus { outline: none; border-color: #059669; }

/* Toolbar border */
.qa-toolbar-sep { border-top: 1px solid var(--line); }

/* Empty state */
.qa-empty {
    background: var(--card-bg, #fff);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 50px 20px;
    text-align: center;
}

/* ── Dark Mode Overrides ── */
:root[data-theme='dark'] .qa-banner-success {
    background: rgba(20,83,45,.35); border-color: rgba(134,239,172,.25); color: #86efac;
}
:root[data-theme='dark'] .qa-banner-error {
    background: rgba(127,29,29,.35); border-color: rgba(252,165,165,.2); color: #fca5a5;
}
:root[data-theme='dark'] .qa-card-unanswered {
    border-color: rgba(252,211,77,.35) !important;
    background: rgba(252,211,77,.04) !important;
}
:root[data-theme='dark'] .qa-card-pinned {
    border-color: rgba(253,230,138,.25) !important;
}
:root[data-theme='dark'] .qa-card-normal {
    background: #0f1f2e !important;
}
:root[data-theme='dark'] .qa-answer-block {
    background: rgba(5,150,105,.08);
    border-color: rgba(5,150,105,.2);
    border-left-color: #059669;
}
:root[data-theme='dark'] .qa-answer-label { color: #6ee7b7; }
:root[data-theme='dark'] .qa-answer-text  { color: #cbd5e1; }
:root[data-theme='dark'] .qa-faq-box      { background: rgba(255,255,255,.02) !important; }
:root[data-theme='dark'] .qa-seed-btn {
    border-color: rgba(255,255,255,.1) !important;
    background: rgba(255,255,255,.04) !important;
    color: #e2e8f0 !important;
}
:root[data-theme='dark'] .qa-form-input {
    background: rgba(255,255,255,.05);
    border-color: rgba(255,255,255,.1);
    color: #e2e8f0;
}
:root[data-theme='dark'] .qa-form-input:focus {
    background: rgba(255,255,255,.08);
    border-color: #059669;
}
:root[data-theme='dark'] .qa-search-input {
    background: rgba(255,255,255,.05);
    border-color: rgba(255,255,255,.1);
    color: #e2e8f0;
}
:root[data-theme='dark'] .qa-empty { background: #0f1f2e; border-color: rgba(255,255,255,.08); }

/* ── Filter Tab Strip ── */
.qa-tab-strip {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.03);
    transition: background .3s ease, border-color .3s ease;
    flex-wrap: wrap;
}
.qa-tab-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 13px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    text-decoration: none;
    color: var(--muted);
    border: 1px solid transparent;
    transition: all .18s ease;
    white-space: nowrap;
}
.qa-tab-link:hover  { background: var(--bg); color: var(--ink); }
.qa-tab-link.active {
    background: var(--navy);
    color: var(--lime);
    border-color: transparent;
    font-weight: 700;
    box-shadow: 0 2px 10px rgba(11,29,43,.15);
}
.qa-tab-count {
    font-size: 11px;
    padding: 1px 6px;
    border-radius: 99px;
    background: rgba(255,255,255,.18);
    font-weight: 700;
}
.qa-tab-link:not(.active) .qa-tab-count {
    background: var(--line);
    color: var(--muted);
}

:root[data-theme='dark'] .qa-tab-strip {
    background: #0f1f2e;
    border-color: rgba(255,255,255,.08);
}
:root[data-theme='dark'] .qa-tab-link:hover {
    background: rgba(255,255,255,.05);
    color: #e2e8f0;
}
:root[data-theme='dark'] .qa-tab-link.active {
    background: rgba(194,245,66,.15);
    color: var(--lime);
    border-color: rgba(194,245,66,.25);
    box-shadow: none;
}

/* Badge pills (status) */
.qa-badge-answered { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#dcfce7;color:#15803d;border:1px solid #86efac;border-radius:99px;font-size:11.5px;font-weight:700; }
.qa-badge-unanswered { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;border-radius:99px;font-size:11.5px;font-weight:700; }
.qa-badge-pinned { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:99px;font-size:11.5px;font-weight:700; }
.qa-contact-pill { display:inline-flex;align-items:center;gap:6px;font-size:11.5px;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:6px;border:1px solid #e2e8f0; }

:root[data-theme='dark'] .qa-badge-answered    { background:rgba(20,83,45,.3);border-color:rgba(134,239,172,.2);color:#6ee7b7; }
:root[data-theme='dark'] .qa-badge-unanswered  { background:rgba(127,29,29,.3);border-color:rgba(252,165,165,.2);color:#fca5a5; }
:root[data-theme='dark'] .qa-badge-pinned      { background:rgba(146,64,14,.25);border-color:rgba(253,230,138,.2);color:#fde68a; }
:root[data-theme='dark'] .qa-contact-pill      { background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.08);color:#94a3b8; }
</style>

<!-- Header Row -->
<div class="welcome-row" style="margin-bottom:24px;">
    <div>
        <p class="eyebrow">Customer Conversion &middot; Pre-Purchase Trust</p>
        <h1 style="margin:0;">Community Q&amp;A Manager</h1>
        <p class="muted" style="margin-top:6px;">Answer customer pre-purchase questions, clarify pricing or services, and curate featured FAQs for <?php echo htmlspecialchars($brand_name); ?>.</p>
        <div style="margin-top:8px;display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;background:rgba(194,245,66,0.18);color:var(--ink);border:1px solid rgba(194,245,66,0.4);">
                <span>🏢</span> Current Branch: <strong><?php echo htmlspecialchars($brand_name); ?></strong>
            </span>
            <?php if (!empty($tenant_all_branches) && count($tenant_all_branches) > 1): ?>
            <span class="muted" style="font-size:11.5px;">&middot; Questions &amp; FAQs are scoped to this branch</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" onclick="toggleFaqBox()" style="display:inline-flex;align-items:center;gap:6px;">
            <span>➕</span> Add Pinned FAQ
        </button>
        <a href="<?php echo htmlspecialchars($public_qa_url); ?>" target="_blank" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
            <span>↗</span> View Public Q&amp;A
        </a>
    </div>
</div>

<?php if (!empty($success)): ?>
    <div class="qa-banner-success">
        <span>✓</span> <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="qa-banner-error">
        <span>⚠️</span> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Metrics Bar -->
<div class="metric-grid" style="margin-bottom:26px;">
    <div class="metric-card">
        <div class="metric-icon blue">❓</div>
        <span>Total Questions</span>
        <strong><?php echo number_format($total_q); ?></strong>
        <small>Customer inquiries received</small>
    </div>
    <div class="metric-card" style="<?php echo $unanswered_q > 0 ? 'border:1.5px solid #f59e0b;background:#fffbeb;' : ''; ?>">
        <div class="metric-icon amber">⏳</div>
        <span>Needs Answer</span>
        <strong style="<?php echo $unanswered_q > 0 ? 'color:#d97706;' : ''; ?>"><?php echo number_format($unanswered_q); ?></strong>
        <small><?php echo $unanswered_q > 0 ? '⚠️ Inquiries awaiting reply' : 'All questions answered!'; ?></small>
    </div>
    <div class="metric-card">
        <div class="metric-icon green">✓</div>
        <span>Answered &amp; Live</span>
        <strong><?php echo number_format($answered_q); ?></strong>
        <small>Published official responses</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon purple">📌</div>
        <span>Featured FAQs</span>
        <strong><?php echo number_format($pinned_q); ?></strong>
        <small>Pinned top on rating page</small>
    </div>
</div>

<!-- Pre-Emptive FAQ Creation Box (Collapsible) -->
<div class="form-card qa-faq-box" id="addFaqBox" style="display:none;padding:24px;margin-bottom:26px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div>
            <h3 style="margin:0;font-size:16px;color:var(--ink);">Create Pre-Emptive FAQ</h3>
            <p class="muted" style="margin:4px 0 0;font-size:13px;">Proactively answer top pre-purchase questions (e.g. delivery zones, MoMo payment, opening hours) to build instant trust.</p>
        </div>
        <button type="button" onclick="toggleFaqBox()" style="background:none;border:none;font-size:22px;color:var(--muted);cursor:pointer;">&times;</button>
    </div>

    <!-- Quick Seed Templates -->
    <div style="margin-bottom:16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:12px;font-weight:700;color:var(--muted);">Quick Suggestions:</span>
        <button type="button" class="badge-pill qa-seed-btn" onclick="seedFaq('Do you offer delivery in Accra / nationwide?', 'Yes! We offer nationwide delivery. Accra and Tema deliveries take 2-4 hours, while other regions take 24-48 hours via registered dispatch.')">🚚 Delivery Area</button>
        <button type="button" class="badge-pill qa-seed-btn" onclick="seedFaq('What payment methods do you accept?', 'We accept MTN MoMo, Vodafone Cash, AirtelTigo, Visa, Mastercard, and Cash on Delivery for selected locations.')">💳 Payment Options</button>
        <button type="button" class="badge-pill qa-seed-btn" onclick="seedFaq('What are your business and customer support hours?', 'Our business hours are Monday through Saturday, 8:00 AM to 7:00 PM. WhatsApp support is available 24/7.')">⏰ Hours &amp; Support</button>
    </div>

    <form method="POST" action="qa.php">
        <input type="hidden" name="action" value="create_faq">
        <div style="margin-bottom:14px;">
            <label class="form-label" style="font-weight:700;font-size:13px;color:var(--ink);display:block;margin-bottom:6px;">Question <span style="color:#ef4444;">*</span></label>
            <input type="text" name="question_text" id="faqQuestionInput" required class="qa-form-input" placeholder="e.g. Do you offer bulk discounts or corporate packages?">
        </div>
        <div style="margin-bottom:14px;">
            <label class="form-label" style="font-weight:700;font-size:13px;color:var(--ink);display:block;margin-bottom:6px;">Official Verified Answer <span style="color:#ef4444;">*</span></label>
            <textarea name="official_answer" id="faqAnswerInput" required rows="3" class="qa-form-input" placeholder="Write a clear, authoritative response from management..." style="resize:vertical;"></textarea>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <label style="display:inline-flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer;color:var(--ink);">
                <input type="checkbox" name="is_pinned" value="1" checked style="width:16px;height:16px;">
                <strong>📌 Pin as Featured FAQ (Appears at top of public Q&amp;A tab)</strong>
            </label>
            <div style="display:flex;gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="toggleFaqBox()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#059669;border-color:#059669;">Publish FAQ to Portal</button>
            </div>
        </div>
    </form>
</div>

<!-- Controls Row: Filter Tabs & Search -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
    <div class="qa-tab-strip">
        <a href="qa.php?filter=all<?php echo $search_term ? '&q=' . urlencode($search_term) : ''; ?>"
           class="qa-tab-link<?php echo $current_filter === 'all' ? ' active' : ''; ?>">
            ❓ All Questions <span class="qa-tab-count"><?php echo $total_q; ?></span>
        </a>
        <a href="qa.php?filter=unanswered<?php echo $search_term ? '&q=' . urlencode($search_term) : ''; ?>"
           class="qa-tab-link<?php echo $current_filter === 'unanswered' ? ' active' : ''; ?>"
           <?php echo $unanswered_q > 0 ? 'style="font-weight:800;"' : ''; ?>>
            ⚠️ Needs Answer <span class="qa-tab-count"><?php echo $unanswered_q; ?></span>
        </a>
        <a href="qa.php?filter=answered<?php echo $search_term ? '&q=' . urlencode($search_term) : ''; ?>"
           class="qa-tab-link<?php echo $current_filter === 'answered' ? ' active' : ''; ?>">
            ✓ Answered <span class="qa-tab-count"><?php echo $answered_q; ?></span>
        </a>
        <a href="qa.php?filter=pinned<?php echo $search_term ? '&q=' . urlencode($search_term) : ''; ?>"
           class="qa-tab-link<?php echo $current_filter === 'pinned' ? ' active' : ''; ?>">
            📌 Featured FAQs <span class="qa-tab-count"><?php echo $pinned_q; ?></span>
        </a>
    </div>

    <!-- Search Form -->
    <form method="GET" action="qa.php" style="display:flex;align-items:center;gap:8px;min-width:240px;">
        <?php if ($current_filter !== 'all'): ?>
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($current_filter); ?>">
        <?php endif; ?>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Search inquiries..." class="qa-search-input">
        <?php if ($search_term !== ''): ?>
            <a href="qa.php?filter=<?php echo urlencode($current_filter); ?>" class="btn btn-secondary" style="padding:8px 12px;font-size:12px;text-decoration:none;flex-shrink:0;">✕</a>
        <?php endif; ?>
    </form>
</div>

<!-- Questions Stream -->
<?php if (!empty($questions)): ?>
    <div style="display:flex;flex-direction:column;gap:18px;">
        <?php foreach ($questions as $q): 
            $qid         = (int)$q['id'];
            $is_pinned   = !empty($q['is_pinned']);
            $has_answer  = !empty($q['official_answer']) && trim($q['official_answer']) !== '';
            $cust_name   = !empty($q['customer_name']) ? $q['customer_name'] : 'Customer';
            $cust_email  = trim((string)($q['customer_email'] ?? ''));
            $cust_phone  = trim((string)($q['customer_phone'] ?? ''));
            $created_ts  = date('M j, Y, g:i A', strtotime($q['created_at']));
            $time_ago    = function_exists('timeAgo') ? timeAgo($q['created_at']) : $created_ts;
            $answered_ts = !empty($q['answered_at']) ? date('M j, Y, g:i A', strtotime($q['answered_at'])) : '';
            
            // Build WhatsApp reply URL if customer left a phone
            $cust_wa_url = '';
            if ($cust_phone !== '') {
                $wa_reply_msg = "Hello " . $cust_name . ", thank you for contacting " . $brand_name . " regarding your question: \"" . mb_substr(strip_tags($q['question_text']), 0, 80) . "\" - ";
                $cust_wa_url = "https://wa.me/" . preg_replace('/[^0-9]/', '', $cust_phone) . "?text=" . rawurlencode($wa_reply_msg);
            }
        ?>
            <div class="collapsible-card form-card <?php echo !$has_answer ? 'qa-card-unanswered' : ($is_pinned ? 'qa-card-pinned qa-card-normal' : 'qa-card-normal'); ?>" style="padding:22px;box-shadow:0 2px 8px rgba(0,0,0,0.03);">
                <!-- Card Top Info - Clickable Header -->
                <div class="collapsible-header" onclick="toggleQuestionCard(this)" style="cursor:pointer;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:12px;">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1;">
                            <?php if ($is_pinned): ?>
                                <span class="qa-badge-pinned">📌 Featured FAQ</span>
                            <?php endif; ?>

                            <?php if ($has_answer): ?>
                                <span class="qa-badge-answered">✓ Answered &amp; Live</span>
                            <?php else: ?>
                                <span class="qa-badge-unanswered">⚠️ Needs Official Answer</span>
                            <?php endif; ?>

                            <span style="font-size:13.5px;font-weight:700;color:var(--ink);"><?php echo htmlspecialchars($cust_name); ?></span>

                            <!-- Private Contact Badge (Visible ONLY to Tenant) -->
                            <?php if ($cust_phone !== '' || $cust_email !== ''): ?>
                                <span class="qa-contact-pill" title="Private contact information provided for direct notifications">
                                    <span>🔒</span>
                                    <?php if ($cust_phone !== ''): ?>
                                        <span><?php echo htmlspecialchars($cust_phone); ?></span>
                                    <?php endif; ?>
                                    <?php if ($cust_email !== ''): ?>
                                        <span><?php echo ($cust_phone !== '' ? '&middot; ' : '') . htmlspecialchars($cust_email); ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div style="display:flex;align-items:center;gap:12px;">
                            <span style="font-size:12px;color:var(--muted);"><?php echo $time_ago; ?></span>
                            <span style="font-size:12px;color:#059669;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                                <span>👍</span> <?php echo (int)$q['helpful_count']; ?>
                            </span>
                            <span class="collapse-icon" style="font-size:18px;color:var(--muted);transition:transform 0.3s;">▼</span>
                        </div>
                    </div>

                    <!-- The Question Text -->
                    <div style="margin-bottom:14px;">
                        <div class="qa-question-text">
                            Q: <?php echo htmlspecialchars($q['question_text']); ?>
                        </div>
                    </div>
                </div>

                <!-- Collapsible Content Area -->
                <div class="collapsible-content">

                <!-- If already answered: Show current answer & edit toggle -->
                <?php if ($has_answer): ?>
                    <div class="qa-answer-block">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                            <span class="qa-answer-label" style="display:inline-flex;align-items:center;gap:6px;">
                                <span>↪</span> Official Management Response
                            </span>
                            <?php if ($answered_ts !== ''): ?>
                                <small style="font-size:11.5px;color:var(--muted);">Answered <?php echo $answered_ts; ?><?php echo !empty($q['answered_by_name']) ? ' by ' . htmlspecialchars($q['answered_by_name']) : ''; ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="qa-answer-text">
                            <?php echo nl2br(htmlspecialchars($q['official_answer'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Response Composer Form (Shown when unanswered, or toggleable if editing) -->
                <div id="replyBox-<?php echo $qid; ?>" style="<?php echo $has_answer ? 'display:none;' : 'display:block;'; ?>margin-top:14px;padding-top:14px;border-top:1px dashed var(--line);">
                    <form method="POST" action="qa.php">
                        <input type="hidden" name="action" value="answer_question">
                        <input type="hidden" name="question_id" value="<?php echo $qid; ?>">
                        <div style="margin-bottom:10px;">
                            <label style="font-weight:700;font-size:12.5px;color:var(--ink);display:block;margin-bottom:6px;">
                                <?php echo $has_answer ? 'Update Official Management Answer:' : 'Compose Official Management Answer:'; ?>
                            </label>
                            <textarea name="official_answer" required rows="3" class="qa-form-input" placeholder="Type your official, verified answer here. This will appear publicly on your rating portal..." style="resize:vertical;"><?php echo htmlspecialchars($q['official_answer'] ?? ''); ?></textarea>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;color:var(--ink);">
                                <input type="checkbox" name="is_pinned" value="1" <?php echo $is_pinned ? 'checked' : ''; ?> style="width:15px;height:15px;">
                                Pin as Featured FAQ on Rating Portal
                            </label>
                            <div style="display:flex;gap:8px;">
                                <?php if ($has_answer): ?>
                                    <button type="button" class="btn btn-secondary" onclick="toggleReplyBox(<?php echo $qid; ?>)" style="padding:6px 12px;font-size:12.5px;">Cancel</button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary" style="padding:7px 16px;font-size:12.5px;background:#059669;border-color:#059669;">
                                    <?php echo $has_answer ? 'Save Changes' : 'Publish Official Answer'; ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Card Action Toolbar -->
                <div class="qa-toolbar-sep" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:14px;padding-top:12px;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <?php if ($has_answer): ?>
                            <button type="button" class="btn btn-secondary" onclick="toggleReplyBox(<?php echo $qid; ?>)" style="padding:5px 12px;font-size:12px;">
                                ✏️ Edit Answer
                            </button>
                        <?php endif; ?>

                        <!-- Toggle Pin Form -->
                        <form method="POST" action="qa.php" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_pin">
                            <input type="hidden" name="question_id" value="<?php echo $qid; ?>">
                            <button type="submit" class="btn btn-secondary" style="padding:5px 12px;font-size:12px;">
                                <?php echo $is_pinned ? 'Unpin FAQ' : '📌 Pin as FAQ'; ?>
                            </button>
                        </form>

                        <!-- Direct WhatsApp Response if Customer provided Phone -->
                        <?php if ($cust_wa_url !== ''): ?>
                            <a href="<?php echo htmlspecialchars($cust_wa_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="padding:5px 12px;font-size:12px;color:#15803d;border-color:#bbf7d0;background:#f0fdf4;text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
                                💬 Reply to Customer on WhatsApp
                            </a>
                        <?php endif; ?>
                    </div>

                    <div>
                        <!-- Delete Question Form -->
                        <form method="POST" action="qa.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete this question?')">
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="question_id" value="<?php echo $qid; ?>">
                            <button type="submit" style="background:none;border:none;color:var(--muted);font-size:12px;cursor:pointer;padding:4px 8px;border-radius:6px;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color=''">
                                🗑 Delete
                            </button>
                        </form>
                    </div>
                </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="qa-empty">
        <div style="font-size:42px;margin-bottom:10px;">💡</div>
        <h3 style="margin:0 0 6px;font-size:18px;color:var(--ink);">No Questions Found</h3>
        <p class="muted" style="max-width:440px;margin:0 auto 18px;font-size:13.5px;">
            <?php echo $search_term ? 'No questions match "' . htmlspecialchars($search_term) . '".' : ($current_filter === 'unanswered' ? 'Great job! You have answered all incoming customer inquiries.' : 'No customer questions have been asked yet. You can create pre-emptive FAQs to answer common questions in advance.'); ?>
        </p>
        <button type="button" class="btn btn-primary" onclick="toggleFaqBox()">+ Create a Pinned FAQ</button>
    </div>
<?php endif; ?>

<script>
// Collapsible question card accordion
function toggleQuestionCard(header) {
    const card = header.closest('.collapsible-card');
    const content = card.querySelector('.collapsible-content');
    const icon = header.querySelector('.collapse-icon');
    const isOpen = content.style.maxHeight && content.style.maxHeight !== '0px';
    
    // Close all other question cards
    document.querySelectorAll('.collapsible-card').forEach(otherCard => {
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
    
    // Toggle current question card
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

// Initialize all question cards as collapsed on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.collapsible-content').forEach(content => {
        content.style.maxHeight = '0';
        content.style.opacity = '0';
        content.style.overflow = 'hidden';
        content.style.transition = 'max-height 0.4s ease, opacity 0.3s ease, margin-top 0.3s ease';
        content.style.marginTop = '0';
    });
});

function toggleFaqBox() {
    var b = document.getElementById('addFaqBox');
    if (!b) return;
    if (b.style.display === 'none' || b.style.display === '') {
        b.style.display = 'block';
        b.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var qInput = document.getElementById('faqQuestionInput');
        if (qInput) qInput.focus();
    } else {
        b.style.display = 'none';
    }
}

function seedFaq(q, a) {
    var b = document.getElementById('addFaqBox');
    if (b && b.style.display === 'none') {
        b.style.display = 'block';
    }
    var qInput = document.getElementById('faqQuestionInput');
    var aInput = document.getElementById('faqAnswerInput');
    if (qInput) qInput.value = q;
    if (aInput) aInput.value = a;
    if (aInput) aInput.focus();
}

function toggleReplyBox(qid) {
    var box = document.getElementById('replyBox-' + qid);
    if (!box) return;
    box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
}
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
