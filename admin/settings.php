<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
requireTeamAccess('settings');

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
        $banner_path = null;

        // Helper closure to store an uploaded image into /uploads
        $handle_image_upload = function ($field, $prefix) use ($tenant_id) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
                return null;
            }
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES[$field]['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed_types) || $_FILES[$field]['size'] > 2 * 1024 * 1024) {
                $_SESSION['error'] = "Invalid file type or file too large. Only JPG, PNG, GIF, WEBP allowed (max 2MB).";
                return false;
            }

            $upload_dir = __DIR__ . '/../uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $ext = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
            $filename = $prefix . '_' . $tenant_id . '_' . time() . '.' . $ext;
            $target_file = $upload_dir . $filename;

            if (move_uploaded_file($_FILES[$field]['tmp_name'], $target_file)) {
                return 'uploads/' . $filename;
            }
            $_SESSION['error'] = "Failed to upload {$prefix}. Please try again.";
            return false;
        };

        // Handle logo upload
        $logo_result = $handle_image_upload('company_logo', 'tenant_logo');
        if ($logo_result === false) {
            $logo_result = null;
        }
        if ($logo_result !== null) {
            $logo_path = $logo_result;
            // Remove old logo if exists
            $old_logo = $tenant['logo'] ?? '';
            if (!empty($old_logo) && file_exists(__DIR__ . '/../' . $old_logo)) {
                unlink(__DIR__ . '/../' . $old_logo);
            }
        }

        // Handle banner upload
        $banner_result = $handle_image_upload('company_banner', 'tenant_banner');
        if ($banner_result === false) {
            $banner_result = null;
        }
        if ($banner_result !== null) {
            $banner_path = $banner_result;
            // Remove old banner if exists
            $old_banner = $tenant['banner'] ?? '';
            if (!empty($old_banner) && file_exists(__DIR__ . '/../' . $old_banner)) {
                unlink(__DIR__ . '/../' . $old_banner);
            }
        }

        if (!isset($_SESSION['error'])) {
            if ($logo_path !== null && $banner_path !== null) {
                $stmt = $conn->prepare("UPDATE tenants SET company_name = ?, email = ?, phone = ?, logo = ?, banner = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $company_name, $email, $phone, $logo_path, $banner_path, $tenant_id);
            } elseif ($logo_path !== null) {
                $stmt = $conn->prepare("UPDATE tenants SET company_name = ?, email = ?, phone = ?, logo = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $company_name, $email, $phone, $logo_path, $tenant_id);
            } elseif ($banner_path !== null) {
                $stmt = $conn->prepare("UPDATE tenants SET company_name = ?, email = ?, phone = ?, banner = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $company_name, $email, $phone, $banner_path, $tenant_id);
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
                if ($banner_path !== null) {
                    $_SESSION['tenant_banner'] = $banner_path;
                }
                $_SESSION['success'] = "Profile updated successfully!";
            } else {
                $_SESSION['error'] = "Failed to update profile: " . $conn->error;
            }
        }
    } elseif (isAdmin()) {
        $admin_id = (int)$_SESSION['admin_id'];
        $stmt = $conn->prepare("UPDATE admins SET username = ?, email = ? WHERE id = ?");
        $stmt->bind_param("ssi", $company_name, $email, $admin_id);
        if ($stmt->execute()) {
            $_SESSION['admin_username'] = $company_name;
            $_SESSION['admin_email']    = $email;
            $_SESSION['success'] = "Account updated successfully!";
        } else {
            $_SESSION['error'] = "Failed to update account: " . $conn->error;
        }
    }
    
    header('Location: settings.php');
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
                    $closed  = auth_session_logout_others($conn, 'admin', $tenant_id, 'password');
                    $success = "Password changed successfully!"
                        . ($closed > 0 ? " $closed other session(s) were signed out." : "");
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
                    $closed  = auth_session_logout_others($conn, 'admin', $admin_id, 'password');
                    $success = "Password changed successfully!"
                        . ($closed > 0 ? " $closed other session(s) were signed out." : "");
                } else {
                    $error = "Failed to update password.";
                }
            } else {
                $error = "Incorrect current password.";
            }
        }
    }
}

// Sign out of every other browser (this one stays signed in)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout_other_sessions') {
    if (!auth_logout_request_ok()) {
        $error = "Your sign-out token did not match. Please try again.";
    } else {
        $closed = auth_session_logout_others($conn, 'admin');
        if ($closed > 0) {
            $success = "Signed out of $closed other session(s). This browser stays signed in.";
        } else {
            $success = "No other session is live — this is the only one.";
        }
    }
}

// Handle Public Page Customisation Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_public_customization') {
    $target_tenant_id = $is_tenant ? (int)$tenant_id : 0;

    $custom_settings = [
        'primary_color'       => sanitize($_POST['primary_color'] ?? '#10b981'),
        'secondary_color'     => sanitize($_POST['secondary_color'] ?? '#059669'),
        'star_color'          => sanitize($_POST['star_color'] ?? '#f59e0b'),
        'page_bg'             => sanitize($_POST['page_bg'] ?? '#f8fafc'),
        'card_bg'             => sanitize($_POST['card_bg'] ?? '#ffffff'),
        'card_border'         => sanitize($_POST['card_border'] ?? '#e2e8f0'),
        'text_color'          => sanitize($_POST['text_color'] ?? '#0f172a'),
        'muted_color'         => sanitize($_POST['muted_color'] ?? '#64748b'),
        'layout_style'        => sanitize($_POST['layout_style'] ?? 'modern_boxed'),
        'header_layout'       => sanitize($_POST['header_layout'] ?? 'standard'),
        'review_layout'       => sanitize($_POST['review_layout'] ?? 'tabs'),
        'border_radius'       => sanitize($_POST['border_radius'] ?? 'rounded'),
        'theme_mode'          => sanitize($_POST['theme_mode'] ?? 'light'),
        'show_banner'         => isset($_POST['show_banner']) ? 1 : 0,
        'show_breadcrumb'     => isset($_POST['show_breadcrumb']) ? 1 : 0,
        'show_verified_badge' => isset($_POST['show_verified_badge']) ? 1 : 0,
        'show_rating_dist'    => isset($_POST['show_rating_dist']) ? 1 : 0,
        'show_services'       => isset($_POST['show_services']) ? 1 : 0,
        'show_qa'             => isset($_POST['show_qa']) ? 1 : 0,
        'show_whatsapp'       => isset($_POST['show_whatsapp']) ? 1 : 0,
        'show_gstore'         => isset($_POST['show_gstore']) ? 1 : 0,
        'show_map'            => isset($_POST['show_map']) ? 1 : 0,
        'custom_css'          => trim((string)($_POST['custom_css'] ?? '')),
    ];

    if (saveTenantPublicPageSettings($conn, $target_tenant_id, $custom_settings)) {
        $_SESSION['success'] = "Public page layout & color customisation saved successfully!";
    } else {
        $_SESSION['error'] = "Failed to save public page customisation: " . $conn->error;
    }

    header('Location: settings.php#tab=customization');
    exit;
}

// Handle Reset Public Page Customisation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_public_customization') {
    $target_tenant_id = $is_tenant ? (int)$tenant_id : 0;
    $defaults = getDefaultPublicPageSettings();
    if (saveTenantPublicPageSettings($conn, $target_tenant_id, $defaults)) {
        $_SESSION['success'] = "Public page customisation has been reset to defaults!";
    } else {
        $_SESSION['error'] = "Failed to reset public page customisation: " . $conn->error;
    }

    header('Location: settings.php#tab=customization');
    exit;
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
$public_review_first_comp = !empty($usage_stats['companies']) ? $usage_stats['companies'][0] : null;
if ($public_review_first_comp) {
    $public_review_url = getCompanyPublicRatingUrl($public_review_first_comp['id'], $public_review_first_comp['company_name']);
    $public_review_qs  = '?company=' . (int)$public_review_first_comp['id'] . '&tenant=' . urlencode(slugify($public_review_first_comp['company_name']));
} else {
    $public_review_qs  = $public_review_tenant_id > 0 ? ('?tenant=' . $public_review_tenant_id . ($display_title ? '&tenant_slug=' . urlencode(slugify($display_title)) : '')) : '';
    $public_review_url = getCompanyPublicRatingUrl(0, $display_title, $public_review_tenant_id > 0 ? ['tenant' => $public_review_tenant_id] : []);
}
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__root   = function_exists('getAppWebRoot') ? getAppWebRoot() : rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$widget_base_url    = (function_exists('getAppBaseUrl') ? getAppBaseUrl() : ($__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $__root)) . '/widget.php';
$widget_default_url = $widget_base_url . $public_review_qs;

// Current public page layout & color customisation
$page_customization = getTenantPublicPageSettings($conn, $is_tenant ? (int)$tenant_id : 0);

// Determine initial active tab from URL query param ?tab=...
$initial_tab = 'profile';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['profile', 'customization', 'preferences', 'subscription', 'security'], true)) {
    $initial_tab = $_GET['tab'];
}

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

