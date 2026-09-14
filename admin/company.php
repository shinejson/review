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
requireTeamAccess('company');

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

// Fetch ALL company profiles owned by this tenant (multi-branch / multi-location support).
$company_profiles   = [];
$primary_company_id = 0;
if ($tenant_id) {
    $cp = $conn->prepare("SELECT * FROM customers WHERE tenant_id=? ORDER BY id ASC");
    $cp->bind_param("i", $tenant_id);
    $cp->execute();
    $res_cp = $cp->get_result();
    while ($row_cp = $res_cp->fetch_assoc()) {
        $company_profiles[] = $row_cp;
    }
    $cp->close();
    $primary_company_id = (int)($company_profiles[0]['id'] ?? 0);
}

// Which profile is being edited? ?company=<id> must be owned by this tenant.
// ?new=1 starts a brand-new company profile (uses the INSERT branch on save).
$is_new_profile  = isset($_GET['new']);
$sel_company_id  = (int)($_GET['company'] ?? 0);
$company_profile = null;
if (!$is_new_profile) {
    foreach ($company_profiles as $cp_row) {
        if ($sel_company_id > 0 && (int)$cp_row['id'] === $sel_company_id) {
            $company_profile = $cp_row;
            break;
        }
    }
    if (!$company_profile) {
        $company_profile = $company_profiles[0] ?? null;
    }
}

$success = '';
$error   = '';

