<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();
$is_admin  = isAdmin();

$success = '';
$error   = '';

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $company_name = sanitize($_POST['company_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');

    if ($is_tenant && $tenant_id) {
        $logo_path = null;

        // Handle logo upload
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['company_logo']['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowed_types) && $_FILES['company_logo']['size'] <= 2 * 1024 * 1024) {
                $upload_dir = __DIR__ . '/../uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $ext = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
                $filename = 'tenant_logo_' . $tenant_id . '_' . time() . '.' . $ext;
                $target_file = $upload_dir . $filename;

                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $target_file)) {
                    // Remove old logo if exists
                    $old_logo = $tenant['logo'] ?? '';
                    if (!empty($old_logo) && file_exists(__DIR__ . '/../' . $old_logo)) {
                        unlink(__DIR__ . '/../' . $old_logo);
                    }
                    $logo_path = 'uploads/' . $filename;
                } else {
                    $error = "Failed to upload logo. Please try again.";
                }
            } else {
                $error = "Invalid file type or file too large. Only JPG, PNG, GIF, WEBP allowed (max 2MB).";
            }
        }

        if ($logo_path !== null) {
            $stmt = $conn->prepare("UPDATE tenants SET company_name = ?, email = ?, phone = ?, logo = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $company_name, $email, $phone, $logo_path, $tenant_id);
        } else {
            $stmt = $conn->prepare("UPDATE tenants SET company_name = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->bind_param("sssi", $company_name, $email, $phone, $tenant_id);
        }
                if ($stmt->execute()) {
            $_SESSION['tenant_name'] = $company_name;
            $_SESSION['tenant_email'] = $email;
            if ($logo_path !== null) {
                $_SESSION['tenant_logo'] = $logo_path;
            }
            $success = "Profile updated successfully!";
        } else {
            $error = "Failed to update profile: " . $conn->error;
        }
    } elseif (isAdmin()) {
        $admin_id = (int)$_SESSION['admin_id'];
        $stmt = $conn->prepare("UPDATE admins SET username = ?, email = ? WHERE id = ?");
        $stmt->bind_param("ssi", $company_name, $email, $admin_id);
        if ($stmt->execute()) {
            $_SESSION['admin_username'] = $company_name;
            $_SESSION['admin_email']    = $email;
            $success = "Account updated successfully!";
        } else {
            $error = "Failed to update account: " . $conn->error;
        }
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } else {
        if ($is_tenant && $tenant_id) {
            $stmt = $conn->prepare("SELECT password FROM tenants WHERE id = ?");
            $stmt->bind_param("i", $tenant_id);
            $stmt->execute();
            $curr_hash = $stmt->get_result()->fetch_assoc()['password'];

            if (password_verify($current_password, $curr_hash)) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $up_stmt = $conn->prepare("UPDATE tenants SET password = ? WHERE id = ?");
                $up_stmt->bind_param("si", $new_hash, $tenant_id);
                if ($up_stmt->execute()) {
                    $success = "Password changed successfully!";
                } else {
                    $error = "Failed to update password.";
                }
            } else {
                $error = "Incorrect current password.";
            }
        } elseif (isAdmin()) {
            $admin_id = (int)$_SESSION['admin_id'];
            $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $curr_hash = $stmt->get_result()->fetch_assoc()['password'];

            if (password_verify($current_password, $curr_hash)) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $up_stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                $up_stmt->bind_param("si", $new_hash, $admin_id);
                if ($up_stmt->execute()) {
                    $success = "Password changed successfully!";
                } else {
                    $error = "Failed to update password.";
                }
            } else {
                $error = "Incorrect current password.";
            }
        }
    }
}

// Data loading for both tenants and platform admins
$tenant      = null;
$admin_info  = null;
$usage_stats = [
    'customer_count' => 0,
    'rating_count'   => 0,
    'avg_rating'     => 0.0,
    'companies'      => [],
    'total_tenants'  => 0,
];

