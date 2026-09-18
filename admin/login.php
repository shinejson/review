<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/login_attempts.php';

ensureRealIdSchema($conn);

if (isLoggedIn()) {
    redirect('index.php');
}

$error  = '';
$username = '';
$notice = auth_login_notice();   // "you have been signed out", "session expired" …

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username/email and password.';
    } else {
        // 1. Check if user is a Tenant in `tenants` table — supports Real Public ID (OPT-XXXXXXXX), username, email
        $stmt = $conn->prepare("SELECT * FROM tenants WHERE username = ? OR email = ? OR public_id = ? LIMIT 1");
        $stmt->bind_param("sss", $username, $username, $username);
        $stmt->execute();
        $resTenant = $stmt->get_result();

        if ($resTenant && $resTenant->num_rows === 1) {
            $tenant = $resTenant->fetch_assoc();
            
            // Check if account is locked
            if (isAccountLocked($conn, 'tenants', (int)$tenant['id'])) {
                $error = 'This account is temporarily locked. Please try again in 30 minutes or contact support to unlock it.';
            } elseif (password_verify($password, $tenant['password'])) {
                // Reset failed attempts on successful login
                resetFailedLoginAttempts($conn, 'tenants', (int)$tenant['id']);
                
                // Store tenant session data
                $_SESSION['tenant_id'] = (int)$tenant['id'];
                $_SESSION['tenant_name'] = $tenant['company_name'];
                $_SESSION['tenant_logo'] = $tenant['logo'] ?? '';
                $_SESSION['tenant_username'] = $tenant['username'];
                $_SESSION['admin_username'] = $tenant['username'];
                $_SESSION['tenant_email'] = $tenant['email'];
                $_SESSION['tenant_plan_id'] = $tenant['plan_id'];
                $_SESSION['tenant_status'] = $tenant['subscription_status'];
                $_SESSION['tenant_subscription_end'] = $tenant['subscription_end_date'] ?? null;
                $_SESSION['user_type'] = 'tenant';
                auth_login_session($conn, 'admin', (int)$tenant['id'], $tenant['username'] ?: $tenant['company_name'], 'tenant');
                redirect('index.php');
            } else {
                // Record failed attempt
                $attempt_result = recordFailedLoginAttempt($conn, 'tenants', (int)$tenant['id']);
                $error = $attempt_result['message'];
            }
        } else {
            // 1.5. Team member (staff account created by a workspace owner)
            ensureTeamSchema($conn);
            $tm_found = false;
            $stmtT = $conn->prepare("SELECT tm.*, t.company_name, t.logo, t.email AS tenant_email, t.phone, t.username AS tenant_username, t.subscription_status, t.subscription_end_date, t.plan_id FROM team_members tm JOIN tenants t ON t.id = tm.tenant_id WHERE tm.username = ? OR tm.email = ? LIMIT 1");
            if ($stmtT) {
                $stmtT->bind_param("ss", $username, $username);
                $stmtT->execute();
                $resT = $stmtT->get_result();
                if ($resT && $resT->num_rows === 1) {
                    $tm_found = true;
                    $member = $resT->fetch_assoc();
                    if ((int)$member['is_active'] !== 1) {
                        $error = 'This team account is disabled. Contact your workspace owner.';
                    } elseif (isAccountLocked($conn, 'team_members', (int)$member['id'])) {
                        $error = 'This account is temporarily locked. Please try again in 30 minutes or contact your workspace owner to unlock it.';
                    } elseif (!password_verify($password, (string)$member['password'])) {
                        // Record failed attempt
                        $attempt_result = recordFailedLoginAttempt($conn, 'team_members', (int)$member['id']);
                        $error = $attempt_result['message'];
                    } else {
                        // Reset failed attempts on successful login
                        resetFailedLoginAttempts($conn, 'team_members', (int)$member['id']);
                        
                        $_SESSION['tenant_id'] = (int)$member['tenant_id'];
                        $_SESSION['tenant_name'] = $member['company_name'];
                        $_SESSION['tenant_logo'] = $member['logo'] ?? '';
                        $_SESSION['tenant_username'] = $member['tenant_username'];
                        $_SESSION['tenant_email'] = $member['tenant_email'];
                        $_SESSION['tenant_plan_id'] = $member['plan_id'];
                        $_SESSION['tenant_status'] = $member['subscription_status'];
                        $_SESSION['tenant_subscription_end'] = $member['subscription_end_date'] ?? null;
                        $_SESSION['user_type'] = 'tenant';
                        $_SESSION['user_kind'] = 'team';
                        $_SESSION['team_member_id'] = (int)$member['id'];
                        $_SESSION['team_member_role'] = $member['role'];
                        $_SESSION['team_permissions'] = teamMemberParsePerms($member['permissions']);
                        $_SESSION['admin_username'] = $member['full_name'];
                        $_SESSION['admin_email'] = $member['email'];
                        auth_login_session($conn, 'admin', (int)$member['id'], $member['full_name'], 'team');
                        $ll = $conn->prepare("UPDATE team_members SET last_login_at = NOW() WHERE id = ?");
                        if ($ll) { $ll->bind_param("i", $member['id']); $ll->execute(); $ll->close(); }
                        redirect('index.php');
                    }
                }
                $stmtT->close();
            }

            if (!$tm_found) {
                // 2. Check if user is in `admins` table
                $stmt2 = $conn->prepare("SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1");
                $stmt2->bind_param("ss", $username, $username);
                $stmt2->execute();
                $resAdmin = $stmt2->get_result();

                if ($resAdmin && $resAdmin->num_rows === 1) {
                    $admin = $resAdmin->fetch_assoc();
                    if (password_verify($password, $admin['password'])) {
                        $_SESSION['admin_id'] = (int)$admin['id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['admin_email'] = $admin['email'];
                        $_SESSION['user_type'] = 'admin';
                        auth_login_session($conn, 'admin', (int)$admin['id'], $admin['username'], 'admin');
                        redirect('index.php');
                    } else {
                        $error = 'Invalid credentials. Please verify your password.';
                    }
                    $stmt2->close();
                } else {
                    $error = 'Account not found. Please check your username or email.';
                }
            }
        }
    }
}

