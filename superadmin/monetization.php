<?php
/**
 * Superadmin — Monetization Command Center
 * Overview + management for 4 revenue streams:
 * 1) Premium Company Profiles
 * 2) Embeddable Review Widgets (Pro/Agency)
 * 3) Sponsored & Featured Category Placements
 * 4) Bulk SMS Credits Packages
 */
require_once dirname(__DIR__).'/includes/auth.php';
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/functions.php';
require_once dirname(__DIR__).'/includes/sa_helpers.php';
require_once dirname(__DIR__).'/includes/monetization.php';
require_once dirname(__DIR__).'/includes/payments.php';
requireSuperAdminLogin(); require_sa_permission('finance');
monetization_ensure_schema($conn); pay_ensure_schema($conn);

$stats = monetization_stats($conn);
$sum = pay_summary($conn, 30);

if(($_SERVER['REQUEST_METHOD']??'')==='POST' && sa_csrf_ok()){
  $act=$_POST['action'] ?? '';
  if($act==='activate_premium'){
    $cid=(int)($_POST['company_id'] ?? 0);
    $subId=(int)($_POST['sub_id'] ?? 0);
    $ps=sa_one($conn,"SELECT * FROM premium_subscriptions WHERE id=".$subId." LIMIT 1",'premium_subscriptions');
    if($ps){
      $tier=$ps['tier']; 
      $months = ($ps['billing_cycle'] ?? 'monthly') === 'yearly' ? 12 : 1;
      $exp=date('Y-m-d H:i:s', strtotime('+'.$months.' months'));
      @$conn->query("UPDATE customers SET is_claimed=1, premium_tier='".$conn->real_escape_string($tier)."', premium_status='active', premium_expires_at='".$conn->real_escape_string($exp)."', remove_competitor_ads=1, verified_badge=1, premium_price='".(float)$ps['price']."' WHERE id=".(int)$ps['company_id']);
      @$conn->query("UPDATE premium_subscriptions SET status='active', starts_at=NOW(), expires_at='".$conn->real_escape_string($exp)."' WHERE id=".$subId);
      sa_flash('success','Premium activated for company #'.$ps['company_id'].' — '.$tier);
    }
    redirect('monetization.php');
  }
  if($act==='activate_sponsored'){
    $lid=(int)($_POST['listing_id'] ?? 0);
    $row=sa_one($conn,"SELECT * FROM sponsored_listings WHERE id=".$lid." LIMIT 1",'sponsored_listings');
    if($row){
      $start=date('Y-m-d H:i:s'); $end=date('Y-m-d H:i:s', strtotime('+'.(int)$row['duration_days'].' days'));
      @$conn->query("UPDATE sponsored_listings SET status='active', starts_at='".$conn->real_escape_string($start)."', ends_at='".$conn->real_escape_string($end)."' WHERE id=".$lid);
      sa_flash('success','Sponsored listing #'.$lid.' activated — runs until '.$end);
    }
    redirect('monetization.php');
  }
  if($act==='activate_widget'){
    $wid=(int)($_POST['widget_id'] ?? 0);
    $w=sa_one($conn,"SELECT * FROM widget_configs WHERE id=".$wid." LIMIT 1",'widget_configs');
    if($w){
      $exp=date('Y-m-d H:i:s', strtotime('+30 days'));
      @$conn->query("UPDATE widget_configs SET status='active', expires_at='".$conn->real_escape_string($exp)."' WHERE id=".$wid);
      sa_flash('success','Review widget #'.$wid.' activated until '.$exp);
    }
    redirect('monetization.php');
  }
  if($act==='credit_sms'){
    $tid=(int)($_POST['tenant_id'] ?? 0); $credits=max(1,(int)($_POST['credits'] ?? 0));
    $w=get_sms_wallet($conn,$tid);
    @$conn->query("INSERT INTO sms_credits_wallet (tenant_id,balance,total_purchased) VALUES (".$tid.",".$credits.",".$credits.") ON DUPLICATE KEY UPDATE balance=balance+".$credits.", total_purchased=total_purchased+".$credits.", last_topup_at=NOW()");
    sa_flash('success',$credits.' SMS credits added to tenant #'.$tid);
    redirect('monetization.php');
  }
}

