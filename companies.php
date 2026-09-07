<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Company list joined with tenant branding (logo + banner) and its review
// summary. c.* carries whatsapp_number, which drives the chat button below.
$customers = $conn->query("SELECT c.*, cat.name as category_name, t.company_name AS tenant_name, t.logo AS tenant_logo, t.banner AS tenant_banner,
                                  (SELECT COUNT(*) FROM ratings r WHERE r.company_id = c.id) AS rating_count,
                                  (SELECT AVG(r.rating) FROM ratings r WHERE r.company_id = c.id) AS avg_rating
                           FROM customers c
                           LEFT JOIN categories cat ON c.category_id = cat.id
                           LEFT JOIN tenants t ON c.tenant_id = t.id
                           ORDER BY c.company_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Companies - Optibiz Rating Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-dark: #0f2438;
            --accent-lime: #c2f542;
            --accent-lime-hover: #a8e030;
            --card-dark: #1a3852;
            --accent-green-bg: #ecfccb;
            --accent-green-text: #4d7c0f;
            --text-muted: #64748b;
            --star: #fbbf24;
            --line: #e6ebf1;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #1e293b;
            background: #f5f7fb;
            overflow-x: hidden;
        }

        /* ===== Header & Navigation (matches index.php) ===== */
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
            padding: 18px 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
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
        }
        .nav-pill {
            display: flex;
            align-items: center;
            gap: 32px;
        }
        .nav-pill a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.25s ease;
            position: relative;
            padding: 6px 0;
        }
        .nav-pill a:hover { color: var(--accent-lime); }
        .nav-pill a.active { color: #ffffff; }
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
        }
        .btn-quote:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(194, 245, 66, 0.3);
        }
