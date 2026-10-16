<?php
/**
 * ============================================================
 *  Super Admin — SEO Checker, Lead Engine & Settings
 * ============================================================
 *  100% FREE & UNLIMITED for Superadmin:
 *    1. Real-time website auditor with full diagnostics & code generator
 *    2. Lead Generation & Visitor Audit Log
 *    3. Platform SEO Metrics & Paid Unlock Revenue
 *    4. Price & Configuration settings
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/seo_checker.php';

requireSuperAdminLogin();
seo_ensure_schema($conn);

$sa_active = 'seo_checker';
$sa_heading = 'SEO Checker & Leads';
$sa_subtitle = 'Audit any website for free, track public audit leads, and manage report pricing.';

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

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    if (sa_csrf_ok()) {
        $new_price = max(0, (float)($_POST['seo_report_price'] ?? 19.00));
        @$conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('seo_report_price', '{$new_price}') ON DUPLICATE KEY UPDATE setting_value = '{$new_price}'");
        sa_flash('success', 'SEO Checker price setting updated successfully.');
        header('Location: seo_checker.php');
        exit();
    }
}

// Handle Manual Unlock Action
if (isset($_GET['unlock_id'])) {
    $uid = (int)$_GET['unlock_id'];
    $conn->query("UPDATE seo_audits SET is_paid = 1, paid_at = NOW() WHERE id = {$uid}");
    sa_flash('success', 'Audit report manually marked as paid/unlocked.');
    header('Location: seo_checker.php');
    exit();
}

// Handle Delete Audit Action
if (isset($_GET['delete_id'])) {
    $did = (int)$_GET['delete_id'];
    $conn->query("DELETE FROM seo_audits WHERE id = {$did}");
    sa_flash('success', 'Audit record deleted.');
    header('Location: seo_checker.php');
    exit();
}

// Handle AJAX Scan from Superadmin
if (isset($_POST['action']) && $_POST['action'] === 'run_sa_scan') {
    header('Content-Type: application/json; charset=utf-8');
    $target_url = trim((string)($_POST['url'] ?? ''));

    if ($target_url === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter a website URL.']);
        exit();
    }

    $analysis = seo_analyze_url($target_url);
    if (!empty($analysis['error'])) {
        echo json_encode(['success' => false, 'error' => $analysis['error']]);
        exit();
    }

    // Superadmin is always 100% free / paid
    $saved = seo_save_audit($conn, $analysis, 'superadmin', null, 1);

    echo json_encode([
        'success' => true,
        'token' => $saved['token'],
        'data' => $analysis
    ]);
    exit();
}

// Platform SEO Metrics
$total_audits = (int)sa_scalar($conn, "SELECT COUNT(*) FROM seo_audits", 0, 'seo_audits');
$public_audits = (int)sa_scalar($conn, "SELECT COUNT(*) FROM seo_audits WHERE user_type = 'public'", 0, 'seo_audits');
$tenant_audits = (int)sa_scalar($conn, "SELECT COUNT(*) FROM seo_audits WHERE user_type = 'tenant'", 0, 'seo_audits');
$paid_audits = (int)sa_scalar($conn, "SELECT COUNT(*) FROM seo_audits WHERE is_paid = 1 AND user_type = 'public'", 0, 'seo_audits');
$total_revenue = (float)sa_scalar($conn, "SELECT SUM(payment_amount) FROM seo_audits WHERE is_paid = 1 AND user_type = 'public'", 0.00, 'seo_audits');

// Fetch recent audit leads
$audits_list = [];
$res_list = $conn->query("SELECT a.*, t.company_name AS tenant_company_name 
                          FROM seo_audits a 
                          LEFT JOIN tenants t ON a.tenant_id = t.id 
                          ORDER BY a.created_at DESC LIMIT 30");
if ($res_list) {
    while ($row = $res_list->fetch_assoc()) {
        $audits_list[] = $row;
    }
}

// If viewing a specific audit
$view_audit = null;
if (!empty($_GET['token'])) {
    $view_audit = seo_get_audit($conn, trim($_GET['token']));
}

include __DIR__ . '/_shell.php';
?>

<div class="sa-container">

    <!-- PLATFORM METRICS -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:24px;">
        <div class="sa-card" style="padding:18px;">
            <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Total SEO Scans</div>
            <div style="font-size:28px;font-weight:900;color:var(--text-main);"><?php echo number_format($total_audits); ?></div>
            <div style="font-size:12px;color:#38bdf8;margin-top:4px;"><?php echo $public_audits; ?> Public Leads • <?php echo $tenant_audits; ?> Tenant Scans</div>
        </div>
        <div class="sa-card" style="padding:18px;">
            <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Paid Public Unlocks</div>
            <div style="font-size:28px;font-weight:900;color:var(--lime,#c2f542);"><?php echo number_format($paid_audits); ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Unlocked paid reports</div>
        </div>
        <div class="sa-card" style="padding:18px;">
            <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Report Revenue</div>
            <div style="font-size:28px;font-weight:900;color:#10b981;"><?php echo sa_money($total_revenue); ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">From public audit sales</div>
        </div>
        <div class="sa-card" style="padding:18px;">
            <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Public Price</div>
            <div style="font-size:28px;font-weight:900;color:#f59e0b;"><?php echo sa_money($report_price); ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Free for tenants &amp; superadmin</div>
        </div>
    </div>

    <!-- SCANNER TOOL CARD -->
    <div class="sa-card" style="padding:24px;margin-bottom:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:12px;">
            <div>
                <h3 style="font-size:18px;font-weight:800;color:var(--text-main);margin:0 0 4px;display:flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-magnifying-glass" style="color:var(--lime,#c2f542);"></i>
                    <span>Superadmin Instant SEO Auditor</span>
                </h3>
                <p style="color:var(--text-muted);font-size:13.5px;margin:0;">Run an immediate deep audit on any URL. Superadmin scans are 100% free with full diagnostics unlocked.</p>
            </div>
            <span style="background:rgba(194,245,66,0.15);color:var(--lime,#c2f542);font-size:11px;font-weight:800;padding:4px 10px;border-radius:20px;">
                100% FREE ACCESS
            </span>
        </div>

        <form id="saScanForm" onsubmit="handleSaScan(event)" style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:280px;">
                <input type="text" id="saUrlInput" required placeholder="https://example.com" value="<?php echo htmlspecialchars($view_audit['url'] ?? ''); ?>" style="width:100%;background:var(--bg-input, rgba(255,255,255,0.05));border:1px solid var(--border-line, rgba(255,255,255,0.15));color:var(--text-main);padding:12px 16px;border-radius:10px;font-size:14px;outline:none;">
            </div>
            <button type="submit" id="saScanBtn" class="sa-btn sa-btn-primary" style="background:var(--lime,#c2f542);color:#0f172a;border:none;padding:12px 24px;font-weight:700;border-radius:10px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-play"></i> <span>Scan Website</span>
            </button>
        </form>

        <div id="saProgressBox" style="display:none;margin-top:16px;">
            <div style="font-size:13px;color:var(--text-muted);margin-bottom:6px;">Analyzing website performance and ranking factors...</div>
            <div style="height:6px;background:rgba(255,255,255,0.08);border-radius:4px;overflow:hidden;">
                <div id="saProgressBar" style="width:30%;height:100%;background:linear-gradient(90deg, #38bdf8, var(--lime,#c2f542));transition:width 0.4s;"></div>
            </div>
        </div>
    </div>

<style>
/* Superadmin SEO Checker Theme Styles */
.sa-health-card {
    border-radius: 20px;
    padding: 28px;
    margin-bottom: 24px;
    transition: background 0.3s, border-color 0.3s;
}
/* Light mode */
.sa-health-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #0f172a;
    box-shadow: 0 6px 24px rgba(15, 23, 42, 0.05);
}
.sa-health-card .sa-card-border-line {
    border-bottom: 1px solid #e2e8f0;
}
.sa-health-card .sa-domain-title {
    color: #0f172a;
}
.sa-health-card .sa-score-number {
    color: #0f172a;
}
.sa-health-card .sa-gauge-bg {
    stroke: #f1f5f9;
}
.sa-health-card .sa-issues-heading {
    color: #0f172a;
}
.sa-health-card .sa-issues-sub {
    color: #475569;
}
.sa-health-card .sa-btn-print {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    color: #0f172a;
}
.sa-health-card .sa-btn-print:hover {
    background: #f1f5f9;
}

