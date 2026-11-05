<?php
require_once dirname(__DIR__).'/includes/auth.php';
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/functions.php';
require_once dirname(__DIR__).'/includes/sa_helpers.php';
require_once dirname(__DIR__).'/includes/monetization.php';
require_once dirname(__DIR__).'/includes/sms_providers.php';

requireSuperAdminLogin();
if (!sa_can('sms_gateways', $conn) && !sa_can('gateways', $conn) && !sa_can('finance', $conn) && !sa_can('settings', $conn)) {
    require_sa_permission('sms_gateways', $conn);
}
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
    if($existing){
      if($data['api_key']==='' && !empty($existing['api_key'])) $data['api_key']=$existing['api_key'];
      if($data['api_secret']==='' && !empty($existing['api_secret'])) $data['api_secret']=$existing['api_secret'];
    }
    sms_provider_save($conn,$key,$data);
    sa_flash('success',($data['display_name'] ?: $key).' settings saved successfully.');
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
  if($act==='send_test_sms'){
    $key=preg_replace('/[^a-z_]/','', $_POST['provider_key'] ?? '');
    $phone=trim($_POST['test_phone'] ?? '');
    $msg=trim($_POST['test_message'] ?? 'Optibiz test SMS: gateway connection is active and working perfectly.');
    if(!$phone){
      sa_flash('error','Please enter a destination phone number (e.g., 0241234567 or +233241234567).');
      redirect('sms_providers.php');
    }
    $res=sms_send_via_provider($conn,$key,$phone,$msg);
    if($res['ok']){
      @$conn->query("UPDATE sms_providers SET connection_status='verified', connection_note='Live test delivered: ".$conn->real_escape_string($res['provider_id'] ?? 'OK')."', last_tested_at=NOW() WHERE provider_key='".$conn->real_escape_string($key)."'");
      sa_flash('success','Live test SMS sent successfully! Provider message ID: '.htmlspecialchars($res['provider_id'] ?? 'OK'));
    } else {
      @$conn->query("UPDATE sms_providers SET connection_status='failed', connection_note='Live test failed: ".$conn->real_escape_string($res['message'])."', last_tested_at=NOW() WHERE provider_key='".$conn->real_escape_string($key)."'");
      sa_flash('error','Live test SMS failed: '.htmlspecialchars($res['message'] ?? 'Gateway returned an error.'));
    }
    redirect('sms_providers.php');
  }
}

$providers=sms_provider_list($conn);
if(empty($providers)){
  monetization_ensure_schema($conn);
  $catalog = monetization_sms_catalog();
  foreach ($catalog as $key => $meta) {
      $name = $conn->real_escape_string($meta['label']);
      $note = $conn->real_escape_string($meta['summary']);
      $url = $conn->real_escape_string($meta['api_url']);
      $order = (int)$meta['order'];
      @$conn->query("INSERT INTO sms_providers (provider_key, display_name, is_enabled, api_url, sender_id, connection_status, connection_note, sort_order) VALUES ('".$conn->real_escape_string($key)."','".$name."',0,'".$url."','Optibiz','unverified','".$note."',".$order.") ON DUPLICATE KEY UPDATE display_name=VALUES(display_name)");
  }
  $providers=sms_provider_list($conn);
}

$active_count = 0;
foreach($providers as $p){ if(!empty($p['is_enabled'])) $active_count++; }

$robots='noindex, nofollow'; $pageTitle='SMS Providers'; $pageHeading='Local SMS Gateways'; $pageSubtitle='Configure Ghana SMS APIs — Hubtel, mNotify, Africa\'s Talking'; $activePage='sms_gateways'; $BASE='../'; $extraCss=['assets/css/superadmin.css']; $bodyClass='sa-body';
include dirname(__DIR__).'/includes/header.php'; include __DIR__.'/_shell.php';
?>
<div class="sa-page-head">
  <div>
    <div class="sa-crumbs">
      <a href="index.php">Super admin</a> <?php echo sa_icon('chevron-right'); ?>
      <a href="payment_gateways.php">Integrations</a> <?php echo sa_icon('chevron-right'); ?>
      <span>SMS Gateways</span>
    </div>
    <h2>Local SMS API Gateways</h2>
    <p>Configure automated review invite delivery via Hubtel, mNotify, and Africa's Talking</p>
  </div>
  <div class="sa-head-actions">
    <a class="sa-btn sa-btn-secondary" href="payment_gateways.php"><?php echo sa_icon('zap'); ?> Payment Integrations</a>
    <a class="sa-btn sa-btn-ghost" href="settings.php#tab=sms"><?php echo sa_icon('settings'); ?> Settings</a>
    <a class="sa-btn sa-btn-ghost" href="monetization.php"><?php echo sa_icon('dollar'); ?> Monetization</a>
  </div>