$pending_premium = sa_query($conn,"SELECT ps.*, c.company_name, t.company_name as tenant_name FROM premium_subscriptions ps JOIN customers c ON c.id=ps.company_id JOIN tenants t ON t.id=ps.tenant_id WHERE ps.status='pending' ORDER BY ps.created_at DESC LIMIT 10",'premium_subscriptions');
$pending_spons = sa_query($conn,"SELECT s.*, c.company_name, cat.name as cat_name FROM sponsored_listings s JOIN customers c ON c.id=s.company_id LEFT JOIN categories cat ON cat.id=s.category_id WHERE s.status='pending' ORDER BY s.created_at DESC LIMIT 10",'sponsored_listings');
$pending_widgets = sa_query($conn,"SELECT wc.*, t.company_name as tenant_name, c.company_name FROM widget_configs wc JOIN tenants t ON t.id=wc.tenant_id LEFT JOIN customers c ON c.id=wc.company_id WHERE wc.status='pending' ORDER BY wc.created_at DESC LIMIT 10",'widget_configs');

$recent_premium = sa_query($conn,"SELECT c.company_name, c.premium_tier, c.premium_status, c.premium_expires_at, c.premium_price FROM customers WHERE c.premium_status='active' ORDER BY c.premium_expires_at DESC LIMIT 8",'customers');
$active_widgets = sa_query($conn,"SELECT wc.*, t.company_name as tenant_name, c.company_name FROM widget_configs wc JOIN tenants t ON t.id=wc.tenant_id LEFT JOIN customers c ON c.id=wc.company_id WHERE wc.status='active' AND wc.tier IN ('pro','agency') ORDER BY wc.expires_at ASC LIMIT 8",'widget_configs');
$active_spons = sa_query($conn,"SELECT s.*, c.company_name, cat.name as cat_name, t.company_name as tenant_name FROM sponsored_listings s JOIN customers c ON c.id=s.company_id LEFT JOIN categories cat ON cat.id=s.category_id LEFT JOIN tenants t ON t.id=s.tenant_id WHERE s.status='active' AND s.ends_at > NOW() ORDER BY s.ends_at ASC LIMIT 8",'sponsored_listings');

$robots='noindex, nofollow'; $pageTitle='Monetization'; $pageHeading='Monetization Command Center'; $pageSubtitle='4 revenue streams — premium profiles, widgets, sponsored listings & SMS credits'; $activePage='monetization'; $BASE='../'; $extraCss=['assets/css/superadmin.css']; $bodyClass='sa-body';
include dirname(__DIR__).'/includes/header.php';
include __DIR__.'/_shell.php';
?>
<div class="sa-page-head">
  <div>
    <div class="sa-crumbs"><a href="index.php">Super admin</a> <?php echo sa_icon('chevron-right'); ?> <span>Monetization</span></div>
    <h2>Monetization Command Center — 4 Revenue Streams</h2>
    <p>Premium Profiles · Review Widgets · Sponsored Placements · SMS Bulk Credits</p>
  </div>
  <div class="sa-head-actions">
    <a class="sa-btn sa-btn-primary" href="subscriptions.php?stream=premium"><?php echo sa_icon('card'); ?> Subscriptions Hub</a>
    <a class="sa-btn sa-btn-secondary" href="widgets.php"><?php echo sa_icon('layers'); ?> Widgets Manager</a>
    <a class="sa-btn sa-btn-secondary" href="sponsored.php"><?php echo sa_icon('trending-up'); ?> Sponsored Manager</a>
    <a class="sa-btn sa-btn-secondary" href="sms_providers.php"><?php echo sa_icon('message'); ?> SMS Gateways</a>
  </div>
</div>
<?php echo sa_render_flash(); ?>

