<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Company list joined with tenant branding (logo + banner)
$customers = $conn->query("SELECT c.*, cat.name as category_name, t.company_name AS tenant_name, t.logo AS tenant_logo, t.banner AS tenant_banner
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