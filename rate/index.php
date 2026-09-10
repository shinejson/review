<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// Support ?tenant=ID routing — resolves to that tenant's first registered company.
// Also supports legacy ?company=ID routing.
if (isset($_GET['tenant']) && (int)$_GET['tenant'] > 0) {
    $tenant_lookup_id = (int)$_GET['tenant'];
    $t_resolve = $conn->prepare("SELECT id FROM customers WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
    $t_resolve->bind_param("i", $tenant_lookup_id);
    $t_resolve->execute();
    $t_row = $t_resolve->get_result()->fetch_assoc();
    $t_resolve->close();

    if ($t_row) {
        // Redirect to company-specific URL for cleaner routing
        $redirect_url = '?company=' . (int)$t_row['id'];
        if (!empty($_GET['tab'])) $redirect_url .= '&tab=' . urlencode($_GET['tab']);
        if (!empty($_GET['name'])) $redirect_url .= '&name=' . urlencode($_GET['name']);
        if (!empty($_GET['email'])) $redirect_url .= '&email=' . urlencode($_GET['email']);
        if (!empty($_GET['ref_code'])) $redirect_url .= '&ref_code=' . urlencode($_GET['ref_code']);
        if (!empty($_GET['ref'])) $redirect_url .= '&ref=' . urlencode($_GET['ref']);
        header('Location: ' . $redirect_url, true, 302);
        exit;
    }

    // Tenant has no companies yet — show a friendly holding page
    $t_info_stmt = $conn->prepare("SELECT company_name FROM tenants WHERE id = ? LIMIT 1");
    $t_info_stmt->bind_param("i", $tenant_lookup_id);
    $t_info_stmt->execute();
    $t_info = $t_info_stmt->get_result()->fetch_assoc();
    $t_info_stmt->close();
    $tenant_name = htmlspecialchars($t_info['company_name'] ?? 'This workspace');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $tenant_name; ?> — Rating Portal</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;min-height:100vh;display:grid;place-items:center;background:#0a1926;color:#e2e8f0;}
  .card{background:#0f2438;border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:48px;text-align:center;max-width:480px;width:90%;}
  .icon{font-size:48px;margin-bottom:20px;}
  h1{font-size:22px;font-weight:800;color:#f1f5f9;margin-bottom:10px;}
  p{font-size:14px;color:#94a3b8;line-height:1.6;}
  .badge{display:inline-block;margin-top:18px;padding:6px 16px;border-radius:99px;background:rgba(194,245,66,.15);color:#c2f542;font-size:12px;font-weight:700;}
</style>
</head>
<body>
<div class="card">
  <div class="icon">⭐</div>
  <h1><?php echo $tenant_name; ?></h1>
  <p>This workspace's public rating portal is not available yet.<br>The tenant needs to register their company profile first.</p>
  <span class="badge">Coming soon</span>
</div>
</body>
</html><?php
    exit;
}

$company_id = isset($_GET['company']) ? (int)$_GET['company'] : 0;

if ($company_id <= 0) {
    die('Please provide a valid company or tenant ID.');
}

// Ensure location and social columns exist in customers table
if (function_exists('ensureLocationAndSocialColumns')) {
    ensureLocationAndSocialColumns($conn);
}

$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();

if (!$company) {
    die('Company not found.');
}

// Pre-filled customer parameters (from WhatsApp / email invites)
$prefill_name     = trim((string)($_GET['name'] ?? ''));
$prefill_email    = trim((string)($_GET['email'] ?? ''));
$prefill_ref_code = trim((string)($_GET['ref_code'] ?? ''));
$prefill_channel  = trim((string)($_GET['ref'] ?? ''));

// Get average rating and total counts
$avg_rating    = getAverageRating($company_id, $conn);
$total_ratings = getRatingCount($company_id, $conn);

// Get rating distribution (5 to 1 stars)
$rating_dist = [];
for ($i = 5; $i >= 1; $i--) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ratings WHERE company_id = ? AND rating = ?");
    $stmt->bind_param("ii", $company_id, $i);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $count = (int)$result['count'];
    $percentage = $total_ratings > 0 ? round(($count / $total_ratings) * 100) : 0;
    $rating_dist[$i] = ['count' => $count, 'percentage' => $percentage];
}

// Fetch ALL active rating questions created by admin/tenant
$tenant_id = (int)($company['tenant_id'] ?? 0);
$questions = [];
if ($tenant_id > 0) {
    $q_stmt = $conn->prepare("SELECT * FROM rating_questions WHERE tenant_id = ? AND is_active = 1 ORDER BY id ASC");
    $q_stmt->bind_param("i", $tenant_id);
    $q_stmt->execute();
    $q_res = $q_stmt->get_result();
    while ($qr = $q_res->fetch_assoc()) {
        $qid = (int)$qr['id'];

        // Stats for this specific question
        $stat_stmt = $conn->prepare("SELECT COUNT(*) as total_answers, AVG(rating) as avg_score FROM ratings WHERE company_id = ? AND question_id = ?");
        $stat_stmt->bind_param("ii", $company_id, $qid);
        $stat_stmt->execute();
        $stat = $stat_stmt->get_result()->fetch_assoc();
        $qr['total_answers'] = (int)($stat['total_answers'] ?? 0);
        $qr['avg_score']     = $qr['total_answers'] > 0 ? round((float)$stat['avg_score'], 1) : 0.0;

        // Recent customer answers for this question
        $ans_stmt = $conn->prepare("SELECT * FROM ratings WHERE company_id = ? AND question_id = ? ORDER BY created_at DESC LIMIT 5");
        $ans_stmt->bind_param("ii", $company_id, $qid);
        $ans_stmt->execute();
        $qr['answers'] = $ans_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $questions[] = $qr;
    }
}

// Fetch the company's active services with their rating stats (star avg + review count)
$services = [];
if ($tenant_id > 0) {
    $svc_stmt = $conn->prepare("
        SELECT s.*,
            (SELECT COUNT(*) FROM ratings r WHERE r.service_id = s.id AND r.company_id = ?) AS review_count,
            (SELECT COALESCE(AVG(r.rating), 0) FROM ratings r WHERE r.service_id = s.id AND r.company_id = ?) AS avg_score
          FROM services s
         WHERE s.tenant_id = ? AND s.status = 'active'
         ORDER BY s.sort_order ASC, s.id ASC
    ");
    $svc_stmt->bind_param("iii", $company_id, $company_id, $tenant_id);
    $svc_stmt->execute();
    $services = $svc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Fetch GENERAL customer reviews (no question) - shown in the "Reviews" tab
$gr_stmt = $conn->prepare("SELECT * FROM ratings WHERE company_id = ? AND (question_id IS NULL OR question_id = 0) ORDER BY created_at DESC");
$gr_stmt->bind_param("i", $company_id);
$gr_stmt->execute();
$general_reviews = $gr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch RESPONSES to the specific review items - shown in the "Review Responses" tab
$qr_stmt = $conn->prepare("SELECT r.*, rq.question_text FROM ratings r LEFT JOIN rating_questions rq ON r.question_id = rq.id WHERE r.company_id = ? AND r.question_id IS NOT NULL AND r.question_id > 0 ORDER BY r.created_at DESC");
$qr_stmt->bind_param("i", $company_id);
$qr_stmt->execute();
$question_responses = $qr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch PUBLIC Community Q&A (customer questions & official management answers)
$community_qa = getCommunityQuestions($company_id, $conn, true);

// Fetch tenant (workspace owner) branding - logo & name shown to customers
$tenant_info = null;
if ($tenant_id > 0) {
    $t_stmt = $conn->prepare("SELECT company_name, logo FROM tenants WHERE id = ? LIMIT 1");
    $t_stmt->bind_param("i", $tenant_id);
    $t_stmt->execute();
    $tenant_info = $t_stmt->get_result()->fetch_assoc();
}
$brand_name   = !empty($tenant_info['company_name']) ? $tenant_info['company_name'] : $company['company_name'];
$brand_logo   = $tenant_info['logo'] ?? '';
$brand_initials = strtoupper(substr($brand_name, 0, 2));

// WhatsApp click-to-chat: fallback to company phone or tenant phone if whatsapp_number is not explicitly specified
$wa_target_number = !empty($company['whatsapp_number']) ? $company['whatsapp_number'] : (!empty($company['phone']) ? $company['phone'] : ($tenant_info['phone'] ?? ''));
if (!empty($wa_target_number)) {
    $company['whatsapp_number'] = $wa_target_number;
}
$whatsapp_url = whatsappChatUrl($company['whatsapp_number'] ?? '', $brand_name);

// Company Website & Location / Social Media Configuration
$company_website = trim((string)($company['website'] ?? ''));
if ($company_website !== '' && !preg_match('~^https?://~i', $company_website)) {
    $company_website = 'https://' . $company_website;
}
$google_map_url       = !empty($company['google_map_url']) ? cleanMapUrl($company['google_map_url']) : '';
$map_embed_src        = !empty($company['map_embed_code']) ? extractMapEmbedSrc($company['map_embed_code']) : '';
$location_description = trim((string)($company['location_description'] ?? ''));
$social_links         = function_exists('getCompanySocialLinks') ? getCompanySocialLinks($company) : [];

// Ad Funnel Tracking & Pixels Configuration
require_once dirname(__DIR__) . '/includes/ad_conversions.php';
$ad_config = function_exists('getTenantAdConfig') ? getTenantAdConfig($conn, $tenant_id, $company_id) : null;
$meta_pixel_id        = !empty($ad_config['meta_pixel_id']) ? htmlspecialchars($ad_config['meta_pixel_id']) : '';
$google_conversion_id = !empty($ad_config['google_ads_conversion_id']) ? htmlspecialchars($ad_config['google_ads_conversion_id']) : '';
$tiktok_pixel_id      = !empty($ad_config['tiktok_pixel_id']) ? htmlspecialchars($ad_config['tiktok_pixel_id']) : '';

$pageTitle = 'Rate ' . htmlspecialchars($brand_name);

// ============================================================
// Google AggregateRating JSON-LD Structured Data for Organic Search Stars
// ============================================================
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$app_web_root = '';
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $doc_root = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']);
    $app_dir  = str_replace('\\', '/', dirname(__DIR__));
    if (strpos($app_dir, $doc_root) === 0) {
        $app_web_root = substr($app_dir, strlen($doc_root));
    }
} elseif (!empty($_SERVER['SCRIPT_NAME'])) {
    $app_web_root = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
}
$app_web_root = '/' . trim($app_web_root, '/');
if ($app_web_root === '/') $app_web_root = '';

$canonical_url   = $__scheme . '://' . $__host . (!empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ($app_web_root . '/rate/index.php?company=' . $company_id));
$brand_logo_full = !empty($brand_logo) ? ($__scheme . '://' . $__host . $app_web_root . '/' . ltrim($brand_logo, '/')) : '';
$meta_desc       = "Read verified customer reviews and ratings for " . $brand_name . ". Overall score of " . number_format($avg_rating, 1) . "/5.0 based on " . number_format($total_ratings) . " customer review(s).";

$schema_json_ld = [
    '@context'    => 'https://schema.org',
    '@type'       => 'LocalBusiness',
    'name'        => $brand_name,
    'url'         => $canonical_url,
    'description' => 'Customer reviews and verified rating profile for ' . $brand_name,
];

if (!empty($brand_logo_full)) {
    $schema_json_ld['image'] = $brand_logo_full;
}

if (!empty($company['phone'])) {
    $schema_json_ld['telephone'] = $company['phone'];
}
if (!empty($company['email'])) {
    $schema_json_ld['email'] = $company['email'];
}
if (!empty($company['address'])) {
    $schema_json_ld['address'] = [
        '@type'         => 'PostalAddress',
        'streetAddress' => $company['address']
    ];
}
if (!empty($google_map_url)) {
    $schema_json_ld['hasMap'] = $google_map_url;
}
$schema_same_as = [];
foreach ($social_links as $s_item) {
    if (!empty($s_item['url'])) {
        $schema_same_as[] = $s_item['url'];
    }
}
if (!empty($company_website)) {
    $schema_same_as[] = $company_website;
}
if (!empty($schema_same_as)) {
    $schema_json_ld['sameAs'] = array_values(array_unique($schema_same_as));
}

// Emit aggregateRating when ratings exist
if ($total_ratings > 0) {
    $schema_json_ld['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => number_format($avg_rating, 1, '.', ''),
        'bestRating'  => '5',
        'worstRating' => '1',
        'ratingCount' => (int)$total_ratings,
        'reviewCount' => (int)$total_ratings
    ];

    // Include top reviews in JSON-LD for rich snippets
    $schema_reviews = [];
    $all_reviews = array_merge($general_reviews, $question_responses);
    usort($all_reviews, function($a, $b) {
        $vA = (int)($a['is_verified'] ?? 0);
        $vB = (int)($b['is_verified'] ?? 0);
        if ($vA !== $vB) return $vB - $vA;
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    $count = 0;
    foreach ($all_reviews as $rev) {
        if (empty($rev['comment']) || $count >= 5) continue;
        $schema_reviews[] = [
            '@type'        => 'Review',
            'author'       => [
                '@type' => 'Person',
                'name'  => !empty($rev['customer_name']) ? $rev['customer_name'] : 'Verified Customer'
            ],
            'datePublished' => date('Y-m-d', strtotime($rev['created_at'])),
            'reviewBody'    => $rev['comment'],
            'reviewRating'  => [
                '@type'       => 'Rating',
                'ratingValue' => (string)(int)$rev['rating'],
                'bestRating'  => '5',
                'worstRating' => '1'
            ]
        ];
        $count++;
    }

    if (!empty($schema_reviews)) {
        $schema_json_ld['review'] = $schema_reviews;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Verified Ratings &amp; Reviews</title>
    <meta name="description" content="<?php echo htmlspecialchars($meta_desc); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($canonical_url); ?>">

    <!-- Open Graph for Social Previews -->
    <meta property="og:title" content="<?php echo $pageTitle; ?> - Verified Ratings &amp; Reviews">
    <meta property="og:description" content="<?php echo htmlspecialchars($meta_desc); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonical_url); ?>">
    <?php if (!empty($brand_logo_full)): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($brand_logo_full); ?>">
    <?php endif; ?>

    <!-- Google AggregateRating JSON-LD for Organic Search Stars -->
    <script type="application/ld+json">
<?php echo json_encode($schema_json_ld, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
    </script>

    <?php if (!empty($meta_pixel_id)): ?>
    <!-- Meta Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '<?php echo $meta_pixel_id; ?>');
    fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=<?php echo $meta_pixel_id; ?>&ev=PageView&noscript=1"
    /></noscript>
    <!-- End Meta Pixel Code -->
    <?php endif; ?>

    <?php if (!empty($google_conversion_id)): ?>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $google_conversion_id; ?>"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', '<?php echo $google_conversion_id; ?>');
    </script>
    <!-- End Google tag -->
    <?php endif; ?>

    <?php if (!empty($tiktok_pixel_id)): ?>
    <!-- TikTok Pixel Code -->
    <script>
    !function (w, d, t) {
      w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndVerify=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndVerify(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndVerify(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};
      ttq.load('<?php echo $tiktok_pixel_id; ?>');
      ttq.page();
    }(window, document, 'ttq');
    </script>
    <!-- End TikTok Pixel Code -->
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            padding: 30px 16px 60px;
        }
        .rt-container {
            max-width: 1160px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            padding: 44px;
            box-shadow: 0 10px 40px rgba(15,23,42,0.06);
            border: 1px solid #e2e8f0;
        }
        .rt-company-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 24px;
            margin-bottom: 36px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .rt-company-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .rt-brand-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .rt-brand-logo {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            object-fit: cover;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
            background: #ffffff;
        }
        .rt-brand-fallback {
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            font-weight: 800;
            font-size: 18px;
            letter-spacing: 0.5px;
            border: none;
        }
        .rt-badge {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 99px;
            background: #dcfce7;
            color: #15803d;
            font-weight: 700;
        }
        /* WhatsApp click-to-chat */
        .rt-header-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 12px;
        }
        .rt-whatsapp-btn {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            background: #25D366;
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            padding: 12px 22px;
            border-radius: 99px;
            text-decoration: none;
            box-shadow: 0 8px 22px rgba(37, 211, 102, 0.28);
            transition: transform .2s ease, background .2s ease, box-shadow .2s ease;
            white-space: nowrap;
        }
        .rt-whatsapp-btn:hover {
            background: #1fb457;
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(37, 211, 102, 0.36);
        }
        .rt-whatsapp-btn svg { width: 19px; height: 19px; fill: currentColor; }
        .rt-whatsapp-note {
            font-size: 12px;
            color: #64748b;
        }
        /* Website / Google Store link buttons (configured by admin) */
        .rt-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #0f172a;
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 700;
            padding: 11px 20px;
            border-radius: 99px;
            text-decoration: none;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.18);
            transition: transform .2s ease, background .2s ease, box-shadow .2s ease;
            white-space: nowrap;
        }
        .rt-link-btn:hover {
            background: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.26);
        }
        .rt-link-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
        .rt-link-btn.rt-gstore-btn {
            background: linear-gradient(135deg, #4285F4, #34A853);
            box-shadow: 0 6px 18px rgba(66, 133, 244, 0.28);
        }
        .rt-link-btn.rt-gstore-btn:hover {
            background: linear-gradient(135deg, #3b78e7, #2f9a4b);
            box-shadow: 0 10px 24px rgba(66, 133, 244, 0.36);
        }
        /* Public rating page footer */
        .rt-footer {
            margin-top: 56px;
            border-top: 1px solid #e2e8f0;
            padding-top: 36px;
            padding-bottom: 12px;
        }
        .rt-footer-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1.3fr;
            gap: 32px;
            align-items: flex-start;
        }
        @media (max-width: 960px) {
            .rt-footer-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 640px) {
            .rt-footer-grid {
                grid-template-columns: 1fr;
                gap: 28px;
            }
        }
        .rt-footer-col {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .rt-footer-col-title {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 7px;
            margin: 0 0 2px 0;
        }
        .rt-footer-col-title svg {
            width: 15px;
            height: 15px;
            stroke: #10b981;
            fill: none;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .rt-footer-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 2px;
        }
        .rt-footer-brand img {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid #e2e8f0;
        }
        .rt-footer-brand .rt-footer-fallback {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            font-weight: 800;
            font-size: 16px;
        }
        .rt-footer-brand strong {
            font-size: 16px;
            color: #0f172a;
            display: block;
            line-height: 1.25;
        }
        .rt-footer-brand small {
            font-size: 12px;
            color: #64748b;
        }
        .rt-footer-desc {
            font-size: 13px;
            color: #475569;
            line-height: 1.6;
            margin: 0;
        }
        .rt-footer-web-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 7px 14px;
            border-radius: 8px;
            text-decoration: none;
            width: fit-content;
            transition: all 0.2s ease;
        }
        .rt-footer-web-btn:hover {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
            transform: translateY(-1px);
        }
        .rt-footer-web-btn svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
        }
        .rt-social-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 4px;
        }
        .rt-social-btn {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .rt-social-btn svg {
            width: 16px;
            height: 16px;
            fill: currentColor;
            transition: transform 0.2s ease;
        }
        .rt-social-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .rt-social-btn:hover svg {
            transform: scale(1.1);
        }
        .rt-social-btn.social-facebook:hover { background: #1877f2; color: #fff; border-color: #1877f2; }
        .rt-social-btn.social-instagram:hover { background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%); color: #fff; border-color: #d6249f; }
        .rt-social-btn.social-twitter:hover { background: #0f1419; color: #fff; border-color: #0f1419; }
        .rt-social-btn.social-linkedin:hover { background: #0a66c2; color: #fff; border-color: #0a66c2; }
        .rt-social-btn.social-tiktok:hover { background: #000000; color: #fff; border-color: #000000; }
        .rt-social-btn.social-youtube:hover { background: #ff0000; color: #fff; border-color: #ff0000; }

        .rt-footer-contact {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
            font-size: 13px;
            color: #475569;
            margin: 0;
            padding: 0;
        }
        .rt-footer-contact li {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            line-height: 1.45;
        }
        .rt-footer-contact svg {
            width: 15px;
            height: 15px;
            flex-shrink: 0;
            margin-top: 2px;
            stroke: #10b981;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .rt-footer-contact a {
            color: #0f172a;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .rt-footer-contact a:hover { color: #059669; }

        /* Location card & map directions */
        .rt-location-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .rt-location-address {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 12.5px;
            color: #334155;
            font-weight: 500;
            line-height: 1.45;
        }
        .rt-location-address svg {
            width: 15px;
            height: 15px;
            stroke: #ef4444;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .rt-location-guide {
            font-size: 12px;
            color: #475569;
            line-height: 1.5;
            background: #f8fafc;
            border-left: 3px solid #10b981;
            padding: 8px 10px;
            border-radius: 0 6px 6px 0;
        }
        .rt-map-direction-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: #ffffff;
            font-size: 12.5px;
            font-weight: 700;
            padding: 9px 14px;
            border-radius: 8px;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.16);
            transition: all 0.2s ease;
        }
        .rt-map-direction-btn:hover {
            background: #2563eb;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.32);
        }
        .rt-map-direction-btn svg {
            width: 15px;
            height: 15px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .rt-map-embed-frame {
            width: 100%;
            height: 140px;
            border: 0;
            border-radius: 8px;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06);
            display: block;
        }

        .rt-footer-bottom {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 12px;
            color: #94a3b8;
        }
        .rt-footer-bottom a { color: #059669; font-weight: 700; text-decoration: none; }
        /* Review-level WhatsApp inquiry & Action buttons */
        .rt-review-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .rt-review-wa-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: #15803d;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 5px 12px;
            border-radius: 99px;
            text-decoration: none;
            transition: all .2s ease;
        }
        .rt-review-wa-btn:hover {
            background: #25D366;
            color: #ffffff;
            border-color: #25D366;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.28);
        }
        .rt-review-wa-btn svg {
            width: 13px;
            height: 13px;
            fill: currentColor;
        }
        .rt-review-share-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 5px 12px;
            border-radius: 99px;
            cursor: pointer;
            transition: all .2s ease;
            font-family: inherit;
        }
        .rt-review-share-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
            transform: translateY(-1px);
        }
        .rt-review-card-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
            color: #4338ca;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            padding: 5px 12px;
            border-radius: 99px;
            text-decoration: none;
            transition: all .2s ease;
            cursor: pointer;
        }
        .rt-review-card-btn:hover {
            background: #4f46e5;
            color: #ffffff;
            border-color: #4f46e5;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .rt-chip-filter {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 99px;
            cursor: pointer;
            transition: all .2s ease;
            font-family: inherit;
        }
        .rt-chip-filter:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .rt-chip-filter.is-active {
            background: #059669;
            color: #ffffff;
            border-color: #059669;
        }
        .rt-rating-sentiment-hint {
            font-size: 12.5px;
            font-weight: 600;
            margin-left: 10px;
            transition: all .2s ease;
        }

        /* Verified Customer Badge & Verification Form Box */
        .rt-verified-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
            letter-spacing: 0.2px;
        }
        .rt-verified-badge svg {
            width: 12px;
            height: 12px;
            fill: #16a34a;
            flex-shrink: 0;
        }
        .rt-verification-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 16px;
        }
        .rt-sub-label {
            font-size: 11.5px;
            font-weight: 600;
            color: #475569;
            display: block;
            margin-bottom: 4px;
        }

        /* Public Community Q&A Tab & Cards */
        .rt-qa-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 18px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
            transition: all .2s ease;
        }
        .rt-qa-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
        }
        .rt-qa-pinned {
            border-color: #fde68a !important;
            background: linear-gradient(180deg, #fffdf5 0%, #ffffff 90px);
        }
        .rt-qa-pinned-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
        }
        .rt-qa-answered-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
        }
        .rt-qa-pending-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 99px;
        }
        .rt-qa-qtext {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.45;
            margin: 10px 0 14px;
        }
        .rt-qa-answer-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #059669;
            border-radius: 0 12px 12px 0;
            padding: 16px 18px;
            margin-top: 14px;
        }
        .rt-qa-answer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: #065f46;
            flex-wrap: wrap;
        }
        .rt-qa-answer-body {
            font-size: 14px;
            color: #334155;
            line-height: 1.6;
        }
        .rt-qa-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }
        .rt-qa-search-box {
            flex: 1;
            min-width: 240px;
            position: relative;
        }
        .rt-qa-search-box input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
            outline: none;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .rt-qa-search-box input:focus {
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12);
        }
        .rt-qa-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
            width: 16px;
            height: 16px;
        }
        .rt-qa-btn-ask {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #0f172a;
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 700;
            padding: 10px 18px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all .2s ease;
        }
        .rt-qa-btn-ask:hover {
            background: #1e293b;
            transform: translateY(-1px);
        }
        .rt-qa-ask-panel {
            background: #ffffff;
            border: 1.5px dashed #94a3b8;
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 24px;
            display: none;
        }
        .rt-qa-vote-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 6px 13px;
            border-radius: 99px;
            cursor: pointer;
            transition: all .15s ease;
        }
        .rt-qa-vote-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .rt-qa-vote-btn.is-voted {
            background: #ecfdf5;
            color: #059669;
            border-color: #a7f3d0;
            pointer-events: none;
        }
        .rt-qa-footer-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
        }

        /* Floating Sticky WhatsApp Button */
        .rt-floating-wa {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #25D366;
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            padding: 12px 20px;
            border-radius: 99px;
            text-decoration: none;
            box-shadow: 0 10px 30px rgba(37, 211, 102, 0.45);
            transition: transform .25s ease, box-shadow .25s ease, background .2s ease;
        }
        .rt-floating-wa:hover {
            background: #1fb457;
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 15px 35px rgba(37, 211, 102, 0.55);
        }
        .rt-floating-wa svg {
            width: 22px;
            height: 22px;
            fill: currentColor;
            flex-shrink: 0;
        }
        @media (max-width: 640px) {
            .rt-header-actions { align-items: stretch; }
            .rt-whatsapp-btn { justify-content: center; }
            .rt-whatsapp-note { text-align: center; }
            .rt-floating-wa span { display: none; }
            .rt-floating-wa {
                padding: 14px;
                border-radius: 50%;
                bottom: 18px;
                right: 18px;
            }
        }
        .rt-rating-grid {
            display: grid;
            grid-template-columns: 1fr 1.25fr;
            gap: 44px;
            margin-bottom: 48px;
        }
        .rt-average-section h2, .rt-review-section h2 {
            font-size: 22px;
            color: #0f172a;
            margin-bottom: 8px;
            font-weight: 800;
        }
        .rt-subtext {
            font-size: 13.5px;
            color: #64748b;
            margin-bottom: 20px;
        }
        .rt-avg-score-box {
            display: flex;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 8px;
        }
        .rt-avg-score {
            font-size: 54px;
            color: #0f172a;
            font-weight: 800;
            line-height: 1;
        }
        .rt-avg-stars {
            font-size: 24px;
            color: #f59e0b;
        }
        .rt-total-counter {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 28px;
            font-weight: 600;
        }
        .rt-rating-bar-item {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            gap: 12px;
        }
        .rt-rating-label {
            font-weight: 700;
            color: #334155;
            width: 32px;
            font-size: 13px;
        }
        .rt-bar-container {
            flex: 1;
            height: 10px;
            background: #f1f5f9;
            border-radius: 10px;
            overflow: hidden;
        }
        .rt-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            border-radius: 10px;
            transition: width 0.4s ease;
        }
        .rt-rating-percentage {
            font-weight: 700;
            color: #64748b;
            min-width: 44px;
            text-align: right;
            font-size: 13px;
        }
        .rt-review-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 30px;
        }
        .rt-review-form-label {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
            display: block;
        }
        .rt-star-rating-input {
            display: flex;
            gap: 6px;
            margin-bottom: 20px;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }
                .rt-star-rating-input input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .rt-star-rating-input label {
            font-size: 30px;
            color: #cbd5e1;
            cursor: pointer;
            transition: color 0.15s ease, transform 0.15s ease;
        }
        .rt-star-rating-input label:hover {
            transform: scale(1.15);
        }
        .rt-star-rating-input input:checked ~ label,
        .rt-star-rating-input label:hover,
        .rt-star-rating-input label:hover ~ label {
            color: #f59e0b;
        }
        .rt-input-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        .rt-form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: #ffffff;
            color: #0f172a;
            transition: border-color 0.2s;
        }
        .rt-form-input:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,0.12);
        }
        .rt-form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 95px;
            margin-bottom: 20px;
            background: #ffffff;
            color: #0f172a;
            transition: border-color 0.2s;
        }
        .rt-form-textarea:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,0.12);
        }
        .rt-submit-btn {
            background: #10b981;
            color: white;
            padding: 13px 28px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
        }
        .rt-submit-btn:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(16,185,129,0.25);
        }
        /* Divided Section Navigation Tabs */
        .rt-divider-section {
            margin-top: 56px;
            padding-top: 48px;
            border-top: 1px solid #e2e8f0;
        }
        .rt-tab-nav {
            display: flex;
            gap: 12px;
            margin-bottom: 28px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 12px;
            flex-wrap: wrap;
        }
        .rt-tab-btn {
            background: transparent;
            border: none;
            padding: 10px 22px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .rt-tab-btn:hover {
            color: #0f172a;
            background: #f1f5f9;
        }
        .rt-tab-btn.is-active {
            color: #065f46;
            background: #d1fae5;
        }
        .rt-tab-panel {
            display: none;
            animation: rtFadeIn .25s ease-out;
        }
        .rt-tab-panel.is-active {
            display: block;
        }
        @keyframes rtFadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* Question Cards Styling */
        .rt-question-list {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .rt-question-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 14px 16px;
            box-shadow: 0 2px 10px rgba(15,23,42,0.03);
            transition: border-color 0.2s;
        }
        .rt-question-card:hover {
            border-color: #10b981;
        }
        .rt-question-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .rt-question-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.35;
        }
        .rt-question-stats {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .rt-chip {
            font-size: 12px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 6px;
            background: #fef3c7;
            color: #b45309;
        }
        .rt-chip-gray {
            font-size: 12px;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #475569;
        }
        /* Inline Question Answer Form */
        .rt-question-form {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 12px 14px;
            margin-top: 10px;
            margin-bottom: 10px;
        }
        /* Google-style review list (Reviews tab) */
        .rt-greview-list { display: flex; flex-direction: column; }
        .rt-greview { padding: 18px 4px; border-bottom: 1px solid #f1f5f9; }
        .rt-greview:last-child { border-bottom: none; }
        .rt-greview-top { display: flex; align-items: flex-start; gap: 12px; }
        .rt-greview-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff; display: grid; place-items: center;
            font-weight: 800; font-size: 16px; flex-shrink: 0;
        }
        .rt-greview-name { font-weight: 700; font-size: 14.5px; color: #0f172a; }
        .rt-greview-stars { font-size: 14px; color: #f59e0b; letter-spacing: 1.5px; margin-top: 2px; }
        .rt-greview-time { color: #64748b; font-size: 12.5px; }
        .rt-greview-text { color: #334155; font-size: 14px; line-height: 1.6; margin: 6px 0 0; }
        .rt-greview-norv { color: #94a3b8; font-style: italic; }
        .rt-greview-q { color: #64748b; font-size: 12px; margin-top: 8px; }
        .rt-greview .rt-reply-box { margin-top: 12px; }
        .rt-q-star-picker {
            display: flex;
            gap: 6px;
            flex-direction: row-reverse;
            justify-content: flex-start;
            margin-bottom: 12px;
        }
                .rt-q-star-picker input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .rt-q-star-picker label {
            font-size: 24px;
            color: #cbd5e1;
            cursor: pointer;
            transition: color 0.15s;
        }
        .rt-q-star-picker input:checked ~ label,
        .rt-q-star-picker label:hover,
        .rt-q-star-picker label:hover ~ label {
            color: #f59e0b;
        }
        .rt-q-answers-list {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }
        .rt-q-answer-item {
            background: #ffffff;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 10px;
            font-size: 13.5px;
        }
        /* Customer Feedback Cards */
        .rt-feedback-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .rt-feedback-item {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            padding: 22px 24px;
            border-radius: 14px;
            transition: box-shadow 0.2s;
        }
        .rt-feedback-item:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
        }
        .rt-feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .rt-feedback-author {
            font-weight: 700;
            color: #0f172a;
            font-size: 15px;
        }
        .rt-feedback-date {
            color: #94a3b8;
            font-size: 13px;
        }
        .rt-feedback-stars {
            color: #f59e0b;
            font-size: 16px;
            margin-bottom: 10px;
        }
        .rt-feedback-text {
            color: #334155;
            line-height: 1.6;
            font-size: 14px;
        }
        .rt-reply-box {
            margin-top: 14px;
            padding: 12px 16px;
            border-radius: 8px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            font-size: 13px;
            color: #065f46;
        }
        .rt-empty {
            text-align: center;
            padding: 44px 20px;
            color: #94a3b8;
            font-size: 14px;
        }
        /* ===== Services Grid (company services with per-service reviews) ===== */
        .rt-services-section {
            margin-top: 44px;
            padding: 34px;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border: 1px solid #e2e8f0;
            border-radius: 20px;
        }
        .rt-services-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .rt-services-head h2 {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
        }
        .rt-services-head p {
            font-size: 13.5px;
            color: #64748b;
            margin: 4px 0 0;
        }
        .rt-services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 18px;
        }
        .rt-service-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 22px 18px;
            position: relative;
            display: flex;
            flex-direction: column;
            transition: all .25s ease;
        }
        .rt-service-card:hover {
            border-color: #10b981;
            box-shadow: 0 10px 30px rgba(5, 150, 105, 0.10);
            transform: translateY(-3px);
        }
        .rt-service-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            display: grid;
            place-items: center;
            font-size: 22px;
            margin-bottom: 14px;
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.22);
            flex-shrink: 0;
        }
        .rt-service-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
            margin-bottom: 6px;
        }
        .rt-service-desc {
            font-size: 12.5px;
            color: #64748b;
            line-height: 1.55;
            margin-bottom: 14px;
            flex: 1;
        }
        .rt-service-stars {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: #f59e0b;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .rt-service-stars b {
            color: #0f172a;
            font-size: 14px;
        }
        .rt-service-meta {
            font-size: 11.5px;
            color: #94a3b8;
            margin-bottom: 16px;
        }
        .rt-service-comment-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            width: 100%;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: all .2s ease;
        }
        .rt-service-comment-btn:hover {
            background: #d1fae5;
            border-color: #10b981;
            color: #065f46;
        }
        .rt-service-comment-btn svg { width: 15px; height: 15px; }
        .rt-service-review-form {
            display: none;
            margin-top: 14px;
            padding: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            animation: rtFadeIn .25s ease-out;
        }
        .rt-service-review-form.is-open { display: block; }
        .rt-service-review-form .rt-review-form-label { margin-bottom: 6px; }
        .rt-service-form-stars {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-start;
            gap: 6px;
            margin-bottom: 12px;
        }
        .rt-service-form-stars input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
        .rt-service-form-stars label {
            font-size: 22px;
            color: #cbd5e1;
            cursor: pointer;
            transition: color .15s;
        }
        .rt-service-form-stars input:checked ~ label,
        .rt-service-form-stars label:hover,
        .rt-service-form-stars label:hover ~ label { color: #f59e0b; }
        .rt-service-form-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .rt-service-form-actions .rt-input-email {
            flex: 1;
            min-width: 160px;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
        }
        .rt-service-submit {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            background: #10b981;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: all .2s ease;
        }
        .rt-service-submit:hover { background: #059669; }

        @media (max-width: 850px) {
            .rt-container { padding: 24px 18px; }
            .rt-rating-grid { grid-template-columns: 1fr; gap: 32px; }
            .rt-input-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="rt-container">

    <!-- Top Branding Header -->
    <header class="rt-company-header">
        <div class="rt-brand-wrap">
            <?php if (!empty($brand_logo)): ?>
                <img src="../<?php echo htmlspecialchars($brand_logo); ?>" alt="<?php echo htmlspecialchars($brand_name); ?> logo" class="rt-brand-logo">
            <?php else: ?>
                <div class="rt-brand-logo rt-brand-fallback"><?php echo htmlspecialchars($brand_initials); ?></div>
            <?php endif; ?>
            <div>
                <h1>
                    ★ Rate <?php echo htmlspecialchars($brand_name); ?>
                </h1>
                <p class="rt-subtext" style="margin-bottom:0;">
                    Official client review and rating portal &middot; <?php echo htmlspecialchars($company['category_name'] ?? 'Verified Business'); ?>
                </p>
            </div>
        </div>
        <div class="rt-header-actions">
            <span class="rt-badge">✓ Verified Rating Channel</span>
            <?php
            $company_website = trim((string)($company['website'] ?? ''));
            $company_gstore  = trim((string)($company['google_store_url'] ?? ''));
            ?>
            <?php if ($company_website !== ''): ?>
            <a class="rt-link-btn" href="<?php echo htmlspecialchars($company_website); ?>" target="_blank" rel="noopener noreferrer" title="Visit <?php echo htmlspecialchars($brand_name); ?> website">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                Visit Website
            </a>
            <?php endif; ?>
            <?php if ($company_gstore !== ''): ?>
            <a class="rt-link-btn rt-gstore-btn" href="<?php echo htmlspecialchars($company_gstore); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo htmlspecialchars($brand_name); ?> on Google Store">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 20.5V3.5c0-.6.34-1.1.84-1.35L13.7 12 3.84 21.85c-.5-.25-.84-.75-.84-1.35zm13.81-5.38L6.05 21.34l8.49-8.49 2.27 2.27zm3.35-4.31c.34.27.59.68.59 1.19s-.22.9-.57 1.18l-2.29 1.32-2.5-2.5 2.5-2.5 2.27 1.31zM6.05 2.66l10.76 6.22-2.27 2.27-8.49-8.49z"/></svg>
                Google Store
            </a>
            <?php endif; ?>
            <?php if ($whatsapp_url !== ''): ?>
            <!-- WhatsApp click-to-chat: shown only when the business published a number -->
            <a class="rt-whatsapp-btn" href="<?php echo htmlspecialchars($whatsapp_url); ?>"
               target="_blank" rel="noopener noreferrer">
                <svg viewBox="0 0 448 512" aria-hidden="true" focusable="false"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
                Chat on WhatsApp
            </a>
            <span class="rt-whatsapp-note">Questions or orders? Message <?php echo htmlspecialchars($brand_name); ?> directly.</span>
            <?php endif; ?>
        </div>
    </header>

    <!-- Top Section: Average Rating & Required General Rating Submission Form -->
    <div class="rt-rating-grid">
        
        <!-- Left: Average Rating & Distribution Bars -->
        <div class="rt-average-section">
            <h2>Average Rating</h2>
            <p class="rt-subtext">Overall customer satisfaction score</p>

            <div class="rt-avg-score-box">
                <span class="rt-avg-score"><?php echo number_format($avg_rating, 1); ?></span>
                <span class="rt-avg-stars">
                    <?php
                    $full_stars = floor($avg_rating);
                    $half_star  = ($avg_rating - $full_stars) >= 0.5;
                    for ($i = 0; $i < $full_stars; $i++) echo '★';
                    if ($half_star) echo '★';
                    for ($i = 0; $i < (5 - $full_stars - ($half_star ? 1 : 0)); $i++) echo '☆';
                    ?>
                </span>
            </div>
            <div class="rt-total-counter">
                Based on <strong><?php echo number_format($total_ratings); ?></strong> verified customer review(s)
            </div>
            
            <div class="rt-rating-bars">
                <?php foreach ($rating_dist as $star => $data): ?>
                <div class="rt-rating-bar-item">
                    <span class="rt-rating-label"><?php echo $star; ?> ★</span>
                    <div class="rt-bar-container">
                        <div class="rt-bar-fill" style="width: <?php echo $data['percentage']; ?>%"></div>
                    </div>
                    <span class="rt-rating-percentage"><?php echo $data['percentage']; ?>% (<?php echo $data['count']; ?>)</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Right: Submit Your Review (Required Email & Submit at top for General Customer Rating) -->
        <div class="rt-review-section">
            <?php if (!empty($prefill_name)): ?>
                <div style="background:linear-gradient(135deg, rgba(37,211,102,0.12) 0%, rgba(16,185,129,0.06) 100%);border:1px solid #86efac;border-radius:12px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:12px;">
                    <div style="width:34px;height:34px;border-radius:50%;background:#25D366;color:#fff;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;">
                        👋
                    </div>
                    <div>
                        <strong style="font-size:13.5px;color:#065f46;display:block;">Welcome, <?php echo htmlspecialchars($prefill_name); ?>!</strong>
                        <span style="font-size:12px;color:#166534;">Thank you for taking 30 seconds to share your review for <?php echo htmlspecialchars($brand_name); ?>. Your feedback means a lot to us!</span>
                    </div>
                </div>
            <?php endif; ?>

            <h2>Submit Your Review</h2>
            <p class="rt-subtext">General customer rating for <?php echo htmlspecialchars($brand_name); ?></p>

            <form id="generalRatingForm" action="../api/submit_rating.php" method="POST" enctype="multipart/form-data" onsubmit="return validateGeneralForm()">
                <input type="hidden" name="company_id" value="<?php echo $company_id; ?>">
                <input type="hidden" name="question_id" value="">

                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:8px;">
                    <label class="rt-review-form-label" style="margin:0;">Add Your Rating *</label>
                    <span id="ratingSentimentHint" class="rt-rating-sentiment-hint" style="color:#64748b;font-size:12px;font-weight:600;"></span>
                </div>
                <div class="rt-star-rating-input">
                    <input type="radio" name="rating" value="5" id="gen_star5" onchange="updateRatingHint(5)">
                    <label for="gen_star5" title="5 Stars" onmouseover="updateRatingHint(5)">★</label>
                    <input type="radio" name="rating" value="4" id="gen_star4" onchange="updateRatingHint(4)">
                    <label for="gen_star4" title="4 Stars" onmouseover="updateRatingHint(4)">★</label>
                    <input type="radio" name="rating" value="3" id="gen_star3" onchange="updateRatingHint(3)">
                    <label for="gen_star3" title="3 Stars" onmouseover="updateRatingHint(3)">★</label>
                    <input type="radio" name="rating" value="2" id="gen_star2" onchange="updateRatingHint(2)">
                    <label for="gen_star2" title="2 Stars" onmouseover="updateRatingHint(2)">★</label>
                    <input type="radio" name="rating" value="1" id="gen_star1" onchange="updateRatingHint(1)">
                    <label for="gen_star1" title="1 Star" onmouseover="updateRatingHint(1)">★</label>
                </div>
                
                <div class="rt-input-row">
                    <div>
                        <label class="rt-review-form-label">Your Name *</label>
                        <input type="text" name="customer_name" class="rt-form-input" placeholder="e.g. John Doe" required value="<?php echo htmlspecialchars($prefill_name); ?>">
                    </div>
                    <div>
                        <label class="rt-review-form-label">Email Address *</label>
                        <input type="email" name="customer_email" class="rt-form-input" placeholder="mail@example.com" required value="<?php echo htmlspecialchars($prefill_email); ?>">
                    </div>
                </div>
                
                <label class="rt-review-form-label">Write Your Review *</label>
                <textarea name="comment" class="rt-form-textarea" placeholder="Share your experience working with <?php echo htmlspecialchars($brand_name); ?>..." required></textarea>

                <!-- Optional Verification: MoMo Ref or Receipt Photo -->
                <div class="rt-verification-box">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                        <span class="rt-verified-badge">
                            <svg viewBox="0 0 512 512"><path d="M504 256c0 137-111 248-248 248S8 393 8 256 119 8 256 8s248 111 248 248zM227.3 387.3l184-184c6.2-6.2 6.2-16.4 0-22.6l-22.6-22.6c-6.2-6.2-16.4-6.2-22.6 0L216 308.1l-70.1-70.1c-6.2-6.2-16.4-6.2-22.6 0l-22.6 22.6c-6.2 6.2-6.2 16.4 0 22.6l104 104c6.2 6.2 16.4 6.2 22.6 0z"/></svg>
                            Get Verified Badge (Optional)
                        </span>
                        <span style="font-size:11.5px;color:#64748b;">Stand out with a green verified checkmark</span>
                    </div>
                    <p style="font-size:12px;color:#64748b;margin:0 0 10px;line-height:1.4;">Provide your Mobile Money (MoMo) transaction ID or upload a photo of your receipt/invoice to confirm your purchase or visit.</p>
                    <div class="rt-input-row" style="margin-bottom:0;">
                        <div>
                            <label class="rt-sub-label">MoMo Transaction ID / Reference</label>
                            <input type="text" name="momo_ref" id="inputMomoRef" class="rt-form-input" style="font-size:13px;padding:9px 12px;" placeholder="e.g. 24819284918" value="<?php echo htmlspecialchars($prefill_ref_code); ?>" oninput="updateVerifiedPreview()">
                        </div>
                        <div>
                            <label class="rt-sub-label">Receipt / Invoice Screenshot</label>
                            <input type="file" name="receipt_photo" id="inputReceiptPhoto" accept="image/jpeg,image/png,image/webp,image/jpg" class="rt-form-input" style="font-size:12px;padding:7px 10px;background:#fff;" onchange="updateVerifiedPreview()">
                        </div>
                    </div>
                    <div id="verifiedBadgePreview" style="<?php echo !empty($prefill_ref_code) ? 'display:flex;' : 'display:none;'; ?>align-items:center;gap:6px;margin-top:10px;padding:8px 12px;background:#dcfce7;border:1px solid #86efac;border-radius:8px;font-size:12px;color:#15803d;font-weight:700;">
                        <span style="font-size:14px;">✓</span> Great! Your review will be submitted with an official Verified Customer badge.
                    </div>
                </div>
                
                <button type="submit" class="rt-submit-btn">
                    Submit Reviews
                </button>
            </form>
        </div>
    </div>
    
    <?php if (!empty($services)): ?>
    <!-- Services Grid: the things this company does, with per-service reviews -->
    <section class="rt-services-section">
        <div class="rt-services-head">
            <div>
                <h2>Our Services</h2>
                <p>Rate each service <?php echo htmlspecialchars($brand_name); ?> provides — tap the comment icon to leave a review.</p>
            </div>
        </div>
        <div class="rt-services-grid">
            <?php foreach ($services as $svc):
                $svc_id     = (int)$svc['id'];
                $svc_avg    = round((float)($svc['avg_score'] ?? 0), 1);
                $svc_count  = (int)($svc['review_count'] ?? 0);
                $svc_full   = (int)floor($svc_avg);
                $svc_half   = ($svc_avg - $svc_full) >= 0.5;
                $svc_icon   = !empty($svc['icon']) ? htmlspecialchars($svc['icon']) : 'fa-solid fa-star';
                $svc_title  = htmlspecialchars($svc['title']);
                $svc_descr  = !empty($svc['description']) ? htmlspecialchars($svc['description']) : '';
                $svc_trunc  = mb_strlen($svc_descr) > 90 ? mb_substr($svc_descr, 0, 90) . '…' : $svc_descr;
            ?>
            <article class="rt-service-card" id="service-<?php echo $svc_id; ?>">
                <div class="rt-service-icon"><i class="<?php echo $svc_icon; ?>"></i></div>
                <div class="rt-service-title"><?php echo $svc_title; ?></div>
                <?php if ($svc_trunc !== ''): ?>
                <div class="rt-service-desc"><?php echo $svc_trunc; ?></div>
                <?php endif; ?>
                <div class="rt-service-stars">
                    <span>
                        <?php
                        for ($i = 0; $i < $svc_full; $i++) echo '★';
                        if ($svc_half) echo '★';
                        for ($i = 0; $i < (5 - $svc_full - ($svc_half ? 1 : 0)); $i++) echo '☆';
                        ?>
                    </span>
                    <b><?php echo number_format($svc_avg, 1); ?></b>
                </div>
                <div class="rt-service-meta"><?php echo number_format($svc_count); ?> review<?php echo $svc_count === 1 ? '' : 's'; ?></div>

                <button type="button" class="rt-service-comment-btn" onclick="toggleServiceForm(<?php echo $svc_id; ?>, this)" aria-expanded="false" title="Write a review for this service">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    Write a review
                </button>

                <form class="rt-service-review-form" action="../api/submit_rating.php" method="POST" onsubmit="return validateServiceReview(<?php echo $svc_id; ?>)">
                    <input type="hidden" name="service_id" value="<?php echo $svc_id; ?>">
                    <input type="hidden" name="company_id" value="<?php echo $company_id; ?>">
                    <label class="rt-review-form-label">Rate this service</label>
                    <div class="rt-service-form-stars">
                        <input type="radio" name="rating" value="5" id="svc_<?php echo $svc_id; ?>_5"><label for="svc_<?php echo $svc_id; ?>_5" title="5 Stars">★</label>
                        <input type="radio" name="rating" value="4" id="svc_<?php echo $svc_id; ?>_4"><label for="svc_<?php echo $svc_id; ?>_4" title="4 Stars">★</label>
                        <input type="radio" name="rating" value="3" id="svc_<?php echo $svc_id; ?>_3"><label for="svc_<?php echo $svc_id; ?>_3" title="3 Stars">★</label>
                        <input type="radio" name="rating" value="2" id="svc_<?php echo $svc_id; ?>_2"><label for="svc_<?php echo $svc_id; ?>_2" title="2 Stars">★</label>
                        <input type="radio" name="rating" value="1" id="svc_<?php echo $svc_id; ?>_1"><label for="svc_<?php echo $svc_id; ?>_1" title="1 Star">★</label>
                    </div>
                    <label class="rt-review-form-label">Your review (optional)</label>
                    <textarea name="comment" class="rt-form-textarea" rows="2" maxlength="500" placeholder="Tell us about your experience with this service…" style="min-height:58px;margin-bottom:0;padding:9px 12px;font-size:13px;"></textarea>
                    <div class="rt-service-form-actions">
                        <input type="email" name="customer_email" class="rt-input-email" placeholder="Your email (optional)">
                        <button type="submit" class="rt-service-submit">Submit Review</button>
                    </div>
                    <span class="rt-service-mail-note" style="display:none;font-size:11px;color:#64748b;"></span>
                </form>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============================================================
         Bottom Section: Divided into Two
         1. Rating Questions (Questions created by admin)
         2. Customer Feedbacks (Customer reviews & testimonials)
         ============================================================ -->
    <div class="rt-divider-section">

        <!-- Navigation Tabs to Switch Between Questions and Customer Feedback -->
        <div class="rt-tab-nav" role="tablist">
            <button type="button" class="rt-tab-btn is-active" id="tabBtn-questions" role="tab" aria-selected="true" onclick="switchPublicSection('questions')">
                <span>❓</span> Specific Reviews (<?php echo count($questions); ?>)
            </button>
            <button type="button" class="rt-tab-btn" id="tabBtn-responses" role="tab" aria-selected="false" onclick="switchPublicSection('responses')">
                <span>📝</span> Review Responses (<?php echo count($question_responses); ?>)
            </button>
            <button type="button" class="rt-tab-btn" id="tabBtn-feedbacks" role="tab" aria-selected="false" onclick="switchPublicSection('feedbacks')">
                <span>💬</span> Reviews (<?php echo count($general_reviews); ?>)
            </button>
            <button type="button" class="rt-tab-btn" id="tabBtn-qa" role="tab" aria-selected="false" onclick="switchPublicSection('qa')">
                <span>💡</span> Community Q&amp;A (<?php echo count($community_qa); ?>)
            </button>
        </div>

        <!-- PANEL 1: Questions Created by Admin -->
        <div class="rt-tab-panel is-active" id="panel-questions" role="tabpanel">
            <div style="margin-bottom:20px;">
                <h3 style="font-size:20px;font-weight:800;color:#0f172a;">Specific Reviews from Administrator</h3>
                <p class="rt-subtext" style="margin-top:4px;">Share your star rating and review for each item configured by <?php echo htmlspecialchars($brand_name); ?>. No name or email needed.</p>
            </div>

            <?php if (!empty($questions)): ?>
                <form action="../api/submit_rating.php" method="POST" id="specificReviewsForm" onsubmit="return validateReviewsForm(this)">
                <input type="hidden" name="company_id" value="<?php echo $company_id; ?>">
                <div class="rt-question-list">
                    <?php foreach ($questions as $idx => $q): $q_id = (int)$q['id']; ?>
                        <article class="rt-question-card" id="question-card-<?php echo $q_id; ?>">
                            <div class="rt-question-head">
                                <div>
                                    <span class="rt-chip-gray" style="margin-bottom:6px;display:inline-block;">Review #<?php echo $idx + 1; ?></span>
                                    <h4 class="rt-question-title"><?php echo htmlspecialchars($q['question_text']); ?></h4>
                                </div>
                                <div class="rt-question-stats">
                                    <span class="rt-chip">★ <?php echo number_format($q['avg_score'], 1); ?></span>
                                    <span class="rt-chip-gray"><?php echo (int)$q['total_answers']; ?> reviews</span>
                                </div>
                            </div>

                            <!-- Star Rating & Optional Comment for this Item -->
                            <div class="rt-question-form">
                                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
                                    <label class="rt-review-form-label" style="margin:0;">Rate this specific review</label>
                                    <div class="rt-q-star-picker" style="margin-bottom:0;">
                                        <?php for ($st = 5; $st >= 1; $st--): ?>
                                            <input type="radio" name="rating[<?php echo $q_id; ?>]" value="<?php echo $st; ?>" id="q_<?php echo $q_id; ?>_star<?php echo $st; ?>">
                                            <label for="q_<?php echo $q_id; ?>_star<?php echo $st; ?>" title="<?php echo $st; ?> Stars">★</label>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <textarea name="comment[<?php echo $q_id; ?>]" class="rt-form-textarea" rows="2" placeholder="Write your review for this... (optional)" style="min-height:60px;margin-bottom:0;padding:9px 12px;font-size:13px;"></textarea>
                            </div>

                            <!-- Existing Answers to this Question -->
                            <?php if (!empty($q['answers'])): ?>
                                <div class="rt-q-answers-list">
                                    <strong style="font-size:12.5px;color:#64748b;display:block;margin-bottom:10px;text-transform:uppercase;letter-spacing:.05em;">
                                        Reviews on this question:
                                    </strong>
                                    <?php foreach ($q['answers'] as $ans): ?>
                                        <div class="rt-q-answer-item">
                                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;gap:8px;flex-wrap:wrap;">
                                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                    <strong style="color:#0f172a;"><?php echo htmlspecialchars($ans['customer_name']); ?></strong>
                                                    <?php if (!empty($ans['is_verified'])): ?>
                                                        <span class="rt-verified-badge" title="Verified Customer">
                                                            <svg viewBox="0 0 512 512"><path d="M504 256c0 137-111 248-248 248S8 393 8 256 119 8 256 8s248 111 248 248zM227.3 387.3l184-184c6.2-6.2 6.2-16.4 0-22.6l-22.6-22.6c-6.2-6.2-16.4-6.2-22.6 0L216 308.1l-70.1-70.1c-6.2-6.2-16.4-6.2-22.6 0l-22.6 22.6c-6.2 6.2-6.2 16.4 0 22.6l104 104c6.2 6.2 16.4 6.2 22.6 0z"/></svg>
                                                            Verified
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <span style="color:#f59e0b;font-weight:700;">
                                                    <?php echo str_repeat('★', (int)$ans['rating']); ?>
                                                    <span style="color:#64748b;font-size:11px;font-weight:400;margin-left:4px;"><?php echo date('M d, Y', strtotime($ans['created_at'])); ?></span>
                                                </span>
                                            </div>
                                            <p style="color:#475569;margin:0;line-height:1.5;">
                                                &ldquo;<?php echo htmlspecialchars($ans['comment']); ?>&rdquo;
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                                        <?php endforeach; ?>
                </div>

                <!-- ONE general submit button for all review items -->
                <div style="display:flex;justify-content:flex-end;margin-top:14px;">
                    <button type="submit" class="rt-submit-btn" style="width:auto;padding:11px 30px;font-size:14px;">
                        Submit Review Response
                    </button>
                </div>
                </form>
            <?php else: ?>
                <div class="rt-empty">
                    <p>No specific rating questions have been created yet by the administrator.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- PANEL 2: Responses to the Specific Review Items -->
        <div class="rt-tab-panel" id="panel-responses" role="tabpanel">
            <div style="margin-bottom:20px;">
                <h3 style="font-size:20px;font-weight:800;color:#0f172a;">Review Responses</h3>
                <p class="rt-subtext" style="margin-top:4px;">Customer responses to the specific review items configured by <?php echo htmlspecialchars($brand_name); ?>.</p>
            </div>

            <?php if (!empty($question_responses)): ?>
                <div class="rt-greview-list">
                    <?php foreach ($question_responses as $rv): $rv_ts = date('M j, Y, g:i A', strtotime($rv['created_at'])); ?>
                        <div class="rt-greview">
                            <div class="rt-greview-top">
                                <div class="rt-greview-avatar"><?php echo strtoupper(substr(trim($rv['customer_name'] ?: 'A'), 0, 1)); ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <span class="rt-greview-name"><?php echo htmlspecialchars($rv['customer_name'] ?: 'Anonymous'); ?></span>
                                            <?php if (!empty($rv['is_verified'])): ?>
                                                <span class="rt-verified-badge" title="Verified Customer<?php echo !empty($rv['verification_type']) ? ' via ' . htmlspecialchars($rv['verification_type']) : ''; ?>">
                                                    <svg viewBox="0 0 512 512"><path d="M504 256c0 137-111 248-248 248S8 393 8 256 119 8 256 8s248 111 248 248zM227.3 387.3l184-184c6.2-6.2 6.2-16.4 0-22.6l-22.6-22.6c-6.2-6.2-16.4-6.2-22.6 0L216 308.1l-70.1-70.1c-6.2-6.2-16.4-6.2-22.6 0l-22.6 22.6c-6.2 6.2-6.2 16.4 0 22.6l104 104c6.2 6.2 16.4 6.2 22.6 0z"/></svg>
                                                    Verified Customer
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="rt-greview-time" title="<?php echo $rv_ts; ?>"><?php echo function_exists('timeAgo') ? timeAgo($rv['created_at']) : $rv_ts; ?> &middot; <?php echo $rv_ts; ?></span>
                                    </div>
                                    <div class="rt-greview-stars">
                                        <?php
                                        for ($i = 0; $i < (int)$rv['rating']; $i++) echo '★';
                                        for ($i = (int)$rv['rating']; $i < 5; $i++) echo '☆';
                                        ?>
                                    </div>
                                    <?php if (!empty($rv['question_text'])): ?>
                                        <div class="rt-greview-q">Re: <?php echo htmlspecialchars($rv['question_text']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($rv['comment'])): ?>
                                        <p class="rt-greview-text"><?php echo htmlspecialchars($rv['comment']); ?></p>
                                    <?php else: ?>
                                        <p class="rt-greview-text rt-greview-norv">The user didn&rsquo;t write a review, and has left just a rating.</p>
                                    <?php endif; ?>

                                    <?php if (!empty($rv['admin_reply'])): ?>
                                        <div class="rt-reply-box">
                                            <strong>↪ Response from <?php echo htmlspecialchars($brand_name); ?>:</strong>
                                            <p style="margin:4px 0 0;"><?php echo htmlspecialchars($rv['admin_reply']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <div class="rt-review-actions">
                                        <?php if (!empty($company['whatsapp_number'])): 
                                            $rv_author = trim($rv['customer_name'] ?: 'a customer');
                                            $rv_snippet = !empty($rv['comment']) ? '"' . mb_substr(strip_tags($rv['comment']), 0, 70) . '..."' : ((int)$rv['rating'] . '-star rating');
                                            $rv_wa_msg = 'Hello ' . $brand_name . ', I was reading the review by ' . $rv_author . ' on Optibiz (' . $rv_snippet . ') and would like to inquire.';
                                            $rv_wa_url = whatsappChatUrl($company['whatsapp_number'], $brand_name, $rv_wa_msg);
                                            if ($rv_wa_url !== ''):
                                        ?>
                                            <a href="<?php echo htmlspecialchars($rv_wa_url); ?>" target="_blank" rel="noopener noreferrer" class="rt-review-wa-btn" title="Chat with <?php echo htmlspecialchars($brand_name); ?> on WhatsApp">
                                                <svg viewBox="0 0 448 512" aria-hidden="true"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
                                                Inquire on WhatsApp
                                            </a>
                                        <?php endif; endif; ?>

                                        <?php 
                                        $share_quote = !empty($rv['comment']) ? '"' . mb_substr(strip_tags($rv['comment']), 0, 90) . '..."' : ((int)$rv['rating'] . '-star review');
                                        $wa_share_msg = rawurlencode('Check out this ' . (int)$rv['rating'] . '★ review for ' . $brand_name . ': ' . $share_quote . ' ' . $canonical_url);
                                        ?>
                                        <button type="button" class="rt-review-share-btn" onclick="shareCustomerReview(<?php echo htmlspecialchars(json_encode([
                                            'title' => (int)$rv['rating'] . '★ Review for ' . $brand_name,
                                            'text' => 'Review by ' . ($rv['customer_name'] ?: 'Guest') . ': ' . $share_quote,
                                            'url' => $canonical_url,
                                            'waUrl' => 'https://api.whatsapp.com/send?text=' . $wa_share_msg
                                        ])); ?>)" title="Share this review">
                                            <svg viewBox="0 0 24 24" style="width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2;"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                                            Share
                                        </button>

                                        <?php if ((int)$rv['rating'] >= 4): ?>
                                            <a href="../admin/social_card.php?rating_id=<?php echo (int)$rv['id']; ?>" target="_blank" rel="noopener noreferrer" class="rt-review-card-btn" title="Open Social Proof Card Studio for this review">
                                                <span>🎨</span> Story Graphic ↗
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rt-empty">
                    <p>No review responses have been submitted yet. Answers from the Specific Reviews tab will appear here.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- PANEL 3: General Customer Feedbacks & Testimonials -->
        <div class="rt-tab-panel" id="panel-feedbacks" role="tabpanel">
            <div style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3 style="font-size:20px;font-weight:800;color:#0f172a;">Customer Reviews &amp; Testimonials</h3>
                    <p class="rt-subtext" style="margin-top:4px;">All general customer reviews and verified ratings for <?php echo htmlspecialchars($brand_name); ?>.</p>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="rt-chip-filter is-active" onclick="filterReviews('all', this)">All (<?php echo count($general_reviews); ?>)</button>
                    <button type="button" class="rt-chip-filter" onclick="filterReviews('verified', this)">✓ Verified Only</button>
                    <button type="button" class="rt-chip-filter" onclick="filterReviews('5star', this)">5 ★ Only</button>
                </div>
            </div>

            <?php if (!empty($general_reviews)): ?>
                <div class="rt-greview-list" id="generalReviewsList">
                    <?php foreach ($general_reviews as $rv): $rv_ts = date('M j, Y, g:i A', strtotime($rv['created_at'])); ?>
                        <div class="rt-greview" data-is-verified="<?php echo !empty($rv['is_verified']) ? '1' : '0'; ?>" data-rating="<?php echo (int)$rv['rating']; ?>">
                            <div class="rt-greview-top">
                                <div class="rt-greview-avatar"><?php echo strtoupper(substr(trim($rv['customer_name'] ?: 'A'), 0, 1)); ?></div>
                                <div style="flex:1;min-width:0;">
                                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <span class="rt-greview-name"><?php echo htmlspecialchars($rv['customer_name'] ?: 'Anonymous'); ?></span>
                                            <?php if (!empty($rv['is_verified'])): ?>
                                                <span class="rt-verified-badge" title="Verified Customer<?php echo !empty($rv['verification_type']) ? ' via ' . htmlspecialchars($rv['verification_type']) : ''; ?>">
                                                    <svg viewBox="0 0 512 512"><path d="M504 256c0 137-111 248-248 248S8 393 8 256 119 8 256 8s248 111 248 248zM227.3 387.3l184-184c6.2-6.2 6.2-16.4 0-22.6l-22.6-22.6c-6.2-6.2-16.4-6.2-22.6 0L216 308.1l-70.1-70.1c-6.2-6.2-16.4-6.2-22.6 0l-22.6 22.6c-6.2 6.2-6.2 16.4 0 22.6l104 104c6.2 6.2 16.4 6.2 22.6 0z"/></svg>
                                                    Verified Customer
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="rt-greview-time" title="<?php echo $rv_ts; ?>"><?php echo function_exists('timeAgo') ? timeAgo($rv['created_at']) : $rv_ts; ?> &middot; <?php echo $rv_ts; ?></span>
                                    </div>
                                    <div class="rt-greview-stars">
                                        <?php
                                        for ($i = 0; $i < (int)$rv['rating']; $i++) echo '★';
                                        for ($i = (int)$rv['rating']; $i < 5; $i++) echo '☆';
                                        ?>
                                    </div>
                                    <?php if (!empty($rv['comment'])): ?>
                                        <p class="rt-greview-text"><?php echo htmlspecialchars($rv['comment']); ?></p>
                                    <?php else: ?>
                                        <p class="rt-greview-text rt-greview-norv">The user didn&rsquo;t write a review, and has left just a rating.</p>
                                    <?php endif; ?>

                                    <!-- Official Tenant Response if present -->
                                    <?php if (!empty($rv['admin_reply'])): ?>
                                        <div class="rt-reply-box">
                                            <strong>↪ Response from <?php echo htmlspecialchars($brand_name); ?>:</strong>
                                            <p style="margin:4px 0 0;"><?php echo htmlspecialchars($rv['admin_reply']); ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <div class="rt-review-actions">
                                        <?php if (!empty($company['whatsapp_number'])): 
                                            $rv_author = trim($rv['customer_name'] ?: 'a customer');
                                            $rv_snippet = !empty($rv['comment']) ? '"' . mb_substr(strip_tags($rv['comment']), 0, 70) . '..."' : ((int)$rv['rating'] . '-star review');
                                            $rv_wa_msg = 'Hello ' . $brand_name . ', I was reading the review by ' . $rv_author . ' on Optibiz (' . $rv_snippet . ') and would like to inquire.';
                                            $rv_wa_url = whatsappChatUrl($company['whatsapp_number'], $brand_name, $rv_wa_msg);
                                            if ($rv_wa_url !== ''):
                                        ?>
                                            <a href="<?php echo htmlspecialchars($rv_wa_url); ?>" target="_blank" rel="noopener noreferrer" class="rt-review-wa-btn" title="Chat with <?php echo htmlspecialchars($brand_name); ?> on WhatsApp">
                                                <svg viewBox="0 0 448 512" aria-hidden="true"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
                                                Inquire on WhatsApp
                                            </a>
                                        <?php endif; endif; ?>

                                        <?php 
                                        $share_quote = !empty($rv['comment']) ? '"' . mb_substr(strip_tags($rv['comment']), 0, 90) . '..."' : ((int)$rv['rating'] . '-star review');
                                        $wa_share_msg = rawurlencode('Check out this ' . (int)$rv['rating'] . '★ review for ' . $brand_name . ': ' . $share_quote . ' ' . $canonical_url);
                                        ?>
                                        <button type="button" class="rt-review-share-btn" onclick="shareCustomerReview(<?php echo htmlspecialchars(json_encode([
                                            'title' => (int)$rv['rating'] . '★ Review for ' . $brand_name,
                                            'text' => 'Review by ' . ($rv['customer_name'] ?: 'Guest') . ': ' . $share_quote,
                                            'url' => $canonical_url,
                                            'waUrl' => 'https://api.whatsapp.com/send?text=' . $wa_share_msg
                                        ])); ?>)" title="Share this review">
                                            <svg viewBox="0 0 24 24" style="width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2;"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                                            Share
                                        </button>

                                        <?php if ((int)$rv['rating'] >= 4): ?>
                                            <a href="../admin/social_card.php?rating_id=<?php echo (int)$rv['id']; ?>" target="_blank" rel="noopener noreferrer" class="rt-review-card-btn" title="Open Social Proof Card Studio for this review">
                                                <span>🎨</span> Story Graphic ↗
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rt-empty">
                    <p>No customer reviews have been submitted yet. Be the first to share your experience above!</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- PANEL 4: Community Q&A (Customer Questions & Official Management Answers) -->
        <div class="rt-tab-panel" id="panel-qa" role="tabpanel">
            <div style="margin-bottom:20px;">
                <h3 style="font-size:20px;font-weight:800;color:#0f172a;">Community Q&amp;A</h3>
                <p class="rt-subtext" style="margin-top:4px;">Have a question before purchasing or visiting? Explore official answers from <?php echo htmlspecialchars($brand_name); ?> or ask your own.</p>
            </div>

            <!-- Q&A Action Toolbar: Search + Ask Question Toggle -->
            <div class="rt-qa-toolbar">
                <div class="rt-qa-search-box">
                    <svg class="rt-qa-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="qaSearchInput" placeholder="Search questions &amp; answers..." oninput="filterCommunityQA(this.value)">
                </div>
                <button type="button" class="rt-qa-btn-ask" onclick="toggleQaAskForm()">
                    <span>❓</span> Ask a Question
                </button>
            </div>

            <!-- Expandable Ask Question Form -->
            <div class="rt-qa-ask-panel" id="qaAskPanel">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                    <div>
                        <h4 style="font-size:16px;font-weight:800;color:#0f172a;margin:0;">Ask <?php echo htmlspecialchars($brand_name); ?> a Question</h4>
                        <p style="font-size:12.5px;color:#64748b;margin:4px 0 0;">Pre-purchase queries, delivery areas, hours, or services. Our management team will answer publicly.</p>
                    </div>
                    <button type="button" onclick="toggleQaAskForm()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;line-height:1;">&times;</button>
                </div>
                <form id="qaSubmitForm" onsubmit="return submitCommunityQaForm(event, this)">
                    <input type="hidden" name="company_id" value="<?php echo $company_id; ?>">
                    <input type="hidden" name="action" value="ask_question">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                        <div>
                            <label class="rt-sub-label">Your Name <span style="color:#94a3b8;font-weight:normal;">(optional)</span></label>
                            <input type="text" name="customer_name" class="rt-input" placeholder="e.g. Samuel A." style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:13.5px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label class="rt-sub-label">Email or WhatsApp <span style="color:#94a3b8;font-weight:normal;">(for answer alert)</span></label>
                            <input type="text" name="customer_email" class="rt-input" placeholder="e.g. samuel@gmail.com" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:13.5px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label class="rt-sub-label">Your Question <span style="color:#ef4444;">*</span></label>
                        <textarea name="question_text" class="rt-textarea" rows="3" required placeholder="e.g. Do you deliver to Tema? What are your weekend check-in hours?" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:13.5px;font-family:inherit;resize:vertical;box-sizing:border-box;"></textarea>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                        <span style="font-size:12px;color:#64748b;">🔒 Your contact info is never displayed publicly.</span>
                        <div style="display:flex;gap:10px;">
                            <button type="button" onclick="toggleQaAskForm()" style="padding:8px 16px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:10px;font-size:13px;cursor:pointer;font-weight:600;color:#475569;">Cancel</button>
                            <button type="submit" id="btnSubmitQa" style="padding:8px 20px;background:#059669;color:#fff;border:none;border-radius:10px;font-size:13px;cursor:pointer;font-weight:700;">Submit Question</button>
                        </div>
                    </div>
                    <div id="qaFormMsg" style="display:none;margin-top:12px;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;"></div>
                </form>
            </div>

            <!-- List of Questions & Answers -->
            <div id="qaListContainer">
                <?php if (!empty($community_qa)): ?>
                    <?php foreach ($community_qa as $qa): 
                        $qa_id = (int)$qa['id'];
                        $is_pinned = !empty($qa['is_pinned']);
                        $is_answered = !empty($qa['is_answered']) || (isset($qa['official_answer']) && trim((string)$qa['official_answer']) !== '');
                        $cust_display = !empty($qa['customer_name']) ? htmlspecialchars($qa['customer_name']) : 'Customer';
                        $qa_date = date('M j, Y', strtotime($qa['created_at']));
                        $q_content = $qa['question_text'] ?? ($qa['question'] ?? '');
                    ?>
                        <article class="rt-qa-card <?php echo $is_pinned ? 'rt-qa-pinned' : ''; ?>" id="qa-card-<?php echo $qa_id; ?>" data-qa-text="<?php echo htmlspecialchars(strtolower($q_content . ' ' . ($qa['official_answer'] ?? '') . ' ' . $cust_display)); ?>">
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <?php if ($is_pinned): ?>
                                        <span class="rt-qa-pinned-badge">📌 Featured FAQ</span>
                                    <?php endif; ?>
                                    <?php if ($is_answered): ?>
                                        <span class="rt-qa-answered-badge">✓ Official Answer</span>
                                    <?php else: ?>
                                        <span class="rt-qa-pending-badge">⏳ Under Review</span>
                                    <?php endif; ?>
                                    <span style="font-size:12.5px;font-weight:700;color:#334155;"><?php echo $cust_display; ?> asked:</span>
                                </div>
                                <span style="font-size:12px;color:#94a3b8;"><?php echo function_exists('timeAgo') ? timeAgo($qa['created_at']) : $qa_date; ?></span>
                            </div>

                            <div class="rt-qa-qtext">
                                Q: <?php echo htmlspecialchars($q_content); ?>
                            </div>

                            <?php if ($is_answered && !empty($qa['official_answer'])): ?>
                                <div class="rt-qa-answer-box">
                                    <div class="rt-qa-answer-header">
                                        <span style="display:inline-flex;align-items:center;gap:6px;">
                                            <svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:#059669;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                                            Official Response from <?php echo htmlspecialchars($brand_name); ?>
                                        </span>
                                        <?php if (!empty($qa['answered_at'])): ?>
                                            <small style="font-size:11.5px;color:#64748b;font-weight:normal;"><?php echo date('M j, Y', strtotime($qa['answered_at'])); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rt-qa-answer-body">
                                        <?php echo nl2br(htmlspecialchars($qa['official_answer'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="rt-qa-footer-actions">
                                <div>
                                    <button type="button" class="rt-qa-vote-btn" onclick="voteQaHelpful(<?php echo $qa_id; ?>, this)">
                                        <span>👍</span> Helpful (<span class="vote-count"><?php echo (int)$qa['helpful_count']; ?></span>)
                                    </button>
                                </div>
                                <?php if (!empty($company['whatsapp_number'])): 
                                    $qa_wa_msg = 'Hello ' . $brand_name . ', I read this Q&A: "' . mb_substr(strip_tags($q_content), 0, 75) . '..." on your verified page and had a quick inquiry.';
                                    $qa_wa_url = whatsappChatUrl($company['whatsapp_number'], $brand_name, $qa_wa_msg);
                                    if ($qa_wa_url !== ''):
                                ?>
                                    <div>
                                        <a href="<?php echo htmlspecialchars($qa_wa_url); ?>" target="_blank" rel="noopener noreferrer" class="rt-review-wa-btn" style="margin-top:0;" title="Ask about this on WhatsApp">
                                            <svg viewBox="0 0 448 512" aria-hidden="true"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
                                            Inquire on WhatsApp
                                        </a>
                                    </div>
                                <?php endif; endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="rt-empty" id="qaEmptyState">
                        <div style="font-size:36px;margin-bottom:8px;">💡</div>
                        <p style="font-weight:700;font-size:16px;color:#1e293b;margin-bottom:6px;">No Community Questions Yet</p>
                        <p style="color:#64748b;font-size:13.5px;max-width:480px;margin:0 auto 16px;">Have a question about services, pricing, delivery, or reservations? Ask <?php echo htmlspecialchars($brand_name); ?> directly!</p>
                        <button type="button" class="rt-qa-btn-ask" onclick="toggleQaAskForm()">Ask the First Question</button>
                    </div>
                <?php endif; ?>
            </div>
            <div id="qaNoResults" style="display:none;text-align:center;padding:36px 20px;color:#64748b;">
                <p style="font-size:15px;font-weight:600;">No matching questions found.</p>
                <button type="button" class="rt-qa-btn-ask" onclick="toggleQaAskForm()" style="margin-top:10px;">Ask this Question</button>
            </div>
        </div>

    </div>

    <!-- Company Info Footer (configured by admin in Company Profile) -->
    <footer class="rt-footer">
        <div class="rt-footer-grid">
            <!-- Col 1: Brand Info, Website & Configured Socials -->
            <div class="rt-footer-col">
                <div class="rt-footer-brand">
                    <?php if (!empty($brand_logo)): ?>
                        <img src="../<?php echo htmlspecialchars($brand_logo); ?>" alt="<?php echo htmlspecialchars($brand_name); ?> logo">
                    <?php else: ?>
                        <div class="rt-footer-fallback"><?php echo htmlspecialchars($brand_initials); ?></div>
                    <?php endif; ?>
                    <div>
                        <strong><?php echo htmlspecialchars($brand_name); ?></strong>
                        <small><?php echo htmlspecialchars($company['category_name'] ?? 'Verified Business'); ?></small>
                    </div>
                </div>
                <?php $company_desc = trim((string)($company['description'] ?? '')); ?>
                <?php if ($company_desc !== ''): ?>
                    <p class="rt-footer-desc"><?php echo htmlspecialchars($company_desc); ?></p>
                <?php else: ?>
                    <p class="rt-footer-desc">Thank you for visiting our verified rating portal. Your honest feedback helps us serve you better every day.</p>
                <?php endif; ?>

                <?php if ($company_website !== ''): ?>
                    <a href="<?php echo htmlspecialchars($company_website); ?>" target="_blank" rel="noopener noreferrer" class="rt-footer-web-btn" title="Visit <?php echo htmlspecialchars($brand_name); ?> website">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        <span>Visit Official Website ↗</span>
                    </a>
                <?php endif; ?>

                <?php if (!empty($social_links)): ?>
                    <div style="margin-top: 4px;">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; display: block; margin-bottom: 6px;">Connect with Us:</span>
                        <div class="rt-social-row">
                            <?php foreach ($social_links as $s): ?>
                                <a href="<?php echo htmlspecialchars($s['url']); ?>" target="_blank" rel="noopener noreferrer" class="rt-social-btn <?php echo htmlspecialchars($s['class']); ?>" title="<?php echo htmlspecialchars($s['label']); ?>" data-network="<?php echo htmlspecialchars($s['key']); ?>" aria-label="<?php echo htmlspecialchars($s['label']); ?>">
                                    <?php if ($s['key'] === 'facebook'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                    <?php elseif ($s['key'] === 'instagram'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                                    <?php elseif ($s['key'] === 'twitter'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                    <?php elseif ($s['key'] === 'linkedin'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                                    <?php elseif ($s['key'] === 'tiktok'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-1.01-.02 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.64 1.82 2.89 3.5 2.77 1.81-.04 3.29-1.52 3.36-3.33.05-3.06.01-6.12.02-9.18.01-3.27.01-6.54.02-9.82z"/></svg>
                                    <?php elseif ($s['key'] === 'youtube'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Col 2: Direct Contact Channels -->
            <div class="rt-footer-col">
                <div class="rt-footer-col-title">
                    <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    Direct Contact
                </div>
                <ul class="rt-footer-contact">
                    <?php if (trim((string)($company['phone'] ?? '')) !== ''): ?>
                    <li>
                        <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <div>
                            <span style="font-size:11px;color:#94a3b8;display:block;">Phone</span>
                            <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $company['phone'])); ?>"><?php echo htmlspecialchars($company['phone']); ?></a>
                        </div>
                    </li>
                    <?php endif; ?>

                    <?php if (!empty($company['whatsapp_number']) && $whatsapp_url !== ''): ?>
                    <li>
                        <svg viewBox="0 0 24 24" style="stroke:#25D366;"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                        <div>
                            <span style="font-size:11px;color:#94a3b8;display:block;">WhatsApp Inquiry</span>
                            <a href="<?php echo htmlspecialchars($whatsapp_url); ?>" target="_blank" rel="noopener noreferrer" style="color:#15803d;"><?php echo htmlspecialchars($company['whatsapp_number']); ?> ↗</a>
                        </div>
                    </li>
                    <?php endif; ?>

                    <?php if (trim((string)($company['email'] ?? '')) !== ''): ?>
                    <li>
                        <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <div>
                            <span style="font-size:11px;color:#94a3b8;display:block;">Email</span>
                            <a href="mailto:<?php echo htmlspecialchars($company['email']); ?>"><?php echo htmlspecialchars($company['email']); ?></a>
                        </div>
                    </li>
                    <?php endif; ?>

                    <?php if (!empty($company['google_store_url'])): ?>
                    <li>
                        <svg viewBox="0 0 24 24" style="stroke:#4285f4;"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        <div>
                            <span style="font-size:11px;color:#94a3b8;display:block;">Google Profile</span>
                            <a href="<?php echo htmlspecialchars($company['google_store_url']); ?>" target="_blank" rel="noopener noreferrer" style="color:#2563eb;">Google Business Profile ↗</a>
                        </div>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Col 3: Location, Landmark Description & Google Map Directions -->
            <div class="rt-footer-col">
                <div class="rt-footer-col-title">
                    <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Location &amp; Directions
                </div>
                <div class="rt-location-card">
                    <?php if (trim((string)($company['address'] ?? '')) !== ''): ?>
                        <div class="rt-location-address">
                            <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span><?php echo htmlspecialchars($company['address']); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($location_description !== ''): ?>
                        <div class="rt-location-guide">
                            <strong style="color:#0f172a;display:block;margin-bottom:3px;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">📍 Landmark &amp; Arrival Guide:</strong>
                            <?php echo nl2br(htmlspecialchars($location_description)); ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    // Target map URL: configured google_map_url or fallback search URL with address
                    $map_target_url = $google_map_url;
                    if (empty($map_target_url) && !empty($company['address'])) {
                        $map_target_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($company['company_name'] . ' ' . $company['address']);
                    }
                    ?>

                    <?php if (!empty($map_target_url)): ?>
                        <a href="<?php echo htmlspecialchars($map_target_url); ?>" target="_blank" rel="noopener noreferrer" class="rt-map-direction-btn" title="Open Google Maps Directions in new tab">
                            <svg viewBox="0 0 24 24"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                            <span>🚗 Get Google Maps Directions ↗</span>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($map_embed_src)): ?>
                        <iframe src="<?php echo htmlspecialchars($map_embed_src); ?>" class="rt-map-embed-frame" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Google Map for <?php echo htmlspecialchars($brand_name); ?>"></iframe>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="rt-footer-bottom">
            <span>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($brand_name); ?>. All rights reserved.</span>
            <span>Powered by <a href="../index.php" target="_blank" rel="noopener">Optibiz Ratings</a></span>
        </div>
    </footer>

</div>

<script>
/* Switch bottom sections between Specific Reviews / Review Responses / Reviews */
function switchPublicSection(section) {
    // Hide all panels
    var panels = document.querySelectorAll('[id^="panel-"]');
    for (var i = 0; i < panels.length; i++) {
        panels[i].classList.remove('is-active');
    }
    // Deactivate all tab buttons
    var buttons = document.querySelectorAll('[id^="tabBtn-"]');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].classList.remove('is-active');
        buttons[i].setAttribute('aria-selected', 'false');
    }
    // Activate selected panel and button
    var panel = document.getElementById('panel-' + section);
    var btn = document.getElementById('tabBtn-' + section);
    if (panel) panel.classList.add('is-active');
    if (btn) {
        btn.classList.add('is-active');
        btn.setAttribute('aria-selected', 'true');
    }
    // Update URL hash
    try {
        history.replaceState(null, null, '#tab=' + section);
    } catch(e) {}
}

// Restore tab from URL hash on page load
(function() {
    var hash = location.hash.replace('#tab=', '').replace('#', '');
    if (hash === 'feedbacks' || hash === 'responses' || hash === 'questions' || hash === 'qa') {
        switchPublicSection(hash);
    }
})();

function toggleQaAskForm() {
    var p = document.getElementById('qaAskPanel');
    if (!p) return;
    if (p.style.display === 'block') {
        p.style.display = 'none';
    } else {
        p.style.display = 'block';
        p.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        var ta = p.querySelector('textarea');
        if (ta) ta.focus();
    }
}

function filterCommunityQA(query) {
    query = (query || '').toLowerCase().trim();
    var cards = document.querySelectorAll('#qaListContainer .rt-qa-card');
    var visible = 0;
    cards.forEach(function(card) {
        var text = card.getAttribute('data-qa-text') || '';
        if (query === '' || text.indexOf(query) !== -1) {
            card.style.display = '';
            visible++;
        } else {
            card.style.display = 'none';
        }
    });
    var noRes = document.getElementById('qaNoResults');
    if (noRes) {
        noRes.style.display = (cards.length > 0 && visible === 0) ? 'block' : 'none';
    }
}

function submitCommunityQaForm(e, form) {
    e.preventDefault();
    var btn = document.getElementById('btnSubmitQa');
    var msg = document.getElementById('qaFormMsg');
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; }
    
    var formData = new FormData(form);
    fetch('../api/submit_qa.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (msg) {
            msg.style.display = 'block';
            if (data.success) {
                msg.style.background = '#dcfce7';
                msg.style.color = '#15803d';
                msg.style.border = '1px solid #86efac';
                msg.innerHTML = '✓ ' + (data.message || 'Thank you! Your question has been submitted for management review.');
                form.reset();
                setTimeout(function() {
                    toggleQaAskForm();
                    msg.style.display = 'none';
                }, 4500);
            } else {
                msg.style.background = '#fee2e2';
                msg.style.color = '#b91c1c';
                msg.style.border = '1px solid #fca5a5';
                msg.innerHTML = '⚠ ' + (data.message || 'An error occurred. Please try again.');
            }
        }
    })
    .catch(function(err) {
        if (msg) {
            msg.style.display = 'block';
            msg.style.background = '#fee2e2';
            msg.style.color = '#b91c1c';
            msg.innerHTML = '⚠ Failed to send question. Please check your internet connection.';
        }
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.textContent = 'Submit Question'; }
    });
    return false;
}

