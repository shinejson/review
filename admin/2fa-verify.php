<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/two_factor.php';

// If already fully signed in, proceed to dashboard
if (isLoggedIn()) {
    redirect('index.php');
}

$pending = tfa_get_pending_session();
if (!$pending || ($pending['portal'] ?? '') !== 'admin') {
    tfa_clear_pending_session();
    redirect('login.php');
}

$userType = $pending['user_type'];
$userId   = (int)$pending['user_id'];
$method   = $pending['method'] ?? 'totp';
$maskedEmail = !empty($pending['target_email']) ? tfa_mask_email($pending['target_email']) : 'your email';

$error   = '';
$success = '';

// Handle cancel request
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    tfa_clear_pending_session();
    redirect('login.php');
}

// Handle resend email OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please try again.';
    } elseif ($method !== 'email') {
        $error = 'Email verification is not active for this account.';
    } else {
        $now = time();
        $lastResend = (int)($pending['last_resend'] ?? 0);
        if ($now - $lastResend < 60) {
            $waitSeconds = 60 - ($now - $lastResend);
            $error = "Please wait {$waitSeconds} seconds before requesting another code.";
        } else {
            $_SESSION['2fa_pending']['last_resend'] = $now;
            $res = tfa_send_email_otp($conn, $userType, $userId, $pending['target_email'], $pending['target_name']);
            if (!empty($res['success'])) {
                $success = 'A fresh 6-digit verification code was sent to ' . htmlspecialchars($maskedEmail) . '.';
            } else {
                $error = 'Could not send verification email. ' . htmlspecialchars($res['message'] ?? 'Please check SMTP settings.');
            }
        }
    }
}

// Handle code verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'verify_2fa')) {
    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($code === '') {
            $error = 'Please enter your verification code.';
        } else {
            // Track attempts to prevent brute force
            $attempts = (int)($pending['attempts'] ?? 0) + 1;
            $_SESSION['2fa_pending']['attempts'] = $attempts;

            if ($attempts > 5) {
                tfa_clear_pending_session();
                redirect('login.php?notice=too_many_attempts');
            }

            $usedBackup = false;
            $verified = tfa_verify_code($conn, $userType, $userId, $code, $usedBackup);

            if ($verified) {
                // Populate authenticated session data
                $sessionData = $pending['session_data'] ?? [];
                foreach ($sessionData as $k => $v) {
                    $_SESSION[$k] = $v;
                }

                // If team member, record login timestamp
                if (!empty($sessionData['touch_team_last_login'])) {
                    $tmId = (int)$sessionData['touch_team_last_login'];
                    $ll = $conn->prepare("UPDATE team_members SET last_login_at = NOW() WHERE id = ?");
                    if ($ll) {
                        $ll->bind_param("i", $tmId);
                        $ll->execute();
                        $ll->close();
                    }
                }

                // Register session in user_sessions table
                $authUserId   = (int)($sessionData['auth_user_id'] ?? $userId);
                $authLabel    = (string)($sessionData['auth_label'] ?? 'Admin');
                $authUserKind = (string)($sessionData['auth_user_kind'] ?? 'admin');
                auth_login_session($conn, 'admin', $authUserId, $authLabel, $authUserKind);

                if ($usedBackup) {
                    $_SESSION['flash_warning'] = 'You used a single-use backup recovery code. Please check your remaining backup codes in Security Settings.';
                }

                // Clear pending 2FA state
                tfa_clear_pending_session();
                redirect('index.php');
            } else {
                $remaining = max(0, 5 - $attempts);
                $error = "Invalid verification code. Please check and try again. ({$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining)";
            }
        }
    }
}

