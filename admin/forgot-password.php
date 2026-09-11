<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

ensureRealIdSchema($conn);

$error = '';
$success = '';
$email_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_input = trim($_POST['email'] ?? '');
    if ($email_input === '' || !filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT id, public_id, company_name, email FROM tenants WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $email_input);
            $stmt->execute();
            $res = $stmt->get_result();
            $tenant = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        } else {
            $esc = $conn->real_escape_string($email_input);
            $r = @$conn->query("SELECT id, public_id, company_name, email FROM tenants WHERE email = '$esc' LIMIT 1");
            $tenant = $r ? $r->fetch_assoc() : null;
            if ($r) $r->close();
        }

        if (!$tenant) {
            // Don't reveal if email exists, but show generic success to prevent enumeration
            $success = 'If an account exists with that email, a password reset link has been sent. Please check your inbox (and spam folder). The link is valid for 48 hours.';
        } else {
            $new_token = generateSecureToken(32);
            $new_exp = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $up = $conn->prepare("UPDATE tenants SET setup_token = ?, setup_token_expires = ? WHERE id = ?");
            if ($up) {
                $up->bind_param("ssi", $new_token, $new_exp, $tenant['id']);
                $up->execute();
                $up->close();
            } else {
                @$conn->query("UPDATE tenants SET setup_token = '" . $conn->real_escape_string($new_token) . "', setup_token_expires = '" . $conn->real_escape_string($new_exp) . "' WHERE id = " . (int)$tenant['id']);
            }

            try {
                $mailRes = sendTenantSetupEmail($conn, $tenant, $new_token, false);
                if ($mailRes['success']) {
                    $success = 'A password reset link has been sent to ' . htmlspecialchars($email_input) . '. Your Account ID is ' . htmlspecialchars($tenant['public_id'] ?? '') . '. The link is valid for 48 hours.';
                } else {
                    $error = 'Could not send email: ' . $mailRes['message'] . '. Please contact support with your Account ID ' . ($tenant['public_id'] ?? '') . '.';
                }
            } catch (Exception $e) {
                $error = 'Error sending email: ' . $e->getMessage();
            }
        }
    }
}

$robots = 'noindex, nofollow';
$BASE = '../';
$pageTitle = 'Forgot Password';
$extraCss = ['assets/css/auth.css'];
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="auth-shell">
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
            <h1>Forgot your <em>password?</em></h1>
            <p>Enter your email and we'll send you a secure link to set a new password. Your Real Account ID (OPT-XXXXXX) will be included.</p>
            <ul class="auth-points">
                <li><span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span> 48-hour secure link</li>
                <li><span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span> Real ID stays same: OPT-XXXXXXXX</li>
                <li><span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span> Login at /admin/login.php after reset</li>
            </ul>
        </div>
        <p class="auth-brand-foot">&copy; <?php echo date('Y'); ?> Optibiz</p>
    </aside>

    <main class="auth-panel">
        <section class="auth-card" aria-labelledby="authCardTitle">
            <a class="auth-logo auth-mobile-logo" href="<?php echo $assetBase; ?>/">
                <span class="auth-logo-badge"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
                <span class="auth-logo-name"><strong>Optibiz</strong><span>Rating Platform</span></span>
            </a>

            <header class="auth-card-head">
                <span class="auth-card-icon" style="background:rgba(194,245,66,0.12);color:#c2f542;border:1px solid rgba(194,245,66,0.2);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </span>
                <h2 id="authCardTitle">Reset your password</h2>
                <p>We'll email you a secure link to set a new password for your Real ID account.</p>
            </header>

            <?php if ($error): ?>
                <div class="auth-alert" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="auth-alert is-success" role="status" style="background:rgba(16,185,129,0.12);border-color:rgba(16,185,129,0.25);color:#166534;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <span><?php echo $success; ?></span>
                </div>
                <div style="margin-top:20px;">
                    <a href="login.php" class="auth-submit" style="text-decoration:none;text-align:center;display:flex;justify-content:center;">
                        <span class="auth-submit-label">Back to login</span>
                    </a>
                </div>
            <?php else: ?>
                <form method="POST" class="auth-form">
                    <div class="auth-field">
                        <label for="email">Email address</label>
                        <div class="auth-input-wrap">
                            <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email_input); ?>" placeholder="you@company.com" autocomplete="email" required autofocus>
                        </div>
                        <small style="font-size:12px;color:#64748b;margin-top:6px;display:block;">Enter the email you used for your quota / account (Real ID will be included in email).</small>
                    </div>
                    <button type="submit" class="auth-submit">
                        <span class="auth-submit-label">Send reset link</span>
                        <span class="auth-spinner" aria-hidden="true"></span>
                    </button>
                </form>
                <footer class="auth-card-foot">
                    <a href="login.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        Back to login
                    </a>
                    <span class="auth-secure">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Secure reset
                    </span>
                </footer>
            <?php endif; ?>
        </section>
    </main>
</div>

</body>
</html>
