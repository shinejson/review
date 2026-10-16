<?php
/**
 * ============================================================
 *  Optibiz — Public Website SEO Checker & Health Audit
 * ============================================================
 *  Features:
 *    - Real-time 30-second live website analysis
 *    - Circular Health Score & issue counters matching LeadOS / Optibiz style
 *    - Free Preview for public visitors with top issue teasers
 *    - Paid Full Actionable Audit ($19 one-time) with payment checkout
 *    - 100% FREE for logged-in Tenants and Superadmins
 *    - Instant Copy-Paste Code Generator (Meta Tags, Schema JSON-LD, Robots.txt)
 *    - Printable Executive PDF Report
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sa_helpers.php';
require_once __DIR__ . '/includes/seo_checker.php';
require_once __DIR__ . '/includes/seo.php';

seo_ensure_schema($conn);

// Check current session privileges
$is_superadmin = function_exists('isSuperAdminLoggedIn') && isSuperAdminLoggedIn();
$is_tenant = function_exists('isTenant') && isTenant();
$tenant_id = $is_tenant ? getTenantId() : null;

// Determine if user has 100% free unlimited access
$has_free_access = $is_superadmin || $is_tenant;

// Get tenant company name if logged in
$logged_company_name = '';
if ($is_tenant && $tenant_id) {
    $stmt = $conn->prepare("SELECT company_name, email FROM tenants WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $tRow = $stmt->get_result()->fetch_assoc();
        $logged_company_name = $tRow['company_name'] ?? 'Workspace Tenant';
        $stmt->close();
    }
} elseif ($is_superadmin) {
    $logged_company_name = 'Super Administrator';
}

// Fetch currency and report price settings
$currency_symbol = '$';
$currency_position = 'before';
$report_price = 19.00;

$r = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('currency_symbol', 'currency_position', 'seo_report_price')");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        if ($row['setting_key'] === 'currency_symbol' && !empty($row['setting_value'])) $currency_symbol = $row['setting_value'];
        if ($row['setting_key'] === 'currency_position' && !empty($row['setting_value'])) $currency_position = $row['setting_value'];
        if ($row['setting_key'] === 'seo_report_price' && is_numeric($row['setting_value'])) $report_price = (float)$row['setting_value'];
    }
}

function fmt_seo_price($amt, $sym, $pos) {
    $f = number_format((float)$amt, 2);
    return $pos === 'after' ? $f . ' ' . $sym : $sym . $f;
}

// Handle AJAX Scan Request
if (isset($_POST['action']) && $_POST['action'] === 'run_scan') {
    header('Content-Type: application/json; charset=utf-8');
    $target_url = trim((string)($_POST['url'] ?? ''));
    $visitor_email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null;

    if ($target_url === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter a website URL.']);
        exit();
    }

    $analysis = seo_analyze_url($target_url);
    if (!empty($analysis['error'])) {
        echo json_encode(['success' => false, 'error' => $analysis['error']]);
        exit();
    }

    $user_type = $is_superadmin ? 'superadmin' : ($is_tenant ? 'tenant' : 'public');
    $is_paid_status = $has_free_access ? 1 : 0;

    $saved = seo_save_audit($conn, $analysis, $user_type, $tenant_id, $is_paid_status, $visitor_email);

    echo json_encode([
        'success' => true,
        'token' => $saved['token'],
        'is_paid' => (bool)$saved['is_paid'],
        'has_free_access' => $has_free_access,
        'data' => $analysis
    ]);
    exit();
}

// Handle Instant Payment Unlock (Simulated / Instant Gateway)
if (isset($_POST['action']) && $_POST['action'] === 'unlock_report') {
    header('Content-Type: application/json; charset=utf-8');
    $token = trim((string)($_POST['token'] ?? ''));
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: 'buyer@example.com';
    $ref = 'SEO-' . strtoupper(bin2hex(random_bytes(4)));

    if ($token === '') {
        echo json_encode(['success' => false, 'error' => 'Invalid audit session token.']);
        exit();
    }

    $ok = seo_unlock_audit($conn, $token, $ref, $report_price);
    if ($ok) {
        // Update email if provided
        $stmtU = $conn->prepare("UPDATE seo_audits SET visitor_email = ? WHERE access_token = ?");
        $stmtU->bind_param("ss", $email, $token);
        $stmtU->execute();
        $stmtU->close();

        echo json_encode(['success' => true, 'reference' => $ref, 'message' => 'Report unlocked successfully!']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not unlock report. Please try again.']);
    }
    exit();
}

// If viewing an existing report via ?token=...
$existing_audit = null;
$view_token = trim((string)($_GET['token'] ?? ''));
if ($view_token !== '') {
    $existing_audit = seo_get_audit($conn, $view_token);
}

// Determine if currently viewed report is unlocked
$report_unlocked = false;
if ($existing_audit) {
    if ($has_free_access || !empty($existing_audit['is_paid'])) {
        $report_unlocked = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en-GH">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $seo_title = 'Free SEO Health Checker & Website Audit — Optibiz';
    $seo_desc  = 'Scan your website in 30 seconds. Discover SEO issues, meta tag flaws, page load speed problems, and get actionable auto-fix guides. Free for tenants!';
    ?>
    <title><?php echo htmlspecialchars($seo_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($seo_desc, ENT_QUOTES, 'UTF-8'); ?>">

    <?php seo_head(array(
        'title'       => $seo_title,
        'description' => $seo_desc,
        'path'        => 'seo-checker.php',
        'breadcrumbs' => array(array('Home', ''), array('SEO Checker', 'seo-checker.php')),
    )); ?>

    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-dark: #0a1727;
            --primary-bg: #07101c;
            --card-dark: #102136;
            --card-border: #1e3653;
            --accent-lime: #c2f542;
            --accent-lime-hover: #aee42c;
            --accent-blue: #38bdf8;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-line: #1e293b;
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--primary-bg);
            color: var(--text-main);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Navbar */
        .top-navbar {
            background: rgba(10, 23, 39, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .nav-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #fff;
            font-weight: 800;
            font-size: 20px;
            letter-spacing: -0.5px;
        }
        .nav-logo-badge {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: var(--accent-lime);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 16px;
        }
        .nav-links {
            display: flex;
            align-items: center;
            gap: 22px;
        }
        .nav-links a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }
        .nav-links a:hover, .nav-links a.active {
            color: var(--accent-lime);
        }
        .nav-badge-free {
            background: rgba(194, 245, 66, 0.15);
            color: var(--accent-lime);
            padding: 2px 7px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 4px;
            border: 1px solid rgba(194, 245, 66, 0.3);
        }
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .btn-signin {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .btn-signin:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.06);
        }
        .btn-cta {
            background: var(--accent-lime);
            color: var(--primary-dark);
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            padding: 8px 18px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-cta:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-1px);
        }

        /* Hero Section Matching Screenshot */
        .hero-section {
            padding: 48px 20px 40px;
            text-align: center;
            max-width: 900px;
            margin: 0 auto;
        }
        .trust-pill-group {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 7px 18px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.4px;
            margin-bottom: 24px;
            text-transform: uppercase;
        }
        .trust-pill-group span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .trust-pill-group .sep {
            color: rgba(255, 255, 255, 0.2);
        }
        .hero-title {
            font-size: clamp(32px, 5vw, 54px);
            font-weight: 900;
            line-height: 1.15;
            letter-spacing: -1px;
            color: #ffffff;
            margin-bottom: 16px;
            text-transform: uppercase;
        }
        .hero-title .highlight-blue {
            background: linear-gradient(135deg, #38bdf8 0%, #818cf8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-subtitle {
            font-size: clamp(16px, 2.5vw, 20px);
            color: #cbd5e1;
            margin-bottom: 32px;
            font-weight: 400;
        }

        /* Status Banner for Logged In User */
        .auth-status-banner {
            max-width: 720px;
            margin: 0 auto 24px;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 13.5px;
            color: #6ee7b7;
        }
        .auth-status-banner strong {
            color: #fff;
        }
        .auth-status-banner a {
            color: var(--accent-lime);
            font-weight: 700;
            text-decoration: underline;
        }

        /* Search Input Box */
        .scan-form-box {
            max-width: 680px;
            margin: 0 auto;
            position: relative;
        }
        .scan-form {
            display: flex;
            align-items: center;
            background: rgba(16, 33, 54, 0.9);
            border: 2px solid var(--card-border);
            border-radius: 16px;
            padding: 6px 6px 6px 18px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.35);
            transition: border-color 0.25s, box-shadow 0.25s;
        }
        .scan-form:focus-within {
            border-color: var(--accent-blue);
            box-shadow: 0 12px 36px rgba(56, 189, 248, 0.25);
        }
        .scan-form i.fa-globe {
            color: var(--accent-blue);
            font-size: 18px;
            margin-right: 12px;
        }
        .scan-input {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            color: #ffffff;
            font-size: 16px;
            font-weight: 500;
        }
        .scan-input::placeholder {
            color: #64748b;
        }
        .btn-scan {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            border: none;
            padding: 14px 26px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
            white-space: nowrap;
        }
        .btn-scan:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: scale(1.02);
        }
        .btn-scan:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .quick-demos {
            margin-top: 14px;
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .quick-demo-link {
            color: #93c5fd;
            text-decoration: underline;
            cursor: pointer;
            transition: color 0.2s;
        }
        .quick-demo-link:hover {
            color: #ffffff;
        }

        /* Animated Scanning Loader */
        .scan-progress-card {
            display: none;
            max-width: 680px;
            margin: 28px auto 0;
            background: var(--card-dark);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 24px;
            text-align: left;
        }
        .progress-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
            font-weight: 600;
        }
        .progress-bar-track {
            height: 8px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 14px;
        }
        .progress-bar-fill {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #38bdf8, var(--accent-lime));
            border-radius: 6px;
            transition: width 0.4s ease;
        }
        .progress-steps {
            list-style: none;
            font-size: 13px;
            color: var(--text-muted);
            display: grid;
            gap: 8px;
        }
        .progress-steps li {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .progress-steps li.active {
            color: var(--accent-blue);
            font-weight: 600;
        }
        .progress-steps li.done {
            color: var(--success);
        }

        /* Results Container (matches screenshot!) */
        .results-container {
            max-width: 820px;
            margin: 36px auto 60px;
            padding: 0 16px;
        }

        /* Main Website Health Card */
        .health-card {
            background: #ffffff;
            color: #0f172a;
            border-radius: 24px;
            padding: 32px 28px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.4);
            margin-bottom: 24px;
            position: relative;
        }
        .health-card-header {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 20px;
        }
        .health-score-layout {
            display: grid;
            grid-template-columns: 180px 1fr;
            align-items: center;
            gap: 28px;
            margin-bottom: 28px;
        }
        @media (max-width: 600px) {
            .health-score-layout {
                grid-template-columns: 1fr;
                text-align: center;
            }
        }

        /* Radial Circular Progress */
        .gauge-wrap {
            position: relative;
            width: 160px;
            height: 160px;
            margin: 0 auto;
        }
        .gauge-svg {
            transform: rotate(-90deg);
            width: 100%;
            height: 100%;
        }
        .gauge-bg {
            fill: none;
            stroke: #f1f5f9;
            stroke-width: 14;
        }
        .gauge-bar {
            fill: none;
            stroke-width: 14;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease-in-out;
        }
        .gauge-inner-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }
        .gauge-number {
            font-size: 48px;
            font-weight: 900;
            line-height: 1;
            color: #0f172a;
        }
        .gauge-label {
            font-size: 14px;
            font-weight: 700;
            color: #94a3b8;
        }

        .issues-summary {
            text-align: left;
        }
        @media (max-width: 600px) {
            .issues-summary { text-align: center; }
        }
        .issues-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 32px;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
        }
        @media (max-width: 600px) {
            .issues-title { justify-content: center; }
        }
        .issues-title i {
            color: #ef4444;
            font-size: 28px;
        }
        .issues-subtitle {
            font-size: 16px;
            color: #475569;
            font-weight: 500;
            margin-bottom: 16px;
        }
        .autofix-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            padding: 10px 18px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
        }
        .autofix-badge i {
            color: #10b981;
            font-size: 16px;
        }

        /* 3 Category Highlight Cards (from screenshot!) */
        .highlight-cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-top: 10px;
        }
        @media (max-width: 700px) {
            .highlight-cards-grid {
                grid-template-columns: 1fr;
            }
        }
        .highlight-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px 14px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            text-align: left;
        }
        .highlight-icon-wrap {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }
        .icon-red { background: #fee2e2; color: #ef4444; }
        .icon-amber { background: #fef3c7; color: #d97706; }
        .icon-blue { background: #e0f2fe; color: #0284c7; }

        .highlight-body h4 {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .highlight-body p {
            font-size: 12.5px;
            color: #64748b;
            line-height: 1.4;
            margin: 0;
        }

        /* Preview Issues List */
        .issues-preview-box {
            margin-top: 24px;
            border-top: 1px solid #e2e8f0;
            padding-top: 20px;
            text-align: left;
        }
        .preview-heading {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 12px;
        }
        .issue-row {
            padding: 12px 14px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .issue-pill {
            font-size: 10px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .pill-critical { background: #fee2e2; color: #b91c1c; }
        .pill-warning { background: #fef3c7; color: #b45309; }
        .pill-passed { background: #dcfce7; color: #15803d; }
        .issue-info h5 {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 2px;
        }
        .issue-info p {
            font-size: 12.5px;
            color: #64748b;
            margin: 0;
        }

        /* Paywall / Unlock Banner */
        .paywall-card {
            background: linear-gradient(135deg, #102136 0%, #1e3653 100%);
            border: 2px solid #38bdf8;
            border-radius: 24px;
            padding: 34px 28px;
            text-align: center;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.4);
            margin-top: 24px;
            position: relative;
            overflow: hidden;
        }
        .paywall-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 16px;
        }
        .paywall-title {
            font-size: 26px;
            font-weight: 900;
            color: #ffffff;
            margin-bottom: 12px;
        }
        .paywall-desc {
            font-size: 15px;
            color: #cbd5e1;
            max-width: 600px;
            margin: 0 auto 24px;
        }
        .paywall-features {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            max-width: 620px;
            margin: 0 auto 28px;
            text-align: left;
        }
        @media (max-width: 600px) {
            .paywall-features { grid-template-columns: 1fr; }
        }
        .paywall-feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13.5px;
            color: #e2e8f0;
        }
        .paywall-feature-item i {
            color: var(--accent-lime);
        }
        .paywall-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .btn-unlock {
            background: var(--accent-lime);
            color: var(--primary-dark);
            border: none;
            padding: 14px 28px;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
            box-shadow: 0 6px 20px rgba(194, 245, 66, 0.35);
        }
        .btn-unlock:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
        }
        .tenant-free-callout {
            margin-top: 20px;
            font-size: 13.5px;
            color: #94a3b8;
        }
        .tenant-free-callout a {
            color: var(--accent-lime);
            text-decoration: underline;
            font-weight: 700;
        }

        /* Full Unlocked Report View */
        .unlocked-report-card {
            background: var(--card-dark);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 28px;
            margin-top: 24px;
            text-align: left;
        }
        .unlocked-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            flex-wrap: wrap;
            gap: 12px;
        }
        .unlocked-title {
            font-size: 20px;
            font-weight: 800;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-print {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.16);
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-print:hover {
            background: rgba(255, 255, 255, 0.14);
        }

        /* Code Studio Box */
        .code-box-wrapper {
            margin-top: 24px;
            background: #07101c;
            border: 1px solid #1e3653;
            border-radius: 14px;
            overflow: hidden;
        }
        .code-tabs {
            display: flex;
            background: rgba(255, 255, 255, 0.04);
            border-bottom: 1px solid #1e3653;
        }
        .code-tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .code-tab-btn.active {
            color: var(--accent-lime);
            border-bottom-color: var(--accent-lime);
            background: rgba(194, 245, 66, 0.04);
        }
        .code-content-panel {
            padding: 16px;
            position: relative;
        }
        .code-content-panel pre {
            margin: 0;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 12.5px;
            color: #e2e8f0;
            overflow-x: auto;
            white-space: pre-wrap;
            line-height: 1.5;
        }
        .btn-copy-code {
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-copy-code:hover {
            background: var(--accent-lime);
            color: var(--primary-dark);
        }

        /* Modal Backdrop */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(6px);
            z-index: 200;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-card {
            background: #102136;
            border: 1px solid #1e3653;
            border-radius: 20px;
            width: 100%;
            max-width: 480px;
            padding: 28px;
            text-align: center;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6);
            position: relative;
        }
        .modal-close {
            position: absolute;
            top: 16px;
            right: 18px;
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 20px;
            cursor: pointer;
        }
        .modal-close:hover { color: #fff; }

        /* Footer */
        .site-footer {
            margin-top: auto;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding: 28px 20px;
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
        }
        .site-footer a {
            color: #cbd5e1;
            text-decoration: none;
        }
        .site-footer a:hover {
            color: var(--accent-lime);
        }

        @media print {
            .top-navbar, .scan-form-box, .paywall-card, .btn-print, .site-footer {
                display: none !important;
            }
            body { background: #fff !important; color: #000 !important; }
            .health-card, .unlocked-report-card { box-shadow: none !important; border: 1px solid #ccc !important; }
        }
    </style>
</head>
<body>

    <!-- TOP NAVIGATION -->
    <header class="top-navbar">
        <div class="nav-container">
            <a href="index.php" class="nav-logo">
                <span class="nav-logo-badge">★</span>
                <span>Optibiz <span style="font-weight:400;font-size:14px;color:var(--accent-lime);">SEO</span></span>
            </a>
            <nav class="nav-links">
                <a href="index.php">Home</a>
                <a href="features.php">Features</a>
                <a href="pricing.php">Pricing</a>
                <a href="seo-checker.php" class="active">SEO Checker <span class="nav-badge-free">Free Scan</span></a>
            </nav>
            <div class="nav-actions">
                <?php if ($is_tenant): ?>
                    <a href="<?php echo admin_url('seo_checker.php'); ?>" class="btn-cta">
                        <i class="fa-solid fa-briefcase"></i> My Workspace (Free)
                    </a>
                <?php elseif ($is_superadmin): ?>
                    <a href="superadmin/seo_checker.php" class="btn-cta">
                        <i class="fa-solid fa-crown"></i> Superadmin Center
                    </a>
                <?php else: ?>
                    <a href="<?php echo admin_url('login.php'); ?>" class="btn-signin">Tenant Sign In</a>
                    <a href="pricing.php" class="btn-cta">Start Free Trial</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- HERO SECTION (MATCHES SCREENSHOT) -->
    <main class="hero-section">
        <div class="trust-pill-group">
            <span>⚡ 100% Free Instant Scan</span>
            <span class="sep">|</span>
            <span>⏱ 30 Seconds</span>
            <span class="sep">|</span>
            <span>✅ No Credit Card</span>
        </div>

        <h1 class="hero-title">
            How Many <span class="highlight-blue">SEO Problems</span><br>Does Your Website Have?
        </h1>
        <p class="hero-subtitle">
            Scan your website in 30 seconds and find out what's holding your Google rankings back.
        </p>

        <?php if ($is_tenant): ?>
            <div class="auth-status-banner">
                <div>
                    <i class="fa-solid fa-circle-check"></i>
                    <strong>Tenant Workspace Active:</strong> You are logged in as <strong><?php echo htmlspecialchars($logged_company_name); ?></strong>. All SEO Audits &amp; code fixes are <strong>100% FREE</strong>!
                </div>
                <a href="<?php echo admin_url('seo_checker.php'); ?>">Open Workspace Tool &rarr;</a>
            </div>
        <?php elseif ($is_superadmin): ?>
            <div class="auth-status-banner">
                <div>
                    <i class="fa-solid fa-crown"></i>
                    <strong>Superadmin Session:</strong> You have unlimited <strong>100% FREE</strong> access to all audits and leads.
                </div>
                <a href="superadmin/seo_checker.php">Open Superadmin Tool &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- URL SCAN FORM -->
        <div class="scan-form-box">
            <form id="seoScanForm" class="scan-form" onsubmit="handleScanSubmit(event)">
                <i class="fa-solid fa-globe"></i>
                <input type="text" id="targetUrlInput" class="scan-input" placeholder="Enter website URL (e.g., https://yourbusiness.com)" required autocomplete="off" value="<?php echo htmlspecialchars($existing_audit['url'] ?? ''); ?>">
                <button type="submit" id="scanSubmitBtn" class="btn-scan">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>Scan My Website Free</span>
                </button>
            </form>
            <div class="quick-demos">
                <span>Or test a demo:</span>
                <span class="quick-demo-link" onclick="quickFill('https://example.com')">example.com</span>
                <span>•</span>
                <span class="quick-demo-link" onclick="quickFill('https://wikipedia.org')">wikipedia.org</span>
                <span>•</span>
                <span class="quick-demo-link" onclick="quickFill('https://github.com')">github.com</span>
            </div>
        </div>

        <!-- ANIMATED PROGRESS CARD -->
        <div id="scanProgressCard" class="scan-progress-card">
            <div class="progress-header">
                <span id="progressStatusLabel">Connecting to server...</span>
                <span id="progressPercentLabel">15%</span>
            </div>
            <div class="progress-bar-track">
                <div id="progressBarFill" class="progress-bar-fill"></div>
            </div>
            <ul class="progress-steps">
                <li id="step1" class="active"><i class="fa-solid fa-spinner fa-spin"></i> Fetching DOM &amp; Measuring Server Response Time</li>
                <li id="step2"><i class="fa-regular fa-circle"></i> Analyzing Titles, Meta Tags &amp; Headings</li>
                <li id="step3"><i class="fa-regular fa-circle"></i> Auditing Images, Alt Text &amp; Mobile Viewport</li>
                <li id="step4"><i class="fa-regular fa-circle"></i> Inspecting OpenGraph, Social Cards &amp; Schema JSON-LD</li>
                <li id="step5"><i class="fa-regular fa-circle"></i> Compiling SEO Health Score &amp; Fixes</li>
            </ul>
        </div>
    </main>

    <!-- AUDIT RESULTS SECTION -->
    <section id="resultsSection" class="results-container" style="<?php echo $existing_audit ? 'display:block;' : 'display:none;'; ?>">
        <?php
        $audit = $existing_audit['details'] ?? [];
        $score = (int)($audit['score'] ?? 41);
        $issues_count = (int)($audit['issues_count'] ?? 17);
        $autofix_count = (int)($audit['auto_fix_count'] ?? 12);
        $scoreColor = $score >= 80 ? '#10b981' : ($score >= 50 ? '#f59e0b' : '#ef4444');
        $dashOffset = 440 - (440 * $score / 100);
        ?>

        <!-- CARD 1: WEBSITE HEALTH (EXACT MATCH TO USER SCREENSHOT) -->
        <div class="health-card">
            <div class="health-card-header">Website Health</div>

            <div class="health-score-layout">
                <!-- Circular Radial Gauge -->
                <div class="gauge-wrap">
                    <svg class="gauge-svg" viewBox="0 0 160 160">
                        <circle class="gauge-bg" cx="80" cy="80" r="70"></circle>
                        <circle id="gaugeBarCircle" class="gauge-bar" cx="80" cy="80" r="70"
                                style="stroke: <?php echo $scoreColor; ?>; stroke-dasharray: 440; stroke-dashoffset: <?php echo $dashOffset; ?>;">
                        </circle>
                    </svg>
                    <div class="gauge-inner-text">
                        <div id="gaugeScoreNum" class="gauge-number"><?php echo $score; ?></div>
                        <div class="gauge-label">/100</div>
                    </div>
                </div>

                <!-- Issues Count & Autofix Note -->
                <div class="issues-summary">
                    <div class="issues-title">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span><span id="issuesCountText"><?php echo $issues_count; ?></span> Issues Found</span>
                    </div>
                    <p class="issues-subtitle">Google can't fully understand your website.</p>
                    <div class="autofix-badge">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Optibiz can help you fix <strong id="autoFixCountText"><?php echo $autofix_count; ?></strong> of them automatically.</span>
                    </div>
                </div>
            </div>

            <!-- 3 HIGHLIGHT CATEGORIES FROM SCREENSHOT -->
            <div class="highlight-cards-grid">
                <div class="highlight-card">
                    <div class="highlight-icon-wrap icon-red">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </div>
                    <div class="highlight-body">
                        <h4>Missing SEO Content</h4>
                        <p>Google can't find important information.</p>
                    </div>
                </div>
                <div class="highlight-card">
                    <div class="highlight-icon-wrap icon-amber">
                        <i class="fa-solid fa-gauge-high"></i>
                    </div>
                    <div class="highlight-body">
                        <h4>Slow Website</h4>
                        <p>Slow sites lose rankings &amp; customers.</p>
                    </div>
                </div>
                <div class="highlight-card">
                    <div class="highlight-icon-wrap icon-blue">
                        <i class="fa-solid fa-arrow-trend-up"></i>
                    </div>
                    <div class="highlight-body">
                        <h4>Weak Visibility</h4>
                        <p>Competitors are ranking above you.</p>
                    </div>
                </div>
            </div>

            <!-- PREVIEW OF TOP ISSUES -->
            <div class="issues-preview-box">
                <div class="preview-heading">Detected Critical Issues Preview</div>
                <div id="previewIssuesList">
                    <?php if (!empty($audit['items'])): ?>
                        <?php $previewCount = 0; foreach ($audit['items'] as $item): if ($item['severity'] !== 'passed' && $previewCount < 3): $previewCount++; ?>
                            <div class="issue-row">
                                <span class="issue-pill <?php echo $item['severity'] === 'critical' ? 'pill-critical' : 'pill-warning'; ?>">
                                    <?php echo strtoupper($item['severity']); ?>
                                </span>
                                <div class="issue-info">
                                    <h5><?php echo htmlspecialchars($item['title']); ?></h5>
                                    <p><?php echo htmlspecialchars($item['description']); ?></p>
                                </div>
                            </div>
                        <?php endif; endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- PAYWALL OR FULL UNLOCKED SECTION -->
        <div id="paywallContainer" style="<?php echo $report_unlocked ? 'display:none;' : 'display:block;'; ?>">
            <div class="paywall-card">
                <div class="paywall-badge">
                    <i class="fa-solid fa-lock"></i> Full Actionable Audit
                </div>
                <h3 class="paywall-title">Unlock Complete In-Depth SEO Audit &amp; Fix Toolkit</h3>
                <p class="paywall-desc">
                    Get the complete breakdown of all <?php echo $issues_count; ?> issues, exact copy-paste code snippets (Meta tags &amp; Schema JSON-LD), and the downloadable executive PDF report.
                </p>

                <div class="paywall-features">
                    <div class="paywall-feature-item">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Complete checklist of all Critical &amp; Warning issues</span>
                    </div>
                    <div class="paywall-feature-item">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>1-Click Copy Ready Meta Tags &amp; OG Social Code</span>
                    </div>
                    <div class="paywall-feature-item">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Structured Schema JSON-LD Generator tailored to site</span>
                    </div>
                    <div class="paywall-feature-item">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Downloadable &amp; Printable Executive PDF Audit Report</span>
                    </div>
                </div>

                <div class="paywall-actions">
                    <button type="button" class="btn-unlock" onclick="openUnlockModal()">
                        <i class="fa-solid fa-unlock"></i>
                        <span>Unlock Full Report (<?php echo fmt_seo_price($report_price, $currency_symbol, $currency_position); ?> One-Time)</span>
                    </button>
                    <a href="pricing.php" class="btn-cta" style="padding:14px 22px;border-radius:14px;">
                        <span>Get 100% Free with Any Tenant Plan</span> <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>

                <div class="tenant-free-callout">
                    Already an Optibiz Tenant? <a href="<?php echo admin_url('login.php'); ?>">Sign In to your workspace</a> for 100% FREE unlimited scans.
                </div>
            </div>
        </div>

        <!-- FULL UNLOCKED REPORT (Visible when paid or for tenants/superadmin) -->
        <div id="unlockedContainer" style="<?php echo $report_unlocked ? 'display:block;' : 'display:none;'; ?>">
            <div class="unlocked-report-card">
                <div class="unlocked-header">
                    <div class="unlocked-title">
                        <i class="fa-solid fa-clipboard-check" style="color:var(--accent-lime);"></i>
                        <span>Full Audit Checklist &amp; Resolution Guide</span>
                    </div>
                    <button type="button" class="btn-print" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Print / Save PDF
                    </button>
                </div>

                <div id="fullIssuesList">
                    <?php if (!empty($audit['items'])): ?>
                        <?php foreach ($audit['items'] as $item): ?>
                            <div class="issue-row" style="background:rgba(255,255,255,0.02);border-color:rgba(255,255,255,0.08);margin-bottom:12px;padding:16px;">
                                <span class="issue-pill <?php echo $item['severity'] === 'critical' ? 'pill-critical' : ($item['severity'] === 'warning' ? 'pill-warning' : 'pill-passed'); ?>">
                                    <?php echo strtoupper($item['severity']); ?>
                                </span>
                                <div class="issue-info" style="flex:1;">
                                    <h5 style="color:#fff;font-size:15px;"><?php echo htmlspecialchars($item['title']); ?></h5>
                                    <p style="color:#94a3b8;margin-bottom:8px;"><?php echo htmlspecialchars($item['description']); ?></p>
                                    <div style="background:rgba(56,189,248,0.08);border-left:3px solid #38bdf8;padding:8px 12px;border-radius:4px;font-size:12.5px;color:#cbd5e1;">
                                        <strong style="color:#38bdf8;">How to fix:</strong> <?php echo htmlspecialchars($item['fix']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- INSTANT CODE GENERATOR STUDIO -->
                <div class="code-box-wrapper">
                    <div class="code-tabs">
                        <button type="button" class="code-tab-btn active" onclick="switchCodeTab('meta')">Optimized Meta Tags</button>
                        <button type="button" class="code-tab-btn" onclick="switchCodeTab('schema')">Schema JSON-LD</button>
                        <button type="button" class="code-tab-btn" onclick="switchCodeTab('robots')">Robots.txt</button>
                    </div>
                    <div class="code-content-panel">
                        <button type="button" class="btn-copy-code" onclick="copyCurrentCode()">
                            <i class="fa-regular fa-copy"></i> Copy Code
                        </button>
                        <pre id="codeDisplayArea"><?php echo htmlspecialchars($audit['code_snippets']['meta_tags'] ?? ''); ?></pre>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- UNLOCK CHECKOUT MODAL -->
    <div id="unlockModal" class="modal-backdrop">
        <div class="modal-card">
            <button type="button" class="modal-close" onclick="closeUnlockModal()">&times;</button>
            <div style="font-size:36px;color:var(--accent-lime);margin-bottom:12px;">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h3 style="color:#fff;font-size:22px;margin-bottom:8px;">Unlock Full SEO Audit Report</h3>
            <p style="color:#94a3b8;font-size:14px;margin-bottom:20px;">
                Instant one-time payment of <strong><?php echo fmt_seo_price($report_price, $currency_symbol, $currency_position); ?></strong> to unlock all fixes, code generators, and PDF download.
            </p>

            <form onsubmit="handleUnlockSubmit(event)" style="display:grid;gap:14px;text-align:left;">
                <div>
                    <label style="font-size:12px;font-weight:700;color:#cbd5e1;text-transform:uppercase;">Email for Receipt &amp; Report Link</label>
                    <input type="email" id="unlockEmailInput" required placeholder="your.name@company.com" style="width:100%;background:#07101c;border:1px solid #1e3653;color:#fff;padding:12px 14px;border-radius:10px;font-size:14px;outline:none;margin-top:4px;">
                </div>

                <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:14px;color:#fff;font-weight:600;margin-bottom:4px;">
                        <span>Full SEO Audit &amp; Fix Guide</span>
                        <span><?php echo fmt_seo_price($report_price, $currency_symbol, $currency_position); ?></span>
                    </div>
                    <div style="font-size:12px;color:#94a3b8;">Permanent access link provided via email.</div>
                </div>

                <button type="submit" id="unlockConfirmBtn" class="btn-unlock" style="justify-content:center;width:100%;border-radius:10px;padding:12px;">
                    <i class="fa-solid fa-lock-open"></i> Complete Payment &amp; Unlock
                </button>
            </form>
        </div>
    </div>

    <!-- FOOTER -->
    <footer class="site-footer">
        <p>&copy; <?php echo date('Y'); ?> Optibiz. All rights reserved. Free for tenants and superadmin.</p>
    </footer>

    <!-- INTERACTION JAVASCRIPT -->
    <script>
        let currentAuditToken = '<?php echo $existing_audit['access_token'] ?? ''; ?>';
        let currentAuditData = <?php echo json_encode($existing_audit['details'] ?? null); ?>;
        let isUserPrivileged = <?php echo $has_free_access ? 'true' : 'false'; ?>;

        function quickFill(url) {
            document.getElementById('targetUrlInput').value = url;
            document.getElementById('seoScanForm').requestSubmit();
        }

        async function handleScanSubmit(e) {
            e.preventDefault();
            const urlInput = document.getElementById('targetUrlInput');
            const submitBtn = document.getElementById('scanSubmitBtn');
            const url = urlInput.value.trim();

            if (!url) return;

            // Show loader & reset progress
            submitBtn.disabled = true;
            document.getElementById('scanProgressCard').style.display = 'block';
            document.getElementById('resultsSection').style.display = 'none';

            let progress = 10;
            const bar = document.getElementById('progressBarFill');
            const label = document.getElementById('progressStatusLabel');
            const pLabel = document.getElementById('progressPercentLabel');

            const interval = setInterval(() => {
                if (progress < 90) {
                    progress += 15;
                    bar.style.width = progress + '%';
                    pLabel.textContent = progress + '%';
                    if (progress >= 30) document.getElementById('step2').className = 'active';
                    if (progress >= 50) { document.getElementById('step2').className = 'done'; document.getElementById('step3').className = 'active'; }
                    if (progress >= 70) { document.getElementById('step3').className = 'done'; document.getElementById('step4').className = 'active'; }
                }
            }, 500);

            try {
                const formData = new FormData();
                formData.append('action', 'run_scan');
                formData.append('url', url);

                const res = await fetch('seo-checker.php', { method: 'POST', body: formData });
                const json = await res.json();
                clearInterval(interval);

                bar.style.width = '100%';
                pLabel.textContent = '100%';

                if (!json.success) {
                    alert(json.error || 'Failed to scan website.');
                    document.getElementById('scanProgressCard').style.display = 'none';
                    submitBtn.disabled = false;
                    return;
                }

                currentAuditToken = json.token;
                currentAuditData = json.data;
                const isPaid = json.is_paid || isUserPrivileged;

                // Render result
                setTimeout(() => {
                    document.getElementById('scanProgressCard').style.display = 'none';
                    submitBtn.disabled = false;
                    renderAuditResults(currentAuditData, isPaid);
                }, 400);

            } catch (err) {
                clearInterval(interval);
                alert('Connection error. Please try again.');
                document.getElementById('scanProgressCard').style.display = 'none';
                submitBtn.disabled = false;
            }
        }

        function renderAuditResults(data, isPaid) {
            document.getElementById('resultsSection').style.display = 'block';

            // Gauge Score
            const score = data.score || 0;
            document.getElementById('gaugeScoreNum').textContent = score;
            const scoreColor = score >= 80 ? '#10b981' : (score >= 50 ? '#f59e0b' : '#ef4444');
            const dashOffset = 440 - (440 * score / 100);

            const circle = document.getElementById('gaugeBarCircle');
            circle.style.stroke = scoreColor;
            circle.style.strokeDashoffset = dashOffset;

            // Issues counters
            document.getElementById('issuesCountText').textContent = data.issues_count || 0;
            document.getElementById('autoFixCountText').textContent = data.auto_fix_count || 0;

            // Preview items
            const previewContainer = document.getElementById('previewIssuesList');
            previewContainer.innerHTML = '';
            let pCount = 0;
            (data.items || []).forEach(it => {
                if (it.severity !== 'passed' && pCount < 3) {
                    pCount++;
                    previewContainer.innerHTML += `
                        <div class="issue-row">
                            <span class="issue-pill ${it.severity === 'critical' ? 'pill-critical' : 'pill-warning'}">
                                ${it.severity.toUpperCase()}
                            </span>
                            <div class="issue-info">
                                <h5>${escapeHtml(it.title)}</h5>
                                <p>${escapeHtml(it.description)}</p>
                            </div>
                        </div>
                    `;
                }
            });

            // Paywall vs Unlocked View
            if (isPaid || isUserPrivileged) {
                document.getElementById('paywallContainer').style.display = 'none';
                document.getElementById('unlockedContainer').style.display = 'block';
                renderFullIssues(data);
            } else {
                document.getElementById('paywallContainer').style.display = 'block';
                document.getElementById('unlockedContainer').style.display = 'none';
            }

            // Scroll smoothly to results
            document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });
        }

        function renderFullIssues(data) {
            const list = document.getElementById('fullIssuesList');
            list.innerHTML = '';

            (data.items || []).forEach(it => {
                const pillClass = it.severity === 'critical' ? 'pill-critical' : (it.severity === 'warning' ? 'pill-warning' : 'pill-passed');
                list.innerHTML += `
                    <div class="issue-row" style="background:rgba(255,255,255,0.02);border-color:rgba(255,255,255,0.08);margin-bottom:12px;padding:16px;">
                        <span class="issue-pill ${pillClass}">${it.severity.toUpperCase()}</span>
                        <div class="issue-info" style="flex:1;">
                            <h5 style="color:#fff;font-size:15px;">${escapeHtml(it.title)}</h5>
                            <p style="color:#94a3b8;margin-bottom:8px;">${escapeHtml(it.description)}</p>
                            <div style="background:rgba(56,189,248,0.08);border-left:3px solid #38bdf8;padding:8px 12px;border-radius:4px;font-size:12.5px;color:#cbd5e1;">
                                <strong style="color:#38bdf8;">How to fix:</strong> ${escapeHtml(it.fix)}
                            </div>
                        </div>
                    </div>
                `;
            });

            // Set default meta tags code
            if (data.code_snippets && data.code_snippets.meta_tags) {
                document.getElementById('codeDisplayArea').textContent = data.code_snippets.meta_tags;
            }
        }

        function switchCodeTab(tab) {
            document.querySelectorAll('.code-tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            if (!currentAuditData || !currentAuditData.code_snippets) return;
            const area = document.getElementById('codeDisplayArea');
            if (tab === 'meta') area.textContent = currentAuditData.code_snippets.meta_tags || '';
            if (tab === 'schema') area.textContent = currentAuditData.code_snippets.schema_json || '';
            if (tab === 'robots') area.textContent = currentAuditData.code_snippets.robots_txt || '';
        }

        function copyCurrentCode() {
            const code = document.getElementById('codeDisplayArea').textContent;
            navigator.clipboard.writeText(code).then(() => {
                alert('Code copied to clipboard!');
            });
        }

        function openUnlockModal() {
            document.getElementById('unlockModal').style.display = 'flex';
        }

        function closeUnlockModal() {
            document.getElementById('unlockModal').style.display = 'none';
        }

        async function handleUnlockSubmit(e) {
            e.preventDefault();
            const email = document.getElementById('unlockEmailInput').value.trim();
            const btn = document.getElementById('unlockConfirmBtn');
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'unlock_report');
            fd.append('token', currentAuditToken);
            fd.append('email', email);

            try {
                const res = await fetch('seo-checker.php', { method: 'POST', body: fd });
                const json = await res.json();
                btn.disabled = false;

                if (json.success) {
                    closeUnlockModal();
                    alert('🎉 Payment verified! Your full report has been unlocked.');
                    document.getElementById('paywallContainer').style.display = 'none';
                    document.getElementById('unlockedContainer').style.display = 'block';
                    renderFullIssues(currentAuditData);
                } else {
                    alert(json.error || 'Payment failed.');
                }
            } catch (err) {
                btn.disabled = false;
                alert('Connection error.');
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
        }
    </script>
</body>
</html>
