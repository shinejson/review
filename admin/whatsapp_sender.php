<?php
/**
 * ============================================================
 *  Admin — "Ask for Reviews" WhatsApp Sender
 * ============================================================
 *  Enables business owners to quickly send pre-filled, personalized
 *  review invitation links to customers over WhatsApp after a visit,
 *  order, or service delivery.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requireTeamAccess('whatsapp');

$tenant_id = getTenantId();
$is_tenant = isTenant();

// Ensure review_invites table is ready
ensureInviteTable($conn);

// Load tenant profile & plan
$tenant = null;
if ($tenant_id) {
    $t = $conn->prepare("SELECT t.*, p.plan_name FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id=p.id WHERE t.id=?");
    $t->bind_param("i", $tenant_id);
    $t->execute();
    $tenant = $t->get_result()->fetch_assoc();
    $t->close();
}

// Fetch primary company profile from customers table
$company_profile = null;
if ($tenant_id) {
    $cp = $conn->prepare("SELECT c.*, cat.name AS category_name FROM customers c LEFT JOIN categories cat ON c.category_id=cat.id WHERE c.tenant_id=? ORDER BY c.id ASC LIMIT 1");
    $cp->bind_param("i", $tenant_id);
    $cp->execute();
    $company_profile = $cp->get_result()->fetch_assoc();
    $cp->close();
}

$company_id   = (int)($company_profile['id'] ?? 0);
$brand_name   = !empty($company_profile['company_name']) ? $company_profile['company_name'] : ($tenant['company_name'] ?? 'Your Business');
$brand_logo   = $tenant['logo'] ?? '';

// Build base public review URL with tenant name
if ($company_id > 0) {
    $base_url = getCompanyPublicRatingUrl($company_id, $brand_name);
} else {
    $base_url = getCompanyPublicRatingUrl(0, $brand_name, ['tenant' => (int)$tenant_id]);
}

// Handle AJAX or POST to log an invite
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'log_invite') {
    $cname  = sanitize($_POST['customer_name'] ?? '');
    $cphone = sanitize($_POST['customer_phone'] ?? '');
    $oref   = sanitize($_POST['order_ref'] ?? '');
    $tkey   = sanitize($_POST['template_key'] ?? 'retail_service');
    $msg    = trim($_POST['message'] ?? '');

    $ok = logReviewInvite($conn, $tenant_id, $cname, $cphone, $oref, $tkey, $msg);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }
    header('Location: whatsapp_sender.php?logged=1');
    exit;
}

// Stats
$total_invites = 0;
$inv_stat = $conn->prepare("SELECT COUNT(*) cnt FROM review_invites WHERE tenant_id=?");
if ($inv_stat) {
    $inv_stat->bind_param("i", $tenant_id);
    $inv_stat->execute();
    $total_invites = (int)($inv_stat->get_result()->fetch_assoc()['cnt'] ?? 0);
    $inv_stat->close();
}

$total_reviews = 0;
$avg_score     = 5.0;
if ($company_id > 0) {
    $st = $conn->prepare("SELECT COUNT(*) cnt, AVG(rating) avg FROM ratings WHERE company_id=?");
    if ($st) {
        $st->bind_param("i", $company_id);
        $st->execute();
        $stat = $st->get_result()->fetch_assoc();
        $st->close();
        $total_reviews = (int)($stat['cnt'] ?? 0);
        if ($total_reviews > 0 && !empty($stat['avg'])) {
            $avg_score = round((float)$stat['avg'], 1);
        }
    }
}

// Load default templates
$templates = getWhatsAppTemplates($brand_name, $base_url);
$recent_invites = getRecentInvites($conn, $tenant_id, 10);

// Fetch incoming customer reviews (strictly immutable & read-only)
$recent_reviews = [];
if ($company_id > 0) {
    $r_stmt = $conn->prepare("SELECT r.*, c.company_name FROM ratings r JOIN customers c ON r.company_id = c.id WHERE r.company_id = ? ORDER BY r.created_at DESC LIMIT 25");
    if ($r_stmt) {
        $r_stmt->bind_param("i", $company_id);
        $r_stmt->execute();
        $recent_reviews = $r_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $r_stmt->close();
    }
} elseif ($tenant_id > 0) {
    $r_stmt = $conn->prepare("SELECT r.*, c.company_name FROM ratings r JOIN customers c ON r.company_id = c.id WHERE c.tenant_id = ? ORDER BY r.created_at DESC LIMIT 25");
    if ($r_stmt) {
        $r_stmt->bind_param("i", $tenant_id);
        $r_stmt->execute();
        $recent_reviews = $r_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $r_stmt->close();
    }
}

$BASE      = '../';
$pageTitle = 'Ask for Reviews (WhatsApp)';
$activeNav = 'whatsapp_sender';
include __DIR__ . '/_shell.php';
?>

<!-- Header -->
<div class="welcome-row" style="margin-bottom:24px;">
    <div>
        <p class="eyebrow">Customer Acquisition &middot; WhatsApp Review Invitations</p>
        <h1 style="margin:0;">"Ask for Reviews" WhatsApp Sender</h1>
        <p class="muted" style="margin-top:6px;">Send personalized WhatsApp review requests with 1 tap. Pre-fills customer name and order details for maximum response rates.</p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <a href="qr_stand.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;">
            ◫ Counter QR Stand
        </a>
        <a href="<?php echo htmlspecialchars($base_url); ?>" target="_blank" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;">
            ↗ View Rating Page
        </a>
    </div>
</div>

<!-- Metrics Bar -->
<div class="metric-grid" style="margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon green">💬</div>
        <span>Invites Dispatched</span>
        <strong><?php echo number_format($total_invites); ?></strong>
        <small>WhatsApp invitations logged</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon amber">★</div>
        <span>Total Reviews</span>
        <strong><?php echo number_format($total_reviews); ?></strong>
        <small>All-time feedback collected</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon lime">✓</div>
        <span>Average Score</span>
        <strong><?php echo $total_reviews > 0 ? $avg_score . ' / 5.0' : '—'; ?></strong>
        <small>Customer satisfaction rating</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon purple">⚡</div>
        <span>Review Link Mode</span>
        <strong style="font-size:16px;">1-Tap Pre-fill</strong>
        <small>Auto-populates customer name</small>
    </div>
</div>

<!-- Main Builder Layout: 2 Columns -->
<div class="grid-2col" style="align-items:start;gap:24px;">

    <!-- Left Column: Form & Template Controls -->
    <div class="form-card" style="padding:26px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--line);">
            <div>
                <h3 style="margin:0;font-size:16px;color:var(--ink);">1. Customer &amp; Order Details</h3>
                <p class="muted" style="margin:3px 0 0;font-size:12.5px;">Enter recipient information to personalize the message and review page.</p>
            </div>
            <button type="button" class="btn btn-secondary" onclick="resetForm()" style="padding:4px 10px;font-size:11px;">Clear Form</button>
        </div>

        <div class="form-grid" style="gap:14px;">
            <div class="form-group">
                <label for="custName" style="font-weight:700;font-size:13px;display:flex;justify-content:space-between;">
                    <span>Customer Name *</span>
                    <span class="muted" style="font-size:11px;font-weight:normal;">e.g. Kofi, Abena, John</span>
                </label>
                <input type="text" id="custName" placeholder="e.g. Kofi Mensah" oninput="updateInviteLive()" style="font-size:14px;">
            </div>

            <div class="form-group">
                <label for="custPhone" style="font-weight:700;font-size:13px;display:flex;justify-content:space-between;">
                    <span>Customer WhatsApp Number *</span>
                    <span class="muted" style="font-size:11px;font-weight:normal;">Ghana or International</span>
                </label>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:9px 10px;font-size:13px;font-weight:700;color:var(--ink);white-space:nowrap;">🇬🇭 +233</span>
                    <input type="text" id="custPhone" placeholder="024 123 4567 or 241234567" oninput="updateInviteLive()" style="font-size:14px;flex:1;">
                </div>
            </div>

            <div class="form-group" style="grid-column:1/-1;">
                <label for="orderRef" style="font-weight:700;font-size:13px;display:flex;justify-content:space-between;">
                    <span>Order / Receipt / Bill # (Optional)</span>
                    <span class="muted" style="font-size:11px;font-weight:normal;">Helps customer verify with MoMo / receipt</span>
                </label>
                <input type="text" id="orderRef" placeholder="e.g. #ORD-892 or MOMO-91823" oninput="updateInviteLive()" style="font-size:13px;">
            </div>
        </div>

        <!-- Template Selector -->
        <div class="collapsible-card ws-panel" style="margin-top:22px;">
            <div class="collapsible-header" onclick="toggleSection(this)" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;cursor:pointer;">
                <h3 style="margin:0;font-size:15px;color:var(--ink);">2. Select Proven Message Template</h3>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="muted" style="font-size:11.5px;">Click to switch</span>
                    <span class="collapse-icon ws-collapse-icon">▼</span>
                </div>
            </div>

            <div class="collapsible-content">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;" id="templateButtons">
                <?php foreach ($templates as $key => $tmpl): ?>
                    <div class="template-choice-card <?php echo $key === 'retail_service' ? 'active' : ''; ?>" 
                         data-key="<?php echo htmlspecialchars($key); ?>"
                         onclick="selectTemplate('<?php echo htmlspecialchars($key); ?>')">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <strong style="font-size:13px;color:var(--ink);"><?php echo htmlspecialchars($tmpl['title']); ?></strong>
                            <span class="tmpl-badge"><?php echo htmlspecialchars($tmpl['badge']); ?></span>
                        </div>
                        <p class="muted" style="margin:0;font-size:11px;line-height:1.3;"><?php echo htmlspecialchars($tmpl['subtitle']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            </div>
        </div>

        <!-- Custom Message Editor -->
        <div class="collapsible-card ws-panel" style="margin-top:16px;">
            <div class="collapsible-header" onclick="toggleSection(this)" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;cursor:pointer;">
                <label for="customMessage" style="font-weight:700;font-size:13px;">3. Edit WhatsApp Message</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="muted" style="font-size:11px;" id="charCount">0 chars</span>
                    <span class="collapse-icon ws-collapse-icon">▼</span>
                </div>
            </div>

            <div class="collapsible-content">
            <textarea id="customMessage" class="ws-textarea" rows="6" oninput="onMessageCustomized()"></textarea>

            <!-- Quick Token Chips -->
            <div style="display:flex;align-items:center;gap:6px;margin-top:8px;flex-wrap:wrap;">
                <span class="muted" style="font-size:11px;">Insert variable:</span>
                <button type="button" class="token-btn" onclick="insertToken('{name}')">{name}</button>
                <button type="button" class="token-btn" onclick="insertToken('{business}')">{business}</button>
                <button type="button" class="token-btn" onclick="insertToken('{link}')">{link}</button>
                <button type="button" class="token-btn" onclick="insertToken('{order}')">{order}</button>
            </div>
            </div>
        </div>

    </div>

    <!-- Right Column: Live WhatsApp Chat Bubble Preview & Action Buttons -->
    <div style="display:flex;flex-direction:column;gap:18px;">

        <!-- WhatsApp Chat Simulation Card -->
        <div class="form-card wa-sim-card" style="padding:0;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.06);background:#e5ddd5;">
            
            <!-- WhatsApp Chat Bar Header -->
            <div style="background:#075e54;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;color:#fff;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:38px;height:38px;border-radius:50%;background:#128c7e;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:bold;color:#fff;overflow:hidden;">
                        <?php if (!empty($brand_logo)): ?>
                            <img src="../<?php echo htmlspecialchars(ltrim($brand_logo, '/')); ?>" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                            ★
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-size:14.5px;font-weight:700;line-height:1.2;" id="previewRecipientTitle">
                            <?php echo htmlspecialchars($brand_name); ?>
                        </div>
                        <div style="font-size:11px;opacity:0.85;">WhatsApp Live Preview</div>
                    </div>
                </div>
                <div style="display:flex;gap:14px;font-size:16px;opacity:0.9;">
                    <span>📞</span>
                    <span>⋮</span>
                </div>
            </div>

            <!-- WhatsApp Wallpaper Area with Chat Bubble -->
            <div style="padding:22px 16px;min-height:260px;background:#efeae2;background-image:radial-gradient(#d1c7b7 1px, transparent 1px);background-size:16px 16px;">
                
                <div class="wa-bubble">
                    <div id="waBubbleText" style="white-space:pre-wrap;word-break:break-word;color:#111b21;font-size:13.5px;line-height:1.5;"></div>

                    <!-- Link Preview Box -->
                    <div class="wa-link-preview">
                        <div style="font-size:11px;text-transform:uppercase;color:#54656f;font-weight:700;margin-bottom:2px;">Optibiz Customer Rating</div>
                        <strong style="font-size:13px;color:#111b21;display:block;" id="waPreviewCardTitle"><?php echo htmlspecialchars($brand_name); ?></strong>
                        <span style="font-size:11.5px;color:#54656f;display:block;margin-top:2px;">★★★★★ 30-second verified customer review form</span>
                    </div>

                    <!-- Timestamp & Read Receipt Checkmarks -->
                    <div style="display:flex;justify-content:flex-end;align-items:center;gap:4px;margin-top:4px;">
                        <span style="font-size:10.5px;color:#667781;" id="waTimestamp"><?php echo date('g:i A'); ?></span>
                        <span style="color:#53bdeb;font-size:11px;font-weight:bold;">✓✓</span>
                    </div>
                </div>

            </div>

            <!-- Pre-filled Link Indicator -->
            <div class="wa-link-bar">
                <div style="min-width:0;flex:1;">
                    <span class="muted" style="font-size:10.5px;text-transform:uppercase;font-weight:700;display:block;">Personalized Review URL:</span>
                    <input type="text" id="livePersonalizedUrl" readonly style="font-family:monospace;font-size:11px;padding:4px 8px;border-radius:6px;border:1px solid var(--line);background:var(--bg);width:100%;color:var(--ink);margin-top:3px;">
                </div>
                <button type="button" class="btn btn-secondary" onclick="copyPersonalizedLink()" style="padding:6px 10px;font-size:11.5px;white-space:nowrap;margin-top:16px;">
                    🔗 Copy Link
                </button>
            </div>

        </div>

        <!-- 1-Click Action Card -->
        <div class="form-card" style="padding:22px;border-left:4px solid #25D366;">
            <h3 style="margin:0 0 12px;font-size:15px;color:var(--ink);">Dispatch via WhatsApp</h3>
            
            <div style="display:flex;flex-direction:column;gap:10px;">
                <button type="button" id="btnSendWaApp" onclick="openWhatsApp('native')" class="btn-wa-action btn-wa-primary">
                    <svg viewBox="0 0 448 512" style="width:18px;height:18px;fill:currentColor;"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
                    Send on WhatsApp ↗ (Mobile / Desktop App)
                </button>

                <button type="button" id="btnSendWaWeb" onclick="openWhatsApp('web')" class="btn-wa-action btn-wa-secondary">
                    💻 Open in WhatsApp Web ↗
                </button>

                <button type="button" onclick="copyFullMessage()" class="btn-wa-action btn-wa-secondary">
                    📋 Copy Full Message to Clipboard
                </button>
            </div>
            
            <div id="statusToast" class="ws-toast">
                ✓ Copied to clipboard!
            </div>
        </div>

    </div>

</div>

<!-- Bottom Data Tables: Reviews Received (Non-editable) & Dispatched Invitations Log -->
<div class="form-card" style="margin-top:28px;padding:24px;">
    <!-- Tab Navigation Bar for Received Reviews vs Dispatched Invites -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;border-bottom:1px solid var(--line);padding-bottom:16px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn <?php echo !empty($recent_reviews) ? 'btn-primary' : 'btn-secondary'; ?>" id="btnTabReviews" onclick="switchWsBottomTab('reviews')" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:13px;font-weight:700;">
                ★ Reviews Received (Feedback That Has Come)
                <span style="background:rgba(255,255,255,0.25);padding:2px 7px;border-radius:99px;font-size:11px;"><?php echo count($recent_reviews); ?></span>
            </button>
            <button type="button" class="btn <?php echo empty($recent_reviews) ? 'btn-primary' : 'btn-secondary'; ?>" id="btnTabInvites" onclick="switchWsBottomTab('invites')" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:13px;font-weight:700;">
                💬 Dispatched WhatsApp Invites
                <span style="background:rgba(255,255,255,0.25);padding:2px 7px;border-radius:99px;font-size:11px;"><?php echo count($recent_invites); ?></span>
            </button>
        </div>
        <div class="ws-auth-pill">
            <span>🔒</span> Review Authenticity Protection Active (Non-Editable)
        </div>
    </div>

    <!-- PANEL 1: REVIEWS THAT HAVE COME IN (PERMANENT & NON-EDITABLE) -->
    <div id="wsPanelReviews" style="<?php echo !empty($recent_reviews) ? 'display:block;' : (empty($recent_invites) ? 'display:block;' : 'display:none;'); ?>">
        <div class="ws-info-banner">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:22px;">🔒</span>
                <div>
                    <strong style="color:var(--ink);font-size:13px;display:block;">Review Records &amp; Reviewer Names are Non-Editable</strong>
                    <span class="muted" style="font-size:12px;">All incoming reviews and reviewer names are permanently locked to preserve genuine proof and prevent tampering.</span>
                </div>
            </div>
            <a href="ratings.php" class="btn btn-secondary" style="font-size:11.5px;padding:5px 12px;text-decoration:none;font-weight:700;">
                ↗ Open Ratings Hub
            </a>
        </div>

        <?php if (!empty($recent_reviews)): ?>
            <div style="overflow-x:auto;">
                <table class="admin-table" style="width:100%;font-size:13px;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;border-bottom:2px solid var(--line);background:var(--bg);">
                            <th style="padding:10px 12px;">Reviewer Name</th>
                            <th style="padding:10px 12px;">Rating</th>
                            <th style="padding:10px 12px;">Customer Review / Feedback</th>
                            <th style="padding:10px 12px;">Ref / Verification</th>
                            <th style="padding:10px 12px;">Date Received</th>
                            <th style="padding:10px 12px;">Integrity Status</th>
                            <th style="padding:10px 12px;text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_reviews as $r): 
                            $r_name = htmlspecialchars($r['customer_name'] ?: 'Verified Customer');
                            $r_score = (int)$r['rating'];
                            $r_comment = $r['comment'] ?: 'Star rating submitted without written comment.';
                            $r_date = date('M d, Y · g:i A', strtotime($r['created_at']));
                        ?>
                            <tr style="border-bottom:1px solid var(--line);">
                                <td style="padding:12px;">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div class="ws-row-avatar">
                                            <?php echo htmlspecialchars(strtoupper(substr($r['customer_name'] ?: 'C', 0, 1))); ?>
                                        </div>
                                        <div>
                                            <strong style="color:var(--ink);font-size:13px;display:block;">
                                                <?php echo $r_name; ?>
                                                <?php if (!empty($r['is_verified'])): ?>
                                                    <span class="ws-verified-pill">✓ Verified</span>
                                                <?php endif; ?>
                                            </strong>
                                            <span class="muted" style="font-size:11px;"><?php echo htmlspecialchars($r['customer_email'] ?: 'Verified Reviewer'); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:12px;white-space:nowrap;">
                                    <span style="color:#f59e0b;font-size:14px;letter-spacing:1px;"><?php echo str_repeat('★', $r_score); ?></span>
                                    <strong style="font-size:12.5px;color:var(--ink);margin-left:4px;"><?php echo $r_score; ?>.0</strong>
                                </td>
                                <td style="padding:12px;max-width:280px;">
                                    <span style="font-size:12.5px;color:var(--ink);display:block;line-height:1.4;">
                                        &ldquo;<?php echo htmlspecialchars(mb_strlen($r_comment) > 85 ? mb_substr($r_comment, 0, 85) . '…' : $r_comment); ?>&rdquo;
                                    </span>
                                </td>
                                <td class="ws-cell-dim">
                                    <?php if (!empty($r['momo_ref'])): ?>
                                        <code class="ws-momo">MoMo: <?php echo htmlspecialchars($r['momo_ref']); ?></code>
                                    <?php elseif (!empty($r['receipt_photo'])): ?>
                                        <a href="../<?php echo htmlspecialchars($r['receipt_photo']); ?>" target="_blank" rel="noopener" style="color:#0284c7;font-size:11px;text-decoration:underline;">📎 Receipt</a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ws-cell-dim">
                                    <?php echo $r_date; ?>
                                </td>
                                <td style="padding:12px;white-space:nowrap;">
                                    <span class="tmpl-badge ws-lockbadge">
                                        🔒 Non-editable
                                    </span>
                                </td>
                                <td style="padding:12px;text-align:right;white-space:nowrap;">
                                    <button type="button" class="btn btn-secondary" style="padding:4px 10px;font-size:11px;" 
                                            onclick="showWsReviewModal(<?php echo htmlspecialchars(json_encode($r['customer_name'])); ?>, <?php echo (int)$r['rating']; ?>, <?php echo htmlspecialchars(json_encode($r_comment)); ?>, <?php echo htmlspecialchars(json_encode($r_date)); ?>)">
                                        👁 View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ws-empty">
                <span style="font-size:32px;display:block;margin-bottom:8px;">★</span>
                <strong style="color:var(--ink);font-size:14.5px;display:block;">No customer reviews submitted yet</strong>
                <p class="muted" style="font-size:12.5px;max-width:420px;margin:4px auto 0;">Send personalized WhatsApp review invitations above. As customers submit their ratings and feedback, they will appear here as tamper-proof, non-editable records.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- PANEL 2: DISPATCHED INVITATIONS LOG -->
    <div id="wsPanelInvites" style="<?php echo empty($recent_reviews) && !empty($recent_invites) ? 'display:block;' : 'display:none;'; ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
            <p class="muted" style="margin:0;font-size:12.5px;">Track which customers have been sent review invitations so your team doesn't double-ask.</p>
            <span class="muted" style="font-size:12px;">Showing last <?php echo count($recent_invites); ?> logged invites</span>
        </div>

        <?php if (!empty($recent_invites)): ?>
            <div style="overflow-x:auto;">
                <table class="admin-table" style="width:100%;font-size:13px;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;border-bottom:2px solid var(--line);background:var(--bg);">
                            <th style="padding:10px 12px;">Customer</th>
                            <th style="padding:10px 12px;">Phone Number</th>
                            <th style="padding:10px 12px;">Order Ref</th>
                            <th style="padding:10px 12px;">Template</th>
                            <th style="padding:10px 12px;">Date &amp; Time Sent</th>
                            <th style="padding:10px 12px;text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_invites as $inv): ?>
                            <tr style="border-bottom:1px solid var(--line);">
                                <td style="padding:12px;">
                                    <strong><?php echo htmlspecialchars($inv['customer_name'] ?: 'Customer'); ?></strong>
                                </td>
                                <td style="padding:12px;font-family:monospace;font-size:12px;">
                                    <?php echo htmlspecialchars(whatsappDisplay($inv['customer_phone'])); ?>
                                </td>
                                <td class="ws-cell-dim">
                                    <?php echo !empty($inv['order_ref']) ? htmlspecialchars($inv['order_ref']) : '—'; ?>
                                </td>
                                <td style="padding:12px;">
                                    <span class="tmpl-badge">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $inv['template_key']))); ?>
                                    </span>
                                </td>
                                <td class="ws-cell-dim">
                                    <?php echo date('M d, Y · g:i A', strtotime($inv['sent_at'])); ?>
                                </td>
                                <td style="padding:12px;text-align:right;">
                                    <button type="button" class="btn btn-secondary" style="padding:4px 10px;font-size:11.5px;"
                                            onclick="repopulateInvite(<?php echo htmlspecialchars(json_encode($inv['customer_name'])); ?>, <?php echo htmlspecialchars(json_encode($inv['customer_phone'])); ?>, <?php echo htmlspecialchars(json_encode($inv['order_ref'])); ?>, <?php echo htmlspecialchars(json_encode($inv['template_key'])); ?>)">
                                        ↺ Re-send
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ws-empty">
                <span style="font-size:32px;display:block;margin-bottom:8px;">💬</span>
                <strong style="color:var(--ink);font-size:14.5px;display:block;">No customer review invites sent yet</strong>
                <p class="muted" style="font-size:12.5px;max-width:400px;margin:4px auto 0;">Use the sender above to invite your first customer via WhatsApp. Invites will be recorded here automatically.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Read-Only Review Detail Modal -->
<div id="wsReviewModal" class="ws-modal">
    <div class="ws-modal-card">
        <div class="ws-modal-head">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:18px;">🔒</span>
                <div>
                    <h3 style="margin:0;font-size:15px;font-weight:700;color:var(--ink);">Review Details (Locked Record)</h3>
                    <span class="ws-modal-sub">Reviewer name &amp; feedback cannot be modified</span>
                </div>
            </div>
            <button type="button" class="ws-modal-close" onclick="closeWsReviewModal()">&times;</button>
        </div>
        <div style="padding:22px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                <div id="wsModalAvatar" class="ws-modal-avatar">A</div>
                <div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span id="wsModalName" class="ws-modal-name">Customer Name</span>
                        <span class="ws-modal-lockpill">🔒 Non-editable</span>
                    </div>
                    <span id="wsModalDate" class="ws-modal-sub">Date</span>
                </div>
            </div>
            
            <div style="margin-bottom:14px;">
                <label class="ws-modal-label">Rating Given</label>
                <div id="wsModalStars" style="font-size:18px;color:#f59e0b;letter-spacing:2px;">★★★★★</div>
            </div>

            <div style="margin-bottom:16px;">
                <label class="ws-modal-label" style="margin-bottom:6px;">Customer Feedback</label>
                <div id="wsModalComment" class="ws-modal-comment">
                </div>
            </div>

            <div class="ws-modal-locknote">
                <span style="font-size:16px;">🛡️</span>
                <span>Reviewer name and feedback are locked permanently to guarantee authentic customer proof.</span>
            </div>
        </div>
        <div class="ws-modal-foot">
            <button type="button" class="btn btn-secondary" onclick="closeWsReviewModal()" style="padding:7px 18px;font-size:12.5px;">Close</button>
        </div>
    </div>
</div>

<style>
.ws-panel {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 18px;
    background: #f8fafc;
}
.ws-textarea {
    width: 100%;
    font-size: 13px;
    line-height: 1.5;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-family: inherit;
    resize: vertical;
    background: #ffffff;
    color: var(--ink);
}
.ws-empty {
    text-align: center;
    padding: 36px 20px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
}
.ws-collapse-icon { font-size: 18px; color: #64748b; transition: transform 0.3s; }
.ws-cell-dim { padding: 12px; color: #64748b; font-size: 12px; white-space: nowrap; }
.ws-momo { background: var(--bg); padding: 2px 6px; border-radius: 4px; font-size: 11px; }
.ws-lockbadge {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
    font-size: 10.5px;
}
.ws-auth-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 99px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    font-size: 12px;
    color: #15803d;
    font-weight: 700;
}
.ws-info-banner {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #10b981;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.ws-row-avatar {
    width: 30px; height: 30px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #334155;
    font-weight: 700;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ws-verified-pill {
    font-size: 10px;
    font-weight: 700;
    color: #16a34a;
    background: rgba(22,163,74,0.12);
    padding: 1px 6px;
    border-radius: 99px;
    margin-left: 4px;
}
.ws-toast {
    display: none;
    margin-top: 12px;
    padding: 8px 12px;
    border-radius: 6px;
    background: #dcfce7;
    color: #166534;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
}
.template-choice-card {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 10px 12px;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.2s ease;
}
.template-choice-card:hover {
    border-color: #25D366;
    background: #f0fdf4;
}
.template-choice-card.active {
    border-color: #25D366;
    background: #f0fdf4;
    box-shadow: 0 0 0 2px rgba(37, 211, 102, 0.25);
}
.tmpl-badge {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 99px;
    background: #dcfce7;
    color: #15803d;
}
.token-btn {
    background: var(--bg, #f1f5f9);
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 2px 7px;
    font-size: 11px;
    font-family: monospace;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    transition: all 0.15s;
}
.token-btn:hover {
    background: #e2e8f0;
    color: var(--ink);
}
.wa-bubble {
    background: #dcf8c6;
    border-radius: 8px 8px 0 8px;
    padding: 10px 12px;
    max-width: 88%;
    margin-left: auto;
    box-shadow: 0 1px 2px rgba(0,0,0,0.15);
    position: relative;
}
.wa-link-preview {
    background: rgba(0,0,0,0.04);
    border-left: 3px solid #25D366;
    padding: 8px 10px;
    margin-top: 8px;
    border-radius: 4px;
}
.wa-sim-card { border: 1px solid #cbd5e1; }
.wa-link-bar {
    background: #ffffff;
    padding: 12px 16px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
/* Review detail modal — theme aware */
.ws-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15,23,42,0.65);
    z-index: 99999;
    backdrop-filter: blur(3px);
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.ws-modal-card {
    background: #ffffff;
    border-radius: 16px;
    max-width: 520px;
    width: 100%;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    overflow: hidden;
    border: 1px solid #e2e8f0;
    color: var(--ink);
}
.ws-modal-head {
    padding: 18px 22px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
}
.ws-modal-sub { font-size: 11.5px; color: #64748b; }
.ws-modal-close {
    background: none;
    border: none;
    font-size: 22px;
    color: #64748b;
    cursor: pointer;
    line-height: 1;
    padding: 4px;
}
.ws-modal-avatar {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: #e0f2fe;
    color: #0369a1;
    font-weight: 700;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ws-modal-name { font-size: 15px; font-weight: 700; color: #0f172a; }
.ws-modal-lockpill {
    background: #f1f5f9;
    color: #475569;
    border: 1px solid #cbd5e1;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 99px;
}
.ws-modal-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    display: block;
    margin-bottom: 4px;
}
.ws-modal-comment {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
    font-size: 13.5px;
    line-height: 1.6;
    color: #1e293b;
    max-height: 180px;
    overflow-y: auto;
    white-space: pre-wrap;
}
.ws-modal-locknote {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #166534;
}
.ws-modal-foot {
    padding: 14px 22px;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    justify-content: flex-end;
}
.btn-wa-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    border: none;
    text-decoration: none;
    width: 100%;
    transition: all 0.2s ease;
}
.btn-wa-primary {
    background: #25D366;
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(37, 211, 102, 0.35);
}
.btn-wa-primary:hover {
    background: #1fb457;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(37, 211, 102, 0.45);
}
.btn-wa-secondary {
    background: #ffffff;
    color: var(--ink);
    border: 1px solid #cbd5e1;
}
.btn-wa-secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
/* ---------- Dark mode ---------- */
:root[data-theme='dark'] .ws-panel {
    background: rgba(255,255,255,.03);
    border-color: rgba(255,255,255,.1);
}
:root[data-theme='dark'] .ws-textarea {
    background: rgba(255,255,255,.05);
    border-color: rgba(255,255,255,.12);
    color: #e2e8f0;
}
:root[data-theme='dark'] .ws-textarea::placeholder { color: #64748b; }
:root[data-theme='dark'] .ws-empty {
    background: rgba(255,255,255,.03);
    border-color: rgba(255,255,255,.14);
}
:root[data-theme='dark'] .template-choice-card {
    background: rgba(255,255,255,.04);
    border-color: rgba(255,255,255,.1);
}
:root[data-theme='dark'] .template-choice-card:hover,
:root[data-theme='dark'] .template-choice-card.active {
    background: rgba(37,211,102,.1);
    border-color: #25D366;
}
:root[data-theme='dark'] .tmpl-badge {
    background: rgba(37,211,102,.15);
    color: #4ade80;
}
:root[data-theme='dark'] .token-btn {
    background: rgba(255,255,255,.05);
    border-color: rgba(255,255,255,.12);
    color: #cbd5e1;
}
:root[data-theme='dark'] .ws-auth-pill {
    background: rgba(37,211,102,.1);
    border-color: rgba(37,211,102,.3);
    color: #4ade80;
}
:root[data-theme='dark'] .ws-info-banner {
    background: rgba(255,255,255,.03);
    border-color: rgba(255,255,255,.08);
    border-left-color: #10b981;
}
:root[data-theme='dark'] .ws-row-avatar {
    background: rgba(255,255,255,.1);
    color: #e2e8f0;
}
:root[data-theme='dark'] .ws-verified-pill {
    background: rgba(37,211,102,.15);
    color: #4ade80;
}
:root[data-theme='dark'] .ws-toast {
    background: rgba(37,211,102,.15);
    color: #4ade80;
}
:root[data-theme='dark'] .ws-collapse-icon { color: #94a3b8; }
:root[data-theme='dark'] .ws-cell-dim { color: #94a3b8; }
:root[data-theme='dark'] .ws-momo {
    background: rgba(255,255,255,.06);
    color: #e2e8f0;
}
:root[data-theme='dark'] .ws-lockbadge {
    background: rgba(255,255,255,.06);
    color: #cbd5e1;
    border-color: rgba(255,255,255,.12);
}
:root[data-theme='dark'] .token-btn:hover { background: rgba(255,255,255,.1); }
:root[data-theme='dark'] .wa-sim-card { border-color: rgba(255,255,255,.1); }
:root[data-theme='dark'] .wa-link-bar {
    background: #0b1c2b;
    border-top-color: rgba(255,255,255,.08);
}
:root[data-theme='dark'] .ws-modal-card {
    background: #0f1f2e;
    border-color: rgba(255,255,255,.1);
    box-shadow: 0 25px 50px -12px rgba(0,0,0,.6);
}
:root[data-theme='dark'] .ws-modal-head,
:root[data-theme='dark'] .ws-modal-foot {
    background: rgba(255,255,255,.03);
    border-color: rgba(255,255,255,.08);
}
:root[data-theme='dark'] .ws-modal-sub { color: #94a3b8; }
:root[data-theme='dark'] .ws-modal-close { color: #94a3b8; }
:root[data-theme='dark'] .ws-modal-avatar {
    background: rgba(56,189,248,.15);
    color: #38bdf8;
}
:root[data-theme='dark'] .ws-modal-name { color: #e2e8f0; }
:root[data-theme='dark'] .ws-modal-lockpill {
    background: rgba(255,255,255,.06);
    color: #cbd5e1;
    border-color: rgba(255,255,255,.12);
}
:root[data-theme='dark'] .ws-modal-label { color: #94a3b8; }
:root[data-theme='dark'] .ws-modal-comment {
    background: rgba(255,255,255,.04);
    border-color: rgba(255,255,255,.1);
    color: #e2e8f0;
}
:root[data-theme='dark'] .ws-modal-locknote {
    background: rgba(37,211,102,.1);
    border-color: rgba(37,211,102,.3);
    color: #4ade80;
}
:root[data-theme='dark'] .btn-wa-secondary {
    background: rgba(255,255,255,.05);
    border-color: rgba(255,255,255,.12);
    color: #e2e8f0;
}
:root[data-theme='dark'] .btn-wa-secondary:hover {
    background: rgba(255,255,255,.1);
    border-color: rgba(255,255,255,.2);
}
</style>

<script>
var basePublicUrl = <?php echo json_encode($base_url); ?>;
var businessName  = <?php echo json_encode($brand_name); ?>;
var templates     = <?php echo json_encode($templates); ?>;
var currentKey    = 'retail_service';
var isCustomized  = false;

function getNormalizedDigits(raw) {
    var digits = String(raw || '').replace(/\D+/g, '');
    if (digits.indexOf('00') === 0) digits = digits.slice(2);
    if (digits.charAt(0) === '0' && digits.length >= 9 && digits.length <= 10) {
        digits = '233' + digits.slice(1);
    }
    return digits;
}

function buildPersonalizedUrl() {
    var name = (document.getElementById('custName').value || '').trim();
    var ref  = (document.getElementById('orderRef').value || '').trim();
    var sep = basePublicUrl.indexOf('?') !== -1 ? '&' : '?';
    var url = basePublicUrl + sep + 'ref=wa';
    if (name) url += '&name=' + encodeURIComponent(name);
    if (ref)  url += '&ref_code=' + encodeURIComponent(ref);
    return url;
}

function compileTemplateText(templateStr) {
    var name = (document.getElementById('custName').value || '').trim();
    var ref  = (document.getElementById('orderRef').value || '').trim();
    var link = buildPersonalizedUrl();

    var nameSalutation = name ? ' ' + name : '';
    var orderNote = ref ? ' (' + ref + ')' : '';

    return templateStr
        .replace(/\{name\}/g, nameSalutation)
        .replace(/\{business\}/g, businessName)
        .replace(/\{link\}/g, link)
        .replace(/\{order\}/g, orderNote);
}

function selectTemplate(key) {
    currentKey = key;
    isCustomized = false;

    // Update active button state
    document.querySelectorAll('.template-choice-card').forEach(function(card) {
        card.classList.toggle('active', card.getAttribute('data-key') === key);
    });

    updateInviteLive();
}

function onMessageCustomized() {
    isCustomized = true;
    updateInviteLive();
}

function insertToken(token) {
    var textarea = document.getElementById('customMessage');
    var start = textarea.selectionStart;
    var end   = textarea.selectionEnd;
    var val   = textarea.value;
    textarea.value = val.substring(0, start) + token + val.substring(end);
    textarea.selectionStart = textarea.selectionEnd = start + token.length;
    textarea.focus();
    isCustomized = true;
    updateInviteLive();
}

function updateInviteLive() {
    var pUrl = buildPersonalizedUrl();
    document.getElementById('livePersonalizedUrl').value = pUrl;

    var name = (document.getElementById('custName').value || '').trim();
    document.getElementById('previewRecipientTitle').innerText = name ? 'Chat with ' + name : businessName;

    var textarea = document.getElementById('customMessage');
    var rawText;

    if (!isCustomized) {
        var rawTmpl = (templates[currentKey] && templates[currentKey].template) ? templates[currentKey].template : '';
        textarea.value = rawTmpl;
        rawText = compileTemplateText(rawTmpl);
    } else {
        rawText = compileTemplateText(textarea.value);
    }

    // Update WhatsApp Chat Bubble
    document.getElementById('waBubbleText').innerText = rawText;
    document.getElementById('charCount').innerText = rawText.length + ' chars';
}

function openWhatsApp(mode) {
    var phoneInput = document.getElementById('custPhone').value.trim();
    var digits = getNormalizedDigits(phoneInput);

    if (!digits) {
        alert('Please enter a valid customer WhatsApp phone number.');
        document.getElementById('custPhone').focus();
        return;
    }

    var text = document.getElementById('waBubbleText').innerText;
    var url = '';

    if (mode === 'web') {
        url = 'https://web.whatsapp.com/send?phone=' + encodeURIComponent(digits) + '&text=' + encodeURIComponent(text);
    } else {
        url = 'https://wa.me/' + encodeURIComponent(digits) + '?text=' + encodeURIComponent(text);
    }

    // Log invite to database in background
    logInviteToDb();

    // Open WhatsApp
    window.open(url, '_blank');
}

function logInviteToDb() {
    var name  = (document.getElementById('custName').value || '').trim();
    var phone = document.getElementById('custPhone').value.trim();
    var ref   = (document.getElementById('orderRef').value || '').trim();
    var text  = document.getElementById('waBubbleText').innerText;

    var fd = new FormData();
    fd.append('action', 'log_invite');
    fd.append('customer_name', name);
    fd.append('customer_phone', phone);
    fd.append('order_ref', ref);
    fd.append('template_key', currentKey);
    fd.append('message', text);

    fetch('whatsapp_sender.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    }).catch(function(err) {
        console.warn('Could not log invite:', err);
    });
}

function copyFullMessage() {
    var text = document.getElementById('waBubbleText').innerText;
    copyTextToClipboard(text, 'Full WhatsApp message copied!');
    logInviteToDb();
}

function copyPersonalizedLink() {
    var link = document.getElementById('livePersonalizedUrl').value;
    copyTextToClipboard(link, 'Personalized review link copied!');
}

function copyTextToClipboard(str, toastMsg) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(str).then(function() { showToast(toastMsg); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = str;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast(toastMsg);
    }
}

function showToast(msg) {
    var t = document.getElementById('statusToast');
    if (!t) return;
    t.innerText = '✓ ' + msg;
    t.style.display = 'block';
    setTimeout(function() {
        t.style.display = 'none';
    }, 2500);
}

function resetForm() {
    document.getElementById('custName').value = '';
    document.getElementById('custPhone').value = '';
    document.getElementById('orderRef').value = '';
    selectTemplate('retail_service');
}

function repopulateInvite(name, phone, ref, tmplKey) {
    document.getElementById('custName').value = name || '';
    document.getElementById('custPhone').value = phone || '';
    document.getElementById('orderRef').value = ref || '';
    selectTemplate(tmplKey || 'retail_service');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Initialize live state on DOM load
document.addEventListener('DOMContentLoaded', function() {
    selectTemplate('retail_service');
    
    // Initialize collapsible sections
    document.querySelectorAll('.collapsible-content').forEach(content => {
        content.style.maxHeight = '0';
        content.style.opacity = '0';
        content.style.overflow = 'hidden';
        content.style.transition = 'max-height 0.4s ease, opacity 0.3s ease, margin-top 0.3s ease';
        content.style.marginTop = '0';
    });
});

// Collapsible accordion functionality
function toggleSection(header) {
    const card = header.closest('.collapsible-card');
    const content = card.querySelector('.collapsible-content');
    const icon = header.querySelector('.collapse-icon');
    const isOpen = content.style.maxHeight && content.style.maxHeight !== '0px';
    
    // Close all other sections
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
    
    // Toggle current section
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

function switchWsBottomTab(tab) {
    var pReviews = document.getElementById('wsPanelReviews');
    var pInvites = document.getElementById('wsPanelInvites');
    var btnReviews = document.getElementById('btnTabReviews');
    var btnInvites = document.getElementById('btnTabInvites');
    
    if (tab === 'reviews') {
        if (pReviews) pReviews.style.display = 'block';
        if (pInvites) pInvites.style.display = 'none';
        if (btnReviews) {
            btnReviews.className = 'btn btn-primary';
        }
        if (btnInvites) {
            btnInvites.className = 'btn btn-secondary';
        }
    } else {
        if (pReviews) pReviews.style.display = 'none';
        if (pInvites) pInvites.style.display = 'block';
        if (btnReviews) {
            btnReviews.className = 'btn btn-secondary';
        }
        if (btnInvites) {
            btnInvites.className = 'btn btn-primary';
        }
    }
}

function showWsReviewModal(name, score, comment, date) {
    var modal = document.getElementById('wsReviewModal');
    if (!modal) return;
    
    var nameEl = document.getElementById('wsModalName');
    var dateEl = document.getElementById('wsModalDate');
    var starsEl = document.getElementById('wsModalStars');
    var commEl = document.getElementById('wsModalComment');
    var avatarEl = document.getElementById('wsModalAvatar');
    
    var safeName = name || 'Verified Customer';
    if (nameEl) nameEl.innerText = safeName;
    if (dateEl) dateEl.innerText = date || '';
    if (commEl) commEl.innerText = comment || 'No written comments submitted.';
    if (avatarEl) avatarEl.innerText = safeName.charAt(0).toUpperCase();
    
    if (starsEl) {
        var num = parseInt(score, 10) || 5;
        var starStr = '';
        for (var i = 1; i <= 5; i++) {
            starStr += i <= num ? '★' : '☆';
        }
        starsEl.innerText = starStr + ' (' + num + '/5)';
    }
    
    modal.style.display = 'flex';
}

function closeWsReviewModal() {
    var modal = document.getElementById('wsReviewModal');
    if (modal) modal.style.display = 'none';
}
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
