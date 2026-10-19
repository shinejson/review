<?php
/**
 * Admin — Lead Generation & Advertising
 * Pay for Sponsored/Featured top-of-category status.
 * High-competition niches: logistics, hotels, clinics, etc.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/monetization.php';
require_once dirname(__DIR__) . '/includes/payments.php';
requireLogin(); requireTeamAccess('company');

$tenant_id=getTenantId(); $is_tenant=isTenant();
monetization_ensure_schema($conn); pay_ensure_schema($conn);

$tenant=null; if($tenant_id){ $t=$conn->prepare("SELECT * FROM tenants WHERE id=?"); $t->bind_param("i",$tenant_id); $t->execute(); $tenant=$t->get_result()->fetch_assoc(); $t->close(); }
$company_id=getActiveCompanyId($conn,$tenant_id);
$company=getActiveCompanyProfile($conn,$tenant_id);
$brand=$company['company_name'] ?? $tenant['company_name'] ?? 'Your Business';
$cat_id=(int)($company['category_id'] ?? 0);
$cat_name='';
if($cat_id){ $c=$conn->query("SELECT name FROM categories WHERE id=".(int)$cat_id); if($c && $row=$c->fetch_assoc()) $cat_name=$row['name']; }

// Handle purchase
if(($_SERVER['REQUEST_METHOD']??'')==='POST' && sa_csrf_ok()){
  $act=$_POST['action'] ?? '';
  if($act==='buy_sponsored' && $company_id){
    $tier=in_array($_POST['tier']??'', ['sponsored','featured'], true) ? $_POST['tier'] : 'sponsored';
    // check slot availability for this category
    $activeCount=(int)sa_scalar($conn,"SELECT COUNT(*) FROM sponsored_listings WHERE status='active' AND ends_at > NOW() AND (category_id=".(int)$cat_id." OR category_id IS NULL)",0,'sponsored_listings');
    $maxSlots = $tier==='featured' ? 1 : 3;
    if($activeCount >= $maxSlots && $tier==='featured'){
      $_SESSION['error']='Featured slot for '.htmlspecialchars($cat_name ?: 'this category').' is already taken — only 1 Featured slot exists per category. Choose Sponsored or try next month.';
      header('Location: sponsored.php'); exit;
    }
    if($activeCount >= 3){
      // allow but warn — queue
    }
    $price = $tier==='featured' ? 599 : 299;
    $res=pay_invoice_create($conn,['tenant_id'=>$tenant_id,'purpose'=>'sponsored','amount'=>$price,'months'=>1,'subject'=>'Category Sponsorship — '.ucfirst($tier).' ('.($cat_name ?: 'General').') for '.$brand,'notes'=>json_encode(['stream'=>'sponsored','company_id'=>$company_id,'category_id'=>$cat_id,'tier'=>$tier])]);
    if($res['ok']){
      $iid=(int)$res['id'];
      $badge=$tier==='featured' ? '★ Featured' : 'Sponsored';
      $title=$brand.' — Top '.($cat_name ?: 'Business');
      $st=$conn->prepare("INSERT INTO sponsored_listings (tenant_id, company_id, category_id, tier, badge_label, title, price, currency, duration_days, status, invoice_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
      $curr='GHS'; $dur=30; $status='pending';
      $st->bind_param("iiisssdsisi",$tenant_id,$company_id,$cat_id,$tier,$badge,$title,$price,$curr,$dur,$status,$iid);
      $st->execute(); $st->close();
      header('Location: payment_checkout.php?invoice='.$iid); exit;
    } else { $_SESSION['error']=$res['message']; header('Location: sponsored.php'); exit; }
  }
  if($act==='track_click' && !empty($_POST['listing_id'])){
    monetization_increment_impression($conn, (int)$_POST['listing_id']);
    echo json_encode(['ok'=>true]); exit;
  }
}
$flash_success=$_SESSION['success'] ?? ''; unset($_SESSION['success']);
$flash_error=$_SESSION['error'] ?? ''; unset($_SESSION['error']);

$myListings = $tenant_id ? sa_query($conn,"SELECT * FROM sponsored_listings WHERE tenant_id=".(int)$tenant_id." ORDER BY created_at DESC LIMIT 20",'sponsored_listings') : [];
$activeLeader = $cat_id ? get_sponsored_for_category($conn,$cat_id,5) : [];
$competitors = [];
if($cat_id){
  $competitors = sa_query($conn,"SELECT c.company_name, (SELECT COUNT(*) FROM ratings r WHERE r.company_id=c.id) as rc, (SELECT AVG(rating) FROM ratings r WHERE r.company_id=c.id) as avg_r, (SELECT COUNT(*) FROM sponsored_listings s WHERE s.company_id=c.id AND s.status='active' AND s.ends_at > NOW()) as is_sponsored FROM customers c WHERE c.category_id=".(int)$cat_id." ORDER BY rc DESC LIMIT 6",'customers');
}

$BASE='../'; $pageTitle='Sponsored & Featured'; $activeNav='company';
include __DIR__.'/_shell.php';
?>
<div class="welcome-row" style="margin-bottom:22px;">
  <div>
    <p class="eyebrow">Monetization · Lead Generation & Advertising</p>
    <h1>Sponsored & Featured — Own Your Category</h1>
    <p class="muted" style="margin-top:6px;">Pay for top-of-category status and turn high-intent directory traffic into booked orders. <strong>Best for:</strong> logistics, hotels, clinics, law, real estate — where being #1 pays.</p>
  </div>
  <div style="display:flex;gap:8px;"><a href="../companies.php" target="_blank" class="btn btn-secondary" style="text-decoration:none;">Preview Directory</a></div>
</div>
<?php if($flash_success): ?><div class="alert alert-success"><?php echo htmlspecialchars($flash_success); ?></div><?php endif; ?>
<?php if($flash_error): ?><div class="alert alert-error"><?php echo htmlspecialchars($flash_error); ?></div><?php endif; ?>

<?php if(!$company_id): ?>
<div class="form-card" style="padding:26px;text-align:center;">Create a company profile first.<br><a href="company.php?new=1" class="btn btn-primary" style="text-decoration:none;margin-top:10px;">Create Profile</a></div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
  <div class="form-card" style="padding:18px;border-left:4px solid #f59e0b;">
    <div style="display:flex;justify-content:space-between;align-items:center;"><strong><?php echo htmlspecialchars($brand); ?></strong><span style="font-size:11px;background:#fffbeb;color:#92400e;border:1px solid #fde68a;padding:2px 8px;border-radius:99px;"><?php echo htmlspecialchars($cat_name ?: 'Uncategorized'); ?></span></div>
    <div style="margin-top:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px;">
      <div style="font-size:12px;color:#64748b;">Current sponsored queue for this category:</div>
      <?php if($activeLeader): foreach($activeLeader as $s): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;">
          <span><?php echo htmlspecialchars($s['company_id']==$company_id ? '★ You — '.$brand : 'Business #'.$s['company_id']); ?> <small style="color:#94a3b8;"><?php echo htmlspecialchars($s['tier']); ?></small></span>
          <span style="color:#10b981;font-weight:700;"><?php echo (int)$s['clicks']; ?> clicks</span>
        </div>
      <?php endforeach; else: ?>
        <div style="font-size:12.5px;color:#334155;">No active sponsors — be first to own the top!</div>
      <?php endif; ?>
      <div style="font-size:11px;color:#94a3b8;margin-top:8px;">Featured = fixed #1 slot (max 1). Sponsored = rotation in top 3 (max 3). Each slot = 30 days.</div>
    </div>
    <?php if($competitors): ?>
    <div style="margin-top:12px;">
      <strong style="font-size:12.5px;">Top competitors in <?php echo htmlspecialchars($cat_name); ?></strong>
      <table style="width:100%;font-size:12.5px;margin-top:6px;border-collapse:collapse;">
        <tr style="color:#94a3b8;"><th style="text-align:left;padding:4px 6px;">Business</th><th style="text-align:right;padding:4px 6px;">Reviews</th><th style="text-align:right;padding:4px 6px;">★</th></tr>
        <?php foreach($competitors as $cp): ?>
        <tr style="border-top:1px solid #f1f5f9;"><td style="padding:4px 6px;"><?php echo htmlspecialchars($cp['company_name']); ?><?php echo !empty($cp['is_sponsored'])?' <span style="background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:99px;font-size:10px;">SPONSORED</span>':''; ?></td><td style="text-align:right;padding:4px 6px;"><?php echo (int)$cp['rc']; ?></td><td style="text-align:right;padding:4px 6px;"><?php echo number_format((float)($cp['avg_r']??0),1); ?></td></tr>
        <?php endforeach; ?>
      </table>
      <div style="font-size:11px;color:#64748b;margin-top:6px;">Tip: sponsors outrank organic results even if competitors have more reviews — visibility is rented, not just earned.</div>
    </div>
    <?php endif; ?>
  </div>
  <div class="form-card" style="padding:18px;">
    <h3 style="margin:0;">Sponsored vs Featured — What You Get</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
      <div style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;">
        <div style="font-weight:800;font-size:13px;">Sponsored Boost</div><div style="font-size:18px;font-weight:800;">GHS 299<span style="font-size:12px;color:#64748b;">/30 days</span></div>
        <ul style="font-size:12px;color:#334155;margin:8px 0 10px;list-style:none;padding:0;"><li>✓ Top 3 rotation in category</li><li>✓ Gold “Sponsored” badge</li><li>✓ Up to 3 slots/category</li><li>✓ Impression & click analytics</li></ul>
        <form method="POST"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="buy_sponsored"><input type="hidden" name="tier" value="sponsored"><button class="btn btn-secondary" style="width:100%;">Buy Sponsored →</button></form>
      </div>
      <div style="border:2px solid #f59e0b;border-radius:12px;padding:14px;background:#fffbeb;">
        <div style="font-weight:800;font-size:13px;color:#92400e;"><i class="fa-solid fa-crown"></i> Featured Crown</div><div style="font-size:18px;font-weight:800;">GHS 599<span style="font-size:12px;color:#64748b;">/30 days</span></div>
        <ul style="font-size:12px;color:#334155;margin:8px 0 10px;list-style:none;padding:0;"><li>✓ Fixed #1 position</li><li>✓ “★ Featured” crown + homepage spotlight</li><li>✓ Only 1 slot/category — exclusivity</li><li>✓ Priority over Sponsored</li></ul>
        <form method="POST"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="buy_sponsored"><input type="hidden" name="tier" value="featured"><button class="btn btn-primary" style="width:100%;">Buy Featured →</button></form>
      </div>
    </div>
    <div style="margin-top:12px;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:10px;padding:10px;font-size:12.5px;color:#065f46;">
      <strong>ROI math:</strong> Directory top slot averages 4–7× more “View & Rate” clicks. At 12% WhatsApp conversion, GHS 299 sponsorship covering ~800 impressions typically returns 15–25 qualified leads — GHS 12–20 per lead vs GHS 35+ for cold ads.
    </div>
  </div>
</div>

<div class="form-card" style="padding:18px;margin-top:16px;">
  <div style="display:flex;justify-content:space-between;align-items:center;">
    <h3 style="margin:0;">Your Campaigns</h3><span class="muted" style="font-size:12px;"><?php echo count($myListings); ?> total</span>
  </div>
  <?php if($myListings): ?>
  <div style="overflow-x:auto;margin-top:10px;">
    <table style="width:100%;font-size:13px;border-collapse:collapse;">
      <thead><tr style="background:#f8fafc;color:#64748b;"><th style="padding:8px 10px;text-align:left;">Tier</th><th style="padding:8px;text-align:left;">Category</th><th style="padding:8px;text-align:right;">Price</th><th style="padding:8px;text-align:left;">Status</th><th style="padding:8px;text-align:left;">Period</th><th style="padding:8px;text-align:right;">Clicks</th></tr></thead>
      <tbody>
      <?php foreach($myListings as $l): ?>
        <tr style="border-top:1px solid #e2e8f0;">
          <td style="padding:8px 10px;"><span style="background:<?php echo $l['tier']==='featured'?'#fef3c7;color:#92400e;':'#ede9fe;color:#6d28d9;'; ?>padding:3px 8px;border-radius:99px;font-size:11px;font-weight:700;"><?php echo htmlspecialchars($l['tier']); ?></span></td>
          <td style="padding:8px;"><?php echo $l['category_id'] ? '#'.$l['category_id'] : 'All'; ?></td>
          <td style="padding:8px;text-align:right;">GHS <?php echo number_format((float)$l['price'],2); ?></td>
          <td style="padding:8px;"><span style="padding:2px 8px;border-radius:99px;font-size:11px;background:<?php echo $l['status']==='active'?'#dcfce7;color:#15803d':($l['status']==='pending'?'#fef3c7;color:#92400e':'#f1f5f9;color:#64748b'); ?>"><?php echo htmlspecialchars($l['status']); ?></span></td>
          <td style="padding:8px;font-size:12px;"><?php echo !empty($l['starts_at']) ? date('M j', strtotime($l['starts_at'])) : '—'; ?> → <?php echo !empty($l['ends_at']) ? date('M j', strtotime($l['ends_at'])) : '—'; ?></td>
          <td style="padding:8px;text-align:right;font-weight:700;"><?php echo (int)$l['clicks']; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <p class="muted" style="margin-top:10px;font-size:13px;">No campaigns yet — buy Sponsored or Featured above. Your position activates immediately after payment confirmation.</p>
  <?php endif; ?>
</div>

<?php endif; ?>
<?php include __DIR__.'/_shell_footer.php'; ?>
