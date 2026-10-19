<?php
require_once dirname(__DIR__).'/includes/auth.php';
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/functions.php';
require_once dirname(__DIR__).'/includes/sa_helpers.php';
require_once dirname(__DIR__).'/includes/monetization.php';
require_once dirname(__DIR__).'/includes/sms_providers.php';
requireSuperAdminLogin(); require_sa_permission('finance');
monetization_ensure_schema($conn);

if(($_SERVER['REQUEST_METHOD']??'')==='POST' && sa_csrf_ok()){
  $act=$_POST['action'] ?? '';
  if($act==='save_provider'){
    $key=preg_replace('/[^a-z_]/','', $_POST['provider_key'] ?? '');
    $data=[
      'display_name'=>sanitize($_POST['display_name'] ?? ''),
      'is_enabled'=>!empty($_POST['is_enabled'])?1:0,
      'api_url'=>sanitize($_POST['api_url'] ?? ''),
      'api_key'=>trim($_POST['api_key'] ?? ''),
      'api_secret'=>trim($_POST['api_secret'] ?? ''),
      'sender_id'=>sanitize($_POST['sender_id'] ?? 'Optibiz'),
    ];
    // Preserve existing key if blank
    $existing=sms_provider($conn,$key);
    if($existing){ if($data['api_key']==='' && !empty($existing['api_key'])) $data['api_key']=$existing['api_key']; if($data['api_secret']==='' && !empty($existing['api_secret'])) $data['api_secret']=$existing['api_secret']; }
    sms_provider_save($conn,$key,$data);
    sa_flash('success',($data['display_name'] ?: $key).' saved.');
    redirect('sms_providers.php');
  }
  if($act==='test_provider'){
    $key=preg_replace('/[^a-z_]/','', $_POST['provider_key'] ?? '');
    $res=sms_test_connection($conn,$key);
    $prov=sms_provider($conn,$key);
    $status=$res['ok']?'verified':'failed';
    if($prov) @$conn->query("UPDATE sms_providers SET connection_status='".$conn->real_escape_string($status)."', connection_note='".$conn->real_escape_string($res['message'])."', last_tested_at=NOW() WHERE provider_key='".$conn->real_escape_string($key)."'");
    sa_flash($res['ok']?'success':'error',$res['message']);
    redirect('sms_providers.php');
  }
}
$providers=sms_provider_list($conn);

$robots='noindex, nofollow'; $pageTitle='SMS Providers'; $pageHeading='Local SMS Gateways'; $pageSubtitle='Configure Ghana SMS APIs — Hubtel, mNotify, Africa\'s Talking'; $activePage='monetization'; $BASE='../'; $extraCss=['assets/css/superadmin.css']; $bodyClass='sa-body';
include dirname(__DIR__).'/includes/header.php'; include __DIR__.'/_shell.php';
?>
<div class="sa-page-head"><div><div class="sa-crumbs"><a href="index.php">Super admin</a> <?php echo sa_icon('chevron-right'); ?> <a href="monetization.php">Monetization</a> <?php echo sa_icon('chevron-right'); ?> <span>SMS Providers</span></div><h2>Local SMS API Gateways</h2><p>Automated review collection — plumbers, restaurants, delivery</p></div></div>
<?php echo sa_render_flash(); ?>

<div class="sa-card" style="padding:16px;margin-bottom:14px;background:#eff6ff;border:1px solid #bfdbfe;">
  <strong style="font-size:13px;color:#1d4ed8;">Ghana-first routing:</strong> <span style="font-size:12.5px;color:#334155;">Hubtel & mNotify deliver 95%+ to MTN/Vodafone/AirtelTigo within 8s. Africa's Talking for Kenya/Nigeria fallback. Configure your API keys below — tenants automatically use your enabled gateways; you earn margin on SMS credits (cost ~GHS 0.25–0.35, sell at GHS 0.40–0.50).</span>
</div>

<?php foreach($providers as $pr): 
  $catalog = monetization_sms_catalog();
  $meta = $catalog[$pr['provider_key']] ?? ['region'=>'Ghana'];
