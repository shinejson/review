<?php
/**
 * ============================================================
 *  Optibiz Workspace — SEO Health Checker & Audit Studio
 * ============================================================
 *  100% FREE & UNLIMITED for all workspace tenants:
 *    - Fully responsive with seamless Light Mode & Dark Mode support
 *    - Real-time crawling of company site or competitor URLs
 *    - Circular Health Score & Issue Diagnostics
 *    - Instant Copy-Paste Code Generator (Meta Tags, Schema JSON-LD, Robots.txt)
 *    - Downloadable / Printable Branded Audit Report
 *    - Full Audit History & Progress Tracking
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/seo_checker.php';

requireLogin();
requireTeamAccess('seo');

$tenant_id = getTenantId();
$is_tenant = isTenant();

admin_ensure_schema($conn);
seo_ensure_schema($conn);

$active_company_id = ($is_tenant && $tenant_id) ? getActiveCompanyId($conn, $tenant_id) : 0;

// Fetch company profile to auto-fill website URL
$company_website = '';
$company_name = 'My Business';
if ($is_tenant && $tenant_id) {
    $stmtC = $conn->prepare("SELECT company_name, website FROM customers WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
    if ($stmtC) {
        $stmtC->bind_param("i", $tenant_id);
        $stmtC->execute();
        $rowC = $stmtC->get_result()->fetch_assoc();
        if ($rowC) {
            $company_website = trim((string)($rowC['website'] ?? ''));
            $company_name = trim((string)($rowC['company_name'] ?? 'My Business'));
        }
        $stmtC->close();
    }
}

// Handle AJAX scan request
if (isset($_POST['action']) && $_POST['action'] === 'run_tenant_scan') {
    header('Content-Type: application/json; charset=utf-8');
    $target_url = trim((string)($_POST['url'] ?? ''));

    if ($target_url === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter a website URL to audit.']);
        exit();
    }

    $analysis = seo_analyze_url($target_url);
    if (!empty($analysis['error'])) {
        echo json_encode(['success' => false, 'error' => $analysis['error']]);
        exit();
    }

    // Save as 100% FREE (is_paid = 1)
    $saved = seo_save_audit($conn, $analysis, 'tenant', $tenant_id, 1);

    echo json_encode([
        'success' => true,
        'token' => $saved['token'],
        'data' => $analysis
    ]);
    exit();
}

// Fetch tenant's past audits
$past_audits = [];
$stmtH = $conn->prepare("SELECT id, url, domain, score, grade, issues_count, critical_count, warning_count, response_time_ms, access_token, created_at FROM seo_audits WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 10");
if ($stmtH) {
    $stmtH->bind_param("i", $tenant_id);
    $stmtH->execute();
    $resH = $stmtH->get_result();
    while ($rowH = $resH->fetch_assoc()) {
        $past_audits[] = $rowH;
    }
    $stmtH->close();
}

// If viewing a past scan
$current_audit = null;
if (!empty($_GET['token'])) {
    $current_audit = seo_get_audit($conn, trim($_GET['token']));
} elseif (!empty($past_audits)) {
    // Default to most recent audit
    $current_audit = seo_get_audit($conn, $past_audits[0]['access_token']);
}

$BASE = '../';
$pageTitle = 'SEO Health Checker & Audit';
$activeNav = 'seo_checker';

include __DIR__ . '/_shell.php';
?>

<style>
/* ============================================================
   SEO Studio — Comprehensive Light & Dark Mode Styles
   ============================================================ */
.seo-wrap {
    max-width: 1200px;
    margin: 0 auto;
    padding-bottom: 60px;
}

