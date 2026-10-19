<?php
/**
 * Superadmin — Monetization Command Center
 * Overview + management for 4 revenue streams.
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
$sum = pay_summary($conn,30);

if(($_SERVER['REQUEST_METHOD']??'')==='POST' && sa_csrf_ok()){
  $act=$_POST['action'] ?? '';
  if($act==='activate_premium'){
    $cid=(int)($_POST['company_id'] ?? 0);
    $subId=(int)($_POST['sub_id'] ?? 0);
    $ps=sa_one($conn,"SELECT * FROM premium_subscriptions WHERE id=".$subId." LIMIT 1",'premium_subscriptions');
    if($ps){
      $tier=$ps['tier']; $exp=date('Y-m-d H:i:s', strtotime('+'.((int)$ps['billing_cycle']==='yearly'?12:1).' months'));
      @$conn->query("UPDATE customers SET is_claimed=1, premium_tier='".$conn->real_escape_string($tier)."', premium_status='active', premium_expires_at='".$conn->real_escape_string($exp)."', remove_competitor_ads=1, verified_badge=1, premium_price='".(float)$ps['price']."' WHERE id=".(int)$ps['company_id']);
      @$conn->query("UPDATE premium_subscriptions SET status='active', starts_at=NOW(), expires_at='".$conn->real_escape_string($exp)."' WHERE id=".$subId);
      // also update linked invoice paid_at handled via finance
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
$recent_premium = sa_query($conn,"SELECT c.company_name, c.premium_tier, c.premium_status, c.premium_expires_at, c.premium_price FROM customers WHERE c.premium_status='active' ORDER BY c.premium_expires_at DESC LIMIT 8",'customers');

$robots='noindex, nofollow'; $pageTitle='Monetization'; $pageHeading='Monetization Command Center'; $pageSubtitle='4 revenue streams — premium profiles, widgets, sponsored listings & SMS credits'; $activePage='monetization'; $BASE='../'; $extraCss=['assets/css/superadmin.css']; $bodyClass='sa-body';
include dirname(__DIR__).'/includes/header.php';
include __DIR__.'/_shell.php';
?>
<div class="sa-page-head"><div><div class="sa-crumbs"><a href="index.php">Super admin</a> <?php echo sa_icon('chevron-right'); ?> <span>Monetization</span></div><h2>Monetization — 4 Streams</h2><p>Premium · Widgets · Sponsored · SMS</p></div></div>
<?php echo sa_render_flash(); ?>

<!-- KPI row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
  <div class="sa-card" style="padding:16px;"><div style="font-size:11px;color:#64748b;font-weight:700;">PREMIUM MRR</div><div style="font-size:20px;font-weight:800;"><?php echo sa_money($stats['premium_mrr']); ?></div><div style="font-size:12px;color:#64748b;"><?php echo $stats['premium_active']; ?> active · <?php echo $stats['premium_claims_pending']; ?> claims pending</div></div>
  <div class="sa-card" style="padding:16px;"><div style="font-size:11px;color:#64748b;font-weight:700;">SPONSORED REVENUE</div><div style="font-size:20px;font-weight:800;"><?php echo sa_money($stats['sponsored_revenue']); ?></div><div style="font-size:12px;color:#64748b;"><?php echo $stats['sponsored_active']; ?> active · <?php echo $stats['sponsored_pending']; ?> pending</div></div>
  <div class="sa-card" style="padding:16px;"><div style="font-size:11px;color:#64748b;font-weight:700;">WIDGET VIEWS</div><div style="font-size:20px;font-weight:800;"><?php echo sa_num($stats['widgets_views']); ?></div><div style="font-size:12px;color:#64748b;"><?php echo $stats['widgets_active']; ?> active widgets</div></div>
  <div class="sa-card" style="padding:16px;"><div style="font-size:11px;color:#64748b;font-weight:700;">SMS CREDITS</div><div style="font-size:20px;font-weight:800;"><?php echo sa_num($stats['sms_credits_sold']); ?></div><div style="font-size:12px;color:#64748b;"><?php echo sa_num($stats['sms_credits_balance']); ?> balance · <?php echo sa_num($stats['sms_sent']); ?> sent</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
  <section class="sa-card" style="padding:18px;">
    <h3 style="margin:0 0 10px;">Pending Premium Activations</h3>
    <?php if($pending_premium): foreach($pending_premium as $p): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #e2e8f0;">
        <div><strong style="font-size:13px;"><?php echo htmlspecialchars($p['company_name']); ?></strong> <span style="font-size:11px;background:#ede9fe;color:#6d28d9;padding:2px 6px;border-radius:99px;"><?php echo htmlspecialchars($p['tier']); ?></span><div style="font-size:11px;color:#64748b;"><?php echo htmlspecialchars($p['tenant_name']); ?> · GHS <?php echo number_format((float)$p['price'],2); ?> · inv #<?php echo (int)$p['invoice_id']; ?></div></div>
        <form method="POST"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="activate_premium"><input type="hidden" name="company_id" value="<?php echo (int)$p['company_id']; ?>"><input type="hidden" name="sub_id" value="<?php echo (int)$p['id']; ?>"><button class="sa-btn sa-btn-primary" style="font-size:12px;">Activate</button></form>
      </div>
    <?php endforeach; else: ?><p class="muted" style="font-size:13px;">No pending premium — invoices activate automatically when marked paid.</p><?php endif; ?>
  </section>
  <section class="sa-card" style="padding:18px;">
    <h3 style="margin:0 0 10px;">Pending Sponsored Listings</h3>
    <?php if($pending_spons): foreach($pending_spons as $s): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #e2e8f0;">
        <div><strong style="font-size:13px;"><?php echo htmlspecialchars($s['company_name']); ?></strong> <span style="font-size:11px;background:<?php echo $s['tier']==='featured'?'#fef3c7;color:#92400e':'#ede9fe;color:#6d28d9'; ?>;padding:2px 6px;border-radius:99px;"><?php echo htmlspecialchars($s['tier']); ?></span><div style="font-size:11px;color:#64748b;"><?php echo htmlspecialchars($s['cat_name'] ?? 'General'); ?> · GHS <?php echo number_format((float)$s['price'],2); ?></div></div>
        <form method="POST"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="activate_sponsored"><input type="hidden" name="listing_id" value="<?php echo (int)$s['id']; ?>"><button class="sa-btn sa-btn-primary" style="font-size:12px;">Activate</button></form>
      </div>
    <?php endforeach; else: ?><p class="muted" style="font-size:13px;">No pending sponsorships.</p><?php endif; ?>
  </section>
</div>

<section class="sa-card" style="padding:18px;margin-top:16px;">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;"><h3 style="margin:0;">Active Premium Profiles</h3><a href="premium_claims.php" class="sa-btn sa-btn-secondary" style="font-size:12px;">Manage Claims →</a></div>
  <?php if($recent_premium): ?>
  <table style="width:100%;font-size:13px;border-collapse:collapse;margin-top:10px;">
    <thead><tr style="color:#64748b;"><th style="text-align:left;padding:6px 8px;">Company</th><th style="padding:6px 8px;">Tier</th><th style="padding:6px 8px;">Price</th><th style="padding:6px 8px;">Expires</th></tr></thead>
    <tbody>
    <?php foreach($recent_premium as $r): ?><tr style="border-top:1px solid #e2e8f0;"><td style="padding:6px 8px;"><?php echo htmlspecialchars($r['company_name']); ?></td><td style="padding:6px 8px;"><span style="background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:99px;font-size:11px;"><?php echo htmlspecialchars($r['premium_tier']); ?></span></td><td style="padding:6px 8px;text-align:right;"><?php echo sa_money((float)$r['premium_price']); ?></td><td style="padding:6px 8px;font-size:12px;"><?php echo !empty($r['premium_expires_at'])?date('M j, Y', strtotime($r['premium_expires_at'])):'—'; ?></td></tr><?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?><p class="muted" style="font-size:13px;margin-top:10px;">No active premium profiles yet.</p><?php endif; ?>
</section>

<section class="sa-card" style="padding:18px;margin-top:16px;">
  <h3 style="margin:0 0 10px;">Manually Credit SMS</h3>
  <form method="POST" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
    <?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="credit_sms">
    <div><label style="font-size:12px;">Tenant ID</label><input type="number" name="tenant_id" placeholder="e.g., 3" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;width:140px;"></div>
    <div><label style="font-size:12px;">Credits</label><input type="number" name="credits" value="100" min="1" style="padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;width:140px;"></div>
    <button class="sa-btn sa-btn-primary">Add Credits</button>
  </form>
  <p class="muted" style="font-size:12px;margin-top:8px;">Use after confirming SMS invoice payment — or grant courtesy credits.</p>
</section>
<?php include __DIR__.'/_shell_footer.php'; ?>