// ============================================================
// POST — save company profile
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $company_name  = trim($_POST['company_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $website       = trim($_POST['website'] ?? '');
    $google_url    = cleanGoogleReviewUrl($_POST['google_store_url'] ?? '');
    $booster_enabled = isset($_POST['booster_enabled']) ? 1 : 0;
    $booster_min_stars = max(1, min(5, (int)($_POST['booster_min_stars'] ?? 4)));
    $address       = trim($_POST['address'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $category_id   = (int)($_POST['category_id'] ?? 0);
    $whatsapp_raw  = trim($_POST['whatsapp_number'] ?? '');
    $whatsapp_num  = whatsappDigits($whatsapp_raw);

    // Google Maps directions & location description
    $google_map_url       = cleanMapUrl($_POST['google_map_url'] ?? '');
    $map_embed_code       = trim($_POST['map_embed_code'] ?? '');
    $location_description = trim($_POST['location_description'] ?? '');

    // Configured Social Media profiles
    $facebook_url  = cleanMapUrl($_POST['facebook_url'] ?? '');
    $instagram_url = cleanMapUrl($_POST['instagram_url'] ?? '');
    $twitter_url   = cleanMapUrl($_POST['twitter_url'] ?? '');
    $linkedin_url  = cleanMapUrl($_POST['linkedin_url'] ?? '');
    $tiktok_url    = cleanMapUrl($_POST['tiktok_url'] ?? '');
    $youtube_url   = cleanMapUrl($_POST['youtube_url'] ?? '');

    // Which company is being saved? (hidden field set by the form)
    $edit_company_id = (int)($_POST['company_id'] ?? 0);

    // Ownership guard: a tenant may only update their own company profiles.
    $owned = false;
    if ($edit_company_id > 0) {
        $own = $conn->prepare("SELECT id FROM customers WHERE id=? AND tenant_id=?");
        $own->bind_param("ii", $edit_company_id, $tenant_id);
        $own->execute();
        $owned = (bool)$own->get_result()->fetch_assoc();
        $own->close();
    }

    if (empty($company_name)) {
        $_SESSION['error'] = 'Company name is required.';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Please enter a valid email address.';
    } elseif ($whatsapp_raw !== '' && $whatsapp_num === '') {
        $_SESSION['error'] = 'That WhatsApp number is not usable. Enter it with the country code, e.g. +233 24 555 0118.';
    } elseif ($edit_company_id > 0 && !$owned) {
        $_SESSION['error'] = 'That company profile does not belong to your workspace.';
    } else {
        $cat_val = $category_id > 0 ? $category_id : null;

        // Ensure schema columns exist
        ensureWhatsappColumn($conn);
        ensureBoosterColumns($conn);
        ensureLocationAndSocialColumns($conn);

        if ($edit_company_id > 0) {
            $upd = $conn->prepare("UPDATE customers SET company_name=?,email=?,phone=?,whatsapp_number=?,website=?,google_store_url=?,booster_enabled=?,booster_min_stars=?,address=?,description=?,google_map_url=?,map_embed_code=?,location_description=?,facebook_url=?,instagram_url=?,twitter_url=?,linkedin_url=?,tiktok_url=?,youtube_url=?,category_id=? WHERE id=? AND tenant_id=?");
            $upd->bind_param("ssssssiisssssssssssiii", $company_name, $email, $phone, $whatsapp_num, $website, $google_url, $booster_enabled, $booster_min_stars, $address, $description, $google_map_url, $map_embed_code, $location_description, $facebook_url, $instagram_url, $twitter_url, $linkedin_url, $tiktok_url, $youtube_url, $cat_val, $edit_company_id, $tenant_id);
            $upd->execute();
            $upd->close();
            $saved_id = $edit_company_id;
            $_SESSION['success'] = 'Company profile saved successfully!';

            // Keep the tenant brand name in sync when the primary profile is updated.
            if ($edit_company_id === $primary_company_id) {
                $sync = $conn->prepare("UPDATE tenants SET company_name=? WHERE id=?");
                $sync->bind_param("si", $company_name, $tenant_id);
                $sync->execute();
                $sync->close();
            }
        } else {
            // Creating a brand-new company profile — enforce the plan's company limit.
            $limit = 0;
            $lim = $conn->query(
                "SELECT p.max_customers FROM tenants t JOIN subscription_plans p ON t.plan_id = p.id WHERE t.id = " . (int)$tenant_id
            );
            if ($lim) {
                $limit = (int)($lim->fetch_assoc()['max_customers'] ?? 0);
                $lim->close();
            }
            $current_count = count($company_profiles);

            if ($limit > 0 && $limit < 999 && $current_count >= $limit) {
                $_SESSION['error'] = 'Your "' . htmlspecialchars($tenant['plan_name'] ?? 'current') . '" plan allows up to ' . $limit . ' company profiles (' . $current_count . ' in use). Please upgrade your subscription to add more.';
            } else {
                $ins = $conn->prepare("INSERT INTO customers (tenant_id,company_name,email,phone,whatsapp_number,website,google_store_url,booster_enabled,booster_min_stars,address,description,google_map_url,map_embed_code,location_description,facebook_url,instagram_url,twitter_url,linkedin_url,tiktok_url,youtube_url,category_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
                $ins->bind_param("issssssiisssssssssssi", $tenant_id, $company_name, $email, $phone, $whatsapp_num, $website, $google_url, $booster_enabled, $booster_min_stars, $address, $description, $google_map_url, $map_embed_code, $location_description, $facebook_url, $instagram_url, $twitter_url, $linkedin_url, $tiktok_url, $youtube_url, $cat_val);
                $ins->execute();
                $saved_id = (int)($conn->insert_id ?: 0);
                $ins->close();
                $_SESSION['success'] = 'New company profile created successfully!';

                // Sync the tenant brand name only when this is the very first profile.
                if (!$primary_company_id) {
                    $sync = $conn->prepare("UPDATE tenants SET company_name=? WHERE id=?");
                    $sync->bind_param("si", $company_name, $tenant_id);
                    $sync->execute();
                    $sync->close();
                }
            }
        }
    }

    // Redirect to prevent form resubmission (keep the tenant on the profile they just edited).
    $redirect_target = 'company.php';
    if (!empty($saved_id) && (int)$saved_id > 0) {
        $redirect_target .= '?company=' . (int)$saved_id;
    } elseif (isset($_GET['new'])) {
        $redirect_target .= '?new=1';
    }
    header('Location: ' . $redirect_target);
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

// Build public rating URL with tenant name
$brand_name_for_slug = !empty($company_profile['company_name']) ? $company_profile['company_name'] : ($tenant['company_name'] ?? '');
if ($company_id > 0) {
    $public_url = getCompanyPublicRatingUrl($company_id, $brand_name_for_slug);
} else {
    $public_url = getCompanyPublicRatingUrl(0, $brand_name_for_slug, ['tenant' => (int)$tenant_id]);
}

// WhatsApp click-to-chat (customers.whatsapp_number)
$wa_number  = $company_profile['whatsapp_number'] ?? '';
$wa_url     = whatsappChatUrl($wa_number, $company_profile['company_name'] ?? $tenant['company_name'] ?? '');
$wa_display = whatsappDisplay($wa_number);

// Google Review Booster & Sentiment Gating
$booster_enabled   = isset($company_profile['booster_enabled']) ? (int)$company_profile['booster_enabled'] : 1;
$booster_min_stars = isset($company_profile['booster_min_stars']) ? (int)$company_profile['booster_min_stars'] : 4;
$google_store_url  = $company_profile['google_store_url'] ?? '';
$booster_active    = !empty($google_store_url) && $booster_enabled;

// Google Maps directions & location description
$google_map_url       = $company_profile['google_map_url'] ?? '';
$map_embed_code       = $company_profile['map_embed_code'] ?? '';
$location_description = $company_profile['location_description'] ?? '';

// Configured Social Media profiles
$facebook_url  = $company_profile['facebook_url'] ?? '';
$instagram_url = $company_profile['instagram_url'] ?? '';
$twitter_url   = $company_profile['twitter_url'] ?? '';
$linkedin_url  = $company_profile['linkedin_url'] ?? '';
$tiktok_url    = $company_profile['tiktok_url'] ?? '';
$youtube_url   = $company_profile['youtube_url'] ?? '';

// Profile Strength score and recommendations
$strength = getProfileStrength($tenant_id, $conn);

$BASE      = '../';
$pageTitle = 'Company Profile';
$activeNav = 'company';
include __DIR__ . '/_shell.php';
?>

<!-- Page Header -->
<div class="welcome-row" style="margin-bottom:22px;">
    <div>
        <p class="eyebrow">Your Workspace &middot; Company Profile</p>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <h1 style="margin:0;"><?php echo $is_new_profile ? 'Add New Company' : htmlspecialchars($company_profile['company_name'] ?? $tenant['company_name'] ?? 'Company Profile'); ?></h1>
            <a href="index.php" title="Profile Strength Meter" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:4px 12px;border-radius:99px;background:rgba(9,26,39,0.05);color:var(--ink);border:1px solid var(--line);text-decoration:none;transition:all 0.2s;">
                <span><?php echo $strength['tier_icon']; ?></span>
                <span style="color:<?php echo $strength['tier_color']; ?>;font-weight:800;"><?php echo $strength['score']; ?>%</span>
                <span style="color:#64748b;">&middot; <?php echo htmlspecialchars($strength['tier']); ?></span>
            </a>
        </div>
        <p class="muted" style="margin-top:6px;">Your company information shown on the public rating page and all reports.</p>
    </div>
    <?php if (!$is_new_profile && $company_profile): ?>
    <a href="<?php echo htmlspecialchars($public_url); ?>" target="_blank"
       class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;">
        ↗ View Public Rating Page
    </a>
    <?php endif; ?>
</div>

<?php if ($success): ?>
<div class="alert alert-success" role="alert">✓ <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error" role="alert">⚠ <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- ===== Company Profiles Manager ===== -->
<div class="form-card" style="padding:22px;margin-bottom:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <div>
            <h3 style="margin:0;font-size:16px;">🏢 Company Profiles</h3>
            <p class="muted" style="margin:3px 0 0;font-size:12.5px;">
                Manage the businesses, branches or locations under your workspace —
                <strong><?php echo count($company_profiles); ?></strong> registered.
            </p>
        </div>
        <a href="company.php?new=1" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px;padding:10px 16px;font-size:13px;text-decoration:none;">
            + Add New Company
        </a>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <?php foreach ($company_profiles as $cp_row):
            $cp_active = $company_profile && (int)$company_profile['id'] === (int)$cp_row['id'];
        ?>
        <a href="company.php?company=<?php echo (int)$cp_row['id']; ?>"
           style="display:inline-flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;
                  border:1px solid <?php echo $cp_active ? 'var(--lime)' : 'var(--line)'; ?>;
                  background:<?php echo $cp_active ? 'rgba(194,245,66,0.14)' : 'var(--bg, #f8fafc)'; ?>;
                  text-decoration:none;transition:all .15s ease;">
            <span style="width:34px;height:34px;border-radius:9px;background:<?php echo $cp_active ? 'var(--lime)' : 'var(--primary-dark)'; ?>;color:<?php echo $cp_active ? 'var(--ink)' : '#fff'; ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;">
                <?php echo htmlspecialchars(strtoupper(mb_substr($cp_row['company_name'], 0, 1))); ?>
            </span>
            <span>
                <strong style="font-size:13px;color:var(--ink);display:block;"><?php echo htmlspecialchars($cp_row['company_name']); ?></strong>
                <small class="muted" style="font-size:11px;">#<?php echo (int)$cp_row['id']; ?><?php echo $cp_active ? ' · Editing now' : ''; ?></small>
            </span>
        </a>
        <?php endforeach; ?>

        <?php if (!count($company_profiles)): ?>
        <p class="muted" style="font-size:13px;margin:6px 0;">No company profiles yet — click <strong>+ Add New Company</strong> to create the first one.</p>
        <?php endif; ?>

        <?php if ($is_new_profile): ?>
        <span style="display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px dashed var(--lime);background:rgba(194,245,66,0.10);font-size:13px;font-weight:700;color:var(--ink);">
            ✚ Creating a new profile…
        </span>
        <?php endif; ?>
    </div>
</div>

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
            <input type="hidden" name="company_id" value="<?php echo (int)($company_profile['id'] ?? 0); ?>">
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

                <!-- Google Review Booster & Sentiment Gating -->
                <div class="collapsible-card" style="grid-column:1/-1;background:var(--bg, #f8fafc);border:1px solid var(--line, #e2e8f0);border-radius:12px;padding:20px;margin:6px 0 10px;">
                    <div class="collapsible-header" onclick="toggleSection(this)" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;cursor:pointer;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:36px;height:36px;border-radius:8px;background:#e8f0fe;display:flex;align-items:center;justify-content:center;font-size:18px;color:#1a73e8;font-weight:bold;">
                                G
                            </div>
                            <div>
                                <h4 style="margin:0;font-size:14.5px;color:var(--ink);font-weight:700;">Google Review Booster &amp; Sentiment Routing</h4>
                                <p class="muted" style="margin:2px 0 0;font-size:12px;">Boost Google stars from happy customers while gating complaints away from public search.</p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <?php if ($booster_active): ?>
                                <span class="status-badge-replied" style="font-size:11px;">● Booster Active</span>
                            <?php else: ?>
                                <span class="status-badge-pending" style="font-size:11px;">● Needs Review Link</span>
                            <?php endif; ?>
                            <span class="collapse-icon" style="font-size:18px;color:#64748b;transition:transform 0.3s;">▼</span>
                        </div>
                    </div>

                    <div class="collapsible-content">

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="google_store_url" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <span>Google Business Profile Review Link</span>
                            <?php if (!empty($google_store_url)): ?>
                                <a href="<?php echo htmlspecialchars($google_store_url); ?>" id="testGoogleLink" target="_blank" rel="noopener noreferrer" style="font-size:11.5px;color:#1a73e8;text-decoration:none;font-weight:600;">Test Link ↗</a>
                            <?php endif; ?>
                        </label>
                        <input type="text" id="google_store_url" name="google_store_url" maxlength="500"
                               placeholder="e.g. https://g.page/r/.../review or https://search.google.com/local/writereview?placeid=..."
                               value="<?php echo htmlspecialchars($google_store_url); ?>">
                        <small class="muted" style="display:block;margin-top:5px;line-height:1.5;">
                            💡 <strong>How to find your link:</strong> Go to your <a href="https://business.google.com" target="_blank" rel="noopener" style="color:#1a73e8;font-weight:600;">Google Business Profile</a> &rarr; click <strong>"Ask for reviews"</strong> &rarr; copy the short review URL.
                        </small>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:14px;padding-top:12px;border-top:1px solid var(--line, #e2e8f0);">
                        <div style="display:flex;align-items:flex-start;gap:10px;">
                            <input type="checkbox" id="booster_enabled" name="booster_enabled" value="1" <?php echo $booster_enabled ? 'checked' : ''; ?> style="margin-top:3px;cursor:pointer;width:18px;height:18px;accent-color:#1a73e8;">
                            <label for="booster_enabled" style="font-size:13px;cursor:pointer;font-weight:600;color:var(--ink);line-height:1.4;">
                                Enable Review Booster
                                <span class="muted" style="display:block;font-size:11.5px;font-weight:normal;margin-top:2px;">Prompt positive reviews to be shared to Google with 1 click.</span>
                            </label>
                        </div>

                        <div>
                            <label for="booster_min_stars" style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px;color:var(--ink);">Routing Threshold</label>
                            <select id="booster_min_stars" name="booster_min_stars" style="padding:7px 10px;font-size:13px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;width:100%;color:var(--ink);">
                                <option value="4" <?php echo $booster_min_stars == 4 ? 'selected' : ''; ?>>4 Stars &amp; Above (Recommended)</option>
                                <option value="5" <?php echo $booster_min_stars == 5 ? 'selected' : ''; ?>>5 Stars Only (Strict)</option>
                            </select>
                            <small class="muted" style="display:block;font-size:11px;margin-top:3px;">Reviews below this score are gated for private resolution.</small>
                        </div>
                    </div>

                    <!-- Workflow explainer -->
                    <div style="margin-top:14px;background:#ffffff;border:1px dashed #cbd5e1;border-radius:8px;padding:10px 14px;display:flex;gap:14px;font-size:11.5px;color:#475569;flex-wrap:wrap;">
                        <div style="flex:1;min-width:160px;display:flex;align-items:center;gap:6px;">
                            <span style="font-size:14px;">⭐⭐⭐⭐⭐</span>
                            <span><strong>Positive (4–5★):</strong> 1-Click "Copy &amp; Post to Google"</span>
                        </div>
                        <div style="flex:1;min-width:160px;display:flex;align-items:center;gap:6px;">
                            <span style="font-size:14px;">🛡️</span>
                            <span><strong>Negative (1–3★):</strong> Privately Gated &amp; Escalated (No Google link)</span>
                        </div>
                    </div>
                    </div>
                </div>

                <div class="form-group" style="grid-column:1/-1;">
                    <label for="address">Business Address</label>
                    <input type="text" id="address" name="address" maxlength="500"
                           placeholder="123 Main Street, Accra, Ghana"
                           value="<?php echo htmlspecialchars($company_profile['address'] ?? ''); ?>">
                    <small class="muted" style="display:block;margin-top:4px;">Shown in the footer of your public rating page.</small>
                </div>

                <div class="form-group" style="grid-column:1/-1;">
                    <label for="description">Company Description</label>
                    <textarea id="description" name="description" rows="3" maxlength="1000"
                              placeholder="A short description of your business shown in the footer of your public rating page."
                              style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;"><?php echo htmlspecialchars($company_profile['description'] ?? ''); ?></textarea>
                </div>

                <!-- Google Map & Landmark Directions Card -->
                <div class="collapsible-card" style="grid-column:1/-1;background:var(--bg, #f8fafc);border:1px solid var(--line, #e2e8f0);border-radius:12px;padding:20px;margin:6px 0 10px;">
                    <div class="collapsible-header" onclick="toggleSection(this)" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;cursor:pointer;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:36px;height:36px;border-radius:8px;background:#e0f2fe;display:flex;align-items:center;justify-content:center;font-size:18px;color:#0284c7;">
                                📍
                            </div>
                            <div>
                                <h4 style="margin:0;font-size:14.5px;color:var(--ink);font-weight:700;">Google Map Location &amp; Directions</h4>
                                <p class="muted" style="margin:2px 0 0;font-size:12px;">Help customers find and navigate directly to your premises with Google Maps directions.</p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <?php if (!empty($google_map_url)): ?>
                                <a href="<?php echo htmlspecialchars($google_map_url); ?>" target="_blank" rel="noopener noreferrer" style="font-size:12px;font-weight:700;color:#0284c7;text-decoration:none;" onclick="event.stopPropagation();">
                                    🚗 Test Direction Link ↗
                                </a>
                            <?php endif; ?>
                            <span class="collapse-icon" style="font-size:18px;color:#64748b;transition:transform 0.3s;">▼</span>
                        </div>
                    </div>

                    <div class="collapsible-content">

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="google_map_url" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <span>Google Maps Direction / Location URL</span>
                            <small class="muted">Shown as "Get Directions" in rating footer</small>
                        </label>
                        <input type="text" id="google_map_url" name="google_map_url" maxlength="1000"
                               placeholder="e.g. https://maps.app.goo.gl/... or https://maps.google.com/?q=..."
                               value="<?php echo htmlspecialchars($google_map_url); ?>">
                        <small class="muted" style="display:block;margin-top:4px;">
                            💡 Open Google Maps, search your company, click <strong>Share</strong> &rarr; copy the location link.
                        </small>
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="location_description">Location &amp; Landmark Directions Guide</label>
                        <textarea id="location_description" name="location_description" rows="2" maxlength="1000"
                                  placeholder="e.g. Located on 10 Senchi Street in Airport Residential Area, 5 minutes drive from Kotoka Airport. Opposite Skybar, with dedicated secure guest parking."
                                  style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:13.5px;font-family:inherit;resize:vertical;"><?php echo htmlspecialchars($location_description); ?></textarea>
                        <small class="muted" style="display:block;margin-top:4px;">
                            Describe notable nearby landmarks, parking availability, or specific entrance instructions for visiting customers.
                        </small>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label for="map_embed_code">Google Map Embed Code or URL (Optional)</label>
                        <textarea id="map_embed_code" name="map_embed_code" rows="2"
                                  placeholder="Paste Google Maps iframe embed code or embed URL: <iframe src=&quot;https://www.google.com/maps/embed?...&quot;></iframe>"
                                  style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:12.5px;font-family:monospace;resize:vertical;"><?php echo htmlspecialchars($map_embed_code); ?></textarea>
                        <small class="muted" style="display:block;margin-top:4px;">
                            Optional: In Google Maps, click <strong>Share &rarr; Embed a map</strong> &rarr; Copy HTML. If provided, an interactive map preview is rendered in the footer.
                        </small>
                    </div>
                    </div>
                </div>

                <!-- Social Media Profiles & Company Links Card -->
                <div class="collapsible-card" style="grid-column:1/-1;background:var(--bg, #f8fafc);border:1px solid var(--line, #e2e8f0);border-radius:12px;padding:20px;margin:6px 0 16px;">
                    <div class="collapsible-header" onclick="toggleSection(this)" style="display:flex;align-items:center;gap:10px;margin-bottom:14px;cursor:pointer;">
                        <div style="width:36px;height:36px;border-radius:8px;background:#fdf2f8;display:flex;align-items:center;justify-content:center;font-size:18px;color:#db2777;">
                            🔗
                        </div>
                        <div style="flex:1;">
                            <h4 style="margin:0;font-size:14.5px;color:var(--ink);font-weight:700;">Company Social Media Profiles</h4>
                            <p class="muted" style="margin:2px 0 0;font-size:12px;">Display clickable, branded social icons alongside your company website link in the footer.</p>
                        </div>
                        <span class="collapse-icon" style="font-size:18px;color:#64748b;transition:transform 0.3s;">▼</span>
                    </div>

                    <div class="collapsible-content">

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:14px;">
                        <div class="form-group" style="margin:0;">
                            <label for="facebook_url" style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;">
                                <span style="color:#1877F2;font-weight:bold;font-size:14px;">f</span> Facebook Page
                            </label>
                            <input type="text" id="facebook_url" name="facebook_url" maxlength="255"
                                   placeholder="https://facebook.com/yourbrand"
                                   value="<?php echo htmlspecialchars($facebook_url); ?>">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label for="instagram_url" style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;">
                                <span style="color:#E4405F;font-weight:bold;font-size:14px;">📸</span> Instagram Profile
                            </label>
                            <input type="text" id="instagram_url" name="instagram_url" maxlength="255"
                                   placeholder="https://instagram.com/yourbrand"
                                   value="<?php echo htmlspecialchars($instagram_url); ?>">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label for="twitter_url" style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;">
                                <span style="color:#0f1419;font-weight:bold;font-size:14px;">𝕏</span> X (Twitter)
                            </label>
                            <input type="text" id="twitter_url" name="twitter_url" maxlength="255"
                                   placeholder="https://x.com/yourbrand"
                                   value="<?php echo htmlspecialchars($twitter_url); ?>">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label for="linkedin_url" style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;">
                                <span style="color:#0A66C2;font-weight:bold;font-size:14px;">in</span> LinkedIn
                            </label>
                            <input type="text" id="linkedin_url" name="linkedin_url" maxlength="255"
                                   placeholder="https://linkedin.com/company/yourbrand"
                                   value="<?php echo htmlspecialchars($linkedin_url); ?>">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label for="tiktok_url" style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;">
                                <span style="color:#000000;font-weight:bold;font-size:14px;">♪</span> TikTok
                            </label>
                            <input type="text" id="tiktok_url" name="tiktok_url" maxlength="255"
                                   placeholder="https://tiktok.com/@yourbrand"
                                   value="<?php echo htmlspecialchars($tiktok_url); ?>">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label for="youtube_url" style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;">
                                <span style="color:#FF0000;font-weight:bold;font-size:14px;">▶</span> YouTube Channel
                            </label>
                            <input type="text" id="youtube_url" name="youtube_url" maxlength="255"
                                   placeholder="https://youtube.com/@yourbrand"
                                   value="<?php echo htmlspecialchars($youtube_url); ?>">
                        </div>
                    </div>
                    </div>
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

        <!-- Profile Strength Mini Widget -->
        <div class="form-card" style="padding:22px;border-left:4px solid <?php echo $strength['tier_color']; ?>;background:var(--card);">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="position:relative;width:52px;height:52px;flex-shrink:0;">
                        <svg width="52" height="52" viewBox="0 0 48 48" style="transform:rotate(-90deg);">
                            <circle cx="24" cy="24" r="20" fill="none" stroke="var(--line)" stroke-width="4.5"/>
                            <circle cx="24" cy="24" r="20" fill="none" stroke="<?php echo $strength['tier_color']; ?>" stroke-width="4.5"
                                    stroke-dasharray="125.66"
                                    stroke-dashoffset="<?php echo round(125.66 * (1 - ($strength['score'] / 100)), 2); ?>"
                                    stroke-linecap="round" style="transition:stroke-dashoffset 0.8s ease;"/>
                        </svg>
                        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--ink);">
                            <?php echo $strength['score']; ?>%
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);">Profile Strength</div>
                        <div style="font-size:15px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:6px;">
                            <span><?php echo $strength['tier_icon']; ?></span>
                            <span><?php echo htmlspecialchars($strength['tier']); ?></span>
                        </div>
                    </div>
                </div>
                <span style="font-size:11.5px;font-weight:700;color:<?php echo $strength['tier_color']; ?>;background:rgba(<?php echo $strength['tier_color'] === '#16a34a' ? '22,163,74' : ($strength['tier_color'] === '#c2f542' ? '194,245,66' : '100,116,139'); ?>,0.15);padding:4px 8px;border-radius:6px;white-space:nowrap;">
                    <?php echo $strength['completed_count']; ?>/<?php echo $strength['total_items']; ?> Done
                </span>
            </div>

            <!-- Progress bar -->
            <div style="height:6px;background:var(--line);border-radius:99px;overflow:hidden;margin-bottom:10px;">
                <div style="height:100%;width:<?php echo $strength['score']; ?>%;background:<?php echo $strength['tier_color']; ?>;border-radius:99px;transition:width 0.6s ease;"></div>
            </div>

            <p class="muted" style="margin:0 0 14px;font-size:12px;line-height:1.45;">
                <?php echo htmlspecialchars($strength['message']); ?>
            </p>

            <div style="display:flex;flex-direction:column;gap:7px;padding-top:12px;border-top:1px solid var(--line);">
                <?php foreach ($strength['items'] as $item): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;font-size:12px;color:<?php echo $item['done'] ? '#16a34a' : 'var(--muted)'; ?>;">
                        <span style="display:flex;align-items:center;gap:6px;min-width:0;">
                            <span style="font-weight:800;font-size:11px;"><?php echo $item['done'] ? '✓' : '○'; ?></span>
                            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;<?php echo $item['done'] ? '' : 'font-weight:600;color:var(--ink);'; ?>"><?php echo htmlspecialchars($item['title']); ?></span>
                        </span>
                        <span style="font-weight:700;font-size:11px;white-space:nowrap;margin-left:6px;"><?php echo $item['done'] ? 'Done' : '+' . $item['weight'] . '%'; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:14px;padding-top:10px;border-top:1px solid var(--line);text-align:right;">
                <a href="index.php" style="font-size:12px;font-weight:700;color:var(--ink);text-decoration:none;">
                    View Dashboard Checklist &rarr;
                </a>
            </div>
        </div>

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
            <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span class="muted" style="font-size:12px;">Marketing &amp; Embeds:</span>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="whatsapp_sender.php" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;background:#dcfce7;color:#15803d;border:1px solid #86efac;font-weight:700;">
                        💬 WhatsApp Invites
                    </a>
                    <a href="qr_stand.php" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
                        ◫ Counter QR Stand
                    </a>
                    <a href="settings.php#tab=preferences" class="btn btn-secondary" style="padding:6px 12px;font-size:12px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
                        📇 Website Widget
                    </a>
                </div>
            </div>
        </div>

        <!-- Google Review Booster status card -->
        <div class="form-card" style="padding:22px;<?php echo $booster_active ? 'border-left:3px solid #4285F4;' : ''; ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px;">
                <h3 style="margin:0;font-size:15px;">⭐ Google Review Booster</h3>
                <?php echo $booster_active
                    ? '<span class="status-badge-replied" style="font-size:11px;">● Active</span>'
                    : '<span class="status-badge-pending" style="font-size:11px;">● Off / Missing Link</span>'; ?>
            </div>
            <?php if ($booster_active): ?>
                <p class="muted" style="margin:0 0 14px;font-size:12.5px;line-height:1.5;">
                    Happy customers rating <strong><?php echo $booster_min_stars; ?>★ or higher</strong> are prompted with a 1-click button to copy their review directly to your Google Business Profile.
                </p>
                <a href="<?php echo htmlspecialchars($google_store_url); ?>" target="_blank" rel="noopener noreferrer"
                   style="display:inline-flex;align-items:center;gap:8px;background:#4285F4;color:#fff;text-decoration:none;font-weight:700;font-size:13px;padding:10px 16px;border-radius:99px;">
                    Test Google Link ↗
                </a>
            <?php else: ?>
                <p class="muted" style="margin:0;font-size:12.5px;line-height:1.6;">
                    Paste your Google Business review link to enable review boosting. 4–5 star reviews get sent to Google, while 1–3 star complaints are gated internally.
                </p>
            <?php endif; ?>
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
                    <dd><?php echo !empty($tenant['public_id']) ? '<span style="font-family:monospace;background:rgba(99,102,241,0.12);color:#4338ca;border:1px solid rgba(99,102,241,0.25);font-weight:800;letter-spacing:1px;padding:3px 10px;border-radius:6px;font-size:13px;">' . htmlspecialchars($tenant['public_id']) . '</span>' : '#' . (int)$tenant_id; ?></dd>
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
// Collapsible accordion functionality
function toggleSection(header) {
    const card = header.closest('.collapsible-card');
    const content = card.querySelector('.collapsible-content');
    const icon = header.querySelector('.collapse-icon');
    const isOpen = content.style.maxHeight && content.style.maxHeight !== '0px';
    
    // Close all other sections
    document.querySelectorAll('.collapsible-card').forEach(otherCard => {
        if (otherCard !== card) {
            const otherContent = otherCard.querySelector('.collapsible-content');
            const otherIcon = otherCard.querySelector('.collapse-icon');
            otherContent.style.maxHeight = '0';
            otherContent.style.opacity = '0';
            otherContent.style.marginTop = '0';
            otherIcon.style.transform = 'rotate(0deg)';
        }
    });
    
    // Toggle current section
    if (isOpen) {
        content.style.maxHeight = '0';
        content.style.opacity = '0';
        content.style.marginTop = '0';
        icon.style.transform = 'rotate(0deg)';
    } else {
        content.style.maxHeight = content.scrollHeight + 'px';
        content.style.opacity = '1';
        content.style.marginTop = '14px';
        icon.style.transform = 'rotate(180deg)';
    }
}

// Initialize all sections as closed on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.collapsible-content').forEach(content => {
        content.style.maxHeight = '0';
        content.style.opacity = '0';
        content.style.overflow = 'hidden';
        content.style.transition = 'max-height 0.4s ease, opacity 0.3s ease, margin-top 0.3s ease';
        content.style.marginTop = '0';
    });
});

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