/* 1. TOP ANNOUNCEMENT BANNER */
.seo-banner {
    border-radius: 16px;
    padding: 18px 24px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    transition: background 0.3s, border-color 0.3s;
}
/* Light Theme Banner */
.seo-banner {
    background: linear-gradient(135deg, #f0fdf4 0%, #ecfccb 100%);
    border: 1px solid #bbf7d0;
    box-shadow: 0 4px 14px rgba(22, 101, 52, 0.05);
}
.seo-banner .banner-title {
    color: #064e3b;
    font-size: 15px;
    font-weight: 800;
}
.seo-banner .banner-desc {
    color: #166534;
    font-size: 13px;
    margin-top: 3px;
    line-height: 1.45;
}
.seo-banner .banner-badge {
    background: #d9f99d;
    color: #365314;
    border: 1px solid #bef264;
    font-size: 11px;
    font-weight: 800;
    padding: 5px 12px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Dark Theme Banner */
:root[data-theme='dark'] .seo-banner {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.15) 0%, rgba(194, 245, 66, 0.12) 100%);
    border-color: rgba(194, 245, 66, 0.3);
    box-shadow: none;
}
:root[data-theme='dark'] .seo-banner .banner-title {
    color: #f8fafc;
}
:root[data-theme='dark'] .seo-banner .banner-desc {
    color: #cbd5e1;
}
:root[data-theme='dark'] .seo-banner .banner-badge {
    background: var(--lime, #c2f542);
    color: #0f172a;
    border-color: transparent;
}

/* 2. BASE CARDS */
.seo-card {
    border-radius: 18px;
    padding: 26px;
    margin-bottom: 24px;
    transition: background 0.3s, border-color 0.3s, box-shadow 0.3s;
}
/* Light Theme Card */
.seo-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
}
.seo-card .card-heading {
    color: #0f172a;
    font-size: 18px;
    font-weight: 800;
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.seo-card .card-subtext {
    color: #475569;
    font-size: 13.5px;
    margin: 0 0 20px;
    line-height: 1.5;
}

/* Dark Theme Card */
:root[data-theme='dark'] .seo-card {
    background: #0f1f2e;
    border-color: rgba(255, 255, 255, 0.08);
    box-shadow: none;
}
:root[data-theme='dark'] .seo-card .card-heading {
    color: #f8fafc;
}
:root[data-theme='dark'] .seo-card .card-subtext {
    color: #94a3b8;
}

/* 3. INPUT FIELD & SCAN BUTTON */
.seo-url-input {
    width: 100%;
    padding: 13px 18px;
    border-radius: 12px;
    font-size: 14.5px;
    font-weight: 500;
    outline: none;
    transition: all 0.2s;
}
/* Light Theme Input */
.seo-url-input {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    color: #0f172a;
}
.seo-url-input::placeholder {
    color: #94a3b8;
}
.seo-url-input:focus {
    background: #ffffff;
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
}

/* Dark Theme Input */
:root[data-theme='dark'] .seo-url-input {
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.15);
    color: #f8fafc;
}
:root[data-theme='dark'] .seo-url-input::placeholder {
    color: #64748b;
}
:root[data-theme='dark'] .seo-url-input:focus {
    background: rgba(255, 255, 255, 0.08);
    border-color: #38bdf8;
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
}

.btn-scan-action {
    background: #c2f542;
    color: #0f172a;
    border: 1px solid #a3e635;
    padding: 13px 26px;
    font-weight: 800;
    font-size: 14.5px;
    border-radius: 12px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    box-shadow: 0 4px 14px rgba(194, 245, 66, 0.35);
    white-space: nowrap;
}
.btn-scan-action:hover {
    background: #aee42c;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(194, 245, 66, 0.45);
}
.btn-scan-action:disabled {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none;
}

/* 4. HEALTH SCORE OVERVIEW */
.health-overview-card {
    border-radius: 20px;
    padding: 28px;
    margin-bottom: 24px;
    transition: background 0.3s, border-color 0.3s;
}
/* Light Theme */
.health-overview-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.05);
}
.health-overview-card .overview-top-line {
    border-bottom: 1px solid #e2e8f0;
}
.health-overview-card .domain-text {
    color: #0f172a;
}
.health-overview-card .score-num {
    color: #0f172a;
}
.health-overview-card .score-label {
    color: #64748b;
}
.health-overview-card .gauge-bg-ring {
    stroke: #f1f5f9;
}
.health-overview-card .issues-hero-title {
    color: #0f172a;
}
.health-overview-card .issues-hero-desc {
    color: #475569;
}
.health-overview-card .btn-print-report {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    color: #0f172a;
}
.health-overview-card .btn-print-report:hover {
    background: #f1f5f9;
}

