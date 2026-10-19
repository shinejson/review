<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo.php';

// Dynamic platform metrics
$total_ratings = (int) ($conn->query("SELECT COUNT(*) as count FROM ratings WHERE reported = 0")->fetch_assoc()['count'] ?? 0);
$total_customers = (int) ($conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'] ?? 0);
$avg_platform_rating = (float) ($conn->query("SELECT AVG(rating) as avg FROM ratings WHERE reported = 0")->fetch_assoc()['avg'] ?? 4.9);
$total_events = (int) ($conn->query("SELECT COUNT(*) as count FROM analytics_events")->fetch_assoc()['count'] ?? 0);

// Fetch Top Rated Featured Companies
$featured_companies = [];
$res_feat = $conn->query(
    "SELECT c.id, c.company_name, cat.name AS category_name,
            (SELECT COUNT(*) FROM ratings r WHERE r.company_id = c.id AND r.reported = 0) AS review_count,
            (SELECT AVG(r.rating) FROM ratings r WHERE r.company_id = c.id AND r.reported = 0) AS avg_score
       FROM customers c
       LEFT JOIN categories cat ON c.category_id = cat.id
      ORDER BY review_count DESC, avg_score DESC
      LIMIT 6"
);
if ($res_feat) {
    while ($fc = $res_feat->fetch_assoc()) {
        $featured_companies[] = $fc;
    }
}

// Fetch Recent Verified Reviews
$recent_reviews = [];
$res_rev = $conn->query(
    "SELECT r.*, c.company_name
       FROM ratings r
       JOIN customers c ON r.company_id = c.id
      WHERE r.reported = 0 AND r.rating >= 4
      ORDER BY r.created_at DESC
      LIMIT 4"
);
if ($res_rev) {
    while ($rv = $res_rev->fetch_assoc()) {
        $recent_reviews[] = $rv;
    }
}

// Hero "Live Interactive Preview" — use a real top-rated tenant with verified reviews when available,
// otherwise fall back to the static demo so the hero always looks polished for traffic.
$hero_demo_name   = 'Acme Bistro & Cafe';
$hero_demo_avatar = 'A';
$hero_demo_rating = 5;
$res_demo = $conn->query(
    "SELECT c.company_name,
            (SELECT COUNT(*) FROM ratings r WHERE r.company_id = c.id AND r.reported = 0) AS review_count,
            (SELECT AVG(r.rating) FROM ratings r WHERE r.company_id = c.id AND r.reported = 0) AS avg_score
       FROM customers c
      ORDER BY review_count DESC, avg_score DESC
      LIMIT 1"
);
if ($res_demo && $row_demo = $res_demo->fetch_assoc()) {
    $real_demo_name = trim((string)($row_demo['company_name'] ?? ''));
    if ($real_demo_name !== '' && (int)$row_demo['review_count'] > 0) {
        $hero_demo_name   = $real_demo_name;
        $hero_demo_avatar = strtoupper(mb_substr($real_demo_name, 0, 1));
        $real_avg = (float)$row_demo['avg_score'];
        if ($real_avg > 0) {
            $hero_demo_rating = max(1, min(5, (int)round($real_avg)));
        }
    }
}

// Data for the "Get Started" quote modal
$modal_categories = [];
$category_result = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($category_result) {
    while ($row = $category_result->fetch_assoc()) {
        $modal_categories[] = $row;
    }
}

$modal_plans = [];
$chk_plan = @$conn->query("SHOW COLUMNS FROM subscription_plans LIKE 'annual_discount_percent'");
if ($chk_plan && $chk_plan->num_rows === 0) {
    @$conn->query("ALTER TABLE subscription_plans ADD COLUMN annual_discount_percent TINYINT(3) DEFAULT 0 COMMENT 'Discount percentage for annual billing (0-100)' AFTER features");
}
$plan_result = $conn->query("SELECT id, plan_name, price, annual_discount_percent FROM subscription_plans WHERE status = 'active' ORDER BY price ASC");
if ($plan_result) {
    while ($row = $plan_result->fetch_assoc()) {
        $modal_plans[] = $row;
    }
}

// ---------------------------------------------------------------------------
// SEO metadata  (see includes/seo.php — the shared helper renders the
// canonical / Open Graph / Twitter / JSON-LD block for every public page)
// ---------------------------------------------------------------------------
$seo_title = 'Optibiz — All-in-One Review, Trust & Reputation Growth Platform';
$seo_title_with_icon = '<i class="fa-solid fa-star-half-stroke"></i> Optibiz — All-in-One Review, Trust & Reputation Growth Platform';
$seo_desc  = 'Collect more customer reviews with QR counter stands, WhatsApp requests and Google review boosting. Optibiz turns 5-star feedback into social proof.';

// Cheapest active plan drives the "offers" price in the SoftwareApplication
// schema. price is selected with ORDER BY price ASC above, so the first row
// is the entry price; we still scan defensively in case ordering changes.
$seo_price = null;
foreach ($modal_plans as $plan) {
    $candidate = (float) ($plan['price'] ?? 0);
    if ($seo_price === null || $candidate < $seo_price) {
        $seo_price = $candidate;
    }
}

$seo_currency = function_exists('sa_setting') ? sa_setting($conn, 'currency_code', 'USD') : 'USD';
if ($seo_currency === '') {
    $seo_currency = 'USD';
}

// --- FAQ content ------------------------------------------------------------
// Single source of truth: rendered to the page AND emitted as FAQPage JSON-LD,
// so the copy and the structured data can never drift apart.
$seo_faqs = array(
    array(
        'q' => 'What is Optibiz?',
        'a' => 'Optibiz is a review and reputation platform for businesses. It collects verified customer ratings at the point of service with printable Counter QR Stands, follows up over WhatsApp, and routes happy customers to your Google Business Profile so good feedback becomes public search visibility.',
    ),
    array(
        'q' => 'How does the printable Counter QR Stand work?',
        'a' => 'Generate a QR stand for your counter, table or reception desk from your workspace, print it, and place it where customers finish their visit. A quick scan opens a one-tap rating card in the customer\'s phone browser — no app install and no sign-up. Every scan is logged so you can see how many customers engaged.',
    ),
    array(
        'q' => 'Will Optibiz help me get more Google reviews?',
        'a' => 'Yes. Ratings of four stars and above are automatically routed to your Google review link, while lower scores are captured privately so your team can resolve the issue before it reaches a public platform. This review-gating pattern raises both the volume and the average of your public ratings.',
    ),
    array(
        'q' => 'What happens when a customer leaves a low rating?',
        'a' => 'Low scores trigger the private feedback shield. The customer is asked what went wrong and the response is delivered straight to the manager, so you can recover the relationship in private instead of wearing a public one-star review.',
    ),
    array(
        'q' => 'Do my customers need to download an app?',
        'a' => 'No. Rating pages open in any mobile browser, and WhatsApp requests are just a normal chat link. There is nothing for your customers to install, which keeps the completion rate high.',
    ),
    array(
        'q' => 'How much does Optibiz cost?',
        'a' => 'Optibiz is sold as a monthly subscription with several plan tiers, and annual billing is discounted on selected plans. Every new workspace starts with a free trial — see the pricing page for current rates in your currency.',
    ),
);

// --- JSON-LD nodes ----------------------------------------------------------
$seo_app_node = array(
    '@type'                 => 'SoftwareApplication',
    '@id'                   => seo_url('') . '#software',
    'name'                  => 'Optibiz',
    'applicationCategory'   => 'BusinessApplication',
    'applicationSubCategory' => 'Reputation Management Software',
    'operatingSystem'       => 'Web browser (desktop and mobile)',
    'url'                   => seo_url(''),
    'description'           => 'Collect verified customer reviews with printable Counter QR Stands and WhatsApp requests, boost Google reviews, and turn five-star feedback into social proof graphics.',
    'publisher'             => array('@id' => seo_url('') . '#organization'),
    'inLanguage'            => 'en-GH',
    'featureList'           => array(
        'Printable Counter QR Stands',
        'WhatsApp 1-Click Review Requester',
        'Google Review Booster',
        'Social Proof Card Studio',
        'Public Community Q&A',
        'Paid Ad Funnel & Retargeting',
    ),
);

if ($seo_price !== null) {
    $seo_app_node['offers'] = array(
        '@type'         => 'Offer',
        'price'         => rtrim(rtrim(number_format($seo_price, 2, '.', ''), '0'), '.'),
        'priceCurrency' => $seo_currency,
        'availability'  => 'https://schema.org/InStock',
        'url'           => seo_url('pricing.php'),
    );
}

$seo_faq_entities = array();
foreach ($seo_faqs as $faq) {
    $seo_faq_entities[] = array(
        '@type'          => 'Question',
        'name'           => $faq['q'],
        'acceptedAnswer' => array(
            '@type' => 'Answer',
            'text'  => $faq['a'],
        ),
    );
}
$seo_faq_node = array(
    '@type'          => 'FAQPage',
    '@id'            => seo_url('') . '#faq',
    'mainEntity'     => $seo_faq_entities,
);

?>
<!DOCTYPE html>
<html lang="en-GH">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($seo_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($seo_desc, ENT_QUOTES, 'UTF-8'); ?>">

    <?php seo_head(array(
        'title'       => $seo_title,
        'description' => $seo_desc,
        'path'        => '',
        'jsonld'      => array($seo_app_node, $seo_faq_node),
    )); ?>

    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-dark: #0f2438;
            --primary-light: #163652;
            --accent-lime: #c2f542;
            --accent-lime-hover: #a8e030;
            --card-dark: #1a3852;
            --accent-green-bg: #ecfccb;
            --accent-green-text: #4d7c0f;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-line: #e2e8f0;
            --star-gold: #f59e0b;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: var(--text-main);
            background: #ffffff;
            overflow-x: hidden;
            line-height: 1.5;
        }

        /* Top Bar & Navigation */
        .top-bar-wrap {
            background: var(--primary-dark);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        .navbar {
            max-width: 1280px;
            margin: 0 auto;
            padding: 16px 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
            font-size: 24px;
            font-weight: 800;
            text-decoration: none;
            letter-spacing: -0.5px;
            flex-shrink: 0;
        }
        .logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--accent-lime);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .nav-pill {
            display: flex;
            align-items: center;
            gap: 22px;
        }
        .nav-pill a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.25s ease;
            padding: 6px 0;
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .nav-pill a i {
            font-size: 13.5px;
            color: #94a3b8;
            transition: color 0.25s ease, transform 0.2s ease;
        }
        .nav-pill a:hover {
            color: var(--accent-lime);
        }
        .nav-pill a:hover i {
            color: var(--accent-lime);
            transform: translateY(-1px);
        }
        .nav-pill a.active {
            color: #ffffff;
        }
        .nav-pill a.active i {
            color: var(--accent-lime);
        }
        .nav-pill a.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--accent-lime);
            border-radius: 2px;
        }
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }
        .btn-signin {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.2s;
            white-space: nowrap;
        }
        .btn-signin:hover {
            color: #ffffff;
        }
        .btn-quote {
            background: var(--accent-lime);
            color: var(--primary-dark);
            text-decoration: none;
            padding: 10px 22px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            white-space: nowrap;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(194, 245, 66, 0.2);
        }
        .btn-quote:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(194, 245, 66, 0.35);
        }

        /* Mobile Hamburger Button */
        .mobile-nav-toggle {
            display: none;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 9px;
            width: 40px;
            height: 40px;
            padding: 0;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            cursor: pointer;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .mobile-nav-toggle:hover {
            background: rgba(255, 255, 255, 0.16);
            border-color: rgba(194, 245, 66, 0.5);
        }
        .mobile-nav-toggle .hamburger-line {
            width: 20px;
            height: 2px;
            background: #ffffff;
            border-radius: 2px;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.2s ease, background-color 0.2s ease;
            display: block;
            pointer-events: none;
        }
        .mobile-nav-toggle.is-active {
            background: rgba(194, 245, 66, 0.15);
            border-color: var(--accent-lime);
        }
        .mobile-nav-toggle.is-active .hamburger-line:nth-child(1) {
            transform: translateY(7px) rotate(45deg);
            background: var(--accent-lime);
        }
        .mobile-nav-toggle.is-active .hamburger-line:nth-child(2) {
            opacity: 0;
            transform: scaleX(0);
        }
        .mobile-nav-toggle.is-active .hamburger-line:nth-child(3) {
            transform: translateY(-7px) rotate(-45deg);
            background: var(--accent-lime);
        }

        /* Mobile Drawer & Overlay */
        .mobile-nav-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(10, 24, 38, 0.65);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 98;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }
        .mobile-nav-overlay.is-open {
            opacity: 1;
            pointer-events: auto;
        }

        .mobile-nav-drawer {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: linear-gradient(180deg, #0f2438 0%, #163652 100%);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            border-bottom: 2px solid rgba(194, 245, 66, 0.35);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            max-height: calc(100vh - 65px);
            overflow-y: auto;
            opacity: 0;
            transform: translateY(-8px);
            pointer-events: none;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.22s ease;
        }
        .mobile-nav-drawer.is-open {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .mobile-nav-inner {
            padding: 16px 5% 22px;
            max-width: 600px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .mobile-nav-links {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .mobile-nav-link {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 12px 14px;
            border-radius: 10px;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .mobile-nav-link i {
            width: 22px;
            text-align: center;
            font-size: 16px;
            color: #94a3b8;
            transition: color 0.2s ease;
        }
        .mobile-nav-link:hover,
        .mobile-nav-link:active {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
        }
        .mobile-nav-link:hover i,
        .mobile-nav-link:active i {
            color: var(--accent-lime);
        }
        .mobile-nav-link.active {
            color: #ffffff;
            background: rgba(194, 245, 66, 0.12);
            border: 1px solid rgba(194, 245, 66, 0.25);
        }
        .mobile-nav-link.active i {
            color: var(--accent-lime);
        }
        .mobile-nav-divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 10px 0 14px;
        }
        .mobile-nav-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .mobile-btn-signin {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #f1f5f9;
            text-decoration: none;
            font-size: 14.5px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .mobile-btn-signin:hover {
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.3);
        }
        .mobile-btn-quote {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            padding: 13px 18px;
            border-radius: 10px;
            background: var(--accent-lime);
            color: var(--primary-dark);
            border: none;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(194, 245, 66, 0.3);
            transition: all 0.2s ease;
        }
        .mobile-btn-quote:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(194, 245, 66, 0.4);
        }
        .mobile-nav-footer {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin-top: 14px;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }
        .mobile-nav-footer i {
            color: var(--accent-lime);
        }

        body.mobile-nav-open {
            overflow: hidden !important;
        }

        /* Top Bar Responsive Breakpoints */
        @media (max-width: 991px) {
            .navbar {
                padding: 12px 5%;
            }
            .nav-pill {
                display: none !important;
            }
            .nav-actions .btn-signin {
                display: none !important;
            }
            .mobile-nav-toggle {
                display: flex;
            }
            .mobile-nav-overlay {
                display: block;
            }
            .mobile-nav-drawer {
                display: block;
            }
            .btn-quote {
                padding: 8px 16px;
                font-size: 13px;
                border-radius: 24px;
            }
        }
        @media (max-width: 520px) {
            .navbar {
                padding: 10px 14px;
                gap: 10px;
            }
            .logo {
                font-size: 20px;
                gap: 8px;
            }
            .logo-icon {
                width: 33px;
                height: 33px;
                font-size: 14px;
                border-radius: 8px;
            }
            .nav-actions {
                gap: 8px;
            }
            .btn-quote {
                padding: 7px 12px;
                font-size: 12.5px;
                gap: 5px;
            }
            .mobile-nav-toggle {
                width: 36px;
                height: 36px;
                border-radius: 8px;
            }
            .mobile-nav-toggle .hamburger-line {
                width: 18px;
            }
        }
        @media (max-width: 360px) {
            .navbar {
                padding: 8px 10px;
            }
            .logo {
                font-size: 18px;
            }
            .btn-quote {
                padding: 6px 9px;
                font-size: 11.5px;
            }
            .btn-quote i {
                display: none;
            }
        }

        /* Hero Section */
        .hero-section {
            background: radial-gradient(circle at 80% 20%, #1a3c5a 0%, var(--primary-dark) 70%);
            padding: 70px 5% 90px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
        }
        .hero-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.15fr 0.95fr;
            gap: 60px;
            align-items: center;
        }
        .badge-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(194, 245, 66, 0.12);
            color: var(--accent-lime);
            border: 1px solid rgba(194, 245, 66, 0.3);
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 20px;
        }
        .hero-title {
            font-size: 48px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -1.2px;
            margin-bottom: 20px;
        }
        .hero-title span {
            color: var(--accent-lime);
        }
        .hero-desc {
            font-size: 17px;
            color: #cbd5e1;
            line-height: 1.6;
            margin-bottom: 34px;
            max-width: 580px;
        }
        .hero-actions {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        .btn-lime {
            background: var(--accent-lime);
            color: var(--primary-dark);
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 30px;
            font-size: 15px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 15px rgba(194, 245, 66, 0.25);
            border: none;
            cursor: pointer;
        }
        .btn-lime:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(194, 245, 66, 0.35);
        }
        .btn-ghost-light {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            text-decoration: none;
            padding: 14px 24px;
            border-radius: 30px;
            font-size: 15px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.2s;
        }
        .btn-ghost-light:hover {
            background: rgba(255, 255, 255, 0.16);
            border-color: #ffffff;
        }

        .hero-proof {
            display: flex;
            align-items: center;
            gap: 40px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        .rating-block {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .stars-row {
            color: var(--star-gold);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        .rating-number-wrap {
            display: flex;
            align-items: baseline;
            gap: 10px;
        }
        .big-rating {
            font-size: 34px;
            font-weight: 800;
            color: #ffffff;
        }
        .rating-text {
            font-size: 12px;
            color: #cbd5e1;
            line-height: 1.35;
        }

        /* Hero Interactive Live Demo Card */
        .hero-demo-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 30px;
            color: var(--text-main);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
        }
        .demo-badge-top {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 10px;
            border-radius: 999px;
            margin-bottom: 14px;
        }
        .demo-biz-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border-line);
        }
        .demo-biz-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--primary-dark);
            color: var(--accent-lime);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
        }
        .demo-biz-info .demo-biz-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
        }
        .demo-biz-info p {
            font-size: 12px;
            color: var(--text-muted);
        }
        .demo-question {
            font-size: 15px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 12px;
        }
        .interactive-stars {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 18px;
        }
        .star-btn {
            background: transparent;
            border: none;
            font-size: 32px;
            color: #cbd5e1;
            cursor: pointer;
            transition: all 0.15s;
        }
        .star-btn:hover, .star-btn.active {
            color: var(--star-gold);
            transform: scale(1.15);
        }
        .demo-feedback-box {
            background: #f8fafc;
            border: 1px solid var(--border-line);
            border-radius: 12px;
            padding: 16px;
            font-size: 13px;
            min-height: 95px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            transition: all 0.3s;
        }
        .routing-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
            font-size: 12px;
            margin-bottom: 6px;
        }
        .routing-pill.google { color: #16a34a; }
        .routing-pill.private { color: #ea580c; }

        /* Hero Carousel / Slider */
        .hero-slider-container {
            position: relative;
            width: 100%;
            max-width: 520px;
            margin: 0 auto;
        }
        .hero-slider-track-wrap {
            position: relative;
            width: 100%;
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 25px 60px -10px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: #ffffff;
        }
        .hero-slide {
            display: none;
            width: 100%;
            animation: heroFadeSlide 0.45s ease-in-out forwards;
        }
        .hero-slide.active {
            display: block;
        }
        @keyframes heroFadeSlide {
            from {
                opacity: 0;
                transform: scale(0.985);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        .hero-slide .hero-demo-card {
            box-shadow: none;
            border: none;
            border-radius: 0;
        }
        .hero-slide-img-card {
            position: relative;
            background: #0f172a;
            overflow: hidden;
            height: 380px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hero-slide-img-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.6s ease;
        }
        .hero-slide-img-card:hover img {
            transform: scale(1.04);
        }
        .hero-slide-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.15) 0%, rgba(15, 23, 42, 0.85) 100%);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 24px 26px;
            color: #ffffff;
            pointer-events: none;
        }
        .hero-slide-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(194, 245, 66, 0.2);
            color: var(--accent-lime);
            border: 1px solid rgba(194, 245, 66, 0.4);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 4px 10px;
            border-radius: 999px;
            width: fit-content;
            margin-bottom: 10px;
            backdrop-filter: blur(4px);
        }
        .hero-slide-title {
            font-size: 20px;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 6px;
            line-height: 1.3;
            text-shadow: 0 2px 8px rgba(0,0,0,0.4);
        }
        .hero-slide-desc {
            font-size: 13px;
            color: #e2e8f0;
            margin: 0;
            line-height: 1.45;
            text-shadow: 0 1px 4px rgba(0,0,0,0.5);
        }
        .hero-slide-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            background: rgba(15, 36, 56, 0.88);
            border: 1px solid rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(6px);
            color: #ffffff;
            padding: 8px 14px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            box-shadow: 0 6px 18px rgba(0,0,0,0.3);
            pointer-events: none;
        }
        .hero-slide-badge i {
            color: var(--star-gold);
        }

        /* Slider Controls (Arrows & Dots) */
        .hero-slider-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 16px;
            padding: 0 4px;
        }
        .slider-arrow-btn {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.22);
            color: #ffffff;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .slider-arrow-btn:hover {
            background: var(--accent-lime);
            color: var(--primary-dark);
            border-color: var(--accent-lime);
            transform: scale(1.08);
        }
        .hero-slider-dots {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hero-slider-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.3);
            border: none;
            padding: 0;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .hero-slider-dot.active {
            width: 26px;
            background: var(--accent-lime);
        }

        /* Trust Stats Bar */
        .trust-bar {
            background: #ffffff;
            padding: 40px 5%;
            border-bottom: 1px solid var(--border-line);
        }
        .trust-grid {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
        }
        .trust-stat {
            display: flex;
            flex-direction: column;
            gap: 4px;
            border-left: 3px solid var(--accent-lime);
            padding-left: 18px;
        }
        .trust-num {
            font-size: 36px;
            font-weight: 800;
            color: var(--primary-dark);
            line-height: 1.1;
        }
        .trust-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }

        /* Features 6-Pillar Section */
        .features-section {
            padding: 90px 5%;
            background: #f8fafc;
        }
        .section-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 60px;
        }
        .section-tag {
            display: inline-block;
            background: var(--accent-green-bg);
            color: var(--accent-green-text);
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
        }
        .section-title {
            font-size: 38px;
            font-weight: 800;
            color: var(--primary-dark);
            letter-spacing: -0.75px;
            margin-bottom: 16px;
            line-height: 1.2;
        }
        .section-subtitle {
            font-size: 16px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .features-grid {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
        }
        .feature-card {
            background: #ffffff;
            border: 1px solid var(--border-line);
            border-radius: 18px;
            padding: 34px 28px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .feature-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 32px rgba(15, 36, 56, 0.08);
            border-color: #cbd5e1;
        }
        .f-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: var(--primary-dark);
            color: var(--accent-lime);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 22px;
        }
        .feature-card h3 {
            font-size: 19px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 12px;
        }
        .feature-card p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 20px;
            flex: 1;
        }
        .f-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: var(--primary-dark);
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 6px;
            align-self: flex-start;
        }

        /* 3-Step How It Works */
        .how-section {
            padding: 90px 5%;
            background: #ffffff;
        }
        .how-grid {
            max-width: 1160px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            position: relative;
        }
        .how-step {
            text-align: center;
            padding: 20px;
        }
        .how-num {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--accent-lime);
            color: var(--primary-dark);
            font-size: 22px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 4px 16px rgba(194, 245, 66, 0.4);
        }
        .how-step h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 10px;
        }
        .how-step p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* Featured Companies Showcase */
        .directory-preview {
            padding: 90px 5%;
            background: #f8fafc;
        }
        .companies-grid {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
        }
        .company-preview-card {
            background: #ffffff;
            border: 1px solid var(--border-line);
            border-radius: 16px;
            padding: 24px;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
            transition: all 0.25s;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .company-preview-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
            border-color: var(--accent-lime);
        }
        .c-card-top {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }
        .c-card-logo {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: var(--primary-dark);
            color: var(--accent-lime);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 18px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .c-card-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .c-card-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-dark);
        }
        .c-card-cat {
            font-size: 12px;
            color: var(--text-muted);
        }
        .c-card-stats {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 14px;
            border-top: 1px solid var(--border-line);
            font-size: 13px;
        }
        .c-stars {
            color: var(--star-gold);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Testimonials / Recent Reviews */
        .reviews-section {
            padding: 90px 5%;
            background: #ffffff;
        }
        .reviews-grid {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
        }
        .review-quote-card {
            background: #f8fafc;
            border: 1px solid var(--border-line);
            border-radius: 14px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .rq-stars {
            color: var(--star-gold);
            font-size: 13px;
            margin-bottom: 10px;
        }
        .rq-comment {
            font-size: 13.5px;
            color: #334155;
            line-height: 1.55;
            margin-bottom: 16px;
            flex: 1;
        }
        .rq-author {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            border-top: 1px solid var(--border-line);
            padding-top: 10px;
        }
        .rq-company {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 10px;
        }
        .rq-company i {
            color: #65a30d;
            font-size: 13px;
        }
        .rq-author strong {
            color: var(--primary-dark);
        }
        .rq-author span {
            color: #16a34a;
            font-weight: 600;
        }

        /* Banner CTA */
        .cta-banner {
            background: var(--primary-dark);
            color: #ffffff;
            padding: 80px 5%;
            text-align: center;
            position: relative;
        }
        .cta-banner h2 {
            font-size: 38px;
            font-weight: 800;
            margin-bottom: 16px;
        }
        .cta-banner p {
            font-size: 17px;
            color: #cbd5e1;
            max-width: 600px;
            margin: 0 auto 30px;
        }

        /* Footer */
        .site-footer {
            background: #091826;
            color: #94a3b8;
            padding: 70px 5% 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .footer-grid-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 1fr;
            gap: 50px;
            margin-bottom: 50px;
        }
        .footer-brand-desc {
            font-size: 13.5px;
            line-height: 1.6;
            margin: 18px 0;
            max-width: 320px;
        }
        .footer-col-title {
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 18px;
        }
        .footer-links-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .footer-links-list a,
        .footer-links-list .footer-link-button {
            color: #94a3b8;
            text-decoration: none;
            font-size: 13.5px;
            transition: color 0.2s;
        }
        .footer-links-list a:hover,
        .footer-links-list .footer-link-button:hover {
            color: var(--accent-lime);
        }
        /* A real <button> styled as a footer link: it opens a dialog, so it is
           not a navigation and should not pretend to be one (no href="#"). */
        .footer-links-list .footer-link-button {
            background: none;
            border: 0;
            padding: 0;
            font-family: inherit;
            font-size: 13.5px;
            text-align: left;
            cursor: pointer;
        }
        .footer-bottom {
            max-width: 1280px;
            margin: 0 auto;
            padding-top: 26px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12.5px;
            flex-wrap: wrap;
            gap: 14px;
        }

        /* Modal Styles (Quotes / Onboarding) */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: #ffffff;
            width: 100%;
            max-width: 640px;
            border-radius: 20px;
            padding: 40px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
        }
        .modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: transparent;
            border: none;
            font-size: 26px;
            color: #94a3b8;
            cursor: pointer;
        }
        .progress-steps {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .step-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }
        .step {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }
        .step-wrap.active .step {
            background: var(--accent-lime);
            color: var(--primary-dark);
        }
        .step-wrap.completed .step {
            background: #a3e635;
            color: #1e293b;
        }
        .step-label {
            font-size: 11.5px;
            font-weight: 600;
            color: #94a3b8;
        }
        .step-line {
            flex: 1;
            height: 2px;
            background: #e2e8f0;
            margin: 0 10px;
        }
        .step-line.active {
            background: var(--accent-lime);
        }
        .form-section { display: none; }
        .form-section.active { display: block; }
        .form-section h3 {
            font-size: 20px;
            margin-bottom: 6px;
        }
        .form-section .subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 22px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #334155;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-line);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--primary-dark);
            outline: none;
        }
        .form-group select option {
            font-size: 13px;
            line-height: 1.4;
        }
        .form-group select option small {
            display: block;
            color: #15803d;
            font-weight: 600;
            font-size: 11px;
            margin-top: 2px;
        }
        .form-alert {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .form-alert-duplicate {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fcd34d;
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 13.5px;
            margin-bottom: 16px;
            line-height: 1.55;
        }
        .form-alert-duplicate strong {
            display: block;
            font-size: 14px;
            margin-bottom: 4px;
            color: #78350f;
        }
        .form-alert-duplicate .dup-ref {
            font-family: monospace;
            font-size: 12.5px;
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 6px;
            padding: 3px 8px;
            display: inline-block;
            margin-top: 6px;
        }
        .form-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-top: 20px;
        }
        /* Level 1 shows only Continue; pin it to the right side */
        #quoteNextBtn {
            margin-left: auto;
        }
        /* Ensure the `hidden` attribute actually hides buttons whose
           classes set a `display` value (e.g. .btn-lime display:inline-flex) */
        .form-nav button[hidden] {
            display: none !important;
        }
        .btn-back {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-cancel {
            background: transparent;
            border: 1px solid var(--border-line);
            color: var(--text-muted);
            border-radius: 8px;
            padding: 10px 18px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            transition: border-color .15s ease, color .15s ease;
        }
        .btn-cancel:hover {
            border-color: #cbd5e1;
            color: #475569;
        }
        .quote-success {
            text-align: center;
            padding: 30px 10px;
        }
        .quote-success-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: var(--accent-green-bg);
            color: var(--accent-green-text);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 20px;
        }

        @media (max-width: 991px) {
            .hero-container { grid-template-columns: 1fr; }
            .features-grid, .trust-grid, .companies-grid, .reviews-grid, .footer-grid-container {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 640px) {
            .features-grid, .trust-grid, .companies-grid, .reviews-grid, .footer-grid-container, .how-grid {
                grid-template-columns: 1fr;
            }
            .hero-title { font-size: 34px; }
            .form-row { grid-template-columns: 1fr; }
        }

        /* Trusted Companies Section */
        .trusted-section {
            padding: 80px 5%;
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%);
        }
        .trusted-section .section-header {
            text-align: center;
            margin-bottom: 50px;
        }
        .trusted-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 30px;
            max-width: 1280px;
            margin: 0 auto;
        }
        .trusted-logo {
            background: white;
            padding: 25px 20px;
            border-radius: 12px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-line);
        }
        .trusted-logo:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: var(--accent-lime);
        }
        .trusted-logo-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 700;
            color: white;
            margin-bottom: 12px;
        }
        .trusted-logo-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
            line-height: 1.3;
        }

        /* Our Team Section */
        .team-section {
            padding: 80px 5%;
            background: var(--primary-dark);
            color: white;
        }
        .team-section .section-header {
            text-align: center;
            margin-bottom: 50px;
        }
        .team-section .section-tag {
            color: var(--accent-lime);
        }
        .team-section .section-title {
            color: white;
        }
        .team-section .section-subtitle {
            color: #94a3b8;
        }
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 30px;
            max-width: 1280px;
            margin: 0 auto;
        }
        .team-card {
            background: rgba(255, 255, 255, 0.05);
            padding: 30px 20px;
            border-radius: 12px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .team-card:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-4px);
            border-color: var(--accent-lime);
        }
        .team-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--accent-lime);
            color: var(--primary-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 16px;
            box-shadow: 0 4px 15px rgba(194, 245, 66, 0.3);
        }
        .team-name {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .team-role {
            font-size: 13px;
            color: var(--accent-lime);
            margin-bottom: 12px;
        }
        .team-bio {
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.5;
        }
        .team-social {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 15px;
        }
        .team-social a {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .team-social a:hover {
            background: var(--accent-lime);
            color: var(--primary-dark);
        }

        /* Skip link — keyboard/screen-reader users jump straight to the content */
        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            z-index: 200;
            background: var(--accent-lime);
            color: var(--primary-dark);
            font-weight: 700;
            padding: 12px 20px;
            border-radius: 0 0 10px 0;
            text-decoration: none;
        }
        .skip-link:focus {
            left: 0;
        }

        /* FAQ Section — native <details> so it stays crawlable without JS */
        .faq-section {
            padding: 80px 5%;
            background: #f8fafc;
        }
        .faq-list {
            max-width: 860px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .faq-item {
            background: #ffffff;
            border: 1px solid var(--border-line);
            border-radius: 12px;
            overflow: hidden;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .faq-item:hover {
            border-color: var(--accent-lime);
        }
        .faq-item[open] {
            border-color: var(--accent-lime);
            box-shadow: 0 8px 24px rgba(15, 36, 56, 0.07);
        }
        .faq-item summary {
            list-style: none;
            cursor: pointer;
            padding: 20px 56px 20px 22px;
            position: relative;
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-dark);
            line-height: 1.45;
        }
        .faq-item summary::-webkit-details-marker {
            display: none;
        }
        .faq-item summary::after {
            content: "\f107";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 22px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-green-text);
            transition: transform 0.25s ease;
            font-size: 15px;
        }
        .faq-item[open] summary::after {
            transform: translateY(-50%) rotate(180deg);
        }
        .faq-item summary:focus-visible {
            outline: 3px solid var(--accent-lime);
            outline-offset: -3px;
        }
        .faq-answer {
            padding: 0 22px 22px;
            font-size: 15px;
            line-height: 1.7;
            color: var(--text-muted);
        }
        .faq-answer p { margin: 0; }
    </style>
</head>
<body>

    <a class="skip-link" href="#main-content">Skip to main content</a>

    <!-- Header & Navigation -->
    <div class="top-bar-wrap" id="top">
        <header class="navbar">
            <a href="index.php" class="logo">
                <span class="logo-icon"><i class="fa-solid fa-star-half-stroke"></i></span>
                Optibiz
            </a>
            <nav class="nav-pill">
                <a href="index.php" class="active"><i class="fa-solid fa-house"></i> <span>Home</span></a>
                <a href="features.php"><i class="fa-solid fa-layer-group"></i> <span>Features</span></a>
                <a href="pricing.php"><i class="fa-solid fa-tags"></i> <span>Pricing</span></a>
                <a href="companies.php"><i class="fa-solid fa-building"></i> <span>Directory</span></a>
                <a href="seo-checker.php"><i class="fa-solid fa-magnifying-glass-chart"></i> <span>SEO Checker</span></a>
                <a href="#how-it-works"><i class="fa-solid fa-circle-question"></i> <span>How It Works</span></a>
            </nav>
            <div class="nav-actions">
                <button type="button" class="btn-quote" onclick="openModal()">
                    <span>Get Started</span> <i class="fa-solid fa-arrow-right"></i>
                </button>
                <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="mobileNavDrawer">
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                </button>
            </div>
        </header>

        <!-- Mobile Navigation Drawer -->
        <div class="mobile-nav-drawer" id="mobileNavDrawer" aria-hidden="true">
            <div class="mobile-nav-inner">
                <div class="mobile-nav-links">
                    <a href="index.php" class="mobile-nav-link active">
                        <i class="fa-solid fa-house"></i>
                        <span>Home</span>
                    </a>
                    <a href="features.php" class="mobile-nav-link">
                        <i class="fa-solid fa-layer-group"></i>
                        <span>Features</span>
                    </a>
                    <a href="pricing.php" class="mobile-nav-link">
                        <i class="fa-solid fa-tags"></i>
                        <span>Pricing</span>
                    </a>
                    <a href="companies.php" class="mobile-nav-link">
                        <i class="fa-solid fa-building"></i>
                        <span>Directory</span>
                    </a>
                    <a href="seo-checker.php" class="mobile-nav-link">
                        <i class="fa-solid fa-magnifying-glass-chart"></i>
                        <span>SEO Checker</span>
                    </a>
                    <a href="#how-it-works" class="mobile-nav-link">
                        <i class="fa-solid fa-circle-question"></i>
                        <span>How It Works</span>
                    </a>
                </div>

                <div class="mobile-nav-divider"></div>

                <div class="mobile-nav-buttons">
                    <button type="button" class="mobile-btn-quote" onclick="closeMobileNav(); openModal();">
                        <i class="fa-solid fa-rocket"></i>
                        <span>Get Started Free</span>
                        <i class="fa-solid fa-arrow-right" style="font-size:12px;"></i>
                    </button>
                </div>

                <div class="mobile-nav-footer">
                    <i class="fa-solid fa-shield-check"></i>
                    <span>14-Day Free Trial • Instant Setup • No Credit Card</span>
                </div>
            </div>
        </div>
    </div>
    <!-- Mobile Backdrop Overlay -->
    <div class="mobile-nav-overlay" id="mobileNavOverlay" aria-hidden="true"></div>

    <main id="main-content">
    <!-- Hero Section -->
    <section class="hero-section" aria-labelledby="hero-title">
        <div class="hero-container">
            <div class="hero-left">
                <div class="badge-tag">
                    <i class="fa-solid fa-shield-check"></i> Omnichannel Review &amp; Reputation Growth
                </div>
                <h1 class="hero-title" id="hero-title">Turn Customer Reviews Into Your <span>#1 Growth Engine</span></h1>
                <p class="hero-desc">
                    Collect in-store ratings with printable Counter QR Stands, trigger personalized WhatsApp outreach, boost your Google Reviews with smart routing, and auto-generate social proof graphics for ads.
                </p>
                
                <div class="hero-actions">
                    <button type="button" class="btn-lime" onclick="openModal()">
                        Start Free Trial <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <a href="features.php" class="btn-ghost-light">
                        <i class="fa-solid fa-layer-group"></i> Explore Features
                    </a>
                    <a href="companies.php" class="btn-ghost-light">
                        <i class="fa-solid fa-building"></i> Browse Companies
                    </a>
                </div>

                <div class="hero-proof">
                    <div class="rating-block">
                        <div class="stars-row">
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                        </div>
                        <div class="rating-number-wrap">
                            <div class="big-rating"><?php echo number_format($avg_platform_rating, 1); ?></div>
                            <div class="rating-text">Platform Average Rating<br>Across Verified Workspaces</div>
                        </div>
                    </div>

                    <div class="rating-block">
                        <div class="big-rating" style="font-size:28px"><?php echo number_format($total_ratings); ?>+</div>
                        <div class="rating-text">Genuine Customer Ratings<br>Collected &amp; Verified</div>
                    </div>
                </div>
            </div>

            <!-- Hero Visual Carousel Slider -->
            <div class="hero-graphic-wrap">
                <div class="hero-slider-container" id="heroSlider" aria-roledescription="carousel" aria-label="Optibiz Platform Highlights">
                    <div class="hero-slider-track-wrap">
                        <!-- Slide 1: Interactive Rating Demo Card -->
                        <div class="hero-slide active" data-slide-index="0" role="group" aria-roledescription="slide" aria-label="1 of 4: Interactive Rating Demo">
                            <div class="hero-demo-card">
                                <div class="demo-badge-top">
                                    <i class="fa-solid fa-bolt"></i> Live Interactive Preview
                                </div>
                                <div class="demo-biz-header">
                                    <div class="demo-biz-avatar"><?php echo htmlspecialchars($hero_demo_avatar); ?></div>
                                    <div class="demo-biz-info">
                                        <p class="demo-biz-name"><?php echo htmlspecialchars($hero_demo_name); ?></p>
                                        <p>Powered by Optibiz Counter QR Stand</p>
                                    </div>
                                </div>

                                <div class="demo-question">How was your experience today?</div>

                                <div class="interactive-stars" id="demoStars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <button type="button" class="star-btn<?php echo $hero_demo_rating === $i ? ' active' : ''; ?>" data-score="<?php echo $i; ?>" title="<?php echo $i; ?> Star<?php echo $i > 1 ? 's' : ''; ?>"><i class="fa-solid fa-star"></i></button>
                                    <?php endfor; ?>
                                </div>

                                <div class="demo-feedback-box" id="demoFeedback">
                                    <?php if ($hero_demo_rating >= 4): ?>
                                    <div class="routing-pill google">
                                        <i class="fa-brands fa-google"></i> Google Review Booster Triggered!
                                    </div>
                                    <p style="color:#0f172a;font-size:12.5px;font-weight:600">
                                        ★ <?php echo $hero_demo_rating; ?>-Star score detected: Customer is auto-redirected to Google Reviews &amp; an Instagram Social Proof Card is generated!
                                    </p>
                                    <?php else: ?>
                                    <div class="routing-pill private">
                                        <i class="fa-solid fa-shield-halved"></i> Private Feedback Shield Activated
                                    </div>
                                    <p style="color:#c2410c;font-size:12.5px;font-weight:600">
                                        ⚠ <?php echo $hero_demo_rating; ?>-Star score caught: Routed privately to the manager before reaching Google or social media!
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Slide 2: Real-Time Analytics & Growth Chart -->
                        <div class="hero-slide" data-slide-index="1" role="group" aria-roledescription="slide" aria-label="2 of 4: Analytics and Performance Chart">
                            <div class="hero-slide-img-card">
                                <img src="assets/images/service_planning.jpg" alt="Optibiz Review Analytics and Performance Charts" width="520" height="380" loading="eager" decoding="async">
                                <div class="hero-slide-badge">
                                    <i class="fa-solid fa-chart-line"></i> +38% Growth
                                </div>
                                <div class="hero-slide-overlay">
                                    <span class="hero-slide-tag"><i class="fa-solid fa-arrow-trend-up"></i> Live Analytics</span>
                                    <h3 class="hero-slide-title">Real-Time Reputation Analytics</h3>
                                    <p class="hero-slide-desc">Track customer satisfaction scores, review velocity, and branch performance in one dashboard.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Slide 3: Lady Taking Review & Customer Feedback -->
                        <div class="hero-slide" data-slide-index="2" role="group" aria-roledescription="slide" aria-label="3 of 4: In-Person Customer Review">
                            <div class="hero-slide-img-card">
                                <img src="assets/images/about_consultants.jpg" alt="Client taking in-person customer review feedback" width="520" height="380" loading="lazy" decoding="async">
                                <div class="hero-slide-badge">
                                    <i class="fa-solid fa-star"></i> 5.0 Verified
                                </div>
                                <div class="hero-slide-overlay">
                                    <span class="hero-slide-tag"><i class="fa-solid fa-comments"></i> In-Person Feedback</span>
                                    <h3 class="hero-slide-title">Customer Review Interactions</h3>
                                    <p class="hero-slide-desc">Capture genuine customer impressions on the spot before customers leave your premises.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Slide 4: Mobile App & QR Scanning -->
                        <div class="hero-slide" data-slide-index="3" role="group" aria-roledescription="slide" aria-label="4 of 4: Mobile App and QR Scanning">
                            <div class="hero-slide-img-card">
                                <img src="assets/images/hero_phones.jpg" alt="Optibiz Mobile Rating and Portfolio App" width="520" height="380" loading="lazy" decoding="async">
                                <div class="hero-slide-badge">
                                    <i class="fa-solid fa-mobile-screen"></i> Instant Tap &amp; Scan
                                </div>
                                <div class="hero-slide-overlay">
                                    <span class="hero-slide-tag"><i class="fa-solid fa-qrcode"></i> Seamless Experience</span>
                                    <h3 class="hero-slide-title">Mobile-First Customer Journey</h3>
                                    <p class="hero-slide-desc">Customers rate via QR codes or WhatsApp in seconds without needing to install any app.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Slider Controls: Prev / Dots / Next -->
                    <div class="hero-slider-nav">
                        <button type="button" class="slider-arrow-btn" id="heroSliderPrev" aria-label="Previous slide">
                            <i class="fa-solid fa-chevron-left"></i>
                        </button>
                        <div class="hero-slider-dots" id="heroSliderDots">
                            <button type="button" class="hero-slider-dot active" data-slide-to="0" aria-label="Go to slide 1"></button>
                            <button type="button" class="hero-slider-dot" data-slide-to="1" aria-label="Go to slide 2"></button>
                            <button type="button" class="hero-slider-dot" data-slide-to="2" aria-label="Go to slide 3"></button>
                            <button type="button" class="hero-slider-dot" data-slide-to="3" aria-label="Go to slide 4"></button>
                        </div>
                        <button type="button" class="slider-arrow-btn" id="heroSliderNext" aria-label="Next slide">
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Trust Stats Bar -->
    <section class="trust-bar">
        <div class="trust-grid">
            <div class="trust-stat">
                <div class="trust-num"><?php echo number_format($total_ratings); ?>+</div>
                <div class="trust-label">Verified Customer Reviews</div>
            </div>
            <div class="trust-stat">
                <div class="trust-num"><?php echo number_format($total_customers); ?>+</div>
                <div class="trust-label">Registered Business Profiles</div>
            </div>
            <div class="trust-stat">
                <div class="trust-num"><?php echo number_format($total_events); ?>+</div>
                <div class="trust-label">QR Scans &amp; WhatsApp Interactions</div>
            </div>
            <div class="trust-stat">
                <div class="trust-num"><?php echo number_format($avg_platform_rating, 1); ?>★</div>
                <div class="trust-label">Average Customer Satisfaction</div>
            </div>
        </div>
    </section>

    <!-- Features Section: 6 Core Engines -->
    <section class="features-section" id="features">
        <div class="section-header">
            <span class="section-tag">Complete Reputation Engine</span>
            <h2 class="section-title">Everything You Need To Build Trust &amp; Drive Sales</h2>
            <p class="section-subtitle">Stop chasing customers for feedback. Optibiz automates review collection across physical counters, WhatsApp, and search engines.</p>
        </div>

        <div class="features-grid">
            <!-- Feature 1: QR Stands -->
            <div class="feature-card">
                <div class="f-icon-wrap"><i class="fa-solid fa-qrcode"></i></div>
                <h3>Printable Counter QR Stands</h3>
                <p>Generate high-resolution acrylic counter stands and table tent displays. In-store shoppers scan and leave verified reviews in under 15 seconds.</p>
                <div class="f-badge"><i class="fa-solid fa-print"></i> Ready-to-print PDF</div>
            </div>

            <!-- Feature 2: WhatsApp Outreach -->
            <div class="feature-card">
                <div class="f-icon-wrap"><i class="fa-brands fa-whatsapp"></i></div>
                <h3>WhatsApp 1-Click Requester</h3>
                <p>Reach customers where they actually read messages. Send personalized review invite links directly into WhatsApp with 82%+ open rates.</p>
                <div class="f-badge"><i class="fa-solid fa-comments"></i> 1-Click Chat Links</div>
            </div>

            <!-- Feature 3: Google Booster -->
            <div class="feature-card">
                <div class="f-icon-wrap"><i class="fa-brands fa-google"></i></div>
                <h3>Google Review Booster</h3>
                <p>Smart sentiment routing prompts satisfied 4-5 star customers to publish straight to your Google Maps listing, while routing critiques to private feedback.</p>
                <div class="f-badge"><i class="fa-solid fa-shield-halved"></i> Reputation Shield</div>
            </div>

            <!-- Feature 4: Social Cards -->
            <div class="feature-card">
                <div class="f-icon-wrap"><i class="fa-solid fa-share-nodes"></i></div>
                <h3>Social Proof Card Studio</h3>
                <p>Transform your best 5-star customer reviews into branded square graphics formatted for Instagram, LinkedIn, and Facebook posts in seconds.</p>
                <div class="f-badge"><i class="fa-solid fa-download"></i> High-Res PNG Export</div>
            </div>

            <!-- Feature 5: Community Q&A -->
            <div class="feature-card">
                <div class="f-icon-wrap"><i class="fa-solid fa-circle-question"></i></div>
                <h3>Public Community Q&amp;A</h3>
                <p>Let potential buyers ask questions on your public rate profile. Answer officially to remove buying objections and establish domain authority.</p>
                <div class="f-badge"><i class="fa-solid fa-check-double"></i> Verified Business Answers</div>
            </div>

            <!-- Feature 6: Ad Pixels -->
            <div class="feature-card">
                <div class="f-icon-wrap"><i class="fa-solid fa-bullseye"></i></div>
                <h3>Paid Ad Funnel &amp; Retargeting</h3>
                <p>Integrate Meta Pixel, Google Tag Manager, and TikTok Pixel to retarget warm review visitors with high-converting promotional ads.</p>
                <div class="f-badge"><i class="fa-solid fa-chart-line"></i> Pixel Audiences</div>
            </div>
        </div>
    </section>

    <!-- New Feature Callout: SEO Health Checker (matching screenshot) -->
    <section class="seo-feature-banner" style="max-width:1200px;margin:40px auto 60px;padding:0 5%;">
        <div style="background:linear-gradient(135deg, #0a1727 0%, #162a45 100%);border:2px solid #1e3a5f;border-radius:24px;padding:44px 36px;box-shadow:0 20px 50px rgba(0,0,0,0.3);position:relative;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:32px;flex-wrap:wrap;">
                <div style="flex:1;min-width:300px;">
                    <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(194,245,66,0.15);color:var(--accent-lime);border:1px solid rgba(194,245,66,0.3);padding:6px 14px;border-radius:20px;font-size:12px;font-weight:800;text-transform:uppercase;margin-bottom:16px;">
                        <span>⚡ 100% Free Instant Scan • Free for Tenants</span>
                    </div>
                    <h2 style="font-size:clamp(26px, 3.5vw, 40px);font-weight:900;line-height:1.2;color:#ffffff;text-transform:uppercase;margin-bottom:14px;letter-spacing:-0.5px;">
                        How Many <span style="background:linear-gradient(135deg, #38bdf8, #818cf8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">SEO Problems</span><br>Does Your Website Have?
                    </h2>
                    <p style="color:#cbd5e1;font-size:16px;margin-bottom:24px;line-height:1.6;max-width:540px;">
                        Scan your website in 30 seconds. Detect missing SEO content, slow server response times, and weak visibility holding your Google rankings back.
                    </p>
                    <form action="seo-checker.php" method="GET" style="display:flex;gap:10px;max-width:520px;flex-wrap:wrap;">
                        <input type="text" name="url" placeholder="https://yourwebsite.com" style="flex:1;min-width:240px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.2);color:#fff;padding:14px 18px;border-radius:12px;font-size:15px;outline:none;">
                        <button type="submit" style="background:var(--accent-lime);color:#0f2438;border:none;padding:14px 24px;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all 0.2s;">
                            <span>Scan Free</span> <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </form>
                </div>
                <div style="flex-shrink:0;background:#ffffff;color:#0f172a;border-radius:20px;padding:24px 28px;box-shadow:0 15px 35px rgba(0,0,0,0.4);max-width:320px;width:100%;">
                    <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;">Website Health Preview</div>
                    <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
                        <div style="width:70px;height:70px;border-radius:50%;background:#fee2e2;border:6px solid #ef4444;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;color:#0f172a;">41</div>
                        <div>
                            <div style="font-size:18px;font-weight:900;color:#ef4444;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-triangle-exclamation"></i> 17 Issues</div>
                            <div style="font-size:12px;color:#64748b;">Google can't understand site</div>
                        </div>
                    </div>
                    <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:700;">
                        ✓ Fix 12 issues automatically
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works 3-Step Section -->
    <section class="how-section" id="how-it-works">
        <div class="section-header">
            <span class="section-tag">Frictionless Workflow</span>
            <h2 class="section-title">How Optibiz Grows Your Business In 3 Steps</h2>
            <p class="section-subtitle">Set up once, print your counter stand, and let the system collect and amplify your reputation on autopilot.</p>
        </div>

        <div class="how-grid">
            <div class="how-step">
                <div class="how-num">1</div>
                <h3>Collect In-Store &amp; Online</h3>
                <p>Place your branded QR Stand on your checkout counter, or send automated WhatsApp review invites after every completed customer sale.</p>
            </div>
            <div class="how-step">
                <div class="how-num">2</div>
                <h3>Filter &amp; Boost to Google</h3>
                <p>Happy customers are automatically guided to post on your Google Business profile. Any unhappy customer is routed privately to your team.</p>
            </div>
            <div class="how-step">
                <div class="how-num">3</div>
                <h3>Amplify &amp; Retarget</h3>
                <p>Turn glowing testimonials into Instagram social cards and build custom ad audiences from verified reviewers using Meta and Google pixels.</p>
            </div>
        </div>
    </section>

    <!-- Featured Companies Preview -->
    <?php if (!empty($featured_companies)): ?>
    <section class="directory-preview">
        <div class="section-header">
            <span class="section-tag">Verified Directory</span>
            <h2 class="section-title">Top-Rated Businesses on Optibiz</h2>
            <p class="section-subtitle">Explore companies actively delivering 5-star customer experiences across dining, retail, healthcare, and services.</p>
        </div>

        <div class="companies-grid">
            <?php foreach ($featured_companies as $fc): ?>
            <a href="<?php echo htmlspecialchars(getCompanyPublicRatingUrl($fc['id'], $fc['company_name'])); ?>" class="company-preview-card">
                <div class="c-card-top">
                    <div class="c-card-logo">
                        <?php if (!empty($fc['logo'])): ?>
                            <img src="<?php echo htmlspecialchars($fc['logo'], ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="<?php echo htmlspecialchars($fc['company_name'], ENT_QUOTES, 'UTF-8'); ?> logo"
                                 width="48" height="48" loading="lazy" decoding="async">
                        <?php else: ?>
                            <?php echo strtoupper(substr($fc['company_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="c-card-name"><?php echo htmlspecialchars($fc['company_name']); ?></div>
                        <div class="c-card-cat"><?php echo htmlspecialchars($fc['category_name'] ?: 'Local Business'); ?></div>
                    </div>
                </div>
                <div class="c-card-stats">
                    <div class="c-stars">
                        <i class="fa-solid fa-star"></i>
                        <span><?php echo number_format((float) ($fc['avg_score'] ?? 5.0), 1); ?></span>
                    </div>
                    <div style="color:var(--text-muted)">
                        <?php echo (int) $fc['review_count']; ?> reviews
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <div style="text-align:center;margin-top:40px">
            <a href="companies.php" class="btn-lime">
                View All Businesses in Directory <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </section>
    <?php endif; ?>

    <!-- Recent Verified Reviews Stream -->
    <?php if (!empty($recent_reviews)): ?>
    <section class="reviews-section">
        <div class="section-header">
            <span class="section-tag">Real Customer Stories</span>
            <h2 class="section-title">Latest Verified Feedback</h2>
            <p class="section-subtitle">See what real customers are saying about businesses on the Optibiz platform.</p>
        </div>

        <div class="reviews-grid">
            <?php foreach ($recent_reviews as $rev): ?>
            <div class="review-quote-card">
                <div class="rq-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fa-solid fa-star<?php echo $i <= (int)$rev['rating'] ? '' : '-o'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <p class="rq-comment">"<?php echo htmlspecialchars(mb_substr($rev['comment'] ?: 'Excellent service and great attention to detail!', 0, 140)); ?><?php echo mb_strlen($rev['comment'] ?? '') > 140 ? '…' : ''; ?>"</p>
                <div class="rq-company">
                    <i class="fa-solid fa-building"></i>
                    <span><?php echo htmlspecialchars($rev['company_name'] ?? 'Verified Business'); ?></span>
                </div>
                <div class="rq-author">
                    <strong><?php echo htmlspecialchars($rev['customer_name'] ?: 'Verified Customer'); ?></strong>
                    <span><i class="fa-solid fa-circle-check"></i> Verified</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Frequently Asked Questions -->
    <!-- Native <details>/<summary>: fully crawlable with JavaScript disabled,
         keyboard accessible for free, and the copy mirrors the FAQPage
         JSON-LD emitted in <head> ($seo_faqs is the single source of truth). -->
    <section class="faq-section" id="faq" aria-labelledby="faq-title">
        <div class="section-header">
            <span class="section-tag">Answers</span>
            <h2 class="section-title" id="faq-title">Frequently Asked Questions</h2>
            <p class="section-subtitle">Everything you need to know about collecting reviews with Optibiz.</p>
        </div>

        <div class="faq-list">
            <?php foreach ($seo_faqs as $faq): ?>
            <details class="faq-item">
                <summary><?php echo htmlspecialchars($faq['q'], ENT_QUOTES, 'UTF-8'); ?></summary>
                <div class="faq-answer">
                    <p><?php echo htmlspecialchars($faq['a'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </details>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Banner CTA -->
    <section class="cta-banner">
        <h2>Ready To 10x Your Customer Reviews?</h2>
        <p>Join hundreds of businesses using Optibiz to turn customer satisfaction into organic search rankings, trust, and more revenue.</p>
        <div style="display:inline-flex;gap:14px;flex-wrap:wrap;justify-content:center">
            <button type="button" class="btn-lime" onclick="openModal()">
                Start 14-Day Free Trial <i class="fa-solid fa-arrow-right"></i>
            </button>
            <a href="pricing.php" class="btn-ghost-light">
                <i class="fa-solid fa-credit-card"></i> View Pricing &amp; Plans
            </a>
        </div>
    </section>

    <!-- Trusted Companies Section -->
    <section class="trusted-section">
        <div class="section-header">
            <span class="section-tag">Trusted By Businesses</span>
            <h2 class="section-title">Companies That Trust Optibiz</h2>
            <p class="section-subtitle">Join hundreds of satisfied businesses using Optibiz to grow their online presence and customer trust.</p>
        </div>

        <div class="trusted-grid">
            <div class="trusted-logo">
                <div class="trusted-logo-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                    <i class="fa-solid fa-store"></i>
                </div>
                <div class="trusted-logo-name">Volta Logistics</div>
            </div>
            <div class="trusted-logo">
                <div class="trusted-logo-icon" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                    <i class="fa-solid fa-apple-whole"></i>
                </div>
                <div class="trusted-logo-name">Harmattan Foods</div>
            </div>
            <div class="trusted-logo">
                <div class="trusted-logo-icon" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                    <i class="fa-solid fa-plane"></i>
                </div>
                <div class="trusted-logo-name">Kotoka Ground Services</div>
            </div>
            <div class="trusted-logo">
                <div class="trusted-logo-icon" style="background: linear-gradient(135deg, #43e97b, #38f9d7);">
                    <i class="fa-solid fa-leaf"></i>
                </div>
                <div class="trusted-logo-name">GreenEarth Africa</div>
            </div>
            <div class="trusted-logo">
                <div class="trusted-logo-icon" style="background: linear-gradient(135deg, #fa709a, #fee140);">
                    <i class="fa-solid fa-hotel"></i>
                </div>
                <div class="trusted-logo-name">Osu Hotels Ltd</div>
            </div>
            <div class="trusted-logo">
                <div class="trusted-logo-icon" style="background: linear-gradient(135deg, #a18cd1, #fbc2eb);">
                    <i class="fa-solid fa-car"></i>
                </div>
                <div class="trusted-logo-name">Accra Auto Mart</div>
            </div>
        </div>
    </section>

    <!-- Our Team Section -->
    <section class="team-section" id="team">
        <div class="section-header">
            <span class="section-tag">Behind The Platform</span>
            <h2 class="section-title">Meet Our Team</h2>
            <p class="section-subtitle">The passionate people building Ghana's leading review and reputation platform.</p>
        </div>

        <?php
        // Team profiles.
        //
        // Fill in a real profile URL whenever you have one. An icon is only
        // rendered as a link when a URL is configured, so the page never ships
        // dead href="#" placeholders — crawlers treat those as soft 404s and
        // screen readers announce them as broken links.
        $team_members = array(
            array(
                'initials' => 'SA',
                'name'     => 'Samuel Asante',
                'role'     => 'Founder & CEO',
                'bio'      => 'Founder of Optibiz with 10+ years experience in digital marketing and customer engagement across West Africa.',
                'links'    => array(
                    // 'linkedin' => 'https://www.linkedin.com/in/…',
                    // 'twitter'  => 'https://x.com/…',
                ),
            ),
            array(
                'initials' => 'EK',
                'name'     => 'Elsie Kumah',
                'role'     => 'Head of Product',
                'bio'      => 'Leads product development and UX design, ensuring Optibiz delivers the best experience for businesses and customers.',
                'links'    => array(
                    // 'linkedin' => 'https://www.linkedin.com/in/…',
                    // 'github'   => 'https://github.com/…',
                ),
            ),
            array(
                'initials' => 'MO',
                'name'     => 'Michael Osei',
                'role'     => 'Senior Developer',
                'bio'      => 'Full-stack developer building scalable features and integrations that power Optibiz\'s review and reputation tools.',
                'links'    => array(
                    // 'linkedin' => 'https://www.linkedin.com/in/…',
                    // 'github'   => 'https://github.com/…',
                ),
            ),
            array(
                'initials' => 'AN',
                'name'     => 'Ama Nkrumah',
                'role'     => 'Customer Success',
                'bio'      => 'Helps businesses maximize their use of Optibiz, providing onboarding support and ongoing success coaching.',
                'links'    => array(
                    // 'linkedin' => 'https://www.linkedin.com/in/…',
                    // 'email'    => 'ama@…',
                ),
            ),
        );

        // Key -> [Font Awesome classes, accessible label]
        $team_link_icons = array(
            'linkedin' => array('fa-brands fa-linkedin', 'LinkedIn'),
            'twitter'  => array('fa-brands fa-x-twitter', 'X (Twitter)'),
            'github'   => array('fa-brands fa-github', 'GitHub'),
            'email'    => array('fa-solid fa-envelope', 'Email'),
        );
        ?>
        <div class="team-grid">
            <?php foreach ($team_members as $member): ?>
            <div class="team-card">
                <div class="team-avatar">
                    <span><?php echo htmlspecialchars($member['initials'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="team-name"><?php echo htmlspecialchars($member['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="team-role"><?php echo htmlspecialchars($member['role'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="team-bio"><?php echo htmlspecialchars($member['bio'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if (!empty($member['links'])): ?>
                <div class="team-social">
                    <?php foreach ($member['links'] as $key => $url): ?>
                        <?php
                        if (!isset($team_link_icons[$key]) || $url === '') continue;
                        list($icon_class, $icon_label) = $team_link_icons[$key];
                        $is_email = ($key === 'email');
                        $href = ($is_email && stripos($url, 'mailto:') !== 0) ? 'mailto:' . $url : $url;
                        ?>
                        <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"
                           aria-label="<?php echo htmlspecialchars($icon_label . ' — ' . $member['name'], ENT_QUOTES, 'UTF-8'); ?>"
                           <?php echo $is_email ? '' : 'target="_blank" rel="noopener noreferrer"'; ?>>
                            <i class="<?php echo htmlspecialchars($icon_class, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Footer -->
    </main>

    <footer class="site-footer" id="contact">
        <div class="footer-grid-container">
            <div class="footer-col-brand">
                <a href="index.php" class="logo">
                    <span class="logo-icon"><i class="fa-solid fa-star-half-stroke"></i></span>
                    Optibiz
                </a>
                <p class="footer-brand-desc">
                    The modern business review, reputation management, and customer acquisition platform. Built for in-store foot traffic and online growth.
                </p>
                <?php
                // Brand social profiles. These feed BOTH the visible footer
                // links and the Organization "sameAs" array in the JSON-LD,
                // which is how Google confirms which profiles belong to you.
                // Set them in Control Center → Settings → Branding, or add the
                // social_* keys to the `settings` table. Until they are set the
                // icons are hidden rather than linking to a generic homepage.
                $footer_socials = array(
                    'twitter'   => array('fa-brands fa-x-twitter', 'X (Twitter)', seo_setting('social_twitter', '')),
                    'linkedin'  => array('fa-brands fa-linkedin', 'LinkedIn',    seo_setting('social_linkedin', '')),
                    'facebook'  => array('fa-brands fa-facebook', 'Facebook',    seo_setting('social_facebook', '')),
                    'instagram' => array('fa-brands fa-instagram', 'Instagram',  seo_setting('social_instagram', '')),
                );
                $footer_socials = array_filter($footer_socials, function ($s) {
                    return preg_match('~^https?://~i', $s[2]);
                });
                ?>
                <?php if (!empty($footer_socials)): ?>
                <div style="display:flex;gap:12px;font-size:16px">
                    <?php foreach ($footer_socials as $s): ?>
                    <a href="<?php echo htmlspecialchars($s[2], ENT_QUOTES, 'UTF-8'); ?>"
                       style="color:#94a3b8"
                       aria-label="<?php echo htmlspecialchars($s[1], ENT_QUOTES, 'UTF-8'); ?>"
                       rel="me noopener noreferrer" target="_blank">
                        <i class="<?php echo htmlspecialchars($s[0], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div>
                <h4 class="footer-col-title">Platform</h4>
                <ul class="footer-links-list">
                    <li><a href="features.php">Features Overview</a></li>
                    <li><a href="pricing.php">Pricing &amp; Plans</a></li>
                    <li><a href="companies.php">Company Directory</a></li>
                    <li><a href="#how-it-works">How It Works</a></li>
                </ul>
            </div>

            <div>
                <h4 class="footer-col-title">Solutions</h4>
                <ul class="footer-links-list">
                    <li><a href="features.php#qr-stands">Counter QR Stands</a></li>
                    <li><a href="features.php#whatsapp">WhatsApp Requester</a></li>
                    <li><a href="features.php#google-booster">Google Booster</a></li>
                    <li><a href="features.php#social-proof">Social Proof Cards</a></li>
                    <li><a href="features.php#ad-funnel">Ad Funnels &amp; Pixels</a></li>
                </ul>
            </div>

            <div>
                <h4 class="footer-col-title">Workspace</h4>
                <ul class="footer-links-list">
                    <li><a href="<?php echo admin_url('login.php'); ?>">Business Admin Login</a></li>
                    <li><a href="superadmin/login.php">Control Center</a></li>
                    <li><button type="button" class="footer-link-button" onclick="openModal()">Request Custom Quote</button></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <div>
                &copy; <?php echo date('Y'); ?> <strong>Optibiz</strong>. All rights reserved.
            </div>
            <div style="display:flex;gap:20px">
                <a href="pricing.php" style="color:#94a3b8;text-decoration:none">Pricing</a>
                <a href="features.php" style="color:#94a3b8;text-decoration:none">Features</a>
            </div>
        </div>
    </footer>

    <!-- ================= GET STARTED / QUOTE MODAL ================= -->
    <div class="modal-overlay" id="quoteModal" role="dialog" aria-modal="true" aria-labelledby="quoteModalTitle">
        <div class="modal-content">
            <button type="button" class="modal-close" id="quoteModalClose" aria-label="Close dialog">&times;</button>

            <!-- Multi-step form view -->
            <div id="quoteFormWrap">
                <div style="margin-bottom:24px">
                    <h2 id="quoteModalTitle" style="font-size:24px;font-weight:800;color:var(--primary-dark)">Get Started</h2>
                    <p style="font-size:13px;color:var(--text-muted)">Set up your company workspace in under 60 seconds. No credit card required.</p>
                </div>

                <div class="progress-steps" id="quoteProgress">
                    <div class="step-wrap active">
                        <div class="step active">1</div>
                        <span class="step-label">Company</span>
                    </div>
                    <div class="step-line"></div>
                    <div class="step-wrap">
                        <div class="step">2</div>
                        <span class="step-label">Contact</span>
                    </div>
                    <div class="step-line"></div>
                    <div class="step-wrap">
                        <div class="step">3</div>
                        <span class="step-label">Plan &amp; Needs</span>
                    </div>
                </div>

                <form id="quoteForm" action="api/submit_quote.php" method="post" novalidate>
                    <!-- Step 1: Company Information -->
                    <div class="form-section active">
                        <h3>Company Information</h3>
                        <p class="subtitle">Let us know who you are so we can set up your workspace.</p>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="q_company_name">Company Name *</label>
                                <input type="text" id="q_company_name" name="company_name" placeholder="e.g. Acme Cafe & Bistro" required>
                            </div>
                            <div class="form-group">
                                <label for="q_contact_person">Contact Person *</label>
                                <input type="text" id="q_contact_person" name="contact_person" placeholder="Your full name" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="q_category">Industry / Category *</label>
                                <select id="q_category" name="category" required>
                                    <option value="" disabled selected>Select a category</option>
                                    <?php foreach ($modal_categories as $cat): ?>
                                    <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="q_location">Location / City</label>
                                <input type="text" id="q_location" name="location" placeholder="e.g. Accra, Ghana">
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Contact Details -->
                    <div class="form-section">
                        <h3>Contact Details</h3>
                        <p class="subtitle">Where should we send your workspace credentials?</p>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="q_email">Work Email *</label>
                                <input type="email" id="q_email" name="email" placeholder="manager@company.com" required>
                            </div>
                            <div class="form-group">
                                <label for="q_phone">Phone / WhatsApp Number *</label>
                                <input type="tel" id="q_phone" name="phone" placeholder="+233 …" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="q_website">Company Website or Social Page</label>
                            <input type="text" id="q_website" name="website" placeholder="https://instagram.com/yourbusiness">
                        </div>
                    </div>

                    <!-- Step 3: Plan & Scale -->
                    <div class="form-section">
                        <h3>Plan &amp; Scale</h3>
                        <p class="subtitle">Pick a plan that fits your business needs.</p>

                        <div class="form-group">
                            <label for="q_plan">Subscription Plan *</label>
                            <select id="q_plan" name="plan_id" required>
                                <option value="" disabled selected>Select a plan</option>
                                <?php foreach ($modal_plans as $plan): 
                                    $discount = (int)($plan['annual_discount_percent'] ?? 0);
                                    $annual_price = $discount > 0 ? round((float)$plan['price'] * (1 - $discount / 100), 2) : round((float)$plan['price'] * 0.8, 2);
                                    $annual_total = round($annual_price * 12, 2);
                                    $savings = round((float)$plan['price'] * 12 - $annual_total, 2);
                                ?>
                                <option value="<?php echo (int)$plan['id']; ?>">
                                    <?php echo htmlspecialchars($plan['plan_name']); ?> — $<?php echo number_format((float)$plan['price'], 2); ?>/month
                                    <?php if ($discount > 0): ?>
                                    <small>($<?php echo number_format($annual_total, 2); ?>/yr – Save $<?php echo number_format($savings, 2); ?>)</small>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="q_num_companies">Profiles to Manage</label>
                                <input type="number" id="q_num_companies" name="num_companies" min="1" value="1">
                            </div>
                            <div class="form-group">
                                <label for="q_expected_ratings">Expected Monthly Customers</label>
                                <input type="number" id="q_expected_ratings" name="expected_ratings" min="1" value="250">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="q_notes">Special Requirements / Notes</label>
                            <textarea id="q_notes" name="notes" rows="2" placeholder="Tell us if you need acrylic counter stands shipped, ad setup assistance, etc."></textarea>
                        </div>
                    </div>

                    <div class="form-alert" id="quoteAlert" hidden></div>

                    <div class="form-nav">
                        <button type="button" class="btn-back" id="quoteBackBtn" hidden>
                            <i class="fa-solid fa-arrow-left"></i> Back
                        </button>
                        <button type="button" class="btn-lime" id="quoteNextBtn">
                            Continue <i class="fa-solid fa-arrow-right"></i>
                        </button>
                        <button type="button" class="btn-cancel" id="quoteCancelBtn" hidden>
                            Cancel
                        </button>
                        <button type="submit" class="btn-lime" id="quoteSubmitBtn" hidden>
                            Submit <i class="fa-solid fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Success view — Real ID + email onboarding (QTE + OPT) — merged main + Real ID feature -->
            <div class="quote-success" id="quoteSuccess" hidden>
                <div class="quote-success-icon">
                    <i class="fa-solid fa-check"></i>
                </div>
                <h2 style="font-size:24px;font-weight:800;color:var(--primary-dark);margin-bottom:8px">Workspace Request Received!</h2>
                <div id="quoteRealIdBox" style="margin:0 auto 18px;display:none;max-width:420px;">
                    <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#475569;letter-spacing:0.5px;text-transform:uppercase;">Your Quota Reference ID</p>
                    <div id="quoteRealId" style="font-family:monospace;background:linear-gradient(135deg, #ecfccb, #d9f99d);border:1.5px solid #a3e635;color:#3f6212;padding:14px 18px;border-radius:12px;font-size:22px;font-weight:900;letter-spacing:1.5px;display:inline-block;min-width:200px;box-shadow:0 6px 20px rgba(132,204,22,0.15);">QTE-XXXXXXXX</div>
                    <p style="margin:12px 0 0;font-size:12.5px;color:#64748b;line-height:1.5;">Keep this ID safe — use it to track your request with support.</p>
                </div>
                <div id="quoteTenantIdBox" style="margin:0 auto 18px;display:none;max-width:420px;">
                    <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#475569;letter-spacing:0.5px;text-transform:uppercase;">Your Real Account ID (Tenant)</p>
                    <div id="quoteTenantId" style="font-family:monospace;background:linear-gradient(135deg, #e0e7ff, #c7d2fe);border:1.5px solid #818cf8;color:#4338ca;padding:14px 18px;border-radius:12px;font-size:22px;font-weight:900;letter-spacing:1.5px;display:inline-block;min-width:200px;box-shadow:0 6px 20px rgba(99,102,241,0.15);">OPT-XXXXXXXX</div>
                    <p style="margin:12px 0 0;font-size:12.5px;color:#64748b;line-height:1.5;">Use this Real ID to login at <span style="font-family:monospace;">/<?php echo admin_path_alias(); ?>/login.php</span> after setting password.</p>
                </div>
                <p id="quoteSuccessMsg" style="font-size:14px;color:var(--text-muted);margin-bottom:24px">Thank you for choosing Optibiz. Our team will review your requirements and contact you within 24 hours. You will receive emails with your Real IDs and next steps.</p>
                <div id="quoteEmailNotice" style="margin:0 auto 24px;max-width:460px;padding:12px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;font-size:13px;color:#166534;line-height:1.6;display:none;">
                    <i class="fa-solid fa-envelope" style="margin-right:6px;"></i> A confirmation email has been sent to your inbox with your Reference ID. You will also receive your <strong>Real Account ID</strong> (e.g. OPT-XXXXXX) and a secure link to set up your password.
                </div>
                <div id="quoteTenantEmailNotice" style="margin:0 auto 24px;max-width:460px;padding:12px 14px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px;font-size:13px;color:#4338ca;line-height:1.6;display:none;">
                    <i class="fa-solid fa-key" style="margin-right:6px;"></i> Your tenant account <strong id="quoteTenantIdInline" style="font-family:monospace;">OPT-XXXXXXXX</strong> has been created! Check your email for a secure link to set your password (valid 48h). Then login at <strong>/<?php echo admin_path_alias(); ?>/login.php</strong> using your Real ID, username or email.
                </div>
                <button type="button" class="btn-lime" id="quoteDoneBtn">Done</button>
            </div>

            <!-- Terms & Conditions Acceptance View -->
            <div id="quoteTermsView" hidden>
                <div style="text-align:center;margin-bottom:28px;">
                    <div style="width:64px;height:64px;background:linear-gradient(135deg, #c2f542, #a8e030);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;box-shadow:0 8px 24px rgba(194,245,66,0.3);">
                        <i class="fa-solid fa-file-contract" style="font-size:28px;color:#0f172a;"></i>
                    </div>
                    <h2 style="font-size:24px;font-weight:800;color:var(--primary-dark);margin-bottom:8px;">One Last Step</h2>
                    <p style="font-size:14px;color:var(--text-muted);">Please review and accept our Terms & Conditions to complete your registration</p>
                </div>

                <div style="max-width:520px;margin:0 auto 28px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;max-height:320px;overflow-y:auto;text-align:left;">
                    <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 14px;">Terms & Conditions</h3>
                    <div style="font-size:13px;line-height:1.7;color:#475569;">
                        <p style="margin:0 0 12px;"><strong>1. Account Creation:</strong> By creating an Optibiz account, you agree to provide accurate business information and maintain the security of your credentials.</p>
                        <p style="margin:0 0 12px;"><strong>2. Service Usage:</strong> You agree to use our platform for legitimate business purposes only, collecting genuine customer reviews and feedback.</p>
                        <p style="margin:0 0 12px;"><strong>3. Data Privacy:</strong> We collect and process customer data in accordance with our Privacy Policy. You are responsible for obtaining consent from your customers.</p>
                        <p style="margin:0 0 12px;"><strong>4. Payment Terms:</strong> Subscription fees are billed according to your selected plan. Cancellation policies apply as outlined in your plan details.</p>
                        <p style="margin:0 0 12px;"><strong>5. Content Ownership:</strong> Customer reviews and ratings belong to your business. We provide the platform and tools to collect and display them.</p>
                        <p style="margin:0 0 12px;"><strong>6. Prohibited Activities:</strong> You may not manipulate reviews, impersonate others, or use the platform for fraudulent purposes.</p>
                        <p style="margin:0 0 12px;"><strong>7. Service Changes:</strong> We reserve the right to modify features, pricing, or terms with reasonable notice to users.</p>
                        <p style="margin:0 0 0;"><strong>8. Termination:</strong> We may suspend or terminate accounts that violate these terms or engage in harmful activities.</p>
                    </div>
                </div>

                <div style="max-width:520px;margin:0 auto 24px;">
                    <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;padding:14px;background:#ffffff;border:2px solid #e2e8f0;border-radius:10px;transition:all 0.2s ease;" id="termsCheckboxLabel">
                        <input type="checkbox" id="termsCheckbox" style="width:20px;height:20px;margin-top:2px;cursor:pointer;accent-color:#c2f542;">
                        <span style="font-size:13.5px;line-height:1.6;color:#334155;">
                            I have read and agree to the <strong>Terms & Conditions</strong> and <strong>Privacy Policy</strong> of Optibiz. I understand that an account setup link will be sent to my email.
                        </span>
                    </label>
                </div>

                <div style="display:flex;gap:12px;justify-content:center;">
                    <button type="button" class="btn-back" id="termsBackBtn">
                        <i class="fa-solid fa-arrow-left"></i> Back
                    </button>
                    <button type="button" class="btn-lime" id="termsAcceptBtn" disabled style="opacity:0.5;cursor:not-allowed;">
                        Accept & Send Setup Link <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
    // Hero Visual Carousel Slider
    (function () {
        var sliderWrap = document.getElementById('heroSlider');
        if (!sliderWrap) return;

        var slides = sliderWrap.querySelectorAll('.hero-slide');
        var dots = sliderWrap.querySelectorAll('.hero-slider-dot');
        var prevBtn = document.getElementById('heroSliderPrev');
        var nextBtn = document.getElementById('heroSliderNext');
        var currentIndex = 0;
        var totalSlides = slides.length;
        var timer = null;
        var slideInterval = 5000; // 5 seconds per slide

        if (!totalSlides) return;

        function goToSlide(index) {
            if (index < 0) {
                index = totalSlides - 1;
            } else if (index >= totalSlides) {
                index = 0;
            }
            currentIndex = index;

            slides.forEach(function (slide, i) {
                slide.classList.toggle('active', i === currentIndex);
            });

            dots.forEach(function (dot, i) {
                dot.classList.toggle('active', i === currentIndex);
            });
        }

        function nextSlide() {
            goToSlide(currentIndex + 1);
        }

        function prevSlide() {
            goToSlide(currentIndex - 1);
        }

        function startAutoplay() {
            stopAutoplay();
            timer = setInterval(nextSlide, slideInterval);
        }

        function stopAutoplay() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                nextSlide();
                startAutoplay();
            });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                prevSlide();
                startAutoplay();
            });
        }

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                var targetIdx = parseInt(dot.getAttribute('data-slide-to'), 10);
                if (!isNaN(targetIdx)) {
                    goToSlide(targetIdx);
                    startAutoplay();
                }
            });
        });

        // Pause autoplay while hovering over slider or interacting with demo card
        sliderWrap.addEventListener('mouseenter', stopAutoplay);
        sliderWrap.addEventListener('mouseleave', startAutoplay);
        sliderWrap.addEventListener('focusin', stopAutoplay);
        sliderWrap.addEventListener('focusout', startAutoplay);

        // Start autoplay on load
        startAutoplay();
    })();

    // Live Interactive Demo Stars
    (function () {
        var starButtons = document.querySelectorAll('#demoStars .star-btn');
        var feedbackBox = document.getElementById('demoFeedback');
        if (!starButtons.length || !feedbackBox) return;

        starButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var score = parseInt(btn.getAttribute('data-score'), 10);
                starButtons.forEach(function (b) {
                    var s = parseInt(b.getAttribute('data-score'), 10);
                    b.classList.toggle('active', s <= score);
                });

                if (score >= 4) {
                    feedbackBox.innerHTML = '<div class="routing-pill google"><i class="fa-brands fa-google"></i> Google Review Booster Triggered!</div>' +
                        '<p style="color:#166534;font-size:12.5px;font-weight:600">★ ' + score + '-Star score detected: Customer is auto-redirected to Google Reviews &amp; an Instagram Social Proof Card is generated!</p>';
                } else {
                    feedbackBox.innerHTML = '<div class="routing-pill private"><i class="fa-solid fa-shield-halved"></i> Private Feedback Shield Activated</div>' +
                        '<p style="color:#c2410c;font-size:12.5px;font-weight:600">⚠ ' + score + '-Star score caught: Routed privately to the manager before reaching Google or social media!</p>';
                }
            });
        });
    })();

    // Mobile Navigation Drawer Toggle Logic
    var closeMobileNav;
    (function () {
        var toggleBtn = document.getElementById('mobileNavToggle');
        var drawer    = document.getElementById('mobileNavDrawer');
        var overlay   = document.getElementById('mobileNavOverlay');
        if (!toggleBtn || !drawer || !overlay) return;

        function openNav() {
            toggleBtn.classList.add('is-active');
            toggleBtn.setAttribute('aria-expanded', 'true');
            drawer.classList.add('is-open');
            drawer.setAttribute('aria-hidden', 'false');
            overlay.classList.add('is-open');
            document.body.classList.add('mobile-nav-open');
        }

        closeMobileNav = function () {
            toggleBtn.classList.remove('is-active');
            toggleBtn.setAttribute('aria-expanded', 'false');
            drawer.classList.remove('is-open');
            drawer.setAttribute('aria-hidden', 'true');
            overlay.classList.remove('is-open');
            document.body.classList.remove('mobile-nav-open');
        };

        window.closeMobileNav = closeMobileNav;

        toggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (drawer.classList.contains('is-open')) {
                closeMobileNav();
            } else {
                openNav();
            }
        });

        overlay.addEventListener('click', closeMobileNav);

        var drawerLinks = drawer.querySelectorAll('.mobile-nav-link');
        for (var i = 0; i < drawerLinks.length; i++) {
            drawerLinks[i].addEventListener('click', function () {
                closeMobileNav();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
                closeMobileNav();
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 991 && drawer.classList.contains('is-open')) {
                closeMobileNav();
            }
        });
    })();

    // Modal logic & preselection
    var openModal;
    (function () {
        'use strict';
        var modal       = document.getElementById('quoteModal');
        var closeBtn    = document.getElementById('quoteModalClose');
        var formWrap    = document.getElementById('quoteFormWrap');
        var successView = document.getElementById('quoteSuccess');
        var termsView   = document.getElementById('quoteTermsView');
        var form        = document.getElementById('quoteForm');
        var sections    = form.querySelectorAll('.form-section');
        var stepWraps   = document.querySelectorAll('#quoteProgress .step-wrap');
        var stepLines   = document.querySelectorAll('#quoteProgress .step-line');
        var backBtn     = document.getElementById('quoteBackBtn');
        var nextBtn     = document.getElementById('quoteNextBtn');
        var submitBtn   = document.getElementById('quoteSubmitBtn');
        var cancelBtn   = document.getElementById('quoteCancelBtn');
        var alertBox    = document.getElementById('quoteAlert');
        var termsCheckbox = document.getElementById('termsCheckbox');
        var termsAcceptBtn = document.getElementById('termsAcceptBtn');
        var termsBackBtn = document.getElementById('termsBackBtn');

        var TOTAL_STEPS = sections.length;
        var currentStep = 1;
        var submitting  = false;
        var pendingFormData = null;

        function hideAlert() {
            alertBox.hidden = true;
            alertBox.textContent = '';
            alertBox.className = 'form-alert';
        }
        function showAlert(message) {
            alertBox.className = 'form-alert';
            alertBox.textContent = message;
            alertBox.hidden = false;
        }
        function showDuplicateAlert(data) {
            alertBox.className = 'form-alert-duplicate';
            alertBox.hidden = false;
            alertBox.innerHTML =
                '<strong>⚠ Duplicate Request Detected</strong>' +
                (data.message || 'A request from this company already exists.') +
                (data.existing_ref ? '<br><span class="dup-ref">Reference: ' + data.existing_ref + ' &nbsp;|&nbsp; Status: ' + (data.existing_status || '—') + ' &nbsp;|&nbsp; Submitted: ' + (data.existing_date || '—') + '</span>' : '');
        }
        function clearInvalidMarks(scope) {
            var fields = scope.querySelectorAll('.invalid');
            for (var i = 0; i < fields.length; i++) {
                fields[i].classList.remove('invalid');
            }
        }
        function validateStep(step) {
            var scope = sections[step - 1];
            var fields = scope.querySelectorAll('input[required], select[required]');
            var firstInvalid = null;
            clearInvalidMarks(scope);
            for (var i = 0; i < fields.length; i++) {
                if (!fields[i].checkValidity()) {
                    fields[i].classList.add('invalid');
                    if (!firstInvalid) { firstInvalid = fields[i]; }
                }
            }
            if (firstInvalid) {
                showAlert(firstInvalid.validationMessage || 'Please fill in all required fields.');
                firstInvalid.focus();
                return false;
            }
            return true;
        }

        function renderStep() {
            for (var i = 0; i < TOTAL_STEPS; i++) {
                sections[i].classList.toggle('active', i === currentStep - 1);
                if (stepWraps[i]) {
                    stepWraps[i].classList.toggle('active', i === currentStep - 1);
                    stepWraps[i].classList.toggle('completed', i < currentStep - 1);
                }
                if (stepLines[i]) {
                    stepLines[i].classList.toggle('active', i < currentStep - 1);
                }
            }
            backBtn.hidden   = currentStep === 1 || currentStep === TOTAL_STEPS;
            nextBtn.hidden   = currentStep === TOTAL_STEPS;
            submitBtn.hidden = currentStep !== TOTAL_STEPS;
            cancelBtn.hidden = currentStep !== TOTAL_STEPS;
            hideAlert();
        }

        openModal = function (planId) {
            currentStep = 1;
            form.reset();
            if (planId) {
                var planSelect = document.getElementById('q_plan');
                if (planSelect) { planSelect.value = planId; }
            }
            renderStep();
            clearInvalidMarks(form);
            hideAlert();
            formWrap.hidden = false;
            successView.hidden = true;
            termsView.hidden = true;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            var firstInput = form.querySelector('.form-section.active input, .form-section.active select');
            if (firstInput) { firstInput.focus(); }
        };

        function closeModal() {
            modal.classList.remove('active');
            document.body.style.overflow = '';
            pendingFormData = null;
        }

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        document.getElementById('quoteDoneBtn').addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
        });

        nextBtn.addEventListener('click', function () {
            if (validateStep(currentStep) && currentStep < TOTAL_STEPS) {
                currentStep++;
                renderStep();
            }
        });
        backBtn.addEventListener('click', function () {
            if (currentStep > 1) {
                currentStep--;
                renderStep();
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (submitting) return;
            if (!validateStep(currentStep)) return;

            // Store form data and show terms view instead of submitting
            pendingFormData = new FormData(form);
            formWrap.hidden = true;
            termsView.hidden = false;
            termsCheckbox.checked = false;
            termsAcceptBtn.disabled = true;
            termsAcceptBtn.style.opacity = '0.5';
            termsAcceptBtn.style.cursor = 'not-allowed';
        });

        // Enable accept button when checkbox is checked
        termsCheckbox.addEventListener('change', function() {
            if (this.checked) {
                termsAcceptBtn.disabled = false;
                termsAcceptBtn.style.opacity = '1';
                termsAcceptBtn.style.cursor = 'pointer';
            } else {
                termsAcceptBtn.disabled = true;
                termsAcceptBtn.style.opacity = '0.5';
                termsAcceptBtn.style.cursor = 'not-allowed';
            }
        });

        // Go back from terms to form
        termsBackBtn.addEventListener('click', function() {
            termsView.hidden = true;
            formWrap.hidden = false;
        });

        // Accept terms and submit form
        termsAcceptBtn.addEventListener('click', function() {
            if (!termsCheckbox.checked || submitting || !pendingFormData) return;

            submitting = true;
            this.disabled = true;
            this.innerHTML = 'Sending Setup Link <i class="fa-solid fa-circle-notch fa-spin"></i>';

            fetch(form.action, {
                method: 'POST',
                body: pendingFormData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    // Real IDs (not DB ids) — QTE for quota, OPT for tenant
                    var realId = data.public_id || data.reference_id || data.id || '';
                    var tenantRealId = data.tenant_public_id || data.tenant_id_real || data.tenant_real_id || '';
                    var idBox = document.getElementById('quoteRealIdBox');
                    var idEl = document.getElementById('quoteRealId');
                    var tenantBox = document.getElementById('quoteTenantIdBox');
                    var tenantEl = document.getElementById('quoteTenantId');
                    var tenantInline = document.getElementById('quoteTenantIdInline');
                    var emailNotice = document.getElementById('quoteEmailNotice');
                    var tenantEmailNotice = document.getElementById('quoteTenantEmailNotice');
                    var msgEl = document.getElementById('quoteSuccessMsg');
                    if (realId && idEl && idBox) {
                        idEl.textContent = realId;
                        idBox.style.display = 'block';
                    }
                    if (tenantRealId && tenantEl && tenantBox) {
                        tenantEl.textContent = tenantRealId;
                        tenantBox.style.display = 'block';
                        if (tenantInline) tenantInline.textContent = tenantRealId;
                    }
                    if (msgEl) {
                        if (tenantRealId) {
                            msgEl.innerHTML = 'Thank you for choosing Optibiz! Your quota request <strong style="font-family:monospace;color:#3f6212;">' + realId + '</strong> has been received and your tenant account <strong style="font-family:monospace;color:#4338ca;">' + tenantRealId + '</strong> has been created. Check your email to set your password.';
                        } else if (realId) {
                            msgEl.innerHTML = 'Thank you for choosing Optibiz! Your quota request <strong style="font-family:monospace;color:#3f6212;">' + realId + '</strong> has been received. Our team will review it within 24 hours.';
                        }
                    }
                    if (emailNotice) {
                        emailNotice.style.display = data.email_sent ? 'block' : 'none';
                        if (!data.email_sent && realId) {
                            emailNotice.style.display = 'block';
                            emailNotice.innerHTML = '<i class="fa-solid fa-envelope" style="margin-right:6px;"></i> Your Reference ID is <strong style="font-family:monospace;">' + realId + '</strong>. You will receive an email with your <strong>Real Account ID</strong> (e.g. OPT-XXXXXX) and a secure link to set up your password at /<?php echo admin_path_alias(); ?>/login.php';
                        }
                    }
                    if (tenantEmailNotice) {
                        if (tenantRealId && data.tenant_setup_email_sent) {
                            tenantEmailNotice.style.display = 'block';
                        } else if (tenantRealId) {
                            tenantEmailNotice.style.display = 'block';
                            tenantEmailNotice.innerHTML = '<i class="fa-solid fa-envelope" style="margin-right:6px;"></i> Your Real Account ID is <strong style="font-family:monospace;">' + tenantRealId + '</strong>. If email delivery fails, contact support or use <a href="<?php echo admin_url('forgot-password.php'); ?>" style="color:#4338ca;font-weight:700;">Forgot Password</a> to resend setup link.';
                        } else {
                            tenantEmailNotice.style.display = 'none';
                        }
                    }
                    termsView.hidden = true;
                    successView.hidden = false;
                } else {
                    // Show error in terms view
                    alert(data.message || 'Something went wrong. Please try again.');
                    termsView.hidden = true;
                    formWrap.hidden = false;
                    if (data.duplicate) {
                        showDuplicateAlert(data);
                    } else {
                        showAlert(data.message || 'Something went wrong. Please try again.');
                    }
                }
            })
            .catch(function () {
                alert('Could not connect to the server. Please try again.');
                termsView.hidden = true;
                formWrap.hidden = false;
            })
            .finally(function () {
                submitting = false;
                termsAcceptBtn.disabled = false;
                termsAcceptBtn.innerHTML = 'Accept & Send Setup Link <i class="fa-solid fa-paper-plane"></i>';
            });
        });

        // Auto open if #get-started or ?plan=X in URL
        var urlParams = new URLSearchParams(window.location.search);
        var planFromUrl = urlParams.get('plan');
        if (window.location.hash === '#get-started' || planFromUrl) {
            setTimeout(function () {
                openModal(planFromUrl);
            }, 300);
        }
    })();
    </script>
</body>
</html>