/* Dark mode */
:root[data-theme='dark'] .sa-health-card {
    background: var(--card-bg, #0f1f2e);
    border-color: rgba(255, 255, 255, 0.08);
    color: var(--text-main, #f8fafc);
    box-shadow: none;
}
:root[data-theme='dark'] .sa-health-card .sa-card-border-line {
    border-bottom-color: rgba(255, 255, 255, 0.08);
}
:root[data-theme='dark'] .sa-health-card .sa-domain-title {
    color: #f8fafc;
}
:root[data-theme='dark'] .sa-health-card .sa-score-number {
    color: #f8fafc;
}
:root[data-theme='dark'] .sa-health-card .sa-gauge-bg {
    stroke: rgba(255, 255, 255, 0.08);
}
:root[data-theme='dark'] .sa-health-card .sa-issues-heading {
    color: #f8fafc;
}
:root[data-theme='dark'] .sa-health-card .sa-issues-sub {
    color: #94a3b8;
}
:root[data-theme='dark'] .sa-health-card .sa-btn-print {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.15);
    color: #f8fafc;
}
:root[data-theme='dark'] .sa-health-card .sa-btn-print:hover {
    background: rgba(255, 255, 255, 0.12);
}
</style>

    <!-- LIVE REPORT VIEW (IF AUDITED) -->
    <div id="saAuditResultBox" style="<?php echo $view_audit ? 'display:block;' : 'display:none;'; ?>">
        <?php
        $va_data = $view_audit['details'] ?? [];
        $va_score = (int)($va_data['score'] ?? 0);
        $va_issues = (int)($va_data['issues_count'] ?? 0);
        $va_autofix = (int)($va_data['auto_fix_count'] ?? 0);
        $va_color = $va_score >= 80 ? '#10b981' : ($va_score >= 50 ? '#f59e0b' : '#ef4444');
        $va_dash = 440 - (440 * $va_score / 100);
        ?>
        <div class="sa-health-card">
            <div class="sa-card-border-line" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:12px;flex-wrap:wrap;gap:10px;">
                <div>
                    <span style="font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#64748b;">Website Health Diagnostic</span>
                    <h4 class="sa-domain-title" style="font-size:19px;font-weight:900;margin:2px 0 0;" id="saAuditedDomain"><?php echo htmlspecialchars($view_audit['domain'] ?? ''); ?></h4>
                </div>
                <button type="button" onclick="window.print()" class="sa-btn-print" style="padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                    <i class="fa-solid fa-print"></i> Print Executive Report
                </button>
            </div>

            <div style="display:grid;grid-template-columns:180px 1fr;gap:28px;align-items:center;margin-bottom:24px;">
                <div style="position:relative;width:150px;height:150px;margin:0 auto;">
                    <svg viewBox="0 0 160 160" style="transform:rotate(-90deg);width:100%;height:100%;">
                        <circle cx="80" cy="80" r="70" fill="none" class="sa-gauge-bg" stroke-width="14"></circle>
                        <circle id="saGaugeCircle" cx="80" cy="80" r="70" fill="none" stroke="<?php echo $va_color; ?>" stroke-width="14" stroke-linecap="round" style="stroke-dasharray:440;stroke-dashoffset:<?php echo $va_dash; ?>;transition:stroke-dashoffset 1s ease;"></circle>
                    </svg>
                    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;">
                        <div id="saGaugeNum" class="sa-score-number" style="font-size:44px;font-weight:900;line-height:1;"><?php echo $va_score; ?></div>
                        <div style="font-size:13px;font-weight:700;color:#94a3b8;">/100</div>
                    </div>
                </div>

                <div>
                    <div class="sa-issues-heading" style="display:flex;align-items:center;gap:10px;font-size:28px;font-weight:900;margin-bottom:4px;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;font-size:24px;"></i>
                        <span><span id="saIssuesCount"><?php echo $va_issues; ?></span> Issues Identified</span>
                    </div>
                    <p class="sa-issues-sub" style="font-size:14.5px;margin-bottom:12px;">Complete diagnostics unlocked for platform superadmin.</p>
                    <div style="display:inline-flex;align-items:center;gap:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:700;">
                        <i class="fa-solid fa-circle-check" style="color:#10b981;"></i>
                        <span><strong id="saAutoFixCount"><?php echo $va_autofix; ?></strong> issues have automatic copy-paste code fixes available.</span>
                    </div>
                </div>
            </div>

            <!-- CODE GENERATOR -->
            <div style="background:#07101c;border:1px solid #1e3653;border-radius:12px;padding:16px;margin-top:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <strong style="color:#fff;font-size:13px;"><i class="fa-solid fa-code" style="color:var(--lime,#c2f542);"></i> Generated Meta Tags &amp; Schema</strong>
                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('saCodeArea').textContent); alert('Copied!');" style="background:rgba(255,255,255,0.1);color:#fff;border:none;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;">Copy</button>
                </div>
                <pre id="saCodeArea" style="margin:0;font-family:monospace;font-size:12px;color:#cbd5e1;white-space:pre-wrap;max-height:180px;overflow-y:auto;"><?php echo htmlspecialchars($va_data['code_snippets']['meta_tags'] ?? ''); ?></pre>
            </div>
        </div>
    </div>

    <!-- LEADS & AUDITS DATABASE TABLE -->
    <div class="sa-card" style="padding:24px;margin-bottom:24px;">
        <h3 style="font-size:18px;font-weight:800;color:var(--text-main);margin-bottom:14px;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-users-viewfinder" style="color:var(--lime,#c2f542);"></i>
            <span>Platform SEO Audit Log &amp; Visitor Leads</span>
        </h3>

        <div style="overflow-x:auto;">
            <table class="sa-table" style="width:100%;font-size:13px;">
                <thead>
                    <tr style="text-align:left;color:var(--text-muted);border-bottom:1px solid rgba(255,255,255,0.08);">
                        <th style="padding:10px;">Domain / Target URL</th>
                        <th style="padding:10px;">User Type</th>
                        <th style="padding:10px;">Visitor Email / Tenant</th>
                        <th style="padding:10px;">Score</th>
                        <th style="padding:10px;">Status</th>
                        <th style="padding:10px;">Date</th>
                        <th style="padding:10px;text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audits_list)): ?>
                        <tr><td colspan="7" style="padding:20px;text-align:center;color:var(--text-muted);">No SEO audits recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($audits_list as $row): ?>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                            <td style="padding:12px 10px;font-weight:600;color:var(--text-main);">
                                <a href="<?php echo htmlspecialchars($row['url']); ?>" target="_blank" rel="noopener" style="color:#38bdf8;text-decoration:none;">
                                    <?php echo htmlspecialchars($row['domain']); ?>
                                </a>
                            </td>
                            <td style="padding:12px 10px;">
                                <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;text-transform:uppercase;<?php echo $row['user_type'] === 'tenant' ? 'background:rgba(194,245,66,0.15);color:var(--lime,#c2f542);' : ($row['user_type'] === 'superadmin' ? 'background:rgba(168,85,247,0.15);color:#c084fc;' : 'background:rgba(56,189,248,0.15);color:#38bdf8;'); ?>">
                                    <?php echo htmlspecialchars($row['user_type']); ?>
                                </span>
                            </td>
                            <td style="padding:12px 10px;color:var(--text-main);">
                                <?php if ($row['user_type'] === 'tenant'): ?>
                                    <strong>🏢 <?php echo htmlspecialchars($row['tenant_company_name'] ?? 'Tenant #' . $row['tenant_id']); ?></strong>
                                <?php elseif (!empty($row['visitor_email'])): ?>
                                    <span>✉️ <?php echo htmlspecialchars($row['visitor_email']); ?></span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">Anonymous Visitor</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 10px;">
                                <strong style="color:<?php echo $row['score'] >= 80 ? '#10b981' : ($row['score'] >= 50 ? '#f59e0b' : '#ef4444'); ?>;">
                                    <?php echo (int)$row['score']; ?>/100 (<?php echo htmlspecialchars($row['grade']); ?>)
                                </strong>
                            </td>
                            <td style="padding:12px 10px;">
                                <?php if (!empty($row['is_paid'])): ?>
                                    <span style="background:rgba(16,185,129,0.15);color:#10b981;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">
                                        <?php echo $row['user_type'] === 'public' ? 'PAID UNLOCKED' : 'FREE UNLOCKED'; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="background:rgba(239,68,68,0.15);color:#ef4444;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">
                                        LOCKED PREVIEW
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 10px;color:var(--text-muted);">
                                <?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?>
                            </td>
                            <td style="padding:12px 10px;text-align:right;">
                                <a href="seo_checker.php?token=<?php echo urlencode($row['access_token']); ?>" style="color:#38bdf8;font-weight:700;font-size:12px;margin-right:8px;text-decoration:none;">View</a>
                                <?php if (empty($row['is_paid'])): ?>
                                    <a href="seo_checker.php?unlock_id=<?php echo $row['id']; ?>" onclick="return confirm('Manually mark as paid?');" style="color:var(--lime,#c2f542);font-weight:700;font-size:12px;margin-right:8px;text-decoration:none;">Unlock</a>
                                <?php endif; ?>
                                <a href="seo_checker.php?delete_id=<?php echo $row['id']; ?>" onclick="return confirm('Delete this audit log?');" style="color:#ef4444;font-weight:700;font-size:12px;text-decoration:none;">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PRICING & CONFIGURATION CARD -->
    <div class="sa-card" style="padding:24px;">
        <h3 style="font-size:18px;font-weight:800;color:var(--text-main);margin-bottom:6px;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-sliders" style="color:var(--lime,#c2f542);"></i>
            <span>SEO Checker Monetization &amp; Pricing</span>
        </h3>
        <p style="color:var(--text-muted);font-size:13.5px;margin-bottom:18px;">
            Configure how much public visitors pay to unlock their full in-depth audit report. Remember: <strong>Tenants and Superadmins are always 100% free</strong>.
        </p>

        <form method="POST" action="seo_checker.php" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="save_settings">

            <div style="flex:1;min-width:240px;">
                <label style="display:block;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">
                    Public Report Unlock Price (<?php echo sa_e($currency_symbol); ?>)
                </label>
                <input type="number" step="0.01" min="0" name="seo_report_price" value="<?php echo number_format($report_price, 2, '.', ''); ?>" style="width:100%;background:var(--bg-input, rgba(255,255,255,0.05));border:1px solid var(--border-line, rgba(255,255,255,0.15));color:var(--text-main);padding:10px 14px;border-radius:8px;font-size:14px;outline:none;">
            </div>

            <button type="submit" class="sa-btn sa-btn-primary" style="background:var(--lime,#c2f542);color:#0f172a;border:none;padding:11px 22px;font-weight:700;border-radius:8px;cursor:pointer;">
                Save Pricing
            </button>
        </form>
    </div>

