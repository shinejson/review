<?php
/**
 * ============================================================
 *  Admin — Shop Counter QR Stand Generator
 * ============================================================
 *  Generates high-resolution, print-ready counter stands, table
 *  tents, and QR stickers so tenants can collect in-person
 *  customer reviews directly at the counter or checkout.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

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

// Rating statistics
$company_id    = (int)($company_profile['id'] ?? 0);
$total_reviews = 0;
$avg_score     = 5.0;
if ($company_id > 0) {
    $st = $conn->prepare("SELECT COUNT(*) cnt, AVG(rating) avg FROM ratings WHERE company_id=?");
    $st->bind_param("i", $company_id);
    $st->execute();
    $stat = $st->get_result()->fetch_assoc();
    $st->close();
    $total_reviews = (int)($stat['cnt'] ?? 0);
    if ($total_reviews > 0 && !empty($stat['avg'])) {
        $avg_score = round((float)$stat['avg'], 1);
    }
}

// Brand identifiers
$brand_name     = !empty($company_profile['company_name']) ? $company_profile['company_name'] : ($tenant['company_name'] ?? 'Your Business');
$brand_category = !empty($company_profile['category_name']) ? $company_profile['category_name'] : 'Verified Business';
$brand_logo     = $tenant['logo'] ?? '';
$brand_initials = strtoupper(substr($brand_name, 0, 2));

// WhatsApp info
$wa_number  = $company_profile['whatsapp_number'] ?? '';
$wa_display = function_exists('whatsappDisplay') ? whatsappDisplay($wa_number) : $wa_number;

// Target review URL
$__scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__root     = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$public_url = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $__root . '/rate/index.php?tenant=' . (int)$tenant_id;

// Direct QR Code image (high-res PNG, margin 15)
$qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&margin=15&format=png&data=' . rawurlencode($public_url);

$BASE      = '../';
$pageTitle = 'Counter QR Stand';
$activeNav = 'qr_stand';
include __DIR__ . '/_shell.php';
?>

<!-- Action Bar (hidden when printing) -->
<div class="welcome-row no-print" style="margin-bottom:24px;">
    <div>
        <p class="eyebrow">Conversion &amp; Acquisition &middot; In-Store Reviews</p>
        <h1>Printable Counter QR Stand</h1>
        <p class="muted">Display this on your reception, checkout counter, or dining tables so customers can scan and leave a review in 30 seconds.</p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary" onclick="window.print()" style="display:inline-flex;align-items:center;gap:8px;padding:11px 20px;font-weight:700;">
            🖨 Print Stand Card
        </button>
        <a href="<?php echo htmlspecialchars($qr_api_url); ?>" download="qr-<?php echo preg_replace('/[^a-z0-9]/i', '-', strtolower($brand_name)); ?>.png" target="_blank" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;padding:11px 20px;font-weight:700;text-decoration:none;">
            ⬇ Download QR PNG
        </a>
    </div>
</div>

<!-- Main Builder Layout -->
<div class="qr-builder-layout">

    <!-- Left / Center: Interactive Live Stand Preview -->
    <div class="qr-stand-preview-area">
        <div class="qr-preview-wrapper">
            
            <!-- Table Tent Stand Card (Printable Target) -->
            <div id="standCard" class="table-tent-card theme-dark">
                
                <!-- Fold guide label (for table tents) -->
                <div class="stand-fold-guide no-screen">▲ Fold here for table tent standing display ▲</div>

                <!-- Card Header -->
                <div class="stand-header">
                    <?php if (!empty($brand_logo)): ?>
                        <img src="../<?php echo htmlspecialchars($brand_logo); ?>" alt="<?php echo htmlspecialchars($brand_name); ?>" class="stand-logo">
                    <?php else: ?>
                        <div class="stand-avatar"><?php echo htmlspecialchars($brand_initials); ?></div>
                    <?php endif; ?>
                    
                    <div class="stand-brand-meta">
                        <h2 class="stand-brand-name"><?php echo htmlspecialchars($brand_name); ?></h2>
                        <span class="stand-badge">✓ <?php echo htmlspecialchars($brand_category); ?></span>
                    </div>
                </div>

                <!-- Star Banner -->
                <div id="starBanner" class="stand-stars-banner">
                    <span class="stand-stars">★★★★★</span>
                    <span class="stand-score"><?php echo number_format($avg_score, 1); ?> / 5.0</span>
                    <span class="stand-review-count">&middot; <?php echo $total_reviews > 0 ? number_format($total_reviews) . ' reviews' : 'Rated on Optibiz'; ?></span>
                </div>

                <!-- Main Call to Action -->
                <div class="stand-cta-box">
                    <h3 id="headlineText" class="stand-headline">Loved your experience?</h3>
                    <p id="subheadlineText" class="stand-subtext">Scan with your phone camera to leave us a quick review!</p>
                </div>

                <!-- QR Code Display Box -->
                <div class="stand-qr-box">
                    <img id="qrImage" src="<?php echo htmlspecialchars($qr_api_url); ?>" alt="Scan to Review <?php echo htmlspecialchars($brand_name); ?>" class="stand-qr-img">
                    <div class="stand-qr-scan-badge">
                        <span>SCAN TO RATE</span>
                    </div>
                </div>

                <!-- Steps pill -->
                <div class="stand-steps-row">
                    <div class="stand-step">
                        <span class="stand-step-num">1</span>
                        <span>Open Camera</span>
                    </div>
                    <div class="stand-step-arrow">→</div>
                    <div class="stand-step">
                        <span class="stand-step-num">2</span>
                        <span>Point at QR</span>
                    </div>
                    <div class="stand-step-arrow">→</div>
                    <div class="stand-step">
                        <span class="stand-step-num">3</span>
                        <span>Tap to Rate</span>
                    </div>
                </div>

                <!-- Optional WhatsApp callout -->
                <?php if (!empty($wa_number)): ?>
                <div id="standWaBox" class="stand-whatsapp-pill">
                    <svg viewBox="0 0 448 512" aria-hidden="true"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
                    <span>Need immediate support or orders? Chat with us on WhatsApp: <strong><?php echo htmlspecialchars($wa_display); ?></strong></span>
                </div>
                <?php endif; ?>

                <!-- Footer branding -->
                <div class="stand-footer">
                    <span>Powered by <strong>Optibiz</strong> Verified Reviews</span>
                    <span class="stand-url-hint"><?php echo htmlspecialchars($public_url); ?></span>
                </div>

            </div>
        </div>
    </div>

    <!-- Right Controls Panel (Customization) -->
    <div class="qr-controls-panel no-print">
        
        <!-- Live Customization Card -->
        <div class="form-card" style="padding:24px;margin-bottom:20px;">
            <h3 style="margin:0 0 6px;font-size:16px;">🎨 Customize Your Stand</h3>
            <p class="muted" style="margin:0 0 20px;font-size:12.5px;">Changes reflect instantly on the live preview on the left.</p>

            <div class="form-group" style="margin-bottom:18px;">
                <label style="font-size:13px;font-weight:700;display:block;margin-bottom:8px;">Color Theme</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <button type="button" class="theme-select-btn active" id="btnThemeDark" onclick="setStandTheme('theme-dark')">
                        <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#0b1d2b;border:2px solid #c2f542;margin-right:6px;"></span>
                        Dark &amp; Lime
                    </button>
                    <button type="button" class="theme-select-btn" id="btnThemeLight" onclick="setStandTheme('theme-light')">
                        <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#ffffff;border:2px solid #0b1d2b;margin-right:6px;"></span>
                        Clean White
                    </button>
                </div>
                <small class="muted" style="margin-top:5px;display:block;">"Clean White" is recommended for saving printer ink.</small>
            </div>

            <div class="form-group" style="margin-bottom:18px;">
                <label for="inputHeadline" style="font-size:13px;font-weight:700;display:block;margin-bottom:6px;">Headline Text</label>
                <input type="text" id="inputHeadline" class="form-control" value="Loved your experience?" oninput="updateHeadline(this.value)" style="font-size:13px;padding:9px 12px;width:100%;">
            </div>

            <div class="form-group" style="margin-bottom:18px;">
                <label for="inputSubheadline" style="font-size:13px;font-weight:700;display:block;margin-bottom:6px;">Sub-headline Text</label>
                <input type="text" id="inputSubheadline" class="form-control" value="Scan with your phone camera to leave us a quick review!" oninput="updateSubheadline(this.value)" style="font-size:13px;padding:9px 12px;width:100%;">
            </div>

            <div class="form-group" style="margin-bottom:18px;">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600;">
                    <input type="checkbox" id="toggleStars" checked onchange="toggleElement('starBanner', this.checked)">
                    <span>Display 5-Star Rating &amp; Average Score</span>
                </label>
            </div>

            <?php if (!empty($wa_number)): ?>
            <div class="form-group" style="margin-bottom:18px;">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600;">
                    <input type="checkbox" id="toggleWa" checked onchange="toggleElement('standWaBox', this.checked)">
                    <span>Show WhatsApp inquiry note at bottom</span>
                </label>
            </div>
            <?php endif; ?>

            <div style="display:flex;flex-direction:column;gap:10px;margin-top:24px;">
                <button type="button" class="btn btn-primary" onclick="window.print()" style="width:100%;padding:12px;font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center;gap:8px;">
                    🖨 Print Stand (A5 / Table Tent)
                </button>
                <a href="<?php echo htmlspecialchars($public_url); ?>" target="_blank" class="btn btn-secondary" style="width:100%;padding:10px;text-align:center;text-decoration:none;font-size:13px;">
                    ↗ Test Public Rating Page
                </a>
            </div>
        </div>

        <!-- Practical In-Store Tips -->
        <div class="form-card" style="padding:22px;border-left:3px solid var(--lime);">
            <h4 style="margin:0 0 8px;font-size:14px;color:var(--ink);">💡 Counter Placement Tips</h4>
            <ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.6;color:var(--muted);">
                <li>Place at eye level near the bill payment counter or cash register.</li>
                <li>For hotels &amp; restaurants, place on the dining tables or front desk.</li>
                <li>Print on sturdy paper (cardstock or 250gsm) or insert in an acrylic stand.</li>
            </ul>
        </div>

    </div>

</div>

<!-- Styles: Screen & Print -->
<style>
/* Layout */
.qr-builder-layout {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: 32px;
    align-items: start;
}
@media (max-width: 960px) {
    .qr-builder-layout {
        grid-template-columns: 1fr;
    }
}

