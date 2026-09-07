<?php
/**
 * ============================================================
 *  Admin — Company Profile
 * ============================================================
 *  Allows the logged-in tenant to view and update their own
 *  company info stored in the customers table.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

// Load tenant data (plan, status etc.)
$tenant = null;
if ($tenant_id) {
    $t = $conn->prepare("SELECT t.*, p.plan_name FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id=p.id WHERE t.id=?");
    $t->bind_param("i", $tenant_id);
    $t->execute();
    $tenant = $t->get_result()->fetch_assoc();
    $t->close();
}

// Fetch tenant's own company profile from customers table
$company_profile = null;
if ($tenant_id) {
    $cp = $conn->prepare("SELECT * FROM customers WHERE tenant_id=? ORDER BY id ASC LIMIT 1");
    $cp->bind_param("i", $tenant_id);
    $cp->execute();
    $company_profile = $cp->get_result()->fetch_assoc();
    $cp->close();
}

$success = '';
$error   = '';

// ============================================================
// POST — save company profile
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $company_name  = trim($_POST['company_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $website       = trim($_POST['website'] ?? '');
    $category_id   = (int)($_POST['category_id'] ?? 0);
    $whatsapp_raw  = trim($_POST['whatsapp_number'] ?? '');
    $whatsapp_num  = whatsappDigits($whatsapp_raw);

    if (empty($company_name)) {
        $_SESSION['error'] = 'Company name is required.';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Please enter a valid email address.';
    } elseif ($whatsapp_raw !== '' && $whatsapp_num === '') {
        $_SESSION['error'] = 'That WhatsApp number is not usable. Enter it with the country code, e.g. +233 24 555 0118.';
    } else {
        $cat_val = $category_id > 0 ? $category_id : null;

        // Installs created before the WhatsApp feature may not have the column yet.
        ensureWhatsappColumn($conn);

        if ($company_profile) {
            $upd = $conn->prepare("UPDATE customers SET company_name=?,email=?,phone=?,whatsapp_number=?,website=?,category_id=? WHERE id=? AND tenant_id=?");
            $upd->bind_param("sssssiii", $company_name, $email, $phone, $whatsapp_num, $website, $cat_val, $company_profile['id'], $tenant_id);
            $upd->execute();
            $upd->close();
        } else {
            $ins = $conn->prepare("INSERT INTO customers (tenant_id,company_name,email,phone,whatsapp_number,website,category_id,created_at) VALUES (?,?,?,?,?,?,?,NOW())");
            $ins->bind_param("isssssi", $tenant_id, $company_name, $email, $phone, $whatsapp_num, $website, $cat_val);
            $ins->execute();
            $ins->close();
        }

        // Keep tenant table in sync
        $sync = $conn->prepare("UPDATE tenants SET company_name=? WHERE id=?");
        $sync->bind_param("si", $company_name, $tenant_id);
        $sync->execute();
        $sync->close();

        $_SESSION['success'] = 'Company profile saved successfully!';
    }
    
    // Redirect to prevent form resubmission
    header('Location: company.php');
    exit;
}

// Get flash messages from session
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Categories dropdown
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");

// Rating stats
$company_id    = (int)($company_profile['id'] ?? 0);
$total_reviews = 0;
$avg_score     = 0.0;
if ($company_id > 0) {
    $st = $conn->prepare("SELECT COUNT(*) cnt, AVG(rating) avg FROM ratings WHERE company_id=?");
    $st->bind_param("i", $company_id);
    $st->execute();
    $stat = $st->get_result()->fetch_assoc();
    $st->close();
    $total_reviews = (int)($stat['cnt'] ?? 0);
    $avg_score     = round((float)($stat['avg'] ?? 0), 1);
}

// Build public rating URL
$__scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__root     = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$public_url = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $__root . '/rate/index.php?tenant=' . (int)$tenant_id;

// WhatsApp click-to-chat (customers.whatsapp_number)
$wa_number  = $company_profile['whatsapp_number'] ?? '';
$wa_url     = whatsappChatUrl($wa_number, $company_profile['company_name'] ?? $tenant['company_name'] ?? '');
$wa_display = whatsappDisplay($wa_number);

$BASE      = '../';
$pageTitle = 'Company Profile';
$activeNav = 'company';
include __DIR__ . '/_shell.php';
?>

<!-- Page Header -->
<div class="welcome-row" style="margin-bottom:22px;">
    <div>
        <p class="eyebrow">Your Workspace &middot; Company Profile</p>
        <h1><?php echo htmlspecialchars($company_profile['company_name'] ?? $tenant['company_name'] ?? 'Company Profile'); ?></h1>
        <p class="muted">Your company information shown on the public rating page and all reports.</p>
    </div>
    <a href="<?php echo htmlspecialchars($public_url); ?>" target="_blank"
       class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;">
        ↗ View Public Rating Page
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success" role="alert">✓ <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error" role="alert">⚠ <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Metric Cards -->
<div class="metric-grid" style="margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon lime">⌂</div>
        <span>Company Record</span>
        <strong><?php echo $company_id > 0 ? '#' . $company_id : '—'; ?></strong>
        <small><?php echo $company_id > 0 ? 'Profile registered' : 'Not set up yet'; ?></small>
    </div>
    <div class="metric-card">
        <div class="metric-icon amber">★</div>
        <span>Total Reviews</span>
        <strong><?php echo number_format($total_reviews); ?></strong>
        <small>All-time customer feedback</small>
    </div>
    <div class="metric-card">
        <div class="metric-icon green">✓</div>
        <span>Average Score</span>
        <strong><?php echo $avg_score > 0 ? $avg_score . ' / 5.0' : '—'; ?></strong>
        <small><?php echo $avg_score > 0 ? 'Customer satisfaction' : 'No ratings yet'; ?></small>
    </div>
    <div class="metric-card">
        <div class="metric-icon purple">◈</div>
        <span>Subscription</span>
        <strong class="active-text"><?php echo htmlspecialchars(ucfirst($tenant['subscription_status'] ?? 'active')); ?></strong>
        <small><?php echo htmlspecialchars($tenant['plan_name'] ?? 'Standard plan'); ?></small>
    </div>
</div>

<!-- Two-column layout -->
<div class="grid-2col" style="align-items:start;">

    <!-- Edit Form -->
    <div class="form-card" style="padding:28px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid var(--line);">
            <div>
                <h3 style="margin:0;font-size:16px;">Company Information</h3>
                <p class="muted" style="margin:3px 0 0;font-size:12.5px;">
                    <?php echo $company_profile ? 'Edit your company details below.' : 'Complete your company profile to activate your public rating page.'; ?>
                </p>
            </div>
            <?php echo $company_profile
                ? '<span class="status-badge-replied" style="font-size:11px;">● Profile active</span>'
                : '<span class="status-badge-pending" style="font-size:11px;">● Incomplete</span>'; ?>
        </div>

        <form method="POST" action="company.php">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-grid">
                <div class="form-group">
                    <label for="company_name">Company Name *</label>
                    <input type="text" id="company_name" name="company_name" required maxlength="255"
                           placeholder="e.g., Airport West Hotel"
                           value="<?php echo htmlspecialchars($company_profile['company_name'] ?? $tenant['company_name'] ?? ''); ?>">
                    <small class="muted">This is how customers see you on the public rating page.</small>
                </div>

                <div class="form-group">
                    <label for="category_id">Business Category</label>
                    <select id="category_id" name="category_id">
                        <option value="0">— Select category —</option>
                        <?php if ($categories): while ($cat = $categories->fetch_assoc()): ?>
                        <option value="<?php echo $cat['id']; ?>"
                            <?php echo ((int)($company_profile['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="email">Business Email</label>
                    <input type="email" id="email" name="email" maxlength="100"
                           placeholder="info@yourcompany.com"
                           value="<?php echo htmlspecialchars($company_profile['email'] ?? $tenant['email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" maxlength="20"
                           placeholder="+233 24 567 8900"
                           value="<?php echo htmlspecialchars($company_profile['phone'] ?? $tenant['phone'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="whatsapp_number">WhatsApp Number</label>
                    <input type="text" id="whatsapp_number" name="whatsapp_number" maxlength="30"
                           placeholder="+233 24 555 0118"
                           value="<?php echo htmlspecialchars($wa_number); ?>"
                           oninput="previewWhatsapp(this.value)">
                    <small class="muted">
                        Adds a green <strong>“Chat on WhatsApp”</strong> button to your public rating page
                        and your directory listing. Leave blank to hide it.
                        <a href="<?php echo $wa_url !== '' ? htmlspecialchars($wa_url) : '#'; ?>" id="waPreview"
                           target="_blank" rel="noopener noreferrer"
                           style="color:var(--ink);font-weight:700;text-decoration:none;<?php echo $wa_url !== '' ? '' : 'display:none;'; ?>">
                            Test this number ↗
                        </a>
                    </small>
                </div>

                <div class="form-group" style="grid-column:1/-1;">
                    <label for="website">Website URL</label>
                    <input type="text" id="website" name="website" maxlength="255"
                           placeholder="https://yourcompany.com"
                           value="<?php echo htmlspecialchars($company_profile['website'] ?? ''); ?>">
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="submit" class="btn btn-primary">
                    <?php echo $company_profile ? '✓ Save Changes' : '＋ Create Profile'; ?>
                </button>
                <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </form>
    </div>

    <!-- Sidebar info -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Public Rating Link -->
        <div class="form-card" style="padding:22px;">
            <h3 style="margin:0 0 6px;font-size:15px;">📡 Public Rating Link</h3>
            <p class="muted" style="margin:0 0 14px;font-size:12.5px;">Share this link with customers so they can submit reviews for your company.</p>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input type="text" readonly id="publicUrl"
                       value="<?php echo htmlspecialchars($public_url); ?>"
                       style="font-family:monospace;font-size:11px;padding:8px 10px;border-radius:8px;border:1px solid var(--line);background:var(--bg);flex:1;min-width:0;color:var(--ink);">
                <button type="button" class="btn btn-secondary" onclick="copyPublicUrl()" style="padding:8px 12px;font-size:12px;white-space:nowrap;">Copy</button>
                <a href="<?php echo htmlspecialchars($public_url); ?>" target="_blank" class="btn btn-primary" style="padding:8px 12px;font-size:12px;text-decoration:none;white-space:nowrap;">Open ↗</a>
            </div>
        </div>

        <!-- WhatsApp click-to-chat -->
        <div class="form-card" style="padding:22px;<?php echo $wa_url ? 'border-left:3px solid #25D366;' : ''; ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px;">
                <h3 style="margin:0;font-size:15px;">💬 WhatsApp Click-to-Chat</h3>
                <?php echo $wa_url
                    ? '<span class="status-badge-replied" style="font-size:11px;">● Active</span>'
                    : '<span class="status-badge-pending" style="font-size:11px;">● Not set</span>'; ?>
            </div>
            <?php if ($wa_url): ?>
                <p class="muted" style="margin:0 0 14px;font-size:12.5px;">
                    Customers can start a chat with <strong><?php echo htmlspecialchars($wa_display); ?></strong>
                    straight from your rating page and directory card.
                </p>
                <a href="<?php echo htmlspecialchars($wa_url); ?>" target="_blank" rel="noopener noreferrer"
                   style="display:inline-flex;align-items:center;gap:8px;background:#25D366;color:#fff;text-decoration:none;font-weight:700;font-size:13px;padding:10px 16px;border-radius:99px;">
                    Chat on WhatsApp ↗
                </a>
            <?php else: ?>
                <p class="muted" style="margin:0;font-size:12.5px;line-height:1.6;">
                    Add a WhatsApp number above and a <strong>“Chat on WhatsApp”</strong> button appears on your
                    public rating page — the fastest way to turn a good review into an order.
                </p>
            <?php endif; ?>
        </div>

        <!-- Account Details -->
        <div class="form-card" style="padding:22px;">
            <h3 style="margin:0 0 16px;font-size:15px;">🔐 Account Details</h3>
            <dl class="admin-kv-list">
                <div class="admin-kv-row">
                    <dt>Tenant ID</dt>
                    <dd>#<?php echo (int)$tenant_id; ?></dd>
                </div>
                <div class="admin-kv-row">
                    <dt>Login Username</dt>
                    <dd style="font-family:monospace;font-size:12px;"><?php echo htmlspecialchars($tenant['username'] ?? '—'); ?></dd>
                </div>
                <div class="admin-kv-row">
                    <dt>Account Email</dt>
                    <dd><?php echo htmlspecialchars($tenant['email'] ?? '—'); ?></dd>
                </div>
                <div class="admin-kv-row">
                    <dt>Member Since</dt>
                    <dd><?php echo !empty($tenant['created_at']) ? date('M d, Y', strtotime($tenant['created_at'])) : '—'; ?></dd>
                </div>
                <div class="admin-kv-row" style="border-bottom:none;">
                    <dt>Current Plan</dt>
                    <dd><?php echo htmlspecialchars($tenant['plan_name'] ?? 'Standard'); ?></dd>
                </div>
            </dl>
        </div>

        <?php if (!$company_profile || $total_reviews === 0): ?>
        <!-- Getting started hint -->
        <div class="form-card" style="padding:18px;border-left:3px solid var(--lime);">
            <p style="font-size:13px;font-weight:700;margin:0 0 6px;color:var(--ink);">🚀 Getting started</p>
            <p class="muted" style="font-size:12.5px;margin:0;line-height:1.6;">
                <?php if (!$company_profile): ?>
                Complete your company profile above, then share your public rating link with customers.
                <?php else: ?>
                Share your public rating link above. All customer reviews will appear in your <a href="ratings.php" style="color:var(--ink);font-weight:700;">Ratings &amp; Reviews</a> page.
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
// Mirrors whatsappDigits() in includes/functions.php so the "test this
// number" link matches the wa.me URL customers will get.
function previewWhatsapp(value) {
    var link = document.getElementById('waPreview');
    if (!link) return;
    var digits = String(value || '').replace(/\D+/g, '');
    if (digits.indexOf('00') === 0) digits = digits.slice(2);
    if (digits.charAt(0) === '0' && digits.length >= 9 && digits.length <= 10) digits = '233' + digits.slice(1);
    if (digits.length < 7 || digits.length > 15) { link.style.display = 'none'; return; }
    link.href = 'https://wa.me/' + digits
        + '?text=' + encodeURIComponent('Hello, I found you on Optibiz and would like to inquire.');
    link.style.display = '';
}

function copyPublicUrl() {
    var el = document.getElementById('publicUrl');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(el.value)
            .then(function() { alert('Rating link copied to clipboard!'); })
            .catch(function() { el.select(); document.execCommand('copy'); alert('Copied!'); });
    } else {
        el.select();
        document.execCommand('copy');
        alert('Rating link copied to clipboard!');
    }
}
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
