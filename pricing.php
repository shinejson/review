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

// Fetch active subscription plans from DB
$plans = [];
$res_plans = $conn->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price ASC");
if ($res_plans) {
    while ($row = $res_plans->fetch_assoc()) {
        $plans[] = $row;
    }
}

// Fallback if DB empty
if (empty($plans)) {
    $plans = [
        ['id' => 1, 'plan_name' => 'Starter', 'price' => 29.99, 'max_ratings' => 100, 'max_customers' => 10, 'features' => 'Basic analytics, Email support, 10 customers, 100 ratings/month'],
        ['id' => 2, 'plan_name' => 'Professional', 'price' => 79.99, 'max_ratings' => 500, 'max_customers' => 50, 'features' => 'Advanced analytics, Priority support, 50 customers, 500 ratings/month, Custom branding'],
        ['id' => 3, 'plan_name' => 'Enterprise', 'price' => 199.99, 'max_ratings' => 9999, 'max_customers' => 999, 'features' => 'Full analytics suite, 24/7 support, Unlimited customers, Unlimited ratings, API access, White label']
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
    <title>Pricing &amp; Plans — Optibiz Review &amp; Reputation Platform</title>
    <meta name="description" content="Flexible and transparent pricing for businesses of all sizes. Collect genuine customer reviews, boost Google rankings, and automate social proof.">
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

        /* Hero Header */
        .pricing-hero {
            background: radial-gradient(circle at 80% 20%, #1a3c5a 0%, var(--primary-dark) 70%);
            padding: 70px 5% 100px;
            color: #ffffff;
            text-align: center;
            position: relative;
        }
        .pricing-hero-container {
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
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .pricing-hero h1 {
            font-size: 46px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.5px;
            margin-bottom: 18px;
        }
        .pricing-hero h1 span {
            color: var(--accent-lime);
        }
        .pricing-hero p {
            font-size: 17px;
            color: #94a3b8;
            max-width: 680px;
            margin: 0 auto 36px;
            line-height: 1.6;
        }

        /* Billing Toggle Switch */
        .billing-toggle-wrap {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.08);
            padding: 6px 10px;
            border-radius: 40px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            margin: 0 auto;
        }
        .toggle-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 18px;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .toggle-btn.active {
            background: var(--accent-lime);
            color: var(--primary-dark);
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .save-badge {
            background: #22c55e;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Pricing Cards Section */
        .pricing-section {
            padding: 0 5% 90px;
            margin-top: -50px;
            position: relative;
            z-index: 10;
        }
        .pricing-grid {
            max-width: 1240px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            align-items: stretch;
        }
        .pricing-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 40px 32px;
            box-shadow: 0 10px 40px rgba(15, 23, 42, 0.06);
            border: 1px solid var(--border-line);
            transition: all 0.3s ease;
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .pricing-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
        }
        .pricing-card.featured {
            border: 2px solid var(--accent-lime);
            background: linear-gradient(180deg, #ffffff 0%, #fafffa 100%);
            box-shadow: 0 15px 45px rgba(194, 245, 66, 0.15);
            transform: scale(1.02);
        }
        .pricing-card.featured:hover {
            transform: scale(1.02) translateY(-8px);
        }
        .badge-popular {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--accent-lime);
            color: var(--primary-dark);
            padding: 6px 20px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .card-plan-header {
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border-line);
        }
        .card-plan-header h3 {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-dark);
            margin-bottom: 6px;
        }
        .card-plan-desc {
            font-size: 13.5px;
            color: var(--text-muted);
            min-height: 40px;
        }
        .card-price-wrap {
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin: 18px 0 6px;
        }
        .card-price {
            font-size: 46px;
            font-weight: 800;
            color: var(--primary-dark);
            line-height: 1;
        }
        .card-period {
            font-size: 15px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .annual-subtext {
            font-size: 12.5px;
            color: #15803d;
            font-weight: 600;
            min-height: 18px;
        }
        .card-limits {
            background: #f8fafc;
            border-radius: 10px;
            padding: 12px 14px;
            margin: 16px 0;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
        }
        .limit-item {
            text-align: center;
        }
        .limit-val {
            font-weight: 800;
            color: var(--primary-dark);
        }
        .limit-lbl {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .plan-features-list {
            list-style: none;
            margin-bottom: 32px;
            flex: 1;
        }
        .plan-features-list li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 0;
            font-size: 14px;
            color: #334155;
            line-height: 1.4;
            border-bottom: 1px solid #f1f5f9;
        }
        .plan-features-list li:last-child {
            border-bottom: none;
        }
        .plan-features-list li i.fa-check {
            color: #16a34a;
            margin-top: 3px;
            flex-shrink: 0;
        }
        .plan-features-list li i.fa-xmark {
            color: #cbd5e1;
            margin-top: 3px;
            flex-shrink: 0;
        }
        .btn-select-plan {
            display: block;
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.25s ease;
        }
        .btn-select-plan.primary {
            background: var(--accent-lime);
            color: var(--primary-dark);
        }
        .btn-select-plan.primary:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(194, 245, 66, 0.35);
        }
        .btn-select-plan.secondary {
            background: #f1f5f9;
            color: var(--primary-dark);
        }
        .btn-select-plan.secondary:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }
        .card-guarantee {
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
            margin-top: 12px;
        }

        /* Trust Banner */
        .trust-banner {
            padding: 40px 5%;
            background: #f8fafc;
            border-top: 1px solid var(--border-line);
            border-bottom: 1px solid var(--border-line);
            text-align: center;
        }
        .trust-badges {
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 24px;
        }
        .t-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .t-badge i {
            color: #10b981;
            font-size: 18px;
        }

        /* ROI Calculator Section */
        .roi-section {
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
        .roi-box {
            max-width: 1100px;
            margin: 0 auto;
            background: linear-gradient(135deg, #0f2438 0%, #163652 100%);
            border-radius: 24px;
            padding: 48px;
            color: #ffffff;
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 50px;
            box-shadow: 0 20px 50px rgba(15, 36, 56, 0.18);
        }
        .roi-input-group {
            margin-bottom: 28px;
        }
        .roi-input-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 10px;
        }
        .roi-input-header label {
            font-size: 15px;
            font-weight: 600;
            color: #e2e8f0;
        }
        .roi-val-badge {
            background: rgba(194, 245, 66, 0.15);
            color: var(--accent-lime);
            font-size: 16px;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 8px;
        }
        .slider-range {
            width: 100%;
            height: 6px;
            border-radius: 5px;
            background: #334155;
            outline: none;
            -webkit-appearance: none;
            cursor: pointer;
        }
        .slider-range::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--accent-lime);
            cursor: pointer;
            box-shadow: 0 0 10px rgba(194, 245, 66, 0.5);
        }
        .roi-output-card {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            padding: 32px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .roi-stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .roi-stat-item {
            background: rgba(0, 0, 0, 0.2);
            padding: 16px;
            border-radius: 12px;
        }
        .roi-stat-num {
            font-size: 26px;
            font-weight: 800;
            color: var(--accent-lime);
            line-height: 1.2;
        }
        .roi-stat-label {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
            line-height: 1.3;
        }
        .roi-highlight-box {
            background: rgba(194, 245, 66, 0.1);
            border: 1px dashed rgba(194, 245, 66, 0.4);
            border-radius: 12px;
            padding: 18px;
            text-align: center;
            margin-bottom: 24px;
        }
        .roi-lift-label {
            font-size: 12px;
            color: #cbd5e1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .roi-lift-value {
            font-size: 34px;
            font-weight: 900;
            color: #ffffff;
            margin: 4px 0;
        }
        .roi-lift-roi {
            font-size: 13px;
            color: var(--accent-lime);
            font-weight: 700;
        }
        .btn-roi-cta {
            background: var(--accent-lime);
            color: var(--primary-dark);
            text-align: center;
            font-weight: 800;
            padding: 14px;
            border-radius: 12px;
            text-decoration: none;
            display: block;
            cursor: pointer;
            transition: all 0.25s;
            border: none;
            width: 100%;
        }
        .btn-roi-cta:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
        }

        /* Feature Matrix Section */
        .matrix-section {
            padding: 90px 5%;
            background: #f8fafc;
        }
        .matrix-wrap {
            max-width: 1140px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid var(--border-line);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);
            overflow-x: auto;
        }
        .matrix-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 720px;
        }
        .matrix-table th, .matrix-table td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid var(--border-line);
        }
        .matrix-table th {
            background: #f1f5f9;
            color: var(--primary-dark);
            font-size: 15px;
            font-weight: 800;
        }
        .matrix-table th.col-plan {
            text-align: center;
            width: 22%;
        }
        .matrix-table th.col-plan.featured {
            background: #ecfccb;
            color: var(--primary-dark);
            border-bottom: 2px solid #84cc16;
        }
        .matrix-table td.col-plan {
            text-align: center;
            font-weight: 500;
        }
        .matrix-table td.col-plan.featured {
            background: rgba(236, 252, 203, 0.25);
            font-weight: 600;
        }
        .matrix-category-row td {
            background: #f8fafc;
            font-weight: 800;
            color: var(--primary-dark);
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.8px;
            padding: 12px 20px;
        }
        .matrix-table i.fa-check-circle {
            color: #16a34a;
            font-size: 16px;
        }
        .matrix-table i.fa-minus {
            color: #cbd5e1;
            font-size: 14px;
        }

        /* FAQ Accordion Section */
        .faq-section {
            padding: 90px 5%;
            background: #ffffff;
        }
        .faq-container {
            max-width: 860px;
            margin: 0 auto;
        }
        .faq-item {
            border: 1px solid var(--border-line);
            border-radius: 14px;
            margin-bottom: 16px;
            background: #ffffff;
            transition: all 0.25s;
            overflow: hidden;
        }
        .faq-item:hover {
            border-color: #cbd5e1;
        }
        .faq-question {
            padding: 22px 26px;
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-dark);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            user-select: none;
        }
        .faq-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: var(--primary-dark);
            transition: transform 0.3s;
        }
        .faq-item.open .faq-icon {
            transform: rotate(180deg);
            background: var(--accent-lime);
        }
        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease, padding 0.35s ease;
            padding: 0 26px;
            font-size: 14.5px;
            color: #475569;
            line-height: 1.6;
        }
        .faq-item.open .faq-answer {
            max-height: 300px;
            padding: 0 26px 22px;
        }

        /* Bottom CTA Section */
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
        .btn-lime {
            background: var(--accent-lime);
            color: var(--primary-dark);
            padding: 14px 32px;
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
            .pricing-grid { grid-template-columns: 1fr; max-width: 440px; }
            .pricing-card.featured { transform: none; }
            .pricing-card.featured:hover { transform: translateY(-8px); }
            .roi-box { grid-template-columns: 1fr; padding: 32px; }
            .nav-pill { display: none; }
            .footer-grid-container { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 640px) {
            .pricing-hero h1 { font-size: 32px; }
            .form-row { grid-template-columns: 1fr; }
            .roi-stat-grid { grid-template-columns: 1fr; }
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
                <a href="features.php">Features</a>
                <a href="pricing.php" class="active">Pricing</a>
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

    <!-- Pricing Hero Section -->
    <section class="pricing-hero">
        <div class="pricing-hero-container">
            <div class="badge-tag">
                <i class="fa-solid fa-shield-check"></i> Simple, Predictable Pricing
            </div>
            <h1>Choose the Plan That <span>Powers Your Growth</span></h1>
            <p>
                From boutique local shops to national multi-branch operators, Optibiz provides the tools you need to collect 5-star reviews, shield your business from negative feedback, and grow recurring revenue.
            </p>

            <!-- Billing Toggle -->
            <div class="billing-toggle-wrap">
                <button type="button" class="toggle-btn active" id="btnMonthly" onclick="setBilling('monthly')">
                    Monthly Billing
                </button>
                <button type="button" class="toggle-btn" id="btnAnnual" onclick="setBilling('annual')">
                    Annual Billing <span class="save-badge">Save 20% + 2 Mo Free</span>
                </button>
            </div>
        </div>
    </section>

    <!-- Pricing Cards Section -->
    <section class="pricing-section">
        <div class="pricing-grid">
            <?php foreach ($plans as $p): 
                $is_featured = (stripos($p['plan_name'], 'pro') !== false);
                $monthly_price = (float)$p['price'];
                $annual_price_per_month = round($monthly_price * 0.8, 2);
                $annual_billed_total = round($annual_price_per_month * 12, 2);
            ?>
            <div class="pricing-card <?php echo $is_featured ? 'featured' : ''; ?>" data-plan-id="<?php echo (int)$p['id']; ?>">
                <?php if ($is_featured): ?>
                <div class="badge-popular">Most Popular</div>
                <?php endif; ?>

                <div class="card-plan-header">
                    <h3><?php echo htmlspecialchars($p['plan_name']); ?></h3>
                    <p class="card-plan-desc">
                        <?php 
                        if (stripos($p['plan_name'], 'start') !== false) {
                            echo 'Ideal for single retail shops, solo consultants, and new storefronts.';
                        } elseif (stripos($p['plan_name'], 'pro') !== false) {
                            echo 'For bustling restaurants, busy clinics, salons, and growing teams.';
                        } else {
                            echo 'Complete suite for multi-branch brands, franchises, and high-volume venues.';
                        }
                        ?>
                    </p>

                    <div class="card-price-wrap">
                        <div class="card-price"
                             data-monthly="<?php echo fmt_price($monthly_price, $currency_symbol, $currency_position); ?>"
                             data-annual="<?php echo fmt_price($annual_price_per_month, $currency_symbol, $currency_position); ?>">
                            <?php echo fmt_price($monthly_price, $currency_symbol, $currency_position); ?>
                        </div>
                        <div class="card-period">/month</div>
                    </div>

                    <div class="annual-subtext"
                         data-monthly=""
                         data-annual="Billed annually at <?php echo fmt_price($annual_billed_total, $currency_symbol, $currency_position); ?>/yr">
                    </div>
                </div>

                <div class="card-limits">
                    <div class="limit-item">
                        <div class="limit-val"><?php echo (int)$p['max_ratings'] >= 9000 ? 'Unlimited' : number_format((int)$p['max_ratings']); ?></div>
                        <div class="limit-lbl">Reviews/Mo</div>
                    </div>
                    <div class="limit-item">
                        <div class="limit-val"><?php echo (int)$p['max_customers'] >= 900 ? 'Unlimited' : number_format((int)$p['max_customers']); ?></div>
                        <div class="limit-lbl">Profiles/Locs</div>
                    </div>
                    <div class="limit-item">
                        <div class="limit-val"><?php echo $is_featured ? 'Priority' : (stripos($p['plan_name'], 'enter') !== false ? '24/7 SLA' : 'Standard'); ?></div>
                        <div class="limit-lbl">Support</div>
                    </div>
                </div>

                <ul class="plan-features-list">
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <span><strong>Printable Counter QR Stands</strong> with live redirection</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <span><strong>Smart Google Review Booster</strong> (4-5★ straight to Google)</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <span><strong>Negative Review Shield</strong> (1-3★ to private resolution)</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <span><strong>Automated WhatsApp Requester</strong> outreach engine</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <span><strong>Social Proof Graphic Card Studio</strong> (Square &amp; Story formats)</span>
                    </li>
                    <?php if ($is_featured || stripos($p['plan_name'], 'enter') !== false): ?>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <span><strong>Free Acrylic Stand</strong> shipped to your business counter</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <span><strong>Ad Funnel Pixels</strong> (Meta Pixel &amp; Google Tag Manager)</span>
                    </li>
                    <?php else: ?>
                    <li style="color:#94a3b8">
                        <i class="fa-solid fa-xmark"></i>
                        <span>Free Physical Acrylic Stand (Print templates only)</span>
                    </li>
                    <li style="color:#94a3b8">
                        <i class="fa-solid fa-xmark"></i>
                        <span>Ad Retargeting Pixel Funnel</span>
                    </li>
                    <?php endif; ?>

                    <?php if (stripos($p['plan_name'], 'enter') !== false): ?>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <span><strong>Full White-Labeling</strong> &amp; Custom Domain Mapping</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-check"></i>
                        <span><strong>REST API Access</strong> for POS &amp; CRM synchronization</span>
                    </li>
                    <?php endif; ?>
                </ul>

                <button type="button" class="btn-select-plan <?php echo $is_featured ? 'primary' : 'secondary'; ?>" onclick="openModal(<?php echo (int)$p['id']; ?>)">
                    Start 14-Day Free Trial
                </button>
                <div class="card-guarantee">No credit card required • Instant access</div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Trust Badges Bar -->
    <div class="trust-banner">
        <div class="trust-badges">
            <div class="t-badge"><i class="fa-solid fa-shield-check"></i> 14-Day Free Full Trial</div>
            <div class="t-badge"><i class="fa-solid fa-credit-card"></i> No Credit Card Required</div>
            <div class="t-badge"><i class="fa-solid fa-clock-rotate-left"></i> Cancel or Switch Anytime</div>
            <div class="t-badge"><i class="fa-solid fa-headset"></i> Dedicated Onboarding Support</div>
        </div>
    </div>

    <!-- Interactive Review ROI Calculator -->
    <section class="roi-section">
        <div class="section-header-center">
            <div class="section-tag">ROI Forecaster</div>
            <h2 class="section-title">See How Fast Optibiz Pays for Itself</h2>
            <p class="section-sub">Adjust your customer footfall and ticket size to calculate your projected 5-star review acceleration and revenue lift.</p>
        </div>

        <div class="roi-box">
            <div class="roi-controls">
                <div class="roi-input-group">
                    <div class="roi-input-header">
                        <label for="sliderCustomers">Monthly Customers / Visits</label>
                        <span class="roi-val-badge" id="valCustomers">400</span>
                    </div>
                    <input type="range" id="sliderCustomers" class="slider-range" min="50" max="3000" step="25" value="400">
                </div>

                <div class="roi-input-group">
                    <div class="roi-input-header">
                        <label for="sliderTicket">Average Ticket / Order Value</label>
                        <span class="roi-val-badge" id="valTicket"><?php echo $currency_symbol; ?>45</span>
                    </div>
                    <input type="range" id="sliderTicket" class="slider-range" min="10" max="500" step="5" value="45">
                </div>

                <div class="roi-input-group">
                    <div class="roi-input-header">
                        <label for="sliderScore">Current Google Rating</label>
                        <span class="roi-val-badge" id="valScore">4.1 ★</span>
                    </div>
                    <input type="range" id="sliderScore" class="slider-range" min="30" max="49" step="1" value="41">
                </div>

                <p style="font-size:12.5px;color:#94a3b8;line-height:1.5;margin-top:16px">
                    <i class="fa-solid fa-circle-info" style="color:var(--accent-lime)"></i>
                    Calculations based on verified Optibiz conversion benchmarks (12% review collection rate via Counter Stands &amp; WhatsApp vs 1.1% unprompted) and Harvard Business Review's local rating impact study.
                </p>
            </div>

            <div class="roi-output-card">
                <div class="roi-stat-grid">
                    <div class="roi-stat-item">
                        <div class="roi-stat-num" id="outNewReviews">+48</div>
                        <div class="roi-stat-label">New 5-Star Reviews / Mo</div>
                    </div>
                    <div class="roi-stat-item">
                        <div class="roi-stat-num" id="outNewScore">4.8 ★</div>
                        <div class="roi-stat-label">Projected 90-Day Rating</div>
                    </div>
                </div>

                <div class="roi-highlight-box">
                    <div class="roi-lift-label">Estimated Monthly Revenue Lift</div>
                    <div class="roi-lift-value" id="outRevenueLift"><?php echo $currency_symbol; ?>1,620 / mo</div>
                    <div class="roi-lift-roi" id="outROI">Approx 20x Return on Investment</div>
                </div>

                <button type="button" class="btn-roi-cta" onclick="openModal(2)">
                    Unlock This Growth on Pro Plan <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </section>

    <!-- Detailed Feature Comparison Matrix -->
    <section class="matrix-section">
        <div class="section-header-center">
            <div class="section-tag">Comparison Matrix</div>
            <h2 class="section-title">Compare Every Feature Side-by-Side</h2>
            <p class="section-sub">Transparent breakdown of what is included across every tier.</p>
        </div>

        <div class="matrix-wrap">
            <table class="matrix-table">
                <thead>
                    <tr>
                        <th style="width:34%">Feature Capabilities</th>
                        <th class="col-plan">Starter</th>
                        <th class="col-plan featured">Professional</th>
                        <th class="col-plan">Enterprise</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Review Collection -->
                    <tr class="matrix-category-row">
                        <td colspan="4"><i class="fa-solid fa-qrcode" style="margin-right:6px"></i> In-Store &amp; Direct Review Collection</td>
                    </tr>
                    <tr>
                        <td>Printable Counter QR Stand Generator (PDF &amp; PNG)</td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan featured"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                    </tr>
                    <tr>
                        <td>Free Acrylic Counter Stand Shipped to Counter</td>
                        <td class="col-plan"><i class="fa-solid fa-minus"></i></td>
                        <td class="col-plan featured">1 Stand Included</td>
                        <td class="col-plan">Up to 5 Stands</td>
                    </tr>
                    <tr>
                        <td>Automated WhatsApp Review Requester Engine</td>
                        <td class="col-plan">Manual Trigger</td>
                        <td class="col-plan featured"><i class="fa-solid fa-check-circle"></i> Automated</td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i> High-Volume API</td>
                    </tr>
                    <tr>
                        <td>Web-Based Instant Tablet Kiosk Mode</td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan featured"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                    </tr>

                    <!-- Shielding & Google Boost -->
                    <tr class="matrix-category-row">
                        <td colspan="4"><i class="fa-solid fa-shield-halved" style="margin-right:6px"></i> Google Booster &amp; Negative Feedback Shield</td>
                    </tr>
                    <tr>
                        <td>Smart Routing (4-5★ straight to Google Business)</td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan featured"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                    </tr>
                    <tr>
                        <td>Negative Review Shield (1-3★ to internal resolution)</td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan featured"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                    </tr>
                    <tr>
                        <td>Negative Rating Instant Alerts</td>
                        <td class="col-plan">Email Only</td>
                        <td class="col-plan featured">Email + WhatsApp</td>
                        <td class="col-plan">Email + WhatsApp + SMS</td>
                    </tr>
                    <tr>
                        <td>Direct Customer Resolution Ticket Tracking</td>
                        <td class="col-plan"><i class="fa-solid fa-minus"></i></td>
                        <td class="col-plan featured"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                    </tr>

                    <!-- Marketing & Social Proof -->
                    <tr class="matrix-category-row">
                        <td colspan="4"><i class="fa-solid fa-wand-magic-sparkles" style="margin-right:6px"></i> Social Proof &amp; Graphic Studio</td>
                    </tr>
                    <tr>
                        <td>Social Proof Graphic Card Studio (Instagram &amp; Facebook)</td>
                        <td class="col-plan">3 Standard Themes</td>
                        <td class="col-plan featured">Unlimited Custom Themes</td>
                        <td class="col-plan">Full Custom Branding</td>
                    </tr>
                    <tr>
                        <td>Website Review Embed Widgets (Carousel, Badges, Wall)</td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan featured"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                    </tr>
                    <tr>
                        <td>Community Q&amp;A Pre-Purchase Engine</td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan featured"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                    </tr>
                    <tr>
                        <td>Google Tag Manager &amp; Meta Pixel Retargeting</td>
                        <td class="col-plan"><i class="fa-solid fa-minus"></i></td>
                        <td class="col-plan featured"><i class="fa-solid fa-check-circle"></i></td>
                        <td class="col-plan"><i class="fa-solid fa-check-circle"></i></td>
                    </tr>

                    <!-- Limits & SLA -->
                    <tr class="matrix-category-row">
                        <td colspan="4"><i class="fa-solid fa-sliders" style="margin-right:6px"></i> Capacity &amp; SLA</td>
                    </tr>
                    <tr>
                        <td>Included Monthly Ratings</td>
                        <td class="col-plan">100 / mo</td>
                        <td class="col-plan featured">500 / mo</td>
                        <td class="col-plan">Unlimited</td>
                    </tr>
                    <tr>
                        <td>Business Profiles / Locations Managed</td>
                        <td class="col-plan">Up to 10</td>
                        <td class="col-plan featured">Up to 50</td>
                        <td class="col-plan">Unlimited</td>
                    </tr>
                    <tr>
                        <td>Support &amp; Dedicated Account Manager</td>
                        <td class="col-plan">Email Support</td>
                        <td class="col-plan featured">Priority Support</td>
                        <td class="col-plan">Dedicated Manager (24/7)</td>
                    </tr>
                    <tr>
                        <td>Action</td>
                        <td class="col-plan">
                            <button type="button" class="btn-select-plan secondary" style="padding:8px 14px;font-size:13px" onclick="openModal(1)">Select Starter</button>
                        </td>
                        <td class="col-plan featured">
                            <button type="button" class="btn-select-plan primary" style="padding:8px 14px;font-size:13px" onclick="openModal(2)">Select Pro</button>
                        </td>
                        <td class="col-plan">
                            <button type="button" class="btn-select-plan secondary" style="padding:8px 14px;font-size:13px" onclick="openModal(3)">Select Enterprise</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- FAQ Accordion Section -->
    <section class="faq-section">
        <div class="section-header-center">
            <div class="section-tag">Got Questions?</div>
            <h2 class="section-title">Frequently Asked Questions</h2>
            <p class="section-sub">Everything you need to know about our billing, setup, and features.</p>
        </div>

        <div class="faq-container">
            <div class="faq-item open">
                <div class="faq-question">
                    <span>How does the 14-day free trial work?</span>
                    <span class="faq-icon"><i class="fa-solid fa-chevron-down"></i></span>
                </div>
                <div class="faq-answer">
                    You can start testing all features of your chosen plan immediately without entering any credit card information. Generate QR codes, test WhatsApp invites, and start collecting reviews in less than 5 minutes. If you love the platform, you can upgrade before your trial ends.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <span>How does Google Review routing protect my reputation?</span>
                    <span class="faq-icon"><i class="fa-solid fa-chevron-down"></i></span>
                </div>
                <div class="faq-answer">
                    When a customer scans your counter stand or clicks your WhatsApp link, they submit their star rating. If they give 4 or 5 stars, they are seamlessly prompted with a direct button to publish their praise on your Google Business Profile. If they give 1, 2, or 3 stars, they are routed to a private feedback form that alerts your manager instantly via email, allowing you to fix customer issues privately before they hurt your public search rating.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <span>Do you provide physical Counter QR stands?</span>
                    <span class="faq-icon"><i class="fa-solid fa-chevron-down"></i></span>
                </div>
                <div class="faq-answer">
                    Yes! All plans include high-resolution printable PDF and PNG designs that fit standard counter tent sizes. Customers on the Professional plan receive 1 complimentary premium acrylic countertop stand shipped directly to their storefront. Enterprise customers receive up to 5 complimentary stands with custom branding.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <span>What happens if I exceed my monthly ratings limit?</span>
                    <span class="faq-icon"><i class="fa-solid fa-chevron-down"></i></span>
                </div>
                <div class="faq-answer">
                    We never shut off your review collection or leave a customer hanging. If you experience an unexpected spike in customers and cross your plan limit, we simply notify you and give you the option to upgrade to the next tier or continue with a modest per-review overage.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <span>Can I cancel or change plans anytime?</span>
                    <span class="faq-icon"><i class="fa-solid fa-chevron-down"></i></span>
                </div>
                <div class="faq-answer">
                    Absolutely. There are no contracts, commitments, or cancellation fees. You can upgrade, downgrade, or cancel your subscription at any time directly from your business settings panel with one click.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <span>Can I manage multiple locations from one dashboard?</span>
                    <span class="faq-icon"><i class="fa-solid fa-chevron-down"></i></span>
                </div>
                <div class="faq-answer">
                    Yes. Even our Starter plan supports up to 10 profiles or branches, while our Professional and Enterprise plans support up to 50 and unlimited locations respectively. Each location gets its own isolated QR codes, staff notifications, and analytics.
                </div>
            </div>
        </div>
    </section>

    <!-- Bottom CTA Banner -->
    <section class="cta-banner">
        <h2>Ready to Supercharge Your Local Reputation?</h2>
        <p>Join hundreds of businesses converting satisfied customers into glowing 5-star Google reviews every single day.</p>
        <div class="cta-banner-buttons">
            <button type="button" class="btn-lime" onclick="openModal()">
                Start 14-Day Free Trial <i class="fa-solid fa-arrow-right"></i>
            </button>
            <a href="features.php" class="btn-ghost-white">
                <i class="fa-solid fa-layer-group"></i> Explore All Features
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
                                <?php foreach ($plans as $plan): ?>
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

        // 1. Monthly vs Annual Billing Toggle
        window.setBilling = function (type) {
            var btnMonthly = document.getElementById('btnMonthly');
            var btnAnnual = document.getElementById('btnAnnual');
            var prices = document.querySelectorAll('.card-price');
            var subtexts = document.querySelectorAll('.annual-subtext');

            if (type === 'annual') {
                btnAnnual.classList.add('active');
                btnMonthly.classList.remove('active');
                prices.forEach(function (el) {
                    el.textContent = el.getAttribute('data-annual');
                });
                subtexts.forEach(function (el) {
                    el.textContent = el.getAttribute('data-annual');
                });
            } else {
                btnMonthly.classList.add('active');
                btnAnnual.classList.remove('active');
                prices.forEach(function (el) {
                    el.textContent = el.getAttribute('data-monthly');
                });
                subtexts.forEach(function (el) {
                    el.textContent = '';
                });
            }
        };

        // 2. Interactive Review ROI Calculator
        var sliderCustomers = document.getElementById('sliderCustomers');
        var sliderTicket    = document.getElementById('sliderTicket');
        var sliderScore     = document.getElementById('sliderScore');

        var valCustomers    = document.getElementById('valCustomers');
        var valTicket       = document.getElementById('valTicket');
        var valScore        = document.getElementById('valScore');

        var outNewReviews   = document.getElementById('outNewReviews');
        var outNewScore     = document.getElementById('outNewScore');
        var outRevenueLift  = document.getElementById('outRevenueLift');
        var outROI          = document.getElementById('outROI');

        var currSymbol      = <?php echo json_encode($currency_symbol); ?>;

        function updateROI() {
            var cust = parseInt(sliderCustomers.value, 10);
            var ticket = parseInt(sliderTicket.value, 10);
            var score = parseInt(sliderScore.value, 10) / 10;

            valCustomers.textContent = cust.toLocaleString();
            valTicket.textContent = currSymbol + ticket;
            valScore.textContent = score.toFixed(1) + ' ★';

            // Projected 5-star review collection: ~12% response rate with Optibiz
            var newReviews = Math.round(cust * 0.12);
            outNewReviews.textContent = '+' + newReviews;

            // Projected rating improvement towards 4.8 - 4.9
            var projectedScore = Math.min(4.9, score + 0.5);
            outNewScore.textContent = projectedScore.toFixed(1) + ' ★';

            // Estimated revenue lift: ~6% - 9% boost from rating uplift
            var scoreDelta = Math.max(0.2, projectedScore - score);
            var liftPct = (scoreDelta / 1.0) * 0.08; // 8% per full star lift
            var monthlyLift = Math.round(cust * ticket * liftPct);

            outRevenueLift.textContent = currSymbol + monthlyLift.toLocaleString() + ' / mo';

            // ROI vs Pro plan ($79.99/mo)
            var roiRatio = Math.round(monthlyLift / 79.99);
            outROI.textContent = 'Approx ' + (roiRatio > 1 ? roiRatio : 10) + 'x Return on Investment';
        }

        sliderCustomers.addEventListener('input', updateROI);
        sliderTicket.addEventListener('input', updateROI);
        sliderScore.addEventListener('input', updateROI);
        updateROI();

        // 3. FAQ Accordion Toggle
        var faqItems = document.querySelectorAll('.faq-item');
        faqItems.forEach(function (item) {
            var q = item.querySelector('.faq-question');
            q.addEventListener('click', function () {
                var isOpen = item.classList.contains('open');
                faqItems.forEach(function (other) { other.classList.remove('open'); });
                if (!isOpen) {
                    item.classList.add('open');
                }
            });
        });

        // 4. Modal Setup
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