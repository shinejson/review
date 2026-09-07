<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$currency_symbol = '$';
$currency_position = 'before';
$settings = [];
$r = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'currency%'");
if ($r) { while ($row = $r->fetch_assoc()) { $settings[$row['setting_key']] = $row['setting_value'] ?? ''; } }
if (!empty($settings['currency_symbol'])) $currency_symbol = $settings['currency_symbol'];
if (!empty($settings['currency_position'])) $currency_position = $settings['currency_position'];

function fmt_price($amount, $symbol, $position) {
    $f = number_format($amount, 2);
    return $position === 'after' ? $f . $symbol : $symbol . $f;
}

$plans = [];
$r = $conn->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price ASC");
if ($r) { while ($row = $r->fetch_assoc()) { $plans[] = $row; } }
$total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'] ?? 0;
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pricing - Optibiz</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--navy:#0f2438;--lime:#c2f542;--muted:#64748b}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#1e293b;background:#f8fafc;overflow-x:hidden}
.top-bar-wrap{background:var(--navy);position:sticky;top:0;z-index:100;box-shadow:0 4px 20px rgba(0,0,0,.15)}
.navbar{max-width:1280px;margin:0 auto;padding:18px 5%;display:flex;align-items:center;justify-content:space-between;gap:30px}
.logo{display:inline-flex;align-items:center;gap:10px;color:#fff;font-size:24px;font-weight:800;text-decoration:none}
.logo-icon{width:38px;height:38px;border-radius:10px;background:var(--lime);color:var(--navy);display:flex;align-items:center;justify-content:center}
.nav-pill{display:flex;align-items:center;gap:32px}
.nav-pill a{color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;padding:6px 0}
.nav-pill a:hover{color:var(--lime)}
.nav-pill a.active{color:#fff;border-bottom:2px solid var(--lime)}
.btn-quote{background:var(--lime);color:var(--navy);text-decoration:none;padding:10px 22px;border-radius:30px;font-size:14px;font-weight:700}
.pricing-hero{background:radial-gradient(circle at 80% 20%,#173854 0%,var(--navy) 70%);padding:80px 5%;color:#fff;text-align:center}
.pricing-hero h1{font-size:48px;font-weight:800;margin-bottom:16px}
.pricing-hero p{font-size:17px;color:#cbd5e1;max-width:600px;margin:0 auto}
.pricing-section{padding:0 5% 100px;margin-top:-40px;position:relative;z-index:10}
.pricing-grid{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(3,1fr);gap:28px}
.pricing-card{background:#fff;border-radius:20px;padding:36px 32px;box-shadow:0 10px 40px rgba(15,23,42,.06);border:1px solid #e2e8f0;transition:all .3s;position:relative;display:flex;flex-direction:column}
.pricing-card:hover{transform:translateY(-8px)}
.pricing-card.featured{border-color:var(--lime)}
.pricing-card.featured::before{content:'Most Popular';position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--lime);color:var(--navy);padding:5px 18px;border-radius:20px;font-size:12px;font-weight:700}
.plan-name{font-size:22px;font-weight:700;color:var(--navy);margin-bottom:8px}
.plan-price{display:flex;align-items:baseline;gap:4px;margin-bottom:8px}
.plan-price .amount{font-size:44px;font-weight:800;color:var(--navy)}
.plan-price .period{font-size:15px;color:var(--muted)}
.plan-features{list-style:none;margin-bottom:28px;flex:1}
.plan-features li{display:flex;align-items:center;gap:10px;padding:12px 0;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9}
.plan-features li i{color:#10b981;font-size:13px}
.btn-plan{display:block;width:100%;padding:14px;border-radius:12px;font-size:15px;font-weight:700;text-align:center;text-decoration:none}
.btn-plan.primary{background:var(--lime);color:var(--navy)}
.btn-plan.secondary{background:#f1f5f9;color:var(--navy)}
.cta-section{background:var(--navy);padding:80px 5%;text-align:center}
.cta-content h2{font-size:32px;font-weight:800;color:#fff;margin-bottom:16px}
.cta-content p{color:#cbd5e1;margin-bottom:32px}
.btn-cta{display:inline-flex;align-items:center;gap:10px;background:var(--lime);color:var(--navy);padding:16px 36px;border-radius:40px;font-size:16px;font-weight:700;text-decoration:none}
.footer-simple{background:#06111a;padding:30px 5%;text-align:center;color:#64748b;font-size:13px}
@media(max-width:900px){.pricing-grid{grid-template-columns:1fr;max-width:400px}.nav-pill{display:none}}
</style></head><body>
<div class="top-bar-wrap"><header class="navbar">
<a href="index.php" class="logo"><span class="logo-icon"><i class="fa-solid fa-shapes"></i></span>Optibiz</a>
<nav class="nav-pill"><a href="index.php">Home</a><a href="companies.php">Companies</a><a href="pricing.php" class="active">Pricing</a><a href="index.php#about">About</a><a href="index.php#contact">Contact</a></nav>
<a href="index.php#get-started" class="btn-quote">Get Started <i class="fa-solid fa-arrow-right"></i></a>
</header></div>
<section class="pricing-hero"><h1>Simple, Transparent Pricing</h1><p>Choose the plan that fits your business. All plans include core review collection and analytics features.</p></section>
<section class="pricing-section"><div class="pricing-grid"><div class="pricing-card"><div class="plan-name">Starter</div><div class="plan-price"><span class="amount">$29.99</span><span class="period">/month</span></div><ul class="plan-features"><li><i class="fa-solid fa-check"></i> Up to <strong>100</strong> reviews/month</li><li><i class="fa-solid fa-check"></i> Up to <strong>10</strong> companies</li><li><i class="fa-solid fa-check"></i> Basic analytics, Email support, 10 customers, 100 ratings/month</li></ul><a href="index.php#get-started" class="btn-plan secondary">Get Started</a></div><div class="pricing-card featured"><div class="plan-name">Professional</div><div class="plan-price"><span class="amount">$79.99</span><span class="period">/month</span></div><ul class="plan-features"><li><i class="fa-solid fa-check"></i> Up to <strong>500</strong> reviews/month</li><li><i class="fa-solid fa-check"></i> Up to <strong>50</strong> companies</li><li><i class="fa-solid fa-check"></i> Advanced analytics, Priority support, 50 customers, 500 ratings/month, Custom branding</li></ul><a href="index.php#get-started" class="btn-plan primary">Get Started</a></div><div class="pricing-card"><div class="plan-name">Enterprise</div><div class="plan-price"><span class="amount">$199.99</span><span class="period">/month</span></div><ul class="plan-features"><li><i class="fa-solid fa-check"></i> Up to <strong>9,999</strong> reviews/month</li><li><i class="fa-solid fa-check"></i> Up to <strong>999</strong> companies</li><li><i class="fa-solid fa-check"></i> Full analytics suite, 24/7 support, Unlimited customers, Unlimited ratings, API access, White label</li></ul><a href="index.php#get-started" class="btn-plan secondary">Get Started</a></div></div></section>
<section class="cta-section"><div class="cta-content">
<h2>Ready to Get Started?</h2>
<p>Join businesses already collecting reviews with Optibiz.</p>
<a href="index.php#get-started" class="btn-cta">Start Free Trial <i class="fa-solid fa-arrow-right"></i></a>
</div></section>
<footer class="footer-simple"><p>&copy; 2026 <a href="index.php" style="color:var(--lime);text-decoration:none;">Optibiz</a> — All rights reserved.</p></footer>
</body></html>