$robots    = 'noindex, nofollow';
$BASE      = '../';
$pageTitle = 'Business & Admin Login';
$extraCss = ['assets/css/auth.css'];
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="auth-shell">

    <!-- Brand panel -->
    <aside class="auth-brand">
        <a class="auth-logo" href="<?php echo $assetBase; ?>/">
            <span class="auth-logo-badge">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            </span>
            <span class="auth-logo-name">
                <strong>Optibiz</strong>
                <span>Rating Platform</span>
            </span>
        </a>

        <div class="auth-brand-body">
            <h1>Grow your business with <em>verified reviews</em>.</h1>
            <p>Sign in to your business workspace to monitor verified ratings, dispatch WhatsApp review requests, and build lasting customer trust.</p>
            <ul class="auth-points">
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Ratings &amp; real-time customer feedback analytics
                </li>
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    WhatsApp review invites &amp; printable counter QR stands
                </li>
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Social proof cards, ad funnels &amp; pre-purchase FAQs
                </li>
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Full public rating portal customiser &amp; brand theme control
                </li>
            </ul>
        </div>

        <p class="auth-brand-foot">&copy; <?php echo date('Y'); ?> Optibiz &middot; Business Reputation Workspace</p>
    </aside>

    <!-- Form panel -->
    <main class="auth-panel">
        <section class="auth-card" aria-labelledby="authCardTitle">
            <a class="auth-logo auth-mobile-logo" href="<?php echo $assetBase; ?>/">
                <span class="auth-logo-badge">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                </span>
                <span class="auth-logo-name">
                    <strong>Optibiz</strong>
                    <span>Rating Platform</span>
                </span>
            </a>

            <header class="auth-card-head">
                <span class="auth-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 11.5 11.5 14 15.5 9.5"/></svg>
                </span>
                <h2 id="authCardTitle">Sign In to Your Workspace</h2>
                <p>Enter your Account ID (OPT-XXXXXXXX), username, or email to continue.</p>
            </header>

            <?php if ($notice): ?>
                <div class="auth-alert is-info" role="status">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span><?php echo htmlspecialchars($notice['text']); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="auth-alert" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="authForm" class="auth-form">
                <div class="auth-field">
                    <label for="username">Account ID / Username / Email</label>
                    <div class="auth-input-wrap">
                        <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" placeholder="e.g. OPT-8K2F9Q1A or abc_corporation or you@company.com" autocomplete="username" autofocus required>
                    </div>
                    <small style="font-size:11px;color:#94a3b8;margin-top:5px;display:block;">You received your Real ID (OPT-XXXXXXXX) in your welcome email after quota approval.</small>
                </div>

                <div class="auth-field">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label for="password" style="margin:0;">Password</label>
                        <a href="forgot-password.php" style="font-size:12px;color:#6366f1;text-decoration:none;font-weight:600;">Forgot password?</a>
                    </div>
                    <div class="auth-input-wrap auth-has-toggle">
                        <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="auth-toggle" id="pwToggle" aria-label="Show password" aria-pressed="false">
                            <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-submit" id="authSubmit">
                    <span class="auth-submit-label">Sign in to dashboard</span>
                    <span class="auth-spinner" aria-hidden="true"></span>
                </button>
            </form>

            <div style="margin-top:16px;padding:12px 14px;background:rgba(99,102,241,0.06);border:1px solid rgba(99,102,241,0.12);border-radius:10px;font-size:12.5px;color:#475569;line-height:1.5;">
                <strong style="color:#4338ca;">New account?</strong> After your quota (QTE-XXXXXXXX) is approved, you'll get an email with your Real Account ID (OPT-XXXXXXXX) and a link to set your password. Check spam folder. <a href="forgot-password.php" style="color:#6366f1;font-weight:600;">Resend link</a>
            </div>

            <footer class="auth-card-foot">
                <a href="<?php echo $assetBase; ?>/">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Back to website
                </a>
                <span class="auth-secure">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Secure login
                </span>
            </footer>
        </section>
    </main>
</div>

<script src="<?php echo $assetBase; ?>/assets/js/auth.js"></script>
</body>
</html>