?>
<section class="sa-card" style="padding:18px;margin-bottom:14px;<?php echo empty($pr['is_enabled'])?'opacity:.9':''; ?>">
  <form method="POST">
    <?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="save_provider"><input type="hidden" name="provider_key" value="<?php echo htmlspecialchars($pr['provider_key']); ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
      <div>
        <div style="display:flex;align-items:center;gap:10px;">
          <strong style="font-size:16px;"><?php echo htmlspecialchars($pr['display_name']); ?></strong>
          <span style="font-size:11px;background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:99px;"><?php echo htmlspecialchars($meta['region'] ?? 'Ghana'); ?></span>
          <?php echo $pr['is_enabled']?'<span style="font-size:11px;background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:2px 8px;border-radius:99px;">Enabled</span>':'<span style="font-size:11px;background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:99px;">Disabled</span>'; ?>
          <?php if(!empty($pr['connection_status']) && $pr['connection_status']!=='unverified'): ?><span style="font-size:11px;padding:2px 8px;border-radius:99px;background:<?php echo $pr['connection_status']==='verified'?'#dcfce7;color:#15803d':'#fee2e2;color:#991b1b'; ?>"><?php echo htmlspecialchars($pr['connection_status']); ?></span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:#64748b;margin-top:4px;"><?php echo htmlspecialchars($meta['summary'] ?? ''); ?></div>
        <?php if(!empty($pr['connection_note'])): ?><div style="font-size:12px;color:#475569;margin-top:4px;">ⓘ <?php echo htmlspecialchars($pr['connection_note']); ?></div><?php endif; ?>
      </div>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;"><input type="checkbox" name="is_enabled" value="1" <?php echo !empty($pr['is_enabled'])?'checked':''; ?>> Enabled for tenants</label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
      <div><label style="font-size:12px;font-weight:600;">Display Name</label><input type="text" name="display_name" value="<?php echo htmlspecialchars($pr['display_name']); ?>" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
      <div><label style="font-size:12px;font-weight:600;">Sender ID (registered)</label><input type="text" name="sender_id" maxlength="11" value="<?php echo htmlspecialchars($pr['sender_id'] ?? 'Optibiz'); ?>" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
      <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;">API URL / Endpoint</label><input type="text" name="api_url" value="<?php echo htmlspecialchars($pr['api_url'] ?? ''); ?>" placeholder="https://..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
      <div><label style="font-size:12px;font-weight:600;">API Key / Username / ClientID</label><input type="text" name="api_key" value="<?php echo !empty($pr['api_key'])?'••••••••'.substr($pr['api_key'],-4):''; ?>" placeholder="Paste new key" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
      <div><label style="font-size:12px;font-weight:600;">API Secret / Secret Hash / Token</label><input type="text" name="api_secret" value="<?php echo !empty($pr['api_secret'])?'••••••••'.substr($pr['api_secret'],-4):''; ?>" placeholder="Paste secret" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
    </div>
    <div style="display:flex;gap:8px;margin-top:12px;">
      <button type="submit" class="sa-btn sa-btn-primary" style="font-size:13px;">Save <?php echo htmlspecialchars($pr['display_name']); ?></button>
    </div>
  </form>
  <form method="POST" style="margin-top:8px;"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="test_provider"><input type="hidden" name="provider_key" value="<?php echo htmlspecialchars($pr['provider_key']); ?>"><button class="sa-btn sa-btn-secondary" style="font-size:12px;">Test Connection</button></form>
</section>
<?php endforeach; ?>

<div class="sa-card" style="padding:16px;background:#fffbeb;border:1px solid #fde68a;">
  <strong style="font-size:13px;color:#92400e;">How to get Hubtel keys:</strong>
  <ol style="font-size:12.5px;color:#78350f;margin:6px 0 0 18px;"><li>Create account at <a href="https://hubtel.com" target="_blank">hubtel.com</a> → SMS → Settings → API Keys</li><li>Register Sender ID (takes 24h approval from NCA)</li><li>Paste ClientID in “API Key” and Secret in “API Secret” above</li><li>Enable and test — send a test SMS to your own number from admin/sms_campaigns.php</li></ol>
</div>
<?php include __DIR__.'/_shell_footer.php'; ?>
