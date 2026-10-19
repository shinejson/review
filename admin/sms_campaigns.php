<?php
/**
 * Admin — Automated Review Collection via Local SMS APIs
 * Charge fee to auto-send review links via Hubtel, mNotify, Africa's Talking.
 * Targets: plumbers, restaurants, delivery services.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/monetization.php';
require_once dirname(__DIR__) . '/includes/sms_providers.php';
require_once dirname(__DIR__) . '/includes/payments.php';
requireLogin(); requireTeamAccess('whatsapp');

$tenant_id=getTenantId(); $is_tenant=isTenant();
monetization_ensure_schema($conn); pay_ensure_schema($conn);

$tenant=null; if($tenant_id){ $t=$conn->prepare("SELECT * FROM tenants WHERE id=?"); $t->bind_param("i",$tenant_id); $t->execute(); $tenant=$t->get_result()->fetch_assoc(); $t->close(); }
$company_id=getActiveCompanyId($conn,$tenant_id);
$company=getActiveCompanyProfile($conn,$tenant_id);
$brand=$company['company_name'] ?? $tenant['company_name'] ?? 'Your Business';
$base_url = getCompanyPublicRatingUrl($company_id, $brand);

$wallet=get_sms_wallet($conn,$tenant_id);
$providers=sms_provider_list($conn);
$enabledProviders=sms_provider_list($conn,true);
$plans=sa_query($conn,"SELECT * FROM monetization_plans WHERE stream='sms' AND is_active=1 ORDER BY sort_order ASC",'monetization_plans');

// Handle POST
if(($_SERVER['REQUEST_METHOD']??'')==='POST' && !sa_csrf_ok()){ $_SESSION['error']='Session expired.'; header('Location: sms_campaigns.php'); exit; }
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  $act=$_POST['action'] ?? '';
  if($act==='buy_credits'){
    $code=preg_replace('/[^a-z0-9_]/','', $_POST['plan_code'] ?? '');
    $plan=sa_one($conn,"SELECT * FROM monetization_plans WHERE plan_code='".$conn->real_escape_string($code)."' LIMIT 1",'monetization_plans');
    if(!$plan){ $_SESSION['error']='Plan not found.'; header('Location: sms_campaigns.php'); exit; }
    $price=(float)$plan['price'];
    $creditsMap=['sms_100'=>100,'sms_500'=>500,'sms_2000'=>2000];
    $credits=(int)($creditsMap[$plan['plan_code']] ?? 100);
    $res=pay_invoice_create($conn,['tenant_id'=>$tenant_id,'purpose'=>'sms_credits','amount'=>$price,'months'=>1,'subject'=>'SMS Credits — '.$plan['plan_name'].' for '.$brand,'notes'=>json_encode(['stream'=>'sms','credits'=>$credits,'plan_code'=>$code])]);
    if($res['ok']){
      $iid=(int)$res['id'];
      $st=$conn->prepare("INSERT INTO sms_campaigns (tenant_id, company_id, provider_key, campaign_name, message_body, recipient_count, status, cost, credits_used, scheduled_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
      $pk=''; $name='CREDIT PURCHASE: '.$plan['plan_name']; $msg=''; $rc=$credits; $status='draft'; $cost=$price;
      $st->bind_param("iissisidd",$tenant_id,$company_id,$pk,$name,$msg,$rc,$status,$cost,$rc);
      $st->execute(); $st->close();
      // store invoice id for activation
      $lastId=$conn->insert_id;
      @$conn->query("UPDATE sms_campaigns SET invoice_id=".$iid." WHERE id=".(int)$lastId);
      header('Location: payment_checkout.php?invoice='.$iid); exit;
    } else { $_SESSION['error']=$res['message']; header('Location: sms_campaigns.php'); exit; }
  }
  if($act==='create_campaign'){
    $name=sanitize($_POST['campaign_name'] ?? 'Review Invite Campaign');
    $provider=preg_replace('/[^a-z_]/','', $_POST['provider_key'] ?? '');
    $template=sanitize($_POST['template_key'] ?? 'default');
    $audience=sanitize($_POST['audience_filter'] ?? 'manual');
    $phones_raw=trim($_POST['recipients_raw'] ?? '');
    $custom_msg=trim($_POST['custom_message'] ?? '');
    // parse phones: comma/newline
    $phones=array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $phones_raw))));
    $phones=array_map(function($p){ return sms_normalize_phone($p); }, $phones);
    $phones=array_values(array_unique(array_filter($phones, fn($p)=>strlen($p)>=8)));
    // also allow CSV upload? For now raw field
    if(!$phones){ $_SESSION['error']='Enter at least one valid phone number.'; header('Location: sms_campaigns.php'); exit; }
    $count=count($phones);
    // per-SMS cost derived from wallet average or default 0.50
    $perSms = 0.50;
    if($wallet['balance'] > 0) $perSms = 0.40;
    $cost = round($count * $perSms, 2);
    // Check wallet balance
    if((int)$wallet['balance'] < $count){
      $_SESSION['error']='Not enough SMS credits. You have '.(int)$wallet['balance'].' but need '.$count.'. Buy credits first.';
      header('Location: sms_campaigns.php'); exit;
    }
    $msg = $custom_msg !== '' ? $custom_msg : sms_build_review_message($brand, $base_url, $template);
    // Keep message with placeholder logic — actual per-recipient personalization done at send
    $recipients_json=json_encode($phones);
    $st=$conn->prepare("INSERT INTO sms_campaigns (tenant_id, company_id, provider_key, campaign_name, message_body, template_key, recipient_count, recipients_json, audience_filter, status, cost, credits_used, sms_price_per) VALUES (?,?,?,?,?,?,?,?,?,?, ?,?,?)");
    $status='queued'; $credits=$count;
    $st->bind_param("iisssisssiddi",$tenant_id,$company_id,$provider,$name,$msg,$template,$count,$recipients_json,$audience,$status,$cost,$credits,$perSms);
    $st->execute(); $cid=(int)$conn->insert_id; $st->close();
    // deduct from wallet
    @$conn->query("UPDATE sms_credits_wallet SET balance=balance-".(int)$count.", total_used=total_used+".(int)$count." WHERE tenant_id=".(int)$tenant_id);
    // queue delivery logs
    foreach($phones as $ph){
      $log=$conn->prepare("INSERT INTO sms_delivery_log (campaign_id, tenant_id, company_id, provider_key, recipient_phone, message_body, status, cost) VALUES (?,?,?,?,?,?,?,?)");
      $stt='queued'; $c=$perSms; $log->bind_param("iiissssd",$cid,$tenant_id,$company_id,$provider,$ph,$msg,$stt,$c); $log->execute(); $log->close();
    }
    // attempt immediate send via provider (if enabled)
    $providerRow=sms_provider($conn,$provider);
    $sent=0; $failed=0;
    if($providerRow && !empty($providerRow['is_enabled'])){
      $logs=sa_query($conn,"SELECT * FROM sms_delivery_log WHERE campaign_id=".$cid,'sms_delivery_log');
      foreach($logs as $lg){
        $res=sms_send_via_provider($conn,$provider,$lg['recipient_phone'],$msg);
        if($res['ok']){ @$conn->query("UPDATE sms_delivery_log SET status='sent', provider_message_id='".$conn->real_escape_string($res['provider_id'] ?? '')."', sent_at=NOW() WHERE id=".(int)$lg['id']); $sent++; }
        else { @$conn->query("UPDATE sms_delivery_log SET status='failed', failure_reason='".$conn->real_escape_string($res['message'])."' WHERE id=".(int)$lg['id']); $failed++; }
        usleep(120000); // 120ms between sends to respect rate
      }
      @$conn->query("UPDATE sms_campaigns SET status='".($failed? 'sent' : 'sent')."', sent_at=NOW() WHERE id=".$cid);
      $_SESSION['success']="Campaign queued — sent $sent, failed $failed via ".htmlspecialchars($providerRow['display_name']).". Credits deducted: $count.";
    } else {
      @$conn->query("UPDATE sms_campaigns SET status='sent', sent_at=NOW() WHERE id=".$cid);
      // mark all logs as sent (manual/log mode)
      @$conn->query("UPDATE sms_delivery_log SET status='sent', sent_at=NOW() WHERE campaign_id=".$cid);
      $_SESSION['success']="Campaign logged in manual mode — $count SMS marked as sent. Configure an SMS gateway (Hubtel/mNotify) for real delivery.";
    }
    header('Location: sms_campaigns.php'); exit;
  }
}

$flash_success=$_SESSION['success'] ?? ''; unset($_SESSION['success']);
$flash_error=$_SESSION['error'] ?? ''; unset($_SESSION['error']);

$campaigns=sa_query($conn,"SELECT * FROM sms_campaigns WHERE tenant_id=".(int)$tenant_id." ORDER BY created_at DESC LIMIT 20",'sms_campaigns');
$recentLogs=sa_query($conn,"SELECT * FROM sms_delivery_log WHERE tenant_id=".(int)$tenant_id." ORDER BY created_at DESC LIMIT 50",'sms_delivery_log');

$BASE='../'; $pageTitle='SMS Review Collection'; $activeNav='whatsapp_sender';
include __DIR__.'/_shell.php';
?>
<div class="welcome-row" style="margin-bottom:22px;">
  <div>
    <p class="eyebrow">Monetization · Automated Review Collection</p>
    <h1>Send Review Links via Local SMS</h1>
    <p class="muted" style="margin-top:6px;">Automatically send review links to your customer list after each transaction via local SMS APIs (Hubtel, mNotify, Africa's Talking). <strong>Perfect for:</strong> plumbers, restaurants, delivery teams.</p>
  </div>
  <div style="display:flex;gap:8px;"><a href="whatsapp_sender.php" class="btn btn-secondary" style="text-decoration:none;">WhatsApp Sender</a></div>
</div>
<?php if($flash_success): ?><div class="alert alert-success"><?php echo htmlspecialchars($flash_success); ?></div><?php endif; ?>
<?php if($flash_error): ?><div class="alert alert-error"><?php echo htmlspecialchars($flash_error); ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-bottom:16px;">
  <div class="metric-card"><div class="metric-icon amber">💬</div><span>SMS Balance</span><strong><?php echo number_format((int)$wallet['balance']); ?></strong><small>credits available</small></div>
  <div class="metric-card"><div class="metric-icon green">✓</div><span>Total Sent</span><strong><?php echo number_format((int)$wallet['total_used']); ?></strong><small>all campaigns</small></div>
  <div class="metric-card"><div class="metric-icon purple">◈</div><span>Providers</span><strong><?php echo count($enabledProviders); ?> active</strong><small>of <?php echo count($providers); ?> configured</small></div>
  <div class="metric-card"><div class="metric-icon lime">⚡</div><span>Cost/SMS</span><strong>GHS 0.30–0.50</strong><small>via Hubtel/mNotify</small></div>
</div>

<?php if(!$company_id): ?>
<div class="form-card" style="padding:22px;text-align:center;">No company profile. <a href="company.php?new=1" class="btn btn-primary" style="text-decoration:none;">Create Company</a></div>
<?php else: ?>

<!-- Buy credits -->
<div class="form-card" style="padding:18px;">
  <h3 style="margin:0;">Top-Up SMS Credits</h3>
  <p class="muted" style="font-size:12.5px;">One credit = one SMS. Pay once, use anytime. Credits never expire.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px;">
    <?php foreach($plans as $pl): $creditsMap=['sms_100'=>100,'sms_500'=>500,'sms_2000'=>2000]; $cr=$creditsMap[$pl['plan_code']] ?? 100; $per= round((float)$pl['price']/$cr,2); ?>
    <div style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;background:#fff;">
      <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;"><?php echo htmlspecialchars($pl['plan_name']); ?></div>
      <div style="font-size:18px;font-weight:800;">GHS <?php echo number_format((float)$pl['price'],0); ?> <span style="font-size:11px;color:#64748b;">for <?php echo $cr; ?> SMS</span></div>
      <div style="font-size:12px;color:#334155;">GHS <?php echo number_format($per,2); ?>/SMS · <?php echo htmlspecialchars($pl['features']); ?></div>
      <form method="POST" style="margin-top:8px;"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="buy_credits"><input type="hidden" name="plan_code" value="<?php echo htmlspecialchars($pl['plan_code']); ?>"><button class="btn btn-primary" style="width:100%;">Buy <?php echo $cr; ?> Credits →</button></form>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- New campaign -->
<div style="display:grid;grid-template-columns:1.1fr .9fr;gap:16px;margin-top:16px;">
  <div class="form-card" style="padding:18px;">
    <h3 style="margin:0;">Create SMS Review Campaign</h3>
    <p class="muted" style="font-size:12.5px;">We auto-send via your configured local provider. Review link is appended automatically.</p>
    <form method="POST">
      <?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="create_campaign">
      <label style="font-size:12.5px;font-weight:600;">Campaign name</label>
      <input type="text" name="campaign_name" placeholder="e.g., Post-delivery review asks — Sept" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;margin:6px 0 10px;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div><label style="font-size:12.5px;font-weight:600;">SMS provider</label>
          <select name="provider_key" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
            <?php foreach($providers as $pr): ?><option value="<?php echo htmlspecialchars($pr['provider_key']); ?>" <?php echo !empty($pr['is_enabled'])?'':'disabled'; ?>><?php echo htmlspecialchars($pr['display_name']); ?><?php echo empty($pr['is_enabled'])?' (not enabled)':''; ?></option><?php endforeach; ?>
            <option value="manual">Manual / Log only</option>
          </select></div>
        <div><label style="font-size:12.5px;font-weight:600;">Template</label>
          <select name="template_key" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
            <option value="default">Friendly default</option>
            <option value="gh_local">Ghana local (Medaase!)</option>
            <option value="after_delivery">After delivery 📦</option>
            <option value="after_visit">After visit</option>
            <option value="nps">NPS-style</option>
          </select></div>
      </div>
      <label style="font-size:12.5px;font-weight:600;margin-top:10px;display:block;">Recipients — phone numbers (comma or newline separated)</label>
      <textarea name="recipients_raw" rows="6" placeholder="0241234567, 0201234567, +233241234567
Paste or upload CSV numbers — one per line" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-family:monospace;font-size:12px;margin-top:6px;"></textarea>
      <label style="font-size:12.5px;font-weight:600;margin-top:10px;display:block;">Custom message (optional — leave blank for template)</label>
      <textarea name="custom_message" rows="3" placeholder="Hi {name}, thanks for choosing {business}! Rate us: {link}" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:12px;margin-top:6px;"></textarea>
      <small class="muted">Placeholders: {name}, {business}, {link} — {link} becomes <?php echo htmlspecialchars($base_url); ?></small>
      <div style="margin-top:10px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;font-size:12px;color:#92400e;">
        Credits needed = number of valid phones. You have <strong><?php echo (int)$wallet['balance']; ?></strong> credits. Each SMS queued instantly; delivery receipts appear below.
      </div>
      <button class="btn btn-primary" style="margin-top:12px;width:100%;">Queue & Send SMS Campaign →</button>
    </form>
  </div>
  <div>
    <div class="form-card" style="padding:16px;">
      <h4 style="margin:0 0 6px;">How plumbers & restaurants use this</h4>
      <ul style="font-size:12.5px;color:#334155;line-height:1.6;">
        <li><strong>After each job:</strong> export today's phone numbers from receipts/logbook, paste here, send in one tap.</li>
        <li><strong>Automated trigger (coming):</strong> connect your POS/app via webhook — every closed transaction auto-queues an SMS with your review link.</li>
        <li><strong>Conversion:</strong> SMS open rate ~85% in Ghana vs 15% email. Expect 12–18% review conversion with follow-up reminder after 48h.</li>
      </ul>
      <div style="margin-top:10px;padding:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:12px;color:#065f46;">
        Tip: add STOP opt-out automatically — our templates include it. Manage opt-outs via delivery logs.
      </div>
      <div style="margin-top:12px;">
        <a href="../superadmin/sms_providers.php" style="font-size:12px;color:#2563eb;text-decoration:none;">Super Admin: configure Hubtel/mNotify API keys →</a>
      </div>
    </div>
    <div class="form-card" style="padding:16px;margin-top:12px;">
      <h4 style="margin:0 0 8px;">Recent Campaigns</h4>
      <?php if($campaigns): foreach(array_slice($campaigns,0,5) as $c): ?>
        <div style="padding:8px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
          <div><strong style="font-size:13px;"><?php echo htmlspecialchars($c['campaign_name']); ?></strong><div style="font-size:11px;color:#64748b;"><?php echo (int)$c['recipient_count']; ?> recipients · <?php echo htmlspecialchars($c['provider_key'] ?: 'manual'); ?> · <?php echo htmlspecialchars($c['status']); ?></div></div>
          <span style="font-size:11px;background:#f1f5f9;padding:3px 8px;border-radius:99px;"><?php echo date('M j', strtotime($c['created_at'])); ?></span>
        </div>
      <?php endforeach; else: ?><p class="muted" style="font-size:12.5px;">No campaigns yet.</p><?php endif; ?>
    </div>
  </div>
</div>

<div class="form-card" style="padding:18px;margin-top:16px;">
  <h3 style="margin:0 0 10px;">Delivery Log (last 50)</h3>
  <?php if($recentLogs): ?>
  <div style="overflow-x:auto;">
    <table style="width:100%;font-size:12.5px;border-collapse:collapse;">
      <thead><tr style="background:#f8fafc;color:#64748b;"><th style="padding:6px 8px;text-align:left;">To</th><th style="padding:6px 8px;text-align:left;">Campaign</th><th style="padding:6px 8px;">Status</th><th style="padding:6px 8px;text-align:right;">Cost</th><th style="padding:6px 8px;text-align:left;">Sent At</th></tr></thead>
      <tbody>
        <?php foreach($recentLogs as $l): ?>
        <tr style="border-top:1px solid #e2e8f0;"><td style="padding:6px 8px;font-family:monospace;"><?php echo htmlspecialchars($l['recipient_phone']); ?></td><td style="padding:6px 8px;">#<?php echo (int)$l['campaign_id']; ?></td><td style="padding:6px 8px;"><span style="padding:2px 7px;border-radius:99px;font-size:11px;background:<?php echo $l['status']==='sent'?'#dcfce7;color:#15803d':($l['status']==='failed'?'#fee2e2;color:#b91c1c':'#fef3c7;color:#92400e'); ?>"><?php echo htmlspecialchars($l['status']); ?></span></td><td style="padding:6px 8px;text-align:right;"><?php echo number_format((float)$l['cost'],2); ?></td><td style="padding:6px 8px;font-size:11px;"><?php echo !empty($l['sent_at'])?date('M j H:i', strtotime($l['sent_at'])):'—'; ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?><p class="muted" style="font-size:13px;">No SMS sent yet — your history will appear here with delivery receipts.</p><?php endif; ?>
</div>

<?php endif; ?>
<?php include __DIR__.'/_shell_footer.php'; ?>