function voteQaHelpful(questionId, btn) {
    var fd = new FormData();
    fd.append('action', 'vote_helpful');
    fd.append('question_id', questionId);
    
    fetch('../api/submit_qa.php', {
        method: 'POST',
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            btn.classList.add('is-voted');
            var countEl = btn.querySelector('.vote-count');
            if (countEl && typeof data.helpful_count !== 'undefined') {
                countEl.textContent = data.helpful_count;
            }
        } else if (data.already_voted) {
            btn.classList.add('is-voted');
            alert('You have already marked this question as helpful.');
        }
    })
    .catch(function(e) {
        console.error(e);
    });
}

function validateGeneralForm() {
    var ratingSelected = document.querySelector('input[name="rating"]:checked');
    if (!ratingSelected) {
        alert('Please select a star rating (1 to 5 stars) before submitting.');
        return false;
    }
    if (typeof window.trackOptibizEvent === 'function') {
        window.trackOptibizEvent('review_submit', 'general_review', 'General Review Form');
    }
    return true;
}

function validateReviewsForm(form) {
    var anyRated = form.querySelector('input[name^="rating"]:checked');
    if (!anyRated) {
        alert('Please select a star rating for at least one review before submitting.');
        return false;
    }
    if (typeof window.trackOptibizEvent === 'function') {
        window.trackOptibizEvent('review_submit', 'specific_reviews', 'Specific Reviews Form');
    }
    return true;
}

