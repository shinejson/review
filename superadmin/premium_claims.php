<?php
require_once dirname(__DIR__).'/includes/auth.php';
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/functions.php';
require_once dirname(__DIR__).'/includes/sa_helpers.php';
require_once dirname(__DIR__).'/includes/monetization.php';
requireSuperAdminLogin(); require_sa_permission('tenants');
monetization_ensure_schema($conn);

if(($_SERVER['REQUEST_METHOD']??'')==='POST' && sa_csrf_ok()){
  $act=$_POST['action'] ?? '';
  $id=(int)($_POST['id'] ?? 0);
  $actor=$_SESSION['super_admin_username'] ?? 'Super Admin';
  if($act==='approve' && $id){
    $row=sa_one($conn,"SELECT * FROM premium_claims WHERE id=".$id." LIMIT 1",'premium_claims');
    if($row){
      @$conn->query("UPDATE premium_claims SET claim_status='approved', reviewer_note='".$conn->real_escape_string($_POST['note'] ?? '')."', reviewed_by='".$conn->real_escape_string($actor)."', verified_at=NOW() WHERE id=".$id);
      @$conn->query("UPDATE customers SET is_claimed=1, claimed_tenant_id=".(int)$row['claimant_tenant_id'].", claimed_at=NOW() WHERE id=".(int)$row['company_id']);
      sa_flash('success','Claim #'.$id.' approved — profile now linked.');
    }
  }
  if($act==='reject' && $id){
    @$conn->query("UPDATE premium_claims SET claim_status='rejected', reviewer_note='".$conn->real_escape_string($_POST['note'] ?? '')."', reviewed_by='".$conn->real_escape_string($actor)."' WHERE id=".$id);
    sa_flash('warning','Claim #'.$id.' rejected.');
  }
  redirect('premium_claims.php');
}
$claims=sa_query($conn,"SELECT pc.*, c.company_name, t.company_name as claimant_company FROM premium_claims pc JOIN customers c ON c.id=pc.company_id JOIN tenants t ON t.id=pc.claimant_tenant_id ORDER BY pc.created_at DESC LIMIT 50",'premium_claims');
$robots='noindex, nofollow'; $pageTitle='Premium Claims'; $pageHeading='Business Claims'; $pageSubtitle='Verify businesses that claim their directory profile'; $activePage='monetization'; $BASE='../'; $extraCss=['assets/css/superadmin.css']; $bodyClass='sa-body';
include dirname(__DIR__).'/includes/header.php'; include __DIR__.'/_shell.php';
?>
<div class="sa-page-head"><div><div class="sa-crumbs"><a href="index.php">Super admin</a> <?php echo sa_icon('chevron-right'); ?> <a href="monetization.php">Monetization</a> <?php echo sa_icon('chevron-right'); ?> <span>Claims</span></div><h2>Premium Profile Claims</h2><p><?php echo count($claims); ?> claims — verify before premium activation</p></div></div>
<?php echo sa_render_flash(); ?>
<section class="sa-card" style="padding:0;overflow:hidden;">
  <div style="overflow-x:auto;">
    <table class="sa-table" style="width:100%;font-size:13px;">
      <thead><tr><th>ID</th><th>Company</th><th>Claimant Workspace</th><th>Method</th><th>Code</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach($claims as $cl): ?>
        <tr>
          <td>#<?php echo (int)$cl['id']; ?></td>
          <td><strong><?php echo htmlspecialchars($cl['company_name']); ?></strong> <small style="color:#64748b;">#<?php echo (int)$cl['company_id']; ?></small></td>
          <td><?php echo htmlspecialchars($cl['claimant_company']); ?></td>
          <td><?php echo htmlspecialchars($cl['claim_method']); ?><br><small style="color:#64748b;"><?php echo htmlspecialchars($cl['claimant_email']); ?></small></td>
          <td style="font-family:monospace;"><?php echo htmlspecialchars($cl['verification_code']); ?></td>
          <td><span class="sa-badge <?php echo $cl['claim_status']==='approved'?'sa-badge-active':($cl['claim_status']==='rejected'?'sa-badge-cancelled':'sa-badge-pending'); ?>"><?php echo htmlspecialchars($cl['claim_status']); ?></span></td>
          <td style="font-size:12px;"><?php echo date('M j, Y', strtotime($cl['created_at'])); ?></td>
          <td>
            <?php if($cl['claim_status']==='pending'): ?>
            <form method="POST" style="display:inline;"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?php echo (int)$cl['id']; ?>"><button class="sa-btn sa-btn-primary" style="font-size:11px;padding:4px 8px;">Approve</button></form>
            <form method="POST" style="display:inline;"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?php echo (int)$cl['id']; ?>"><button class="sa-btn sa-btn-secondary" style="font-size:11px;padding:4px 8px;">Reject</button></form>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$claims): ?><tr><td colspan="8" style="text-align:center;padding:20px;color:#64748b;">No claims yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php include __DIR__.'/_shell_footer.php'; ?>
