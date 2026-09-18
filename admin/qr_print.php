<?php
/**
 * ============================================================
 *  QR Stand Print Preview — opens in a new tab
 * ============================================================
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();

$tenant_id = getTenantId();

$tenant = null;
if ($tenant_id) {
    $t = $conn->prepare("SELECT t.*, p.plan_name FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id=p.id WHERE t.id=?");
    $t->bind_param("i", $tenant_id);
    $t->execute();
    $tenant = $t->get_result()->fetch_assoc();
    $t->close();
}

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

$company_id = (int)($company_profile['id'] ?? 0);
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

$brand_name     = !empty($company_profile['company_name']) ? $company_profile['company_name'] : ($tenant['company_name'] ?? 'Your Business');
$brand_category = !empty($company_profile['category_name']) ? $company_profile['category_name'] : 'Verified Business';
$brand_logo     = $tenant['logo'] ?? '';
$brand_initials = strtoupper(substr($brand_name, 0, 2));
$wa_number      = $company_profile['whatsapp_number'] ?? '';
$wa_display     = function_exists('whatsappDisplay') ? whatsappDisplay($wa_number) : $wa_number;

if ($company_id > 0) {
    $public_url = getCompanyPublicRatingUrl($company_id, $brand_name, ['src' => 'qr']);
} else {
    $public_url = getCompanyPublicRatingUrl(0, $brand_name, ['tenant' => (int)$tenant_id, 'src' => 'qr']);
}

$qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&margin=15&format=png&data=' . rawurlencode($public_url);

// Print size
$size_map = [
    'a6'         => 'A6 portrait',
    'a5'         => 'A5 portrait',
    'a4'         => 'A4 portrait',
    'letter'     => 'letter portrait',
    'custom-4x6' => '4in 6in portrait',
];
$selected_size = $_GET['size'] ?? 'a5';
if ($selected_size === 'custom') {
    $cw   = floatval($_GET['customW']    ?? 14.8);
    $ch   = floatval($_GET['customH']    ?? 21);
    $cu   = in_array($_GET['customUnit'] ?? 'cm', ['cm','mm','in']) ? $_GET['customUnit'] : 'cm';
    $page_size = "{$cw}{$cu} {$ch}{$cu} portrait";
} else {
    $page_size = $size_map[$selected_size] ?? 'A5 portrait';
}

$theme    = in_array($_GET['theme'] ?? '', ['theme-dark','theme-light']) ? $_GET['theme'] : 'theme-dark';
$headline = htmlspecialchars($_GET['headline'] ?? 'Loved your experience?');
$subtext  = htmlspecialchars($_GET['subtext']  ?? 'Scan with your phone camera to leave us a quick review!');
$brand_slug = preg_replace('/[^a-z0-9]/i', '-', strtolower($brand_name));

$BASE = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Print Preview &mdash; <?php echo htmlspecialchars($brand_name); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;}
html,body{margin:0;padding:0;font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9;-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;}

/* ── Toolbar ── */
#print-toolbar{
    position:fixed;top:0;left:0;right:0;z-index:999;
    background:#0f172a;color:#fff;
    display:flex;align-items:center;gap:10px;
    padding:10px 20px;
    box-shadow:0 2px 8px rgba(0,0,0,0.4);
}
#print-toolbar h1{margin:0;font-size:13px;font-weight:700;color:#c2f542;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tb-btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:8px 18px;border-radius:8px;border:none;cursor:pointer;
    font-size:13px;font-weight:700;white-space:nowrap;
    transition:opacity .15s;
}
.tb-btn:hover{opacity:.85;}
.tb-print{background:#c2f542;color:#0f172a;}
.tb-dl{background:#334155;color:#fff;}
.tb-close{background:transparent;color:#94a3b8;border:1px solid #334155;}

/* ── Preview area ── */
#preview-area{
    padding-top:65px;
    padding-bottom:40px;
    min-height:100vh;
    display:flex;
    justify-content:center;
    align-items:flex-start;
}
#card-wrap{
    margin:24px auto;
    padding:24px;
    background:#fff;
    border-radius:16px;
    box-shadow:0 4px 24px rgba(0,0,0,0.12);
    display:inline-block;
}

/* ── @page ── */
@page{size:<?php echo $page_size; ?>;margin:10mm;}

/* ── Print overrides ── */
@media print{
    #print-toolbar{display:none!important;}
    html,body{background:transparent;}
    #preview-area{padding:0;min-height:unset;display:block;}
    #card-wrap{margin:0;padding:0;box-shadow:none;border-radius:0;background:transparent;}
    .table-tent-card{page-break-inside:avoid;break-inside:avoid;}
}

/* ═══ Stand card styles ═══ */
.table-tent-card{
    border-radius:20px;padding:28px 24px;text-align:center;position:relative;
    width:320px;max-width:100%;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;
}
/* Dark */
.table-tent-card.theme-dark{background:#091a27!important;border:2px solid #1a354b;color:#f8fafc;}
.table-tent-card.theme-dark .stand-brand-name{color:#fff;}
.table-tent-card.theme-dark .stand-headline{color:#c2f542;}
.table-tent-card.theme-dark .stand-subtext{color:#94a3b8;}
.table-tent-card.theme-dark .stand-stars-banner{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);}
.table-tent-card.theme-dark .stand-qr-box{background:#fff;}
.table-tent-card.theme-dark .stand-whatsapp-pill{background:rgba(37,211,102,.12);color:#86efac;border:1px solid rgba(37,211,102,.25);}
.table-tent-card.theme-dark .stand-footer{color:#64748b;border-top-color:rgba(255,255,255,.08);}
/* Light */
.table-tent-card.theme-light{background:#fff!important;border:2px solid #0f172a;color:#0f172a;}
.table-tent-card.theme-light .stand-brand-name{color:#0f172a;}
.table-tent-card.theme-light .stand-headline{color:#0f172a;}
.table-tent-card.theme-light .stand-subtext{color:#475569;}
.table-tent-card.theme-light .stand-badge{background:#e2e8f0;color:#0f172a;}
.table-tent-card.theme-light .stand-stars-banner{background:#f8fafc;border:1px solid #e2e8f0;color:#0f172a;}
.table-tent-card.theme-light .stand-qr-box{background:#f8fafc;border:2px solid #e2e8f0;}
.table-tent-card.theme-light .stand-step{background:#f1f5f9;color:#334155;}
.table-tent-card.theme-light .stand-step-num{background:#0f172a;color:#fff;}
.table-tent-card.theme-light .stand-whatsapp-pill{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.table-tent-card.theme-light .stand-footer{color:#64748b;border-top-color:#e2e8f0;}
/* Elements */
.stand-fold-guide{font-size:9px;text-align:center;color:#64748b;letter-spacing:1px;text-transform:uppercase;margin-bottom:16px;padding-bottom:8px;border-bottom:1px dashed #cbd5e1;}
.stand-header{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:16px;}
.stand-logo{width:44px;height:44px;border-radius:12px;object-fit:cover;}
.stand-avatar{width:44px;height:44px;border-radius:12px;background:#c2f542;color:#091a27;font-size:16px;font-weight:800;display:flex;align-items:center;justify-content:center;}
.stand-brand-meta{text-align:left;}
.stand-brand-name{margin:0;font-size:17px;font-weight:800;line-height:1.2;}
.stand-badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:rgba(194,245,66,.18);color:#c2f542;margin-top:3px;}
.stand-stars-banner{border-radius:12px;padding:8px 14px;display:inline-flex;align-items:center;gap:8px;margin-bottom:18px;}
.stand-stars{color:#fbbf24;font-size:16px;letter-spacing:1px;}
.stand-score{font-weight:800;font-size:14px;}
.stand-review-count{font-size:11.5px;opacity:.8;}
.stand-cta-box{margin-bottom:20px;}
.stand-headline{margin:0 0 6px;font-size:20px;font-weight:800;letter-spacing:-.5px;}
.stand-subtext{margin:0;font-size:12.5px;line-height:1.4;}
.stand-qr-box{box-sizing:border-box;width:232px;border-radius:16px;padding:16px;display:inline-flex;flex-direction:column;align-items:center;margin-bottom:18px;}
.stand-qr-img{width:200px;height:200px;display:block;margin:0 auto;}
.stand-qr-scan-badge{margin-top:8px;background:#0f172a;color:#c2f542;padding:4px 10px;border-radius:99px;font-size:10px;font-weight:800;letter-spacing:1px;display:inline-block;}
.stand-steps-row{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:16px;font-size:11px;font-weight:700;}
.stand-step{background:rgba(255,255,255,.06);padding:5px 10px;border-radius:99px;display:flex;align-items:center;gap:5px;}
.stand-step-num{width:15px;height:15px;border-radius:50%;background:#c2f542;color:#091a27;font-size:10px;display:flex;align-items:center;justify-content:center;font-weight:900;}
.stand-step-arrow{opacity:.4;font-size:12px;}
.stand-whatsapp-pill{font-size:11px;line-height:1.4;padding:8px 12px;border-radius:10px;display:flex;align-items:center;gap:8px;text-align:left;margin-bottom:16px;}
.stand-whatsapp-pill svg{width:16px;height:16px;fill:currentColor;flex-shrink:0;}
.stand-footer{font-size:10px;padding-top:12px;border-top:1px solid #e2e8f0;display:flex;flex-direction:column;gap:3px;}
.stand-url-hint{font-family:monospace;font-size:9px;opacity:.7;word-break:break-all;}
</style>
</head>
<body>

<!-- Toolbar -->
<div id="print-toolbar">
    <h1>🖨 Print Preview &mdash; <?php echo htmlspecialchars($brand_name); ?> Stand Card</h1>
    <button class="tb-btn tb-dl" onclick="downloadPng()" id="dlBtn">⬇ Download PNG</button>
    <button class="tb-btn tb-print" onclick="window.print()">🖨 Print</button>
    <button class="tb-btn tb-close" onclick="window.close()">✕ Close</button>
</div>

<!-- Preview -->
<div id="preview-area">
<div id="card-wrap">
<div id="standCard" class="table-tent-card <?php echo $theme; ?>">

    <div class="stand-fold-guide">▲ Fold here for table tent standing display ▲</div>

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

    <div class="stand-stars-banner">
        <span class="stand-stars">★★★★★</span>
        <span class="stand-score"><?php echo number_format($avg_score, 1); ?> / 5.0</span>
        <span class="stand-review-count">&middot; <?php echo $total_reviews > 0 ? number_format($total_reviews) . ' reviews' : 'Rated on Optibiz'; ?></span>
    </div>

    <div class="stand-cta-box">
        <h3 class="stand-headline"><?php echo $headline; ?></h3>
        <p class="stand-subtext"><?php echo $subtext; ?></p>
    </div>

    <div class="stand-qr-box">
        <img id="qrImage" src="<?php echo htmlspecialchars($qr_api_url); ?>"
             alt="Scan to Review <?php echo htmlspecialchars($brand_name); ?>"
             class="stand-qr-img">
        <div class="stand-qr-scan-badge"><span>SCAN TO RATE</span></div>
    </div>

    <div class="stand-steps-row">
        <div class="stand-step"><span class="stand-step-num">1</span><span>Open Camera</span></div>
        <div class="stand-step-arrow">→</div>
        <div class="stand-step"><span class="stand-step-num">2</span><span>Point at QR</span></div>
        <div class="stand-step-arrow">→</div>
        <div class="stand-step"><span class="stand-step-num">3</span><span>Tap to Rate</span></div>
    </div>

    <?php if (!empty($wa_number)): ?>
    <div class="stand-whatsapp-pill">
        <svg viewBox="0 0 448 512" aria-hidden="true"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
        <span>Need immediate support or orders? Chat with us on WhatsApp: <strong><?php echo htmlspecialchars($wa_display); ?></strong></span>
    </div>
    <?php endif; ?>

    <div class="stand-footer">
        <span>Powered by <strong>Optibiz</strong> Verified Reviews</span>
        <span class="stand-url-hint"><?php echo htmlspecialchars($public_url); ?></span>
    </div>

</div><!-- #standCard -->
</div><!-- #card-wrap -->
</div><!-- #preview-area -->

<!-- Hidden QR generator -->
<div id="qrHiddenGen" style="position:absolute;left:-99999px;top:-99999px;opacity:0;pointer-events:none;z-index:-9999;" aria-hidden="true"></div>

<script src="<?php echo $BASE; ?>assets/js/html2canvas.min.js"></script>
<script>if(typeof html2canvas==='undefined'){document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"><\/script>');}</script>
<script src="<?php echo $BASE; ?>assets/js/qrcode.min.js"></script>
<script>if(typeof QRCode==='undefined'){document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>');}</script>

<script>
var qrTargetUrl = <?php echo json_encode($public_url); ?>;
var qrDataUrl = null;
var qrReadyPromise = null;

function initHiddenQr() {
    if (qrDataUrl) return Promise.resolve(qrDataUrl);
    if (qrReadyPromise) return qrReadyPromise;
    if (typeof QRCode === 'undefined') {
        return new Promise(function(resolve, reject) {
            setTimeout(function() { initHiddenQr().then(resolve, reject); }, 100);
        });
    }
    qrReadyPromise = new Promise(function(resolve, reject) {
        var gen = document.getElementById('qrHiddenGen');
        if (!gen) { reject(new Error('QR generator not found.')); return; }
        gen.innerHTML = '';
        new QRCode(gen, { text: qrTargetUrl, width: 500, height: 500, colorDark: '#000', colorLight: '#fff', correctLevel: QRCode.CorrectLevel.H });
        var attempts = 0;
        function finish(dataUrl) {
            qrDataUrl = dataUrl;
            gen.innerHTML = '';
            var visibleQr = document.getElementById('qrImage');
            if (visibleQr) visibleQr.src = dataUrl;
            resolve(dataUrl);
        }
        function extract() {
            if (qrDataUrl) { resolve(qrDataUrl); return; }
            var c = gen.querySelector('canvas');
            if (c) { try { finish(c.toDataURL('image/png')); return; } catch(e){} }
            var i = gen.querySelector('img');
            if (i && i.src && i.src.indexOf('data:') === 0) { finish(i.src); return; }
            attempts += 1;
            if (attempts < 20) { setTimeout(extract, 50); }
            else { qrReadyPromise = null; reject(new Error('QR code could not be generated.')); }
        }
        extract();
    });
    return qrReadyPromise;
}
document.addEventListener('DOMContentLoaded', initHiddenQr);
if (document.readyState !== 'loading') initHiddenQr();

function prepareQrForCapture() {
    return initHiddenQr().then(function(dataUrl) {
        var visibleQr = document.getElementById('qrImage');
        if (!visibleQr) throw new Error('Visible QR image not found.');
        if (visibleQr.complete && visibleQr.naturalWidth > 0 && visibleQr.src === dataUrl) return;
        return new Promise(function(resolve, reject) {
            visibleQr.onload = function() { resolve(); };
            visibleQr.onerror = function() { reject(new Error('QR image could not load.')); };
            visibleQr.src = dataUrl;
        });
    });
}

function downloadPng() {
    var card  = document.getElementById('standCard');
    var btn   = document.getElementById('dlBtn');
    if (!card) return;
    var orig = btn.innerHTML;
    btn.innerHTML = '⏳ Capturing…';
    btn.disabled = true;
    prepareQrForCapture()
        .then(function() { return html2canvas(card, { scale: 2, useCORS: false, allowTaint: false, backgroundColor: null, logging: false }); })
        .then(function(canvas) {
            var a = document.createElement('a');
            a.download = 'stand-card-<?php echo $brand_slug; ?>.png';
            a.href = canvas.toDataURL('image/png');
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        })
        .catch(function(e) { console.error(e); alert('Download failed.'); })
        .finally(function() { btn.innerHTML = orig; btn.disabled = false; });
}
</script>
</body>
</html>
