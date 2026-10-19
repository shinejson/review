<?php
require_once dirname(__DIR__).'/includes/auth.php';
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/functions.php';
require_once dirname(__DIR__).'/includes/sa_helpers.php';
require_once dirname(__DIR__).'/includes/monetization.php';
requireSuperAdminLogin(); require_sa_permission('finance');
monetization_ensure_schema($conn);

if(($_SERVER['REQUEST_METHOD']??'')==='POST' && sa_csrf_ok()){
  $act=$_POST['action'] ?? '';
  $id=(int)($_POST['id'] ?? 0);
  if($act==='activate' && $id){
    $row=sa_one($conn,"SELECT * FROM sponsored_listings WHERE id=".$id." LIMIT 1",'sponsored_listings');
    if($row){
      $start=date('Y-m-d H:i:s'); $end=date('Y-m-d H:i:s', strtotime('+'.(int)$row['duration_days'].' days'));
      @$conn->query("UPDATE sponsored_listings SET status='active', starts_at='$start', ends_at='$end' WHERE id=".$id);
      sa_flash('success','Listing #'.$id.' activated until '.$end);
    }
  }
  if($act==='expire' && $id){ @$conn->query("UPDATE sponsored_listings SET status='expired', ends_at=NOW() WHERE id=".$id); sa_flash('warning','Listing #'.$id.' expired.'); }
  if($act==='reject' && $id){ @$conn->query("UPDATE sponsored_listings SET status='rejected' WHERE id=".$id); sa_flash('error','Rejected #'.$id); }
  redirect('sponsored.php');
}
$list=sa_query($conn,"SELECT s.*, c.company_name, cat.name as cat_name, t.company_name as tenant_name FROM sponsored_listings s JOIN customers c ON c.id=s.company_id LEFT JOIN categories cat ON cat.id=s.category_id LEFT JOIN tenants t ON t.id=s.tenant_id ORDER BY s.created_at DESC LIMIT 100",'sponsored_listings');
$robots='noindex, nofollow'; $pageTitle='Sponsored Listings'; $pageHeading='Lead Ads — Sponsored'; $pageSubtitle='Manage pay-to-top sponsored & featured placements'; $activePage='monetization'; $BASE='../'; $extraCss=['assets/css/superadmin.css']; $bodyClass='sa-body';
include dirname(__DIR__).'/includes/header.php'; include __DIR__.'/_shell.php';
?>
<div class="sa-page-head"><div><div class="sa-crumbs"><a href="index.php">Super admin</a> <?php echo sa_icon('chevron-right'); ?> <a href="monetization.php">Monetization</a> <?php echo sa_icon('chevron-right'); ?> <span>Sponsored</span></div><h2>Sponsored & Featured Placements</h2><p><?php echo count($list); ?> listings — Max 1 Featured & 3 Sponsored per category</p></div></div>
<?php echo sa_render_flash(); ?>
<section class="sa-card" style="padding:0;overflow:hidden;">
  <div style="overflow-x:auto;">
    <table class="sa-table" style="width:100%;font-size:13px;">
      <thead><tr><th>Company</th><th>Tier / Category</th><th>Tenant</th><th>Price</th><th>Duration</th><th>Status</th><th>Impr / Clicks</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach($list as $s): ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($s['company_name']); ?></strong> <small style="color:#64748b;">#<?php echo (int)$s['company_id']; ?></small></td>
          <td><span style="background:<?php echo $s['tier']==='featured'?'#fef3c7;color:#92400e':'#ede9fe;color:#6d28d9'; ?>;padding:2px 7px;border-radius:99px;font-size:11px;font-weight:700;"><?php echo htmlspecialchars($s['tier']); ?></span> <small style="color:#64748b;"><?php echo htmlspecialchars($s['cat_name'] ?? 'General'); ?></small></td>
          <td style="font-size:12px;"><?php echo htmlspecialchars($s['tenant_name'] ?? ''); ?></td>
          <td style="text-align:right;"><?php echo sa_money((float)$s['price']); ?></td>
          <td style="font-size:11px;"><?php echo !empty($s['starts_at'])?date('M j',strtotime($s['starts_at'])):'—'; ?> → <?php echo !empty($s['ends_at'])?date('M j',strtotime($s['ends_at'])):'—'; ?></td>
          <td><span class="sa-badge <?php echo $s['status']==='active'?'sa-badge-active':($s['status']==='pending'?'sa-badge-pending':'sa-badge-inactive'); ?>"><?php echo htmlspecialchars($s['status']); ?></span></td>
          <td style="text-align:center;"><small><?php echo (int)$s['impressions']; ?> / <?php echo (int)$s['clicks']; ?></small></td>
          <td>
            <?php if($s['status']==='pending'): ?>
              <form method="POST" style="display:inline;"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><button class="sa-btn sa-btn-primary" style="font-size:11px;padding:4px 8px;">Activate</button></form>
              <form method="POST" style="display:inline;"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><button class="sa-btn sa-btn-secondary" style="font-size:11px;padding:4px 8px;">Reject</button></form>
            <?php elseif($s['status']==='active'): ?>
              <form method="POST" style="display:inline;"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="expire"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><button class="sa-btn sa-btn-secondary" style="font-size:11px;padding:4px 8px;">Expire</button></form>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$list): ?><tr><td colspan="8" style="text-align:center;padding:20px;color:#64748b;">No sponsored listings yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php include __DIR__.'/_shell_footer.php'; ?>
