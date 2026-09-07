<?php
/**
 * ============================================================
 *  Admin — Social Proof Card Generator
 * ============================================================
 *  Generates high-resolution, branded review graphics tailored
 *  for WhatsApp Status (9:16), Instagram Feed (1:1), and Banner (16:9).
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

$company_id     = (int)($company_profile['id'] ?? 0);
$brand_name     = !empty($company_profile['company_name']) ? $company_profile['company_name'] : ($tenant['company_name'] ?? 'Your Business');
$brand_category = !empty($company_profile['category_name']) ? $company_profile['category_name'] : 'Verified Business';
$brand_logo     = $tenant['logo'] ?? '';

// Build base public review URL
$__scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__root     = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$public_url = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $__root . '/rate/index.php?tenant=' . (int)$tenant_id;

// Fetch top positive reviews with text comments (4 & 5 stars)
$reviews = [];
if ($company_id > 0) {
    $r_stmt = $conn->prepare("SELECT r.*, c.company_name FROM ratings r JOIN customers c ON r.company_id = c.id WHERE r.company_id = ? AND r.rating >= 4 AND r.comment IS NOT NULL AND TRIM(r.comment) != '' ORDER BY r.is_verified DESC, r.rating DESC, r.created_at DESC LIMIT 30");
    if ($r_stmt) {
        $r_stmt->bind_param("i", $company_id);
        $r_stmt->execute();
        $res = $r_stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $reviews[] = $row;
        }
        $r_stmt->close();
    }
}

// Selected review from query param or first available
$selected_id = isset($_GET['rating_id']) ? (int)$_GET['rating_id'] : 0;
$initial_review = null;
if ($selected_id > 0) {
    foreach ($reviews as $rev) {
        if ((int)$rev['id'] === $selected_id) {
            $initial_review = $rev;
            break;
        }
    }
}
if (!$initial_review && !empty($reviews)) {
    $initial_review = $reviews[0];
}

// Fallback demo review if workspace has zero reviews yet
if (!$initial_review) {
    $initial_review = [
        'id' => 0,
        'customer_name' => 'Kofi Mensah',
        'rating' => 5,
        'comment' => 'Exceptional service and outstanding quality! The staff went above and beyond to make sure our experience was top-notch. Highly recommended to everyone!',
        'is_verified' => 1,
        'created_at' => date('Y-m-d H:i:s')
    ];
}

$BASE      = '../';
$pageTitle = 'Social Proof Card Generator';
$activeNav = 'social_card';
include __DIR__ . '/_shell.php';
?>

<!-- Header -->
<div class="welcome-row" style="margin-bottom:24px;">
    <div>
        <p class="eyebrow">Marketing &middot; Social Proof Graphics</p>
        <h1 style="margin:0;">Social Proof Card Generator</h1>
        <p class="muted" style="margin-top:6px;">Turn 5-star customer reviews into stunning graphics formatted for WhatsApp Status, Instagram Stories, and Facebook Feeds.</p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <a href="ratings.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;">
            ☆ All Reviews
        </a>
        <a href="whatsapp_sender.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;background:#dcfce7;color:#15803d;border:1px solid #86efac;font-weight:700;">
            💬 Ask for Reviews
        </a>
    </div>
</div>

<!-- Main Builder Layout -->
<div class="grid-2col" style="align-items:start;gap:26px;">

    <!-- Left Column: Graphic Controls -->
    <div class="form-card" style="padding:26px;">
        
        <!-- Step 1: Select or Customize Review -->
        <div style="margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid var(--line);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="margin:0;font-size:15.5px;color:var(--ink);">1. Select Review</h3>
                <span class="muted" style="font-size:12px;"><?php echo count($reviews); ?> positive reviews available</span>
            </div>

            <?php if (!empty($reviews)): ?>
                <div class="form-group" style="margin-bottom:14px;">
                    <label for="reviewPicker" style="font-weight:700;font-size:12.5px;">Choose from your real reviews:</label>
                    <select id="reviewPicker" onchange="onReviewPicked(this.value)" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid #cbd5e1;font-size:13px;background:var(--bg);color:var(--ink);">
                        <?php foreach ($reviews as $r): 
                            $snippet = mb_substr(strip_tags($r['comment']), 0, 60) . (mb_strlen($r['comment']) > 60 ? '...' : '');
                            $stars = str_repeat('★', (int)$r['rating']);
                        ?>
                            <option value="<?php echo (int)$r['id']; ?>" <?php echo ((int)$r['id'] === (int)$initial_review['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['customer_name'] ?: 'Anonymous'); ?> (<?php echo $stars; ?>) &mdash; "<?php echo htmlspecialchars($snippet); ?>"
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#92400e;">
                    💡 No 4★ or 5★ customer reviews found yet. You can use the custom editor below to draft a sample card!
                </div>
            <?php endif; ?>

            <div class="form-grid" style="gap:12px;">
                <div class="form-group">
                    <label for="cardCustName" style="font-weight:700;font-size:12.5px;">Customer Name</label>
                    <input type="text" id="cardCustName" value="<?php echo htmlspecialchars($initial_review['customer_name'] ?: 'Valued Customer'); ?>" oninput="renderCard()">
                </div>
                <div class="form-group">
                    <label for="cardStars" style="font-weight:700;font-size:12.5px;">Rating Stars</label>
                    <select id="cardStars" onchange="renderCard()" style="padding:9px 12px;font-size:13px;border-radius:8px;border:1px solid #cbd5e1;">
                        <option value="5" <?php echo ((int)$initial_review['rating'] === 5) ? 'selected' : ''; ?>>★★★★★ 5.0 Stars (Perfect)</option>
                        <option value="4" <?php echo ((int)$initial_review['rating'] === 4) ? 'selected' : ''; ?>>★★★★☆ 4.0 Stars (Great)</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label for="cardComment" style="font-weight:700;font-size:12.5px;">Review Text</label>
                    <textarea id="cardComment" rows="4" oninput="renderCard()" style="width:100%;font-size:13px;line-height:1.45;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;resize:vertical;"><?php echo htmlspecialchars($initial_review['comment']); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Step 2: Format / Aspect Ratio -->
        <div style="margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid var(--line);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="margin:0;font-size:15.5px;color:var(--ink);">2. Format &amp; Aspect Ratio</h3>
                <span class="muted" style="font-size:12px;">Optimized dimensions</span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:10px;" id="aspectRatioGroup">
                <button type="button" class="aspect-btn active" data-ratio="story" onclick="setAspectRatio('story')">
                    <span style="font-size:20px;display:block;margin-bottom:4px;">📱</span>
                    <strong>WhatsApp Status</strong>
                    <small>9:16 &middot; 1080&times;1920</small>
                </button>
                <button type="button" class="aspect-btn" data-ratio="square" onclick="setAspectRatio('square')">
                    <span style="font-size:20px;display:block;margin-bottom:4px;">📷</span>
                    <strong>Instagram Feed</strong>
                    <small>1:1 &middot; 1080&times;1080</small>
                </button>
                <button type="button" class="aspect-btn" data-ratio="banner" onclick="setAspectRatio('banner')">
                    <span style="font-size:20px;display:block;margin-bottom:4px;">💻</span>
                    <strong>Banner / Web</strong>
                    <small>16:9 &middot; 1200&times;675</small>
                </button>
            </div>
        </div>

        <!-- Step 3: Visual Theme Palette -->
        <div style="margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid var(--line);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="margin:0;font-size:15.5px;color:var(--ink);">3. Color Theme</h3>
                <span class="muted" style="font-size:12px;">Studio styles</span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:10px;" id="themeGroup">
                <button type="button" class="theme-pill active" data-theme="midnight" onclick="setTheme('midnight')" style="background:#091a27;color:#fff;border-color:#c2f542;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#c2f542;display:inline-block;margin-right:6px;"></span>
                    Midnight
                </button>
                <button type="button" class="theme-pill" data-theme="emerald" onclick="setTheme('emerald')" style="background:#064e3b;color:#fff;border-color:#34d399;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#34d399;display:inline-block;margin-right:6px;"></span>
                    Emerald
                </button>
                <button type="button" class="theme-pill" data-theme="sunset" onclick="setTheme('sunset')" style="background:#431407;color:#fff;border-color:#f59e0b;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#f59e0b;display:inline-block;margin-right:6px;"></span>
                    Sunset
                </button>
                <button type="button" class="theme-pill" data-theme="royal" onclick="setTheme('royal')" style="background:#1e1b4b;color:#fff;border-color:#a855f7;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#a855f7;display:inline-block;margin-right:6px;"></span>
                    Royal
                </button>
                <button type="button" class="theme-pill" data-theme="editorial" onclick="setTheme('editorial')" style="background:#ffffff;color:#091a27;border:1px solid #cbd5e1;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#091a27;display:inline-block;margin-right:6px;"></span>
                    Editorial
                </button>
                <button type="button" class="theme-pill" data-theme="gold" onclick="setTheme('gold')" style="background:#1c1917;color:#fff;border-color:#eab308;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#eab308;display:inline-block;margin-right:6px;"></span>
                    Ghana Gold
                </button>
            </div>
        </div>

        <!-- Step 4: Customizer Toggles -->
        <div>
            <h3 style="margin:0 0 12px;font-size:15.5px;color:var(--ink);">4. Badges &amp; Details</h3>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" id="toggleVerified" checked onchange="renderCard()" style="accent-color:#16a34a;width:17px;height:17px;">
                    <span>Display <strong>"✓ Verified Customer"</strong> badge</span>
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" id="toggleLogo" checked onchange="renderCard()" style="accent-color:#16a34a;width:17px;height:17px;">
                    <span>Display business branding &amp; logo</span>
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" id="toggleQr" checked onchange="renderCard()" style="accent-color:#16a34a;width:17px;height:17px;">
                    <span>Display scannable QR verification stamp</span>
                </label>
                <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" id="toggleDate" checked onchange="renderCard()" style="accent-color:#16a34a;width:17px;height:17px;">
                    <span>Display review date (<?php echo date('M Y'); ?>)</span>
                </label>
            </div>
        </div>

    </div>

    <!-- Right Column: Live Canvas Preview & Export Hub -->
    <div style="display:flex;flex-direction:column;gap:18px;position:sticky;top:20px;">
        
        <!-- Live Preview Stage -->
        <div class="form-card" style="padding:22px;display:flex;flex-direction:column;align-items:center;background:#f8fafc;border:1px solid #cbd5e1;">
            <div style="width:100%;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <strong style="font-size:13.5px;color:var(--ink);">🎨 Real-Time Graphic Preview</strong>
                <span style="font-size:11.5px;background:#e2e8f0;color:#475569;font-weight:700;padding:3px 8px;border-radius:99px;" id="previewDimLabel">
                    1080 &times; 1920
                </span>
            </div>

            <!-- Canvas Viewport with responsive constraints -->
            <div id="previewContainer" style="display:flex;justify-content:center;align-items:center;width:100%;max-width:380px;background:#000;border-radius:14px;box-shadow:0 12px 36px rgba(0,0,0,0.18);overflow:hidden;">
                <canvas id="cardCanvas" style="width:100%;height:auto;display:block;"></canvas>
            </div>

            <p class="muted" style="font-size:11.5px;margin:12px 0 0;text-align:center;">
                ✨ Rendered at full 1080p resolution. Ready to post directly to WhatsApp Status or Instagram.
            </p>
        </div>

        <!-- 1-Click Action Card -->
        <div class="form-card" style="padding:22px;border-left:4px solid #c2f542;">
            <h3 style="margin:0 0 12px;font-size:15px;color:var(--ink);">Export &amp; Share</h3>
            
            <div style="display:flex;flex-direction:column;gap:10px;">
                <button type="button" onclick="downloadCardImage()" class="btn-export btn-export-primary">
                    ⬇ Download High-Res PNG (1080p)
                </button>

                <button type="button" onclick="copyCardToClipboard()" class="btn-export btn-export-secondary">
                    📋 Copy Image to Clipboard (Paste in WhatsApp Web)
                </button>

                <button type="button" onclick="shareCaptionToWhatsApp()" class="btn-export btn-export-secondary" style="color:#15803d;background:#f0fdf4;border-color:#86efac;">
                    💬 Share Caption to WhatsApp ↗
                </button>
            </div>

            <div id="cardToast" style="display:none;margin-top:12px;padding:8px 12px;border-radius:6px;background:#dcfce7;color:#166534;font-size:12px;font-weight:600;text-align:center;">
                ✓ Copied to clipboard!
            </div>
        </div>

    </div>

</div>

<style>
.aspect-btn {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 12px 8px;
    background: #ffffff;
    cursor: pointer;
    text-align: center;
    transition: all 0.2s ease;
}
.aspect-btn:hover {
    border-color: #091a27;
    background: #f8fafc;
}
.aspect-btn.active {
    border-color: #091a27;
    background: #091a27;
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(9, 26, 39, 0.2);
}
.aspect-btn strong {
    display: block;
    font-size: 12px;
}
.aspect-btn small {
    display: block;
    font-size: 10.5px;
    opacity: 0.8;
    margin-top: 2px;
}
.theme-pill {
    border: 2px solid transparent;
    border-radius: 8px;
    padding: 10px 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}
.theme-pill:hover {
    transform: translateY(-1px);
}
.theme-pill.active {
    box-shadow: 0 0 0 3px rgba(194, 245, 66, 0.6);
}
.btn-export {
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
.btn-export-primary {
    background: #091a27;
    color: #c2f542;
    box-shadow: 0 4px 14px rgba(9, 26, 39, 0.25);
}
.btn-export-primary:hover {
    background: #0d2538;
    transform: translateY(-1px);
}
.btn-export-secondary {
    background: #ffffff;
    color: var(--ink);
    border: 1px solid #cbd5e1;
}
.btn-export-secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
</style>

<script>
// State
var reviewsData   = <?php echo json_encode($reviews); ?>;
var brandName     = <?php echo json_encode($brand_name); ?>;
var brandCategory = <?php echo json_encode($brand_category); ?>;
var brandLogoSrc  = <?php echo json_encode($brand_logo ? ('../' . ltrim($brand_logo, '/')) : ''); ?>;
var publicUrl     = <?php echo json_encode($public_url); ?>;
var qrApiUrl      = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=0&format=png&data=' + encodeURIComponent(publicUrl);

var currentRatio = 'story'; // story (9:16), square (1:1), banner (16:9)
var currentTheme = 'midnight';

var brandLogoImg = null;
var qrCodeImg    = null;

// Preload Images
if (brandLogoSrc) {
    brandLogoImg = new Image();
    brandLogoImg.crossOrigin = 'anonymous';
    brandLogoImg.src = brandLogoSrc;
    brandLogoImg.onload = function() { renderCard(); };
}

qrCodeImg = new Image();
qrCodeImg.crossOrigin = 'anonymous';
qrCodeImg.src = qrApiUrl;
qrCodeImg.onload = function() { renderCard(); };

var themes = {
    midnight: {
        bgTop: '#091a27', bgBot: '#050f17',
        accent: '#c2f542', text: '#ffffff',
        muted: '#94a3b8', cardBg: 'rgba(255,255,255,0.04)',
        cardBorder: 'rgba(255,255,255,0.1)', stars: '#facc15'
    },
    emerald: {
        bgTop: '#064e3b', bgBot: '#022c22',
        accent: '#34d399', text: '#ffffff',
        muted: '#a7f3d0', cardBg: 'rgba(255,255,255,0.05)',
        cardBorder: 'rgba(255,255,255,0.12)', stars: '#fbbf24'
    },
    sunset: {
        bgTop: '#431407', bgBot: '#1c0803',
        accent: '#f59e0b', text: '#ffffff',
        muted: '#fed7aa', cardBg: 'rgba(255,255,255,0.05)',
        cardBorder: 'rgba(255,255,255,0.12)', stars: '#f59e0b'
    },
    royal: {
        bgTop: '#1e1b4b', bgBot: '#0f0e26',
        accent: '#a855f7', text: '#ffffff',
        muted: '#ddd6fe', cardBg: 'rgba(255,255,255,0.05)',
        cardBorder: 'rgba(255,255,255,0.12)', stars: '#fbbf24'
    },
    editorial: {
        bgTop: '#ffffff', bgBot: '#f1f5f9',
        accent: '#091a27', text: '#091a27',
        muted: '#64748b', cardBg: '#ffffff',
        cardBorder: '#e2e8f0', stars: '#eab308'
    },
    gold: {
        bgTop: '#1c1917', bgBot: '#0c0a09',
        accent: '#eab308', text: '#ffffff',
        muted: '#fef08a', cardBg: 'rgba(255,255,255,0.04)',
        cardBorder: 'rgba(234,179,8,0.25)', stars: '#eab308'
    }
};

function setAspectRatio(ratio) {
    currentRatio = ratio;
    document.querySelectorAll('#aspectRatioGroup .aspect-btn').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-ratio') === ratio);
    });
    
    var container = document.getElementById('previewContainer');
    if (ratio === 'story') {
        container.style.maxWidth = '320px';
        document.getElementById('previewDimLabel').innerText = '1080 × 1920 (Story)';
    } else if (ratio === 'square') {
        container.style.maxWidth = '380px';
        document.getElementById('previewDimLabel').innerText = '1080 × 1080 (Square)';
    } else {
        container.style.maxWidth = '460px';
        document.getElementById('previewDimLabel').innerText = '1200 × 675 (Banner)';
    }

    renderCard();
}

function setTheme(themeKey) {
    currentTheme = themeKey;
    document.querySelectorAll('#themeGroup .theme-pill').forEach(function(p) {
        p.classList.toggle('active', p.getAttribute('data-theme') === themeKey);
    });
    renderCard();
}

function onReviewPicked(reviewId) {
    var id = parseInt(reviewId, 10);
    var found = null;
    for (var i = 0; i < reviewsData.length; i++) {
        if (parseInt(reviewsData[i].id, 10) === id) {
            found = reviewsData[i];
            break;
        }
    }
    if (found) {
        document.getElementById('cardCustName').value = found.customer_name || 'Valued Customer';
        document.getElementById('cardStars').value = String(found.rating || 5);
        document.getElementById('cardComment').value = found.comment || '';
        document.getElementById('toggleVerified').checked = (found.is_verified == 1);
        renderCard();
    }
}

// Text wrapping utility
function wrapText(ctx, text, maxWidth) {
    var words = text.split(/\s+/);
    var lines = [];
    var currentLine = words[0] || '';

    for (var i = 1; i < words.length; i++) {
        var word = words[i];
        var width = ctx.measureText(currentLine + ' ' + word).width;
        if (width < maxWidth) {
            currentLine += ' ' + word;
        } else {
            lines.push(currentLine);
            currentLine = word;
        }
    }
    if (currentLine) lines.push(currentLine);
    return lines;
}

function renderCard() {
    var canvas = document.getElementById('cardCanvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');

    var width = 1080;
    var height = 1920;

    if (currentRatio === 'square') {
        width = 1080;
        height = 1080;
    } else if (currentRatio === 'banner') {
        width = 1200;
        height = 675;
    }

    canvas.width = width;
    canvas.height = height;

    var theme = themes[currentTheme] || themes.midnight;
    var custName = (document.getElementById('cardCustName').value || '').trim() || 'Valued Customer';
    var stars = parseInt(document.getElementById('cardStars').value || 5, 10);
    var comment = (document.getElementById('cardComment').value || '').trim() || 'Great customer experience!';
    var showVerified = document.getElementById('toggleVerified').checked;
    var showLogo = document.getElementById('toggleLogo').checked;
    var showQr = document.getElementById('toggleQr').checked;
    var showDate = document.getElementById('toggleDate').checked;

    // 1. Background Gradient
    var bgGrad = ctx.createLinearGradient(0, 0, 0, height);
    bgGrad.addColorStop(0, theme.bgTop);
    bgGrad.addColorStop(1, theme.bgBot);
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, 0, width, height);

    // Subtle Radial Glow
    var glow = ctx.createRadialGradient(width * 0.5, height * 0.35, 20, width * 0.5, height * 0.35, width * 0.8);
    glow.addColorStop(0, theme.accent + '15');
    glow.addColorStop(1, 'transparent');
    ctx.fillStyle = glow;
    ctx.fillRect(0, 0, width, height);

    // Ambient Outer Card Border
    ctx.strokeStyle = theme.cardBorder;
    ctx.lineWidth = 4;
    ctx.strokeRect(30, 30, width - 60, height - 60);

    // 2. Header Branding
    var padX = currentRatio === 'banner' ? 70 : 80;
    var headerY = currentRatio === 'banner' ? 70 : (currentRatio === 'story' ? 140 : 100);

    if (showLogo) {
        // Avatar circle
        var avatarR = currentRatio === 'banner' ? 26 : 32;
        var avatarX = padX + avatarR;
        var avatarY = headerY + avatarR;

        ctx.save();
        ctx.beginPath();
        ctx.arc(avatarX, avatarY, avatarR, 0, Math.PI * 2);
        ctx.closePath();
        ctx.clip();

        if (brandLogoImg && brandLogoImg.complete && brandLogoImg.naturalWidth > 0) {
            ctx.drawImage(brandLogoImg, avatarX - avatarR, avatarY - avatarR, avatarR * 2, avatarR * 2);
        } else {
            ctx.fillStyle = theme.accent;
            ctx.fillRect(avatarX - avatarR, avatarY - avatarR, avatarR * 2, avatarR * 2);
            ctx.fillStyle = '#091a27';
            ctx.font = 'bold ' + Math.round(avatarR * 0.9) + 'px -apple-system, BlinkMacSystemFont, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(brandName.charAt(0).toUpperCase(), avatarX, avatarY);
        }
        ctx.restore();

        // Business Name & Category
        var brandTextX = avatarX + avatarR + 18;
        ctx.fillStyle = theme.text;
        ctx.font = 'bold ' + (currentRatio === 'banner' ? 26 : 32) + 'px -apple-system, BlinkMacSystemFont, sans-serif';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText(brandName, brandTextX, avatarY - 10);

        ctx.fillStyle = theme.muted;
        ctx.font = '600 ' + (currentRatio === 'banner' ? 16 : 19) + 'px -apple-system, BlinkMacSystemFont, sans-serif';
        ctx.fillText(brandCategory + ' · Customer Reviews', brandTextX, avatarY + 16);
    }

    // 3. Central Review Card Box
    var cardMarginX = currentRatio === 'banner' ? 60 : 70;
    var cardTop = currentRatio === 'banner' ? 150 : (currentRatio === 'story' ? 270 : 200);
    var cardBottom = currentRatio === 'banner' ? (height - 80) : (currentRatio === 'story' ? (height - 230) : (height - 140));
    var cardWidth = width - (cardMarginX * 2);
    var cardHeight = cardBottom - cardTop;

    // Card background fill
    ctx.fillStyle = theme.cardBg;
    ctx.strokeStyle = theme.cardBorder;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.roundRect(cardMarginX, cardTop, cardWidth, cardHeight, 24);
    ctx.fill();
    ctx.stroke();

    // Large Quotation Mark
    ctx.fillStyle = theme.accent;
    ctx.font = 'bold ' + (currentRatio === 'banner' ? 70 : 100) + 'px Georgia, serif';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
    ctx.fillText('“', cardMarginX + 36, cardTop + 20);

    // Stars Rating
    var starY = cardTop + (currentRatio === 'banner' ? 36 : 56);
    var starX = cardMarginX + (currentRatio === 'banner' ? 100 : 120);
    var starStr = '';
    for (var s = 0; s < stars; s++) starStr += '★ ';
    for (var s = stars; s < 5; s++) starStr += '☆ ';

    ctx.fillStyle = theme.stars;
    ctx.font = 'bold ' + (currentRatio === 'banner' ? 32 : 42) + 'px sans-serif';
    ctx.fillText(starStr.trim(), starX, starY);

    // 4. Review Comment Text
    var textPaddingX = cardMarginX + 44;
    var maxTextWidth = cardWidth - 88;
    var textY = cardTop + (currentRatio === 'banner' ? 100 : 140);
    
    // Choose font size based on text length and aspect ratio
    var fontSize = currentRatio === 'banner' ? 22 : (currentRatio === 'story' ? 34 : 32);
    if (comment.length > 200) fontSize -= 4;
    if (comment.length > 350) fontSize -= 4;
    if (currentRatio === 'banner' && comment.length > 150) fontSize = 18;

    ctx.fillStyle = theme.text;
    ctx.font = '500 ' + fontSize + 'px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';

    var lines = wrapText(ctx, comment, maxTextWidth);
    var lineHeight = fontSize * 1.5;

    // Limit visible lines so it fits comfortably
    var maxLines = currentRatio === 'banner' ? 4 : (currentRatio === 'story' ? 10 : 7);
    for (var l = 0; l < Math.min(lines.length, maxLines); l++) {
        var lineText = lines[l];
        if (l === maxLines - 1 && lines.length > maxLines) {
            lineText += '...';
        }
        ctx.fillText(lineText, textPaddingX, textY + (l * lineHeight));
    }

    // 5. Customer Attribution Block (Inside bottom of card)
    var authorY = cardBottom - (currentRatio === 'banner' ? 50 : 70);

    // Customer Avatar Circle (Initial)
    var custAvatarR = currentRatio === 'banner' ? 20 : 26;
    var custAvatarX = textPaddingX + custAvatarR;
    var custAvatarCenterY = authorY + custAvatarR;

    ctx.fillStyle = theme.accent;
    ctx.beginPath();
    ctx.arc(custAvatarX, custAvatarCenterY, custAvatarR, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = '#091a27';
    ctx.font = 'bold ' + Math.round(custAvatarR * 0.95) + 'px -apple-system, BlinkMacSystemFont, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(custName.charAt(0).toUpperCase(), custAvatarX, custAvatarCenterY);

    // Customer Name
    var custTextX = custAvatarX + custAvatarR + 14;
    ctx.fillStyle = theme.text;
    ctx.font = 'bold ' + (currentRatio === 'banner' ? 20 : 24) + 'px -apple-system, BlinkMacSystemFont, sans-serif';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText(custName, custTextX, custAvatarCenterY - 8);

    // Verified badge / Date under customer name
    var metaText = '';
    if (showVerified) metaText += '✓ Verified Customer';
    if (showDate) metaText += (metaText ? ' · ' : '') + '<?php echo date('M Y'); ?>';

    if (metaText) {
        ctx.fillStyle = showVerified ? '#22c55e' : theme.muted;
        ctx.font = '700 ' + (currentRatio === 'banner' ? 13 : 15) + 'px -apple-system, BlinkMacSystemFont, sans-serif';
        ctx.fillText(metaText, custTextX, custAvatarCenterY + 12);
    }

    // 6. Footer (Bottom of graphic)
    var footerY = height - (currentRatio === 'banner' ? 45 : (currentRatio === 'story' ? 130 : 80));

    if (showQr && qrCodeImg && qrCodeImg.complete && qrCodeImg.naturalWidth > 0 && currentRatio !== 'banner') {
        var qrSize = currentRatio === 'story' ? 96 : 76;
        var qrX = width - cardMarginX - qrSize;
        var qrY = footerY - 20;

        // White background for QR code
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.roundRect(qrX - 6, qrY - 6, qrSize + 12, qrSize + 12, 8);
        ctx.fill();

        ctx.drawImage(qrCodeImg, qrX, qrY, qrSize, qrSize);

        ctx.fillStyle = theme.muted;
        ctx.font = '600 13px -apple-system, BlinkMacSystemFont, sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText('Scan to read all verified reviews', qrX - 14, qrY + (qrSize / 2) - 4);
        ctx.fillStyle = theme.text;
        ctx.font = 'bold 14px monospace';
        ctx.fillText('optibiz.net/rate', qrX - 14, qrY + (qrSize / 2) + 14);
    } else {
        ctx.fillStyle = theme.muted;
        ctx.font = '600 ' + (currentRatio === 'banner' ? 13 : 16) + 'px -apple-system, BlinkMacSystemFont, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Verified Customer Review on Optibiz · ' + brandName, width / 2, footerY);
    }
}

function downloadCardImage() {
    var canvas = document.getElementById('cardCanvas');
    var custName = (document.getElementById('cardCustName').value || 'review').toLowerCase().replace(/[^a-z0-9]/g, '-');
    var filename = 'review-' + custName + '-' + currentRatio + '.png';

    var link = document.createElement('a');
    link.download = filename;
    link.href = canvas.toDataURL('image/png');
    link.click();
    showToast('Graphic downloaded successfully!');
}

function copyCardToClipboard() {
    var canvas = document.getElementById('cardCanvas');
    if (!navigator.clipboard || !window.ClipboardItem) {
        alert('Clipboard image copying is not supported on this browser. Please use the Download button instead.');
        return;
    }

    canvas.toBlob(function(blob) {
        if (!blob) return;
        var item = new ClipboardItem({ 'image/png': blob });
        navigator.clipboard.write([item]).then(function() {
            showToast('Review graphic copied to clipboard! Paste into WhatsApp Web.');
        }).catch(function(err) {
            console.error(err);
            downloadCardImage();
        });
    });
}

function shareCaptionToWhatsApp() {
    var custName = document.getElementById('cardCustName').value.trim();
    var comment  = document.getElementById('cardComment').value.trim();
    var msg = '⭐⭐⭐⭐⭐ " ' + comment + ' "\n\n— ' + custName + ' on ' + brandName + '\n\nRead all our verified reviews or share your feedback here: ' + publicUrl;
    var url = 'https://wa.me/?text=' + encodeURIComponent(msg);
    window.open(url, '_blank');
}

function showToast(msg) {
    var t = document.getElementById('cardToast');
    if (!t) return;
    t.innerText = '✓ ' + msg;
    t.style.display = 'block';
    setTimeout(function() { t.style.display = 'none'; }, 2800);
}

// Initial draw on DOM load
document.addEventListener('DOMContentLoaded', function() {
    renderCard();
});
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
