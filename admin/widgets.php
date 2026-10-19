<?php
/**
 * Admin — Review Generation Widgets (Monetization #2)
 * Subscription for embeddable 5-star review snippets.
 * Targets: local web design clients, e-commerce stores.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/monetization.php';
require_once dirname(__DIR__) . '/includes/payments.php';
requireLogin();
requireTeamAccess('company');

$tenant_id=getTenantId(); $is_tenant=isTenant();
monetization_ensure_schema($conn); pay_ensure_schema($conn);

$tenant=null; if($tenant_id){ $t=$conn->prepare("SELECT t.*, p.plan_name FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id=p.id WHERE t.id=?"); $t->bind_param("i",$tenant_id); $t->execute(); $tenant=$t->get_result()->fetch_assoc(); $t->close(); }
$company_id=getActiveCompanyId($conn,$tenant_id);
$company=getActiveCompanyProfile($conn,$tenant_id);
$brand=$company['company_name'] ?? $tenant['company_name'] ?? 'Your Business';

$existingWidget=get_widget_config($conn,$tenant_id,$company_id);
$tiers=monetization_widget_tiers();
$plans = sa_query($conn,"SELECT * FROM monetization_plans WHERE stream='widget' AND is_active=1 ORDER BY sort_order ASC",'monetization_plans');

if(($_SERVER['REQUEST_METHOD']??'')==='POST' && !sa_csrf_ok()) { $_SESSION['error']='Session expired.'; header('Location: widgets.php'); exit; }
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  $act=$_POST['action'] ?? '';
  if($act==='buy_widget'){
    $tier=in_array($_POST['tier']??'', ['pro','agency'], true) ? $_POST['tier'] : 'pro';
    $price = $tier==='agency' ? 149 : 79;
    $res=pay_invoice_create($conn,['tenant_id'=>$tenant_id,'purpose'=>'widget','amount'=>$price,'months'=>1,'subject'=>'Review Widget — '.ucfirst($tier).' for '.$brand,'notes'=>json_encode(['stream'=>'widget','company_id'=>$company_id,'tier'=>$tier])]);
    if($res['ok']){
      $iid=(int)$res['id'];
      $key=strtoupper(bin2hex(random_bytes(8)));
      $exp=date('Y-m-d H:i:s', strtotime('+30 days'));
      $st=$conn->prepare("INSERT INTO widget_configs (tenant_id,company_id,widget_key,tier,status,theme,layout,limit_count, expires_at) VALUES (?,?,?,?,?,?, ?,?,?)");
      $theme='light'; $layout='card'; $limit=5; $status='pending';
      $st->bind_param("iissssis s", $tenant_id,$company_id,$key,$tier,$status,$theme,$layout,$limit,$exp);
      // use simpler
      $stmt2=$conn->prepare("INSERT INTO widget_configs (tenant_id, company_id, widget_key, tier, status, theme, layout, limit_count, allowed_domains, expires_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
      if($stmt2){ $lim=5; $dom=''; $stmt2->bind_param("iisssssiss",$tenant_id,$company_id,$key,$tier,$status,$theme,$layout,$lim,$dom,$exp); $stmt2->execute(); $stmt2->close(); 
        // link invoice to widget by updating expires? keep mapping via notes
      }
      // store widget pending referencing invoice
      @$conn->query("UPDATE widget_configs SET api_key='".$conn->real_escape_string($key)."' WHERE widget_key='".$conn->real_escape_string($key)."'");
      header('Location: payment_checkout.php?invoice='.$iid); exit;
    } else { $_SESSION['error']=$res['message']; header('Location: widgets.php'); exit; }
  }
  if($act==='save_widget' && $existingWidget){
    $theme=in_array($_POST['theme']??'',['light','dark','brand'],true)?$_POST['theme']:'light';
    $layout=in_array($_POST['layout']??'',['card','badge','carousel','grid'],true)?$_POST['layout']:'card';
    $limit=max(1,min(20,(int)($_POST['limit_count']??5)));
    $domains=trim($_POST['allowed_domains'] ?? '');
    $hide_low=!empty($_POST['hide_low_rating'])?1:0;
    $verified_only=!empty($_POST['show_verified_only'])?1:0;
    $css=trim($_POST['custom_css'] ?? '');
    // pro/agency only can have custom css
    if(($existingWidget['tier']??'free')==='free' && $css!==''){ $css=''; }
    $st=$conn->prepare("UPDATE widget_configs SET theme=?, layout=?, limit_count=?, hide_low_rating=?, show_verified_only=?, custom_css=?, allowed_domains=? WHERE id=? AND tenant_id=?");
    $st->bind_param("ssiiissii",$theme,$layout,$limit,$hide_low,$verified_only,$css,$domains,$existingWidget['id'],$tenant_id);
    $st->execute(); $st->close();
    $_SESSION['success']='Widget configuration saved — copy the embed code below.';
    header('Location: widgets.php'); exit;
  }
  if($act==='create_free_widget' && !$existingWidget){
    $key=strtoupper(bin2hex(random_bytes(8)));
    $stmt=$conn->prepare("INSERT INTO widget_configs (tenant_id, company_id, widget_key, tier, status, theme, layout, limit_count) VALUES (?,?,?,?,?,?,?,?)");
    $tier='free'; $status='active'; $theme='light'; $layout='card'; $lim=3;
    $stmt->bind_param("iisssssi",$tenant_id,$company_id,$key,$tier,$status,$theme,$layout,$lim);
    $stmt->execute(); $stmt->close();
    $_SESSION['success']='Free widget created — up to 1,000 views/month. Upgrade for unlimited & white-label.';
    header('Location: widgets.php'); exit;
  }
}
$flash_success=$_SESSION['success'] ?? ''; unset($_SESSION['success']);
$flash_error=$_SESSION['error'] ?? ''; unset($_SESSION['error']);

if($existingWidget){
  // refresh after save
  $existingWidget=get_widget_config($conn,$tenant_id,$company_id);
}
$BASE='../'; $pageTitle='Review Widgets'; $activeNav='company';
include __DIR__.'/_shell.php';
?>
<div class="welcome-row" style="margin-bottom:22px;">
  <div>
    <p class="eyebrow">Monetization · Review Generation Widgets</p>
    <h1>Embed 5-Star Reviews on Any Website</h1>
    <p class="muted" style="margin-top:6px;">Sell embeddable review widgets as a subscription — clients paste one line of code and show live 5★ reviews on their own sites. <strong>Targets:</strong> web design clients & e-commerce stores.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="ratings.php" class="btn btn-secondary" style="text-decoration:none;">View Reviews</a>
  </div>
</div>
<?php if($flash_success): ?><div class="alert alert-success"><?php echo htmlspecialchars($flash_success); ?></div><?php endif; ?>
<?php if($flash_error): ?><div class="alert alert-error"><?php echo htmlspecialchars($flash_error); ?></div><?php endif; ?>

<?php if(!$company_id): ?>
<div class="form-card" style="padding:28px;text-align:center;"><p>No company profile.</p><a href="company.php?new=1" class="btn btn-primary" style="text-decoration:none;">Create Company</a></div>
<?php else: ?>

<div class="form-card" style="padding:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
  <div><strong><?php echo htmlspecialchars($brand); ?></strong> <span class="muted">· ID #<?php echo (int)$company_id; ?></span>
    <?php if($existingWidget): ?>
      <span style="margin-left:8px;background:<?php echo $existingWidget['status']==='active'?'#dcfce7;color:#15803d;border:1px solid #86efac':'#fef3c7;color:#92400e;border:1px solid #fde68a'; ?>;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;"><?php echo htmlspecialchars($existingWidget['tier'].' · '.$existingWidget['status']); ?> · <?php echo (int)($existingWidget['views_total'] ?? 0); ?> views</span>
    <?php else: ?>
      <span style="margin-left:8px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;padding:2px 8px;border-radius:99px;font-size:11px;">No widget yet</span>
    <?php endif; ?>
  </div>
  <div style="font-size:12px;color:#64748b;">GHS 79/mo Pro · GHS 149/mo Agency · Free tier 1k views</div>
</div>

<?php if(!$existingWidget): ?>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-top:16px;">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">
    <div style="font-size:11px;font-weight:800;color:#64748b;">FREE</div><div style="font-size:20px;font-weight:800;">GHS 0<span style="font-size:12px;color:#64748b;">/mo</span></div>
    <ul style="font-size:12.5px;color:#334155;margin:10px 0;"> <li>1 widget · 3 reviews</li><li>1,000 views/mo</li><li>Card layout only</li><li>Optibiz badge</li></ul>
    <form method="POST"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="create_free_widget"><button class="btn btn-secondary" style="width:100%;">Start Free →</button></form>
  </div>
  <div style="background:#fff;border:2px solid #6366f1;border-radius:12px;padding:16px;position:relative;">
    <div style="position:absolute;top:10px;right:10px;background:#6366f1;color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:99px;">BEST VALUE</div>
    <div style="font-size:11px;font-weight:800;color:#6366f1;">PRO</div><div style="font-size:20px;font-weight:800;">GHS 79<span style="font-size:12px;color:#64748b;">/mo</span></div>
    <ul style="font-size:12.5px;color:#334155;margin:10px 0;"> <li>Up to 3 widgets · unlimited views</li><li>Carousel & Grid + Card</li><li>Hide low ratings</li><li>Analytics</li></ul>
    <form method="POST"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="buy_widget"><input type="hidden" name="tier" value="pro"><button class="btn btn-primary" style="width:100%;">Get Pro — Pay Now →</button></form>
  </div>
  <div style="background:#111827;color:#e5e7eb;border-radius:12px;padding:16px;">
    <div style="font-size:11px;font-weight:800;color:#c2f542;">AGENCY</div><div style="font-size:20px;font-weight:800;">GHS 149<span style="font-size:12px;color:#9ca3af;">/mo</span></div>
    <ul style="font-size:12.5px;color:#d1d5db;margin:10px 0;"> <li>Unlimited widgets & domains</li><li>White-label (no badge)</li><li>Verified-only filter</li><li>Priority support</li></ul>
    <form method="POST"><?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="buy_widget"><input type="hidden" name="tier" value="agency"><button class="btn" style="width:100%;background:#c2f542;color:#111827;font-weight:800;">Get Agency →</button></form>
  </div>
</div>
<div style="margin-top:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px;font-size:12.5px;color:#1e40af;">
  <strong>How agencies monetize:</strong> Bundle widget hosting into your web design retainer — charge clients GHS 100-200/mo for “reputation widget + reviews page” powered by Optibiz while you pay GHS 79 agency wholesale.
</div>
<?php else: ?>

<?php
  $baseUrl = (defined('APP_BASE_URL') && APP_BASE_URL!=='') ? rtrim(APP_BASE_URL,'/') : '';
  if($baseUrl===''){ $scheme=!empty($_SERVER['HTTPS'])?'https':'http'; $baseUrl=$scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost'); }
  $widgetSrc = $baseUrl . '/widget.php?company='.(int)$company_id.'&key='.$existingWidget['widget_key'];
  $iframeCode = '<iframe src="'.$widgetSrc.'" width="100%" height="340" style="border:0;border-radius:12px;" loading="lazy" title="Customer Reviews for '.htmlspecialchars($brand).'"></iframe>';
  $jsCode = '<div id="optibiz-widget-'.$existingWidget['widget_key'].'"></div>'."\n".'<script src="'.$baseUrl.'/widget.js?key='.$existingWidget['widget_key'].'" async></script>';
?>
<!-- Builder -->
<div style="display:grid;grid-template-columns:1.1fr .9fr;gap:16px;margin-top:16px;">
  <div class="form-card" style="padding:18px;">
    <h3 style="margin:0;">Widget Studio</h3>
    <p class="muted" style="font-size:12.5px;">Configure theme, layout & domain allowlist. Changes apply instantly to embedded widgets.</p>
    <form method="POST" style="margin-top:14px;">
      <?php echo sa_csrf_field(); ?><input type="hidden" name="action" value="save_widget">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div><label style="font-size:12.5px;font-weight:600;">Theme</label>
          <select name="theme" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
            <option value="light" <?php echo $existingWidget['theme']==='light'?'selected':''; ?>>Light</option>
            <option value="dark" <?php echo $existingWidget['theme']==='dark'?'selected':''; ?>>Dark</option>
            <option value="brand" <?php echo $existingWidget['theme']==='brand'?'selected':''; ?>>Brand (lime accent)</option>
          </select></div>
        <div><label style="font-size:12.5px;font-weight:600;">Layout</label>
          <select name="layout" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
            <option value="card" <?php echo $existingWidget['layout']==='card'?'selected':''; ?>>Card</option>
            <option value="badge" <?php echo $existingWidget['layout']==='badge'?'selected':''; ?>>Badge (compact)</option>
            <option value="carousel" <?php echo $existingWidget['layout']==='carousel'?'selected':''; ?>>Carousel</option>
            <option value="grid" <?php echo $existingWidget['layout']==='grid'?'selected':''; ?>>Grid</option>
          </select></div>
        <div><label style="font-size:12.5px;font-weight:600;">Reviews count</label>
          <select name="limit_count" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;">
            <?php foreach([3,5,8,10] as $n): ?><option value="<?php echo $n; ?>" <?php echo (int)$existingWidget['limit_count']===$n?'selected':''; ?>><?php echo $n; ?> reviews</option><?php endforeach; ?>
          </select></div>
        <div><label style="font-size:12.5px;font-weight:600;">Allowed domains (one per line)</label>
          <input type="text" name="allowed_domains" value="<?php echo htmlspecialchars($existingWidget['allowed_domains'] ?? ''); ?>" placeholder="example.com, shop.example.com" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;"></div>
        <div style="grid-column:1/-1;display:flex;gap:14px;margin-top:4px;">
          <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;"><input type="checkbox" name="hide_low_rating" value="1" <?php echo !empty($existingWidget['hide_low_rating'])?'checked':''; ?>> Hide ≤3★</label>
          <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;"><input type="checkbox" name="show_verified_only" value="1" <?php echo !empty($existingWidget['show_verified_only'])?'checked':''; ?>> Verified only</label>
        </div>
        <?php if(in_array($existingWidget['tier'],['pro','agency'])): ?>
        <div style="grid-column:1/-1;">
          <label style="font-size:12.5px;font-weight:600;">Custom CSS (Pro/Agency)</label>
          <textarea name="custom_css" rows="3" placeholder=".obw-card{border-radius:16px}" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-family:monospace;font-size:12px;"><?php echo htmlspecialchars($existingWidget['custom_css'] ?? ''); ?></textarea>
        </div>
        <?php endif; ?>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:12px;">Save Widget Settings</button>
    </form>
  </div>
  <div>
    <div class="form-card" style="padding:16px;margin-bottom:12px;">
      <h4 style="margin:0 0 8px;">Live Preview</h4>
      <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
        <iframe src="<?php echo htmlspecialchars($widgetSrc.'&preview=1'); ?>" style="width:100%;height:360px;border:0;" loading="lazy"></iframe>
      </div>
      <small class="muted">Preview renders with current settings. Embed below updates instantly.</small>
    </div>
    <div class="form-card" style="padding:16px;">
      <h4 style="margin:0 0 8px;">Embed Code — Paste on Client Website</h4>
      <div style="margin-bottom:10px;">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;">Option A — iFrame (simplest)</label>
        <div style="display:flex;gap:6px;margin-top:4px;">
          <input readonly id="codeIframe" value="<?php echo htmlspecialchars($iframeCode); ?>" style="flex:1;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-family:monospace;font-size:11px;background:#f8fafc;">
          <button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('codeIframe').value);this.textContent='Copied!';">Copy</button>
        </div>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;">Option B — JS Snippet (responsive, auto-height)</label>
        <div style="display:flex;gap:6px;margin-top:4px;">
          <textarea readonly id="codeJs" rows="3" style="flex:1;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-family:monospace;font-size:11px;background:#f8fafc;"><?php echo htmlspecialchars($jsCode); ?></textarea>
          <button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('codeJs').value);this.textContent='Copied!';">Copy</button>
        </div>
      </div>
      <div style="margin-top:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 10px;font-size:12px;color:#15803d;">
        Analytics: each widget view is tracked. Upgrade unlocks carousel/grid & verified-only.
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
<?php endif; ?>
<?php include __DIR__.'/_shell_footer.php'; ?>
