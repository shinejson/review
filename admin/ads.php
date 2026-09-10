<?php
/**
 * ============================================================
 *  Optibiz Workspace — Ads & Reputation Funnel
 * ============================================================
 *  Powers the end-to-end advertising funnel:
 *    1. Funnel Overview & ROAS Calculator
 *    2. Ad Pixels & Meta CAPI / Google Tag Setup
 *    3. Review-to-Ad Creative Studio & UTM Link Builder
 *    4. Campaign Attribution & Traffic Analytics
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/ad_conversions.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

admin_ensure_schema($conn);

/* ---------- Filters & Routing ---------- */
$days       = admin_period_days($_GET['days'] ?? 30);
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
$active_tab = $_GET['tab'] ?? 'funnel';
$allowed_tabs = ['funnel', 'pixels', 'creatives', 'attribution'];
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'funnel';
}

$companies_list = admin_companies($conn, $tenant_id, $is_tenant);
$valid_company = false;
foreach ($companies_list as $row) {
    if ((int)$row['id'] === $company_id) {
        $valid_company = true;
        break;
    }
}
if (!$valid_company && count($companies_list) > 0) {
    // Default to first company if not explicitly selected
    $company_id = (int)$companies_list[0]['id'];
}

$ad_config = getTenantAdConfig($conn, $tenant_id, $company_id);

/* ---------- POST Handlers ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect("ads.php?tab={$active_tab}&company_id={$company_id}&days={$days}");
    }

    $action = $_POST['action'] ?? '';

    // Save Ad Pixels & CAPI configuration
    if ($action === 'save_pixels') {
        $data = [
            'meta_pixel_id'               => sanitize($_POST['meta_pixel_id'] ?? ''),
            'meta_capi_token'             => trim((string)($_POST['meta_capi_token'] ?? '')),
            'meta_test_event_code'        => sanitize($_POST['meta_test_event_code'] ?? ''),
            'google_ads_conversion_id'    => sanitize($_POST['google_ads_conversion_id'] ?? ''),
            'google_ads_conversion_label' => sanitize($_POST['google_ads_conversion_label'] ?? ''),
            'tiktok_pixel_id'             => sanitize($_POST['tiktok_pixel_id'] ?? ''),
            'is_active'                   => isset($_POST['is_active']) ? 1 : 0
        ];

        // Keep existing token if field is submitted blank during an update
        if ($data['meta_capi_token'] === '' && $ad_config && !empty($ad_config['meta_capi_token'])) {
            $data['meta_capi_token'] = $ad_config['meta_capi_token'];
        }

        $ok = saveTenantAdConfig($conn, $tenant_id, $company_id, $data);
        if ($ok) {
            sa_flash('success', 'Ad tracking pixels & Conversions API settings updated successfully.');
        } else {
            sa_flash('error', 'Could not save ad configuration. Please try again.');
        }
        redirect("ads.php?tab=pixels&company_id={$company_id}&days={$days}");
    }

    // Send Test Event to Meta CAPI
    if ($action === 'send_test_event') {
        $platform = sanitize($_POST['platform'] ?? 'meta');
        $testResult = sendTestAdConversionEvent($conn, $tenant_id, $company_id, $platform);

        if (!empty($testResult['ok'])) {
            $code = $testResult['http_code'] ?? 200;
            sa_flash('success', "Live test event dispatched successfully to {$platform} (HTTP {$code}). Check your Events Manager test panel.");
        } else {
            $err = $testResult['error'] ?? 'Unknown connection error';
            sa_flash('error', "Test event failed: {$err}");
        }
        redirect("ads.php?tab=pixels&company_id={$company_id}&days={$days}");
    }
}

/* ---------- Query Data ---------- */
$funnel_metrics  = getAdsFunnelMetrics($conn, $tenant_id, $company_id, $days);
$attribution_rows = getAdCampaignAttributionBreakdown($conn, $tenant_id, $company_id, $days);

