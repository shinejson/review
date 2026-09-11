<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Category icon helper
function getCategoryMeta($category_name) {
    $cat = strtolower(trim((string)$category_name));
    if (strpos($cat, 'hosp') !== false || strpos($cat, 'hotel') !== false || strpos($cat, 'restaurant') !== false || strpos($cat, 'food') !== false || strpos($cat, 'cafe') !== false) {
        return ['icon' => 'fa-utensils', 'color' => '#d97706', 'bg' => '#fef3c7', 'border' => '#fde68a'];
    }
    if (strpos($cat, 'tech') !== false || strpos($cat, 'software') !== false || strpos($cat, 'it') !== false) {
        return ['icon' => 'fa-laptop-code', 'color' => '#2563eb', 'bg' => '#dbeafe', 'border' => '#bfdbfe'];
    }
    if (strpos($cat, 'health') !== false || strpos($cat, 'medic') !== false || strpos($cat, 'clinic') !== false || strpos($cat, 'dental') !== false) {
        return ['icon' => 'fa-heart-pulse', 'color' => '#059669', 'bg' => '#d1fae5', 'border' => '#a7f3d0'];
    }
    if (strpos($cat, 'finan') !== false || strpos($cat, 'bank') !== false || strpos($cat, 'account') !== false || strpos($cat, 'insur') !== false) {
        return ['icon' => 'fa-chart-line', 'color' => '#7c3aed', 'bg' => '#ede9fe', 'border' => '#ddd6fe'];
    }
    if (strpos($cat, 'retail') !== false || strpos($cat, 'shop') !== false || strpos($cat, 'store') !== false || strpos($cat, 'boutique') !== false) {
        return ['icon' => 'fa-bag-shopping', 'color' => '#db2777', 'bg' => '#fce7f3', 'border' => '#fbcfe8'];
    }
    if (strpos($cat, 'manufact') !== false || strpos($cat, 'indus') !== false) {
        return ['icon' => 'fa-industry', 'color' => '#475569', 'bg' => '#f1f5f9', 'border' => '#e2e8f0'];
    }
    if (strpos($cat, 'auto') !== false || strpos($cat, 'car') !== false || strpos($cat, 'transport') !== false) {
        return ['icon' => 'fa-car', 'color' => '#dc2626', 'bg' => '#fee2e2', 'border' => '#fecaca'];
    }
    return ['icon' => 'fa-shapes', 'color' => '#0f2438', 'bg' => '#e2e8f0', 'border' => '#cbd5e1'];
}