</div>
<?php echo sa_render_flash(); ?>

<!-- Posture KPIs -->
<div class="sa-grid sa-kpis sa-anim" style="margin-bottom:16px;">
  <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
    <div class="sa-kpi-top">
      <span class="sa-kpi-label">Active SMS Gateways</span>
      <span class="sa-kpi-icon"><?php echo sa_icon('zap'); ?></span>
    </div>
    <div class="sa-kpi-value"><?php echo sa_num($active_count); ?> <small>of <?php echo sa_num(count($providers)); ?> available</small></div>
    <div class="sa-kpi-note"><?php echo $active_count ? 'Tenants can auto-dispatch SMS invites' : 'Manual mode active (all campaigns logged without API dispatch)'; ?></div>
  </article>
  <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
    <div class="sa-kpi-top">
      <span class="sa-kpi-label">Primary Gateway</span>
      <span class="sa-kpi-icon"><?php echo sa_icon('phone'); ?></span>
    </div>
    <div class="sa-kpi-value" style="font-size:18px;">
      <?php 
        $active_names = [];
        foreach($providers as $pr) { if(!empty($pr['is_enabled'])) $active_names[] = $pr['display_name']; }
        echo !empty($active_names) ? sa_e(implode(', ', array_slice($active_names, 0, 2))) : 'Manual / Log only';
      ?>
    </div>
    <div class="sa-kpi-note">Hubtel &amp; mNotify recommended for 95%+ Ghana delivery</div>
  </article>
  <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
    <div class="sa-kpi-top">
      <span class="sa-kpi-label">Resell Margin</span>
      <span class="sa-kpi-icon"><?php echo sa_icon('dollar'); ?></span>
    </div>
    <div class="sa-kpi-value">30% &ndash; 50%</div>
    <div class="sa-kpi-note">Cost: GHS 0.25&ndash;0.35 &middot; Retail: GHS 0.40&ndash;0.50/credit</div>
  </article>
</div>

<div class="sa-card" style="padding:16px;margin-bottom:14px;background:#eff6ff;border:1px solid #bfdbfe;">
  <strong style="font-size:13px;color:#1d4ed8;">Ghana-first routing:</strong>
  <span style="font-size:12.5px;color:#334155;">Hubtel &amp; mNotify deliver 95%+ to MTN, Telecel (Vodafone) &amp; AT within 8s. Africa's Talking provides pan-African fallback. Configure your API credentials below. When enabled, tenant review collection campaigns automatically dispatch through your active gateways.</span>
</div>

<?php foreach($providers as $pr): 
  $catalog = monetization_sms_catalog();
  $meta = $catalog[$pr['provider_key']] ?? ['region'=>'Ghana'];