/* ===== Company Cards Grid ===== */
        .cmp-companies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 28px;
        }
        .cmp-company-card {
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid var(--line);
            box-shadow: 0 2px 12px rgba(15,23,42,.05);
            transition: transform .3s ease, box-shadow .3s ease, border-color .3s ease;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .cmp-company-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 42px rgba(15,23,42,.12);
            border-color: rgba(194,245,66,.6);
        }

        /* Banner */
        .cmp-card-banner {
            height: 140px;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #1a3852, #2c5c8a);
        }
        .cmp-card-banner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform .5s ease;
        }
        .cmp-company-card:hover .cmp-card-banner img { transform: scale(1.06); }
        .cmp-banner-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 46px;
            color: rgba(255,255,255,.25);
            background:
                radial-gradient(circle at 20% 30%, rgba(194,245,66,.2), transparent 45%),
                linear-gradient(135deg, #1a3852, #2c5c8a);
        }
        .cmp-card-category {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(15,36,56,.82);
            color: #fff;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 11.5px;
            font-weight: 700;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,.18);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .cmp-card-category i { color: var(--accent-lime); font-size: 10px; }

        /* Logo badge overlapping banner */
        .cmp-card-logo-wrap {
            margin-top: -34px;
            padding: 0 22px;
            position: relative;
            z-index: 3;
        }
        .cmp-card-logo {
            width: 68px;
            height: 68px;
            border-radius: 16px;
            background: #fff;
            border: 3px solid #fff;
            box-shadow: 0 6px 18px rgba(15,23,42,.18);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cmp-card-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .cmp-card-logo .cmp-initials {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #c2f542, #84cc16);
            color: #0f2438;
            font-weight: 800;
            font-size: 22px;
        }

        /* Card body */
        .cmp-card-body {
            padding: 16px 22px 22px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .cmp-company-name {
            font-size: 20px;
            font-weight: 800;
            color: #0f2438;
            margin-bottom: 4px;
            letter-spacing: -.3px;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .cmp-company-name i { color: #2563eb; font-size: 14px; }
        .cmp-tenant-name {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .cmp-tenant-name i { color: var(--accent-lime-hover); font-size: 11px; }

        .cmp-company-info {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-bottom: 16px;
        }
        .cmp-info-item {
            display: flex;
            align-items: center;
            gap: 9px;
            color: #475569;
            font-size: 13px;
            min-width: 0;
        }
        .cmp-info-item i {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            background: #f1f5f9;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }
        .cmp-info-item span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cmp-info-item a.cmp-info-link {
            color: inherit;
            text-decoration: none;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .cmp-info-item a.cmp-info-link:hover { color: var(--primary-dark); text-decoration: underline; }

        /* Rating summary above the action buttons */
        .cmp-card-rating {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 12px;
            background: var(--accent-green-bg);
            color: var(--accent-green-text);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .cmp-card-rating .cmp-stars { color: var(--star); letter-spacing: 1px; }
        .cmp-card-rating .cmp-rating-count { font-weight: 500; color: var(--text-muted); margin-left: auto; }

        /* ===== Action buttons (incl. WhatsApp click-to-chat) ===== */
        .cmp-card-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
            flex-wrap: wrap;
        }
        .cmp-btn {
            flex: 1 1 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
            white-space: nowrap;
        }
        .cmp-btn:hover { transform: translateY(-2px); }
        .cmp-btn-view {
            background: var(--primary-dark);
            color: #ffffff;
        }
        .cmp-btn-view:hover { box-shadow: 0 10px 24px rgba(15,36,56,.28); }
        .cmp-btn-whatsapp {
            background: #25D366;
            color: #ffffff;
        }
        .cmp-btn-whatsapp:hover {
            background: #1fb457;
            box-shadow: 0 10px 24px rgba(37,211,102,.34);
        }

        /* ===== Page shell ===== */
        .cmp-page {
            max-width: 1280px;
            margin: 0 auto;
            padding: 46px 5% 70px;
        }
        .cmp-page-head { margin-bottom: 34px; }
        .cmp-page-head h1 {
            font-size: 34px;
            font-weight: 800;
            color: var(--primary-dark);
            letter-spacing: -.6px;
            margin-bottom: 8px;
        }
        .cmp-page-head p {
            color: var(--text-muted);
            font-size: 15px;
            max-width: 640px;
            line-height: 1.6;
        }
        .cmp-empty {
            background: #ffffff;
            border: 1px dashed #cbd5e1;
            border-radius: 18px;
            padding: 60px 24px;
            text-align: center;
            color: var(--text-muted);
        }
        .cmp-empty i { font-size: 34px; color: #cbd5e1; display: block; margin-bottom: 14px; }
        .cmp-footer {
            background: var(--primary-dark);
            color: #cbd5e1;
            text-align: center;
            padding: 26px 5%;
            font-size: 13.5px;
        }

        @media (max-width: 640px) {
            .navbar { flex-wrap: wrap; gap: 14px; }
            .nav-pill { gap: 18px; }
            .cmp-page-head h1 { font-size: 27px; }
            .cmp-card-actions .cmp-btn { flex: 1 1 100%; }
        }
    </style>
</head>
<body>

    <!-- Header & Navigation (matches index.php) -->
    <div class="top-bar-wrap">
        <header class="navbar">
            <a href="index.php" class="logo">
                <span class="logo-icon"><i class="fa-solid fa-shapes"></i></span>
                Optibiz
            </a>
            <nav class="nav-pill">
                <a href="index.php">Home</a>
                <a href="companies.php" class="active">Companies</a>
                <a href="index.php#about">About Us</a>
                <a href="index.php#contact">Contact</a>
            </nav>
            <a href="index.php#get-started" class="btn-quote">
                Get Started <i class="fa-solid fa-arrow-right"></i>
            </a>
        </header>
    </div>

    <main class="cmp-page">
        <div class="cmp-page-head">
            <h1>Companies on Optibiz</h1>
            <p>Read verified customer reviews, then talk to the business directly — every listing with a
               WhatsApp number carries a one-tap <strong>Chat on WhatsApp</strong> button.</p>
        </div>

        <?php if ($customers && $customers->num_rows > 0): ?>
        <div class="cmp-companies-grid">
            <?php while ($c = $customers->fetch_assoc()): ?>
            <?php
                $c_name      = (string)($c['company_name'] ?? '');
                $c_logo      = (string)($c['tenant_logo'] ?? '');
                $c_banner    = (string)($c['tenant_banner'] ?? '');
                $c_initials  = getInitials($c_name);
                // '' when this business has not published a WhatsApp number.
                $c_whatsapp  = whatsappChatUrl($c['whatsapp_number'] ?? '', $c_name);
                $c_reviews   = (int)($c['rating_count'] ?? 0);
                $c_avg       = (float)($c['avg_rating'] ?? 0);
            ?>
            <article class="cmp-company-card">
                <div class="cmp-card-banner">
                    <?php if ($c_banner !== ''): ?>
                        <img src="<?php echo htmlspecialchars($c_banner); ?>" alt="<?php echo htmlspecialchars($c_name); ?> cover image">
                    <?php else: ?>
                        <div class="cmp-banner-fallback"><i class="fa-solid fa-building"></i></div>
                    <?php endif; ?>
                    <?php if (!empty($c['category_name'])): ?>
                        <span class="cmp-card-category"><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($c['category_name']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="cmp-card-logo-wrap">
                    <div class="cmp-card-logo">
                        <?php if ($c_logo !== ''): ?>
                            <img src="<?php echo htmlspecialchars($c_logo); ?>" alt="<?php echo htmlspecialchars($c_name); ?> logo">
                        <?php else: ?>
                            <span class="cmp-initials"><?php echo htmlspecialchars($c_initials); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="cmp-card-body">
                    <h2 class="cmp-company-name">
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($c_name); ?>
                    </h2>
                    <?php if (!empty($c['tenant_name'])): ?>
                        <div class="cmp-tenant-name">
                            <i class="fa-solid fa-briefcase" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($c['tenant_name']); ?>
                        </div>
                    <?php endif; ?>

                    <div class="cmp-company-info">
                        <?php if (!empty($c['email'])): ?>
                            <div class="cmp-info-item">
                                <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                                <a class="cmp-info-link" href="mailto:<?php echo htmlspecialchars($c['email']); ?>"><?php echo htmlspecialchars($c['email']); ?></a>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($c['phone'])): ?>
                            <div class="cmp-info-item">
                                <i class="fa-solid fa-phone" aria-hidden="true"></i>
                                <a class="cmp-info-link" href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $c['phone'])); ?>"><?php echo htmlspecialchars($c['phone']); ?></a>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($c['website'])): ?>
                            <div class="cmp-info-item">
                                <i class="fa-solid fa-globe" aria-hidden="true"></i>
                                <a class="cmp-info-link" href="<?php echo htmlspecialchars(preg_match('#^https?://#i', $c['website']) ? $c['website'] : 'https://' . $c['website']); ?>"
                                   target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($c['website']); ?></a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($c_reviews > 0): ?>
                        <div class="cmp-card-rating">
                            <span class="cmp-stars" aria-hidden="true">★</span>
                            <span><?php echo number_format($c_avg, 1); ?> average</span>
                            <span class="cmp-rating-count"><?php echo number_format($c_reviews); ?> review<?php echo $c_reviews === 1 ? '' : 's'; ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="cmp-card-actions">
                        <a class="cmp-btn cmp-btn-view" href="rate/index.php?company=<?php echo (int)$c['id']; ?>">
                            <i class="fa-solid fa-star" aria-hidden="true"></i> View &amp; Rate
                        </a>
                        <?php if ($c_whatsapp !== ''): ?>
                        <!-- WhatsApp click-to-chat -->
                        <a class="cmp-btn cmp-btn-whatsapp" href="<?php echo htmlspecialchars($c_whatsapp); ?>"
                           target="_blank" rel="noopener noreferrer">
                            <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Chat on WhatsApp
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <div class="cmp-empty">
            <i class="fa-solid fa-store" aria-hidden="true"></i>
            <strong>No companies listed yet.</strong>
            <p style="margin-top:6px;">Businesses appear here as soon as they register their profile.</p>
        </div>
        <?php endif; ?>
    </main>

    <footer class="cmp-footer">
        &copy; <?php echo date('Y'); ?> Optibiz Rating Platform &middot; Verified reviews &amp; direct WhatsApp contact
    </footer>

</body>
</html>