<?php
/* Live sessions of the signed-in account, for the security tab. */
$my_sessions = auth_sessions_for($conn, 'admin', auth_current_user_id('admin'), 6);
$my_token    = auth_session_token(false);
$session_started = !empty($_SESSION['session_started_at']) ? (int) $_SESSION['session_started_at'] : 0;
?>

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
    <button type="button" class="admin-tab-btn <?php echo $initial_tab === 'profile' ? 'is-active' : ''; ?>" id="tabBtn-profile" role="tab" aria-selected="<?php echo $initial_tab === 'profile' ? 'true' : 'false'; ?>" aria-controls="tab-profile" onclick="switchAdminTab('profile')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile &amp; Details
    </button>
    <button type="button" class="admin-tab-btn <?php echo $initial_tab === 'customization' ? 'is-active' : ''; ?>" id="tabBtn-customization" role="tab" aria-selected="<?php echo $initial_tab === 'customization' ? 'true' : 'false'; ?>" aria-controls="tab-customization" onclick="switchAdminTab('customization')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/><path d="M12 22.5A8.5 8.5 0 0 0 20.5 14c0-2.3-1.07-4.4-2.84-5.84L12 2.69 6.34 8.16A8.5 8.5 0 0 0 3.5 14a8.5 8.5 0 0 0 8.5 8.5z"/></svg>
        Public Page Customisation
    </button>
    <button type="button" class="admin-tab-btn <?php echo $initial_tab === 'preferences' ? 'is-active' : ''; ?>" id="tabBtn-preferences" role="tab" aria-selected="<?php echo $initial_tab === 'preferences' ? 'true' : 'false'; ?>" aria-controls="tab-preferences" onclick="switchAdminTab('preferences')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Preferences &amp; Links
    </button>
    <button type="button" class="admin-tab-btn <?php echo $initial_tab === 'subscription' ? 'is-active' : ''; ?>" id="tabBtn-subscription" role="tab" aria-selected="<?php echo $initial_tab === 'subscription' ? 'true' : 'false'; ?>" aria-controls="tab-subscription" onclick="switchAdminTab('subscription')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        <?php echo $is_tenant ? 'Subscription & Quotas' : 'Platform Overview'; ?>
    </button>
    <button type="button" class="admin-tab-btn <?php echo $initial_tab === 'security' ? 'is-active' : ''; ?>" id="tabBtn-security" role="tab" aria-selected="<?php echo $initial_tab === 'security' ? 'true' : 'false'; ?>" aria-controls="tab-security" onclick="switchAdminTab('security')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Security &amp; Password
    </button>
</nav>

<!-- ============================================================
     TAB 1: PROFILE & DETAILS
     ============================================================ -->
<div class="admin-tab-panel <?php echo $initial_tab === 'profile' ? 'is-active' : ''; ?>" id="tab-profile" role="tabpanel" aria-labelledby="tabBtn-profile">
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

                <div class="form-group" style="margin-bottom:24px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#334155;margin-bottom:8px;">
                        Company Banner (shown at the front of your company cards)
                    </label>
                    <div style="display:flex;align-items:center;gap:14px;">
                        <?php if (!empty($tenant['banner'])): ?>
                            <img src="<?php echo $BASE . htmlspecialchars($tenant['banner']); ?>" alt="Current Banner" style="width:120px;height:68px;border-radius:8px;object-fit:cover;border:1px solid var(--line);flex-shrink:0;">
                        <?php else: ?>
                            <div style="width:120px;height:68px;border-radius:8px;border:1px dashed var(--line);display:grid;place-items:center;color:var(--muted);font-size:11px;text-align:center;">No Banner
                            </div>
                        <?php endif; ?>
                        <div style="flex:1;">
                            <input type="file" name="company_banner" accept="image/*" style="font-size:13px;">
                            <small class="muted" style="display:block;margin-top:4px;font-size:11.5px;">PNG, JPG, GIF, WEBP - max 2MB, recommended 1200 x 400px</small>
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
                        <dd>
                            <?php 
                            if ($is_tenant && !empty($tenant['public_id'])) {
                                echo '<span style="display:inline-block;padding:4px 10px;background:rgba(99,102,241,0.1);color:#6366f1;border:1px solid rgba(99,102,241,0.2);border-radius:6px;font-family:monospace;font-size:12px;font-weight:600;letter-spacing:0.5px;">' . htmlspecialchars($tenant['public_id']) . '</span>';
                            } else {
                                echo '#' . (int)($is_tenant ? $tenant_id : ($_SESSION['admin_id'] ?? 1));
                            }
                            ?>
                        </dd>
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
<div class="admin-tab-panel <?php echo $initial_tab === 'subscription' ? 'is-active' : ''; ?>" id="tab-subscription" role="tabpanel" aria-labelledby="tabBtn-subscription">
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
<div class="admin-tab-panel <?php echo $initial_tab === 'security' ? 'is-active' : ''; ?>" id="tab-security" role="tabpanel" aria-labelledby="tabBtn-security">
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
                        <dd><span style="color:#10b981;font-weight:700;">● Authenticated &amp; Valid</span>
                            <?php if ($session_started): ?>
                                <span class="muted" style="font-weight:400;">&middot; since <?php echo htmlspecialchars(date('M d, H:i', $session_started)); ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>Automatic Sign-out</dt>
                        <dd>After <?php echo (int) round(AUTH_IDLE_TIMEOUT_ADMIN / 60); ?> minutes idle,
                            or <?php echo (int) round(AUTH_ABSOLUTE_TIMEOUT_ADMIN / 86400); ?> days</dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>Client IP Address</dt>
                        <dd><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'); ?></dd>
                    </div>
                    <div class="admin-kv-row">
                        <dt>This Device</dt>
                        <dd><?php echo htmlspecialchars(auth_describe_agent($_SERVER['HTTP_USER_AGENT'] ?? '')); ?></dd>
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
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
                    <strong style="font-size:13px;color:var(--ink);">Signed-in sessions</strong>
                    <form method="POST" action="settings.php" style="margin:0;">
                        <input type="hidden" name="action" value="logout_other_sessions">
                        <input type="hidden" name="logout_token" value="<?php echo htmlspecialchars(auth_logout_token()); ?>">
                        <button type="submit" class="btn btn-secondary" style="padding:8px 16px;font-size:12.5px;"
                                data-admin-confirm="Sign out of every other browser? This one stays signed in.">
                            Sign out everywhere else
                        </button>
                    </form>
                </div>
                <?php if ($my_sessions): ?>
                    <ul class="muted" style="margin:0;padding-left:18px;font-size:12px;line-height:1.8;">
                        <?php foreach ($my_sessions as $sess): ?>
                            <li>
                                <?php echo htmlspecialchars(auth_describe_agent($sess['user_agent'])); ?>
                                &middot; <?php echo htmlspecialchars($sess['ip_address'] ?: 'unknown IP'); ?>
                                &middot; active <?php echo htmlspecialchars(timeAgo($sess['last_seen_at'] ?: $sess['created_at'])); ?>
                                <?php if ($sess['session_token'] === $my_token): ?>
                                    <strong style="color:var(--ink);">(this browser)</strong>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted" style="font-size:12px;line-height:1.5;margin:0;">
                        No session has been recorded for this account yet — sign-ins are tracked from the next login.
                    </p>
                <?php endif; ?>
            </div>

            <div style="margin-top:20px;padding:16px;border-radius:10px;background:var(--bg);border:1px solid var(--line);">
                <strong style="display:block;font-size:13px;color:var(--ink);margin-bottom:6px;">Security Tip</strong>
                <p class="muted" style="font-size:12px;line-height:1.5;margin:0;">
                    To protect your rating workspace, avoid using common phrases or reusing passwords from other online services.
                    Signing out closes this browser immediately; changing your password closes every other one.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     TAB 4: PREFERENCES & LINKS
     ============================================================ -->