function updateRatingHint(stars) {
    var el = document.getElementById('ratingSentimentHint');
    if (!el) return;
    var hints = {
        5: '⭐⭐⭐⭐⭐ Outstanding Experience!',
        4: '⭐⭐⭐⭐ Very Good / Satisfied',
        3: '⭐⭐⭐ Neutral / Average',
        2: '⭐⭐ Needs Improvement',
        1: '⭐ Unsatisfactory'
    };
    var colors = {
        5: '#15803d',
        4: '#059669',
        3: '#d97706',
        2: '#dc2626',
        1: '#b91c1c'
    };
    el.textContent = hints[stars] || '';
    el.style.color = colors[stars] || '#64748b';
}

function updateVerifiedPreview() {
    var momo = document.getElementById('inputMomoRef');
    var photo = document.getElementById('inputReceiptPhoto');
    var box = document.getElementById('verifiedBadgePreview');
    if (!box) return;
    var hasMomo = momo && momo.value.trim().length > 0;
    var hasPhoto = photo && photo.files && photo.files.length > 0;
    box.style.display = (hasMomo || hasPhoto) ? 'flex' : 'none';
}

/* Service Card Review Functions */
function toggleServiceForm(serviceId, btn) {
    var card = document.getElementById('service-' + serviceId);
    if (!card) return;
    
    var form = card.querySelector('.rt-service-review-form');
    if (!form) return;
    
    var isOpen = form.style.display === 'block';
    
    // Close all other service forms first
    document.querySelectorAll('.rt-service-review-form').forEach(function(f) {
        f.style.display = 'none';
    });
    document.querySelectorAll('.rt-service-comment-btn').forEach(function(b) {
        b.setAttribute('aria-expanded', 'false');
        b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Write a review';
    });
    
    if (!isOpen) {
        form.style.display = 'block';
        btn.setAttribute('aria-expanded', 'true');
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel';
        
        // Scroll into view
        setTimeout(function() {
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 100);
    }
}

function validateServiceReview(serviceId) {
    var card = document.getElementById('service-' + serviceId);
    if (!card) return false;
    
    var form = card.querySelector('.rt-service-review-form');
    if (!form) return false;
    
    var ratingSelected = form.querySelector('input[name="rating"]:checked');
    if (!ratingSelected) {
        alert('Please select a star rating (1 to 5 stars) before submitting your review.');
        return false;
    }
    
    // Track event if analytics available
    if (typeof window.trackOptibizEvent === 'function') {
        window.trackOptibizEvent('review_submit', 'service_review', 'Service Review: ' + serviceId);
    }
    
    return true;
}

function shareCustomerReview(data) {
    if (navigator.share) {
        navigator.share({
            title: data.title,
            text: data.text,
            url: data.url
        }).then(function() {
            if (typeof window.trackOptibizEvent === 'function') {
                window.trackOptibizEvent('share_click', 'native_share', data.title);
            }
        }).catch(function() {});
    } else {
        if (typeof window.trackOptibizEvent === 'function') {
            window.trackOptibizEvent('share_click', 'whatsapp_share', data.title);
        }
        window.open(data.waUrl, '_blank');
    }
}

function filterReviews(filter, btn) {
    var buttons = btn.parentElement.querySelectorAll('.rt-chip-filter');
    buttons.forEach(function(b) { b.classList.remove('is-active'); });
    btn.classList.add('is-active');
    
    var reviews = document.querySelectorAll('#generalReviewsList .rt-greview');
    reviews.forEach(function(el) {
        var isVerified = el.getAttribute('data-is-verified') === '1';
        var rating = parseInt(el.getAttribute('data-rating') || '0', 10);
        if (filter === 'all') {
            el.style.display = '';
        } else if (filter === 'verified') {
            el.style.display = isVerified ? '' : 'none';
        } else if (filter === '5star') {
            el.style.display = (rating === 5) ? '' : 'none';
        }
    });
}

// ============================================================
// Telemetry & Interaction Analytics (Feature #6)
// ============================================================
(function() {
    var companyId = <?php echo (int)$company_id; ?>;
    var tenantId = <?php echo (int)$tenant_id; ?>;
    var pageUrl = window.location.href;
    var referrer = document.referrer || '';
    
    // Persistent visitor/session ID
    var sessId = '';
    try {
        sessId = localStorage.getItem('optibiz_vid');
        if (!sessId) {
            sessId = 'v_' + Math.random().toString(36).substring(2, 15) + Date.now().toString(36);
            localStorage.setItem('optibiz_vid', sessId);
        }
    } catch(e) {
        sessId = 'v_' + Math.random().toString(36).substring(2, 15);
    }

    // Determine traffic source, UTM parameters, and Ad Click IDs
    var urlParams   = new URLSearchParams(window.location.search);
    var srcParam    = urlParams.get('src') || urlParams.get('ref') || '';
    var utmSource   = urlParams.get('utm_source') || '';
    var utmMedium   = urlParams.get('utm_medium') || '';
    var utmCampaign = urlParams.get('utm_campaign') || '';
    var utmContent  = urlParams.get('utm_content') || '';
    var clickId     = urlParams.get('gclid') || urlParams.get('fbclid') || urlParams.get('ttclid') || '';

    // Cache attribution parameters across sessions/tabs
    try {
        if (utmSource) sessionStorage.setItem('optibiz_utm_source', utmSource);
        else utmSource = sessionStorage.getItem('optibiz_utm_source') || '';

        if (utmMedium) sessionStorage.setItem('optibiz_utm_medium', utmMedium);
        else utmMedium = sessionStorage.getItem('optibiz_utm_medium') || '';

        if (utmCampaign) sessionStorage.setItem('optibiz_utm_campaign', utmCampaign);
        else utmCampaign = sessionStorage.getItem('optibiz_utm_campaign') || '';

        if (utmContent) sessionStorage.setItem('optibiz_utm_content', utmContent);
        else utmContent = sessionStorage.getItem('optibiz_utm_content') || '';

        if (clickId) sessionStorage.setItem('optibiz_click_id', clickId);
        else clickId = sessionStorage.getItem('optibiz_click_id') || '';
    } catch(e) {}

    var trafficSource = 'direct';
    if (srcParam === 'qr') trafficSource = 'qr';
    else if (srcParam === 'wa' || urlParams.get('invite')) trafficSource = 'whatsapp_invite';
    else if (srcParam === 'widget') trafficSource = 'widget';
    else if (utmSource) trafficSource = utmSource.toLowerCase();
    else if (urlParams.get('gclid')) trafficSource = 'google_ads';
    else if (urlParams.get('fbclid')) trafficSource = 'meta_ads';
    else if (urlParams.get('ttclid')) trafficSource = 'tiktok_ads';
    else if (referrer && referrer.indexOf(location.hostname) === -1) trafficSource = 'external';

    window.trackOptibizEvent = function(eventType, category, label) {
        var data = {
            company_id: companyId,
            tenant_id: tenantId,
            event_type: eventType,
            event_category: category || '',
            event_label: label || '',
            traffic_source: trafficSource,
            utm_source: utmSource,
            utm_medium: utmMedium,
            utm_campaign: utmCampaign,
            utm_content: utmContent,
            click_id: clickId,
            page_url: pageUrl,
            referrer: referrer,
            session_id: sessId
        };

        // Fire client-side pixel conversion events
        if (eventType === 'whatsapp_click') {
            if (typeof fbq === 'function') {
                try { fbq('track', 'Contact', { content_name: 'WhatsApp Inquiry', company_id: companyId }); } catch(e) {}
            }
            if (typeof gtag === 'function') {
                try { gtag('event', 'conversion', { 'event_category': 'WhatsApp', 'event_label': label || 'Inquiry' }); } catch(e) {}
            }
            if (typeof ttq === 'object' && typeof ttq.track === 'function') {
                try { ttq.track('Contact'); } catch(e) {}
            }
        } else if (eventType === 'review_submit') {
            if (typeof fbq === 'function') {
                try { fbq('track', 'CompleteRegistration', { content_name: 'Customer Review' }); } catch(e) {}
            }
            if (typeof gtag === 'function') {
                try { gtag('event', 'conversion', { 'event_category': 'Review', 'event_label': 'Submitted' }); } catch(e) {}
            }
        } else if (eventType === 'map_directions_click') {
            if (typeof fbq === 'function') {
                try { fbq('track', 'FindLocation'); } catch(e) {}
            }
        }

        var endpoint = '../api/submit_event.php';
        if (navigator.sendBeacon) {
            var blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
            navigator.sendBeacon(endpoint, blob);
        } else {
            try {
                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data),
                    keepalive: true
                }).catch(function() {});
            } catch(e) {}
        }
    };

    // Log Page View
    trackOptibizEvent('page_view', 'rating_page', document.title);
    if (trafficSource === 'qr') {
        trackOptibizEvent('qr_scan', 'qr_stand', 'Counter QR Stand Scan');
    }

    // Bind WhatsApp click tracking & form interaction
    function bindTelemetry() {
        // Floating button
        var floatWa = document.querySelector('.rt-floating-wa');
        if (floatWa) {
            floatWa.addEventListener('click', function() {
                trackOptibizEvent('whatsapp_click', 'floating_button', 'Floating Sticky WhatsApp');
            });
        }
        // Review & Q&A WhatsApp buttons
        document.querySelectorAll('.rt-review-wa-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var isQa = btn.closest('#panel-qa');
                var cat = isQa ? 'qa_inquiry' : 'review_inquiry';
                trackOptibizEvent('whatsapp_click', cat, btn.getAttribute('title') || 'Inquire on WhatsApp');
            });
        });
        // Social media profile clicks
        document.querySelectorAll('.rt-social-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var net = btn.getAttribute('data-network') || 'social';
                var target = btn.getAttribute('href') || '';
                trackOptibizEvent('social_click', net, target);
            });
        });
        // Map directions click
        document.querySelectorAll('.rt-map-direction-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                trackOptibizEvent('map_directions_click', 'google_maps', btn.getAttribute('href') || 'directions');
            });
        });
        // Footer official website link click
        document.querySelectorAll('.rt-footer-web-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                trackOptibizEvent('website_click', 'footer_button', btn.getAttribute('href') || 'website');
            });
        });
        // Form interaction tracking (form_start)
        var formStarted = false;
        function onFormInteract() {
            if (!formStarted) {
                formStarted = true;
                trackOptibizEvent('form_start', 'review_form', 'User Started Review');
            }
        }
        document.querySelectorAll('input[name="rating"], textarea, input[name="customer_name"]').forEach(function(el) {
            el.addEventListener('focus', onFormInteract, { once: true });
            el.addEventListener('change', onFormInteract, { once: true });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindTelemetry);
    } else {
        bindTelemetry();
    }
})();
</script>

<?php if ($whatsapp_url !== ''): ?>
<!-- Sticky Floating WhatsApp Chat Button -->
<a href="<?php echo htmlspecialchars($whatsapp_url); ?>" class="rt-floating-wa" target="_blank" rel="noopener noreferrer" title="Chat with <?php echo htmlspecialchars($brand_name); ?> on WhatsApp" aria-label="Chat with <?php echo htmlspecialchars($brand_name); ?> on WhatsApp">
    <svg viewBox="0 0 448 512" aria-hidden="true"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
    <span>Chat on WhatsApp</span>
</a>
<?php endif; ?>

</body>
</html>