/* Stand Preview Wrapper */
.qr-stand-preview-area {
    display: flex;
    justify-content: center;
    background: #06111a;
    padding: 36px 20px;
    border-radius: 18px;
    border: 1px solid rgba(255,255,255,.08);
}
.theme-select-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid var(--line);
    background: var(--bg);
    color: var(--ink);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
}
.theme-select-btn.active {
    border-color: var(--lime);
    background: rgba(194,245,66,.12);
    color: var(--ink);
}

/* Table Tent Stand Card Structure */
.table-tent-card {
    width: 360px;
    border-radius: 20px;
    padding: 32px 28px;
    text-align: center;
    box-shadow: 0 25px 60px rgba(0,0,0,0.5);
    transition: all .3s ease;
    position: relative;
}

/* Theme: Dark & Lime */
.table-tent-card.theme-dark {
    background: #091a27;
    border: 2px solid #1a354b;
    color: #f8fafc;
}
.table-tent-card.theme-dark .stand-brand-name { color: #ffffff; }
.table-tent-card.theme-dark .stand-headline { color: #c2f542; }
.table-tent-card.theme-dark .stand-subtext { color: #94a3b8; }
.table-tent-card.theme-dark .stand-stars-banner { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); }
.table-tent-card.theme-dark .stand-qr-box { background: #ffffff; }
.table-tent-card.theme-dark .stand-whatsapp-pill { background: rgba(37,211,102,0.12); color: #86efac; border: 1px solid rgba(37,211,102,0.25); }
.table-tent-card.theme-dark .stand-footer { color: #64748b; }

/* Theme: Clean White (Ink-Saver) */
.table-tent-card.theme-light {
    background: #ffffff;
    border: 2px solid #0f172a;
    color: #0f172a;
    box-shadow: 0 20px 45px rgba(0,0,0,0.15);
}
.table-tent-card.theme-light .stand-brand-name { color: #0f172a; }
.table-tent-card.theme-light .stand-headline { color: #0f172a; }
.table-tent-card.theme-light .stand-subtext { color: #475569; }
.table-tent-card.theme-light .stand-badge { background: #e2e8f0; color: #0f172a; }
.table-tent-card.theme-light .stand-stars-banner { background: #f8fafc; border: 1px solid #e2e8f0; color: #0f172a; }
.table-tent-card.theme-light .stand-qr-box { background: #f8fafc; border: 2px solid #e2e8f0; }
.table-tent-card.theme-light .stand-step { background: #f1f5f9; color: #334155; }
.table-tent-card.theme-light .stand-step-num { background: #0f172a; color: #ffffff; }
.table-tent-card.theme-light .stand-whatsapp-pill { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.table-tent-card.theme-light .stand-footer { color: #64748b; }

/* Header Elements */
.stand-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-bottom: 16px;
}
.stand-logo {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    object-fit: cover;
    background: #fff;
}
.stand-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #c2f542;
    color: #091a27;
    font-size: 16px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
}
.stand-brand-meta {
    text-align: left;
}
.stand-brand-name {
    margin: 0;
    font-size: 17px;
    font-weight: 800;
    line-height: 1.2;
}
.stand-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 99px;
    background: rgba(194,245,66,0.18);
    color: #c2f542;
    margin-top: 3px;
}

/* Star Banner */
.stand-stars-banner {
    border-radius: 12px;
    padding: 8px 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
}
.stand-stars {
    color: #fbbf24;
    font-size: 16px;
    letter-spacing: 1px;
}
.stand-score {
    font-weight: 800;
    font-size: 14px;
}
.stand-review-count {
    font-size: 11.5px;
    opacity: .8;
}

/* Call to Action */
.stand-cta-box {
    margin-bottom: 20px;
}
.stand-headline {
    margin: 0 0 6px;
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -.5px;
}
.stand-subtext {
    margin: 0;
    font-size: 12.5px;
    line-height: 1.4;
}

/* QR Container */
.stand-qr-box {
    border-radius: 16px;
    padding: 16px;
    display: inline-block;
    margin-bottom: 18px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}
.stand-qr-img {
    width: 200px;
    height: 200px;
    display: block;
}
.stand-qr-scan-badge {
    margin-top: 8px;
    background: #0f172a;
    color: #c2f542;
    padding: 4px 10px;
    border-radius: 99px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1px;
    display: inline-block;
}

/* Steps Row */
.stand-steps-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-bottom: 16px;
    font-size: 11px;
    font-weight: 700;
}
.stand-step {
    background: rgba(255,255,255,0.06);
    padding: 5px 10px;
    border-radius: 99px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.stand-step-num {
    width: 15px;
    height: 15px;
    border-radius: 50%;
    background: #c2f542;
    color: #091a27;
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
}
.stand-step-arrow {
    opacity: .4;
    font-size: 12px;
}

/* WhatsApp pill */
.stand-whatsapp-pill {
    font-size: 11px;
    line-height: 1.4;
    padding: 8px 12px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    text-align: left;
    margin-bottom: 16px;
}
.stand-whatsapp-pill svg {
    width: 16px;
    height: 16px;
    fill: currentColor;
    flex-shrink: 0;
}

/* Footer */
.stand-footer {
    font-size: 10px;
    padding-top: 12px;
    border-top: 1px solid rgba(255,255,255,0.08);
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.stand-url-hint {
    font-family: monospace;
    font-size: 9px;
    opacity: .7;
    word-break: break-all;
}

/* Screen / Print Toggle Guides */
.no-screen { display: none; }

/* ============================================================
   PRINT STYLES: window.print()
   ============================================================ */
@media print {
    @page {
        size: A5 portrait;
        margin: 10mm;
    }
    body, html {
        background: #ffffff !important;
        color: #000000 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .no-print, header, aside, .admin-sidebar, .topbar, .admin-main > *:not(.qr-builder-layout), .qr-controls-panel {
        display: none !important;
    }
    .admin-app, .admin-main, .dashboard-content, .qr-builder-layout, .qr-stand-preview-area, .qr-preview-wrapper {
        display: block !important;
        background: transparent !important;
        padding: 0 !important;
        margin: 0 auto !important;
        border: none !important;
        box-shadow: none !important;
        width: 100% !important;
    }
    .table-tent-card {
        margin: 0 auto !important;
        width: 100% !important;
        max-width: 440px !important;
        box-shadow: none !important;
        page-break-inside: avoid !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        border: 2px solid #0f172a !important;
    }
    .stand-fold-guide {
        display: block !important;
        font-size: 9px;
        text-align: center;
        color: #64748b;
        letter-spacing: 1px;
        text-transform: uppercase;
        margin-bottom: 16px;
        padding-bottom: 8px;
        border-bottom: 1px dashed #cbd5e1;
    }
}
</style>

<script>
function setStandTheme(theme) {
    var card = document.getElementById('standCard');
    card.classList.remove('theme-dark', 'theme-light');
    card.classList.add(theme);

    document.getElementById('btnThemeDark').classList.toggle('active', theme === 'theme-dark');
    document.getElementById('btnThemeLight').classList.toggle('active', theme === 'theme-light');
}

function updateHeadline(val) {
    document.getElementById('headlineText').textContent = val.trim() || 'Loved your experience?';
}

function updateSubheadline(val) {
    document.getElementById('subheadlineText').textContent = val.trim() || 'Scan with your phone camera to leave us a quick review!';
}

function toggleElement(elId, show) {
    var el = document.getElementById(elId);
    if (el) {
        el.style.display = show ? '' : 'none';
    }
}
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
