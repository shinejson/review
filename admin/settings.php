<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

$success = '';
$error = '';

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $company_name = sanitize($_POST['company_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');

    if ($is_tenant && $tenant_id) {
        $stmt = $conn->prepare("UPDATE tenants SET company_name = ?, email = ?, phone = ? WHERE id = ?");
        $stmt->bind_param("sssi", $company_name, $email, $phone, $tenant_id);
        if ($stmt->execute()) {
            $_SESSION['tenant_name'] = $company_name;
            $_SESSION['tenant_email'] = $email;
            $success = "Profile updated successfully!";
        } else {
            $error = "Failed to update profile: " . $conn->error;
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
        }
    }
}

// Fetch current tenant data
$tenant = null;
if ($is_tenant && $tenant_id) {
    $stmt = $conn->prepare("SELECT t.*, p.plan_name, p.max_ratings, p.max_customers, p.features, p.price as plan_price 
                            FROM tenants t 
                            LEFT JOIN subscription_plans p ON t.plan_id = p.id 
                            WHERE t.id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
}

$robots    = 'noindex, nofollow';

$pageTitle = 'Account & Subscription Settings - Optibiz';
$extraCss = ['/assets/css/auth.css'];
include dirname(__DIR__) . '/includes/header.php';
?>

<div style="background:#f8fafc;min-height:100vh;font-family:'Plus Jakarta Sans',sans-serif;">
    <!-- Top Nav -->
    <header style="background:#0a1926;color:white;padding:16px 5%;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,0.1);">
        <div style="display:flex;align-items:center;gap:12px;">
            <a href="index.php" style="color:white;text-decoration:none;font-size:22px;font-weight:800;letter-spacing:-0.5px;display:flex;align-items:center;gap:8px;">
                <span style="width:28px;height:28px;background:#c2f542;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#0a1926;font-size:14px;font-weight:900;">★</span>
                Optibiz
            </a>
            <span style="background:rgba(255,255,255,0.12);padding:4px 12px;border-radius:20px;font-size:12px;color:#c2f542;font-weight:600;">
                <?php echo $is_tenant ? 'Tenant Portal' : 'Global Admin'; ?>
            </span>
        </div>

        <nav style="display:flex;align-items:center;gap:20px;">
            <a href="index.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Dashboard</a>
            <a href="ratings.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Ratings &amp; Reviews</a>
            <a href="settings.php" style="color:#c2f542;text-decoration:none;font-size:14px;font-weight:600;">Settings</a>
            <a href="logout.php" style="background:rgba(239,68,68,0.2);color:#f87171;padding:6px 14px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:600;">Logout</a>
        </nav>
    </header>

    <main style="max-width:1240px;margin:30px auto;padding:0 20px;">
        <div style="margin-bottom:24px;">
            <h1 style="font-size:26px;font-weight:800;color:#0f172a;">Settings &amp; Subscription</h1>
            <p style="color:#64748b;font-size:14px;">Manage company profile, security, and subscription tier.</p>
        </div>

        <?php if ($success): ?>
            <div style="background:#ecfdf5;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:14px;font-weight:600;border:1px solid #a7f3d0;">
                ✓ <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background:#fef2f2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:14px;font-weight:600;border:1px solid #fecaca;">
                ⚠ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:24px;">
            <!-- Company Profile -->
            <div style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;">
                <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:16px;">Tenant Profile Information</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Company / Tenant Name</label>
                        <input type="text" name="company_name" value="<?php echo htmlspecialchars($tenant['company_name'] ?? ''); ?>" required style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Tenant Username (Permanent)</label>
                        <input type="text" value="<?php echo htmlspecialchars($tenant['username'] ?? ''); ?>" disabled style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;background:#f1f5f9;color:#64748b;">
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Billing / Admin Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($tenant['email'] ?? ''); ?>" required style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                    </div>

                    <div style="margin-bottom:20px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Phone Number</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($tenant['phone'] ?? ''); ?>" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                    </div>

                    <button type="submit" style="background:#0a1926;color:#c2f542;padding:11px 24px;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;">
                        Save Profile Changes
                    </button>
                </form>

                <hr style="margin:28px 0;border:0;border-top:1px solid #e2e8f0;">

                <!-- Change Password -->
                <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:16px;">Security &amp; Password</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Current Password</label>
                        <input type="password" name="current_password" required style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">New Password</label>
                        <input type="password" name="new_password" placeholder="Min 6 characters" required style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                    </div>

                    <div style="margin-bottom:20px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Confirm New Password</label>
                        <input type="password" name="confirm_password" required style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                    </div>

                    <button type="submit" style="background:#0a1926;color:white;padding:11px 24px;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;">
                        Update Password
                    </button>
                </form>
            </div>

            <!-- Subscription Plan Box -->
            <div style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;height:fit-content;">
                <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:16px;">Current Subscription Plan</h3>
                
                <?php if ($tenant): ?>
                    <div style="background:linear-gradient(135deg,#0a1926 0%,#1a3852 100%);color:white;padding:24px;border-radius:14px;margin-bottom:20px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                            <span style="font-size:22px;font-weight:800;color:#c2f542;">
                                <?php echo htmlspecialchars($tenant['plan_name'] ?? 'Professional'); ?>
                            </span>
                            <span style="background:rgba(194,245,66,0.2);color:#c2f542;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;">
                                <?php echo htmlspecialchars($tenant['subscription_status']); ?>
                            </span>
                        </div>
                        <div style="font-size:32px;font-weight:800;margin-bottom:12px;">
                            $<?php echo number_format($tenant['subscription_price'] ?? $tenant['plan_price'] ?? 79.99, 2); ?>
                            <span style="font-size:14px;font-weight:400;color:#cbd5e1;">/ month</span>
                        </div>
                        <p style="font-size:13px;color:#94a3b8;line-height:1.5;">
                            <?php echo htmlspecialchars($tenant['features'] ?? 'Full analytics suite, priority support, and multi-branch ratings.'); ?>
                        </p>
                    </div>

                    <div style="font-size:13px;color:#475569;display:flex;flex-direction:column;gap:10px;margin-bottom:20px;">
                        <div style="display:flex;justify-content:space-between;border-bottom:1px solid #f1f5f9;padding-bottom:8px;">
                            <span>Max Allowed Companies:</span>
                            <strong><?php echo $tenant['max_customers'] ?? 'Unlimited'; ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;border-bottom:1px solid #f1f5f9;padding-bottom:8px;">
                            <span>Max Ratings per Month:</span>
                            <strong><?php echo $tenant['max_ratings'] ?? 'Unlimited'; ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;border-bottom:1px solid #f1f5f9;padding-bottom:8px;">
                            <span>Auto-Renew:</span>
                            <strong><?php echo ($tenant['auto_renew'] ?? true) ? 'Enabled' : 'Disabled'; ?></strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

</body>
</html>