<!-- KPI row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:16px;">
  <div class="sa-card" style="padding:16px;">
    <div style="font-size:11px;color:#64748b;font-weight:700;">PREMIUM PROFILES MRR</div>
    <div style="font-size:22px;font-weight:800;color:#7c3aed;"><?php echo sa_money($stats['premium_mrr']); ?></div>
    <div style="font-size:12px;color:#64748b;margin-top:4px;"><?php echo $stats['premium_active']; ?> active · <?php echo $stats['premium_pending']; ?> pending · <a href="subscriptions.php?stream=premium">View &rarr;</a></div>
  </div>
  <div class="sa-card" style="padding:16px;">
    <div style="font-size:11px;color:#64748b;font-weight:700;">REVIEW WIDGETS MRR</div>
    <div style="font-size:22px;font-weight:800;color:#2563eb;"><?php echo sa_money($stats['widgets_mrr']); ?></div>
    <div style="font-size:12px;color:#64748b;margin-top:4px;"><?php echo $stats['widgets_active']; ?> active Pro/Agency · <?php echo sa_num($stats['widgets_views']); ?> views · <a href="subscriptions.php?stream=widgets">View &rarr;</a></div>
  </div>
  <div class="sa-card" style="padding:16px;">
    <div style="font-size:11px;color:#64748b;font-weight:700;">SPONSORED REVENUE</div>
    <div style="font-size:22px;font-weight:800;color:#b45309;"><?php echo sa_money($stats['sponsored_revenue']); ?></div>
    <div style="font-size:12px;color:#64748b;margin-top:4px;"><?php echo $stats['sponsored_active']; ?> active · <?php echo $stats['sponsored_pending']; ?> pending · <a href="subscriptions.php?stream=sponsored">View &rarr;</a></div>
  </div>
  <div class="sa-card" style="padding:16px;">
    <div style="font-size:11px;color:#64748b;font-weight:700;">SMS CREDITS VOLUME</div>
    <div style="font-size:22px;font-weight:800;color:#15803d;"><?php echo sa_num($stats['sms_credits_sold']); ?></div>
    <div style="font-size:12px;color:#64748b;margin-top:4px;"><?php echo sa_num($stats['sms_credits_balance']); ?> wallet bal · <?php echo sa_num($stats['sms_sent']); ?> sent · <a href="sms_providers.php">Gateways &rarr;</a></div>
  </div>
</div>

<!-- Pending Activations Grid (3 Columns) -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:20px;">
  <!-- 1. Pending Premium -->
  <section class="sa-card" style="padding:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <h3 style="margin:0;font-size:15px;">Pending Premium Profiles</h3>
      <span class="sa-badge" style="background:#ede9fe;color:#6d28d9;"><?php echo count($pending_premium); ?></span>
    </div>
    <?php if($pending_premium): foreach($pending_premium as $p): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #e2e8f0;">
        <div>
          <strong style="font-size:13px;"><?php echo htmlspecialchars($p['company_name']); ?></strong> 
          <span style="font-size:11px;background:#ede9fe;color:#6d28d9;padding:2px 6px;border-radius:99px;font-weight:700;"><?php echo htmlspecialchars($p['tier']); ?></span>
          <div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo htmlspecialchars($p['tenant_name']); ?> · GHS <?php echo number_format((float)$p['price'],2); ?> · Inv #<?php echo (int)$p['invoice_id']; ?></div>
        </div>
        <form method="POST">
          <?php echo sa_csrf_field(); ?>
          <input type="hidden" name="action" value="activate_premium">
          <input type="hidden" name="company_id" value="<?php echo (int)$p['company_id']; ?>">
          <input type="hidden" name="sub_id" value="<?php echo (int)$p['id']; ?>">
          <button class="sa-btn sa-btn-primary" style="font-size:11px;padding:4px 8px;">Activate</button>
        </form>
      </div>
    <?php endforeach; else: ?>
      <p class="muted" style="font-size:12.5px;padding:10px 0;">No pending premium profiles — auto-activates when invoice marked paid.</p>
    <?php endif; ?>
  </section>

  <!-- 2. Pending Widgets -->
  <section class="sa-card" style="padding:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <h3 style="margin:0;font-size:15px;">Pending Review Widgets</h3>
      <span class="sa-badge" style="background:#e0f2fe;color:#0369a1;"><?php echo count($pending_widgets); ?></span>
    </div>
    <?php if($pending_widgets): foreach($pending_widgets as $pw): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #e2e8f0;">
        <div>
          <strong style="font-size:13px;"><?php echo htmlspecialchars($pw['company_name'] ?: $pw['tenant_name']); ?></strong>
          <span style="font-size:11px;background:#e0f2fe;color:#0369a1;padding:2px 6px;border-radius:99px;font-weight:700;"><?php echo htmlspecialchars($pw['tier']); ?></span>
          <div style="font-size:11px;color:#64748b;margin-top:2px;">Tenant: <?php echo htmlspecialchars($pw['tenant_name']); ?> · Inv #<?php echo (int)$pw['invoice_id']; ?></div>
        </div>
        <form method="POST">
          <?php echo sa_csrf_field(); ?>
          <input type="hidden" name="action" value="activate_widget">
          <input type="hidden" name="widget_id" value="<?php echo (int)$pw['id']; ?>">
          <button class="sa-btn sa-btn-primary" style="font-size:11px;padding:4px 8px;">Activate</button>
        </form>
      </div>
    <?php endforeach; else: ?>
      <p class="muted" style="font-size:12.5px;padding:10px 0;">No pending widget upgrades.</p>
    <?php endif; ?>
  </section>

  <!-- 3. Pending Sponsored -->
  <section class="sa-card" style="padding:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <h3 style="margin:0;font-size:15px;">Pending Category Ads</h3>
      <span class="sa-badge" style="background:#fef3c7;color:#92400e;"><?php echo count($pending_spons); ?></span>
    </div>
    <?php if($pending_spons): foreach($pending_spons as $s): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #e2e8f0;">
        <div>
          <strong style="font-size:13px;"><?php echo htmlspecialchars($s['company_name']); ?></strong> 
          <span style="font-size:11px;background:<?php echo $s['tier']==='featured'?'#fef3c7;color:#92400e':'#ede9fe;color:#6d28d9'; ?>;padding:2px 6px;border-radius:99px;font-weight:700;"><?php echo htmlspecialchars($s['tier']); ?></span>
          <div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo htmlspecialchars($s['cat_name'] ?? 'General'); ?> · GHS <?php echo number_format((float)$s['price'],2); ?></div>
        </div>
        <form method="POST">
          <?php echo sa_csrf_field(); ?>
          <input type="hidden" name="action" value="activate_sponsored">
          <input type="hidden" name="listing_id" value="<?php echo (int)$s['id']; ?>">
          <button class="sa-btn sa-btn-primary" style="font-size:11px;padding:4px 8px;">Activate</button>
        </form>
      </div>
    <?php endforeach; else: ?>
      <p class="muted" style="font-size:12.5px;padding:10px 0;">No pending category sponsorships.</p>
    <?php endif; ?>
  </section>
