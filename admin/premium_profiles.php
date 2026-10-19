<?php
/**
 * Admin — B2B Premium Profiles
 * Claim your business, remove competitor ads, pin reviews, custom banners.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/monetization.php';
require_once dirname(__DIR__) . '/includes/payments.php';
requireLogin();
requireTeamAccess('company');

$tenant_id = getTenantId();
$is_tenant = isTenant();
monetization_ensure_schema($conn);
pay_ensure_schema($conn);

// Tenant info
$tenant = null;
if ($tenant_id) {
    $t = $conn->prepare("SELECT t.*, p.plan_name FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id=p.id WHERE t.id=?");
    $t->bind_param("i", $tenant_id); $t->execute(); $tenant = $t->get_result()->fetch_assoc(); $t->close();
}
$companies = [];
if ($tenant_id) {
    $cp = $conn->prepare("SELECT * FROM customers WHERE tenant_id=? ORDER BY id ASC");
    $cp->bind_param("i", $tenant_id); $cp->execute(); $res=$cp->get_result(); while($r=$res->fetch_assoc()) $companies[]=$r; $cp->close();
}
$activeId = getActiveCompanyId($conn, $tenant_id);
$active = null; foreach($companies as $c) if((int)$c['id']===(int)$activeId) $active=$c;
if(!$active) $active=$companies[0] ?? null;
$company_id = (int)($active['id'] ?? 0);

// handle claim / premium purchase
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    if (!sa_csrf_ok()) { $_SESSION['error']='Session expired.'; header('Location: premium_profiles.php'); exit; }
    $action = $_POST['action'] ?? '';
    if ($action==='claim' && $company_id) {
        $method = sanitize($_POST['claim_method'] ?? 'email_domain');
        $email = sanitize($_POST['claim_email'] ?? ($tenant['email'] ?? ''));
        $phone = sanitize($_POST['claim_phone'] ?? ($tenant['phone'] ?? ''));
        // create claim
        $token = bin2hex(random_bytes(16));
        $code = str_pad((string)random_int(100000,999999),6,'0',STR_PAD_LEFT);
        $stmt=$conn->prepare("INSERT INTO premium_claims (company_id, claimant_tenant_id, claimant_name, claimant_email, claimant_phone, claim_method, verification_token, verification_code) VALUES (?,?,?,?,?,?,?,?)");
        $cname = $active['company_name'] ?? $tenant['company_name'];
        $stmt->bind_param("iissssss", $company_id, $tenant_id, $cname, $email, $phone, $method, $token, $code);
        $stmt->execute(); $stmt->close();
        $_SESSION['success']='Claim submitted! Verification code: '.$code.' — Our team will verify within 24h. You can upgrade to Premium while we verify.';
        header('Location: premium_profiles.php'); exit;
    }
    if ($action==='buy_premium' && $company_id) {
        $tier = in_array($_POST['tier'] ?? '', ['essential','growth','authority'], true) ? $_POST['tier'] : 'essential';
        $cycle = (($_POST['cycle'] ?? 'monthly')==='yearly') ? 'yearly' : 'monthly';
        $tiers = monetization_premium_tiers();
        $price = (float)($tiers[$tier]['price'] ?? 149);
        if ($cycle==='yearly') $price = round($price * 12 * 0.80, 2); // 20% off yearly
        $months = $cycle==='yearly'?12:1;
        // create invoice
        $res = pay_invoice_create($conn, [
            'tenant_id'=>$tenant_id,
            'purpose'=>$cycle==='yearly'?'premium_renewal':'premium_claim',
            'amount'=>$price,
            'months'=>$months,
            'subject'=>'Premium Profile — '.ucfirst($tier).' ('.($cycle==='yearly'?'Yearly':'Monthly').') for '.($active['company_name'] ?? 'Business'),
            'notes'=>json_encode(['stream'=>'premium','company_id'=>$company_id,'tier'=>$tier,'cycle'=>$cycle]),
        ]);
        if ($res['ok']) {
            $iid=(int)$res['id'];
            // create pending subscription
            $st=$conn->prepare("INSERT INTO premium_subscriptions (company_id, tenant_id, tier, status, price, currency, billing_cycle, invoice_id, starts_at, expires_at) VALUES (?,?,?,?,?,?,?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH))");
            $curr='GHS'; $status='pending';
            $st->bind_param("iisdssiii", $company_id, $tenant_id, $tier, $status, $price, $curr, $cycle, $iid, $months);
            $st->execute(); $st->close();
            header('Location: payment_checkout.php?invoice='.$iid); exit;
        } else {
            $_SESSION['error']=$res['message'];
            header('Location: premium_profiles.php'); exit;
        }
    }
    if ($action==='update_premium_assets' && $company_id) {
        $banner = trim($_POST['custom_banner_url'] ?? '');
        $cta_label = sanitize($_POST['cta_label'] ?? '');
        $cta_url = cleanMapUrl($_POST['cta_url'] ?? '');
        $pinned = (int)($_POST['pinned_review_id'] ?? 0);
        // only allow if premium active
        $tierCheck = is_premium_company($conn, $company_id);
        if (!$tierCheck) {
            $_SESSION['error']='Upgrade to Premium to unlock custom banners and pinned reviews.';
        } else {
            $stmt=$conn->prepare("UPDATE customers SET custom_banner_url=?, custom_cta_label=?, custom_cta_url=?, pinned_review_id=? WHERE id=? AND tenant_id=?");
            $stmt->bind_param("sssiii", $banner, $cta_label, $cta_url, $pinned, $company_id, $tenant_id);
            $stmt->execute(); $stmt->close();
            $_SESSION['success']='Premium assets updated — visible on your public profile.';
        }
        header('Location: premium_profiles.php'); exit;
    }
}

$flash_success = $_SESSION['success'] ?? ''; unset($_SESSION['success']);
$flash_error = $_SESSION['error'] ?? ''; unset($_SESSION['error']);

$premiumTier = $company_id ? is_premium_company($conn, $company_id) : false;
$premiumRow = $company_id ? get_premium_profile($conn, $company_id) : null;
$hasClaim = false; $claimRow=null;
if ($company_id) {
    $claimRow = sa_one($conn, "SELECT * FROM premium_claims WHERE company_id=".(int)$company_id." AND claimant_tenant_id=".(int)$tenant_id." ORDER BY id DESC LIMIT 1", 'premium_claims');
    $hasClaim = !empty($claimRow);
}
$tiers = monetization_premium_tiers();
$allRatings = [];
if ($company_id) {
    $r = $conn->prepare("SELECT id, customer_name, rating, comment, created_at FROM ratings WHERE company_id=? ORDER BY created_at DESC LIMIT 20");
    $r->bind_param("i",$company_id); $r->execute(); $allRatings = $r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close();
}
$premiumPlans = sa_query($conn, "SELECT * FROM monetization_plans WHERE stream='premium' AND is_active=1 ORDER BY sort_order ASC", 'monetization_plans');

$BASE='../'; $pageTitle='Premium Profiles'; $activeNav='company';
include __DIR__.'/_shell.php';
?>
<div class="welcome-row" style="margin-bottom:22px;">
  <div>
    <p class="eyebrow">Monetization · B2B Premium Profiles</p>
    <h1 style="margin:0;">Claim & Monetize Your Business Profile</h1>
    <p class="muted" style="margin-top:6px;">Charge local businesses a monthly fee to claim their profile, remove competitor ads, pin positive reviews & upload custom banners. <strong>Targets:</strong> mid-sized businesses, private schools, clinics.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="company.php" class="btn btn-secondary" style="text-decoration:none;">Company Setup</a>
    <a href="../companies.php" target="_blank" class="btn btn-secondary">View Directory</a>
  </div>
</div>

<?php if($flash_success): ?><div class="alert alert-success"><?php echo htmlspecialchars($flash_success); ?></div><?php endif; ?>
<?php if($flash_error): ?><div class="alert alert-error"><?php echo htmlspecialchars($flash_error); ?></div><?php endif; ?>

<!-- Status bar -->
<div class="form-card" style="padding:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;border-left:4px solid <?php echo $premiumTier ? '#10b981' : '#f59e0b'; ?>;">
  <div style="display:flex;align-items:center;gap:14px;">
    <div style="width:44px;height:44px;border-radius:10px;background:<?php echo $premiumTier ? '#ecfdf5' : '#fffbeb'; ?>;display:grid;place-items:center;font-size:22px;"><?php echo $premiumTier ? '👑' : '🏢'; ?></div>
    <div>
      <strong style="font-size:15px;"><?php echo htmlspecialchars($active['company_name'] ?? 'No company'); ?></strong>
      <div style="font-size:12px;color:#64748b;">
        <?php if($premiumTier): ?>
          <span style="background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:2px 8px;border-radius:99px;font-weight:700;">★ <?php echo htmlspecialchars(ucfirst($premiumTier)); ?> Premium Active</span>
          · Expires <?php echo !empty($premiumRow['premium_expires_at']) ? date('M j, Y', strtotime($premiumRow['premium_expires_at'])) : '—'; ?>
        <?php elseif($hasClaim): ?>
          <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 8px;border-radius:99px;font-weight:700;">Claim <?php echo htmlspecialchars($claimRow['claim_status']); ?></span>
          · Verification code <?php echo htmlspecialchars($claimRow['verification_code']); ?>
        <?php else: ?>
          Standard listing — <strong>unclaimed</strong> (competitor ads visible)
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div style="text-align:right;">
    <div style="font-size:11px;color:#94a3b8;">Company ID #<?php echo (int)$company_id; ?></div>
    <div style="font-size:12px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($active['category_id'] ? 'Categorized' : 'Uncategorized'); ?></div>
  </div>
</div>

<?php if(!$company_id): ?>
<div class="form-card" style="padding:28px;text-align:center;">
  <p style="font-size:15px;font-weight:700;">No company profile found</p>
  <p class="muted">Create your company profile first to enable Premium claims.</p>
  <a href="company.php?new=1" class="btn btn-primary" style="margin-top:10px;text-decoration:none;">Create Company Profile</a>
</div>
<?php else: ?>

<!-- Two columns: benefits vs. claim -->
<div style="display:grid;grid-template-columns:1.3fr .9fr;gap:18px;margin-top:18px;">
  <div class="form-card" style="padding:22px;">
    <h3 style="margin:0 0 6px;">Why Businesses Pay for Premium</h3>
    <p class="muted" style="font-size:12.5px;">Turn your Optibiz directory into a revenue engine — local businesses will pay monthly to own their reputation.</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px;">
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;">
        <div style="font-size:18px;">🚫</div><strong style="font-size:13px;">Remove Competitor Ads</strong><div style="font-size:12px;color:#64748b;">Premium profiles hide “You may also like” & competitor suggestion blocks that siphon leads.</div>
      </div>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;">
        <div style="font-size:18px;">📌</div><strong style="font-size:13px;">Pin 5★ Reviews</strong><div style="font-size:12px;color:#64748b;">Feature your best testimonials at the top — growth tier pins 3, authority unlimited.</div>
      </div>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;">
        <div style="font-size:18px;">🎨</div><strong style="font-size:13px;">Custom Banners & CTA</strong><div style="font-size:12px;color:#64748b;">Upload hero banners, promo images & a “Book Now / Call” button that drives conversions.</div>
      </div>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;">
        <div style="font-size:18px;">✅</div><strong style="font-size:13px;">Verified Badge + Priority Rank</strong><div style="font-size:12px;color:#64748b;">Verified checkmark + boost in category listings. Authority tier gets 1 week/month featured slot.</div>
      </div>
    </div>
    <div style="margin-top:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px;">
      <strong style="font-size:13px;color:#1d4ed8;">Pricing insight (Ghana):</strong>
      <span style="font-size:12.5px;color:#334155;">Essential GHS 149/mo · Growth GHS 299/mo · Authority GHS 499/mo. Yearly saves 15–20%. Target 30 premium businesses = GHS 4,470–14,970 MRR.</span>
    </div>
  </div>
  <div class="form-card" style="padding:22px;">
    <h3 style="margin:0;">Claim This Business</h3>
    <p class="muted" style="font-size:12.5px;">Prove you own this profile. Fastest: matching email domain or phone number.</p>
    <?php if($hasClaim && $claimRow['claim_status']!=='rejected'): ?>
      <div style="margin-top:12px;padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;">
        <strong style="font-size:13px;color:#15803d;">Claim <?php echo htmlspecialchars($claimRow['claim_status']); ?></strong>
        <div style="font-size:12px;color:#475569;margin-top:4px;">Method: <?php echo htmlspecialchars($claimRow['claim_method']); ?> · Code: <strong><?php echo htmlspecialchars($claimRow['verification_code']); ?></strong></div>
        <div style="font-size:12px;color:#64748b;margin-top:6px;">We verify within 24h. You can still upgrade to Premium — payment activates the moment we approve.</div>
      </div>
    <?php else: ?>
      <form method="POST" style="margin-top:12px;">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="claim">
        <label style="font-size:12.5px;font-weight:600;">Verification method</label>
        <select name="claim_method" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;margin:6px 0 10px;">
          <option value="email_domain">Business email domain match (fastest)</option>
          <option value="phone_match">Phone number on file</option>
          <option value="document">Business registration document</option>
        </select>
        <label style="font-size:12.5px;font-weight:600;">Business email</label>
        <input type="email" name="claim_email" value="<?php echo htmlspecialchars($tenant['email'] ?? $active['email'] ?? ''); ?>" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;margin:6px 0 10px;">
        <label style="font-size:12.5px;font-weight:600;">Phone</label>
        <input type="text" name="claim_phone" value="<?php echo htmlspecialchars($tenant['phone'] ?? $active['phone'] ?? ''); ?>" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;margin:6px 0 10px;">
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px;">Submit Claim for Verification</button>
      </form>
    <?php endif; ?>
    <div style="margin-top:12px;font-size:11px;color:#94a3b8;">Fraud-protected: duplicate claims are blocked. Our control center verifies every claim before activation.</div>
  </div>
</div>

<!-- Pricing cards -->
<h3 style="margin:22px 0 12px;">Upgrade to Premium — Choose Your Tier</h3>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;">
  <?php foreach($tiers as $key=>$t): $isActiveTier = $premiumTier===$key; ?>
  <div style="background:#fff;border: <?php echo $isActiveTier ? '2px solid #10b981' : '1px solid #e2e8f0'; ?>;border-radius:14px;padding:18px;position:relative;overflow:hidden;">
    <?php if($key==='growth'): ?><div style="position:absolute;top:10px;right:10px;background:#8b5cf6;color:#fff;font-size:10px;font-weight:800;padding:3px 8px;border-radius:99px;">MOST POPULAR</div><?php endif; ?>
    <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:<?php echo $t['color']; ?>"><?php echo htmlspecialchars($t['label']); ?></div>
    <div style="font-size:26px;font-weight:800;margin:6px 0;">GHS <?php echo number_format($t['price']); ?><span style="font-size:12px;color:#64748b;font-weight:600;">/mo</span></div>
    <div style="font-size:12px;color:#64748b;margin-bottom:10px;">or GHS <?php echo number_format($t['price']*12*0.8); ?>/year (20% off)</div>
    <ul style="list-style:none;padding:0;margin:0 0 14px;font-size:12.5px;color:#334155;">
      <?php foreach($t['benefits'] as $b): ?><li style="display:flex;gap:6px;margin-bottom:6px;"><span style="color:#10b981;">✓</span> <?php echo htmlspecialchars($b); ?></li><?php endforeach; ?>
    </ul>
    <?php if($isActiveTier): ?>
      <span style="display:block;text-align:center;padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;font-weight:700;color:#065f46;">✓ Active Tier</span>
    <?php else: ?>
      <form method="POST">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="buy_premium">
        <input type="hidden" name="tier" value="<?php echo $key; ?>">
        <div style="display:flex;gap:6px;margin-bottom:8px;">
          <label style="flex:1;display:flex;align-items:center;gap:6px;font-size:12px;border:1px solid #cbd5e1;border-radius:8px;padding:6px 10px;cursor:pointer;"><input type="radio" name="cycle" value="monthly" checked> Monthly</label>
          <label style="flex:1;display:flex;align-items:center;gap:6px;font-size:12px;border:1px solid #cbd5e1;border-radius:8px;padding:6px 10px;cursor:pointer;"><input type="radio" name="cycle" value="yearly"> Yearly</label>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Upgrade to <?php echo htmlspecialchars($t['label']); ?> →</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Premium assets editor (only active) -->
<?php if($premiumTier): ?>
<div class="form-card" style="padding:22px;margin-top:18px;border-left:4px solid #10b981;">
  <h3 style="margin:0;">Premium Assets — Your Public Profile Shine</h3>
  <p class="muted" style="font-size:12.5px;">These appear on <a href="<?php echo htmlspecialchars(getCompanyPublicRatingUrl($company_id, $active['company_name'] ?? '')); ?>" target="_blank">your public rating page</a>. Competitor suggestions are hidden for Premium.</p>
  <form method="POST" style="margin-top:14px;">
    <?php echo sa_csrf_field(); ?>
    <input type="hidden" name="action" value="update_premium_assets">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
      <div style="grid-column:1/-1;">
        <label style="font-size:12.5px;font-weight:600;">Custom Hero Banner URL (image — 1200×300 recommended)</label>
        <input type="text" name="custom_banner_url" value="<?php echo htmlspecialchars($premiumRow['custom_banner_url'] ?? ''); ?>" placeholder="https://yourcdn.com/banner.jpg" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;">
        <small class="muted">Leave blank to use default. Upload to your media and paste the link.</small>
      </div>
      <div>
        <label style="font-size:12.5px;font-weight:600;">CTA Button Label (e.g., “Book Now”)</label>
        <input type="text" name="cta_label" maxlength="40" value="<?php echo htmlspecialchars($premiumRow['custom_cta_label'] ?? ''); ?>" placeholder="e.g., Book Appointment" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;">
      </div>
      <div>
        <label style="font-size:12.5px;font-weight:600;">CTA URL</label>
        <input type="text" name="cta_url" value="<?php echo htmlspecialchars($premiumRow['custom_cta_url'] ?? ''); ?>" placeholder="https://booking.link" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;">
      </div>
      <div style="grid-column:1/-1;">
        <label style="font-size:12.5px;font-weight:600;">Pin a 5★ Review to Top</label>
        <select name="pinned_review_id" style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;">
          <option value="0">— No pinned review (chronological) —</option>
          <?php foreach($allRatings as $rr): if((int)$rr['rating'] < 4) continue; ?>
            <option value="<?php echo (int)$rr['id']; ?>" <?php echo ((int)($premiumRow['pinned_review_id'] ?? 0)===(int)$rr['id']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars(mb_substr($rr['customer_name'].' — '. $rr['comment'],0,80)); ?> (<?php echo (int)$rr['rating']; ?>★)
            </option>
          <?php endforeach; ?>
        </select>
        <small class="muted">
          <?php if($premiumTier==='growth'): ?> Growth allows up to 3 pinned. Pin your best here (more via support).
          <?php elseif($premiumTier==='authority'): ?> Unlimited pins — contact support to pin more.
          <?php else: ?> Essential: 1 pinned review allowed.
          <?php endif; ?>
        </small>
      </div>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:14px;">Save Premium Assets</button>
  </form>
</div>
<?php endif; ?>

<?php endif; ?>
<?php include __DIR__.'/_shell_footer.php'; ?>
