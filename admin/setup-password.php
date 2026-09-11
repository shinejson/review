<?php
/**
 * Tenant Password Setup & Reset
 * Accessed via email link: /admin/setup-password.php?token=xxxx
 * The token is generated when a tenant is created from a quote or via superadmin.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

ensureRealIdSchema($conn);

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$success = '';
$tenant = null;
$expired = false;

if ($token === '') {
    $error = 'Invalid or missing setup link. Please check the email link or contact support.';
} else {
    // Find tenant by token
    $stmt = $conn->prepare("SELECT id, public_id, company_name, email, username, setup_token_expires FROM tenants WHERE setup_token = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $tenant = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    } else {
        // Fallback direct query
        $esc = $conn->real_escape_string($token);
        $res = @$conn->query("SELECT id, public_id, company_name, email, username, setup_token_expires FROM tenants WHERE setup_token = '$esc' LIMIT 1");
        $tenant = $res ? $res->fetch_assoc() : null;
        if ($res) $res->close();
    }

    if (!$tenant) {
        $error = 'This setup link is invalid or has already been used. Please request a new one from support.';
    } else {
        // Check expiry
        $expires = $tenant['setup_token_expires'];
        if (!empty($expires) && strtotime($expires) < time()) {
            $expired = true;
            $error = 'This setup link has expired. For security, links are valid for 48 hours. Please contact support to get a new link.';
        }
    }
}

// Handle password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenant && !$expired && $error === '') {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $tokenPost = trim($_POST['token'] ?? '');

    // Re-validate token from POST to prevent tampering
    if ($tokenPost !== $token) {
        $error = 'Security token mismatch. Please use the link from your email.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = 'Password must contain at least one uppercase letter, one lowercase letter, and one number.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');

        $upd = $conn->prepare("UPDATE tenants SET password = ?, setup_token = NULL, setup_token_expires = NULL, email_verified_at = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param("ssi", $hash, $now, $tenant['id']);
            $ok = $upd->execute();
            $upd->close();
        } else {
            $escHash = $conn->real_escape_string($hash);
            $ok = (bool)@$conn->query("UPDATE tenants SET password = '$escHash', setup_token = NULL, setup_token_expires = NULL, email_verified_at = '$now' WHERE id = " . (int)$tenant['id']);
        }

        if ($ok) {
            // Refresh tenant data for welcome email
            $tenantFull = null;
            $s2 = $conn->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
            if ($s2) {
                $s2->bind_param("i", $tenant['id']);
                $s2->execute();
                $r2 = $s2->get_result();
                $tenantFull = $r2 ? $r2->fetch_assoc() : $tenant;
                $s2->close();
            } else {
                $tenantFull = $tenant;
            }

            try {
                sendTenantWelcomeAfterSetup($conn, $tenantFull);
            } catch (Exception $e) {
                // Ignore email errors
            }

            $success = 'Your password has been set successfully! You can now sign in to your workspace.';
            // Clear tenant to prevent re-submission
            $tenant = null;
        } else {
            $error = 'Could not update password. Please try again or contact support.';
        }
    }
}

$robots = 'noindex, nofollow';
$BASE = '../';
$pageTitle = 'Set Up Your Password';
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
            <h1>Secure your <em>workspace</em> in seconds.</h1>
            <p>Set a strong password to activate your account and start managing your company's reputation.</p>
            <ul class="auth-points">
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Your Account ID: <strong style="font-family:monospace;letter-spacing:0.5px;color:#c2f542;"><?php echo htmlspecialchars($tenant['public_id'] ?? ($tenant ? 'OPT-...' : 'OPT-XXXXXX'), ENT_QUOTES, 'UTF-8'); ?></strong>
                </li>
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    48-hour secure link • One-time use
                </li>
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    After setup, sign in at <span style="font-family:monospace;">/admin/login.php</span>
                </li>
            </ul>
        </div>

        <p class="auth-brand-foot">&copy; <?php echo date('Y'); ?> Optibiz &middot; Company Rating Platform</p>
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
                <span class="auth-card-icon" style="background:rgba(194,245,66,0.12);color:#c2f542;border:1px solid rgba(194,245,66,0.2);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
                <h2 id="authCardTitle">Set up your password</h2>
                <p>
                    <?php if ($tenant): ?>
                        Hello <strong><?php echo htmlspecialchars($tenant['company_name'], ENT_QUOTES, 'UTF-8'); ?></strong> — create a secure password for your account
                        <span style="font-family:monospace;background:rgba(194,245,66,0.1);padding:2px 8px;border-radius:6px;color:#c2f542;font-size:13px;margin-left:4px;"><?php echo htmlspecialchars($tenant['public_id'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                        Activate your Optibiz workspace account
                    <?php endif; ?>
                </p>
            </header>

            <?php if ($success): ?>
                <div class="auth-alert is-success" role="status" style="background:rgba(16,185,129,0.12);border-color:rgba(16,185,129,0.25);color:#10b981;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;">
                    <a href="login.php" class="auth-submit" style="text-decoration:none;text-align:center;">
                        <span class="auth-submit-label">Sign in to dashboard</span>
                    </a>
                </div>
            <?php else: ?>

                <?php if ($error): ?>
                    <div class="auth-alert" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($tenant && !$expired): ?>
                    <form method="POST" class="auth-form" id="setupForm">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="auth-field">
                            <label for="password">New Password</label>
                            <div class="auth-input-wrap auth-has-toggle">
                                <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <input type="password" id="password" name="password" placeholder="At least 8 characters, upper + lower + number" autocomplete="new-password" required minlength="8">
                                <button type="button" class="auth-toggle" data-toggle="password" aria-label="Show password" aria-pressed="false">
                                    <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                            <small style="font-size:12px;color:#64748b;margin-top:6px;display:block;">Must be 8+ chars with uppercase, lowercase and number</small>
                        </div>

                        <div class="auth-field">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="auth-input-wrap auth-has-toggle">
                                <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat your new password" autocomplete="new-password" required minlength="8">
                                <button type="button" class="auth-toggle" data-toggle="confirm_password" aria-label="Show password" aria-pressed="false">
                                    <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="auth-submit" id="setupSubmit">
                            <span class="auth-submit-label">Set my password & activate account</span>
                            <span class="auth-spinner" aria-hidden="true"></span>
                        </button>

                        <div style="margin-top:16px;padding:12px 14px;background:rgba(148,163,184,0.06);border:1px solid rgba(148,163,184,0.12);border-radius:10px;font-size:12.5px;color:#94a3b8;line-height:1.5;">
                            <strong style="color:#cbd5e1;">Your credentials after setup:</strong><br>
                            • Username: <span style="font-family:monospace;color:#eef2f7;"><?php echo htmlspecialchars($tenant['username'], ENT_QUOTES, 'UTF-8'); ?></span><br>
                            • Email: <?php echo htmlspecialchars($tenant['email'], ENT_QUOTES, 'UTF-8'); ?><br>
                            • Account ID: <span style="font-family:monospace;color:#c2f542;"><?php echo htmlspecialchars($tenant['public_id'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </form>

                    <script>
                    (function(){
                        document.querySelectorAll('[data-toggle]').forEach(function(btn){
                            btn.addEventListener('click', function(){
                                var targetId = btn.getAttribute('data-toggle');
                                var input = document.getElementById(targetId);
                                if (!input) return;
                                var isPwd = input.type === 'password';
                                input.type = isPwd ? 'text' : 'password';
                                btn.setAttribute('aria-pressed', isPwd ? 'true' : 'false');
                                btn.querySelector('.icon-eye').style.display = isPwd ? 'none' : 'block';
                                btn.querySelector('.icon-eye-off').style.display = isPwd ? 'block' : 'none';
                            });
                            // Init icons
                            var eye = btn.querySelector('.icon-eye');
                            var eyeOff = btn.querySelector('.icon-eye-off');
                            if (eye && eyeOff) { eye.style.display='block'; eyeOff.style.display='none'; }
                        });
                        var form = document.getElementById('setupForm');
                        if (form) {
                            form.addEventListener('submit', function(){
                                var btn = document.getElementById('setupSubmit');
                                if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
                            });
                        }
                    })();
                    </script>

                <?php else: ?>
                    <div style="margin-top:20px;">
                        <a href="login.php" class="auth-submit" style="text-decoration:none;text-align:center;display:flex;justify-content:center;">
                            <span class="auth-submit-label">Back to login</span>
                        </a>
                        <p style="text-align:center;margin-top:14px;font-size:13px;color:#64748b;">
                            Need a new link? Contact support with your Account ID or email.
                        </p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

            <footer class="auth-card-foot">
                <a href="<?php echo $assetBase; ?>/">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Back to website
                </a>
                <span class="auth-secure">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Secure setup
                </span>
            </footer>
        </section>
    </main>
</div>

</body>
</html>