/* Dark Theme */
:root[data-theme='dark'] .health-overview-card {
    background: #0f1f2e;
    border-color: rgba(255, 255, 255, 0.08);
    box-shadow: none;
}
:root[data-theme='dark'] .health-overview-card .overview-top-line {
    border-bottom-color: rgba(255, 255, 255, 0.08);
}
:root[data-theme='dark'] .health-overview-card .domain-text {
    color: #f8fafc;
}
:root[data-theme='dark'] .health-overview-card .score-num {
    color: #f8fafc;
}
:root[data-theme='dark'] .health-overview-card .score-label {
    color: #94a3b8;
}
:root[data-theme='dark'] .health-overview-card .gauge-bg-ring {
    stroke: rgba(255, 255, 255, 0.08);
}
:root[data-theme='dark'] .health-overview-card .issues-hero-title {
    color: #f8fafc;
}
:root[data-theme='dark'] .health-overview-card .issues-hero-desc {
    color: #94a3b8;
}
:root[data-theme='dark'] .health-overview-card .btn-print-report {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.15);
    color: #f8fafc;
}
:root[data-theme='dark'] .health-overview-card .btn-print-report:hover {
    background: rgba(255, 255, 255, 0.12);
}

/* 5. 3 HIGHLIGHT CATEGORY TILES */
.seo-tile {
    border-radius: 14px;
    padding: 16px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    transition: background 0.3s, border-color 0.3s;
}
/* Light Theme */
.seo-tile {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}
.seo-tile .tile-title {
    color: #0f172a;
    font-size: 11.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 3px;
}
.seo-tile .tile-desc {
    color: #475569;
    font-size: 12.5px;
    line-height: 1.45;
}

/* Dark Theme */
:root[data-theme='dark'] .seo-tile {
    background: rgba(255, 255, 255, 0.03);
    border-color: rgba(255, 255, 255, 0.08);
}
:root[data-theme='dark'] .seo-tile .tile-title {
    color: #f8fafc;
}
:root[data-theme='dark'] .seo-tile .tile-desc {
    color: #94a3b8;
}

/* 6. ISSUES LIST ROWS */
.seo-issue-row {
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 12px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    transition: background 0.3s, border-color 0.3s;
}
/* Light Theme */
.seo-issue-row {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}
.seo-issue-row .issue-title {
    color: #0f172a;
    font-size: 15px;
    font-weight: 800;
    margin: 0 0 4px;
}
.seo-issue-row .issue-desc {
    color: #334155;
    font-size: 13px;
    margin: 0 0 10px;
    line-height: 1.5;
}
.seo-issue-row .issue-action-box {
    background: #f0f9ff;
    border-left: 3px solid #0284c7;
    color: #0c4a6e;
    padding: 9px 13px;
    border-radius: 6px;
    font-size: 12.5px;
    line-height: 1.45;
}
.seo-issue-row .issue-action-box strong {
    color: #0369a1;
}

/* Dark Theme */
:root[data-theme='dark'] .seo-issue-row {
    background: rgba(255, 255, 255, 0.03);
    border-color: rgba(255, 255, 255, 0.08);
}
:root[data-theme='dark'] .seo-issue-row .issue-title {
    color: #f8fafc;
}
:root[data-theme='dark'] .seo-issue-row .issue-desc {
    color: #94a3b8;
}
:root[data-theme='dark'] .seo-issue-row .issue-action-box {
    background: rgba(56, 189, 248, 0.08);
    border-left-color: #38bdf8;
    color: #cbd5e1;
}
:root[data-theme='dark'] .seo-issue-row .issue-action-box strong {
    color: #38bdf8;
}

/* 7. CODE STUDIO TABS */
.seo-tab-btn {
    background: transparent;
    border: none;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
}
/* Light Theme Tabs */
.seo-tab-btn {
    color: #64748b;
}
.seo-tab-btn.active {
    color: #0284c7;
    border-bottom-color: #0284c7;
    background: rgba(2, 132, 199, 0.05);
}