</div>

<script>
async function handleSaScan(e) {
    e.preventDefault();
    const url = document.getElementById('saUrlInput').value.trim();
    const btn = document.getElementById('saScanBtn');
    if (!url) return;

    btn.disabled = true;
    document.getElementById('saProgressBox').style.display = 'block';

    let pct = 25;
    const bar = document.getElementById('saProgressBar');
    const timer = setInterval(() => {
        if (pct < 90) {
            pct += 15;
            bar.style.width = pct + '%';
        }
    }, 400);

    try {
        const fd = new FormData();
        fd.append('action', 'run_sa_scan');
        fd.append('url', url);

        const res = await fetch('seo_checker.php', { method: 'POST', body: fd });
        const json = await res.json();
        clearInterval(timer);

        bar.style.width = '100%';

        if (!json.success) {
            alert(json.error || 'Audit failed.');
            document.getElementById('saProgressBox').style.display = 'none';
            btn.disabled = false;
            return;
        }

        setTimeout(() => {
            document.getElementById('saProgressBox').style.display = 'none';
            btn.disabled = false;
            renderSaAudit(json.data);
        }, 400);

    } catch (err) {
        clearInterval(timer);
        alert('Audit error.');
        document.getElementById('saProgressBox').style.display = 'none';
        btn.disabled = false;
    }
}

function renderSaAudit(data) {
    document.getElementById('saAuditResultBox').style.display = 'block';
    document.getElementById('saAuditedDomain').textContent = data.domain || '';

    const score = data.score || 0;
    document.getElementById('saGaugeNum').textContent = score;
    const color = score >= 80 ? '#10b981' : ($score >= 50 ? '#f59e0b' : '#ef4444');
    const dash = 440 - (440 * score / 100);

    const circle = document.getElementById('saGaugeCircle');
    circle.style.stroke = color;
    circle.style.strokeDashoffset = dash;

    document.getElementById('saIssuesCount').textContent = data.issues_count || 0;
    document.getElementById('saAutoFixCount').textContent = data.auto_fix_count || 0;

    if (data.code_snippets) {
        document.getElementById('saCodeArea').textContent = data.code_snippets.meta_tags || '';
    }

    document.getElementById('saAuditResultBox').scrollIntoView({ behavior: 'smooth' });
}
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