// Fetch recent 5-star customer reviews for the Review-to-Ad Creative Studio
$top_reviews = [];
if ($company_id > 0) {
    $rev_stmt = $conn->prepare("
        SELECT r.*, c.company_name 
        FROM ratings r 
        JOIN customers c ON c.id = r.company_id 
        WHERE r.company_id = ? AND r.rating >= 4 AND r.reported = 0 
        ORDER BY r.rating DESC, r.created_at DESC 
        LIMIT 10
    ");
    if ($rev_stmt) {
        $rev_stmt->bind_param("i", $company_id);
        $rev_stmt->execute();
        $top_reviews = $rev_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rev_stmt->close();
    }
}

// Current company details for link builder
$selected_company = null;
foreach ($companies_list as $c) {
    if ((int)$c['id'] === $company_id) {
        $selected_company = $c;
        break;
    }
}
$company_name = $selected_company['company_name'] ?? 'Your Business';

// Base public URL for campaign link builder
$base_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$app_path    = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$public_rate_url = "{$base_scheme}://{$base_host}{$app_path}/rate/?company={$company_id}";

/* ---------- Render Page ---------- */
$robots    = 'noindex, nofollow';
$BASE      = '../';
$pageTitle = 'Ads & Funnel';
$activeNav = 'ads';
include __DIR__ . '/_shell.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:24px;">
    <div>
        <h1 style="font-size:26px;font-weight:800;letter-spacing:-0.5px;margin:0 0 6px;">Ads &amp; Reputation Funnel</h1>
        <p style="color:var(--muted);font-size:13px;margin:0;">Connect Google &amp; Social Ads, track WhatsApp sales leads, and turn 5-star reviews into high-converting campaigns.</p>
    </div>

    <!-- Company & Period Filter Form -->
    <form method="GET" class="filter-form" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
        <?php if (count($companies_list) > 1): ?>
        <select name="company_id" onchange="this.form.submit()" style="padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:#fff;color:var(--ink);font-size:12px;font-weight:600;">
            <?php foreach ($companies_list as $comp): ?>
            <option value="<?php echo (int)$comp['id']; ?>" <?php echo $company_id === (int)$comp['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($comp['company_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <select name="days" onchange="this.form.submit()" style="padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:#fff;color:var(--ink);font-size:12px;font-weight:600;">
            <option value="7" <?php echo $days === 7 ? 'selected' : ''; ?>>Last 7 days</option>
            <option value="30" <?php echo $days === 30 ? 'selected' : ''; ?>>Last 30 days</option>
            <option value="90" <?php echo $days === 90 ? 'selected' : ''; ?>>Last 90 days</option>
        </select>
    </form>
</div>

<!-- Navigation Tabs -->
<div style="display:flex;gap:8px;margin-bottom:24px;border-bottom:1px solid var(--line);padding-bottom:12px;overflow-x:auto;white-space:nowrap;">
    <a href="?tab=funnel&company_id=<?php echo $company_id; ?>&days=<?php echo $days; ?>" 
       class="admin-tab-btn <?php echo $active_tab === 'funnel' ? 'is-active' : ''; ?>"
       style="padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .15s ease;<?php echo $active_tab === 'funnel' ? 'background:var(--navy);color:var(--lime);' : 'background:rgba(0,0,0,0.04);color:var(--muted);'; ?>">
        <span>⚡</span> Funnel Overview &amp; ROI
    </a>
    <a href="?tab=pixels&company_id=<?php echo $company_id; ?>&days=<?php echo $days; ?>" 
       class="admin-tab-btn <?php echo $active_tab === 'pixels' ? 'is-active' : ''; ?>"
       style="padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .15s ease;<?php echo $active_tab === 'pixels' ? 'background:var(--navy);color:var(--lime);' : 'background:rgba(0,0,0,0.04);color:var(--muted);'; ?>">
        <span>🔌</span> Pixels &amp; Meta CAPI
    </a>
    <a href="?tab=creatives&company_id=<?php echo $company_id; ?>&days=<?php echo $days; ?>" 
       class="admin-tab-btn <?php echo $active_tab === 'creatives' ? 'is-active' : ''; ?>"
       style="padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .15s ease;<?php echo $active_tab === 'creatives' ? 'background:var(--navy);color:var(--lime);' : 'background:rgba(0,0,0,0.04);color:var(--muted);'; ?>">
        <span>🎨</span> Review Ad Creatives &amp; UTMs
    </a>
    <a href="?tab=attribution&company_id=<?php echo $company_id; ?>&days=<?php echo $days; ?>" 
       class="admin-tab-btn <?php echo $active_tab === 'attribution' ? 'is-active' : ''; ?>"
       style="padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .15s ease;<?php echo $active_tab === 'attribution' ? 'background:var(--navy);color:var(--lime);' : 'background:rgba(0,0,0,0.04);color:var(--muted);'; ?>">
        <span>📊</span> Campaign Attribution
    </a>
</div>

<?php if ($active_tab === 'funnel'): ?>
<!-- ============================================================
     TAB 1: FUNNEL OVERVIEW & ROI CALCULATOR
     ============================================================ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:24px;">
    <!-- Stage 1 -->
    <div class="metric-card" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;position:relative;">
        <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;">1. Ad Clicks / Reach</span>
        <strong style="font-size:28px;font-weight:800;display:block;margin:6px 0;"><?php echo number_format($funnel_metrics['stage_1_ad_clicks']); ?></strong>
        <small style="color:var(--muted);font-size:11px;">Tracked campaign clicks</small>
        <span style="position:absolute;top:16px;right:16px;background:#e0f2fe;color:#0369a1;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;">TOFU</span>
    </div>

    <!-- Stage 2 -->
    <div class="metric-card" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;position:relative;">
        <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;">2. Profile Page Views</span>
        <strong style="font-size:28px;font-weight:800;display:block;margin:6px 0;"><?php echo number_format($funnel_metrics['stage_2_page_views']); ?></strong>
        <small style="color:#059669;font-weight:700;font-size:11px;">
            <?php echo $funnel_metrics['rate_click_to_view']; ?>% land rate
        </small>
        <span style="position:absolute;top:16px;right:16px;background:#fef3c7;color:#b45309;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;">MOFU</span>
    </div>

    <!-- Stage 3 -->
    <div class="metric-card" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;position:relative;">
        <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;">3. Engaged Visitors</span>
        <strong style="font-size:28px;font-weight:800;display:block;margin:6px 0;"><?php echo number_format($funnel_metrics['stage_3_engagements']); ?></strong>
        <small style="color:#059669;font-weight:700;font-size:11px;">
            <?php echo $funnel_metrics['rate_view_to_engage']; ?>% view-to-engage
        </small>
        <span style="position:absolute;top:16px;right:16px;background:#ede9fe;color:#6d28d9;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;">CONSIDER</span>
    </div>

    <!-- Stage 4 -->
    <div class="metric-card" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px;position:relative;">
        <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;">4. WhatsApp Sales Leads</span>
        <strong style="font-size:28px;font-weight:800;display:block;margin:6px 0;color:#059669;"><?php echo number_format($funnel_metrics['whatsapp_leads']); ?></strong>
        <small style="color:#059669;font-weight:700;font-size:11px;">
            <?php echo $funnel_metrics['rate_engage_to_lead']; ?>% engage-to-lead
        </small>
        <span style="position:absolute;top:16px;right:16px;background:#dcfce7;color:#15803d;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;">BOFU</span>
    </div>
</div>

<!-- Visual Funnel & ROAS Calculator Grid -->
<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:20px;margin-bottom:24px;">
    <!-- Visual Funnel Flow -->
    <div class="panel" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;">
        <h3 style="font-size:17px;font-weight:800;margin:0 0 16px;display:flex;align-items:center;gap:8px;">
            <span>📈</span> Funnel Progression
        </h3>
        <p style="color:var(--muted);font-size:13px;margin:0 0 20px;">Live conversion efficiency across the customer acquisition journey:</p>

        <div style="display:flex;flex-direction:column;gap:14px;">
            <!-- Step 1 -->
            <div style="background:var(--bg);border-radius:10px;padding:14px 18px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;margin-bottom:6px;">
                    <span>1. Ad Impressions &amp; Clicks</span>
                    <span>100% (<?php echo number_format($funnel_metrics['stage_1_ad_clicks']); ?>)</span>
                </div>
                <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                    <div style="width:100%;height:100%;background:#3b82f6;"></div>
                </div>
            </div>

            <!-- Step 2 -->
            <div style="background:var(--bg);border-radius:10px;padding:14px 18px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;margin-bottom:6px;">
                    <span>2. Profile Page View (Review Landing)</span>
                    <span style="color:#059669;"><?php echo $funnel_metrics['rate_click_to_view']; ?>% (<?php echo number_format($funnel_metrics['stage_2_page_views']); ?>)</span>
                </div>
                <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                    <div style="width:<?php echo max(5, min(100, $funnel_metrics['rate_click_to_view'])); ?>%;height:100%;background:#10b981;"></div>
                </div>
            </div>

            <!-- Step 3 -->
            <div style="background:var(--bg);border-radius:10px;padding:14px 18px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;margin-bottom:6px;">
                    <span>3. Social Proof Engagement (Reviews/Q&amp;A Read)</span>
                    <span style="color:#8b5cf6;"><?php echo $funnel_metrics['rate_view_to_engage']; ?>% (<?php echo number_format($funnel_metrics['stage_3_engagements']); ?>)</span>
                </div>
                <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                    <div style="width:<?php echo max(5, min(100, $funnel_metrics['rate_view_to_engage'])); ?>%;height:100%;background:#8b5cf6;"></div>
                </div>
            </div>

            <!-- Step 4 -->
            <div style="background:var(--bg);border-radius:10px;padding:14px 18px;">
                <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;margin-bottom:6px;">
                    <span>4. Bottom of Funnel Conversions (WhatsApp Leads)</span>
                    <span style="color:#15803d;font-weight:800;"><?php echo $funnel_metrics['overall_funnel_cvr']; ?>% overall CVR (<?php echo number_format($funnel_metrics['stage_4_conversions']); ?>)</span>
                </div>
                <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                    <div style="width:<?php echo max(5, min(100, $funnel_metrics['overall_funnel_cvr'])); ?>%;height:100%;background:#059669;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ROAS & Cost Per Lead Calculator -->
    <div class="panel" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;">
        <h3 style="font-size:17px;font-weight:800;margin:0 0 12px;display:flex;align-items:center;gap:8px;">
            <span>💰</span> Cost Per Lead Calculator
        </h3>
        <p style="color:var(--muted);font-size:12px;margin:0 0 16px;">Estimate your customer acquisition costs based on total ad spend:</p>

        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:6px;">Total Ad Spend ($)</label>
            <input type="number" id="calcSpend" value="100" min="1" step="5" style="width:100%;padding:10px 14px;border:1px solid var(--line);border-radius:8px;font-size:15px;font-weight:700;box-sizing:border-box;">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
            <div style="background:var(--bg);border-radius:10px;padding:12px;text-align:center;">
                <span style="display:block;font-size:11px;color:var(--muted);font-weight:600;">Cost Per Page View</span>
                <strong id="calcCostPerView" style="font-size:18px;font-weight:800;color:var(--ink);">$0.00</strong>
            </div>
            <div style="background:rgba(5,150,105,0.08);border:1px solid rgba(5,150,105,0.2);border-radius:10px;padding:12px;text-align:center;">
                <span style="display:block;font-size:11px;color:#059669;font-weight:700;">Cost Per WhatsApp Lead</span>
                <strong id="calcCostPerLead" style="font-size:18px;font-weight:800;color:#059669;">$0.00</strong>
            </div>
        </div>

        <div style="background:#f8fafc;border-radius:8px;padding:12px;font-size:12px;color:var(--muted);line-height:1.5;">
            💡 <strong>Reputation Advantage:</strong> Sending ads to an Optibiz verified profile typically reduces Cost Per Lead by <strong>35% - 50%</strong> compared to sending cold traffic to an unrated generic homepage.
        </div>
    </div>
</div>

<script>
// Interactive ROAS / CPL Calculator Logic
(function() {
    var spendInput = document.getElementById('calcSpend');
    var costPerView = document.getElementById('calcCostPerView');
    var costPerLead = document.getElementById('calcCostPerLead');
    var views = <?php echo max(1, (int)$funnel_metrics['stage_2_page_views']); ?>;
    var leads = <?php echo max(1, (int)$funnel_metrics['whatsapp_leads']); ?>;

    function recalculate() {
        var spend = parseFloat(spendInput.value) || 0;
        if (spend <= 0) {
            costPerView.textContent = '$0.00';
            costPerLead.textContent = '$0.00';
            return;
        }
        var cpv = (spend / views).toFixed(2);
        var cpl = (spend / leads).toFixed(2);
        costPerView.textContent = '$' + cpv;
        costPerLead.textContent = '$' + cpl;
    }
    if (spendInput) {
        spendInput.addEventListener('input', recalculate);
        recalculate();
    }
})();
</script>

<?php elseif ($active_tab === 'pixels'): ?>
<!-- ============================================================
     TAB 2: PIXELS & META CAPI CONFIGURATION
     ============================================================ -->
<div style="display:grid;grid-template-columns:1.5fr 1fr;gap:20px;">
    <!-- Pixels Form Card -->
    <div class="panel" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;">
        <h3 style="font-size:18px;font-weight:800;margin:0 0 8px;display:flex;align-items:center;gap:8px;">
            <span>🔌</span> Connect Ad Accounts &amp; Pixels
        </h3>
        <p style="color:var(--muted);font-size:13px;margin:0 0 24px;">Configure your tracking tags. Once saved, pixel events and server-side Conversions API (CAPI) signals will fire automatically.</p>

        <form method="POST" action="ads.php?tab=pixels&company_id=<?php echo $company_id; ?>&days=<?php echo $days; ?>">
            <input type="hidden" name="action" value="save_pixels">
            <?php echo sa_csrf_field(); ?>

            <!-- Status Toggle -->
            <div style="margin-bottom:24px;display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--bg);border-radius:10px;">
                <input type="checkbox" id="is_active" name="is_active" value="1" <?php echo empty($ad_config) || (int)($ad_config['is_active'] ?? 1) === 1 ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;">
                <label for="is_active" style="font-size:13px;font-weight:700;cursor:pointer;">Enable Ad Tracking on Public Rating Portal</label>
            </div>

            <!-- Meta (Facebook & Instagram) -->
            <div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:20px;background:#fcfdff;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                    <div style="width:36px;height:36px;border-radius:8px;background:#1877f2;color:#fff;display:grid;place-items:center;font-weight:800;font-size:18px;">f</div>
                    <div>
                        <strong style="display:block;font-size:14px;">Meta Ads (Facebook &amp; Instagram)</strong>
                        <small style="color:var(--muted);font-size:11px;">Client-side Pixel + Server-Side Conversions API (CAPI)</small>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:5px;">Meta Pixel ID</label>
                    <input type="text" name="meta_pixel_id" value="<?php echo htmlspecialchars($ad_config['meta_pixel_id'] ?? ''); ?>" placeholder="e.g. 123456789012345" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;box-sizing:border-box;">
                    <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">Find this in your <a href="https://adsmanager.facebook.com/events_manager2" target="_blank" style="color:#0284c7;text-decoration:none;">Meta Events Manager &rarr; Data Sources</a>.</small>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:5px;">Conversions API (CAPI) Access Token</label>
                    <input type="password" name="meta_capi_token" value="<?php echo !empty($ad_config['meta_capi_token']) ? '********' : ''; ?>" placeholder="Paste token (starts with EAAB...)" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;box-sizing:border-box;">
                    <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">Settings &rarr; Conversions API &rarr; Generate access token. Enables server-side tracking to bypass iOS restrictions.</small>
                </div>

                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:5px;">Test Event Code (Optional)</label>
                    <input type="text" name="meta_test_event_code" value="<?php echo htmlspecialchars($ad_config['meta_test_event_code'] ?? ''); ?>" placeholder="e.g. TEST12345" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;box-sizing:border-box;">
                    <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block;">Found in Events Manager &rarr; "Test events" tab. Leave blank for live production events.</small>
                </div>
            </div>

            <!-- Google Ads & Analytics -->
            <div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:20px;background:#fcfdff;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                    <div style="width:36px;height:36px;border-radius:8px;background:#ea4335;color:#fff;display:grid;place-items:center;font-weight:800;font-size:18px;">G</div>
                    <div>
                        <strong style="display:block;font-size:14px;">Google Ads &amp; Analytics</strong>
                        <small style="color:var(--muted);font-size:11px;">Google Tag (`gtag.js`) + Conversion Action</small>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:5px;">Google Conversion ID / Measurement ID</label>
                    <input type="text" name="google_ads_conversion_id" value="<?php echo htmlspecialchars($ad_config['google_ads_conversion_id'] ?? ''); ?>" placeholder="e.g. AW-123456789 or G-ABC123XYZ" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;box-sizing:border-box;">
                </div>

                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:5px;">Google Conversion Label (Optional)</label>
                    <input type="text" name="google_ads_conversion_label" value="<?php echo htmlspecialchars($ad_config['google_ads_conversion_label'] ?? ''); ?>" placeholder="e.g. AbC-D_efGh12345" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;box-sizing:border-box;">
                </div>
            </div>

            <!-- TikTok Ads -->
            <div style="border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:24px;background:#fcfdff;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                    <div style="width:36px;height:36px;border-radius:8px;background:#000;color:#fff;display:grid;place-items:center;font-weight:800;font-size:18px;">d</div>
                    <div>
                        <strong style="display:block;font-size:14px;">TikTok Pixel</strong>
                        <small style="color:var(--muted);font-size:11px;">TikTok Events Pixel</small>
                    </div>
                </div>

                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:5px;">TikTok Pixel ID</label>
                    <input type="text" name="tiktok_pixel_id" value="<?php echo htmlspecialchars($ad_config['tiktok_pixel_id'] ?? ''); ?>" placeholder="e.g. C1234567890ABCDEFG" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;box-sizing:border-box;">
                </div>
            </div>

            <button type="submit" class="btn" style="background:var(--navy);color:var(--lime);padding:12px 24px;border-radius:8px;font-size:13px;font-weight:800;border:none;cursor:pointer;">
                Save Tracking Configuration
            </button>
        </form>
    </div>

    <!-- Diagnostic & Live Test Panel -->
    <div>
        <div class="panel" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;margin-bottom:20px;">
            <h3 style="font-size:16px;font-weight:800;margin:0 0 12px;display:flex;align-items:center;gap:8px;">
                <span>🧪</span> Connection Diagnostics
            </h3>
            <p style="color:var(--muted);font-size:12px;line-height:1.5;margin:0 0 18px;">
                Verify that your server-side Conversions API token is working properly by sending a synthetic test lead event to Meta:
            </p>

            <form method="POST" action="ads.php?tab=pixels&company_id=<?php echo $company_id; ?>&days=<?php echo $days; ?>">
                <input type="hidden" name="action" value="send_test_event">
                <input type="hidden" name="platform" value="meta">
                <?php echo sa_csrf_field(); ?>

                <button type="submit" class="btn" style="width:100%;background:#0284c7;color:#fff;padding:11px 16px;border-radius:8px;font-size:13px;font-weight:700;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;" <?php echo empty($ad_config['meta_capi_token']) ? 'disabled title="Save a Meta CAPI token first"' : ''; ?>>
                    <span>📡</span> Send Live Test Event to Meta CAPI
                </button>
            </form>

            <?php if (empty($ad_config['meta_capi_token'])): ?>
            <p style="color:#d97706;font-size:11px;margin:10px 0 0;text-align:center;">⚠️ Add your Meta Pixel ID and CAPI token on the left to enable live diagnostics.</p>
            <?php endif; ?>
        </div>

        <div class="panel" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;">
            <h3 style="font-size:16px;font-weight:800;margin:0 0 12px;">Supported Conversion Signals</h3>
            <ul style="margin:0;padding-left:18px;font-size:12px;color:var(--muted);line-height:1.8;">
                <li><strong style="color:var(--ink);">WhatsApp Click:</strong> Dispatched as <code>Contact</code> / <code>Lead</code> via Meta CAPI &amp; Google Conversion.</li>
                <li><strong style="color:var(--ink);">Review Submission:</strong> Dispatched as <code>CompleteRegistration</code>.</li>
                <li><strong style="color:var(--ink);">Directions Click:</strong> Dispatched as <code>FindLocation</code>.</li>
                <li><strong style="color:var(--ink);">Privacy Protected:</strong> IP &amp; User Agent hashed according to Meta privacy guidelines.</li>
            </ul>
        </div>
    </div>
</div>

<?php elseif ($active_tab === 'creatives'): ?>
<!-- ============================================================
     TAB 3: REVIEW AD CREATIVES & UTM CAMPAIGN LINK BUILDER
     ============================================================ -->
<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:20px;">
    <!-- Review to Ad Copy Presets -->
    <div class="panel" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;">
        <h3 style="font-size:18px;font-weight:800;margin:0 0 8px;display:flex;align-items:center;gap:8px;">
            <span>⭐</span> Review-to-Ad Copy Studio
        </h3>
        <p style="color:var(--muted);font-size:13px;margin:0 0 20px;">Pick any verified 5-star customer testimonial and copy ready-to-launch ad copy presets for Facebook, Instagram, or Google Ads:</p>

        <?php if (count($top_reviews) > 0): ?>
        <div style="display:flex;flex-direction:column;gap:14px;">
            <?php foreach ($top_reviews as $idx => $rev): ?>
            <div style="border:1px solid var(--line);border-radius:12px;padding:16px;background:var(--bg);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <div>
                        <strong style="font-size:13px;color:var(--ink);"><?php echo htmlspecialchars($rev['customer_name'] ?: 'Verified Customer'); ?></strong>
                        <span style="color:#f59e0b;font-size:12px;margin-left:6px;">★★★★★</span>
                    </div>
                    <span style="font-size:11px;color:var(--muted);"><?php echo date('M j, Y', strtotime($rev['created_at'])); ?></span>
                </div>
                <p style="font-size:12px;color:var(--ink);line-height:1.5;margin:0 0 12px;font-style:italic;">
                    "<?php echo htmlspecialchars(mb_substr($rev['comment'] ?: 'Outstanding service and quality!', 0, 180)); ?>"
                </p>

                <!-- Ad Headline & Copy Snippet -->
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:10px;">
                    <span style="display:block;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:2px;">Recommended Ad Copy</span>
                    <div style="font-size:12px;color:var(--ink);line-height:1.4;">
                        <strong>Headline:</strong> Rated 5.0★ by Customers in <?php echo htmlspecialchars($company_name); ?><br>
                        <strong>Primary Text:</strong> "<?php echo htmlspecialchars(mb_substr($rev['comment'] ?: 'Top-rated experience', 0, 120)); ?>" — Tap below to chat on WhatsApp for fast booking &amp; instant quotes!
                    </div>
                </div>

                <div style="display:flex;gap:8px;">
                    <a href="social_card.php?review_id=<?php echo (int)$rev['id']; ?>" class="btn btn-secondary" style="padding:6px 12px;font-size:11px;font-weight:700;border-radius:6px;text-decoration:none;">
                        <span>🎨</span> Design Visual Card (1:1 / Stories)
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:40px;color:var(--muted);">
            <div style="font-size:36px;margin-bottom:12px;">🌟</div>
            <h4 style="font-size:15px;margin:0 0 6px;">No 5-star reviews available yet</h4>
            <p style="font-size:12px;">Invite your recent customers via <a href="whatsapp_sender.php" style="color:#0284c7;">Ask for Reviews (WhatsApp)</a> to collect high-converting testimonials.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Interactive UTM Campaign Link Generator -->
    <div class="panel" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;">
        <h3 style="font-size:18px;font-weight:800;margin:0 0 8px;display:flex;align-items:center;gap:8px;">
            <span>🔗</span> UTM Campaign Link Builder
        </h3>
        <p style="color:var(--muted);font-size:13px;margin:0 0 20px;">Generate campaign URLs so Google Ads and Meta Ads clicks are automatically tracked and attributed in your Optibiz dashboard:</p>

        <div style="display:flex;flex-direction:column;gap:14px;">
            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px;">Campaign Source (Platform)</label>
                <select id="utmSource" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
                    <option value="facebook">Facebook Ads (facebook)</option>
                    <option value="instagram">Instagram Ads (instagram)</option>
                    <option value="google">Google Ads (google)</option>
                    <option value="tiktok">TikTok Ads (tiktok)</option>
                    <option value="whatsapp">WhatsApp Broadcast (whatsapp)</option>
                </select>
            </div>

            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px;">Campaign Medium</label>
                <select id="utmMedium" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
                    <option value="paid_social">paid_social</option>
                    <option value="cpc">cpc (Search Ads)</option>
                    <option value="display">display (Banner / PMax)</option>
                    <option value="story_ad">story_ad</option>
                </select>
            </div>

            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px;">Campaign Name</label>
                <input type="text" id="utmCampaign" value="reviews_promo_2026" placeholder="e.g. spring_special" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;box-sizing:border-box;">
            </div>

            <div>
                <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px;">Ad Content / Variation (Optional)</label>
                <input type="text" id="utmContent" value="5star_card" placeholder="e.g. video_ad_v1" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;box-sizing:border-box;">
            </div>

            <!-- Result Box -->
            <div style="background:var(--bg);border-radius:10px;padding:14px;margin-top:8px;">
                <span style="display:block;font-size:11px;font-weight:700;color:var(--muted);margin-bottom:6px;">Your Tracking Destination URL</span>
                <textarea id="finalUtmUrl" readonly rows="3" style="width:100%;padding:8px;font-family:monospace;font-size:11px;border:1px solid var(--line);border-radius:6px;resize:none;box-sizing:border-box;background:#fff;"></textarea>

                <button type="button" id="btnCopyUtm" style="margin-top:10px;width:100%;background:var(--navy);color:var(--lime);padding:10px;border-radius:8px;font-size:12px;font-weight:800;border:none;cursor:pointer;">
                    📋 Copy Destination URL for Ads
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var baseUrl = <?php echo json_encode($public_rate_url); ?>;
    var sourceEl = document.getElementById('utmSource');
    var mediumEl = document.getElementById('utmMedium');
    var campaignEl = document.getElementById('utmCampaign');
    var contentEl = document.getElementById('utmContent');
    var finalUrlEl = document.getElementById('finalUtmUrl');
    var copyBtn = document.getElementById('btnCopyUtm');

    function updateUrl() {
        var src = encodeURIComponent(sourceEl.value.trim());
        var med = encodeURIComponent(mediumEl.value.trim());
        var camp = encodeURIComponent(campaignEl.value.trim() || 'campaign');
        var cnt = encodeURIComponent(contentEl.value.trim());

        var url = baseUrl + '&utm_source=' + src + '&utm_medium=' + med + '&utm_campaign=' + camp;
        if (cnt) url += '&utm_content=' + cnt;
        finalUrlEl.value = url;
    }

    sourceEl.addEventListener('change', updateUrl);
    mediumEl.addEventListener('change', updateUrl);
    campaignEl.addEventListener('input', updateUrl);
    contentEl.addEventListener('input', updateUrl);
    updateUrl();

    copyBtn.addEventListener('click', function() {
        finalUrlEl.select();
        document.execCommand('copy');
        copyBtn.textContent = '✅ Copied to Clipboard!';
        setTimeout(function() { copyBtn.textContent = '📋 Copy Destination URL for Ads'; }, 2000);
    });
})();
</script>