/* Dark Theme Tabs */
:root[data-theme='dark'] .seo-tab-btn {
    color: #94a3b8;
}
:root[data-theme='dark'] .seo-tab-btn.active {
    color: var(--lime, #c2f542);
    border-bottom-color: var(--lime, #c2f542);
    background: rgba(194, 245, 66, 0.05);
}

/* 8. RECENT AUDITS TABLE */
.seo-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
/* Light Theme Table */
.seo-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    border-bottom: 1px solid #e2e8f0;
    padding: 11px 12px;
}
.seo-table td {
    border-bottom: 1px solid #f1f5f9;
    padding: 12px;
    color: #0f172a;
}
.seo-table td .domain-link {
    color: #0284c7;
    font-weight: 700;
    text-decoration: none;
}
.seo-table td .domain-link:hover {
    text-decoration: underline;
}
.seo-table td .date-text {
    color: #64748b;
}
.btn-view-scan {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #0f172a;
    padding: 6px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 700;
    font-size: 12px;
    display: inline-block;
}
.btn-view-scan:hover {
    background: #e2e8f0;
}

/* Dark Theme Table */
:root[data-theme='dark'] .seo-table th {
    background: rgba(255, 255, 255, 0.03);
    color: #94a3b8;
    border-bottom-color: rgba(255, 255, 255, 0.08);
}
:root[data-theme='dark'] .seo-table td {
    border-bottom-color: rgba(255, 255, 255, 0.04);
    color: #f8fafc;
}
:root[data-theme='dark'] .seo-table td .domain-link {
    color: #38bdf8;
}
:root[data-theme='dark'] .seo-table td .date-text {
    color: #94a3b8;
}
:root[data-theme='dark'] .btn-view-scan {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.16);
    color: #f8fafc;
}
:root[data-theme='dark'] .btn-view-scan:hover {
    background: rgba(255, 255, 255, 0.14);
}