<div class="admin-tab-panel <?php echo $initial_tab === 'preferences' ? 'is-active' : ''; ?>" id="tab-preferences" role="tabpanel" aria-labelledby="tabBtn-preferences">
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
                        <?php foreach ($usage_stats['companies'] as $comp): 
                            $c_uuid = !empty($comp['uuid']) ? $comp['uuid'] : getCompanyUuid($conn, (int)$comp['id']);
                        ?>
                            <option value="<?php echo (int)$comp['id']; ?>" data-name="<?php echo htmlspecialchars($comp['company_name']); ?>" data-slug="<?php echo htmlspecialchars(slugify($comp['company_name'])); ?>" data-uuid="<?php echo htmlspecialchars($c_uuid); ?>" data-url="<?php echo htmlspecialchars(getCompanyPublicRatingUrl($comp['id'], $comp['company_name'])); ?>">
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
                    <a href="company.php" class="btn btn-primary" style="padding:8px 18px;font-size:13px;">
                        + Add Your First Company
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         Website Embed Widget Generator (widget.php)
         ============================================================ -->
    <style>
        .w-btn-toggle {
            transition: all .2s ease;
        }
        .w-btn-toggle.is-active {
            border-color: var(--lime) !important;
            background: rgba(194, 245, 66, 0.18) !important;
            color: var(--ink) !important;
            font-weight: 700;
        }
        @media (max-width: 900px) {
            #widgetCustomizerGrid {
                grid-template-columns: 1fr !important;
            }
        }
    </style>

    <div class="form-card" style="margin-top:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px;">
            <div>
                <h3 style="margin:0;display:flex;align-items:center;gap:8px;">
                    <span>Website Embed Review Widget</span>
                    <span style="font-size:11px;background:rgba(194,245,66,.2);color:var(--ink);padding:2px 8px;border-radius:6px;font-weight:700;">widget.php</span>
                </h3>
                <p class="muted" style="margin:4px 0 0;">Embed live customer ratings, verified purchase badges, and top testimonials directly on your official website, WordPress, or online store.</p>
            </div>
            <span class="status-dot">● Embed Ready</span>
        </div>

        <div style="display:grid;grid-template-columns:1.1fr 0.9fr;gap:26px;align-items:start;" id="widgetCustomizerGrid">
            <!-- Left: Customizer Controls & Snippet -->
            <div>
                <div class="form-group" style="margin-bottom:16px;">
                    <label style="font-size:13px;font-weight:700;color:var(--ink);">1. Select Widget Layout</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;">
                        <button type="button" class="btn btn-secondary w-btn-toggle is-active" id="wBtnLayoutCard" onclick="setWidgetLayout('card')" style="text-align:left;padding:12px 14px;border-radius:10px;display:flex;flex-direction:column;gap:4px;">
                            <span style="font-size:13px;font-weight:700;color:var(--ink);">📇 Full Review Card</span>
                            <small class="muted" style="font-weight:400;font-size:11px;">Score hero + verified reviews stream + CTA</small>
                        </button>
                        <button type="button" class="btn btn-secondary w-btn-toggle" id="wBtnLayoutBadge" onclick="setWidgetLayout('badge')" style="text-align:left;padding:12px 14px;border-radius:10px;display:flex;flex-direction:column;gap:4px;">
                            <span style="font-size:13px;font-weight:700;color:var(--ink);">🏷️ Compact Badge</span>
                            <small class="muted" style="font-weight:400;font-size:11px;">Minimal pill badge for header, footer &amp; checkout</small>
                        </button>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label style="font-size:13px;font-weight:700;color:var(--ink);">2. Choose Color Theme</label>
                    <div style="display:flex;gap:10px;margin-top:6px;">
                        <button type="button" class="btn btn-secondary w-btn-toggle is-active" id="wBtnThemeLight" onclick="setWidgetTheme('light')" style="flex:1;padding:10px;font-size:13px;">
                            ☀️ Light Mode
                        </button>
                        <button type="button" class="btn btn-secondary w-btn-toggle" id="wBtnThemeDark" onclick="setWidgetTheme('dark')" style="flex:1;padding:10px;font-size:13px;">
                            🌙 Dark Mode
                        </button>
                    </div>
                </div>

                <?php if (!empty($usage_stats['companies']) && count($usage_stats['companies']) > 1): ?>
                <div class="form-group" style="margin-bottom:16px;">
                    <label for="wCompanySelect" style="font-size:13px;font-weight:700;color:var(--ink);">3. Target Listing</label>
                    <select id="wCompanySelect" onchange="updateWidgetCode()" style="margin-top:6px;width:100%;padding:9px 12px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;">
                        <option value="tenant" selected>All Workspace Listings (Tenant Default)</option>
                        <?php foreach ($usage_stats['companies'] as $comp): ?>
                            <option value="company-<?php echo (int)$comp['id']; ?>">
                                <?php echo htmlspecialchars($comp['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group" style="margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;">
                        <label style="font-size:13px;font-weight:700;color:var(--ink);margin:0;">HTML Embed Snippet</label>
                        <span class="muted" style="font-size:11.5px;">Iframe tag with zero script dependencies</span>
                    </div>
                    <textarea id="wEmbedCodeText" readonly rows="3" style="width:100%;font-family:monospace;font-size:12px;padding:10px 12px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);resize:none;line-height:1.5;"></textarea>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-primary" id="copyWidgetBtn" onclick="copyWidgetEmbedCode()" style="padding:10px 22px;">
                        📋 Copy Embed Code
                    </button>
                    <a id="wOpenDirectLink" href="<?php echo htmlspecialchars($widget_default_url); ?>&theme=light&layout=card" target="_blank" rel="noopener" class="btn btn-secondary" style="padding:10px 16px;">
                        ↗ Open in New Window
                    </a>
                </div>

                <div style="margin-top:16px;padding:12px 14px;background:var(--bg);border-radius:8px;border:1px solid var(--line);font-size:12px;color:var(--muted);line-height:1.5;">
                    💡 <strong>Quick Install Guide:</strong>
                    <ul style="margin:6px 0 0;padding-left:16px;">
                        <li><strong>WordPress:</strong> Add a <em>Custom HTML</em> block anywhere on a page or sidebar, and paste the code.</li>
                        <li><strong>Shopify / Wix / Squarespace:</strong> Insert an <em>Embed / Custom Code</em> block into your theme.</li>
                        <li><strong>Any Website:</strong> Paste the snippet inside your HTML where you want the reviews to appear.</li>
                    </ul>
                </div>
            </div>

            <!-- Right: Live Interactive Preview -->
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <label style="font-size:13px;font-weight:700;color:var(--ink);">Live Widget Preview</label>
                    <span class="muted" style="font-size:11.5px;" id="wPreviewSizeNote">Card: 100% × 340px</span>
                </div>

                <div style="padding:20px;background:var(--bg);border-radius:12px;border:1px dashed var(--line);display:flex;align-items:center;justify-content:center;min-height:360px;" id="wPreviewWrap">
                    <iframe id="wPreviewIframe" src="<?php echo htmlspecialchars($widget_default_url); ?>&theme=light&layout=card" style="width:100%;height:340px;border:none;border-radius:14px;overflow:hidden;background:transparent;" frameborder="0"></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     TAB: PUBLIC PAGE CUSTOMISATION
     ============================================================ -->
<div class="admin-tab-panel <?php echo $initial_tab === 'customization' ? 'is-active' : ''; ?>" id="tab-customization" role="tabpanel" aria-labelledby="tabBtn-customization">
    <style>
    /* ── Public Page Customizer Styles ── */
    .customizer-banner {
        background: #ffffff;
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 20px 24px;
        margin-bottom: 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 14px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    :root[data-theme='dark'] .customizer-banner {
        background: #0f1f2e;
        border-color: rgba(255,255,255,0.08);
    }
    .customizer-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.25fr) minmax(320px, 0.95fr);
        gap: 24px;
        align-items: start;
    }
    @media (max-width: 990px) {
        .customizer-grid {
            grid-template-columns: 1fr;
        }
        .customizer-preview-col {
            position: static !important;
        }
    }
    .customizer-card {
        background: #ffffff;
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 22px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .collapsible-card.is-collapsed {
        padding-bottom: 22px;
    }
    :root[data-theme='dark'] .customizer-card {
        background: #0f1f2e;
        border-color: rgba(255,255,255,0.08);
        box-shadow: none;
    }
    .customizer-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 14px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--line);
        cursor: pointer;
        user-select: none;
        transition: all 0.2s ease;
    }
    .collapsible-card.is-collapsed .customizer-card-header {
        margin-bottom: 0;
        padding-bottom: 0;
        border-bottom: none;
    }
    .customizer-card-header:hover .customizer-card-title {
        color: #10b981;
    }
    :root[data-theme='dark'] .customizer-card-header:hover .customizer-card-title {
        color: var(--lime);
    }
    .customizer-card-title {
        margin: 0;
        font-size: 14.5px;
        font-weight: 800;
        color: var(--ink);
        display: flex;
        align-items: center;
        gap: 8px;
        transition: color 0.15s ease;
    }
    .customizer-card-desc {
        margin: 4px 0 0;
        font-size: 12px;
        color: var(--muted);
    }
    .customizer-collapse-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: transparent;
        border: none;
        color: var(--muted);
        font-size: 13px;
        cursor: pointer;
        padding: 4px;
    }
    .customizer-collapse-icon {
        display: inline-block;
        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        font-size: 11px;
        color: var(--muted);
        user-select: none;
    }
    .collapsible-card.is-collapsed .customizer-collapse-icon {
        transform: rotate(-90deg);
    }
    .preset-card-btn {
        border: 1.5px solid var(--line);
        background: var(--bg);
        border-radius: 10px;
        padding: 12px 10px;
        cursor: pointer;
        text-align: left;
        display: flex;
        flex-direction: column;
        gap: 6px;
        transition: all 0.15s ease;
    }
    .preset-card-btn:hover {
        border-color: #cbd5e1;
        transform: translateY(-1px);
    }
    :root[data-theme='dark'] .preset-card-btn {
        background: rgba(255,255,255,0.04);
        border-color: rgba(255,255,255,0.08);
    }
    :root[data-theme='dark'] .preset-card-btn:hover {
        background: rgba(255,255,255,0.07);
        border-color: rgba(194,245,66,0.3);
    }
    .custom-radio-card {
        border: 1.5px solid var(--line);
        padding: 12px 14px;
        border-radius: 10px;
        cursor: pointer;
        display: flex;
        gap: 10px;
        align-items: flex-start;
        background: var(--bg);
        transition: all 0.15s ease;
    }
    .custom-radio-card:hover {
        border-color: #cbd5e1;
    }
    .custom-radio-card input[type="radio"]:checked + div strong {
        color: var(--navy);
    }
    :root[data-theme='dark'] .custom-radio-card {
        background: rgba(255,255,255,0.03);
        border-color: rgba(255,255,255,0.08);
    }
    :root[data-theme='dark'] .custom-radio-card:hover {
        background: rgba(255,255,255,0.06);
    }
    :root[data-theme='dark'] .custom-radio-card input[type="radio"]:checked + div strong {
        color: var(--lime);
    }
    .custom-toggle-card {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 9px;
        cursor: pointer;
        font-size: 13px;
        color: var(--ink);
        transition: all 0.15s ease;
    }
    .custom-toggle-card:hover {
        border-color: #cbd5e1;
    }
    :root[data-theme='dark'] .custom-toggle-card {
        background: rgba(255,255,255,0.03);
        border-color: rgba(255,255,255,0.08);
    }
    .customizer-save-card {
        background: #ffffff;
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 16px 20px;
        margin-top: 24px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    }
    :root[data-theme='dark'] .customizer-save-card {
        background: #0f1f2e;
        border-color: rgba(255,255,255,0.08);
    }
    .customizer-preview-frame {
        padding: 16px;
        border-radius: 16px;
        border: 1px solid var(--line);
        background: #f8fafc;
        box-shadow: 0 8px 30px rgba(0,0,0,0.06);
        transition: all 0.25s ease;
    }
    :root[data-theme='dark'] .customizer-preview-frame {
        border-color: rgba(255,255,255,0.08);
    }
    /* Color Palette Settings Cards */
    .color-settings-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    @media (max-width: 680px) {
        .color-settings-grid {
            grid-template-columns: 1fr;
        }
    }
    .color-setting-item {
        background: var(--bg);
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 12px 14px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        transition: all 0.2s ease;
    }
    .color-setting-item:hover {
        border-color: #cbd5e1;
        box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }
    :root[data-theme='dark'] .color-setting-item {
        background: rgba(255,255,255,0.025);
        border-color: rgba(255,255,255,0.08);
    }
    :root[data-theme='dark'] .color-setting-item:hover {
        border-color: rgba(255,255,255,0.15);
        background: rgba(255,255,255,0.04);
    }
    .color-setting-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
    }
    .color-setting-title {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--ink);
        margin: 0;
        cursor: pointer;
    }
    .color-setting-hint {
        font-size: 11px;
        color: var(--muted);
        background: rgba(0,0,0,0.04);
        padding: 2px 7px;
        border-radius: 4px;
        white-space: nowrap;
        font-weight: 500;
    }
    :root[data-theme='dark'] .color-setting-hint {
        background: rgba(255,255,255,0.06);
        color: #94a3b8;
    }
    .color-input-pair {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
    }
    .color-swatch-wrapper {
        position: relative;
        width: 38px;
        height: 38px;
        min-width: 38px;
        max-width: 38px;
        border-radius: 9px;
        overflow: hidden;
        cursor: pointer;
        box-shadow: 0 1px 3px rgba(0,0,0,0.12), inset 0 0 0 1px rgba(0,0,0,0.08);
        flex-shrink: 0;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .color-swatch-wrapper:hover {
        transform: scale(1.05);
        box-shadow: 0 2px 6px rgba(0,0,0,0.2), inset 0 0 0 1px rgba(0,0,0,0.15);
    }
    input[type="color"].color-picker-swatch {
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        position: absolute;
        top: -8px;
        left: -8px;
        width: calc(100% + 16px) !important;
        height: calc(100% + 16px) !important;
        min-width: 0 !important;
        max-width: none !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        outline: none !important;
        background: none !important;
        cursor: pointer;
    }
    input[type="color"].color-picker-swatch::-webkit-color-swatch-wrapper {
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
    }
    input[type="color"].color-picker-swatch::-webkit-color-swatch {
        border: none !important;
        padding: 0 !important;
    }
    input[type="color"].color-picker-swatch::-moz-color-swatch {
        border: none !important;
        padding: 0 !important;
    }
    .color-hex-text {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace !important;
        text-transform: uppercase;
        font-size: 13px !important;
        font-weight: 600 !important;
        letter-spacing: 0.5px;
        width: 100% !important;
        height: 38px !important;
        padding: 0 12px !important;
        border: 1px solid var(--line) !important;
        border-radius: 8px !important;
        background: #ffffff !important;
        color: var(--ink) !important;
        box-sizing: border-box !important;
        transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
    }
    .color-hex-text:focus {
        outline: none !important;
        border-color: #10b981 !important;
        box-shadow: 0 0 0 3px rgba(16,185,129,0.15) !important;
    }
    :root[data-theme='dark'] .color-hex-text {
        background: #0b1520 !important;
        border-color: rgba(255,255,255,0.12) !important;
        color: #f1f5f9 !important;
    }
    :root[data-theme='dark'] .color-hex-text:focus {
        border-color: var(--lime) !important;
        box-shadow: 0 0 0 3px rgba(194,245,66,0.15) !important;
    }
    .device-toggle-btn {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid var(--line);
        background: var(--bg);
        color: var(--muted);
        transition: all 0.15s ease;
    }
    .device-toggle-btn.is-active {
        background: var(--navy);
        color: var(--lime);
        border-color: transparent;
    }
    :root[data-theme='dark'] .device-toggle-btn.is-active {
        background: rgba(194,245,66,0.15);
        color: var(--lime);
        border-color: rgba(194,245,66,0.25);
    }
    </style>

    <!-- Header Banner -->
    <div class="customizer-banner">
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap;">
                <h3 style="margin:0;font-size:18px;color:var(--ink);font-weight:800;">Public Rating Page Customisation</h3>
                <span style="font-size:11px;background:rgba(194,245,66,.2);color:var(--ink);padding:3px 8px;border-radius:6px;font-weight:700;font-family:monospace;">rate/index.php</span>
            </div>
            <p class="muted" style="margin:0;font-size:13px;">Customise the visual appearance, color palette, container layout, and component visibility for your customer review portal.</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button type="submit" form="publicCustomizerForm" class="btn btn-primary" style="padding:9px 18px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:6px;">
                <span>✓</span> Save Changes
            </button>
            <?php if (!empty($public_review_url)): ?>
            <a href="<?php echo htmlspecialchars($public_review_url); ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding:9px 14px;font-size:12.5px;display:inline-flex;align-items:center;gap:6px;">
                <span>↗</span> View Live Page
            </a>
            <?php endif; ?>
            <form method="POST" action="settings.php" onsubmit="return confirm('Reset all layout and color customisations back to system defaults?');" style="margin:0;">
                <input type="hidden" name="action" value="reset_public_customization">
                <button type="submit" class="btn btn-secondary" style="padding:9px 13px;font-size:12.5px;color:#ef4444;" title="Revert to original theme defaults">
                    ↺ Reset Defaults
                </button>
            </form>
        </div>
    </div>

    <!-- Main Customizer Grid -->
    <div class="customizer-grid" id="publicCustomizerGrid">
        <!-- Left: Customizer Form -->
        <form method="POST" action="settings.php" id="publicCustomizerForm">
            <input type="hidden" name="action" value="update_public_customization">

            <!-- Card 1: 1-Click Color Presets -->
            <div class="collapsible-card customizer-card is-collapsed">
                <div class="customizer-card-header" onclick="toggleSettingsSection(this)">
                    <div>
                        <h4 class="customizer-card-title">
                            <span>🎨</span> 1. Quick Theme Presets
                        </h4>
                        <p class="customizer-card-desc">Instant curated color palettes for high conversion and brand harmony.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="status-dot">● 1-Click</span>
                        <span class="customizer-collapse-icon collapse-icon">▼</span>
                    </div>
                </div>

                <div class="collapsible-content" style="display: none;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(130px, 1fr));gap:10px;" id="themePresetsGrid">
                        <button type="button" class="preset-card-btn" onclick="applyColorPreset('emerald')">
                            <div style="display:flex;gap:5px;">
                                <span style="width:14px;height:14px;border-radius:50%;background:#10b981;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#f59e0b;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#f8fafc;border:1px solid #cbd5e1;"></span>
                            </div>
                            <strong style="font-size:12px;color:var(--ink);">Emerald &amp; Lime</strong>
                            <span class="muted" style="font-size:11px;">Default Fresh</span>
                        </button>

                        <button type="button" class="preset-card-btn" onclick="applyColorPreset('indigo')">
                            <div style="display:flex;gap:5px;">
                                <span style="width:14px;height:14px;border-radius:50%;background:#4f46e5;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#f59e0b;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#f5f3ff;border:1px solid #c7d2fe;"></span>
                            </div>
                            <strong style="font-size:12px;color:var(--ink);">Royal Indigo</strong>
                            <span class="muted" style="font-size:11px;">Modern SaaS</span>
                        </button>

                        <button type="button" class="preset-card-btn" onclick="applyColorPreset('ocean')">
                            <div style="display:flex;gap:5px;">
                                <span style="width:14px;height:14px;border-radius:50%;background:#0284c7;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#f59e0b;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#f0f9ff;border:1px solid #bae6fd;"></span>
                            </div>
                            <strong style="font-size:12px;color:var(--ink);">Ocean Azure</strong>
                            <span class="muted" style="font-size:11px;">Medical &amp; Corp</span>
                        </button>

                        <button type="button" class="preset-card-btn" onclick="applyColorPreset('crimson')">
                            <div style="display:flex;gap:5px;">
                                <span style="width:14px;height:14px;border-radius:50%;background:#e11d48;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#f59e0b;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#fff1f2;border:1px solid #fecdd3;"></span>
                            </div>
                            <strong style="font-size:12px;color:var(--ink);">Crimson Rose</strong>
                            <span class="muted" style="font-size:11px;">Hospitality</span>
                        </button>

                        <button type="button" class="preset-card-btn" onclick="applyColorPreset('amber')">
                            <div style="display:flex;gap:5px;">
                                <span style="width:14px;height:14px;border-radius:50%;background:#d97706;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#f59e0b;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#fffbeb;border:1px solid #fde68a;"></span>
                            </div>
                            <strong style="font-size:12px;color:var(--ink);">Sunset Amber</strong>
                            <span class="muted" style="font-size:11px;">Warm Energetic</span>
                        </button>

                        <button type="button" class="preset-card-btn" onclick="applyColorPreset('midnight')">
                            <div style="display:flex;gap:5px;">
                                <span style="width:14px;height:14px;border-radius:50%;background:#10b981;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#fbbf24;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#0a1926;border:1px solid #1e293b;"></span>
                            </div>
                            <strong style="font-size:12px;color:var(--ink);">Dark Midnight</strong>
                            <span class="muted" style="font-size:11px;">Dark Mode</span>
                        </button>

                        <button type="button" class="preset-card-btn" onclick="applyColorPreset('obsidian')">
                            <div style="display:flex;gap:5px;">
                                <span style="width:14px;height:14px;border-radius:50%;background:#6366f1;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#fbbf24;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#090d16;border:1px solid #1f2937;"></span>
                            </div>
                            <strong style="font-size:12px;color:var(--ink);">Obsidian Cyber</strong>
                            <span class="muted" style="font-size:11px;">Deep Obsidian</span>
                        </button>

                        <button type="button" class="preset-card-btn" onclick="applyColorPreset('monochrome')">
                            <div style="display:flex;gap:5px;">
                                <span style="width:14px;height:14px;border-radius:50%;background:#0f172a;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#f59e0b;"></span>
                                <span style="width:14px;height:14px;border-radius:50%;background:#ffffff;border:1px solid #cbd5e1;"></span>
                            </div>
                            <strong style="font-size:12px;color:var(--ink);">Clean Slate</strong>
                            <span class="muted" style="font-size:11px;">Monochrome</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Card 2: Detailed Color Palette -->
            <div class="collapsible-card customizer-card is-collapsed">
                <div class="customizer-card-header" onclick="toggleSettingsSection(this)">
                    <div>
                        <h4 class="customizer-card-title">
                            <span>✨</span> 2. Brand Colors &amp; Styling
                        </h4>
                        <p class="customizer-card-desc">Fine-tune individual colors for actions, buttons, cards, and rating stars.</p>
                    </div>
                    <span class="customizer-collapse-icon collapse-icon">▼</span>
                </div>

                <div class="collapsible-content" style="display: none;">
                    <div class="color-settings-grid">
                        <!-- Primary Action Color -->
                        <div class="color-setting-item">
                            <div class="color-setting-header">
                                <label for="c_primary" class="color-setting-title">Primary Action Color</label>
                                <span class="color-setting-hint">Buttons, Active Tabs</span>
                            </div>
                            <div class="color-input-pair">
                                <div class="color-swatch-wrapper" title="Click to pick color">
                                    <input type="color" id="c_primary_picker" class="color-picker-swatch" value="<?php echo htmlspecialchars($page_customization['primary_color'] ?? '#10b981'); ?>">
                                </div>
                                <input type="text" id="c_primary" name="primary_color" class="color-hex-text" value="<?php echo htmlspecialchars($page_customization['primary_color'] ?? '#10b981'); ?>" maxlength="7" spellcheck="false">
                            </div>
                        </div>

                        <!-- Secondary / Hover Color -->
                        <div class="color-setting-item">
                            <div class="color-setting-header">
                                <label for="c_secondary" class="color-setting-title">Hover / Accent Color</label>
                                <span class="color-setting-hint">Button Hover States</span>
                            </div>
                            <div class="color-input-pair">
                                <div class="color-swatch-wrapper" title="Click to pick color">
                                    <input type="color" id="c_secondary_picker" class="color-picker-swatch" value="<?php echo htmlspecialchars($page_customization['secondary_color'] ?? '#059669'); ?>">
                                </div>
                                <input type="text" id="c_secondary" name="secondary_color" class="color-hex-text" value="<?php echo htmlspecialchars($page_customization['secondary_color'] ?? '#059669'); ?>" maxlength="7" spellcheck="false">
                            </div>
                        </div>

                        <!-- Page Background -->
                        <div class="color-setting-item">
                            <div class="color-setting-header">
                                <label for="c_page_bg" class="color-setting-title">Page Background</label>
                                <span class="color-setting-hint">Outer Portal Canvas</span>
                            </div>
                            <div class="color-input-pair">
                                <div class="color-swatch-wrapper" title="Click to pick color">
                                    <input type="color" id="c_page_bg_picker" class="color-picker-swatch" value="<?php echo htmlspecialchars($page_customization['page_bg'] ?? '#f8fafc'); ?>">
                                </div>
                                <input type="text" id="c_page_bg" name="page_bg" class="color-hex-text" value="<?php echo htmlspecialchars($page_customization['page_bg'] ?? '#f8fafc'); ?>" maxlength="7" spellcheck="false">
                            </div>
                        </div>

                        <!-- Card / Surface Background -->
                        <div class="color-setting-item">
                            <div class="color-setting-header">
                                <label for="c_card_bg" class="color-setting-title">Container Background</label>
                                <span class="color-setting-hint">Main Card Surface</span>
                            </div>
                            <div class="color-input-pair">
                                <div class="color-swatch-wrapper" title="Click to pick color">
                                    <input type="color" id="c_card_bg_picker" class="color-picker-swatch" value="<?php echo htmlspecialchars($page_customization['card_bg'] ?? '#ffffff'); ?>">
                                </div>
                                <input type="text" id="c_card_bg" name="card_bg" class="color-hex-text" value="<?php echo htmlspecialchars($page_customization['card_bg'] ?? '#ffffff'); ?>" maxlength="7" spellcheck="false">
                            </div>
                        </div>

                        <!-- Card Border Color -->
                        <div class="color-setting-item">
                            <div class="color-setting-header">
                                <label for="c_card_border" class="color-setting-title">Card Border Color</label>
                                <span class="color-setting-hint">Dividers &amp; Outlines</span>
                            </div>
                            <div class="color-input-pair">
                                <div class="color-swatch-wrapper" title="Click to pick color">
                                    <input type="color" id="c_card_border_picker" class="color-picker-swatch" value="<?php echo htmlspecialchars($page_customization['card_border'] ?? '#e2e8f0'); ?>">
                                </div>
                                <input type="text" id="c_card_border" name="card_border" class="color-hex-text" value="<?php echo htmlspecialchars($page_customization['card_border'] ?? '#e2e8f0'); ?>" maxlength="7" spellcheck="false">
                            </div>
                        </div>

                        <!-- Star Rating Color -->
                        <div class="color-setting-item">
                            <div class="color-setting-header">
                                <label for="c_star" class="color-setting-title">Rating Stars Color</label>
                                <span class="color-setting-hint">★ Star Icons</span>
                            </div>
                            <div class="color-input-pair">
                                <div class="color-swatch-wrapper" title="Click to pick color">
                                    <input type="color" id="c_star_picker" class="color-picker-swatch" value="<?php echo htmlspecialchars($page_customization['star_color'] ?? '#f59e0b'); ?>">
                                </div>
                                <input type="text" id="c_star" name="star_color" class="color-hex-text" value="<?php echo htmlspecialchars($page_customization['star_color'] ?? '#f59e0b'); ?>" maxlength="7" spellcheck="false">
                            </div>
                        </div>

                        <!-- Primary Headings & Text -->
                        <div class="color-setting-item">
                            <div class="color-setting-header">
                                <label for="c_text" class="color-setting-title">Headings &amp; Text</label>
                                <span class="color-setting-hint">Main Typography</span>
                            </div>
                            <div class="color-input-pair">
                                <div class="color-swatch-wrapper" title="Click to pick color">
                                    <input type="color" id="c_text_picker" class="color-picker-swatch" value="<?php echo htmlspecialchars($page_customization['text_color'] ?? '#0f172a'); ?>">
                                </div>
                                <input type="text" id="c_text" name="text_color" class="color-hex-text" value="<?php echo htmlspecialchars($page_customization['text_color'] ?? '#0f172a'); ?>" maxlength="7" spellcheck="false">
                            </div>
                        </div>

                        <!-- Muted Text -->
                        <div class="color-setting-item">
                            <div class="color-setting-header">
                                <label for="c_muted" class="color-setting-title">Muted Subtext</label>
                                <span class="color-setting-hint">Timestamps, Labels</span>
                            </div>
                            <div class="color-input-pair">
                                <div class="color-swatch-wrapper" title="Click to pick color">
                                    <input type="color" id="c_muted_picker" class="color-picker-swatch" value="<?php echo htmlspecialchars($page_customization['muted_color'] ?? '#64748b'); ?>">
                                </div>
                                <input type="text" id="c_muted" name="muted_color" class="color-hex-text" value="<?php echo htmlspecialchars($page_customization['muted_color'] ?? '#64748b'); ?>" maxlength="7" spellcheck="false">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 3: Layout & Structure -->
            <div class="collapsible-card customizer-card is-collapsed">
                <div class="customizer-card-header" onclick="toggleSettingsSection(this)">
                    <div>
                        <h4 class="customizer-card-title">
                            <span>📐</span> 3. Layout &amp; Container Structure
                        </h4>
                        <p class="customizer-card-desc">Configure container width, header orientation, and review presentation mode.</p>
                    </div>
                    <span class="customizer-collapse-icon collapse-icon">▼</span>
                </div>

                <div class="collapsible-content" style="display: none;">
                    <!-- Container Width / Style -->
                    <div class="form-group" style="margin-bottom:18px;">
                        <label style="font-size:13px;font-weight:700;margin-bottom:8px;display:block;color:var(--ink);">Page Container Style</label>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <?php $curr_layout = $page_customization['layout_style'] ?? 'modern_boxed'; ?>
                            <label class="custom-radio-card">
                                <input type="radio" name="layout_style" value="modern_boxed" <?php echo $curr_layout === 'modern_boxed' ? 'checked' : ''; ?> onchange="syncCustomizerPreview()" style="margin-top:2px;">
                                <div>
                                    <strong style="font-size:13px;color:var(--ink);display:block;">Modern Boxed</strong>
                                    <span class="muted" style="font-size:11.5px;">Max-width 1160px card with subtle shadow.</span>
                                </div>
                            </label>

                            <label class="custom-radio-card">
                                <input type="radio" name="layout_style" value="wide_compact" <?php echo $curr_layout === 'wide_compact' ? 'checked' : ''; ?> onchange="syncCustomizerPreview()" style="margin-top:2px;">
                                <div>
                                    <strong style="font-size:13px;color:var(--ink);display:block;">Wide View</strong>
                                    <span class="muted" style="font-size:11.5px;">Max-width 1360px expanded presentation.</span>
                                </div>
                            </label>

                            <label class="custom-radio-card">
                                <input type="radio" name="layout_style" value="minimal_clean" <?php echo $curr_layout === 'minimal_clean' ? 'checked' : ''; ?> onchange="syncCustomizerPreview()" style="margin-top:2px;">
                                <div>
                                    <strong style="font-size:13px;color:var(--ink);display:block;">Minimal Streamlined</strong>
                                    <span class="muted" style="font-size:11.5px;">Max-width 880px single-column focus.</span>
                                </div>
                            </label>

                            <label class="custom-radio-card">
                                <input type="radio" name="layout_style" value="full_width" <?php echo $curr_layout === 'full_width' ? 'checked' : ''; ?> onchange="syncCustomizerPreview()" style="margin-top:2px;">
                                <div>
                                    <strong style="font-size:13px;color:var(--ink);display:block;">Full Width</strong>
                                    <span class="muted" style="font-size:11.5px;">Fluid 100% width with edge padding.</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Header Alignment -->
                    <div class="form-group" style="margin-bottom:18px;">
                        <label style="font-size:13px;font-weight:700;margin-bottom:8px;display:block;color:var(--ink);">Header Alignment &amp; Format</label>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                            <?php $curr_header = $page_customization['header_layout'] ?? 'standard'; ?>
                            <label class="custom-radio-card">
                                <input type="radio" name="header_layout" value="standard" <?php echo $curr_header === 'standard' ? 'checked' : ''; ?> onchange="syncCustomizerPreview()" style="margin-top:2px;">
                                <div>
                                    <strong style="font-size:12.5px;color:var(--ink);display:block;">Standard Split</strong>
                                    <span class="muted" style="font-size:11px;">Logo left, actions right</span>
                                </div>
                            </label>

                            <label class="custom-radio-card">
                                <input type="radio" name="header_layout" value="centered" <?php echo $curr_header === 'centered' ? 'checked' : ''; ?> onchange="syncCustomizerPreview()" style="margin-top:2px;">
                                <div>
                                    <strong style="font-size:12.5px;color:var(--ink);display:block;">Centered Showcase</strong>
                                    <span class="muted" style="font-size:11px;">Prominent centered logo</span>
                                </div>
                            </label>

                            <label class="custom-radio-card">
                                <input type="radio" name="header_layout" value="compact" <?php echo $curr_header === 'compact' ? 'checked' : ''; ?> onchange="syncCustomizerPreview()" style="margin-top:2px;">
                                <div>
                                    <strong style="font-size:12.5px;color:var(--ink);display:block;">Compact Slim</strong>
                                    <span class="muted" style="font-size:11px;">Reduced vertical height</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Review Presentation Mode & Corner Rounding -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label for="review_layout_select" style="font-size:13px;font-weight:700;margin-bottom:6px;display:block;color:var(--ink);">Review Listing Mode</label>
                            <select id="review_layout_select" name="review_layout" onchange="syncCustomizerPreview()" style="width:100%;padding:10px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;">
                                <?php $curr_rev = $page_customization['review_layout'] ?? 'tabs'; ?>
                                <option value="tabs" <?php echo $curr_rev === 'tabs' ? 'selected' : ''; ?>>🗂️ Interactive Tabs (Feedbacks, Questions, Q&A)</option>
                                <option value="card_grid" <?php echo $curr_rev === 'card_grid' ? 'selected' : ''; ?>>🧱 2-Column Responsive Card Grid</option>
                                <option value="stacked" <?php echo $curr_rev === 'stacked' ? 'selected' : ''; ?>>📜 Continuous Scrolling Feed</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label for="border_radius_select" style="font-size:13px;font-weight:700;margin-bottom:6px;display:block;color:var(--ink);">Corner Rounding (Radius)</label>
                            <select id="border_radius_select" name="border_radius" onchange="syncCustomizerPreview()" style="width:100%;padding:10px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:13px;">
                                <?php $curr_rad = $page_customization['border_radius'] ?? 'rounded'; ?>
                                <option value="rounded" <?php echo $curr_rad === 'rounded' ? 'selected' : ''; ?>>Rounded (20px - Modern Smooth)</option>
                                <option value="subtle" <?php echo $curr_rad === 'subtle' ? 'selected' : ''; ?>>Subtle (8px - Clean &amp; Sleek)</option>
                                <option value="pill" <?php echo $curr_rad === 'pill' ? 'selected' : ''; ?>>Pill (28px - Soft &amp; Friendly)</option>
                                <option value="sharp" <?php echo $curr_rad === 'sharp' ? 'selected' : ''; ?>>Sharp (4px - Crisp &amp; Technical)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 4: Component Visibility Toggles -->
            <div class="collapsible-card customizer-card is-collapsed">
                <div class="customizer-card-header" onclick="toggleSettingsSection(this)">
                    <div>
                        <h4 class="customizer-card-title">
                            <span>👁️</span> 4. Feature &amp; Component Visibility
                        </h4>
                        <p class="customizer-card-desc">Toggle specific modules on or off on your public rating portal.</p>
                    </div>
                    <span class="customizer-collapse-icon collapse-icon">▼</span>
                </div>

                <div class="collapsible-content" style="display: none;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <label class="custom-toggle-card">
                            <input type="checkbox" name="show_banner" value="1" <?php echo !empty($page_customization['show_banner']) ? 'checked' : ''; ?> onchange="syncCustomizerPreview()">
                            <span>Cover Banner Image</span>
                        </label>

                        <label class="custom-toggle-card">
                            <input type="checkbox" name="show_breadcrumb" value="1" <?php echo !empty($page_customization['show_breadcrumb']) ? 'checked' : ''; ?> onchange="syncCustomizerPreview()">
                            <span>Breadcrumb Navigation</span>
                        </label>

                        <label class="custom-toggle-card">
                            <input type="checkbox" name="show_verified_badge" value="1" <?php echo !empty($page_customization['show_verified_badge']) ? 'checked' : ''; ?> onchange="syncCustomizerPreview()">
                            <span>Verified Channel Badge</span>
                        </label>

                        <label class="custom-toggle-card">
                            <input type="checkbox" name="show_rating_dist" value="1" <?php echo !empty($page_customization['show_rating_dist']) ? 'checked' : ''; ?> onchange="syncCustomizerPreview()">
                            <span>5-Star Breakdown Bars</span>
                        </label>

                        <label class="custom-toggle-card">
                            <input type="checkbox" name="show_services" value="1" <?php echo !empty($page_customization['show_services']) ? 'checked' : ''; ?> onchange="syncCustomizerPreview()">
                            <span>Services Showcase</span>
                        </label>

                        <label class="custom-toggle-card">
                            <input type="checkbox" name="show_qa" value="1" <?php echo !empty($page_customization['show_qa']) ? 'checked' : ''; ?> onchange="syncCustomizerPreview()">
                            <span>Community Q&amp;A Section</span>
                        </label>

                        <label class="custom-toggle-card">
                            <input type="checkbox" name="show_whatsapp" value="1" <?php echo !empty($page_customization['show_whatsapp']) ? 'checked' : ''; ?> onchange="syncCustomizerPreview()">
                            <span>WhatsApp Click-to-Chat Button</span>
                        </label>

                        <label class="custom-toggle-card">
                            <input type="checkbox" name="show_gstore" value="1" <?php echo !empty($page_customization['show_gstore']) ? 'checked' : ''; ?> onchange="syncCustomizerPreview()">
                            <span>Google Store Redirect Button</span>
                        </label>

                        <label class="custom-toggle-card" style="grid-column:span 2;">
                            <input type="checkbox" name="show_map" value="1" <?php echo !empty($page_customization['show_map']) ? 'checked' : ''; ?> onchange="syncCustomizerPreview()">
                            <span>Footer Location &amp; Directions Card</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Card 5: Custom CSS Code (Optional) -->
            <div class="collapsible-card customizer-card is-collapsed">
                <div class="customizer-card-header" onclick="toggleSettingsSection(this)">
                    <div>
                        <h4 class="customizer-card-title">
                            <span>💻</span> 5. Custom CSS Styling (Optional)
                        </h4>
                        <p class="customizer-card-desc">Add custom CSS rules to tailor fonts, borders, or spacing.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="muted" style="font-size:11.5px;background:var(--bg);padding:2px 8px;border-radius:6px;border:1px solid var(--line);">Advanced</span>
                        <span class="customizer-collapse-icon collapse-icon">▼</span>
                    </div>
                </div>

                <div class="collapsible-content" style="display: none;">
                    <textarea name="custom_css" rows="4" placeholder="/* e.g. .rt-submit-btn { font-size: 16px; } */" style="width:100%;font-family:monospace;font-size:12.5px;padding:12px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);line-height:1.5;resize:vertical;"><?php echo htmlspecialchars($page_customization['custom_css'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- In-flow Save Action Bar (Never floats or covers content) -->
            <div class="customizer-save-card">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:rgba(16,185,129,0.15);color:#10b981;display:grid;place-items:center;font-size:14px;font-weight:800;flex-shrink:0;">
                        ✓
                    </div>
                    <div>
                        <strong style="font-size:13px;color:var(--ink);display:block;">Ready to update your portal?</strong>
                        <span class="muted" style="font-size:12px;">Changes reflect in live preview instantly • Click Save to publish.</span>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="padding:10px 24px;font-size:13.5px;font-weight:700;display:inline-flex;align-items:center;gap:6px;">
                    <span>✓</span> Save Changes
                </button>
            </div>
        </form>

        <!-- Right: Sticky Live Interactive Visual Preview -->
        <div class="customizer-preview-col" style="position:sticky;top:88px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <label style="font-size:13px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:6px;">
                    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;"></span>
                    Live Portal Preview
                </label>
                <span class="muted" style="font-size:11.5px;">Auto-updates as you edit</span>
            </div>

            <!-- Outer Preview Frame Mockup -->
            <div id="pvOuter" class="customizer-preview-frame">
                <!-- Mini Breadcrumb -->
                <div id="pvBreadcrumb" style="display:flex;align-items:center;gap:6px;font-size:10.5px;color:#64748b;margin-bottom:12px;">
                    <span>Home</span> <span>›</span> <span>Directory</span> <span>›</span> <strong id="pvBrandBreadcrumb" style="color:#0f172a;"><?php echo htmlspecialchars($display_title); ?></strong>
                </div>

                <!-- Mini Container Card -->
                <div id="pvCard" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:20px;padding:20px;box-shadow:0 4px 18px rgba(0,0,0,0.04);transition:all .2s ease;">
                    <!-- Mini Banner -->
                    <div id="pvBanner" style="width:100%;height:70px;background:linear-gradient(135deg, rgba(16,185,129,0.15), rgba(5,150,105,0.25));border-radius:12px;margin-bottom:14px;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:11px;font-weight:600;border:1px dashed rgba(0,0,0,0.1);">
                        Cover Banner Preview
                    </div>

                    <!-- Mini Header -->
                    <div id="pvHeader" style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;margin-bottom:16px;flex-wrap:wrap;">
                        <div id="pvBrandWrap" style="display:flex;align-items:center;gap:10px;">
                            <div id="pvLogo" style="width:40px;height:40px;border-radius:10px;background:#10b981;color:#ffffff;display:grid;place-items:center;font-weight:800;font-size:14px;flex-shrink:0;">
                                <?php echo htmlspecialchars($display_initials ?: 'OP'); ?>
                            </div>
                            <div>
                                <div style="font-size:15px;font-weight:800;color:#0f172a;line-height:1.2;" id="pvTitle">
                                    ★ Rate <?php echo htmlspecialchars($display_title); ?>
                                </div>
                                <div style="font-size:10.5px;color:#64748b;margin-top:2px;">Verified Business Profile</div>
                            </div>
                        </div>
                        <div id="pvActions" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span id="pvBadge" style="font-size:10.5px;padding:3px 8px;border-radius:99px;background:#dcfce7;color:#15803d;font-weight:700;">✓ Verified</span>
                            <span id="pvWaBtn" style="font-size:10.5px;padding:4px 10px;border-radius:99px;background:#25D366;color:#ffffff;font-weight:700;">WhatsApp</span>
                            <span id="pvReviewJump" style="font-size:10.5px;padding:4px 10px;border-radius:99px;background:#10b981;color:#ffffff;font-weight:700;">Leave Review</span>
                        </div>
                    </div>

                    <!-- Mini Rating Overview Grid -->
                    <div style="display:grid;grid-template-columns:1fr 1.3fr;gap:14px;margin-bottom:16px;padding:12px;background:rgba(0,0,0,0.02);border-radius:12px;">
                        <!-- Score box -->
                        <div style="text-align:center;">
                            <div style="font-size:24px;font-weight:800;color:#0f172a;" id="pvScore">4.9</div>
                            <div id="pvStars" style="color:#f59e0b;font-size:13px;letter-spacing:1px;">★★★★★</div>
                            <div style="font-size:10px;color:#64748b;margin-top:2px;">Overall Satisfaction</div>
                        </div>
                        <!-- Bars breakdown -->
                        <div id="pvDistBars" style="display:flex;flex-direction:column;justify-content:center;gap:4px;">
                            <div style="display:flex;align-items:center;gap:6px;font-size:10px;">
                                <span style="width:20px;color:#64748b;">5 ★</span>
                                <div style="flex:1;height:5px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                                    <div id="pvBarFill" style="width:88%;height:100%;background:#10b981;border-radius:4px;"></div>
                                </div>
                                <span style="font-size:9.5px;color:#64748b;">88%</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;font-size:10px;">
                                <span style="width:20px;color:#64748b;">4 ★</span>
                                <div style="flex:1;height:5px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                                    <div style="width:12%;height:100%;background:#10b981;border-radius:4px;opacity:0.7;"></div>
                                </div>
                                <span style="font-size:9.5px;color:#64748b;">12%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Mini Nav Tabs -->
                    <div id="pvTabs" style="display:flex;gap:6px;border-bottom:1px solid #e2e8f0;margin-bottom:12px;padding-bottom:6px;">
                        <span id="pvTabActive" style="font-size:11px;font-weight:700;color:#10b981;border-bottom:2px solid #10b981;padding-bottom:4px;">Customer Feedbacks</span>
                        <span style="font-size:11px;color:#64748b;padding-bottom:4px;">Rating Items</span>
                        <span id="pvTabQa" style="font-size:11px;color:#64748b;padding-bottom:4px;">Community Q&amp;A</span>
                    </div>

                    <!-- Mini Review Card Sample -->
                    <div id="pvSampleCard" style="border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin-bottom:12px;background:#ffffff;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <strong style="font-size:12px;color:#0f172a;" id="pvReviewerName">Michael K.</strong>
                            <div id="pvReviewStars" style="color:#f59e0b;font-size:11px;">★★★★★</div>
                        </div>
                        <p style="font-size:11px;color:#475569;margin:0 0 6px 0;line-height:1.4;" id="pvReviewText">"Incredible customer support and seamless service! Highly recommend."</p>
                        <span style="font-size:9.5px;color:#15803d;background:#dcfce7;padding:2px 6px;border-radius:6px;font-weight:700;">✓ Verified Reviewer</span>
                    </div>

                    <!-- Mini Leave Review Action -->
                    <div style="text-align:center;padding-top:6px;">
                        <button type="button" id="pvSubmitBtn" class="btn" style="width:100%;padding:10px;background:#10b981;color:#ffffff;border:none;border-radius:10px;font-size:12.5px;font-weight:700;cursor:default;">
                            Submit Your Review ★
                        </button>
                    </div>
                </div>
            </div>
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

// Restore active tab on load from URL query (?tab=...) or hash (#tab=...)
(function () {
    var validTabs = ['profile', 'customization', 'preferences', 'subscription', 'security'];
    var targetTab = '';

    try {
        var urlParams = new URLSearchParams(window.location.search);
        var qTab = urlParams.get('tab');
        if (qTab && validTabs.indexOf(qTab) !== -1) {
            targetTab = qTab;
        }
    } catch (e) {}

    if (!targetTab && location.hash) {
        var hash = location.hash.replace('#tab=', '').replace('#', '');
        if (validTabs.indexOf(hash) !== -1) {
            targetTab = hash;
        }
    }

    if (targetTab && validTabs.indexOf(targetTab) !== -1) {
        switchAdminTab(targetTab);
        var btn = document.getElementById('tabBtn-' + targetTab);
        if (btn && typeof btn.scrollIntoView === 'function') {
            btn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    }
})();

/* ============================================================
   Public Page Customizer Live Preview & Presets
   ============================================================ */
var colorPresets = {
    emerald: {
        primary: '#10b981',
        secondary: '#059669',
        star: '#f59e0b',
        page_bg: '#f8fafc',
        card_bg: '#ffffff',
        card_border: '#e2e8f0',
        text: '#0f172a',
        muted: '#64748b',
        radius: 'rounded',
        layout: 'modern_boxed',
        header: 'standard'
    },
    indigo: {
        primary: '#4f46e5',
        secondary: '#4338ca',
        star: '#f59e0b',
        page_bg: '#f5f3ff',
        card_bg: '#ffffff',
        card_border: '#e0e7ff',
        text: '#0f172a',
        muted: '#64748b',
        radius: 'rounded',
        layout: 'modern_boxed',
        header: 'standard'
    },
    ocean: {
        primary: '#0284c7',
        secondary: '#0369a1',
        star: '#f59e0b',
        page_bg: '#f0f9ff',
        card_bg: '#ffffff',
        card_border: '#bae6fd',
        text: '#0f172a',
        muted: '#64748b',
        radius: 'rounded',
        layout: 'modern_boxed',
        header: 'standard'
    },
    crimson: {
        primary: '#e11d48',
        secondary: '#be123c',
        star: '#f59e0b',
        page_bg: '#fff1f2',
        card_bg: '#ffffff',
        card_border: '#fecdd3',
        text: '#0f172a',
        muted: '#64748b',
        radius: 'pill',
        layout: 'modern_boxed',
        header: 'centered'
    },
    amber: {
        primary: '#d97706',
        secondary: '#b45309',
        star: '#f59e0b',
        page_bg: '#fffbeb',
        card_bg: '#ffffff',
        card_border: '#fde68a',
        text: '#0f172a',
        muted: '#64748b',
        radius: 'rounded',
        layout: 'modern_boxed',
        header: 'standard'
    },
    midnight: {
        primary: '#10b981',
        secondary: '#059669',
        star: '#fbbf24',
        page_bg: '#0a1926',
        card_bg: '#0f2438',
        card_border: '#1e293b',
        text: '#f8fafc',
        muted: '#94a3b8',
        radius: 'rounded',
        layout: 'modern_boxed',
        header: 'standard'
    },
    obsidian: {
        primary: '#6366f1',
        secondary: '#4f46e5',
        star: '#fbbf24',
        page_bg: '#090d16',
        card_bg: '#111827',
        card_border: '#1f2937',
        text: '#f9fafb',
        muted: '#9ca3af',
        radius: 'subtle',
        layout: 'modern_boxed',
        header: 'standard'
    },
    monochrome: {
        primary: '#0f172a',
        secondary: '#1e293b',
        star: '#f59e0b',
        page_bg: '#f1f5f9',
        card_bg: '#ffffff',
        card_border: '#cbd5e1',
        text: '#0f172a',
        muted: '#64748b',
        radius: 'sharp',
        layout: 'modern_boxed',
        header: 'standard'
    }
};

function applyColorPreset(presetKey) {
    var p = colorPresets[presetKey];
    if (!p) return;

    setColorVal('c_primary', p.primary);
    setColorVal('c_secondary', p.secondary);
    setColorVal('c_star', p.star);
    setColorVal('c_page_bg', p.page_bg);
    setColorVal('c_card_bg', p.card_bg);
    setColorVal('c_card_border', p.card_border);
    setColorVal('c_text', p.text);
    setColorVal('c_muted', p.muted);

    if (p.radius) {
        var rSel = document.getElementById('border_radius_select');
        if (rSel) rSel.value = p.radius;
    }
    if (p.layout) {
        var rLayout = document.querySelector('input[name="layout_style"][value="' + p.layout + '"]');
        if (rLayout) rLayout.checked = true;
    }
    if (p.header) {
        var rHeader = document.querySelector('input[name="header_layout"][value="' + p.header + '"]');
        if (rHeader) rHeader.checked = true;
    }

    syncCustomizerPreview();
}

function setColorVal(id, hex) {
    var txt = document.getElementById(id);
    var pkr = document.getElementById(id + '_picker');
    if (txt) txt.value = hex;
    if (pkr) pkr.value = hex.indexOf('#') === 0 && hex.length === 7 ? hex : '#10b981';
}

function syncCustomizerPreview() {
    var primary    = (document.getElementById('c_primary') || {}).value || '#10b981';
    var secondary  = (document.getElementById('c_secondary') || {}).value || '#059669';
    var star       = (document.getElementById('c_star') || {}).value || '#f59e0b';
    var pageBg     = (document.getElementById('c_page_bg') || {}).value || '#f8fafc';
    var cardBg     = (document.getElementById('c_card_bg') || {}).value || '#ffffff';
    var cardBorder = (document.getElementById('c_card_border') || {}).value || '#e2e8f0';
    var textColor  = (document.getElementById('c_text') || {}).value || '#0f172a';
    var mutedColor = (document.getElementById('c_muted') || {}).value || '#64748b';

    var radiusSel = (document.getElementById('border_radius_select') || {}).value || 'rounded';
    var radPx = (radiusSel === 'subtle') ? '8px' : ((radiusSel === 'pill') ? '28px' : ((radiusSel === 'sharp') ? '4px' : '20px'));
    var innerRadPx = (radiusSel === 'sharp') ? '2px' : '10px';

    var headerRadio = document.querySelector('input[name="header_layout"]:checked');
    var headerMode = headerRadio ? headerRadio.value : 'standard';

    // Outer and Card Styles
    var pvOuter = document.getElementById('pvOuter');
    if (pvOuter) pvOuter.style.background = pageBg;

    var pvCard = document.getElementById('pvCard');
    if (pvCard) {
        pvCard.style.background = cardBg;
        pvCard.style.borderColor = cardBorder;
        pvCard.style.borderRadius = radPx;
        pvCard.style.color = textColor;
    }

    // Header elements
    var pvTitle = document.getElementById('pvTitle');
    if (pvTitle) pvTitle.style.color = textColor;

    var pvLogo = document.getElementById('pvLogo');
    if (pvLogo) {
        pvLogo.style.background = primary;
        pvLogo.style.borderRadius = innerRadPx;
    }

    var pvReviewJump = document.getElementById('pvReviewJump');
    if (pvReviewJump) {
        pvReviewJump.style.background = primary;
        pvReviewJump.style.borderRadius = (radiusSel === 'sharp') ? '2px' : '99px';
    }

    var pvSubmitBtn = document.getElementById('pvSubmitBtn');
    if (pvSubmitBtn) {
        pvSubmitBtn.style.background = primary;
        pvSubmitBtn.style.borderRadius = innerRadPx;
    }

    var pvBarFill = document.getElementById('pvBarFill');
    if (pvBarFill) pvBarFill.style.background = primary;

    var pvTabActive = document.getElementById('pvTabActive');
    if (pvTabActive) {
        pvTabActive.style.color = primary;
        pvTabActive.style.borderColor = primary;
    }

    // Stars
    var pvStars = document.getElementById('pvStars');
    if (pvStars) pvStars.style.color = star;

    var pvReviewStars = document.getElementById('pvReviewStars');
    if (pvReviewStars) pvReviewStars.style.color = star;

    // Header layout
    var pvHeader = document.getElementById('pvHeader');
    var pvBrandWrap = document.getElementById('pvBrandWrap');
    if (pvHeader && pvBrandWrap) {
        if (headerMode === 'centered') {
            pvHeader.style.flexDirection = 'column';
            pvHeader.style.textAlign = 'center';
            pvBrandWrap.style.flexDirection = 'column';
        } else if (headerMode === 'compact') {
            pvHeader.style.flexDirection = 'row';
            pvHeader.style.textAlign = 'left';
            pvBrandWrap.style.flexDirection = 'row';
            pvHeader.style.marginBottom = '8px';
            pvHeader.style.paddingBottom = '8px';
        } else {
            pvHeader.style.flexDirection = 'row';
            pvHeader.style.textAlign = 'left';
            pvBrandWrap.style.flexDirection = 'row';
            pvHeader.style.marginBottom = '16px';
            pvHeader.style.paddingBottom = '14px';
        }
    }

    // Visibility toggles
    var showBanner = document.querySelector('input[name="show_banner"]');
    var pvBanner = document.getElementById('pvBanner');
    if (pvBanner) pvBanner.style.display = (showBanner && !showBanner.checked) ? 'none' : 'flex';

    var showBreadcrumb = document.querySelector('input[name="show_breadcrumb"]');
    var pvBreadcrumb = document.getElementById('pvBreadcrumb');
    if (pvBreadcrumb) pvBreadcrumb.style.display = (showBreadcrumb && !showBreadcrumb.checked) ? 'none' : 'flex';

    var showBadge = document.querySelector('input[name="show_verified_badge"]');
    var pvBadge = document.getElementById('pvBadge');
    if (pvBadge) pvBadge.style.display = (showBadge && !showBadge.checked) ? 'none' : 'inline-block';

    var showWa = document.querySelector('input[name="show_whatsapp"]');
    var pvWaBtn = document.getElementById('pvWaBtn');
    if (pvWaBtn) pvWaBtn.style.display = (showWa && !showWa.checked) ? 'none' : 'inline-block';

    var showDist = document.querySelector('input[name="show_rating_dist"]');
    var pvDistBars = document.getElementById('pvDistBars');
    if (pvDistBars) pvDistBars.style.visibility = (showDist && !showDist.checked) ? 'hidden' : 'visible';

    var showQa = document.querySelector('input[name="show_qa"]');
    var pvTabQa = document.getElementById('pvTabQa');
    if (pvTabQa) pvTabQa.style.display = (showQa && !showQa.checked) ? 'none' : 'inline-block';
}

// Bind 2-way sync between text & color pickers
document.addEventListener('DOMContentLoaded', function() {
    var colorIds = ['c_primary', 'c_secondary', 'c_star', 'c_page_bg', 'c_card_bg', 'c_card_border', 'c_text', 'c_muted'];
    colorIds.forEach(function(cid) {
        var txt = document.getElementById(cid);
        var pkr = document.getElementById(cid + '_picker');
        if (txt && pkr) {
            pkr.addEventListener('input', function() {
                txt.value = pkr.value;
                syncCustomizerPreview();
            });
            txt.addEventListener('input', function() {
                if (txt.value.indexOf('#') === 0 && txt.value.length === 7) {
                    pkr.value = txt.value;
                }
                syncCustomizerPreview();
            });
        }
    });

    syncCustomizerPreview();
});

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
    var select = document.getElementById('companySelect');
    var input  = document.getElementById('shareUrlInput');
    var btn    = document.getElementById('previewRatingBtn');
    if (!input) return;

    var opt = select ? select.options[select.selectedIndex] : null;
    var url = opt && opt.getAttribute('data-url') ? opt.getAttribute('data-url') : '';
    if (!url && companyId) {
        var uuid = opt ? (opt.getAttribute('data-uuid') || '') : '';
        var root = '<?php echo rtrim($__root, "/"); ?>';
        if (uuid) {
            url = window.location.origin + root + '/' + encodeURIComponent(uuid);
        } else {
            var slug = opt ? (opt.getAttribute('data-slug') || '') : '';
            url = window.location.origin + root + '/rate/index.php?company=' + encodeURIComponent(companyId) + (slug ? '&tenant=' + encodeURIComponent(slug) : '');
        }
    }
    if (url) {
        input.value = url;
        if (btn) btn.href = url;
    }
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

/* ============================================================
   Website Review Widget (widget.php) Embed Logic
   ============================================================ */
var curWidgetLayout = 'card';
var curWidgetTheme  = 'light';

function setWidgetLayout(layout) {
    curWidgetLayout = layout;
    var btnCard  = document.getElementById('wBtnLayoutCard');
    var btnBadge = document.getElementById('wBtnLayoutBadge');
    if (btnCard) btnCard.classList.toggle('is-active', layout === 'card');
    if (btnBadge) btnBadge.classList.toggle('is-active', layout === 'badge');
    updateWidgetCode();
}

function setWidgetTheme(theme) {
    curWidgetTheme = theme;
    var btnLight = document.getElementById('wBtnThemeLight');
    var btnDark  = document.getElementById('wBtnThemeDark');
    if (btnLight) btnLight.classList.toggle('is-active', theme === 'light');
    if (btnDark) btnDark.classList.toggle('is-active', theme === 'dark');
    updateWidgetCode();
}

function updateWidgetCode() {
    var baseUrl = <?php echo json_encode($widget_base_url); ?>;
    var tenantId = <?php echo (int)$public_review_tenant_id; ?>;
    var queryParts = [];

    var compSelect = document.getElementById('wCompanySelect');
    if (compSelect && compSelect.value && compSelect.value.indexOf('company-') === 0) {
        var cId = compSelect.value.replace('company-', '');
        queryParts.push('company=' + encodeURIComponent(cId));
    } else if (tenantId > 0) {
        queryParts.push('tenant=' + tenantId);
    }

    queryParts.push('theme=' + curWidgetTheme);
    queryParts.push('layout=' + curWidgetLayout);

    var fullUrl = baseUrl + '?' + queryParts.join('&');

    // Layout dimension specs
    var width = '100%';
    var height = (curWidgetLayout === 'badge') ? '60px' : '340px';
    var maxW = (curWidgetLayout === 'badge') ? 'max-width:380px;' : 'max-width:100%;';

    var embedHtml = '<iframe src="' + fullUrl + '" width="' + width + '" height="' + height + '" frameborder="0" style="border:none;overflow:hidden;border-radius:14px;' + maxW + '"></iframe>';

    var codeText = document.getElementById('wEmbedCodeText');
    if (codeText) codeText.value = embedHtml;

    var previewIframe = document.getElementById('wPreviewIframe');
    if (previewIframe) {
        previewIframe.style.height = (curWidgetLayout === 'badge') ? '70px' : '340px';
        previewIframe.style.maxWidth = (curWidgetLayout === 'badge') ? '380px' : '100%';
        previewIframe.src = fullUrl;
    }

    var sizeNote = document.getElementById('wPreviewSizeNote');
    if (sizeNote) {
        sizeNote.innerText = (curWidgetLayout === 'badge') ? 'Badge: auto × 60px' : 'Card: 100% × 340px';
    }

    var directLink = document.getElementById('wOpenDirectLink');
    if (directLink) {
        directLink.href = fullUrl;
    }
}

function copyWidgetEmbedCode() {
    var ta = document.getElementById('wEmbedCodeText');
    if (!ta) return;
    ta.select();

    function onCopied() {
        var btn = document.getElementById('copyWidgetBtn');
        if (btn) {
            var orig = btn.innerText;
            btn.innerText = '✓ Copied to Clipboard!';
            setTimeout(function() { btn.innerText = orig; }, 2500);
        }
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ta.value).then(onCopied).catch(function() {
            document.execCommand('copy');
            onCopied();
        });
    } else {
        document.execCommand('copy');
        onCopied();
    }
}

// Initialize share link and widget on load
document.addEventListener('DOMContentLoaded', function() {
    var select = document.getElementById('companySelect');
    if (select && select.value) {
        updateShareLink(select.value);
    }
    updateWidgetCode();
});

// Toggle collapsible sections in settings page independently
function toggleSettingsSection(header) {
    const card = header.closest('.collapsible-card');
    if (!card) return;
    const content = card.querySelector('.collapsible-content');
    const icon = header.querySelector('.collapse-icon');
    if (!content) return;
    
    const isClosed = card.classList.contains('is-collapsed') || content.style.display === 'none' || window.getComputedStyle(content).display === 'none';
    if (isClosed) {
        card.classList.remove('is-collapsed');
        content.style.display = 'block';
        if (icon) icon.style.transform = 'rotate(0deg)';
    } else {
        card.classList.add('is-collapsed');
        content.style.display = 'none';
        if (icon) icon.style.transform = 'rotate(-90deg)';
    }
}
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>

