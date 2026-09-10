<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Fetch currency settings
$currency_symbol = '$';
$currency_position = 'before';
$settings = [];
$r = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'currency%'");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'] ?? '';
    }
}
if (!empty($settings['currency_symbol'])) $currency_symbol = $settings['currency_symbol'];
if (!empty($settings['currency_position'])) $currency_position = $settings['currency_position'];

if (!function_exists('fmt_price')) {
    function fmt_price($amount, $symbol, $position) {
        $f = number_format((float)$amount, 2);
        return $position === 'after' ? $f . ' ' . $symbol : $symbol . $f;
    }
}

// Fetch active subscription plans for trial modal
$modal_plans = [];
$res_plans = $conn->query("SELECT id, plan_name, price FROM subscription_plans WHERE status = 'active' ORDER BY price ASC");
if ($res_plans) {
    while ($row = $res_plans->fetch_assoc()) {
        $modal_plans[] = $row;
    }
}
if (empty($modal_plans)) {
    $modal_plans = [
        ['id' => 1, 'plan_name' => 'Starter', 'price' => 29.99],
        ['id' => 2, 'plan_name' => 'Professional', 'price' => 79.99],
        ['id' => 3, 'plan_name' => 'Enterprise', 'price' => 199.99]
    ];
}

// Fetch categories for trial modal
$modal_categories = [];
$cat_res = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($cat_res) {
    while ($c = $cat_res->fetch_assoc()) {
        $modal_categories[] = $c;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Features Tour — Optibiz Review, Reputation &amp; Growth Suite</title>
    <meta name="description" content="Explore the 6 core engines of Optibiz: In-store Counter QR Stands, WhatsApp review requests, Google Review boosting, social proof card studio, and ad retargeting funnels.">
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
            border: none;
            cursor: pointer;
        }
        .btn-quote:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(194, 245, 66, 0.3);
        }

        /* Hero Section */
        .features-hero {
            background: radial-gradient(circle at 80% 20%, #1a3c5a 0%, var(--primary-dark) 70%);
            padding: 80px 5% 90px;
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
            margin-bottom: 20px;
        }
        .features-hero h1 {
            font-size: 46px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.5px;
            margin-bottom: 20px;
        }
        .features-hero h1 span {
            color: var(--accent-lime);
        }
        .features-hero p {
            font-size: 17px;
            color: #94a3b8;
            max-width: 680px;
            margin: 0 auto 36px;
            line-height: 1.6;
        }
        .hero-cta-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 40px;
        }
        .btn-lime {
            background: var(--accent-lime);
            color: var(--primary-dark);
            padding: 14px 30px;
            border-radius: 40px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.25s;
        }
        .btn-lime:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(194, 245, 66, 0.3);
        }
        .btn-ghost-white {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 14px 28px;
            border-radius: 40px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s;
        }
        .btn-ghost-white:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        /* Quick Engine Nav Pills */
        .engine-nav-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            max-width: 1050px;
            margin: 0 auto;
        }
        .engine-pill {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: #cbd5e1;
            padding: 8px 16px;
            border-radius: 24px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s;
        }
        .engine-pill:hover {
            background: var(--accent-lime);
            color: var(--primary-dark);
            border-color: var(--accent-lime);
            transform: translateY(-2px);
        }

        /* Section Containers */
        .feature-block {
            padding: 100px 5%;
            position: relative;
        }
        .feature-block.alt-bg {
            background: #f8fafc;
        }
        .feature-row {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
        }
        .feature-row.reverse {
            direction: rtl;
        }
        .feature-row.reverse > * {
            direction: ltr;
        }
        .feature-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #4d7c0f;
            background: var(--accent-green-bg);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 5px 14px;
            border-radius: 20px;
            margin-bottom: 16px;
        }
        .feature-title {
            font-size: 36px;
            font-weight: 800;
            color: var(--primary-dark);
            line-height: 1.2;
            margin-bottom: 18px;
            letter-spacing: -0.5px;
        }
        .feature-lead {
            font-size: 16.5px;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .feature-points {
            list-style: none;
            margin-bottom: 30px;
        }
        .feature-points li {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            font-size: 14.5px;
            color: #334155;
            margin-bottom: 16px;
            line-height: 1.45;
        }
        .feature-points li i {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #ecfccb;
            color: #16a34a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* Mockup / Visual Cards */
        .feature-visual {
            background: #ffffff;
            border-radius: 24px;
            border: 1px solid var(--border-line);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            padding: 36px;
            position: relative;
            overflow: hidden;
        }
        .visual-dark {
            background: linear-gradient(135deg, #0f2438 0%, #163652 100%);
            color: #ffffff;
            border: none;
        }

        /* Engine 1: QR Stand Mockup */
        .qr-mockup {
            text-align: center;
            padding: 20px;
        }
        .qr-stand-frame {
            display: inline-block;
            background: #ffffff;
            border-radius: 18px;
            border: 3px solid #0f2438;
            padding: 24px 30px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.12);
            max-width: 280px;
        }
        .qr-stand-badge {
            background: var(--accent-lime);
            color: var(--primary-dark);
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 12px;
            display: inline-block;
            margin-bottom: 12px;
        }
        .qr-stand-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--primary-dark);
            margin-bottom: 6px;
        }
        .qr-stand-sub {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-bottom: 14px;
        }
        .qr-code-box {
            width: 140px;
            height: 140px;
            margin: 0 auto 14px;
            border: 2px dashed #94a3b8;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            color: var(--primary-dark);
            font-size: 60px;
        }
        .qr-stars-preview {
            color: var(--star-gold);
            font-size: 14px;
            display: flex;
            justify-content: center;
            gap: 4px;
        }

        /* Engine 2: WhatsApp Chat Mockup */
        .wa-chat-window {
            background: #ece5dd;
            border-radius: 16px;
            padding: 20px;
            max-width: 360px;
            margin: 0 auto;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);
            font-family: inherit;
        }
        .wa-bubble-received {
            background: #ffffff;
            border-radius: 8px 8px 8px 0;
            padding: 14px;
            font-size: 13.5px;
            color: #111b21;
            margin-bottom: 12px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            position: relative;
        }
        .wa-bubble-time {
            font-size: 10px;
            color: #667781;
            text-align: right;
            margin-top: 6px;
        }
        .wa-action-btn {
            background: #25d366;
            color: #ffffff;
            text-align: center;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            display: block;
            box-shadow: 0 2px 4px rgba(37, 211, 102, 0.3);
            margin-top: 10px;
        }
        .wa-action-btn:hover {
            background: #20ba59;
        }

        /* Engine 3: Smart Routing Mockup */
        .routing-split-preview {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .route-branch {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border-radius: 14px;
        }
        .route-high {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
        }
        .route-low {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .route-stars {
            font-size: 18px;
            font-weight: 800;
            min-width: 90px;
        }
        .route-high .route-stars { color: #15803d; }
        .route-low .route-stars { color: #b91c1c; }
        .route-desc {
            font-size: 13px;
            flex: 1;
        }
        .route-desc strong {
            display: block;
            margin-bottom: 2px;
            font-size: 14px;
        }
        .route-high .route-desc strong { color: #166534; }
        .route-low .route-desc strong { color: #991b1b; }
        .route-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .route-high .route-badge {
            background: #22c55e;
            color: #ffffff;
        }
        .route-low .route-badge {
            background: #ef4444;
            color: #ffffff;
        }

        /* Engine 4: Social Proof Graphic Card Mockup */
        .social-card-mockup {
            background: #ffffff;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            max-width: 360px;
            margin: 0 auto;
            border: 1px solid var(--border-line);
        }
        .scm-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .scm-author {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .scm-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--primary-dark);
            color: var(--accent-lime);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 15px;
        }
        .scm-name {
            font-weight: 700;
            font-size: 14px;
            color: var(--primary-dark);
        }
        .scm-status {
            font-size: 11px;
            color: #16a34a;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .scm-quote {
            font-size: 14.5px;
            line-height: 1.5;
            color: #334155;
            font-style: italic;
            margin-bottom: 16px;
        }
        .scm-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 14px;
            border-top: 1px solid var(--border-line);
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Engine 5: Community Q&A Mockup */
        .qa-thread-mockup {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .qa-item-q {
            background: #f1f5f9;
            padding: 16px;
            border-radius: 14px;
            font-size: 13.5px;
            color: var(--primary-dark);
        }
        .qa-item-a {
            background: #ecfccb;
            padding: 16px;
            border-radius: 14px;
            font-size: 13.5px;
            color: #14532d;
            border-left: 4px solid #84cc16;
            margin-left: 20px;
        }
        .qa-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* Engine 6: Ad Pixel Mockup */
        .pixel-flow-mockup {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .pixel-step {
            display: flex;
            align-items: center;
            gap: 14px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 14px 18px;
            border-radius: 12px;
        }
        .pixel-step-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: var(--accent-lime);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .pixel-step-text {
            font-size: 13px;
        }
        .pixel-step-text strong {
            display: block;
            color: #ffffff;
            font-size: 14px;
        }
        .pixel-step-text span {
            color: #cbd5e1;
        }

        /* Industry Solutions Grid */
        .industry-section {
            padding: 90px 5%;
            background: #ffffff;
        }
        .section-header-center {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 50px;
        }
        .section-tag {
            display: inline-block;
            color: #4d7c0f;
            background: var(--accent-green-bg);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 5px 14px;
            border-radius: 20px;
            margin-bottom: 12px;
        }
        .section-title {
            font-size: 34px;
            font-weight: 800;
            color: var(--primary-dark);
            margin-bottom: 14px;
            line-height: 1.25;
        }
        .section-sub {
            font-size: 16px;
            color: var(--text-muted);
        }
        .industry-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
        }
        .industry-card {
            background: #f8fafc;
            border: 1px solid var(--border-line);
            border-radius: 18px;
            padding: 30px;
            transition: all 0.3s;
        }
        .industry-card:hover {
            transform: translateY(-6px);
            border-color: var(--accent-lime);
            box-shadow: 0 12px 30px rgba(0,0,0,0.06);
        }
        .industry-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: var(--primary-dark);
            color: var(--accent-lime);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 20px;
        }
        .industry-card h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 10px;
        }
        .industry-card p {
            font-size: 14px;
            color: #475569;
            line-height: 1.55;
            margin-bottom: 16px;
        }
        .industry-benefit {
            font-size: 12.5px;
            color: #15803d;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Optibiz vs Traditional Comparison */
        .vs-section {
            padding: 90px 5%;
            background: #f8fafc;
        }
        .vs-table-wrap {
            max-width: 1000px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid var(--border-line);
            box-shadow: 0 10px 30px rgba(0,0,0,0.04);
            overflow-x: auto;
        }
        .vs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 600px;
        }
        .vs-table th, .vs-table td {
            padding: 18px 24px;
            text-align: left;
            border-bottom: 1px solid var(--border-line);
        }
        .vs-table th {
            background: #f1f5f9;
            color: var(--primary-dark);
            font-weight: 800;
            font-size: 15px;
        }
        .vs-table th.col-optibiz {
            background: #ecfccb;
            color: var(--primary-dark);
            border-bottom: 2px solid #84cc16;
            width: 40%;
        }
        .vs-table td.col-optibiz {
            background: rgba(236, 252, 203, 0.25);
            font-weight: 600;
            color: #14532d;
        }
        .vs-table td.col-old {
            color: #64748b;
        }

        /* Bottom CTA */
        .cta-banner {
            background: radial-gradient(circle at 50% 50%, #163652 0%, var(--primary-dark) 80%);
            padding: 80px 5%;
            text-align: center;
            color: #ffffff;
        }
        .cta-banner h2 {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 16px;
        }
        .cta-banner p {
            font-size: 17px;
            color: #cbd5e1;
            max-width: 600px;
            margin: 0 auto 36px;
        }
        .cta-banner-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        /* Footer */
        .footer-main {
            background: #06111a;
            color: #94a3b8;
            padding: 70px 5% 40px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .footer-grid-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 40px;
            margin-bottom: 50px;
        }
        .footer-col-title {
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 18px;
        }
        .footer-links-list {
            list-style: none;
        }
        .footer-links-list li {
            margin-bottom: 10px;
        }
        .footer-links-list a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 13.5px;
            transition: color 0.2s;
        }
        .footer-links-list a:hover {
            color: var(--accent-lime);
        }
        .footer-bottom {
            max-width: 1280px;
            margin: 0 auto;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            flex-wrap: wrap;
            gap: 16px;
        }

        /* Modal Styles */
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
        .modal-overlay.active { display: flex; }
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
        .form-alert {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .form-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-top: 20px;
        }
        .btn-back {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
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

        @media (max-width: 960px) {
            .feature-row { grid-template-columns: 1fr; gap: 40px; }
            .feature-row.reverse { direction: ltr; }
            .industry-grid { grid-template-columns: 1fr 1fr; }
            .nav-pill { display: none; }
            .footer-grid-container { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            .features-hero h1 { font-size: 32px; }
            .industry-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .footer-grid-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Header & Navigation -->
    <div class="top-bar-wrap" id="top">
        <header class="navbar">
            <a href="index.php" class="logo">
                <span class="logo-icon"><i class="fa-solid fa-shapes"></i></span>
                Optibiz
            </a>
            <nav class="nav-pill">
                <a href="index.php">Home</a>
                <a href="features.php" class="active">Features</a>
                <a href="pricing.php">Pricing</a>
                <a href="companies.php">Directory</a>
                <a href="index.php#how-it-works">How It Works</a>
            </nav>
            <div class="nav-actions">
                <a href="admin/login.php" class="btn-signin">Sign In</a>
                <button type="button" class="btn-quote" onclick="openModal()">
                    Get Started <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>
        </header>
    </div>

    <!-- Features Hero Section -->
    <section class="features-hero">
        <div class="hero-inner">
            <div class="badge-tag">
                <i class="fa-solid fa-wand-magic-sparkles"></i> The Optibiz Growth Engine
            </div>
            <h1>The Complete Platform to <span>Dominate Local Reviews</span></h1>
            <p>
                From contactless counter stands to automated WhatsApp outreach and smart Google review boosters, discover the 6 purpose-built engines that make Optibiz the #1 choice for reputation-minded businesses.
            </p>

            <div class="hero-cta-row">
                <button type="button" class="btn-lime" onclick="openModal()">
                    Start 14-Day Free Trial <i class="fa-solid fa-arrow-right"></i>
                </button>
                <a href="pricing.php" class="btn-ghost-white">
                    <i class="fa-solid fa-tags"></i> View Plans &amp; Pricing
                </a>
            </div>

            <!-- Quick Engine Jump Links -->
            <div class="engine-nav-wrap">
                <a href="#qr-stands" class="engine-pill"><i class="fa-solid fa-qrcode"></i> Counter QR Stands</a>
                <a href="#whatsapp" class="engine-pill"><i class="fa-brands fa-whatsapp"></i> WhatsApp Requester</a>
                <a href="#google-booster" class="engine-pill"><i class="fa-brands fa-google"></i> Google Booster &amp; Shield</a>
                <a href="#social-proof" class="engine-pill"><i class="fa-solid fa-wand-magic-sparkles"></i> Social Proof Studio</a>
                <a href="#community-qa" class="engine-pill"><i class="fa-solid fa-comments"></i> Community Q&amp;A</a>
                <a href="#ad-funnel" class="engine-pill"><i class="fa-solid fa-bullhorn"></i> Ad Retargeting Funnels</a>
            </div>
        </div>
    </section>

    <!-- Engine 1: Counter QR Stands -->
    <section class="feature-block" id="qr-stands">
        <div class="feature-row">
            <div>
                <div class="feature-tag"><i class="fa-solid fa-qrcode"></i> Engine 01 • Physical In-Store Capture</div>
                <h2 class="feature-title">Printable Counter QR Stands &amp; In-Store Kiosks</h2>
                <p class="feature-lead">
                    Capture feedback at the exact point of sale when customer satisfaction is highest. Our printable QR stands eliminate friction—no apps, no account creation, no delays.
                </p>
                <ul class="feature-points">
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Zero Friction Scan-to-Rate:</strong> Customers simply open their smartphone camera, scan the counter tent or receipt, and tap their star rating in 5 seconds.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Print-Ready PDF &amp; PNG Generator:</strong> Download high-resolution print templates customized with your logo, brand colors, and call-to-action in standard table tent sizes.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Dynamic QR Redirect:</strong> Change your review destination, campaign promos, or survey questions anytime without reprinting physical acrylic cards.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Tablet Kiosk Mode:</strong> Turn any counter iPad or Android tablet into a dedicated, self-refreshing review station for high-footfall checkout counters.</div>
                    </li>
                </ul>
                <button type="button" class="btn-lime" onclick="openModal()">Generate Free QR Template</button>
            </div>

            <div class="feature-visual">
                <div class="qr-mockup">
                    <div class="qr-stand-frame">
                        <div class="qr-stand-badge">RATE US IN 5 SECONDS</div>
                        <div class="qr-stand-title">How was your visit?</div>
                        <div class="qr-stand-sub">Scan to share your thoughts</div>
                        <div class="qr-code-box">
                            <i class="fa-solid fa-qrcode"></i>
                        </div>
                        <div class="qr-stars-preview">
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Engine 2: WhatsApp Review Requester -->
    <section class="feature-block alt-bg" id="whatsapp">
        <div class="feature-row reverse">
            <div>
                <div class="feature-tag"><i class="fa-brands fa-whatsapp"></i> Engine 02 • Conversational Outreach</div>
                <h2 class="feature-title">Automated WhatsApp Review Requests</h2>
                <p class="feature-lead">
                    Email review requests get lost in spam and yield 2% responses. WhatsApp enjoys an extraordinary 98% open rate and 4x higher review conversions.
                </p>
                <ul class="feature-points">
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Personalized Friendly Messages:</strong> Automatically populate customer first name, service specialist, or purchased item with smart placeholders.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>One-Tap Review Link:</strong> A direct, friction-free link opens the rating interface right from WhatsApp with no login barrier.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Respectful Smart Cadence:</strong> Schedule messages 1 hour, 24 hours, or 3 days after visit, with automatic cancellation once feedback is submitted.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>CRM &amp; POS Integration:</strong> Trigger review invites automatically via webhooks when a transaction completes in your point of sale.</div>
                    </li>
                </ul>
                <button type="button" class="btn-lime" onclick="openModal()">Test WhatsApp Engine</button>
            </div>

            <div class="feature-visual" style="background:#f1f5f9">
                <div class="wa-chat-window">
                    <div class="wa-bubble-received">
                        <strong>The Grand Bistro</strong><br>
                        Hi Sarah! Thanks for dining with us this evening. Chef David and the team would love to know how your dinner was! 🍽️
                        <a href="#" onclick="return false;" class="wa-action-btn">
                            <i class="fa-solid fa-star"></i> Rate Your Experience
                        </a>
                        <div class="wa-bubble-time">8:42 PM • Delivered</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Engine 3: Smart Google Review Booster & Shield -->
    <section class="feature-block" id="google-booster">
        <div class="feature-row">
            <div>
                <div class="feature-tag"><i class="fa-brands fa-google"></i> Engine 03 • Google Boost &amp; Negative Shield</div>
                <h2 class="feature-title">Smart Google Review Routing &amp; Feedback Shield</h2>
                <p class="feature-lead">
                    Stop bad days from wrecking your public Google rating. Optibiz intelligently routes glowing reviews to Google while resolving complaints privately behind closed doors.
                </p>
                <ul class="feature-points">
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Direct Google Booster (4 &amp; 5 Stars):</strong> Delighted customers are directed directly to your verified Google Business Profile review dialog to leave 5-star praise.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Internal Shield (1, 2 &amp; 3 Stars):</strong> Unhappy customers are routed to a private resolution form so they can vent directly to management instead of writing a public 1-star rant.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Instant Manager Alerts:</strong> Receive immediate email &amp; WhatsApp alerts whenever low scores occur, enabling your team to resolve issues within minutes.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Turn Detractors Into Champions:</strong> Over 70% of unhappy customers return when their grievance is acknowledged and remedied swiftly by management.</div>
                    </li>
                </ul>
                <button type="button" class="btn-lime" onclick="openModal()">Try Google Booster</button>
            </div>

            <div class="feature-visual">
                <div class="routing-split-preview">
                    <div class="route-branch route-high">
                        <div class="route-stars">★★★★★</div>
                        <div class="route-desc">
                            <strong>4 &amp; 5 Stars: Public Google Boost</strong>
                            Directs reviewer to Google Maps review dialog to boost local SEO ranking.
                        </div>
                        <div class="route-badge">Boost Google</div>
                    </div>

                    <div class="route-branch route-low">
                        <div class="route-stars">★☆☆☆☆</div>
                        <div class="route-desc">
                            <strong>1 to 3 Stars: Negative Shield</strong>
                            Captures private feedback internally. Alerts manager instantly to resolve issues.
                        </div>
                        <div class="route-badge">Shield Public</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Engine 4: Social Proof Graphic Card Studio -->
    <section class="feature-block alt-bg" id="social-proof">
        <div class="feature-row reverse">
            <div>
                <div class="feature-tag"><i class="fa-solid fa-wand-magic-sparkles"></i> Engine 04 • Automated Marketing Assets</div>
                <h2 class="feature-title">Social Proof Graphic Card Studio</h2>
                <p class="feature-lead">
                    Customer reviews are your most persuasive sales copy. Instantly transform verified 5-star customer testimonials into stunning branded graphics ready for Instagram, Facebook, and LinkedIn.
                </p>
                <ul class="feature-points">
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>1-Click Multi-Format Export:</strong> Generate perfect Square (1:1), Story (9:16), and Landscape (16:9) graphic assets in seconds.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Custom Brand Styling:</strong> Apply your brand colors, custom font treatments, dark/light themes, and company logo automatically.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Verified Customer Badge:</strong> Every generated card features the verified review badge, elevating authenticity and ad click-through rates.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Ad-Ready Creatives:</strong> Plug generated graphics directly into Meta Ads and Google Display to slash customer acquisition costs by up to 35%.</div>
                    </li>
                </ul>
                <button type="button" class="btn-lime" onclick="openModal()">Open Graphic Studio</button>
            </div>

            <div class="feature-visual" style="background:#f1f5f9">
                <div class="social-card-mockup">
                    <div class="scm-header">
                        <div class="scm-author">
                            <div class="scm-avatar">EM</div>
                            <div>
                                <div class="scm-name">Elena Martinez</div>
                                <div class="scm-status"><i class="fa-solid fa-circle-check"></i> Verified Customer</div>
                            </div>
                        </div>
                        <div style="color:var(--star-gold);font-size:14px">★★★★★</div>
                    </div>
                    <p class="scm-quote">
                        "Hands down the best customer service in town. The team solved my request in under 10 minutes. Will never go anywhere else!"
                    </p>
                    <div class="scm-footer">
                        <span>Reviewed on Optibiz</span>
                        <span style="font-weight:700;color:var(--primary-dark)"><i class="fa-solid fa-shapes"></i> Optibiz Verified</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Engine 5: Community Q&A -->
    <section class="feature-block" id="community-qa">
        <div class="feature-row">
            <div>
                <div class="feature-tag"><i class="fa-solid fa-comments"></i> Engine 05 • Pre-Purchase Conversion</div>
                <h2 class="feature-title">Community Q&amp;A &amp; Pre-Purchase Knowledgebase</h2>
                <p class="feature-lead">
                    Shoppers have questions before booking or buying. Our integrated Q&amp;A engine lets prospective customers ask questions directly and receive verified answers from your team.
                </p>
                <ul class="feature-points">
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Eliminate Buying Hesitation:</strong> Answer crucial questions about menu allergens, parking, warranties, and appointments directly on your profile.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>SEO Rich Snippets:</strong> Q&amp;A threads are structured with Schema.org FAQ markup, helping your business capture Google Rich Snippets in search results.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Verified Owner Badging:</strong> Answers from your official management team are distinguished with an official green verification badge.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Searchable Public Repository:</strong> Common questions are permanently available, reducing repeat inquiries to your phone and support inbox.</div>
                    </li>
                </ul>
                <button type="button" class="btn-lime" onclick="openModal()">Explore Q&amp;A Engine</button>
            </div>

            <div class="feature-visual">
                <div class="qa-thread-mockup">
                    <div class="qa-item-q">
                        <strong>Q: Do you accommodate gluten-free and vegan dietary options?</strong>
                        <div class="qa-meta">Asked by Michael T. • 2 days ago</div>
                    </div>
                    <div class="qa-item-a">
                        <strong><i class="fa-solid fa-circle-check"></i> Answer from Business Owner:</strong><br>
                        Yes Michael! We have a dedicated gluten-free prep station and over 8 signature vegan entrees marked clearly on our menu.
                        <div class="qa-meta">Answered by Head Chef Marco • Verified Owner</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Engine 6: Ad Retargeting Funnels & Pixels -->
    <section class="feature-block alt-bg" id="ad-funnel">
        <div class="feature-row reverse">
            <div>
                <div class="feature-tag"><i class="fa-solid fa-bullhorn"></i> Engine 06 • Growth &amp; Paid Media</div>
                <h2 class="feature-title">Ad Retargeting Funnels &amp; Conversion Pixels</h2>
                <p class="feature-lead">
                    Supercharge your paid advertising by syncing real customer review events with Meta (Facebook/Instagram), Google Tag Manager, and TikTok ad accounts.
                </p>
                <ul class="feature-points">
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Retarget 5-Star Advocates:</strong> Create custom advertising audiences comprised exclusively of customers who rated your business 5 stars.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>High-Value Lookalike Audiences:</strong> Feed Meta and Google machine learning models with your happiest verified customers to find high-converting new clients.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>Automated Special Offer Ads:</strong> Automatically serve repeat purchase discounts or referral vouchers to customers right after they submit positive feedback.</div>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <div><strong>No Coding Required:</strong> Simply paste your Meta Pixel ID or Google Tag Manager Container ID into your business dashboard.</div>
                    </li>
                </ul>
                <button type="button" class="btn-lime" onclick="openModal()">Configure Ad Funnel</button>
            </div>

            <div class="feature-visual visual-dark">
                <div class="pixel-flow-mockup">
                    <div class="pixel-step">
                        <div class="pixel-step-icon"><i class="fa-solid fa-star"></i></div>
                        <div class="pixel-step-text">
                            <strong>1. Customer Rates 5 Stars</strong>
                            <span>In-store QR scan or WhatsApp prompt completed</span>
                        </div>
                    </div>
                    <div class="pixel-step">
                        <div class="pixel-step-icon"><i class="fa-brands fa-meta"></i></div>
                        <div class="pixel-step-text">
                            <strong>2. Pixel Event Fired</strong>
                            <span>fbq('trackCustom', 'FiveStarReviewCompleted')</span>
                        </div>
                    </div>
                    <div class="pixel-step">
                        <div class="pixel-step-icon"><i class="fa-solid fa-users-viewfinder"></i></div>
                        <div class="pixel-step-text">
                            <strong>3. VIP Lookalike Audience Built</strong>
                            <span>Algorithm finds thousands of high-intent local prospects</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Industry Solutions -->
    <section class="industry-section">
        <div class="section-header-center">
            <div class="section-tag">Tailored Solutions</div>
            <h2 class="section-title">Built for High-Growth Local Businesses</h2>
            <p class="section-sub">See how Optibiz adapts to your unique operational workflow.</p>
        </div>

        <div class="industry-grid">
            <div class="industry-card">
                <div class="industry-icon"><i class="fa-solid fa-utensils"></i></div>
                <h3>Restaurants &amp; Cafes</h3>
                <p>Acrylic counter stands at checkout, bill-folder QR cards, and instant server tip motivation. Catch food or service issues before diners post to Google Maps.</p>
                <div class="industry-benefit"><i class="fa-solid fa-chart-line"></i> +45 New Google reviews / mo</div>
            </div>

            <div class="industry-card">
                <div class="industry-icon"><i class="fa-solid fa-stethoscope"></i></div>
                <h3>Dental &amp; Medical Clinics</h3>
                <p>HIPAA-sensitive private patient feedback routing. Empower satisfied patients to share their clinical experience while keeping confidential concerns private.</p>
                <div class="industry-benefit"><i class="fa-solid fa-shield-halved"></i> 99.4% Negative review shielding</div>
            </div>

            <div class="industry-card">
                <div class="industry-icon"><i class="fa-solid fa-bag-shopping"></i></div>
                <h3>Retail &amp; Boutiques</h3>
                <p>Printable QR on shopping bags and receipts, paired with post-purchase WhatsApp loyalty incentives to drive repeat weekend foot traffic.</p>
                <div class="industry-benefit"><i class="fa-solid fa-repeat"></i> +28% Repeat visit rate</div>
            </div>

            <div class="industry-card">
                <div class="industry-icon"><i class="fa-solid fa-scissors"></i></div>
                <h3>Salons &amp; Spas</h3>
                <p>Automated WhatsApp outreach 2 hours after styling appointments. Generate gorgeous Instagram social cards showcasing client praise and stylist names.</p>
                <div class="industry-benefit"><i class="fa-solid fa-wand-magic-sparkles"></i> 1-Click Instagram quote cards</div>
            </div>

            <div class="industry-card">
                <div class="industry-icon"><i class="fa-solid fa-wrench"></i></div>
                <h3>Auto Repair &amp; Service</h3>
                <p>Send review requests the minute the repair invoice is finalized. Build rock-solid local trust with verified customer vehicle maintenance reviews.</p>
                <div class="industry-benefit"><i class="fa-solid fa-star"></i> 4.9 Average rating on Google</div>
            </div>

            <div class="industry-card">
                <div class="industry-icon"><i class="fa-solid fa-briefcase"></i></div>
                <h3>Professional Services</h3>
                <p>Real estate agents, lawyers, and accountants can showcase high-ticket client recommendations with verified trust badges on their firm websites.</p>
                <div class="industry-benefit"><i class="fa-solid fa-arrow-trend-up"></i> Top 3 Google Local Pack rank</div>
            </div>
        </div>
    </section>

    <!-- Optibiz vs Traditional Comparison -->
    <section class="vs-section">
        <div class="section-header-center">
            <div class="section-tag">Direct Comparison</div>
            <h2 class="section-title">The Optibiz Advantage</h2>
            <p class="section-sub">Why conventional review collection methods fail, and how Optibiz delivers guaranteed results.</p>
        </div>

        <div class="vs-table-wrap">
            <table class="vs-table">
                <thead>
                    <tr>
                        <th style="width:30%">Capability</th>
                        <th style="width:35%">Traditional / Passive Approach</th>
                        <th class="col-optibiz">The Optibiz Engine</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Review Capture Rate</strong></td>
                        <td class="col-old">Under 1% (Only angry customers bother to write)</td>
                        <td class="col-optibiz">10% - 15% Verified Customer Conversion</td>
                    </tr>
                    <tr>
                        <td><strong>Negative Review Handling</strong></td>
                        <td class="col-old">Posted directly to Google Maps, damaging your public score</td>
                        <td class="col-optibiz">Intelligently shielded to internal manager resolution</td>
                    </tr>
                    <tr>
                        <td><strong>Channel Flexibility</strong></td>
                        <td class="col-old">Paper comment cards or forgotten email receipts</td>
                        <td class="col-optibiz">Omnichannel: Counter QR Stands + 98% Open WhatsApp</td>
                    </tr>
                    <tr>
                        <td><strong>Social Media Marketing</strong></td>
                        <td class="col-old">Manual copy-pasting into Photoshop or Canva</td>
                        <td class="col-optibiz">1-Click Auto-Generated Social Proof Graphics</td>
                    </tr>
                    <tr>
                        <td><strong>Paid Advertising Integration</strong></td>
                        <td class="col-old">Zero connection between reviews and ad campaigns</td>
                        <td class="col-optibiz">Meta &amp; Google Tag Manager Pixel conversion syncing</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Bottom CTA Banner -->
    <section class="cta-banner">
        <h2>Start Turning Customer Satisfaction Into Revenue</h2>
        <p>Set up your workspace in 60 seconds. Test all 6 engines free for 14 days with zero risk.</p>
        <div class="cta-banner-buttons">
            <button type="button" class="btn-lime" onclick="openModal()">
                Start 14-Day Free Trial <i class="fa-solid fa-arrow-right"></i>
            </button>
            <a href="pricing.php" class="btn-ghost-white">
                <i class="fa-solid fa-tags"></i> Compare All Plans
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer-main">
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
                    <li><a href="#" onclick="openModal(); return false;">Request Custom Quote</a></li>
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
                <a href="admin/login.php" style="color:#94a3b8;text-decoration:none">Sign In</a>
            </div>
        </div>
    </footer>

    <!-- ================= GET STARTED / TRIAL MODAL ================= -->
    <div class="modal-overlay" id="quoteModal" role="dialog" aria-modal="true" aria-labelledby="quoteModalTitle">
        <div class="modal-content">
            <button type="button" class="modal-close" id="quoteModalClose" aria-label="Close dialog">&times;</button>

            <!-- Multi-step form view -->
            <div id="quoteFormWrap">
                <div style="margin-bottom:24px">
                    <h2 id="quoteModalTitle" style="font-size:24px;font-weight:800;color:var(--primary-dark)">Start Your 14-Day Free Trial</h2>
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
                                <input type="text" id="q_company_name" name="company_name" placeholder="e.g. Acme Cafe &amp; Bistro" required>
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
                        <p class="subtitle">Pick a plan to get started with your 14-day free trial.</p>

                        <div class="form-group">
                            <label for="q_plan">Subscription Plan *</label>
                            <select id="q_plan" name="plan_id" required>
                                <option value="" disabled selected>Select a plan</option>
                                <?php foreach ($modal_plans as $plan): ?>
                                <option value="<?php echo (int)$plan['id']; ?>">
                                    <?php echo htmlspecialchars($plan['plan_name']); ?> — <?php echo fmt_price($plan['price'], $currency_symbol, $currency_position); ?>/month
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
                        <button type="submit" class="btn-lime" id="quoteSubmitBtn" hidden>
                            Start 14-Day Free Trial <i class="fa-solid fa-rocket"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Success view -->
            <div class="quote-success" id="quoteSuccess" hidden>
                <div class="quote-success-icon">
                    <i class="fa-solid fa-check"></i>
                </div>
                <h2 style="font-size:24px;font-weight:800;color:var(--primary-dark);margin-bottom:8px">Workspace Request Received!</h2>
                <p style="font-size:14px;color:var(--text-muted);margin-bottom:24px">
                    Thank you for joining Optibiz! We have received your details and our team is setting up your trial workspace. You will receive an onboarding email shortly.
                </p>
                <button type="button" class="btn-lime" id="quoteDoneBtn">Done</button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
    (function () {
        'use strict';

        var modal       = document.getElementById('quoteModal');
        var form        = document.getElementById('quoteForm');
        var formWrap    = document.getElementById('quoteFormWrap');
        var successView = document.getElementById('quoteSuccess');
        var closeBtn    = document.getElementById('quoteModalClose');
        var nextBtn     = document.getElementById('quoteNextBtn');
        var backBtn     = document.getElementById('quoteBackBtn');
        var submitBtn   = document.getElementById('quoteSubmitBtn');
        var alertBox    = document.getElementById('quoteAlert');

        var sections  = form.querySelectorAll('.form-section');
        var stepWraps = document.querySelectorAll('#quoteProgress .step-wrap');
        var stepLines = document.querySelectorAll('#quoteProgress .step-line');

        var currentStep = 1;
        var TOTAL_STEPS = 3;
        var submitting  = false;

        function showAlert(msg) {
            alertBox.textContent = msg;
            alertBox.hidden = false;
        }
        function hideAlert() {
            alertBox.hidden = true;
            alertBox.textContent = '';
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
            backBtn.hidden   = currentStep === 1;
            nextBtn.hidden   = currentStep === TOTAL_STEPS;
            submitBtn.hidden = currentStep !== TOTAL_STEPS;
            hideAlert();
        }

        window.openModal = function (planId) {
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
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            var firstInput = form.querySelector('.form-section.active input, .form-section.active select');
            if (firstInput) { firstInput.focus(); }
        };

        function closeModal() {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        closeBtn.addEventListener('click', closeModal);
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

            submitting = true;
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Creating Workspace <i class="fa-solid fa-circle-notch fa-spin"></i>';
            hideAlert();

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    formWrap.hidden = true;
                    successView.hidden = false;
                } else {
                    showAlert(data.message || 'Something went wrong. Please try again.');
                }
            })
            .catch(function () {
                showAlert('Could not connect to the server. Please try again.');
            })
            .finally(function () {
                submitting = false;
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Start 14-Day Free Trial <i class="fa-solid fa-rocket"></i>';
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