@media print {
    .seo-banner, #tenantScanForm, #tenantProgressBox, .btn-print-report, .admin-sidebar, .admin-topbar, .site-footer {
        display: none !important;
    }
    body { background: #fff !important; color: #000 !important; }
    .health-overview-card, .seo-card { box-shadow: none !important; border: 1px solid #ccc !important; }
}
</style>

<div class="admin-content-wrap seo-wrap">
    <!-- TOP NOTIFICATION BANNER -->
    <div class="seo-banner">
        <div style="display:flex;align-items:center;gap:14px;">
            <span style="font-size:26px;">🚀</span>
            <div>
                <div class="banner-title">100% Free &amp; Unlimited SEO Audits for Workspace Tenants</div>
                <div class="banner-desc">Audit your official website or competitor domains, auto-generate meta tags and schema markup, and export executive PDF reports.</div>
            </div>
        </div>
        <span class="banner-badge">
            Tenant Included
        </span>
    </div>

    <!-- URL SCAN FORM CARD -->
    <div class="seo-card">
        <h3 class="card-heading">
            <i class="fa-solid fa-magnifying-glass" style="color:#0284c7;font-size:16px;"></i>
            <span>Scan Website for SEO Health</span>
        </h3>
        <p class="card-subtext">
            Enter your landing page URL. The engine crawls the DOM, evaluates Google ranking factors, measures server speed, and identifies fixable issues.
        </p>

        <form id="tenantScanForm" onsubmit="handleTenantScan(event)" style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:280px;">
                <input type="text" id="tenantUrlInput" class="seo-url-input" required placeholder="https://example.com" value="<?php echo htmlspecialchars($company_website !== '' ? $company_website : ($current_audit['url'] ?? '')); ?>">
            </div>
            <button type="submit" id="tenantScanBtn" class="btn-scan-action">
                <i class="fa-solid fa-play"></i> <span>Run Full SEO Audit</span>
            </button>
        </form>

        <!-- PROGRESS BAR -->
        <div id="tenantProgressBox" style="display:none;margin-top:20px;">
            <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:600;margin-bottom:8px;" class="card-subtext">
                <span id="tenantProgressStatus">Crawl in progress...</span>
                <span id="tenantProgressPct">20%</span>
            </div>
            <div style="height:7px;background:rgba(0,0,0,0.06);border-radius:4px;overflow:hidden;">
                <div id="tenantProgressBar" style="width:20%;height:100%;background:linear-gradient(90deg, #38bdf8, #10b981);transition:width 0.4s;"></div>
            </div>
        </div>
    </div>

    <!-- AUDIT RESULTS PRESENTATION -->
    <div id="tenantAuditResults" style="<?php echo $current_audit ? 'display:block;' : 'display:none;'; ?>">
        <?php
        $audit_data = $current_audit['details'] ?? [];
        $score = (int)($audit_data['score'] ?? 0);
        $issues_count = (int)($audit_data['issues_count'] ?? 0);
        $autofix_count = (int)($audit_data['auto_fix_count'] ?? 0);
        $score_color = $score >= 80 ? '#10b981' : ($score >= 50 ? '#f59e0b' : '#ef4444');
        $dash_offset = 440 - (440 * $score / 100);
        ?>

        <!-- MAIN HEALTH CARD (MATCHES LEAD OS / OPTIBIZ STYLE) -->
        <div class="health-overview-card">
            <div class="overview-top-line" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:14px;flex-wrap:wrap;gap:10px;">
                <div>
                    <span style="font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#64748b;">Website Health Report</span>
                    <h4 class="domain-text" style="font-size:19px;font-weight:900;margin:3px 0 0;" id="displayAuditedDomain"><?php echo htmlspecialchars($current_audit['domain'] ?? ''); ?></h4>
                </div>
                <button type="button" onclick="window.print()" class="btn-print-report" style="padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-print"></i> Export / Print PDF
                </button>
            </div>

            <div style="display:grid;grid-template-columns:180px 1fr;gap:28px;align-items:center;margin-bottom:26px;">
                <!-- Circular Radial Gauge -->
                <div style="position:relative;width:150px;height:150px;margin:0 auto;">
                    <svg viewBox="0 0 160 160" style="transform:rotate(-90deg);width:100%;height:100%;">
                        <circle cx="80" cy="80" r="70" fill="none" class="gauge-bg-ring" stroke-width="14"></circle>
                        <circle id="tenantGaugeCircle" cx="80" cy="80" r="70" fill="none" stroke="<?php echo $score_color; ?>" stroke-width="14" stroke-linecap="round" style="stroke-dasharray:440;stroke-dashoffset:<?php echo $dash_offset; ?>;transition:stroke-dashoffset 1s ease;"></circle>
                    </svg>
                    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;">
                        <div id="tenantGaugeNumber" class="score-num" style="font-size:44px;font-weight:900;line-height:1;"><?php echo $score; ?></div>
                        <div class="score-label" style="font-size:13px;font-weight:700;">/100</div>
                    </div>
                </div>

                <div>
                    <div class="issues-hero-title" style="display:flex;align-items:center;gap:10px;font-size:28px;font-weight:900;margin-bottom:4px;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;font-size:24px;"></i>
                        <span><span id="tenantIssuesCount"><?php echo $issues_count; ?></span> Issues Found</span>
                    </div>
                    <p class="issues-hero-desc" style="font-size:15px;margin-bottom:14px;">Google cannot fully index and understand key elements on your website.</p>
                    <div style="display:inline-flex;align-items:center;gap:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;padding:8px 16px;border-radius:10px;font-size:13.5px;font-weight:700;">
                        <i class="fa-solid fa-circle-check" style="color:#10b981;"></i>
                        <span>Optibiz can automatically fix <strong id="tenantAutoFixCount"><?php echo $autofix_count; ?></strong> of them for you below.</span>
                    </div>
                </div>
            </div>

            <!-- 3 HIGHLIGHT CATEGORY TILES -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;margin-top:10px;">
                <div class="seo-tile">
                    <div style="width:36px;height:36px;border-radius:50%;background:#fee2e2;color:#ef4444;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </div>
                    <div>
                        <div class="tile-title">Missing SEO Content</div>
                        <div class="tile-desc">Missing titles, meta descriptions, or alt tags.</div>
                    </div>
                </div>

                <div class="seo-tile">
                    <div style="width:36px;height:36px;border-radius:50%;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fa-solid fa-gauge-high"></i>
                    </div>
                    <div>
                        <div class="tile-title">Slow Website</div>
                        <div class="tile-desc">Response time: <strong id="tenantResponseTime" style="color:inherit;"><?php echo number_format(($audit_data['response_time_ms'] ?? 0) / 1000, 2); ?>s</strong>.</div>
                    </div>
                </div>

                <div class="seo-tile">
                    <div style="width:36px;height:36px;border-radius:50%;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fa-solid fa-arrow-trend-up"></i>
                    </div>
                    <div>
                        <div class="tile-title">Weak Visibility</div>
                        <div class="tile-desc">Social preview cards &amp; Schema structured data.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- AUTOMATED CODE FIX STUDIO -->
        <div class="seo-card">
            <div style="margin-bottom:16px;">
                <h3 class="card-heading">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color:#0284c7;"></i>
                    <span>Auto-Fix Code Generator</span>
                </h3>
                <p class="card-subtext" style="margin-bottom:0;">Copy and paste these optimized snippets directly into your website's <code>&lt;head&gt;</code> tag to instantly resolve issues.</p>
            </div>

            <div style="background:#07101c;border:1px solid #1e3653;border-radius:14px;overflow:hidden;">
                <div style="display:flex;background:rgba(255,255,255,0.04);border-bottom:1px solid #1e3653;">
                    <button type="button" class="seo-tab-btn active" onclick="switchTenantTab('meta')" id="btnTabMeta">Optimized Meta Tags</button>
                    <button type="button" class="seo-tab-btn" onclick="switchTenantTab('schema')" id="btnTabSchema">Schema.org JSON-LD</button>
                    <button type="button" class="seo-tab-btn" onclick="switchTenantTab('robots')" id="btnTabRobots">Robots.txt</button>
                </div>
                <div style="position:relative;padding:18px;">
                    <button type="button" onclick="copyTenantCode()" style="position:absolute;top:14px;right:14px;background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.2);padding:6px 14px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;transition:background 0.2s;">
                        <i class="fa-regular fa-copy"></i> Copy Snippet
                    </button>
                    <pre id="tenantCodeSnippet" style="margin:0;font-family:'Consolas', monospace;font-size:12.5px;color:#e2e8f0;overflow-x:auto;white-space:pre-wrap;line-height:1.55;"><?php echo htmlspecialchars($audit_data['code_snippets']['meta_tags'] ?? ''); ?></pre>
                </div>
            </div>
        </div>

        <!-- FULL ISSUE DIAGNOSTICS CHECKLIST -->
        <div class="seo-card">
            <h3 class="card-heading" style="margin-bottom:18px;">
                <i class="fa-solid fa-list-check" style="color:#0284c7;"></i>
                <span>Complete Issue Diagnostics &amp; Resolution Checklist</span>
            </h3>

            <div id="tenantIssuesList">
                <?php if (!empty($audit_data['items'])): ?>
                    <?php foreach ($audit_data['items'] as $item): ?>
                        <div class="seo-issue-row">
                            <span style="font-size:10px;font-weight:800;padding:4px 8px;border-radius:6px;text-transform:uppercase;flex-shrink:0;<?php echo $item['severity'] === 'critical' ? 'background:#fee2e2;color:#b91c1c;' : ($item['severity'] === 'warning' ? 'background:#fef3c7;color:#b45309;' : 'background:#dcfce7;color:#15803d;'); ?>">
                                <?php echo strtoupper($item['severity']); ?>
                            </span>
                            <div style="flex:1;">
                                <h5 class="issue-title"><?php echo htmlspecialchars($item['title']); ?></h5>
                                <p class="issue-desc"><?php echo htmlspecialchars($item['description']); ?></p>
                                <div class="issue-action-box">
                                    <strong>Recommended Action:</strong> <?php echo htmlspecialchars($item['fix']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- AUDIT HISTORY -->
    <?php if (!empty($past_audits)): ?>
    <div class="seo-card">
        <h3 class="card-heading" style="margin-bottom:16px;">
            <i class="fa-solid fa-clock-rotate-left" style="color:#0284c7;"></i>
            <span>Recent SEO Audits for this Workspace</span>
        </h3>
        <div style="overflow-x:auto;">
            <table class="seo-table">
                <thead>
                    <tr>
                        <th>Domain / URL</th>
                        <th>Score</th>
                        <th>Grade</th>
                        <th>Issues</th>
                        <th>Response Time</th>
                        <th>Date</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($past_audits as $pa): ?>
                    <tr>
                        <td>
                            <a href="<?php echo htmlspecialchars($pa['url']); ?>" target="_blank" rel="noopener" class="domain-link">
                                <?php echo htmlspecialchars($pa['domain']); ?>
                            </a>
                        </td>
                        <td>
                            <strong style="font-weight:800;color:<?php echo $pa['score'] >= 80 ? '#10b981' : ($pa['score'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                <?php echo $pa['score']; ?>/100
                            </strong>
                        </td>
                        <td style="font-weight:800;"><?php echo htmlspecialchars($pa['grade']); ?></td>
                        <td><?php echo (int)$pa['issues_count']; ?> issues</td>
                        <td class="date-text"><?php echo number_format($pa['response_time_ms'] / 1000, 2); ?>s</td>
                        <td class="date-text"><?php echo date('M d, Y H:i', strtotime($pa['created_at'])); ?></td>
                        <td style="text-align:right;">
                            <a href="seo_checker.php?token=<?php echo urlencode($pa['access_token']); ?>" class="btn-view-scan">
                                View Report
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
let tenantAuditData = <?php echo json_encode($current_audit['details'] ?? null); ?>;

async function handleTenantScan(e) {
    e.preventDefault();
    const url = document.getElementById('tenantUrlInput').value.trim();
    const btn = document.getElementById('tenantScanBtn');
    if (!url) return;

    btn.disabled = true;
    document.getElementById('tenantProgressBox').style.display = 'block';

    let pct = 20;
    const bar = document.getElementById('tenantProgressBar');
    const pctLabel = document.getElementById('tenantProgressPct');
    const timer = setInterval(() => {
        if (pct < 90) {
            pct += 15;
            bar.style.width = pct + '%';
            pctLabel.textContent = pct + '%';
        }
    }, 400);

    try {
        const fd = new FormData();
        fd.append('action', 'run_tenant_scan');
        fd.append('url', url);

        const res = await fetch('seo_checker.php', { method: 'POST', body: fd });
        const json = await res.json();
        clearInterval(timer);

        bar.style.width = '100%';
        pctLabel.textContent = '100%';

        if (!json.success) {
            alert(json.error || 'Failed to scan website.');
            document.getElementById('tenantProgressBox').style.display = 'none';
            btn.disabled = false;
            return;
        }

        tenantAuditData = json.data;
        setTimeout(() => {
            document.getElementById('tenantProgressBox').style.display = 'none';
            btn.disabled = false;
            renderTenantAudit(tenantAuditData);
        }, 400);

    } catch (err) {
        clearInterval(timer);
        alert('Connection error during audit.');
        document.getElementById('tenantProgressBox').style.display = 'none';
        btn.disabled = false;
    }
}

function renderTenantAudit(data) {
    document.getElementById('tenantAuditResults').style.display = 'block';
    document.getElementById('displayAuditedDomain').textContent = data.domain || '';

    const score = data.score || 0;
    document.getElementById('tenantGaugeNumber').textContent = score;
    const color = score >= 80 ? '#10b981' : (score >= 50 ? '#f59e0b' : '#ef4444');
    const dash = 440 - (440 * score / 100);

    const circle = document.getElementById('tenantGaugeCircle');
    circle.style.stroke = color;
    circle.style.strokeDashoffset = dash;

    document.getElementById('tenantIssuesCount').textContent = data.issues_count || 0;
    document.getElementById('tenantAutoFixCount').textContent = data.auto_fix_count || 0;
    document.getElementById('tenantResponseTime').textContent = ((data.response_time_ms || 0) / 1000).toFixed(2) + 's';

    // Code snippet
    if (data.code_snippets) {
        document.getElementById('tenantCodeSnippet').textContent = data.code_snippets.meta_tags || '';
    }

    // Issues list
    const container = document.getElementById('tenantIssuesList');
    container.innerHTML = '';
    (data.items || []).forEach(it => {
        const bg = it.severity === 'critical' ? 'background:#fee2e2;color:#b91c1c;' : (it.severity === 'warning' ? 'background:#fef3c7;color:#b45309;' : 'background:#dcfce7;color:#15803d;');
        container.innerHTML += `
            <div class="seo-issue-row">
                <span style="font-size:10px;font-weight:800;padding:4px 8px;border-radius:6px;text-transform:uppercase;flex-shrink:0;${bg}">
                    ${it.severity.toUpperCase()}
                </span>
                <div style="flex:1;">
                    <h5 class="issue-title">${escapeHtml(it.title)}</h5>
                    <p class="issue-desc">${escapeHtml(it.description)}</p>
                    <div class="issue-action-box">
                        <strong>Recommended Action:</strong> ${escapeHtml(it.fix)}
                    </div>
                </div>
            </div>
        `;
    });

    document.getElementById('tenantAuditResults').scrollIntoView({ behavior: 'smooth' });
}

function switchTenantTab(tab) {
    document.querySelectorAll('.seo-tab-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');

    if (!tenantAuditData || !tenantAuditData.code_snippets) return;
    const area = document.getElementById('tenantCodeSnippet');
    if (tab === 'meta') area.textContent = tenantAuditData.code_snippets.meta_tags || '';
    if (tab === 'schema') area.textContent = tenantAuditData.code_snippets.schema_json || '';
    if (tab === 'robots') area.textContent = tenantAuditData.code_snippets.robots_txt || '';
}

function copyTenantCode() {
    const code = document.getElementById('tenantCodeSnippet').textContent;
    navigator.clipboard.writeText(code).then(() => {
        alert('Code snippet copied to clipboard!');
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
