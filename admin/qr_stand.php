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
requireTeamAccess('qr_stand');

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

// Multi-branch: Load the active company profile
$company_profile = null;
if ($tenant_id) {
    $active_company_id = getActiveCompanyId($conn, $tenant_id);
    $company_profile   = getActiveCompanyProfile($conn, $tenant_id);
    $company_id        = (int)($company_profile['id'] ?? $active_company_id);
} else {
    $c_res = $conn->query("SELECT * FROM customers ORDER BY id ASC LIMIT 1");
    $company_profile = $c_res ? $c_res->fetch_assoc() : null;
    $company_id = (int)($company_profile['id'] ?? 0);
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

// Target review URL (tagged with &src=qr for offline scan analytics)
if ($company_id > 0) {
    $public_url = getCompanyPublicRatingUrl($company_id, $brand_name, ['src' => 'qr']);
} else {
    $public_url = getCompanyPublicRatingUrl(0, $brand_name, ['tenant' => (int)$tenant_id, 'src' => 'qr']);
}

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
        <div style="margin-top:8px;display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;background:rgba(194,245,66,0.18);color:var(--ink);border:1px solid rgba(194,245,66,0.4);">
                <span>🏢</span> Current Branch: <strong><?php echo htmlspecialchars($brand_name); ?></strong>
            </span>
            <?php if (!empty($tenant_all_branches) && count($tenant_all_branches) > 1): ?>
            <span class="muted" style="font-size:11.5px;">&middot; Stand &amp; QR link reflect this branch</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <a href="whatsapp_sender.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:11px 18px;text-decoration:none;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;">
            💬 WhatsApp Invites
        </a>

        <!-- Download split-button dropdown -->
        <div class="dl-split-btn" id="dlSplitBtn">
            <!-- Main action button -->
            <button type="button" class="dl-split-main" onclick="downloadFullDesign()" title="Download the full stand card as PNG">
                ⬇ Download PNG
            </button>
            <!-- Chevron toggle -->
            <button type="button" class="dl-split-chevron" onclick="toggleDlMenu(event)" aria-label="More download options" aria-expanded="false" aria-haspopup="true">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <!-- Dropdown menu -->
            <div class="dl-split-menu" id="dlMenu" role="menu">
                <button type="button" class="dl-split-option" onclick="downloadFullDesign()" role="menuitem">
                    <span class="dl-opt-icon">🖼</span>
                    <span>
                        <strong>Full Stand Design</strong>
                        <small>Card with branding, QR &amp; layout as PNG</small>
                    </span>
                </button>
                <div class="dl-split-divider"></div>
                <button type="button" class="dl-split-option" onclick="downloadQrOnly()" role="menuitem">
                    <span class="dl-opt-icon">⬛</span>
                    <span>
                        <strong>QR Code Only</strong>
                        <small>Plain QR code PNG (high-res)</small>
                    </span>
                </button>
            </div>
        </div>
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
                    <img id="qrImage" src="<?php echo htmlspecialchars($qr_api_url); ?>"
                         alt="Scan to Review <?php echo htmlspecialchars($brand_name); ?>"
                         class="stand-qr-img" crossorigin="anonymous">
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

            <div class="form-group" style="margin-bottom:18px;">
                <label style="font-size:13px;font-weight:700;display:block;margin-bottom:8px;">Print Size</label>
                <select id="printSize" class="form-control" onchange="onSizeChange(this.value)" style="font-size:13px;padding:9px 12px;width:100%;">
                    <option value="a6">A6 — Postcard (10.5 × 14.8 cm)</option>
                    <option value="a5" selected>A5 — Table Tent (14.8 × 21 cm)</option>
                    <option value="a4">A4 — Full Page (21 × 29.7 cm)</option>
                    <option value="letter">Letter (8.5 × 11 in)</option>
                    <option value="custom-4x6">4 × 6 in — Postcard</option>
                    <option value="custom">✏ Custom size…</option>
                </select>
                <small class="muted" style="margin-top:5px;display:block;">The card will scale to fill the selected paper size.</small>

                <!-- Custom size inputs (shown only when "Custom" is selected) -->
                <div id="customSizeRow">
                    <input type="number" id="customW" value="14.8" min="1" step="0.1" placeholder="W" title="Width">
                    <span style="font-size:13px;font-weight:700;">×</span>
                    <input type="number" id="customH" value="21" min="1" step="0.1" placeholder="H" title="Height">
                    <select id="customUnit">
                        <option value="cm" selected>cm</option>
                        <option value="mm">mm</option>
                        <option value="in">in</option>
                    </select>
                    <small class="muted" style="font-size:11px;">Width × Height</small>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:10px;margin-top:24px;">
                <button type="button" class="btn btn-primary" onclick="handlePrint()" style="width:100%;padding:12px;font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center;gap:8px;">
                    🖨 Print Stand Card
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

<!-- Hidden off-page QR generator — QRCode.js renders here, destroyed after data URL extracted -->
<div id="qrHiddenGen" style="position:absolute;left:-99999px;top:-99999px;opacity:0;pointer-events:none;z-index:-9999;" aria-hidden="true"></div>

<!-- Styles: Screen only (no @media print needed — print happens in popup) -->
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
.table-tent-card.theme-dark .stand-footer { color: #64748b; border-top-color: rgba(255,255,255,0.08); }

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
/* QR image in visible card */
.stand-qr-img {
    width: 200px;
    height: 200px;
    display: block;
    margin: 0 auto;
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

/* Custom size row */
#customSizeRow {
    display: none;
    gap: 8px;
    align-items: center;
    margin-top: 10px;
    flex-wrap: wrap;
}
#customSizeRow input[type=number] {
    width: 70px;
    padding: 8px 10px;
    font-size: 13px;
    border-radius: 8px;
    border: 1px solid var(--line, #e2e8f0);
    background: var(--bg, #fff);
    color: var(--ink, #0f172a);
    text-align: center;
}
#customSizeRow select {
    padding: 8px 10px;
    font-size: 13px;
    border-radius: 8px;
    border: 1px solid var(--line, #e2e8f0);
    background: var(--bg, #fff);
    color: var(--ink, #0f172a);
}

/* ── Download Split Button ── */
.dl-split-btn {
    position: relative;
    display: inline-flex;
    align-items: stretch;
    border-radius: 10px;
    overflow: visible;
}
.dl-split-main {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 18px;
    font-weight: 800;
    font-size: 13.5px;
    background: var(--lime, #c2f542);
    color: #091a27;
    border: none;
    border-radius: 10px 0 0 10px;
    cursor: pointer;
    transition: background .15s;
    white-space: nowrap;
}
.dl-split-main:hover { background: #b5e236; }
.dl-split-chevron {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 11px;
    background: #aad930;
    color: #091a27;
    border: none;
    border-left: 1px solid rgba(0,0,0,0.12);
    border-radius: 0 10px 10px 0;
    cursor: pointer;
    transition: background .15s;
}
.dl-split-chevron:hover { background: #9dcb28; }
.dl-split-chevron svg { display: block; transition: transform .2s; }
.dl-split-chevron[aria-expanded="true"] svg { transform: rotate(180deg); }

/* Dropdown menu */
.dl-split-menu {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    min-width: 240px;
    background: var(--card, #fff);
    border: 1px solid var(--line, #e2e8f0);
    border-radius: 12px;
    box-shadow: 0 12px 32px rgba(0,0,0,0.14);
    z-index: 999;
    overflow: hidden;
    padding: 6px;
}
.dl-split-menu.open { display: block; }
.dl-split-option {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 11px 12px;
    border: none;
    background: transparent;
    border-radius: 8px;
    text-align: left;
    cursor: pointer;
    text-decoration: none;
    color: var(--ink, #0f172a);
    transition: background .15s;
}
.dl-split-option:hover { background: var(--hover, #f1f5f9); }
.dl-split-option strong { display: block; font-size: 13px; font-weight: 700; }
.dl-split-option small  { display: block; font-size: 11px; color: var(--muted, #64748b); margin-top: 1px; }
.dl-opt-icon { font-size: 20px; flex-shrink: 0; line-height: 1; }
.dl-split-divider { height: 1px; background: var(--line, #e2e8f0); margin: 4px 0; }
</style>


<script src="<?php echo $BASE; ?>assets/js/qrcode.min.js"></script>
<script>
if (typeof QRCode === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>');
}
</script>
<script src="<?php echo $BASE; ?>assets/js/html2canvas.min.js"></script>
<script>
if (typeof html2canvas === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"><\/script>');
}
</script>
<script>
// ─────────────────────────────────────────────────────────────
//  Local QR Code Generator (same-origin, zero CORS issues)
// ─────────────────────────────────────────────────────────────
var qrTargetUrl = <?php echo json_encode($public_url); ?>;
var qrDataUrl   = null; // cached data URL from local QRCode.js generation

// ─── Generate QR into hidden off-page div (never touches visible card) ────────
function initHiddenQr() {
    var gen = document.getElementById('qrHiddenGen');
    if (!gen) return;
    if (typeof QRCode === 'undefined') { setTimeout(initHiddenQr, 100); return; }

    gen.innerHTML = '';
    new QRCode(gen, {
        text: qrTargetUrl,
        width:  500,
        height: 500,
        colorDark:  '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H
    });

    // Extract data URL after QRCode.js finishes (it has an internal async step)
    // Immediately wipe innerHTML after extraction so the canvas never leaks into view.
    function extractDataUrl() {
        if (qrDataUrl) { gen.innerHTML = ''; return; } // already done

        var canvas = gen.querySelector('canvas');
        if (canvas) {
            try {
                qrDataUrl = canvas.toDataURL('image/png');
                gen.innerHTML = ''; // ← destroy immediately
                return;
            } catch (e) {}
        }
        var img = gen.querySelector('img');
        if (img && img.src && img.src.indexOf('data:') === 0) {
            qrDataUrl = img.src;
            gen.innerHTML = ''; // ← destroy immediately
        }
    }
    // QRCode.js converts canvas → img asynchronously — try at 80ms and 500ms
    setTimeout(extractDataUrl, 80);
    setTimeout(extractDataUrl, 500);
}

document.addEventListener('DOMContentLoaded', initHiddenQr);
if (document.readyState !== 'loading') initHiddenQr();

// ─── Split-button dropdown helpers ───────────────────────────────────────────
function toggleDlMenu(e) {
    e.stopPropagation();
    var menu    = document.getElementById('dlMenu');
    var chevron = document.querySelector('.dl-split-chevron');
    var isOpen  = menu.classList.toggle('open');
    if (chevron) chevron.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}
function closeDlMenu() {
    var menu    = document.getElementById('dlMenu');
    var chevron = document.querySelector('.dl-split-chevron');
    if (menu)    menu.classList.remove('open');
    if (chevron) chevron.setAttribute('aria-expanded', 'false');
}
document.addEventListener('click', function(e) {
    var btn = document.getElementById('dlSplitBtn');
    if (btn && !btn.contains(e.target)) { closeDlMenu(); }
});

// ─── Paint QR data URL onto canvas over the blank QR slot ────────────────────
function compositeQr(targetCanvas, qrSrc, cardEl, qrEl) {
    return new Promise(function(resolve) {
        if (!qrSrc) { resolve(targetCanvas); return; }
        var scale    = targetCanvas.width / cardEl.getBoundingClientRect().width;
        var cardRect = cardEl.getBoundingClientRect();
        var qrRect   = qrEl.getBoundingClientRect();
        var x = (qrRect.left - cardRect.left) * scale;
        var y = (qrRect.top  - cardRect.top)  * scale;
        var w = qrRect.width  * scale;
        var h = qrRect.height * scale;

        var img = new Image();
        img.onload = function() {
            // White background behind QR (in case card is dark)
            var ctx = targetCanvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(x, y, w, h);
            ctx.drawImage(img, x, y, w, h);
            resolve(targetCanvas);
        };
        img.onerror = function() { resolve(targetCanvas); }; // graceful
        img.src = qrSrc;
    });
}

// ─── downloadFullDesign ───────────────────────────────────────────────────────
function downloadFullDesign() {
    closeDlMenu();
    var card  = document.getElementById('standCard');
    var qrImg = document.getElementById('qrImage');
    if (!card) { alert('Stand card not found.'); return; }

    var mainBtn  = document.querySelector('.dl-split-main');
    var origText = mainBtn ? mainBtn.innerHTML : '';
    if (mainBtn) { mainBtn.innerHTML = '⏳ Capturing…'; mainBtn.disabled = true; }

    html2canvas(card, {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        backgroundColor: null,
        logging: false
    }).then(function(canvas) {
        // Paint local QR data URL over the blank spot left by CORS-blocked img
        if (qrDataUrl && qrImg) {
            return compositeQr(canvas, qrDataUrl, card, qrImg);
        }
        return canvas;
    }).then(function(canvas) {
        var link = document.createElement('a');
        link.download = 'stand-card-<?php echo preg_replace('/[^a-z0-9]/i', '-', strtolower($brand_name)); ?>.png';
        link.href = canvas.toDataURL('image/png');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }).catch(function(err) {
        console.error('Download error:', err);
        alert('Could not capture the card. Please try the QR Code Only option.');
    }).finally(function() {
        if (mainBtn) { mainBtn.innerHTML = origText; mainBtn.disabled = false; }
    });
}

// ─── downloadQrOnly ───────────────────────────────────────────────────────────
function downloadQrOnly() {
    closeDlMenu();
    var link = document.createElement('a');
    link.download = 'qr-<?php echo preg_replace('/[^a-z0-9]/i', '-', strtolower($brand_name)); ?>.png';
    if (qrDataUrl) {
        link.href = qrDataUrl;
    } else {
        // Fallback to the API URL direct download
        link.href = '<?php echo htmlspecialchars($qr_api_url); ?>';
        link.target = '_blank';
    }
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ─────────────────────────────────────────────────────────────
//  Print Size Configurations
// ─────────────────────────────────────────────────────────────
var printSizeConfigs = {
    'a6':         { pageSize: 'A6 portrait'      },
    'a5':         { pageSize: 'A5 portrait'      },
    'a4':         { pageSize: 'A4 portrait'      },
    'letter':     { pageSize: 'letter portrait'  },
    'custom-4x6': { pageSize: '4in 6in portrait' },
    'custom':     { pageSize: null               }
};

// Show/hide custom size input row when "Custom" is selected
function onSizeChange(val) {
    var row = document.getElementById('customSizeRow');
    if (row) row.style.display = (val === 'custom') ? 'flex' : 'none';
}

// ─────────────────────────────────────────────────────────────
//  handlePrint: open a blank popup containing ONLY the card
//  so the admin-shell layout never interferes with printing
// ─────────────────────────────────────────────────────────────
function handlePrint() {
    var selectedSize = document.getElementById('printSize').value;
    var config = printSizeConfigs[selectedSize] || printSizeConfigs['a5'];

    // Resolve @page size string
    var pageSize = config.pageSize;
    if (selectedSize === 'custom') {
        var w    = parseFloat(document.getElementById('customW').value)    || 14.8;
        var h    = parseFloat(document.getElementById('customH').value)    || 21;
        var unit = document.getElementById('customUnit').value             || 'cm';
        pageSize = w + unit + ' ' + h + unit + ' portrait';
    }

    // Clone the live card (captures current theme / text / visibility tweaks)
    var cardHtml = document.getElementById('standCard').outerHTML;

    // Self-contained HTML document for the popup (all CSS inlined)
    var popupHtml =
        '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Print Stand Card</title>' +
        '<style>' +
        '@page { size: ' + pageSize + '; margin: 10mm; }' +
        'html,body{margin:0;padding:0;background:#fff;font-family:system-ui,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;}' +
        'body{display:flex;justify-content:center;align-items:flex-start;}' +
        /* Card */
        '.table-tent-card{border-radius:20px;padding:32px 28px;text-align:center;position:relative;-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;page-break-inside:avoid;}' +
        /* Dark theme */
        '.table-tent-card.theme-dark{background:#091a27!important;border:2px solid #1a354b;color:#f8fafc;}' +
        '.table-tent-card.theme-dark .stand-brand-name{color:#fff;}' +
        '.table-tent-card.theme-dark .stand-headline{color:#c2f542;}' +
        '.table-tent-card.theme-dark .stand-subtext{color:#94a3b8;}' +
        '.table-tent-card.theme-dark .stand-stars-banner{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);}' +
        '.table-tent-card.theme-dark .stand-qr-box{background:#fff;}' +
        '.table-tent-card.theme-dark .stand-whatsapp-pill{background:rgba(37,211,102,0.12);color:#86efac;border:1px solid rgba(37,211,102,0.25);}' +
        '.table-tent-card.theme-dark .stand-footer{color:#64748b;border-top-color:rgba(255,255,255,0.08);}' +
        /* Light theme */
        '.table-tent-card.theme-light{background:#fff!important;border:2px solid #0f172a;color:#0f172a;}' +
        '.table-tent-card.theme-light .stand-brand-name{color:#0f172a;}' +
        '.table-tent-card.theme-light .stand-headline{color:#0f172a;}' +
        '.table-tent-card.theme-light .stand-subtext{color:#475569;}' +
        '.table-tent-card.theme-light .stand-badge{background:#e2e8f0;color:#0f172a;}' +
        '.table-tent-card.theme-light .stand-stars-banner{background:#f8fafc;border:1px solid #e2e8f0;color:#0f172a;}' +
        '.table-tent-card.theme-light .stand-qr-box{background:#f8fafc;border:2px solid #e2e8f0;}' +
        '.table-tent-card.theme-light .stand-step{background:#f1f5f9;color:#334155;}' +
        '.table-tent-card.theme-light .stand-step-num{background:#0f172a;color:#fff;}' +
        '.table-tent-card.theme-light .stand-whatsapp-pill{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}' +
        '.table-tent-card.theme-light .stand-footer{color:#64748b;border-top-color:#e2e8f0;}' +
        /* Elements */
        '.stand-header{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:16px;}' +
        '.stand-logo{width:44px;height:44px;border-radius:12px;object-fit:cover;background:#fff;}' +
        '.stand-avatar{width:44px;height:44px;border-radius:12px;background:#c2f542;color:#091a27;font-size:16px;font-weight:800;display:flex;align-items:center;justify-content:center;}' +
        '.stand-brand-meta{text-align:left;}' +
        '.stand-brand-name{margin:0;font-size:17px;font-weight:800;line-height:1.2;}' +
        '.stand-badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:rgba(194,245,66,0.18);color:#c2f542;margin-top:3px;}' +
        '.stand-stars-banner{border-radius:12px;padding:8px 14px;display:inline-flex;align-items:center;gap:8px;margin-bottom:18px;}' +
        '.stand-stars{color:#fbbf24;font-size:16px;letter-spacing:1px;}' +
        '.stand-score{font-weight:800;font-size:14px;}' +
        '.stand-review-count{font-size:11.5px;opacity:.8;}' +
        '.stand-cta-box{margin-bottom:20px;}' +
        '.stand-headline{margin:0 0 6px;font-size:20px;font-weight:800;letter-spacing:-.5px;}' +
        '.stand-subtext{margin:0;font-size:12.5px;line-height:1.4;}' +
        '.stand-qr-box{border-radius:16px;padding:16px;display:inline-block;margin-bottom:18px;}' +
        '.stand-qr-container{display:flex;justify-content:center;align-items:center;width:200px;height:200px;margin:0 auto;overflow:hidden;flex-shrink:0;}' +
        '.stand-qr-container img,.stand-qr-container canvas{width:200px!important;height:200px!important;max-width:200px!important;max-height:200px!important;min-width:unset!important;min-height:unset!important;display:block!important;flex-shrink:0!important;object-fit:contain;}' +
        '.stand-qr-scan-badge{margin-top:8px;background:#0f172a;color:#c2f542;padding:4px 10px;border-radius:99px;font-size:10px;font-weight:800;letter-spacing:1px;display:inline-block;}' +
        '.stand-steps-row{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:16px;font-size:11px;font-weight:700;}' +
        '.stand-step{background:rgba(255,255,255,0.06);padding:5px 10px;border-radius:99px;display:flex;align-items:center;gap:5px;}' +
        '.stand-step-num{width:15px;height:15px;border-radius:50%;background:#c2f542;color:#091a27;font-size:10px;display:flex;align-items:center;justify-content:center;font-weight:900;}' +
        '.stand-step-arrow{opacity:.4;font-size:12px;}' +
        '.stand-whatsapp-pill{font-size:11px;line-height:1.4;padding:8px 12px;border-radius:10px;display:flex;align-items:center;gap:8px;text-align:left;margin-bottom:16px;}' +
        '.stand-whatsapp-pill svg{width:16px;height:16px;fill:currentColor;flex-shrink:0;}' +
        '.stand-footer{font-size:10px;padding-top:12px;border-top:1px solid #e2e8f0;display:flex;flex-direction:column;gap:3px;}' +
        '.stand-url-hint{font-family:monospace;font-size:9px;opacity:.7;word-break:break-all;}' +
        '.stand-fold-guide{display:block!important;font-size:9px;text-align:center;color:#64748b;letter-spacing:1px;text-transform:uppercase;margin-bottom:16px;padding-bottom:8px;border-bottom:1px dashed #cbd5e1;}' +
        '.no-screen{display:block!important;}' +
        '</style></head><body>' +
        cardHtml +
        '<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};};<\/script>' +
        '</body></html>';

    var popup = window.open('', '_blank', 'width=800,height=900,scrollbars=yes,resizable=yes');
    if (!popup) {
        alert('Pop-up blocked! Please allow pop-ups for this site and try again.');
        return;
    }
    popup.document.open();
    popup.document.write(popupHtml);
    popup.document.close();
}

// ─────────────────────────────────────────────────────────────
//  Live preview helpers
// ─────────────────────────────────────────────────────────────
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
    if (el) { el.style.display = show ? '' : 'none'; }
}
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