$robots    = 'noindex, nofollow';
$BASE      = '../';
$assetBase = rtrim($BASE, '/');
$pageTitle = 'Two-Factor Authentication';
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
            <h1>Enhanced account security <em>with 2FA</em>.</h1>
            <p>Two-factor authentication adds an essential layer of defense to protect your customer ratings, store metrics, and business reputation.</p>
            <ul class="auth-points">
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Protection against unauthorized credential access
                </li>
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Compatible with Google Authenticator, Microsoft Authenticator &amp; Email OTP
                </li>
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Emergency recovery codes ensure you never get locked out
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
                <span class="auth-card-icon" style="background:rgba(194,245,66,0.12);color:#c2f542;border:1px solid rgba(194,245,66,0.3);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="11" r="3"/></svg>
                </span>
                <h2 id="authCardTitle">Two-Factor Verification</h2>
                <?php if ($method === 'email'): ?>
                    <p>Enter the 6-digit security code sent to <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>.</p>
                <?php else: ?>
                    <p>Enter the 6-digit code from your authenticator app (or enter a recovery code).</p>
                <?php endif; ?>
            </header>

            <?php if ($error): ?>
                <div class="auth-alert" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="auth-alert is-info" role="status" style="background:rgba(34,197,94,0.1);border-color:rgba(34,197,94,0.3);color:#4ade80;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="tfaForm" class="auth-form" action="2fa-verify.php">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="verify_2fa">

                <div class="auth-field">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label for="twoFactorCode" id="codeLabel" style="margin:0;">
                            <?php echo ($method === 'email') ? 'Email Verification Code' : '6-Digit Authenticator Code'; ?>
                        </label>
                        <button type="button" id="toggleModeBtn" style="background:none;border:none;padding:0;font-size:12px;color:#c2f542;cursor:pointer;font-weight:600;text-decoration:underline;">
                            Use recovery code
                        </button>
                    </div>

                    <div class="auth-input-wrap">
                        <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="text" id="twoFactorCode" name="code"
                               placeholder="e.g. 123456"
                               autocomplete="one-time-code"
                               inputmode="numeric"
                               maxlength="16"
                               style="font-family:monospace;letter-spacing:4px;font-size:19px;font-weight:700;text-align:center;"
                               autofocus required>
                    </div>
                    <small id="codeHint" style="font-size:11.5px;color:#94a3b8;margin-top:6px;display:block;">
                        <?php if ($method === 'email'): ?>
                            Check your inbox and spam folder for the code. Valid for 10 minutes.
                        <?php else: ?>
                            Open Google Authenticator, Microsoft Authenticator, or your password manager.
                        <?php endif; ?>
                    </small>
                </div>

                <button type="submit" class="auth-submit" id="authSubmit" style="background:#c2f542;color:#0a1a2a;font-weight:800;">
                    <span class="auth-submit-label">Verify &amp; Sign In</span>
                    <span class="auth-spinner" aria-hidden="true"></span>
                </button>
            </form>

            <?php if ($method === 'email'): ?>
                <form method="POST" action="2fa-verify.php" style="margin-top:14px;text-align:center;">
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="action" value="resend_otp">
                    <span style="font-size:12.5px;color:#94a3b8;">Didn't receive the email code? </span>
                    <button type="submit" style="background:none;border:none;padding:0;color:#c2f542;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:underline;">
                        Resend code
                    </button>
                </form>
            <?php endif; ?>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid rgba(148,163,184,0.12);text-align:center;">
                <a href="2fa-verify.php?action=cancel" style="font-size:12.5px;color:#94a3b8;text-decoration:none;">
                    &larr; Cancel and return to login
                </a>
            </div>

            <footer class="auth-card-foot" style="margin-top:18px;">
                <a href="<?php echo $assetBase; ?>/">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Back to website
                </a>
                <span class="auth-secure">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Two-factor protected
                </span>
            </footer>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('toggleModeBtn');
    var codeInput = document.getElementById('twoFactorCode');
    var codeLabel = document.getElementById('codeLabel');
    var codeHint  = document.getElementById('codeHint');
    var isRecoveryMode = false;

    var defaultLabel = <?php echo json_encode(($method === 'email') ? 'Email Verification Code' : '6-Digit Authenticator Code'); ?>;
    var defaultHint  = <?php echo json_encode(($method === 'email') ? 'Check your inbox and spam folder for the code. Valid for 10 minutes.' : 'Open Google Authenticator, Microsoft Authenticator, or your password manager.'); ?>;

    if (toggleBtn && codeInput) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            isRecoveryMode = !isRecoveryMode;

            if (isRecoveryMode) {
                codeLabel.textContent = 'Backup Recovery Code';
                codeInput.placeholder = 'e.g. 7A8B-9C2D';
                codeInput.inputMode   = 'text';
                codeInput.maxLength   = 12;
                codeInput.style.letterSpacing = '2px';
                codeHint.textContent  = 'Enter one of your 8-character single-use emergency recovery codes.';
                toggleBtn.textContent = 'Use standard code';
            } else {
                codeLabel.textContent = defaultLabel;
                codeInput.placeholder = 'e.g. 123456';
                codeInput.inputMode   = 'numeric';
                codeInput.maxLength   = 8;
                codeInput.style.letterSpacing = '4px';
                codeHint.textContent  = defaultHint;
                toggleBtn.textContent = 'Use recovery code';
            }
            codeInput.value = '';
            codeInput.focus();
        });
    }
});
</script>

<script src="<?php echo $assetBase; ?>/assets/js/auth.js"></script>
</body>
</html>