</div>

<!-- Active Tables Grid (2 Columns) -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
  <!-- Active Premium Profiles -->
  <section class="sa-card" style="padding:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <h3 style="margin:0;font-size:15px;">Active Premium Profiles</h3>
      <a href="subscriptions.php?stream=premium" class="sa-btn sa-btn-ghost sa-btn-sm">Subscriptions Hub &rarr;</a>
    </div>
    <?php if($recent_premium): ?>
    <table style="width:100%;font-size:12.5px;border-collapse:collapse;margin-top:10px;">
      <thead><tr style="color:#64748b;"><th style="text-align:left;padding:6px 4px;">Company</th><th style="padding:6px 4px;">Tier</th><th style="padding:6px 4px;text-align:right;">Price</th><th style="padding:6px 4px;text-align:right;">Expires</th></tr></thead>
      <tbody>
      <?php foreach($recent_premium as $r): ?>
        <tr style="border-top:1px solid #e2e8f0;">
          <td style="padding:6px 4px;"><strong><?php echo htmlspecialchars($r['company_name']); ?></strong></td>
          <td style="padding:6px 4px;"><span style="background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:99px;font-size:11px;font-weight:700;"><?php echo htmlspecialchars($r['premium_tier']); ?></span></td>
          <td style="padding:6px 4px;text-align:right;"><strong><?php echo sa_money((float)$r['premium_price']); ?></strong></td>
          <td style="padding:6px 4px;font-size:11.5px;text-align:right;color:#64748b;"><?php echo !empty($r['premium_expires_at'])?date('M j, Y', strtotime($r['premium_expires_at'])):'—'; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><p class="muted" style="font-size:12.5px;margin-top:10px;">No active premium profiles yet.</p><?php endif; ?>
  </section>

  <!-- Active Widgets (Pro / Agency) -->
  <section class="sa-card" style="padding:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <h3 style="margin:0;font-size:15px;">Active Pro / Agency Widgets</h3>
      <a href="widgets.php" class="sa-btn sa-btn-ghost sa-btn-sm">All Widgets &rarr;</a>
    </div>
    <?php if($active_widgets): ?>
    <table style="width:100%;font-size:12.5px;border-collapse:collapse;margin-top:10px;">
      <thead><tr style="color:#64748b;"><th style="text-align:left;padding:6px 4px;">Tenant</th><th style="padding:6px 4px;">Tier</th><th style="padding:6px 4px;">Traffic</th><th style="padding:6px 4px;text-align:right;">Expires</th></tr></thead>
      <tbody>
      <?php foreach($active_widgets as $aw): ?>
        <tr style="border-top:1px solid #e2e8f0;">
          <td style="padding:6px 4px;"><strong><?php echo htmlspecialchars($aw['company_name'] ?: $aw['tenant_name']); ?></strong></td>
          <td style="padding:6px 4px;"><span style="background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:99px;font-size:11px;font-weight:700;"><?php echo htmlspecialchars($aw['tier']); ?></span></td>
          <td style="padding:6px 4px;font-size:11.5px;"><?php echo sa_num($aw['views_total']); ?>v · <?php echo sa_num($aw['clicks_total']); ?>c</td>
          <td style="padding:6px 4px;font-size:11.5px;text-align:right;color:#64748b;"><?php echo !empty($aw['expires_at'])?date('M j, Y', strtotime($aw['expires_at'])):'—'; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><p class="muted" style="font-size:12.5px;margin-top:10px;">No active paid widget subscriptions yet.</p><?php endif; ?>
  </section>
