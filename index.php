<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

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

// Data for the "Get Started" quote modal
$modal_categories = [];
$category_result = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($category_result) {
    while ($row = $category_result->fetch_assoc()) {
        $modal_categories[] = $row;
    }
}

$modal_plans = [];
$plan_result = $conn->query("SELECT id, plan_name, price FROM subscription_plans WHERE status = 'active' ORDER BY price ASC");
if ($plan_result) {
    while ($row = $plan_result->fetch_assoc()) {
        $modal_plans[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optibiz — All-in-One Review, Trust &amp; Reputation Growth Platform</title>
    <meta name="description" content="Collect customer reviews with Counter QR Stands, WhatsApp requests, Google Review boosting, and automated social proof graphics.">
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
        .demo-biz-info h3 {
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
            .hero-container { grid-template-columns: 1fr; }
            .features-grid, .trust-grid, .companies-grid, .reviews-grid, .footer-grid-container {
                grid-template-columns: 1fr 1fr;
            }
            .nav-pill { display: none; }
        }
        @media (max-width: 640px) {
            .features-grid, .trust-grid, .companies-grid, .reviews-grid, .footer-grid-container, .how-grid {
                grid-template-columns: 1fr;
            }
            .hero-title { font-size: 34px; }
            .form-row { grid-template-columns: 1fr; }
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
                <a href="index.php" class="active">Home</a>
                <a href="features.php">Features</a>
                <a href="pricing.php">Pricing</a>
                <a href="companies.php">Directory</a>
                <a href="#how-it-works">How It Works</a>
            </nav>
            <div class="nav-actions">
                <a href="admin/login.php" class="btn-signin">Sign In</a>
                <button type="button" class="btn-quote" onclick="openModal()">
                    Get Started <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>
        </header>
    </div>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-container">
            <div class="hero-left">
                <div class="badge-tag">
                    <i class="fa-solid fa-star"></i> Omnichannel Review &amp; Reputation Growth
                </div>
                <h1 class="hero-title">Turn Customer Reviews Into Your <span>#1 Growth Engine</span></h1>
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

            <!-- Hero Interactive Live Demo Card -->
            <div class="hero-graphic-wrap">
                <div class="hero-demo-card">
                    <div class="demo-badge-top">
                        <i class="fa-solid fa-bolt"></i> Live Interactive Preview
                    </div>
                    <div class="demo-biz-header">
                        <div class="demo-biz-avatar">A</div>
                        <div class="demo-biz-info">
                            <h3>Acme Bistro &amp; Cafe</h3>
                            <p>Powered by Optibiz Counter QR Stand</p>
                        </div>
                    </div>

                    <div class="demo-question">How was your experience today?</div>

                    <div class="interactive-stars" id="demoStars">
                        <button type="button" class="star-btn" data-score="1" title="1 Star"><i class="fa-solid fa-star"></i></button>
                        <button type="button" class="star-btn" data-score="2" title="2 Stars"><i class="fa-solid fa-star"></i></button>
                        <button type="button" class="star-btn" data-score="3" title="3 Stars"><i class="fa-solid fa-star"></i></button>
                        <button type="button" class="star-btn" data-score="4" title="4 Stars"><i class="fa-solid fa-star"></i></button>
                        <button type="button" class="star-btn active" data-score="5" title="5 Stars"><i class="fa-solid fa-star"></i></button>
                    </div>

                    <div class="demo-feedback-box" id="demoFeedback">
                        <div class="routing-pill google">
                            <i class="fa-brands fa-google"></i> Google Review Booster Triggered!
                        </div>
                        <p style="color:#166534;font-size:12.5px;font-weight:600">
                            ★ 5-Star score detected: Customer is auto-redirected to Google Reviews &amp; an Instagram Social Proof Card is generated!
                        </p>
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
            <a href="rate/index.php?company=<?php echo (int) $fc['id']; ?>" class="company-preview-card">
                <div class="c-card-top">
                    <div class="c-card-logo">
                        <?php if (!empty($fc['logo'])): ?>
                            <img src="<?php echo htmlspecialchars($fc['logo']); ?>" alt="<?php echo htmlspecialchars($fc['company_name']); ?>">
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
                View All Companies in Directory <i class="fa-solid fa-arrow-right"></i>
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
                <div class="rq-author">
                    <strong><?php echo htmlspecialchars($rev['customer_name'] ?: 'Verified Customer'); ?></strong>
                    <span><i class="fa-solid fa-circle-check"></i> Verified</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

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

    <!-- Footer -->
    <footer class="site-footer" id="contact">
        <div class="footer-grid-container">
            <div class="footer-col-brand">
                <a href="index.php" class="logo">
                    <span class="logo-icon"><i class="fa-solid fa-shapes"></i></span>
                    Optibiz
                </a>
                <p class="footer-brand-desc">
                    The modern business review, reputation management, and customer acquisition platform. Built for in-store foot traffic and online growth.
                </p>
                <div style="display:flex;gap:12px;font-size:16px">
                    <a href="https://twitter.com" style="color:#94a3b8"><i class="fa-brands fa-x-twitter"></i></a>
                    <a href="https://linkedin.com" style="color:#94a3b8"><i class="fa-brands fa-linkedin"></i></a>
                    <a href="https://facebook.com" style="color:#94a3b8"><i class="fa-brands fa-facebook"></i></a>
                    <a href="https://instagram.com" style="color:#94a3b8"><i class="fa-brands fa-instagram"></i></a>
                </div>
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

    <!-- ================= GET STARTED / QUOTE MODAL ================= -->
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
                        <p class="subtitle">Pick a plan to get started with your 14-day free trial.</p>

                        <div class="form-group">
                            <label for="q_plan">Subscription Plan *</label>
                            <select id="q_plan" name="plan_id" required>
                                <option value="" disabled selected>Select a plan</option>
                                <?php foreach ($modal_plans as $plan): ?>
                                <option value="<?php echo (int)$plan['id']; ?>">
                                    <?php echo htmlspecialchars($plan['plan_name']); ?> — $<?php echo number_format((float)$plan['price'], 2); ?>/month
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
                    <p style="margin:12px 0 0;font-size:12.5px;color:#64748b;line-height:1.5;">Use this Real ID to login at <span style="font-family:monospace;">/admin/login.php</span> after setting password.</p>
                </div>
                <p id="quoteSuccessMsg" style="font-size:14px;color:var(--text-muted);margin-bottom:24px">Thank you for choosing Optibiz. Our team will review your requirements and contact you within 24 hours. You will receive emails with your Real IDs and next steps.</p>
                <div id="quoteEmailNotice" style="margin:0 auto 24px;max-width:460px;padding:12px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;font-size:13px;color:#166534;line-height:1.6;display:none;">
                    <i class="fa-solid fa-envelope" style="margin-right:6px;"></i> A confirmation email has been sent to your inbox with your Reference ID. You will also receive your <strong>Real Account ID</strong> (e.g. OPT-XXXXXX) and a secure link to set up your password.
                </div>
                <div id="quoteTenantEmailNotice" style="margin:0 auto 24px;max-width:460px;padding:12px 14px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:10px;font-size:13px;color:#4338ca;line-height:1.6;display:none;">
                    <i class="fa-solid fa-key" style="margin-right:6px;"></i> Your tenant account <strong id="quoteTenantIdInline" style="font-family:monospace;">OPT-XXXXXXXX</strong> has been created! Check your email for a secure link to set your password (valid 48h). Then login at <strong>/admin/login.php</strong> using your Real ID, username or email.
                </div>
                <button type="button" class="btn-lime" id="quoteDoneBtn">Done</button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
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

    // Modal logic & preselection
    var openModal;
    (function () {
        'use strict';
        var modal       = document.getElementById('quoteModal');
        var closeBtn    = document.getElementById('quoteModalClose');
        var formWrap    = document.getElementById('quoteFormWrap');
        var successView = document.getElementById('quoteSuccess');
        var form        = document.getElementById('quoteForm');
        var sections    = form.querySelectorAll('.form-section');
        var stepWraps   = document.querySelectorAll('#quoteProgress .step-wrap');
        var stepLines   = document.querySelectorAll('#quoteProgress .step-line');
        var backBtn     = document.getElementById('quoteBackBtn');
        var nextBtn     = document.getElementById('quoteNextBtn');
        var submitBtn   = document.getElementById('quoteSubmitBtn');
        var alertBox    = document.getElementById('quoteAlert');

        var TOTAL_STEPS = sections.length;
        var currentStep = 1;
        var submitting  = false;

        function hideAlert() {
            alertBox.hidden = true;
            alertBox.textContent = '';
        }
        function showAlert(message) {
            alertBox.textContent = message;
            alertBox.hidden = false;
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
                            emailNotice.innerHTML = '<i class="fa-solid fa-envelope" style="margin-right:6px;"></i> Your Reference ID is <strong style="font-family:monospace;">' + realId + '</strong>. You will receive an email with your <strong>Real Account ID</strong> (e.g. OPT-XXXXXX) and a secure link to set up your password at /admin/login.php';
                        }
                    }
                    if (tenantEmailNotice) {
                        if (tenantRealId && data.tenant_setup_email_sent) {
                            tenantEmailNotice.style.display = 'block';
                        } else if (tenantRealId) {
                            tenantEmailNotice.style.display = 'block';
                            tenantEmailNotice.innerHTML = '<i class="fa-solid fa-envelope" style="margin-right:6px;"></i> Your Real Account ID is <strong style="font-family:monospace;">' + tenantRealId + '</strong>. If email delivery fails, contact support or use <a href="admin/forgot-password.php" style="color:#4338ca;font-weight:700;">Forgot Password</a> to resend setup link.';
                        } else {
                            tenantEmailNotice.style.display = 'none';
                        }
                    }
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