<?php elseif ($active_tab === 'attribution'): ?>
<!-- ============================================================
     TAB 4: PAID CAMPAIGN ATTRIBUTION ANALYTICS
     ============================================================ -->
<div class="panel" style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;">
    <h3 style="font-size:18px;font-weight:800;margin:0 0 8px;display:flex;align-items:center;gap:8px;">
        <span>📊</span> Campaign Attribution Performance
    </h3>
    <p style="color:var(--muted);font-size:13px;margin:0 0 20px;">Breakdown of incoming traffic, unique visitors, and bottom-of-funnel WhatsApp inquiries grouped by campaign:</p>

    <?php if (count($attribution_rows) > 0): ?>
    <div class="table-scroll-wrap" style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:left;">
            <thead>
                <tr style="border-bottom:2px solid var(--line);color:var(--muted);font-size:11px;text-transform:uppercase;">
                    <th style="padding:12px 14px;">Campaign Name</th>
                    <th style="padding:12px 14px;">Source</th>
                    <th style="padding:12px 14px;">Medium</th>
                    <th style="padding:12px 14px;text-align:right;">Page Views</th>
                    <th style="padding:12px 14px;text-align:right;">Unique Visitors</th>
                    <th style="padding:12px 14px;text-align:right;">WhatsApp Leads</th>
                    <th style="padding:12px 14px;text-align:right;">Reviews</th>
                    <th style="padding:12px 14px;text-align:right;">Lead CVR</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attribution_rows as $row): ?>
                <tr style="border-bottom:1px solid var(--line);">
                    <td style="padding:12px 14px;font-weight:700;color:var(--ink);">
                        <?php if ($row['is_paid']): ?>
                        <span style="display:inline-block;padding:2px 6px;border-radius:4px;background:#e0f2fe;color:#0284c7;font-size:10px;font-weight:700;margin-right:6px;">PAID</span>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($row['campaign']); ?>
                    </td>
                    <td style="padding:12px 14px;color:var(--muted);">
                        <?php echo htmlspecialchars($row['source']); ?>
                    </td>
                    <td style="padding:12px 14px;color:var(--muted);">
                        <?php echo htmlspecialchars($row['medium']); ?>
                    </td>
                    <td style="padding:12px 14px;text-align:right;font-weight:600;">
                        <?php echo number_format($row['page_views']); ?>
                    </td>
                    <td style="padding:12px 14px;text-align:right;color:var(--muted);">
                        <?php echo number_format($row['unique_visitors']); ?>
                    </td>
                    <td style="padding:12px 14px;text-align:right;font-weight:800;color:#059669;">
                        <?php echo number_format($row['whatsapp_clicks']); ?>
                    </td>
                    <td style="padding:12px 14px;text-align:right;color:var(--muted);">
                        <?php echo number_format($row['reviews_submitted']); ?>
                    </td>
                    <td style="padding:12px 14px;text-align:right;font-weight:800;color:<?php echo $row['conversion_rate'] > 0 ? '#059669' : 'var(--muted)'; ?>;">
                        <?php echo $row['conversion_rate']; ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:50px 20px;color:var(--muted);">
        <div style="font-size:40px;margin-bottom:14px;">🎯</div>
        <h4 style="font-size:16px;color:var(--ink);margin:0 0 8px;">No campaign attribution data yet</h4>
        <p style="font-size:13px;max-width:440px;margin:0 auto 18px;">Create your first tracked link using the <strong>Review Ad Creatives &amp; UTMs</strong> tab, and start driving traffic from Facebook, Instagram, or Google Ads.</p>
        <a href="?tab=creatives&company_id=<?php echo $company_id; ?>&days=<?php echo $days; ?>" class="btn" style="background:var(--navy);color:var(--lime);padding:10px 20px;border-radius:8px;font-size:13px;font-weight:800;text-decoration:none;display:inline-block;">
            Build First Campaign Link &rarr;
        </a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_shell_footer.php'; ?>