?>
<section class="sa-card" style="padding:18px;margin-bottom:14px;<?php echo empty($pr['is_enabled'])?'opacity:.92':''; ?>">
  <form method="POST">
    <?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="save_provider"><input type="hidden" name="provider_key" value="<?php echo htmlspecialchars($pr['provider_key']); ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
      <div>
        <div style="display:flex;align-items:center;gap:10px;">
          <strong style="font-size:16px;"><?php echo htmlspecialchars($pr['display_name']); ?></strong>
          <span style="font-size:11px;background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:99px;"><?php echo htmlspecialchars($meta['region'] ?? 'Ghana'); ?></span>
          <?php echo $pr['is_enabled']?'<span style="font-size:11px;background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:2px 8px;border-radius:99px;font-weight:600;">Enabled</span>':'<span style="font-size:11px;background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:99px;">Disabled</span>'; ?>
          <?php if(!empty($pr['connection_status']) && $pr['connection_status']!=='unverified'): ?><span style="font-size:11px;padding:2px 8px;border-radius:99px;font-weight:600;background:<?php echo $pr['connection_status']==='verified'?'#dcfce7;color:#15803d':'#fee2e2;color:#991b1b'; ?>"><?php echo htmlspecialchars($pr['connection_status']); ?></span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:#64748b;margin-top:4px;"><?php echo htmlspecialchars($meta['summary'] ?? ''); ?></div>
        <?php if(!empty($pr['connection_note'])): ?><div style="font-size:12px;color:#475569;margin-top:4px;">ⓘ <?php echo htmlspecialchars($pr['connection_note']); ?></div><?php endif; ?>
      </div>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;"><input type="checkbox" name="is_enabled" value="1" <?php echo !empty($pr['is_enabled'])?'checked':''; ?>> Enabled for tenants</label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
      <div><label style="font-size:12px;font-weight:600;">Display Name</label><input type="text" name="display_name" value="<?php echo htmlspecialchars($pr['display_name']); ?>" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
      <div><label style="font-size:12px;font-weight:600;">Sender ID (NCA Registered, max 11 chars)</label><input type="text" name="sender_id" maxlength="11" value="<?php echo htmlspecialchars($pr['sender_id'] ?? 'Optibiz'); ?>" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
      <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:600;">API URL / Endpoint</label><input type="text" name="api_url" value="<?php echo htmlspecialchars($pr['api_url'] ?? ''); ?>" placeholder="https://..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
      <div><label style="font-size:12px;font-weight:600;">API Key / Username / ClientID</label><input type="text" name="api_key" value="<?php echo !empty($pr['api_key'])?'••••••••'.substr($pr['api_key'],-4):''; ?>" placeholder="Paste new key (blank keeps existing)" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
      <div><label style="font-size:12px;font-weight:600;">API Secret / Secret Hash / Token</label><input type="text" name="api_secret" value="<?php echo !empty($pr['api_secret'])?'••••••••'.substr($pr['api_secret'],-4):''; ?>" placeholder="Paste new secret (blank keeps existing)" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
    </div>
    <div style="display:flex;gap:8px;margin-top:12px;">
      <button type="submit" class="sa-btn sa-btn-primary" style="font-size:13px;">Save <?php echo htmlspecialchars($pr['display_name']); ?></button>
    </div>
  </form>

  <div style="display:flex;align-items:center;gap:12px;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;flex-wrap:wrap;">
    <form method="POST" style="margin:0;">
      <?php echo sa_csrf_field(); ?>
      <input type="hidden" name="action" value="test_provider">
      <input type="hidden" name="provider_key" value="<?php echo htmlspecialchars($pr['provider_key']); ?>">
      <button type="submit" class="sa-btn sa-btn-secondary" style="font-size:12px;">Verify Credentials</button>
    </form>

    <?php if($pr['provider_key'] !== 'manual'): ?>
    <details style="margin:0;">
      <summary style="font-size:12px;color:#2563eb;cursor:pointer;font-weight:600;">Send Live Test SMS to Phone &darr;</summary>
      <form method="POST" style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="send_test_sms">
        <input type="hidden" name="provider_key" value="<?php echo htmlspecialchars($pr['provider_key']); ?>">
        <input type="text" name="test_phone" placeholder="024XXXXXXX or +233..." style="padding:6px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;width:170px;" required>
        <button type="submit" class="sa-btn sa-btn-primary" style="font-size:12px;padding:6px 12px;">Send Test SMS</button>
      </form>
    </details>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<div class="sa-card" style="padding:16px;background:#fffbeb;border:1px solid #fde68a;">
  <strong style="font-size:13px;color:#92400e;">How to get Gateway API Keys:</strong>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:10px;font-size:12.5px;color:#78350f;">
    <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #fef3c7;">
      <strong>Hubtel SMS:</strong>
      <ol style="margin:6px 0 0 16px;padding:0;line-height:1.5;">
        <li>Sign up at <a href="https://hubtel.com" target="_blank" style="color:#b45309;font-weight:600;">hubtel.com</a></li>
        <li>SMS &rarr; Settings &rarr; API Keys</li>
        <li>Paste ClientID in API Key and Secret in API Secret</li>
        <li>Sender ID approval takes 24h from NCA</li>
      </ol>
    </div>
    <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #fef3c7;">
      <strong>mNotify SMS:</strong>
      <ol style="margin:6px 0 0 16px;padding:0;line-height:1.5;">
        <li>Sign up at <a href="https://mnotify.com" target="_blank" style="color:#b45309;font-weight:600;">mnotify.com</a></li>
        <li>Developers &rarr; API &rarr; Copy API Key</li>
        <li>Paste in API Key above (leave secret blank)</li>
        <li>Register custom Sender ID in mNotify dashboard</li>
      </ol>
    </div>
    <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #fef3c7;">
      <strong>Africa's Talking:</strong>
      <ol style="margin:6px 0 0 16px;padding:0;line-height:1.5;">
        <li>Sign up at <a href="https://africastalking.com" target="_blank" style="color:#b45309;font-weight:600;">africastalking.com</a></li>
        <li>App settings &rarr; API Key (Secret)</li>
        <li>Put app username in API Key, API key in Secret</li>
        <li>Great for fallback outside Ghana</li>
      </ol>
    </div>
  </div>
</div>
<?php include __DIR__.'/_shell_footer.php'; ?>