if ($is_tenant && $tenant_id) {
    $stmt = $conn->prepare("SELECT t.*, p.plan_name, p.max_ratings, p.max_customers, p.features, p.price as plan_price 
                            FROM tenants t 
                            LEFT JOIN subscription_plans p ON t.plan_id = p.id 
                            WHERE t.id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();

    // Live usage: companies
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM customers WHERE tenant_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $usage_stats['customer_count'] = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // Live usage: ratings & score
    $stmt = $conn->prepare("SELECT COUNT(r.id) as cnt, AVG(r.rating) as avg_r 
                            FROM ratings r 
                            JOIN customers c ON r.company_id = c.id 
                            WHERE c.tenant_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $r_row = $stmt->get_result()->fetch_assoc();
    $usage_stats['rating_count'] = (int)($r_row['cnt'] ?? 0);
    $usage_stats['avg_rating']   = round((float)($r_row['avg_r'] ?? 0), 1);

    // Tenant companies for public share links
    $stmt = $conn->prepare("SELECT id, company_name FROM customers WHERE tenant_id = ? ORDER BY company_name ASC");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $resComp = $stmt->get_result();
    while ($c = $resComp->fetch_assoc()) {
        $usage_stats['companies'][] = $c;
    }
} elseif ($is_admin) {
    $admin_id = (int)$_SESSION['admin_id'];
    $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $admin_info = $stmt->get_result()->fetch_assoc();

    // Global platform metrics for administrator
    $resT = $conn->query("SELECT COUNT(*) as cnt FROM tenants");
    $usage_stats['total_tenants'] = (int)($resT ? $resT->fetch_assoc()['cnt'] : 0);

    $resC = $conn->query("SELECT COUNT(*) as cnt FROM customers");
    $usage_stats['customer_count'] = (int)($resC ? $resC->fetch_assoc()['cnt'] : 0);

    $resR = $conn->query("SELECT COUNT(*) as cnt, AVG(rating) as avg_r FROM ratings");
    $r_row = $resR ? $resR->fetch_assoc() : [];
    $usage_stats['rating_count'] = (int)($r_row['cnt'] ?? 0);
    $usage_stats['avg_rating']   = round((float)($r_row['avg_r'] ?? 0), 1);
}

// User-facing display variables
$display_title     = $is_tenant ? ($tenant['company_name'] ?? 'Tenant Workspace') : ($admin_info['username'] ?? 'Administrator');
$display_username  = $is_tenant ? ($tenant['username'] ?? '') : ($admin_info['username'] ?? '');
$display_email     = $is_tenant ? ($tenant['email'] ?? '') : ($admin_info['email'] ?? '');
$display_phone     = $is_tenant ? ($tenant['phone'] ?? '') : '';
$display_initials  = strtoupper(substr($display_title, 0, 2));
$account_created   = $is_tenant ? ($tenant['created_at'] ?? '') : ($admin_info['created_at'] ?? '');
$formatted_created = $account_created ? date('M d, Y', strtotime($account_created)) : 'Active';

// Tenant-specific public review link — uses ?tenant= so it always works for that tenant
// even if they have no companies yet; rate/index.php will auto-resolve to their first company.
$public_review_tenant_id = $is_tenant ? (int)$tenant_id : 0;
$public_review_qs        = $public_review_tenant_id > 0 ? '?tenant=' . $public_review_tenant_id : '';

// Build an absolute URL for display/copy (works regardless of current subfolder)
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__root   = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$public_review_url = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $__root . '/rate/index.php' . $public_review_qs;

$robots    = 'noindex, nofollow';
$BASE      = '../';
$pageTitle = 'Settings & Workspace';
$activeNav = 'settings';
include __DIR__ . '/_shell.php';
?>
<!-- Header Section -->
<div class="welcome-row" style="margin-bottom:22px;">
    <div>
        <p class="eyebrow"><?php echo $is_tenant ? 'Tenant Workspace' : 'Global Administrator'; ?> &middot; ID #<?php echo (int)($is_tenant ? $tenant_id : ($_SESSION['admin_id'] ?? 1)); ?></p>
        <h1>Settings &amp; Workspace</h1>
        <?php if ($is_tenant): ?>
        <p class="muted" style="font-weight:600;color:var(--ink);"><?php echo htmlspecialchars($display_title); ?> &mdash; Tenant Administration</p>
        <?php endif; ?>
        <p class="muted">Manage your profile, credentials, subscription limits, and application preferences.</p>
    </div>
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span class="status-dot">● <?php echo $is_tenant ? 'Tenant: ' . htmlspecialchars($tenant['subscription_status'] ?? 'Active') : 'Global Admin'; ?></span>
        <a class="primary-button" href="<?php echo $BASE; ?>" target="_blank" rel="noopener">
            ↗ Public Portal
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success" role="alert">
        ✓ <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error" role="alert">
        ⚠ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Tab Navigation Bar -->
<nav class="admin-tabs" role="tablist" aria-label="Settings navigation">
    <button type="button" class="admin-tab-btn is-active" id="tabBtn-profile" role="tab" aria-selected="true" aria-controls="tab-profile" onclick="switchAdminTab('profile')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile &amp; Details
    </button>
    <button type="button" class="admin-tab-btn" id="tabBtn-subscription" role="tab" aria-selected="false" aria-controls="tab-subscription" onclick="switchAdminTab('subscription')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        <?php echo $is_tenant ? 'Subscription & Quotas' : 'Platform Overview'; ?>
    </button>
    <button type="button" class="admin-tab-btn" id="tabBtn-security" role="tab" aria-selected="false" aria-controls="tab-security" onclick="switchAdminTab('security')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Security &amp; Password
    </button>
    <button type="button" class="admin-tab-btn" id="tabBtn-preferences" role="tab" aria-selected="false" aria-controls="tab-preferences" onclick="switchAdminTab('preferences')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Preferences &amp; Links
    </button>
</nav>

<!-- ============================================================
     TAB 1: PROFILE & DETAILS
     ============================================================ -->
<div class="admin-tab-panel is-active" id="tab-profile" role="tabpanel" aria-labelledby="tabBtn-profile">
    <div class="grid-2col">
        <!-- Profile Form Card -->
        <div class="form-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--line);">
                <div>
                    <h3 style="margin:0;"><?php echo $is_tenant ? 'Company Profile Information' : 'Administrator Account Details'; ?></h3>
                    <p class="muted" style="margin:4px 0 0;">Update your primary contact identity and workspace settings.</p>
                </div>
                <span class="status-dot">● Active</span>
            </div>

                        <form method="POST" action="settings.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-grid" style="grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:18px;margin-bottom:18px;">
                    <div class="form-group">
                        <label for="f_company_name"><?php echo $is_tenant ? 'Company / Organization Name' : 'Administrator Display Name'; ?></label>
                        <input id="f_company_name" type="text" name="company_name" value="<?php echo htmlspecialchars($display_title); ?>" required placeholder="e.g. Acme Corp">
                    </div>

                    <div class="form-group">
                        <label for="f_username">Username (Permanent Identifier)</label>
                        <input id="f_username" type="text" value="<?php echo htmlspecialchars($display_username); ?>" disabled title="Usernames cannot be changed once created.">
                    </div>
                </div>

                <div class="form-grid" style="grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:18px;margin-bottom:24px;">
                    <div class="form-group">
                        <label for="f_email">Primary / Billing Email</label>
                        <input id="f_email" type="email" name="email" value="<?php echo htmlspecialchars($display_email); ?>" required placeholder="admin@domain.com">
                    </div>

                    <div class="form-group">
                        <label for="f_phone">Contact Phone Number</label>
                        <input id="f_phone" type="text" name="phone" value="<?php echo htmlspecialchars($display_phone); ?>" placeholder="e.g. +1 (555) 019-2831">
                    </div>
                                </div>

                <?php if ($is_tenant): ?>
                <div class="form-group" style="margin-bottom:24px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;">
                        Company Logo
                    </label>
                    <div style="display:flex;align-items:center;gap:14px;">
                        <?php if (!empty($tenant['logo'])): ?>
                            <img src="<?php echo $BASE . htmlspecialchars($tenant['logo']); ?>" alt="Current Logo" style="width:60px;height:60px;border-radius:8px;object-fit:cover;border:1px solid var(--line);">
                        <?php else: ?>
                            <div style="width:60px;height:60px;border-radius:8px;border:1px dashed var(--line);display:grid;place-items:center;color:var(--muted);font-size:11px;text-align:center;">No Logo
                            </div>
                        <?php endif; ?>
                        <div style="flex:1;">
                            <input type="file" name="company_logo" accept="image/*" style="font-size:13px;">
                            <small class="muted" style="display:block;margin-top:4px;font-size:11.5px;">PNG, JPG, GIF, WEBP - max 2MB</small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding-top:16px;border-top:1px solid var(--line);flex-wrap:wrap;">
                    <span class="muted" style="font-size:12.5px;">Changes take effect across the workspace immediately.</span>
                    <button type="submit" class="btn btn-primary" style="padding:11px 26px;">
                        Save Profile Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Identity & Workspace Summary Card -->
        <div class="form-card" style="display:flex;flex-direction:column;justify-content:space-between;">
            <div>
                <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
                    <?php if ($is_tenant && !empty($tenant['logo'])): ?>
                        <img src="<?php echo $BASE . htmlspecialchars($tenant['logo']); ?>" alt="<?php echo htmlspecialchars($display_title); ?> Logo" style="width:50px;height:50px;border-radius:14px;object-fit:cover;border:1px solid var(--line);flex-shrink:0;">
                    <?php else: ?>
                        <div class="mini-avatar">
                            <?php echo htmlspecialchars($display_initials); ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <strong style="font-size:17px;display:block;color:var(--ink);"><?php echo htmlspecialchars($display_title); ?></strong>
                        <span class="muted" style="font-size:12px;"><?php echo $is_tenant ? 'Tenant Workspace Admin' : 'Global Platform Administrator'; ?></span>
                    </div>
                </div>

                <dl class="admin-kv-list">
                    <div class="admin-kv-row">
                        <dt>Account ID</dt>
                        <dd>#<?php echo (int)($is_tenant ? $tenant_id : ($_SESSION['admin_id'] ?? 1)); ?></dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>Account Type</dt>
                        <dd><span style="padding:2px 8px;border-radius:6px;background:rgba(194,245,66,.2);color:var(--ink);font-size:11px;font-weight:700;"><?php echo $is_tenant ? 'Multi-Tenant Workspace' : 'Platform Root Admin'; ?></span></dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>Member Since</dt>
                        <dd><?php echo htmlspecialchars($formatted_created); ?></dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt><?php echo $is_tenant ? 'Current Plan' : 'Role Scope'; ?></dt>
                        <dd><?php echo htmlspecialchars($is_tenant ? ($tenant['plan_name'] ?? 'Professional Plan') : 'Full Access'); ?></dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>Total Companies</dt>
                        <dd><?php echo number_format($usage_stats['customer_count']); ?> registered</dd>
                    </div>
                    <div class="admin-kv-row" style="border-bottom:none;">
                        <dt>All-time Reviews</dt>
                        <dd><?php echo number_format($usage_stats['rating_count']); ?> reviews (<?php echo $usage_stats['avg_rating']; ?> ★)</dd>
                    </div>
                </dl>
            </div>

            <div style="margin-top:20px;padding:14px;border-radius:10px;background:var(--bg);border:1px solid var(--line);">
                <span class="muted" style="font-size:11.5px;display:block;margin-bottom:6px;">Public Review Page Link:</span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <input type="text" readonly id="workspaceRatingUrl" value="<?php echo htmlspecialchars($public_review_url); ?>" style="font-family:monospace;font-size:12px;padding:6px 10px;border-radius:6px;border:1px solid var(--line);background:transparent;flex:1;color:var(--ink);">
                    <button type="button" class="btn btn-secondary" onclick="copyWorkspaceUrl()" style="padding:6px 12px;font-size:12px;">Copy Link</button>
                    <a href="<?php echo htmlspecialchars($public_review_url); ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;text-decoration:none;">Open ↗</a>
                    <script>
                    function copyWorkspaceUrl() {
                        var url = document.getElementById("workspaceRatingUrl").value;
                        function done() { alert("Public rating link copied to clipboard!"); }
                        function fallback() {
                            var ta = document.createElement("textarea");
                            ta.value = url;
                            document.body.appendChild(ta);
                            ta.select();
                            document.execCommand("copy");
                            document.body.removeChild(ta);
                            done();
                        }
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(url).then(done).catch(fallback);
                        } else { fallback(); }
                    }
                    </script>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     TAB 2: SUBSCRIPTION & QUOTAS / PLATFORM OVERVIEW
     ============================================================ -->
<div class="admin-tab-panel" id="tab-subscription" role="tabpanel" aria-labelledby="tabBtn-subscription">
    <?php if ($is_tenant && $tenant): 
        $max_cust  = (int)($tenant['max_customers'] ?? 50);
        $curr_cust = $usage_stats['customer_count'];
        $cust_pct  = $max_cust > 0 ? min(100, round(($curr_cust / $max_cust) * 100)) : 0;

        $max_rat   = (int)($tenant['max_ratings'] ?? 500);
        $curr_rat  = $usage_stats['rating_count'];
        $rat_pct   = $max_rat > 0 ? min(100, round(($curr_rat / $max_rat) * 100)) : 0;
    ?>
        <div class="grid-2col" style="margin-bottom:24px;">
            <!-- Subscription Card -->
            <div class="subscription-card" style="margin-bottom:0;">
                <div class="plan-header">
                    <span class="plan-name"><?php echo htmlspecialchars($tenant['plan_name'] ?? 'Professional Tier'); ?></span>
                    <span class="plan-status"><?php echo htmlspecialchars($tenant['subscription_status'] ?? 'Active'); ?></span>
                </div>
                <div class="plan-price">
                    $<?php echo number_format((float)($tenant['subscription_price'] ?? $tenant['plan_price'] ?? 79.99), 2); ?>
                    <span>/ month</span>
                </div>
                <p class="plan-description">
                    <?php echo htmlspecialchars($tenant['features'] ?? 'Full analytics suite, priority support, multi-branch ratings, custom branding.'); ?>
                </p>

                <hr style="border:none;border-top:1px solid rgba(255,255,255,0.1);margin:18px 0;">

                <div class="subscription-details" style="margin-bottom:0;color:#cbd5e1;">
                    <div class="subscription-detail-row" style="border-color:rgba(255,255,255,0.1);">
                        <span>Subscription Status:</span>
                        <strong style="color:var(--lime);"><?php echo ucfirst(htmlspecialchars($tenant['subscription_status'] ?? 'Active')); ?></strong>
                    </div>
                    <div class="subscription-detail-row" style="border-color:rgba(255,255,255,0.1);">
                        <span>Billing Period Start:</span>
                        <strong style="color:#fff;"><?php echo !empty($tenant['subscription_start_date']) ? date('M d, Y', strtotime($tenant['subscription_start_date'])) : 'Rolling active'; ?></strong>
                    </div>
                    <div class="subscription-detail-row" style="border-color:rgba(255,255,255,0.1);">
                        <span>Next Renewal Date:</span>
                        <strong style="color:#fff;"><?php echo !empty($tenant['subscription_end_date']) ? date('M d, Y', strtotime($tenant['subscription_end_date'])) : 'Ongoing monthly'; ?></strong>
                    </div>
                    <div class="subscription-detail-row" style="border-color:rgba(255,255,255,0.1);border-bottom:none;">
                        <span>Auto-Renew:</span>
                        <strong style="color:#fff;"><?php echo (!isset($tenant['auto_renew']) || $tenant['auto_renew']) ? 'Enabled (Automatic card charge)' : 'Manual renewal'; ?></strong>
                    </div>
                </div>
            </div>

            <!-- Plan Features List Card -->
            <div class="form-card" style="display:flex;flex-direction:column;justify-content:space-between;margin-bottom:0;">
                <div>
                    <h3 style="margin-top:0;">Included In Your Plan</h3>
                    <p class="muted">Your current active subscription provides the following features and capacity limits:</p>

                    <ul style="list-style:none;padding:0;margin:18px 0;display:flex;flex-direction:column;gap:12px;">
                        <li style="display:flex;align-items:center;gap:10px;font-size:13.5px;">
                            <span style="width:20px;height:20px;border-radius:50%;background:#dcfce7;color:#16a34a;display:grid;place-items:center;font-size:11px;font-weight:800;">✓</span>
                            <span>Up to <strong><?php echo number_format($max_cust); ?> company listings</strong> &amp; locations</span>
                        </li>
                        <li style="display:flex;align-items:center;gap:10px;font-size:13.5px;">
                            <span style="width:20px;height:20px;border-radius:50%;background:#dcfce7;color:#16a34a;display:grid;place-items:center;font-size:11px;font-weight:800;">✓</span>
                            <span>Up to <strong><?php echo number_format($max_rat); ?> customer reviews</strong> per month</span>
                        </li>
                        <li style="display:flex;align-items:center;gap:10px;font-size:13.5px;">
                            <span style="width:20px;height:20px;border-radius:50%;background:#dcfce7;color:#16a34a;display:grid;place-items:center;font-size:11px;font-weight:800;">✓</span>
                            <span>Instant public rating links &amp; QR code generation</span>
                        </li>
                        <li style="display:flex;align-items:center;gap:10px;font-size:13.5px;">
                            <span style="width:20px;height:20px;border-radius:50%;background:#dcfce7;color:#16a34a;display:grid;place-items:center;font-size:11px;font-weight:800;">✓</span>
                            <span>Real-time customer feedback &amp; star breakdown</span>
                        </li>
                        <li style="display:flex;align-items:center;gap:10px;font-size:13.5px;">
                            <span style="width:20px;height:20px;border-radius:50%;background:#dcfce7;color:#16a34a;display:grid;place-items:center;font-size:11px;font-weight:800;">✓</span>
                            <span>Direct customer review monitoring dashboard</span>
                        </li>
                    </ul>
                </div>

                <div style="padding:14px;border-radius:10px;background:var(--bg);border:1px solid var(--line);font-size:12.5px;color:var(--muted);">
                    Need higher quota limits? Contact the platform administrator at <span style="font-weight:700;color:var(--ink);"><?php echo htmlspecialchars(defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@example.com'); ?></span> to upgrade.
                </div>
            </div>
        </div>

        <!-- Usage Quota Progress Cards -->
        <div class="form-card">
            <h3 style="margin-top:0;">Usage Quotas &amp; Capacity</h3>
            <p class="muted">Live consumption metrics compared against your tier allowances.</p>

            <div class="admin-quota-grid">
                <!-- Companies Quota -->
                <div class="admin-quota-card">
                    <div class="admin-quota-head">
                        <span>Companies / Locations</span>
                        <span><?php echo $cust_pct; ?>% capacity</span>
                    </div>
                    <div class="admin-quota-val">
                        <?php echo $curr_cust; ?> <span style="font-size:14px;color:var(--muted);font-weight:500;">/ <?php echo $max_cust; ?></span>
                    </div>
                    <div class="admin-quota-track">
                        <div class="admin-quota-fill <?php echo $cust_pct >= 90 ? 'is-danger' : ($cust_pct >= 75 ? 'is-warning' : ''); ?>" style="width:<?php echo $cust_pct; ?>%;"></div>
                    </div>
                    <small class="muted" style="font-size:11px;">Active branch and company profiles</small>
                </div>

                <!-- Ratings Quota -->
                <div class="admin-quota-card">
                    <div class="admin-quota-head">
                        <span>Customer Ratings</span>
                        <span><?php echo $rat_pct; ?>% capacity</span>
                    </div>
                    <div class="admin-quota-val">
                        <?php echo $curr_rat; ?> <span style="font-size:14px;color:var(--muted);font-weight:500;">/ <?php echo $max_rat; ?></span>
                    </div>
                    <div class="admin-quota-track">
                        <div class="admin-quota-fill <?php echo $rat_pct >= 90 ? 'is-danger' : ($rat_pct >= 75 ? 'is-warning' : ''); ?>" style="width:<?php echo $rat_pct; ?>%;"></div>
                    </div>
                    <small class="muted" style="font-size:11px;">Total verified customer submissions</small>
                </div>

                <!-- Satisfaction Rating -->
                <div class="admin-quota-card">
                    <div class="admin-quota-head">
                        <span>Customer Satisfaction</span>
                        <span style="color:#f59e0b;font-weight:700;">★ <?php echo $usage_stats['avg_rating']; ?> / 5.0</span>
                    </div>
                    <div class="admin-quota-val" style="color:#f59e0b;">
                        <?php echo $usage_stats['avg_rating']; ?> <span style="font-size:14px;color:var(--muted);font-weight:500;">Average score</span>
                    </div>
                    <div class="admin-quota-track">
                        <div class="admin-quota-fill" style="width:<?php echo min(100, round(($usage_stats['avg_rating'] / 5.0) * 100)); ?>%;background:#f59e0b;"></div>
                    </div>
                    <small class="muted" style="font-size:11px;">Calculated from <?php echo $curr_rat; ?> customer reviews</small>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Global Admin Platform Overview -->
        <div class="grid-2col" style="margin-bottom:24px;">
            <div class="form-card">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
                    <span class="metric-icon purple" style="position:static;width:42px;height:42px;">⚡</span>
                    <div>
                        <h3 style="margin:0;">Global Administrator Privileges</h3>
                        <p class="muted" style="margin:2px 0 0;">Root authority over tenant workspaces and platform records.</p>
                    </div>
                </div>
                <p style="font-size:13.5px;line-height:1.6;color:var(--muted);">
                    As a platform administrator, your account is exempt from tenant quota ceilings and monthly rating caps. You have unrestricted oversight of all tenants, customer review feeds, category classifications, and operational health.
                </p>
                <div style="display:flex;gap:12px;margin-top:20px;flex-wrap:wrap;">
                    <a href="<?php echo $BASE; ?>superadmin/index.php" class="btn btn-primary" style="padding:10px 20px;">
                        Open Super Admin Portal &rarr;
                    </a>
                    <a href="ratings.php" class="btn btn-secondary" style="padding:10px 20px;">
                        Review All Ratings
                    </a>
                </div>
            </div>

            <div class="form-card">
                <h3 style="margin-top:0;">Platform Capacity Overview</h3>
                <dl class="admin-kv-list">
                    <div class="admin-kv-row">
                        <dt>Total Registered Tenants</dt>
                        <dd style="font-size:15px;"><?php echo number_format($usage_stats['total_tenants']); ?> accounts</dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>Total Listed Companies</dt>
                        <dd style="font-size:15px;"><?php echo number_format($usage_stats['customer_count']); ?> companies</dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>All-time Customer Ratings</dt>
                        <dd style="font-size:15px;"><?php echo number_format($usage_stats['rating_count']); ?> ratings</dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>Global Platform Average</dt>
                        <dd style="color:#f59e0b;font-weight:800;"><?php echo $usage_stats['avg_rating']; ?> / 5.0 ★</dd>
                    </div>
                    <div class="admin-kv-row" style="border-bottom:none;">
                        <dt>PHP Engine</dt>
                        <dd>v<?php echo PHP_VERSION; ?></dd>
                    </div>
                </dl>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     TAB 3: SECURITY & PASSWORD
     ============================================================ -->
<div class="admin-tab-panel" id="tab-security" role="tabpanel" aria-labelledby="tabBtn-security">
    <div class="grid-2col">
        <!-- Change Password Card -->
        <div class="form-card">
            <div style="margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--line);">
                <h3 style="margin:0;">Change Account Password</h3>
                <p class="muted" style="margin:4px 0 0;">Use at least 6 characters including numbers and symbols.</p>
            </div>

            <form method="POST" action="settings.php">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group" style="margin-bottom:16px;">
                    <label for="p_current">Current Password</label>
                    <div class="admin-pw-wrap">
                        <input id="p_current" type="password" name="current_password" required placeholder="Enter current password" autocomplete="current-password">
                        <button type="button" class="admin-pw-toggle" onclick="toggleAdminPw('p_current', this)" aria-label="Toggle password visibility">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label for="p_new">New Password</label>
                    <div class="admin-pw-wrap">
                        <input id="p_new" type="password" name="new_password" minlength="6" required placeholder="Minimum 6 characters" autocomplete="new-password">
                        <button type="button" class="admin-pw-toggle" onclick="toggleAdminPw('p_new', this)" aria-label="Toggle password visibility">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:24px;">
                    <label for="p_confirm">Confirm New Password</label>
                    <div class="admin-pw-wrap">
                        <input id="p_confirm" type="password" name="confirm_password" minlength="6" required placeholder="Re-enter new password" autocomplete="new-password">
                        <button type="button" class="admin-pw-toggle" onclick="toggleAdminPw('p_confirm', this)" aria-label="Toggle password visibility">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding-top:16px;border-top:1px solid var(--line);flex-wrap:wrap;">
                    <span class="muted" style="font-size:12.5px;">Passwords are securely encrypted with PHP bcrypt.</span>
                    <button type="submit" class="btn btn-primary" style="padding:11px 26px;">
                        Update Password
                    </button>
                </div>
            </form>
        </div>

        <!-- Security Guidelines & Session Info -->
        <div class="form-card" style="display:flex;flex-direction:column;justify-content:space-between;">
            <div>
                <h3 style="margin-top:0;">Session &amp; Security Standards</h3>
                <p class="muted">Your active sign-in credentials and connection environment.</p>

                <dl class="admin-kv-list" style="margin-top:16px;">
                    <div class="admin-kv-row">
                        <dt>Authentication Protocol</dt>
                        <dd>Password Hash (Bcrypt 60-char salt)</dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>Session State</dt>
                        <dd><span style="color:#10b981;font-weight:700;">● Authenticated &amp; Valid</span></dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>Client IP Address</dt>
                        <dd><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'); ?></dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>User Role</dt>
                        <dd><?php echo $is_tenant ? 'Tenant Workspace Manager' : 'Global Administrator'; ?></dd>
                    </div>
                    <div class="admin-kv-row" style="border-bottom:none;">
                        <dt>Password Policy</dt>
                        <dd>Minimum 6 characters</dd>
                    </div>
                </dl>
            </div>

            <div style="margin-top:20px;padding:16px;border-radius:10px;background:var(--bg);border:1px solid var(--line);">
                <strong style="display:block;font-size:13px;color:var(--ink);margin-bottom:6px;">Security Tip</strong>
                <p class="muted" style="font-size:12px;line-height:1.5;margin:0;">
                    To protect your rating workspace, avoid using common phrases or reusing passwords from other online services. You will remain logged in on this browser until you manually sign out.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     TAB 4: PREFERENCES & LINKS
     ============================================================ -->
<div class="admin-tab-panel" id="tab-preferences" role="tabpanel" aria-labelledby="tabBtn-preferences">
    <div class="grid-2col">
        <!-- Appearance & Theme Card -->
        <div class="form-card">
            <h3 style="margin-top:0;">Display &amp; Appearance</h3>
            <p class="muted">Customize the visual interface and workspace presentation.</p>

            <div style="margin:24px 0;padding:20px;border-radius:12px;background:var(--bg);border:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;">
                <div>
                    <strong style="display:block;font-size:14px;color:var(--ink);margin-bottom:4px;">Workspace Color Theme</strong>
                    <span class="muted" style="font-size:12.5px;">Switch between high-contrast dark theme and crisp daylight mode.</span>
                </div>
                <div>
                    <button type="button" class="btn btn-secondary" onclick="document.querySelector('[data-admin-theme]') ? document.querySelector('[data-admin-theme]').click() : toggleThemeFallback();" style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        Toggle Dark / Light Theme
                    </button>
                </div>
            </div>

            <div style="font-size:12.5px;color:var(--muted);line-height:1.5;">
                Theme choices are remembered specifically for your browser session using local storage without affecting other team members.
            </div>
        </div>

        <!-- Public Rating Link Generator & Direct URLs -->
        <div class="form-card">
            <h3 style="margin-top:0;">Public Review Collection Links</h3>
            <p class="muted">Share direct rating links with your customers via SMS, email, or invoices.</p>

            <?php if (!empty($usage_stats['companies'])): ?>
                <div class="form-group" style="margin-bottom:16px;">
                    <label for="companySelect">Select Company Listing</label>
                    <select id="companySelect" onchange="updateShareLink(this.value)">
                        <?php foreach ($usage_stats['companies'] as $comp): ?>
                            <option value="<?php echo (int)$comp['id']; ?>" data-name="<?php echo htmlspecialchars($comp['company_name']); ?>">
                                <?php echo htmlspecialchars($comp['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label>Public Rating Page URL</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" id="shareUrlInput" readonly value="" style="font-family:monospace;font-size:13px;">
                        <button type="button" class="btn btn-primary" onclick="copyShareUrl()" style="padding:10px 18px;white-space:nowrap;">
                            Copy Link
                        </button>
                    </div>
                </div>

                <div style="display:flex;gap:12px;margin-top:16px;">
                    <a id="previewRatingBtn" href="#" target="_blank" rel="noopener" class="btn btn-secondary" style="font-size:13px;">
                        ↗ Open Customer Rating Page
                    </a>
                </div>
            <?php else: ?>
                <div style="padding:24px;text-align:center;background:var(--bg);border:1px dashed var(--line);border-radius:10px;">
                    <p class="muted" style="margin:0 0 12px;">No companies currently registered under your workspace.</p>
                    <a href="customers.php" class="btn btn-primary" style="padding:8px 18px;font-size:13px;">
                        + Add Your First Company
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
/* ============================================================
   Tab Switching with URL Hash Memory
   ============================================================ */
function switchAdminTab(tabId) {
    var buttons = document.querySelectorAll('.admin-tab-btn');
    var panels  = document.querySelectorAll('.admin-tab-panel');

    buttons.forEach(function(btn) {
        var active = (btn.id === 'tabBtn-' + tabId);
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    panels.forEach(function(panel) {
        panel.classList.toggle('is-active', panel.id === 'tab-' + tabId);
    });

    try {
        history.replaceState(null, null, '#tab=' + tabId);
    } catch (e) {}
}

// Restore active tab on load from URL hash
(function () {
    var hash = location.hash.replace('#tab=', '').replace('#', '');
    var validTabs = ['profile', 'subscription', 'security', 'preferences'];
    if (validTabs.indexOf(hash) !== -1) {
        switchAdminTab(hash);
    }
})();

/* ============================================================
   Password Visibility Toggle
   ============================================================ */
function toggleAdminPw(fieldId, btn) {
    var field = document.getElementById(fieldId);
    if (!field) return;
    var isPw = (field.type === 'password');
    field.type = isPw ? 'text' : 'password';
    btn.style.color = isPw ? 'var(--lime)' : 'var(--muted)';
}

/* ============================================================
   Share Link Updater
   ============================================================ */
function updateShareLink(companyId) {
    var input = document.getElementById('shareUrlInput');
    var btn   = document.getElementById('previewRatingBtn');
    if (!input || !companyId) return;

    var url = window.location.origin + '<?php echo $BASE; ?>rate/index.php?company=' + companyId;
    input.value = url;
    if (btn) btn.href = url;
}

function copyShareUrl() {
    var input = document.getElementById('shareUrlInput');
    if (!input) return;
    input.select();
    navigator.clipboard.writeText(input.value).then(function() {
        alert('Public rating link copied to clipboard!');
    }).catch(function() {
        document.execCommand('copy');
        alert('Public rating link copied to clipboard!');
    });
}

function toggleThemeFallback() {
    var curr = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', curr);
    try { localStorage.setItem('optibiz-sa-theme', curr); } catch (e) {}
}

// Initialize share link on load
document.addEventListener('DOMContentLoaded', function() {
    var select = document.getElementById('companySelect');
    if (select && select.value) {
        updateShareLink(select.value);
    }
});
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>