</div>

<!-- Active Sponsored Listings Table -->
<section class="sa-card" style="padding:18px;margin-top:16px;">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <h3 style="margin:0;font-size:15px;">Active Category Sponsorships</h3>
    <a href="sponsored.php" class="sa-btn sa-btn-ghost sa-btn-sm">Placement Manager &rarr;</a>
  </div>
  <?php if($active_spons): ?>
  <table style="width:100%;font-size:12.5px;border-collapse:collapse;margin-top:10px;">
    <thead><tr style="color:#64748b;"><th style="text-align:left;padding:6px 6px;">Company</th><th style="padding:6px 6px;">Category</th><th style="padding:6px 6px;">Tier</th><th style="padding:6px 6px;text-align:right;">Price</th><th style="padding:6px 6px;text-align:center;">Impr / Clicks</th><th style="padding:6px 6px;text-align:right;">Ends At</th></tr></thead>
    <tbody>
    <?php foreach($active_spons as $as): ?>
      <tr style="border-top:1px solid #e2e8f0;">
        <td style="padding:6px 6px;"><strong><?php echo htmlspecialchars($as['company_name']); ?></strong><small style="display:block;color:#64748b;"><?php echo htmlspecialchars($as['tenant_name']); ?></small></td>
        <td style="padding:6px 6px;"><strong><?php echo htmlspecialchars($as['cat_name'] ?: 'General'); ?></strong></td>
        <td style="padding:6px 6px;"><span style="background:<?php echo $as['tier']==='featured'?'#fef3c7;color:#92400e':'#ede9fe;color:#6d28d9'; ?>;padding:2px 7px;border-radius:99px;font-size:11px;font-weight:700;"><?php echo htmlspecialchars($as['tier']); ?></span></td>
        <td style="padding:6px 6px;text-align:right;"><strong><?php echo sa_money((float)$as['price']); ?></strong></td>
        <td style="padding:6px 6px;text-align:center;font-size:11.5px;"><?php echo sa_num($as['impressions']); ?> / <?php echo sa_num($as['clicks']); ?></td>
        <td style="padding:6px 6px;font-size:11.5px;text-align:right;color:#64748b;"><?php echo !empty($as['ends_at'])?date('M j, Y', strtotime($as['ends_at'])):'—'; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p class="muted" style="font-size:12.5px;margin-top:10px;">No active category sponsorships running right now.</p><?php endif; ?>
</section>

<!-- Manually Credit SMS -->
<section class="sa-card" style="padding:18px;margin-top:16px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <h3 style="margin:0;font-size:15px;">Manually Credit SMS Wallet</h3>
    <a href="sms_providers.php" class="sa-btn sa-btn-secondary sa-btn-sm">Configure SMS Gateways (Hubtel / mNotify) &rarr;</a>
  </div>
  <form method="POST" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
    <?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="credit_sms">
    <div><label style="font-size:12px;font-weight:600;">Tenant ID</label><input type="number" name="tenant_id" placeholder="e.g. 3" required style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;width:140px;display:block;margin-top:4px;"></div>
    <div><label style="font-size:12px;font-weight:600;">Credits</label><input type="number" name="credits" value="100" min="1" required style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;width:140px;display:block;margin-top:4px;"></div>
    <button class="sa-btn sa-btn-primary">Add Credits</button>
  </form>
  <p class="muted" style="font-size:12px;margin-top:8px;">Use after confirming SMS bank wire payment or to grant promotional courtesy credits.</p>
</section>

<?php include __DIR__.'/_shell_footer.php'; ?>