// 1. Fetch Top 3 Daily Ranked & Most Clicked Companies
$top3_query = "
    SELECT c.*, cat.name AS category_name, t.company_name AS tenant_name, t.logo AS tenant_logo, t.banner AS tenant_banner,
           (SELECT COUNT(*) FROM ratings r WHERE r.company_id = c.id AND r.reported = 0) AS rating_count,
           (SELECT AVG(r.rating) FROM ratings r WHERE r.company_id = c.id AND r.reported = 0) AS avg_rating,
           (SELECT COUNT(*) FROM analytics_events a 
             WHERE a.company_id = c.id 
               AND a.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND a.event_type IN ('page_view', 'whatsapp_click', 'qr_scan', 'company_click')) AS clicks_24h,
           (SELECT COUNT(*) FROM analytics_events a 
             WHERE a.company_id = c.id 
               AND a.event_type IN ('page_view', 'whatsapp_click', 'qr_scan', 'company_click')) AS total_clicks,
           (SELECT COUNT(*) FROM ratings r 
             WHERE r.company_id = c.id 
               AND r.reported = 0 
               AND r.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS ratings_24h
    FROM customers c
    LEFT JOIN categories cat ON c.category_id = cat.id
    LEFT JOIN tenants t ON c.tenant_id = t.id
    ORDER BY 
        ((clicks_24h * 10) + (ratings_24h * 15) + (total_clicks * 2) + (rating_count * 4) + (IFNULL(avg_rating, 0) * 5)) DESC,
        rating_count DESC,
        IFNULL(avg_rating, 0) DESC,
        c.id ASC
    LIMIT 3
";
$top3 = [];
$r3 = $conn->query($top3_query);
if ($r3) {
    while ($row = $r3->fetch_assoc()) {
        $top3[] = $row;
    }
}

// 2. Fetch All Companies Grouped by Category
$all_query = "
    SELECT c.*, cat.id AS cat_id, IFNULL(cat.name, 'Other Businesses') AS category_name, 
           t.company_name AS tenant_name, t.logo AS tenant_logo, t.banner AS tenant_banner,
           (SELECT COUNT(*) FROM ratings r WHERE r.company_id = c.id AND r.reported = 0) AS rating_count,
           (SELECT AVG(r.rating) FROM ratings r WHERE r.company_id = c.id AND r.reported = 0) AS avg_rating,
           (SELECT COUNT(*) FROM analytics_events a 
             WHERE a.company_id = c.id 
               AND a.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND a.event_type IN ('page_view', 'whatsapp_click', 'qr_scan', 'company_click')) AS clicks_24h,
           (SELECT COUNT(*) FROM analytics_events a 
             WHERE a.company_id = c.id 
               AND a.event_type IN ('page_view', 'whatsapp_click', 'qr_scan', 'company_click')) AS total_clicks
    FROM customers c
    LEFT JOIN categories cat ON c.category_id = cat.id
    LEFT JOIN tenants t ON c.tenant_id = t.id
    ORDER BY cat.name ASC, rating_count DESC, avg_rating DESC, c.company_name ASC
";
$categories_grouped = [];
$total_companies_count = 0;
$r_all = $conn->query($all_query);
if ($r_all) {
    while ($row = $r_all->fetch_assoc()) {
        $c_name = $row['category_name'];
        $categories_grouped[$c_name][] = $row;
        $total_companies_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Directory &amp; Daily Leaderboard — Optibiz</title>
    <meta name="description" content="Discover verified local businesses grouped by category. Explore today's top 3 most clicked and highest ranked companies, read real reviews, and connect on WhatsApp.">
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
            --star: #f59e0b;
            --gold: #f59e0b;
            --silver: #94a3b8;
            --bronze: #d97706;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: var(--text-main);
            background: #f8fafc;
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
            gap: 26px;
        }
        .nav-pill a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.25s ease;
            padding: 6px 0;
            position: relative;
        }
        .nav-pill a:hover {
            color: var(--accent-lime);
        }
        .nav-pill a.active {
            color: #ffffff;
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
        }
        .btn-signin {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.2s;
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
        }
        .btn-quote:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(194, 245, 66, 0.3);
        }

        /* Hero Banner */
        .directory-hero {
            background: radial-gradient(circle at 80% 20%, #1a3c5a 0%, var(--primary-dark) 70%);
            padding: 60px 5% 70px;
            color: #ffffff;
            text-align: center;
            position: relative;
        }
        .hero-inner {
            max-width: 860px;
            margin: 0 auto;
        }
        .badge-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(194, 245, 66, 0.15);
            color: var(--accent-lime);
            border: 1px solid rgba(194, 245, 66, 0.3);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .directory-hero h1 {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.5px;
            margin-bottom: 14px;
        }
        .directory-hero h1 span {
            color: var(--accent-lime);
        }
        .directory-hero p {
            font-size: 16.5px;
            color: #94a3b8;
            max-width: 650px;
            margin: 0 auto 32px;
            line-height: 1.6;
        }

        /* Search & Filter Bar */
        .search-filter-box {
            background: #ffffff;
            border-radius: 50px;
            padding: 8px 12px 8px 24px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            max-width: 680px;
            margin: 0 auto 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .search-filter-box i {
            color: #94a3b8;
            font-size: 18px;
        }
        .search-filter-box input {
            border: none;
            outline: none;
            width: 100%;
            font-size: 15px;
            font-family: inherit;
            color: var(--text-main);
        }
        .search-filter-box button.btn-clear-search {
            background: #f1f5f9;
            border: none;
            color: #64748b;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: none;
        }
        .search-filter-box button.btn-clear-search:hover {
            background: #e2e8f0;
            color: var(--primary-dark);
        }

        /* Quick Category Jump Tabs */
        .category-chips-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            max-width: 1000px;
            margin: 0 auto;
        }
        .cat-chip {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            color: #cbd5e1;
            padding: 7px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            cursor: pointer;
        }
        .cat-chip:hover, .cat-chip.active {
            background: var(--accent-lime);
            color: var(--primary-dark);
            border-color: var(--accent-lime);
            transform: translateY(-2px);
        }
        .cat-chip .chip-count {
            background: rgba(0, 0, 0, 0.2);
            color: inherit;
            padding: 2px 7px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }

        /* Main Content Shell */
        .directory-main {
            max-width: 1280px;
            margin: 0 auto;
            padding: 50px 5% 90px;
        }

        /* ============================================================
         * SECTION 1: TOP 3 DAILY LEADERBOARD
         * ============================================================ */
        .leaderboard-section {
            background: linear-gradient(180deg, #ffffff 0%, #fdfdfd 100%);
            border-radius: 24px;
            border: 1px solid var(--border-line);
            padding: 40px;
            box-shadow: 0 10px 40px rgba(15, 23, 42, 0.05);
            margin-bottom: 60px;
            position: relative;
            overflow: hidden;
        }
        .leaderboard-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #f59e0b, #eab308, #84cc16, #06b6d4);
        }
        .leaderboard-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .leaderboard-title-wrap {
            max-width: 700px;
        }
        .tag-leaderboard {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .leaderboard-title {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary-dark);
            margin-bottom: 6px;
            letter-spacing: -0.4px;
        }
        .leaderboard-sub {
            font-size: 14px;
            color: var(--text-muted);
        }
        .leaderboard-live-badge {
            background: #f8fafc;
            border: 1px solid var(--border-line);
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 12.5px;
            color: var(--text-muted);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.25);
            display: inline-block;
        }

        /* Top 3 Podium Grid */
        .top3-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            align-items: stretch;
        }

        /* Podium Cards */
        .podium-card {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid var(--border-line);
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
            display: flex;
            flex-direction: column;
            position: relative;
            transition: all 0.3s ease;
        }
        .podium-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
        }

        /* Rank 1 Highlight */
        .podium-card.rank-1 {
            border: 2px solid #f59e0b;
            box-shadow: 0 12px 35px rgba(245, 158, 11, 0.15);
            transform: scale(1.02);
        }
        .podium-card.rank-1:hover {
            transform: scale(1.02) translateY(-8px);
        }
        .podium-card.rank-2 {
            border: 2px solid #94a3b8;
        }
        .podium-card.rank-3 {
            border: 2px solid #d97706;
        }

        /* Rank Ribbons */
        .podium-rank-ribbon {
            position: absolute;
            top: 14px;
            left: 14px;
            z-index: 5;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .rank-1 .podium-rank-ribbon {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #ffffff;
        }
        .rank-2 .podium-rank-ribbon {
            background: linear-gradient(135deg, #64748b, #475569);
            color: #ffffff;
        }
        .rank-3 .podium-rank-ribbon {
            background: linear-gradient(135deg, #b45309, #78350f);
            color: #ffffff;
        }

        /* ============================================================
         * COMMON COMPANY CARD STYLES (Used for Top 3 & Categorized Cards)
         * ============================================================ */
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
            transition: transform 0.5s ease;
        }
        .podium-card:hover .cmp-card-banner img,
        .cmp-company-card:hover .cmp-card-banner img {
            transform: scale(1.06);
        }
        .cmp-banner-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 44px;
            color: rgba(255, 255, 255, 0.25);
            background: radial-gradient(circle at 20% 30%, rgba(194, 245, 66, 0.2), transparent 45%),
                        linear-gradient(135deg, #1a3852, #2c5c8a);
        }
        .cmp-card-category {
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(15, 36, 56, 0.85);
            color: #ffffff;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 11.5px;
            font-weight: 700;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            z-index: 4;
        }

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
            background: #ffffff;
            border: 3px solid #ffffff;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.15);
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

        /* Card Body */
        .cmp-card-body {
            padding: 16px 22px 22px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .cmp-company-name {
            font-size: 19px;
            font-weight: 800;
            color: var(--primary-dark);
            margin-bottom: 4px;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .cmp-company-name i { color: #2563eb; font-size: 14px; }
        .cmp-tenant-name {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Daily Momentum Counter Badge */
        .cmp-momentum-bar {
            background: #f1f5f9;
            border-radius: 10px;
            padding: 8px 12px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
        }
        .momentum-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
            color: var(--primary-dark);
        }
        .momentum-item i {
            color: #10b981;
        }

        /* Company Info Items */
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
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #f1f5f9;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
        }
        .cmp-info-item a.cmp-info-link {
            color: inherit;
            text-decoration: none;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .cmp-info-item a.cmp-info-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        /* Rating pill */
        .cmp-card-rating {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 10px;
            background: var(--accent-green-bg);
            color: var(--accent-green-text);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .cmp-card-rating .cmp-stars { color: var(--star); letter-spacing: 1px; }
        .cmp-card-rating .cmp-rating-count { font-weight: 500; color: var(--text-muted); margin-left: auto; }

        /* Action Buttons */
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
            transition: all 0.2s ease;
            white-space: nowrap;
            cursor: pointer;
            border: none;
        }
        .cmp-btn-view {
            background: var(--primary-dark);
            color: #ffffff;
        }
        .cmp-btn-view:hover {
            box-shadow: 0 10px 24px rgba(15, 36, 56, 0.28);
            transform: translateY(-2px);
        }
        .cmp-btn-whatsapp {
            background: #25D366;
            color: #ffffff;
        }
        .cmp-btn-whatsapp:hover {
            background: #1fb457;
            box-shadow: 0 10px 24px rgba(37, 211, 102, 0.34);
            transform: translateY(-2px);
        }

        /* ============================================================
         * SECTION 2: CATEGORY GROUPED SECTIONS
         * ============================================================ */
        .category-group-block {
            margin-bottom: 55px;
            scroll-margin-top: 90px;
        }
        .category-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 14px;
            border-bottom: 2px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 12px;
        }
        .cat-title-group {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .cat-icon-circle {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            border: 1px solid transparent;
        }
        .cat-title-group h2 {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-dark);
            margin: 0;
            letter-spacing: -0.3px;
        }
        .cat-badge-count {
            background: #f1f5f9;
            color: #475569;
            font-size: 12.5px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
        }
        .btn-top-jump {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-top-jump:hover {
            color: var(--primary-dark);
        }

        .cmp-companies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 28px;
        }
        .cmp-company-card {
            background: #ffffff;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid var(--border-line);
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .cmp-company-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.12);
            border-color: rgba(194, 245, 66, 0.6);
        }

        /* Empty State */
        .cmp-empty {
            background: #ffffff;
            border: 1px dashed #cbd5e1;
            border-radius: 18px;
            padding: 60px 24px;
            text-align: center;
            color: var(--text-muted);
            max-width: 600px;
            margin: 40px auto;
        }
        .cmp-empty i { font-size: 38px; color: #cbd5e1; display: block; margin-bottom: 14px; }

        /* Footer */
        .cmp-footer {
            background: #06111a;
            color: #94a3b8;
            padding: 60px 5% 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 13.5px;
        }
        .footer-grid-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }
        .footer-col-title {
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .footer-links-list { list-style: none; }
        .footer-links-list li { margin-bottom: 10px; }
        .footer-links-list a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 13.5px;
            transition: color 0.2s;
        }
        .footer-links-list a:hover { color: var(--accent-lime); }
        .footer-bottom {
            max-width: 1280px;
            margin: 0 auto;
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            flex-wrap: wrap;
            gap: 14px;
        }

        @media (max-width: 960px) {
            .top3-grid { grid-template-columns: 1fr; max-width: 440px; margin: 0 auto; }
            .podium-card.rank-1 { transform: none; }
            .podium-card.rank-1:hover { transform: translateY(-8px); }
            .footer-grid-container { grid-template-columns: 1fr 1fr; }
            .nav-pill { display: none; }
        }
        @media (max-width: 640px) {
            .directory-hero h1 { font-size: 32px; }
            .leaderboard-title { font-size: 22px; }
            .cmp-card-actions .cmp-btn { flex: 1 1 100%; }
            .footer-grid-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Header & Navigation (matches index.php, pricing.php, features.php) -->
    <div class="top-bar-wrap" id="top">
        <header class="navbar">
            <a href="index.php" class="logo">
                <span class="logo-icon"><i class="fa-solid fa-shapes"></i></span>
                Optibiz
            </a>
            <nav class="nav-pill">
                <a href="index.php">Home</a>
                <a href="features.php">Features</a>
                <a href="pricing.php">Pricing</a>
                <a href="companies.php" class="active">Directory</a>
                <a href="index.php#how-it-works">How It Works</a>
            </nav>
            <div class="nav-actions">
                <a href="admin/login.php" class="btn-signin">Sign In</a>
                <a href="index.php#get-started" class="btn-quote">
                    Get Started <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </header>
    </div>

    <!-- Directory Hero Banner with Search & Filter Bar -->
    <section class="directory-hero">
        <div class="hero-inner">
            <div class="badge-tag">
                <i class="fa-solid fa-building-circle-check"></i> Verified Business Directory
            </div>
            <h1>Discover Businesses by <span>Category &amp; Daily Rank</span></h1>
            <p>
                Browse verified businesses, review real customer ratings, check daily leaderboard champions, and contact companies directly on WhatsApp.
            </p>

            <!-- Search Input -->
            <div class="search-filter-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="companySearch" placeholder="Search by business name, category, or city..." autocomplete="off">
                <button type="button" class="btn-clear-search" id="btnClearSearch">Clear</button>
            </div>

            <!-- Category Quick-Jump Chips -->
            <div class="category-chips-wrap">
                <button type="button" class="cat-chip active" onclick="filterCategory('all', this)">
                    All Categories <span class="chip-count"><?php echo $total_companies_count; ?></span>
                </button>
                <?php foreach ($categories_grouped as $cat_title => $comps_in_cat): 
                    $cat_slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($cat_title));
                    $meta = getCategoryMeta($cat_title);
                ?>
                <button type="button" class="cat-chip" onclick="filterCategory('<?php echo htmlspecialchars($cat_slug); ?>', this)">
                    <i class="fa-solid <?php echo $meta['icon']; ?>"></i>
                    <?php echo htmlspecialchars($cat_title); ?>
                    <span class="chip-count"><?php echo count($comps_in_cat); ?></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Main Content Container -->
    <main class="directory-main">

        <!-- ============================================================
             SECTION 1: TODAY'S TOP 3 RANKED & MOST CLICKED COMPANIES
             ============================================================ -->
        <?php if (!empty($top3)): ?>
        <section class="leaderboard-section" id="daily-leaderboard">
            <div class="leaderboard-header">
                <div class="leaderboard-title-wrap">
                    <div class="tag-leaderboard">
                        <i class="fa-solid fa-crown"></i> Daily Leaderboard &middot; <?php echo date('F j, Y'); ?>
                    </div>
                    <h2 class="leaderboard-title">Today's Top 3 Most Clicked &amp; Top-Ranked Businesses</h2>
                    <p class="leaderboard-sub">
                        Ranked dynamically every day by verified customer reviews, daily page visits, and WhatsApp interactions.
                    </p>
                </div>
                <div class="leaderboard-live-badge">
                    <span class="pulse-dot"></span> Live Activity Ranking
                </div>
            </div>

            <div class="top3-grid">
                <?php 
                $rank_index = 1;
                foreach ($top3 as $c): 
                    $c_name      = (string)($c['company_name'] ?? '');
                    $c_logo      = (string)($c['tenant_logo'] ?? '');
                    $c_banner    = (string)($c['tenant_banner'] ?? '');
                    $c_initials  = getInitials($c_name);
                    $c_whatsapp  = whatsappChatUrl($c['whatsapp_number'] ?? '', $c_name);
                    $c_reviews   = (int)($c['rating_count'] ?? 0);
                    $c_avg       = (float)($c['avg_rating'] ?? 0);
                    $c_clicks_24 = (int)($c['clicks_24h'] ?? 0);
                    $c_clicks_tot= (int)($c['total_clicks'] ?? 0);

                    // Ribbon styling per rank
                    if ($rank_index === 1) {
                        $ribbon_text = '🥇 #1 Daily Leader';
                        $rank_class  = 'rank-1';
                    } elseif ($rank_index === 2) {
                        $ribbon_text = '🥈 #2 Top Performer';
                        $rank_class  = 'rank-2';
                    } else {
                        $ribbon_text = '🥉 #3 Rising Star';
                        $rank_class  = 'rank-3';
                    }
                ?>
                <article class="podium-card <?php echo $rank_class; ?> company-search-target"
                         data-company-name="<?php echo htmlspecialchars(strtolower($c_name)); ?>"
                         data-category="<?php echo htmlspecialchars(strtolower($c['category_name'] ?? '')); ?>"
                         data-location="<?php echo htmlspecialchars(strtolower($c['location_description'] ?? $c['address'] ?? '')); ?>">

                    <div class="podium-rank-ribbon">
                        <?php echo $ribbon_text; ?>
                    </div>

                    <div class="cmp-card-banner">
                        <?php if ($c_banner !== ''): ?>
                            <img src="<?php echo htmlspecialchars($c_banner); ?>" alt="<?php echo htmlspecialchars($c_name); ?> banner">
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
                        <h3 class="cmp-company-name">
                            <i class="fa-solid fa-circle-check" aria-hidden="true" title="Verified Business"></i>
                            <?php echo htmlspecialchars($c_name); ?>
                        </h3>
                        <?php if (!empty($c['tenant_name'])): ?>
                            <div class="cmp-tenant-name">
                                <i class="fa-solid fa-briefcase" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($c['tenant_name']); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Daily Engagement Momentum -->
                        <div class="cmp-momentum-bar">
                            <span class="momentum-item">
                                <i class="fa-solid fa-arrow-trend-up"></i>
                                <?php echo $c_clicks_24 > 0 ? "{$c_clicks_24} visits today" : ($c_clicks_tot > 0 ? "{$c_clicks_tot} total visits" : "Active today"); ?>
                            </span>
                            <span style="font-weight:700;color:var(--primary-dark)">
                                <i class="fa-solid fa-star" style="color:var(--star)"></i> <?php echo number_format($c_avg, 1); ?>
                            </span>
                        </div>

                        <div class="cmp-company-info">
                            <?php if (!empty($c['phone'])): ?>
                                <div class="cmp-info-item">
                                    <i class="fa-solid fa-phone" aria-hidden="true"></i>
                                    <a class="cmp-info-link" href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $c['phone'])); ?>">
                                        <?php echo htmlspecialchars($c['phone']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($c['address']) || !empty($c['location_description'])): ?>
                                <div class="cmp-info-item">
                                    <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                    <span class="cmp-info-link">
                                        <?php echo htmlspecialchars($c['location_description'] ?: $c['address']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($c_reviews > 0): ?>
                            <div class="cmp-card-rating">
                                <span class="cmp-stars" aria-hidden="true">★</span>
                                <span><?php echo number_format($c_avg, 1); ?> score</span>
                                <span class="cmp-rating-count"><?php echo number_format($c_reviews); ?> verified review<?php echo $c_reviews === 1 ? '' : 's'; ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="cmp-card-actions">
                            <a class="cmp-btn cmp-btn-view" href="rate/index.php?company=<?php echo (int)$c['id']; ?>"
                               onclick="trackCompanyClick(<?php echo (int)$c['id']; ?>, 'company_click')">
                                <i class="fa-solid fa-star" aria-hidden="true"></i> View &amp; Rate
                            </a>
                            <?php if ($c_whatsapp !== ''): ?>
                            <a class="cmp-btn cmp-btn-whatsapp" href="<?php echo htmlspecialchars($c_whatsapp); ?>"
                               target="_blank" rel="noopener noreferrer"
                               onclick="trackCompanyClick(<?php echo (int)$c['id']; ?>, 'whatsapp_click')">
                                <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Chat on WhatsApp
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php 
                    $rank_index++;
                endforeach; 
                ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ============================================================
             SECTION 2: COMPANIES GROUPED BY CATEGORIES
             ============================================================ -->
        <div id="categories-container">
            <?php if (!empty($categories_grouped)): ?>
                <?php foreach ($categories_grouped as $cat_title => $comps_in_cat): 
                    $cat_slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($cat_title));
                    $meta = getCategoryMeta($cat_title);
                ?>
                <section class="category-group-block" id="cat-<?php echo htmlspecialchars($cat_slug); ?>" data-category-slug="<?php echo htmlspecialchars($cat_slug); ?>">
                    <div class="category-header-bar">
                        <div class="cat-title-group">
                            <div class="cat-icon-circle" style="background:<?php echo $meta['bg']; ?>;color:<?php echo $meta['color']; ?>;border-color:<?php echo $meta['border']; ?>">
                                <i class="fa-solid <?php echo $meta['icon']; ?>"></i>
                            </div>
                            <div>
                                <h2><?php echo htmlspecialchars($cat_title); ?></h2>
                            </div>
                            <span class="cat-badge-count">
                                <?php echo count($comps_in_cat); ?> <?php echo count($comps_in_cat) === 1 ? 'Business' : 'Businesses'; ?>
                            </span>
                        </div>

                        <a href="#top" class="btn-top-jump">
                            Back to Top <i class="fa-solid fa-arrow-up"></i>
                        </a>
                    </div>

                    <div class="cmp-companies-grid">
                        <?php foreach ($comps_in_cat as $c): 
                            $c_name      = (string)($c['company_name'] ?? '');
                            $c_logo      = (string)($c['tenant_logo'] ?? '');
                            $c_banner    = (string)($c['tenant_banner'] ?? '');
                            $c_initials  = getInitials($c_name);
                            $c_whatsapp  = whatsappChatUrl($c['whatsapp_number'] ?? '', $c_name);
                            $c_reviews   = (int)($c['rating_count'] ?? 0);
                            $c_avg       = (float)($c['avg_rating'] ?? 0);
                            $c_clicks_tot= (int)($c['total_clicks'] ?? 0);
                        ?>
                        <article class="cmp-company-card company-search-target"
                                 data-company-name="<?php echo htmlspecialchars(strtolower($c_name)); ?>"
                                 data-category="<?php echo htmlspecialchars(strtolower($cat_title)); ?>"
                                 data-category-slug="<?php echo htmlspecialchars($cat_slug); ?>"
                                 data-location="<?php echo htmlspecialchars(strtolower($c['location_description'] ?? $c['address'] ?? '')); ?>">

                            <div class="cmp-card-banner">
                                <?php if ($c_banner !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($c_banner); ?>" alt="<?php echo htmlspecialchars($c_name); ?> banner">
                                <?php else: ?>
                                    <div class="cmp-banner-fallback"><i class="fa-solid fa-building"></i></div>
                                <?php endif; ?>
                                <span class="cmp-card-category">
                                    <i class="fa-solid <?php echo $meta['icon']; ?>"></i> <?php echo htmlspecialchars($cat_title); ?>
                                </span>
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
                                <h3 class="cmp-company-name">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true" title="Verified Business"></i>
                                    <?php echo htmlspecialchars($c_name); ?>
                                </h3>
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
                                            <a class="cmp-info-link" href="mailto:<?php echo htmlspecialchars($c['email']); ?>">
                                                <?php echo htmlspecialchars($c['email']); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($c['phone'])): ?>
                                        <div class="cmp-info-item">
                                            <i class="fa-solid fa-phone" aria-hidden="true"></i>
                                            <a class="cmp-info-link" href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $c['phone'])); ?>">
                                                <?php echo htmlspecialchars($c['phone']); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($c['website'])): ?>
                                        <div class="cmp-info-item">
                                            <i class="fa-solid fa-globe" aria-hidden="true"></i>
                                            <a class="cmp-info-link" href="<?php echo htmlspecialchars(preg_match('#^https?://#i', $c['website']) ? $c['website'] : 'https://' . $c['website']); ?>"
                                               target="_blank" rel="noopener noreferrer">
                                                <?php echo htmlspecialchars($c['website']); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($c['location_description']) || !empty($c['address'])): ?>
                                        <div class="cmp-info-item">
                                            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                            <span class="cmp-info-link">
                                                <?php echo htmlspecialchars($c['location_description'] ?: $c['address']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($c_reviews > 0): ?>
                                    <div class="cmp-card-rating">
                                        <span class="cmp-stars" aria-hidden="true">★</span>
                                        <span><?php echo number_format($c_avg, 1); ?> score</span>
                                        <span class="cmp-rating-count"><?php echo number_format($c_reviews); ?> review<?php echo $c_reviews === 1 ? '' : 's'; ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="cmp-card-actions">
                                    <a class="cmp-btn cmp-btn-view" href="rate/index.php?company=<?php echo (int)$c['id']; ?>"
                                       onclick="trackCompanyClick(<?php echo (int)$c['id']; ?>, 'company_click')">
                                        <i class="fa-solid fa-star" aria-hidden="true"></i> View &amp; Rate
                                    </a>
                                    <?php if ($c_whatsapp !== ''): ?>
                                    <a class="cmp-btn cmp-btn-whatsapp" href="<?php echo htmlspecialchars($c_whatsapp); ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       onclick="trackCompanyClick(<?php echo (int)$c['id']; ?>, 'whatsapp_click')">
                                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Chat on WhatsApp
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="cmp-empty">
                    <i class="fa-solid fa-store" aria-hidden="true"></i>
                    <strong style="font-size:18px;color:var(--primary-dark)">No businesses listed yet</strong>
                    <p style="margin-top:8px">Companies will appear here as soon as they set up their workspace profile.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- No Results Search State -->
        <div id="noSearchResults" class="cmp-empty" style="display:none">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <strong style="font-size:18px;color:var(--primary-dark)">No matching businesses found</strong>
            <p style="margin-top:8px">Try searching for a different company name, city, or category.</p>
            <button type="button" class="btn-quote" style="margin-top:16px;cursor:pointer" onclick="resetSearch()">
                Show All Businesses
            </button>
        </div>

    </main>

    <!-- Footer (matches index.php, pricing.php, features.php) -->
    <footer class="cmp-footer">
        <div class="footer-grid-container">
            <div>
                <a href="index.php" class="logo" style="margin-bottom:16px">
                    <span class="logo-icon"><i class="fa-solid fa-shapes"></i></span>
                    Optibiz
                </a>
                <p style="font-size:14px;color:#94a3b8;line-height:1.6;margin-bottom:20px;max-width:320px">
                    The complete review management, Google boost, and social proof platform engineered for modern retail, dining, and service businesses.
                </p>
                <div style="display:flex;gap:14px;font-size:18px">
                    <a href="https://facebook.com" style="color:#94a3b8"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="https://twitter.com" style="color:#94a3b8"><i class="fa-brands fa-x-twitter"></i></a>
                    <a href="https://linkedin.com" style="color:#94a3b8"><i class="fa-brands fa-linkedin-in"></i></a>
                    <a href="https://instagram.com" style="color:#94a3b8"><i class="fa-brands fa-instagram"></i></a>
                </div>
            </div>

            <div>
                <h4 class="footer-col-title">Platform</h4>
                <ul class="footer-links-list">
                    <li><a href="features.php">Features Overview</a></li>
                    <li><a href="pricing.php">Pricing &amp; Plans</a></li>
                    <li><a href="companies.php">Company Directory</a></li>
                    <li><a href="index.php#how-it-works">How It Works</a></li>
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
                    <li><a href="admin/login.php">Business Admin Login</a></li>
                    <li><a href="superadmin/login.php">Control Center</a></li>
                    <li><a href="index.php#get-started">Start Free Trial</a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <div>
                &copy; <?php echo date('Y'); ?> <strong>Optibiz</strong>. All rights reserved &middot; Verified Reviews &amp; WhatsApp Lead Routing.
            </div>
            <div style="display:flex;gap:20px">
                <a href="pricing.php" style="color:#94a3b8;text-decoration:none">Pricing</a>
                <a href="features.php" style="color:#94a3b8;text-decoration:none">Features</a>
                <a href="admin/login.php" style="color:#94a3b8;text-decoration:none">Sign In</a>
            </div>
        </div>
    </footer>

    <!-- Interactive Search, Filter & Event Beacon Scripts -->
    <script>
    (function () {
        'use strict';

        // 1. Asynchronous Client-Side Event Beacon (tracks clicks for daily rank)
        window.trackCompanyClick = function (companyId, eventType) {
            try {
                var payload = new FormData();
                payload.append('company_id', companyId);
                payload.append('event_type', eventType || 'company_click');
                if (navigator.sendBeacon) {
                    navigator.sendBeacon('api/submit_event.php', payload);
                } else {
                    fetch('api/submit_event.php', { method: 'POST', body: payload, keepalive: true });
                }
            } catch (e) {
                // Ignore analytics transmission failures
            }
        };

        // 2. Client-Side Real-Time Filter
        var searchInput    = document.getElementById('companySearch');
        var btnClearSearch = document.getElementById('btnClearSearch');
        var noResultsBox   = document.getElementById('noSearchResults');
        var categoryBlocks = document.querySelectorAll('.category-group-block');
        var allCards       = document.querySelectorAll('.company-search-target');
        var catChips       = document.querySelectorAll('.cat-chip');

        var activeCategorySlug = 'all';

        function applyFilter() {
            var query = (searchInput.value || '').trim().toLowerCase();
            var totalVisibleCards = 0;

            if (query !== '') {
                btnClearSearch.style.display = 'block';
            } else {
                btnClearSearch.style.display = 'none';
            }

            // Filter cards
            allCards.forEach(function (card) {
                var cName = card.getAttribute('data-company-name') || '';
                var cCat  = card.getAttribute('data-category') || '';
                var cLoc  = card.getAttribute('data-location') || '';
                var cardCatSlug = card.getAttribute('data-category-slug') || '';

                var matchesQuery = (query === '') || 
                                   (cName.indexOf(query) !== -1) || 
                                   (cCat.indexOf(query) !== -1) || 
                                   (cLoc.indexOf(query) !== -1);

                var matchesCategory = (activeCategorySlug === 'all') || 
                                      (cardCatSlug === activeCategorySlug);

                if (matchesQuery && matchesCategory) {
                    card.style.display = '';
                    totalVisibleCards++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Show/hide category blocks if all children are hidden
            categoryBlocks.forEach(function (block) {
                var blockSlug = block.getAttribute('data-category-slug');
                var visibleInBlock = block.querySelectorAll('.cmp-company-card:not([style*="display: none"])').length;

                if ((activeCategorySlug === 'all' || activeCategorySlug === blockSlug) && visibleInBlock > 0) {
                    block.style.display = '';
                } else {
                    block.style.display = 'none';
                }
            });

            // Show or hide empty search results state
            if (totalVisibleCards === 0) {
                noResultsBox.style.display = 'block';
            } else {
                noResultsBox.style.display = 'none';
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilter);
        }

        if (btnClearSearch) {
            btnClearSearch.addEventListener('click', function () {
                searchInput.value = '';
                applyFilter();
                searchInput.focus();
            });
        }

        window.resetSearch = function () {
            if (searchInput) searchInput.value = '';
            activeCategorySlug = 'all';
            catChips.forEach(function (c) { c.classList.remove('active'); });
            if (catChips[0]) catChips[0].classList.add('active');
            applyFilter();
        };

        window.filterCategory = function (slug, btnElement) {
            activeCategorySlug = slug;
            catChips.forEach(function (c) { c.classList.remove('active'); });
            if (btnElement) {
                btnElement.classList.add('active');
            }

            applyFilter();

            // Smooth scroll to the category block if not "all"
            if (slug !== 'all') {
                var targetBlock = document.getElementById('cat-' + slug);
                if (targetBlock) {
                    targetBlock.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        };

    })();
    </script>
</body>
</html>
