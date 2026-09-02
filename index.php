<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$total_ratings = $conn->query("SELECT COUNT(*) as count FROM ratings")->fetch_assoc()['count'];
$total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'];
$avg_platform_rating = $conn->query("SELECT AVG(rating) as avg FROM ratings")->fetch_assoc()['avg'] ?? 4.5;

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
    <title>Optibiz - Company Rating Platform</title>
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
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #1e293b;
            overflow-x: hidden;
        }

        /* Header & Navigation */
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
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            padding: 50px;
        }
        .modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            border: none;
            background: #f1f5f9;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            color: #64748b;
            transition: background 0.3s;
        }
        .modal-close:hover {
            background: #e2e8f0;
        }
        .modal-header h2 {
            font-size: 32px;
            color: #1e293b;
            margin-bottom: 10px;
        }
        .modal-header p {
            color: #64748b;
            font-size: 15px;
            margin-bottom: 40px;
        }
        .progress-steps {
            display: flex;
            align-items: center;
            margin-bottom: 50px;
        }
        .step {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
            position: relative;
        }
        .step.active {
            background: var(--accent-lime);
            color: var(--primary-dark);
        }
        .step.completed {
            background: #a3e635;
            color: #1e293b;
        }
        .step-line {
            flex: 1;
            height: 3px;
            background: #e2e8f0;
            margin: 0 10px;
        }
        .step-line.active {
            background: var(--accent-lime);
        }
        .form-section {
            display: none;
        }
        .form-section.active {
            display: block;
        }
        .form-section h3 {
            font-size: 24px;
            color: #1e293b;
            margin-bottom: 10px;
        }
        .form-section .subtitle {
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 25px;
        }
        .form-group label {
            display: block;
            color: #475569;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }
        .form-group label .required {
            color: #ef4444;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            color: #1e293b;
            transition: border-color 0.3s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--accent-lime);
            outline: none;
        }

        /* Get Started Quote Wizard */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 20px;
        }
        .progress-steps {
            align-items: flex-start;
        }
        .step-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        .step-label {
            font-size: 12px;
            font-weight: 600;
            color: #94a3b8;
            white-space: nowrap;
            transition: color 0.3s;
        }
        .step-wrap.active .step-label,
        .step-wrap.completed .step-label {
            color: var(--accent-green-text);
        }
        .step-line {
            margin-top: 24px;
        }
        .form-group input.invalid,
        .form-group select.invalid,
        .form-group textarea.invalid {
            border-color: #ef4444;
            background: #fef2f2;
        }
        .form-alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            line-height: 1.5;
        }
        .form-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin-top: 10px;
        }
        .form-nav .btn-lime {
            border: none;
            cursor: pointer;
            font-family: inherit;
        }
        .form-nav .btn-lime:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            box-shadow: none;
        }
        .btn-back {
            border: none;
            background: transparent;
            color: #64748b;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            padding: 13px 22px;
            border-radius: 40px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
        }
        .btn-back:hover {
            background: #f1f5f9;
            color: #1e293b;
        }
        .quote-success {
            text-align: center;
            padding: 40px 10px 20px;
        }
        .quote-success-icon {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            background: var(--accent-green-bg);
            color: var(--accent-green-text);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin: 0 auto 26px;
        }
        .quote-success h2 {
            font-size: 28px;
            color: #1e293b;
            margin-bottom: 12px;
        }
        .quote-success p {
            color: #64748b;
            font-size: 15px;
            line-height: 1.7;
            max-width: 460px;
            margin: 0 auto 32px;
        }
        .quote-success .btn-lime {
            border: none;
            cursor: pointer;
            font-family: inherit;
        }
        @media (max-width: 640px) {
            .modal-content {
                padding: 34px 22px;
            }
            .modal-header h2 {
                font-size: 24px;
            }
            .modal-header p {
                margin-bottom: 30px;
            }
            .progress-steps {
                margin-bottom: 34px;
            }
            .step {
                width: 40px;
                height: 40px;
                font-size: 15px;
            }
            .step-line {
                margin-top: 19px;
            }
            .step-label {
                font-size: 10px;
            }
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .form-nav {
                flex-wrap: wrap;
            }
            .form-nav .btn-lime {
                flex: 1;
                justify-content: center;
            }
        }

        /* Hero Section */
        .hero-section {
            background: radial-gradient(circle at 80% 20%, #173854 0%, var(--primary-dark) 70%);
            padding: 60px 5% 100px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
        }

        .hero-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 60px;
            align-items: center;
        }

        .badge-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(194, 245, 66, 0.12);
            color: var(--accent-lime);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 24px;
            border: 1px solid rgba(194, 245, 66, 0.25);
        }

        .hero-title {
            font-size: 58px;
            font-weight: 800;
            line-height: 1.12;
            letter-spacing: -1.5px;
            margin-bottom: 24px;
            color: #ffffff;
        }

        .hero-desc {
            color: #cbd5e1;
            font-size: 16px;
            line-height: 1.7;
            max-width: 540px;
            margin-bottom: 36px;
        }

        .hero-actions {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 48px;
        }

        .btn-lime {
            background: var(--accent-lime);
            color: var(--primary-dark);
            text-decoration: none;
            padding: 14px 30px;
            border-radius: 40px;
            font-weight: 700;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 20px rgba(194, 245, 66, 0.25);
        }

        .btn-lime:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(194, 245, 66, 0.35);
        }

        .btn-play {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #ffffff;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.25s ease;
        }

        .btn-play:hover {
            transform: scale(1.1);
            background: var(--accent-lime);
        }

        .hero-proof {
            display: flex;
            align-items: center;
            gap: 50px;
            padding-top: 10px;
        }

        .rating-block {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .stars-row {
            color: #f59e0b;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .stars-row span {
            color: #94a3b8;
            font-size: 12px;
            margin-left: 4px;
            font-weight: 600;
        }

        .rating-number-wrap {
            display: flex;
            align-items: baseline;
            gap: 12px;
        }

        .big-rating {
            font-size: 32px;
            font-weight: 800;
            color: #ffffff;
        }

        .rating-text {
            font-size: 12px;
            color: #cbd5e1;
            line-height: 1.35;
        }

        .team-block {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .team-title {
            font-size: 13px;
            color: #cbd5e1;
            font-weight: 600;
        }

        .avatar-group {
            display: flex;
            align-items: center;
        }

        .avatar-group img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 2.5px solid var(--primary-dark);
            margin-left: -10px;
            object-fit: cover;
        }

        .avatar-group img:first-child {
            margin-left: 0;
        }

        .avatar-plus {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--accent-lime);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            margin-left: -10px;
            border: 2.5px solid var(--primary-dark);
        }

        /* Hero Right / Phone Graphics */
        .hero-graphic-wrap {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .hero-phones-img {
            width: 100%;
            max-width: 480px;
            height: auto;
            border-radius: 28px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.45);
            display: block;
            position: relative;
            z-index: 2;
        }

        .floating-badge-top {
            position: absolute;
            top: 25px;
            left: -20px;
            background: rgba(15, 36, 56, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 12px 18px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 3;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            max-width: 250px;
        }

        .floating-badge-top i {
            color: var(--accent-lime);
            font-size: 16px;
            flex-shrink: 0;
        }

        .floating-badge-top p {
            font-size: 11px;
            line-height: 1.35;
            color: #f1f5f9;
            font-weight: 600;
        }

        .floating-badge-bottom {
            position: absolute;
            bottom: -20px;
            right: -10px;
            background: var(--accent-lime);
            color: var(--primary-dark);
            padding: 16px 24px;
            border-radius: 18px;
            z-index: 3;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
            text-align: left;
        }

        .floating-badge-bottom h4 {
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 4px;
        }

        .floating-badge-bottom p {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Feature Cards Strip */
        .feature-strip-section {
            background: #ffffff;
            padding: 0 5% 100px;
            margin-top: -50px;
            position: relative;
            z-index: 10;
        }

        .feature-strip-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 2.5fr;
            gap: 40px;
        }

        .how-card {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            height: 180px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.08);
            background: #1e293b;
            display: flex;
            align-items: flex-end;
            padding: 20px;
            text-decoration: none;
        }

        .how-card img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }

        .how-card:hover img {
            transform: scale(1.05);
        }

        .how-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(0,0,0,0.1) 0%, rgba(10,25,38,0.85) 100%);
        }

        .how-card-content {
            position: relative;
            z-index: 2;
            color: #ffffff;
        }

        .how-card-content h3 {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .how-card-content span {
            font-size: 12px;
            color: var(--accent-lime);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .consulting-cards-grid {
            background: var(--card-dark);
            border-radius: 20px;
            padding: 32px 40px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 50px;
            align-items: center;
        }

        .c-card {
            color: #ffffff;
        }

        .c-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--accent-lime);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            margin-bottom: 12px;
        }

        .c-card h4 {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #ffffff;
        }

        .c-card p {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.5;
        }

        /* About Section */
        .about-section {
            background-color: #ffffff;
            padding: 60px 5% 80px;
        }

        .about-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1.15fr;
            gap: 60px;
            align-items: center;
        }

        .about-image-wrap {
            position: relative;
        }

        .about-main-img {
            width: 100%;
            height: 420px;
            object-fit: cover;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            display: block;
        }

        .tag-pill {
            display: inline-block;
            background: var(--accent-green-bg);
            color: var(--accent-green-text);
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .about-title {
            font-size: 40px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
            letter-spacing: -1px;
            margin-bottom: 30px;
        }

        .mission-vision-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 36px;
        }

        .mv-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .mv-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--accent-green-bg);
            color: var(--accent-green-text);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .mv-content h4 {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .mv-content p {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .join-cta-bar {
            background: var(--primary-dark);
            border-radius: 40px;
            padding: 12px 20px 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .join-text-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #ffffff;
            font-size: 13px;
            font-weight: 500;
        }

        .join-text-wrap i {
            color: var(--accent-lime);
            font-size: 16px;
        }

        .btn-learn-pill {
            background: var(--accent-lime);
            color: var(--primary-dark);
            text-decoration: none;
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .btn-learn-pill:hover {
            background: var(--accent-lime-hover);
        }

        /* Stats Section */
        .stats-section {
            background-color: #ffffff;
            padding: 40px 5% 80px;
            border-bottom: 1px solid #f1f5f9;
        }

        .stats-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 40px;
        }

        .stat-card {
            position: relative;
            padding-top: 20px;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background-color: var(--accent-lime);
            border-radius: 2px;
        }

        .stat-number {
            font-size: 44px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            margin-bottom: 8px;
            letter-spacing: -1px;
        }

        .stat-number span {
            color: #84cc16;
            font-weight: 700;
            font-size: 34px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* Services Grid Section */
        .services-section {
            background: #ffffff;
            padding: 60px 5% 100px;
        }

        .services-container {
            max-width: 1280px;
            margin: 0 auto;
        }

        .services-header {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 40px;
            align-items: flex-end;
            margin-bottom: 50px;
        }

        .services-title {
            font-size: 42px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
            letter-spacing: -1px;
        }

        .services-header-right p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .services-cards-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
        }

        .service-photo-card {
            position: relative;
            height: 420px;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
            text-decoration: none;
            display: block;
        }

        .service-photo-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .service-photo-card:hover img {
            transform: scale(1.06);
        }

        .service-photo-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(0,0,0,0) 40%, rgba(10,25,38,0.85) 100%);
        }

        .service-card-bottom {
            position: absolute;
            bottom: 24px;
            left: 24px;
            right: 24px;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(15, 36, 56, 0.85);
            backdrop-filter: blur(10px);
            padding: 12px 18px;
            border-radius: 30px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.25s ease;
        }

        .service-photo-card:hover .service-card-bottom {
            background: var(--primary-dark);
            border-color: var(--accent-lime);
        }

        .service-badge-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .service-badge-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--accent-lime);
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .service-badge-left span {
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
        }

        .service-arrow {
            color: var(--accent-lime);
            font-size: 14px;
            transform: rotate(-45deg);
            transition: transform 0.25s ease;
        }

        .service-photo-card:hover .service-arrow {
            transform: rotate(0deg);
        }

        /* ================= FOOTER SECTION ================= */
        .site-footer {
            background-color: var(--primary-dark);
            color: #ffffff;
            position: relative;
            padding-top: 70px;
            overflow: hidden;
        }

        /* Subtle background glow element */
        .site-footer::before {
            content: '';
            position: absolute;
            top: -150px;
            right: -100px;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(194, 245, 66, 0.08) 0%, rgba(10, 25, 38, 0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .footer-cta-card {
            max-width: 1280px;
            margin: 0 auto 60px;
            padding: 44px 50px;
            background: linear-gradient(135deg, var(--card-dark) 0%, #1a3852 100%);
            border-radius: 28px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }

        .cta-content h3 {
            font-size: 28px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .cta-content p {
            color: #94a3b8;
            font-size: 15px;
            max-width: 520px;
        }

        .newsletter-form {
            display: flex;
            align-items: center;
            background: rgba(10, 25, 38, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 40px;
            padding: 6px 6px 6px 20px;
            width: 100%;
            max-width: 420px;
        }

        .newsletter-form input {
            background: transparent;
            border: none;
            outline: none;
            color: #ffffff;
            font-size: 14px;
            width: 100%;
            font-family: inherit;
        }

        .newsletter-form input::placeholder {
            color: #64748b;
        }

        .newsletter-btn {
            background: var(--accent-lime);
            color: var(--primary-dark);
            border: none;
            border-radius: 30px;
            padding: 12px 24px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.25s ease;
            font-family: inherit;
        }

        .newsletter-btn:hover {
            background: var(--accent-lime-hover);
            transform: translateY(-1px);
        }

        /* Main Footer Grid */
        .footer-grid-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 5% 60px;
            display: grid;
            grid-template-columns: 1.4fr 1fr 1fr 1.2fr;
            gap: 50px;
        }

        .footer-col-brand .logo {
            margin-bottom: 20px;
        }

        .footer-brand-desc {
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 24px;
            max-width: 320px;
        }

        .footer-socials {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .social-link {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            color: #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 14px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.25s ease;
        }

        .social-link:hover {
            background: var(--accent-lime);
            color: var(--primary-dark);
            border-color: var(--accent-lime);
            transform: translateY(-3px);
        }

        .footer-col-title {
            font-size: 17px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 22px;
            position: relative;
            padding-bottom: 10px;
        }

        .footer-col-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 25px;
            height: 2px;
            background: var(--accent-lime);
            border-radius: 2px;
        }

        .footer-links-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .footer-links-list a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .footer-links-list a:hover {
            color: var(--accent-lime);
            transform: translateX(4px);
        }

        .footer-contact-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .contact-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.5;
        }

        .contact-item i {
            color: var(--accent-lime);
            font-size: 15px;
            margin-top: 3px;
            flex-shrink: 0;
        }

        /* Footer Bottom Bar */
        .footer-bottom {
            background: #06111a;
            padding: 24px 5%;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .footer-bottom-container {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 13px;
            color: #64748b;
        }

        .footer-bottom-links {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .footer-bottom-links a {
            color: #64748b;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .footer-bottom-links a:hover {
            color: var(--accent-lime);
        }

        .scroll-top-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 12px;
            transition: all 0.25s ease;
        }

        .scroll-top-btn:hover {
            background: var(--accent-lime);
            color: var(--primary-dark);
            transform: translateY(-3px);
        }

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            .hero-container {
                grid-template-columns: 1fr;
                gap: 50px;
            }
            .hero-title {
                font-size: 46px;
            }
            .feature-strip-container {
                grid-template-columns: 1fr;
            }
            .about-container {
                grid-template-columns: 1fr;
            }
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 30px;
            }
            .services-header {
                grid-template-columns: 1fr;
            }
            .services-cards-row {
                grid-template-columns: 1fr;
            }
            .footer-grid-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 40px;
            }
            .footer-cta-card {
                flex-direction: column;
                align-items: flex-start;
                padding: 34px 30px;
            }
            .newsletter-form {
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .nav-pill {
                display: none;
            }
            .hero-title {
                font-size: 36px;
            }
            .consulting-cards-grid {
                grid-template-columns: 1fr;
            }
            .mission-vision-grid {
                grid-template-columns: 1fr;
            }
            .join-cta-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            .floating-badge-top {
                left: 10px;
            }
            .floating-badge-bottom {
                right: 10px;
            }
            .footer-grid-container {
                grid-template-columns: 1fr;
                gap: 36px;
            }
            .footer-bottom-container {
                flex-direction: column;
                text-align: center;
                gap: 16px;
            }
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
                <a href="companies.php">Companies</a>
                <a href="#about">About Us</a>
                <a href="#services">Services</a>
                <a href="#contact">Contact</a>
            </nav>
            <a href="#get-started" class="btn-quote" id="getStartedBtn" aria-haspopup="dialog">
                Get Started <i class="fa-solid fa-arrow-right"></i>
            </a>
        </header>
    </div>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-container">
            <div class="hero-left">
                <div class="badge-tag">
                    <i class="fa-solid fa-circle-check"></i> Welcome To Optibiz
                </div>
                <h1 class="hero-title">Where The Expertise Creates Excellence</h1>
                <p class="hero-desc">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.</p>
                
                <div class="hero-actions">
                    <a href="companies.php" class="btn-lime">
                        Browse Companies <i class="fa-solid fa-arrow-right"></i>
                    </a>
                    <a href="#about" class="btn-play">
                        <i class="fa-solid fa-play"></i>
                    </a>
                </div>

                <div class="hero-proof">
                    <div class="rating-block">
                        <div class="stars-row">
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star-half-stroke"></i>
                            <span>(4.5/5)</span>
                        </div>
                        <div class="rating-number-wrap">
                            <div class="big-rating"><?php echo number_format($avg_platform_rating ?? 4.5, 1); ?></div>
                            <div class="rating-text">Positive Reviews From<br>Our Customer</div>
                        </div>
                    </div>

                    <div class="team-block">
                        <div class="team-title">Join Our Team Now:</div>
                        <div class="avatar-group">
                            <img src="assets/images/avatar1.jpg" alt="Team Member 1">
                            <img src="assets/images/avatar2.jpg" alt="Team Member 2">
                            <img src="assets/images/avatar3.jpg" alt="Team Member 3">
                            <div class="avatar-plus"><i class="fa-solid fa-plus"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hero-graphic-wrap">
                <div class="floating-badge-top">
                    <i class="fa-solid fa-circle-check"></i>
                    <p>Guiding Financial Journey To Elevating Your Business Destiny</p>
                </div>
                
                <img src="assets/images/hero_phones.jpg" alt="Optibiz Mobile Application" class="hero-phones-img">
                
                <div class="floating-badge-bottom">
                    <h4>25+</h4>
                    <p>Years Of Experience</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Features & Services Strip -->
    <section class="feature-strip-section">
        <div class="feature-strip-container">
            <a href="#about" class="how-card">
                <img src="assets/images/how_it_works.jpg" alt="How Does It Work">
                <div class="how-card-content">
                    <h3>How Does It Work?</h3>
                    <span>Learn More <i class="fa-solid fa-arrow-right"></i></span>
                </div>
            </a>

            <div class="consulting-cards-grid">
                <div class="c-card">
                    <div class="c-icon">
                        <i class="fa-solid fa-briefcase"></i>
                    </div>
                    <h4>Operational Consulting</h4>
                    <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit Ut.</p>
                </div>

                <div class="c-card">
                    <div class="c-icon">
                        <i class="fa-solid fa-compass"></i>
                    </div>
                    <h4>Strategy Consulting</h4>
                    <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit Ut.</p>
                </div>

                <div class="c-card">
                    <div class="c-icon">
                        <i class="fa-solid fa-dollar-sign"></i>
                    </div>
                    <h4>Financial Consulting</h4>
                    <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit Ut.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="about-section" id="about">
        <div class="about-container">
            <div class="about-image-wrap">
                <img src="assets/images/about_consultants.jpg" alt="Financial Consultants Team" class="about-main-img">
            </div>

            <div class="about-text-wrap">
                <div class="tag-pill">About Us</div>
                <h2 class="about-title">The Best Finance Consultant In Town</h2>

                <div class="mission-vision-grid">
                    <div class="mv-item">
                        <div class="mv-icon">
                            <i class="fa-solid fa-bullseye"></i>
                        </div>
                        <div class="mv-content">
                            <h4>Company Vission</h4>
                            <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.</p>
                        </div>
                    </div>

                    <div class="mv-item">
                        <div class="mv-icon">
                            <i class="fa-solid fa-lightbulb"></i>
                        </div>
                        <div class="mv-content">
                            <h4>Company Mission</h4>
                            <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.</p>
                        </div>
                    </div>
                </div>

                <div class="join-cta-bar">
                    <div class="join-text-wrap">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Join us to achieve sustainable growth and reach your financial goals with the right strategies.</span>
                    </div>
                    <a href="admin/login.php" class="btn-learn-pill">
                        Learn More <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section" id="stats">
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number">25<span>+</span></div>
                <div class="stat-label">A legacy of expertise spanning 24+ years.</div>
            </div>

            <div class="stat-card">
                <div class="stat-number">150K<span>+</span></div>
                <div class="stat-label">Where ideas flourish and projects thrive.</div>
            </div>

            <div class="stat-card">
                <div class="stat-number">98<span>%</span></div>
                <div class="stat-label">Striving for customer satisfaction is top priority.</div>
            </div>

            <div class="stat-card">
                <div class="stat-number">$40M<span>+</span></div>
                <div class="stat-label">This is our pure benefit to our clients</div>
            </div>
        </div>
    </section>

    <!-- Services Grid Section -->
    <section class="services-section" id="services">
        <div class="services-container">
            <div class="services-header">
                <div>
                    <div class="tag-pill">Our Services</div>
                    <h2 class="services-title">Financial Services To Grow And Secure Your Wealth</h2>
                </div>
                <div class="services-header-right">
                    <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvina.</p>
                    <a href="admin/login.php" class="btn-learn-pill">
                        Learn More <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="services-cards-row">
                <!-- Card 1: Business Strategy -->
                <a href="admin/login.php" class="service-photo-card">
                    <img src="assets/images/service_strategy.jpg" alt="Business Strategy">
                    <div class="service-card-bottom">
                        <div class="service-badge-left">
                            <div class="service-badge-icon">
                                <i class="fa-solid fa-users"></i>
                            </div>
                            <span>Business Strategy</span>
                        </div>
                        <i class="fa-solid fa-arrow-right service-arrow"></i>
                    </div>
                </a>

                <!-- Card 2: Taxes & Accounting -->
                <a href="admin/login.php" class="service-photo-card">
                    <img src="assets/images/service_taxes.jpg" alt="Taxes & Accounting">
                    <div class="service-card-bottom">
                        <div class="service-badge-left">
                            <div class="service-badge-icon">
                                <i class="fa-solid fa-calculator"></i>
                            </div>
                            <span>Taxes & Accounting</span>
                        </div>
                        <i class="fa-solid fa-arrow-right service-arrow"></i>
                    </div>
                </a>

                <!-- Card 3: Financial Planning -->
                <a href="admin/login.php" class="service-photo-card">
                    <img src="assets/images/service_planning.jpg" alt="Financial Planning">
                    <div class="service-card-bottom">
                        <div class="service-badge-left">
                            <div class="service-badge-icon">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                            <span>Financial Planning</span>
                        </div>
                        <i class="fa-solid fa-arrow-right service-arrow"></i>
                    </div>
                </a>
            </div>
        </div>
    </section>

    <!-- ================= FOOTER ================= -->
    <footer class="site-footer" id="contact">
        <!-- Newsletter / CTA Card -->
        <div class="footer-cta-card">
            <div class="cta-content">
                <h3>Ready to Elevate Your Financial Growth?</h3>
                <p>Subscribe to our newsletter to receive the latest market insights, consulting strategies, and updates directly in your inbox.</p>
            </div>
            <form class="newsletter-form" onsubmit="event.preventDefault(); alert('Thank you for subscribing to Optibiz updates!');">
                <input type="email" placeholder="Enter your business email..." required>
                <button type="submit" class="newsletter-btn">
                    Subscribe <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>
        </div>

        <!-- Main Footer Links Grid -->
        <div class="footer-grid-container">
            <!-- Brand Info -->
            <div class="footer-col-brand">
                <a href="index.php" class="logo">
                    <span class="logo-icon"><i class="fa-solid fa-shapes"></i></span>
                    Optibiz
                </a>
                <p class="footer-brand-desc">
                    Empowering businesses and investors with world-class financial consulting, data analytics, and performance ratings to build long-term excellence.
                </p>
                <div class="footer-socials">
                    <a href="#" class="social-link" title="Twitter / X"><i class="fa-brands fa-x-twitter"></i></a>
                    <a href="#" class="social-link" title="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
                    <a href="#" class="social-link" title="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#" class="social-link" title="Instagram"><i class="fa-brands fa-instagram"></i></a>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="footer-col">
                <h4 class="footer-col-title">Quick Links</h4>
                <ul class="footer-links-list">
                    <li><a href="#top"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> Home</a></li>
                    <li><a href="#about"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> About Us</a></li>
                    <li><a href="#services"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> Our Services</a></li>
                    <li><a href="#stats"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> Key Numbers</a></li>
                    <li><a href="admin/login.php"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> Portal Login</a></li>
                </ul>
            </div>

            <!-- Our Services -->
            <div class="footer-col">
                <h4 class="footer-col-title">Our Services</h4>
                <ul class="footer-links-list">
                    <li><a href="admin/login.php"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> Business Strategy</a></li>
                    <li><a href="admin/login.php"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> Taxes & Accounting</a></li>
                    <li><a href="admin/login.php"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> Financial Planning</a></li>
                    <li><a href="admin/login.php"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> Operational Consulting</a></li>
                    <li><a href="admin/login.php"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i> Risk & Compliance</a></li>
                </ul>
            </div>

            <!-- Contact & Office -->
            <div class="footer-col">
                <h4 class="footer-col-title">Get In Touch</h4>
                <ul class="footer-contact-list">
                    <li class="contact-item">
                        <i class="fa-solid fa-location-dot"></i>
                        <span>1247 Financial District, Suite 500, New York, NY 10005</span>
                    </li>
                    <li class="contact-item">
                        <i class="fa-solid fa-phone"></i>
                        <span>+1 (800) 555-0199</span>
                    </li>
                    <li class="contact-item">
                        <i class="fa-solid fa-envelope"></i>
                        <span>contact@optibiz.com</span>
                    </li>
                    <li class="contact-item">
                        <i class="fa-solid fa-clock"></i>
                        <span>Mon - Fri: 9:00 AM - 6:00 PM</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Copyright & Legal -->
        <div class="footer-bottom">
            <div class="footer-bottom-container">
                <div>
                    &copy; <?php echo date('Y'); ?> <strong>Optibiz</strong>. All rights reserved.
                </div>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <a href="#">Terms of Service</a>
                    <a href="#">Security</a>
                    <a href="#">Cookie Settings</a>
                </div>
                <div>
                    <a href="#top" class="scroll-top-btn" title="Back to top">
                        <i class="fa-solid fa-arrow-up"></i>
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <!-- ================= GET STARTED / QUOTE MODAL ================= -->
    <div class="modal-overlay" id="quoteModal" role="dialog" aria-modal="true" aria-labelledby="quoteModalTitle">
        <div class="modal-content">

            <button type="button" class="modal-close" id="quoteModalClose" aria-label="Close dialog">&times;</button>

            <!-- Multi-step form view -->
            <div id="quoteFormWrap">
                <div class="modal-header">
                    <h2 id="quoteModalTitle">Get Started With Optibiz</h2>
                    <p>Tell us about your company and our team will get back to you within 24 hours with a tailored onboarding plan.</p>
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
                                <label for="q_company_name">Company Name <span class="required">*</span></label>
                                <input type="text" id="q_company_name" name="company_name" placeholder="e.g. ABC Corporation" required>
                            </div>
                            <div class="form-group">
                                <label for="q_contact_person">Contact Person <span class="required">*</span></label>
                                <input type="text" id="q_contact_person" name="contact_person" placeholder="Your full name" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="q_category">Industry / Category <span class="required">*</span></label>
                                <select id="q_category" name="category" required>
                                    <option value="" disabled selected>Select a category</option>
                                    <?php foreach ($modal_categories as $cat): ?>
                                    <option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="q_location">Location</label>
                                <input type="text" id="q_location" name="location" placeholder="City, Country">
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Contact Details -->
                    <div class="form-section">
                        <h3>Contact Details</h3>
                        <p class="subtitle">How can our team reach you with your quote?</p>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="q_email">Work Email <span class="required">*</span></label>
                                <input type="email" id="q_email" name="email" placeholder="you@company.com" required>
                            </div>
                            <div class="form-group">
                                <label for="q_phone">Phone Number <span class="required">*</span></label>
                                <input type="tel" id="q_phone" name="phone" placeholder="+1 (800) 555-0199" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="q_website">Company Website</label>
                            <input type="text" id="q_website" name="website" placeholder="www.company.com">
                        </div>
                    </div>

                    <!-- Step 3: Plan & Requirements -->
                    <div class="form-section">
                        <h3>Plan &amp; Requirements</h3>
                        <p class="subtitle">Pick a starting plan and tell us about the scale of your rollout.</p>

                        <div class="form-group">
                            <label for="q_plan">Subscription Plan <span class="required">*</span></label>
                            <select id="q_plan" name="plan_id" required>
                                <option value="" disabled selected>Select a plan</option>
                                <?php foreach ($modal_plans as $plan): ?>
                                <option value="<?php echo (int)$plan['id']; ?>">
                                    <?php echo htmlspecialchars($plan['plan_name']); ?> &mdash; $<?php echo number_format((float)$plan['price'], 2); ?>/month
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="q_num_companies">Companies To Manage</label>
                                <input type="number" id="q_num_companies" name="num_companies" min="1" value="1">
                            </div>
                            <div class="form-group">
                                <label for="q_expected_ratings">Expected Ratings / Month</label>
                                <input type="number" id="q_expected_ratings" name="expected_ratings" min="1" value="100">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="q_notes">Additional Notes</label>
                            <textarea id="q_notes" name="notes" rows="4" placeholder="Anything else we should know about your requirements?"></textarea>
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
                            Submit Request <i class="fa-solid fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Success view -->
            <div class="quote-success" id="quoteSuccess" hidden>
                <div class="quote-success-icon">
                    <i class="fa-solid fa-check"></i>
                </div>
                <h2>Request Received!</h2>
                <p>Thank you for choosing Optibiz. Our team will review your requirements and contact you within 24 hours with your tailored quote and onboarding details.</p>
                <button type="button" class="btn-lime" id="quoteDoneBtn">Done</button>
            </div>

        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var modal       = document.getElementById('quoteModal');
        var openBtn     = document.getElementById('getStartedBtn');
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

        function openModal() {
            currentStep = 1;
            form.reset();
            renderStep();
            clearInvalidMarks(form);
            hideAlert();
            formWrap.hidden = false;
            successView.hidden = true;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            var firstInput = form.querySelector('.form-section.active input, .form-section.active select');
            if (firstInput) { firstInput.focus(); }
        }

        function closeModal() {
            modal.classList.remove('active');
            document.body.style.overflow = '';
            if (openBtn) { openBtn.focus(); }
        }

        function submitForm() {
            if (submitting) { return; }
            if (!validateStep(currentStep)) { return; }

            submitting = true;
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Submitting <i class="fa-solid fa-circle-notch fa-spin"></i>';
            hideAlert();

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    formWrap.hidden = true;
                    successView.hidden = false;
                } else {
                    showAlert(data.message || 'Something went wrong. Please try again.');
                }
            })
            .catch(function () {
                showAlert('Could not reach the server. Please check your connection and try again.');
            })
            .finally(function () {
                submitting = false;
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Submit Request <i class="fa-solid fa-paper-plane"></i>';
            });
        }

        if (openBtn) {
            openBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openModal();
            });
        }

        closeBtn.addEventListener('click', closeModal);
        document.getElementById('quoteDoneBtn').addEventListener('click', closeModal);

        modal.addEventListener('click', function (e) {
            if (e.target === modal) { closeModal(); }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
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
            submitForm();
        });

        // Clear the red highlight as soon as a field is fixed
        form.addEventListener('input', function (e) {
            if (e.target.classList.contains('invalid') && e.target.checkValidity()) {
                e.target.classList.remove('invalid');
            }
        });
    })();
    </script>

</body>
</html>
