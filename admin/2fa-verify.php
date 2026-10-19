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

$userType     = $pending['user_type'];
$userId       = (int)$pending['user_id'];
$method       = $pending['method'] ?? 'totp';
$displayName  = !empty($pending['target_name']) ? $pending['target_name'] : 'User';
$displayEmail = $pending['target_email'] ?? '';
$initial      = tfa_get_user_initial($displayName);
$maskedEmail  = !empty($displayEmail) ? tfa_mask_email($displayEmail) : 'your email';

$userContact  = tfa_get_user_contact($conn, $userType, $userId);
$displayPhone = !empty($pending['target_phone']) ? $pending['target_phone'] : ($userContact['phone'] ?? '');
$maskedPhone  = !empty($displayPhone) ? tfa_mask_phone($displayPhone) : 'your WhatsApp phone';
if (empty($displayEmail) && !empty($userContact['email'])) {
    $displayEmail = $userContact['email'];
    $maskedEmail  = tfa_mask_email($displayEmail);
}

$error   = '';
$success = '';
$deliveryWarning = (string)($pending['delivery_warning'] ?? '');
unset($_SESSION['2fa_pending']['delivery_warning']);

// Handle cancel request
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    tfa_clear_pending_session();
    redirect('login.php');
}

// Handle resend WhatsApp OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_whatsapp') {
    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please try again.';
    } elseif (empty($displayPhone)) {
        $error = 'No WhatsApp phone number is associated with this account.';
    } else {
        $now = time();
        $lastResend = (int)($pending['last_resend'] ?? 0);
        if ($now - $lastResend < 60) {
            $waitSeconds = 60 - ($now - $lastResend);
            $error = "Please wait {$waitSeconds} seconds before requesting another WhatsApp code.";
        } else {
            $_SESSION['2fa_pending']['last_resend'] = $now;
            $res = tfa_send_whatsapp_otp($conn, $userType, $userId, $displayPhone, $displayName);
            if (!empty($res['success']) && !empty($res['delivered'])) {
                $success = 'A fresh 6-digit verification code was sent to WhatsApp at ' . htmlspecialchars($maskedPhone) . '.';
            } elseif (!empty($res['success'])) {
                $error = htmlspecialchars($res['message'] ?? 'The WhatsApp code could not be delivered automatically.');
            } else {
                $error = 'Could not send WhatsApp code: ' . htmlspecialchars($res['message'] ?? 'Please check number format.');
            }
        }
    }
}

// Handle resend email OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please try again.';
    } elseif (empty($displayEmail)) {
        $error = 'Email verification is not available for this account.';
    } else {
        $now = time();
        $lastResend = (int)($pending['last_resend'] ?? 0);
        if ($now - $lastResend < 60) {
            $waitSeconds = 60 - ($now - $lastResend);
            $error = "Please wait {$waitSeconds} seconds before requesting another code.";
        } else {
            $_SESSION['2fa_pending']['last_resend'] = $now;
            $res = tfa_send_email_otp($conn, $userType, $userId, $displayEmail, $displayName);
            if (!empty($res['success'])) {
                $success = 'A fresh 6-digit verification code was sent to ' . htmlspecialchars($maskedEmail) . '.';
            } else {
                $error = 'Could not send verification email. ' . htmlspecialchars($res['message'] ?? 'Please check SMTP settings.');
            }
        }
    }
}

// Handle switch to Email OTP fallback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'switch_to_email') {
    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please try again.';
    } elseif (empty($displayEmail)) {
        $error = 'No email address registered for this account.';
    } else {
        $res = tfa_send_email_otp($conn, $userType, $userId, $displayEmail, $displayName);
        if (!empty($res['success'])) {
            $_SESSION['2fa_pending']['method'] = 'email';
            $method = 'email';
            $success = 'Verification method switched! A 6-digit code was sent to ' . htmlspecialchars($maskedEmail) . '.';
        } else {
            $error = 'Could not send verification email: ' . htmlspecialchars($res['message'] ?? 'Check SMTP settings.');
        }
    }
}

// Handle switch to WhatsApp OTP fallback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'switch_to_whatsapp') {
    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please try again.';
    } elseif (empty($displayPhone)) {
        $error = 'No WhatsApp phone number registered for this account.';
    } else {
        $res = tfa_send_whatsapp_otp($conn, $userType, $userId, $displayPhone, $displayName);
        if (!empty($res['success']) && !empty($res['delivered'])) {
            $_SESSION['2fa_pending']['method'] = 'whatsapp';
            $method = 'whatsapp';
            $success = 'Verification method switched! A 6-digit code was sent to WhatsApp at ' . htmlspecialchars($maskedPhone) . '.';
        } elseif (!empty($res['success'])) {
            $_SESSION['2fa_pending']['method'] = 'whatsapp';
            $method = 'whatsapp';
            $error = htmlspecialchars($res['message'] ?? 'The WhatsApp code could not be delivered automatically.');
        } else {
            $error = 'Could not send WhatsApp code: ' . htmlspecialchars($res['message'] ?? 'Check phone number format.');
        }
    }
}

// Handle code verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'verify_2fa')) {
    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($code === '' && isset($_POST['d0'], $_POST['d1'], $_POST['d2'], $_POST['d3'], $_POST['d4'], $_POST['d5'])) {
            $code = trim($_POST['d0'] . $_POST['d1'] . $_POST['d2'] . $_POST['d3'] . $_POST['d4'] . $_POST['d5']);
        }

        if ($code === '') {
            $error = 'Please enter your 6-digit verification code.';
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
                // Check if user chose to trust this device for 30 days
                if (!empty($_POST['trust_device'])) {
                    tfa_trust_device($conn, $userType, $userId, 30);
                }

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
                    $_SESSION['flash_warning'] = 'You used an emergency single-use backup recovery code. Please check your remaining backup codes in Security Settings.';
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
                    Remember this device for 30 days for seamless verified access
                </li>
            </ul>
        </div>

        <p class="auth-brand-foot">&copy; <?php echo date('Y'); ?> Optibiz &middot; Business Reputation Workspace</p>
    </aside>

    <!-- Form panel -->
    <main class="auth-panel">
        <section class="auth-card auth-card-relative" aria-labelledby="authCardTitle">

            <!-- Back Arrow (Image 3) -->
            <a href="2fa-verify.php?action=cancel" class="auth-card-back" aria-label="Cancel and return to login" title="Cancel and return to login">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            </a>

            <!-- Logo -->
            <a class="auth-logo auth-mobile-logo" href="<?php echo $assetBase; ?>/">
                <span class="auth-logo-badge">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                </span>
                <span class="auth-logo-name">
                    <strong>Optibiz</strong>
                    <span>Rating Platform</span>
                </span>
            </a>

            <!-- User Avatar Pill (Image 2 & 3) -->
            <div style="display:flex;justify-content:center;margin: 6px 0 16px 0;">
                <div class="user-identity-capsule">
                    <span class="user-capsule-avatar"><?php echo htmlspecialchars($initial); ?></span>
                    <span class="user-capsule-name"><?php echo htmlspecialchars($displayName); ?></span>
                </div>
            </div>

            <!-- Header Description -->
            <p class="otp-instruction-text" id="otpInstruction">
                <?php if ($method === 'whatsapp'): ?>
                    Enter the 6-digit verification code for your WhatsApp at <strong><?php echo htmlspecialchars($maskedPhone); ?></strong> below. If it has not arrived within a minute, use the resend or email options below.
                <?php elseif ($method === 'email'): ?>
                    Enter the 6-digit verification code for <strong><?php echo htmlspecialchars($maskedEmail); ?></strong> below. If it has not arrived within a minute, check your spam folder or resend.
                <?php else: ?>
                    Enter the 6-digit verification code from your authenticator app below.
                <?php endif; ?>
            </p>

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

            <?php if ($deliveryWarning && !$error && !$success): ?>
                <div class="auth-alert" role="alert" style="background:rgba(245,158,11,0.1);border-color:rgba(245,158,11,0.35);color:#fbbf24;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span><?php echo htmlspecialchars($deliveryWarning); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="tfaForm" class="auth-form" action="2fa-verify.php">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="verify_2fa">
                <input type="hidden" name="code" id="twoFactorFinalCode" value="">

                <!-- Segmented 6-digit OTP Inputs (Image 3) -->
                <div id="standardOtpSection">
                    <div class="otp-boxes-grid" id="otpBoxesGrid">
                        <input type="text" name="d0" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code" autofocus>
                        <input type="text" name="d1" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" name="d2" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" name="d3" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" name="d4" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" name="d5" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    </div>

                    <!-- Countdown Timer Pill (Image 3) -->
                    <div style="display:flex;justify-content:center;margin: 12px 0 16px 0;">
                        <div class="otp-expires-badge" id="otpTimerBadge">
                            Expires in <span id="otpCountdownTimer"><?php echo ($method === 'email' || $method === 'whatsapp') ? '09:59' : '04:59'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Recovery Code Alternative Input (Hidden by default) -->
                <div id="recoveryCodeSection" style="display:none;margin-bottom:18px;">
                    <div class="auth-field">
                        <label for="recoveryCodeInput">Emergency Backup Recovery Code</label>
                        <div class="auth-input-wrap">
                            <svg class="auth-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="text" id="recoveryCodeInput" placeholder="e.g. 7A8B-9C2D" maxlength="12" style="font-family:monospace;letter-spacing:2px;font-size:18px;font-weight:700;text-transform:uppercase;">
                        </div>
                        <small style="font-size:11.5px;color:#94a3b8;margin-top:6px;display:block;">
                            Enter one of your 8-character single-use emergency backup codes.
                        </small>
                    </div>
                </div>

                <!-- Trust this device for 30 days Card (Image 3) -->
                <div class="trust-device-box">
                    <label class="trust-device-check-row">
                        <input type="checkbox" name="trust_device" value="1" checked class="trust-device-checkbox">
                        <div class="trust-device-copy">
                            <span class="trust-device-title">Trust this device for 30 days</span>
                            <span class="trust-device-desc">Skip the code on this device. New sign-ins still alert you.</span>
                        </div>
                    </label>
                </div>

                <!-- VERIFY CODE Button (Image 3) -->
                <button type="submit" class="auth-submit btn-verify-code" id="authSubmit">
                    <span class="auth-submit-label">VERIFY CODE</span>
                    <span class="auth-spinner" aria-hidden="true"></span>
                </button>
            </form>

            <!-- Bottom Action Controls -->
            <div style="margin-top: 16px; text-align: center; display: flex; flex-direction: column; gap: 10px;">
                <?php if ($method === 'whatsapp'): ?>
                    <div>
                        <form method="POST" id="resendForm" action="2fa-verify.php" style="display:inline;">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="resend_whatsapp">
                            <span style="font-size:13px;color:#94a3b8;">Didn't receive the WhatsApp code? </span>
                            <button type="submit" id="resendBtn" style="background:none;border:none;padding:0;color:#25D366;font-size:13px;font-weight:700;cursor:pointer;">
                                Resend WhatsApp Code <span id="resendTimerText">(60s)</span>
                            </button>
                        </form>
                    </div>
                    <?php if (!empty($displayEmail)): ?>
                    <div>
                        <form method="POST" action="2fa-verify.php" style="display:inline;">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="switch_to_email">
                            <button type="submit" style="background:none;border:none;padding:0;font-size:12.5px;color:#94a3b8;cursor:pointer;text-decoration:underline;">
                                Send code to my email instead (<?php echo htmlspecialchars($maskedEmail); ?>)
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                <?php elseif ($method === 'email'): ?>
                    <div>
                        <form method="POST" id="resendForm" action="2fa-verify.php" style="display:inline;">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="resend_otp">
                            <span style="font-size:13px;color:#94a3b8;">Didn't receive the code? </span>
                            <button type="submit" id="resendBtn" style="background:none;border:none;padding:0;color:#c2f542;font-size:13px;font-weight:700;cursor:pointer;">
                                Resend OTP <span id="resendTimerText">(60s)</span>
                            </button>
                        </form>
                    </div>
                    <?php if (!empty($displayPhone)): ?>
                    <div>
                        <form method="POST" action="2fa-verify.php" style="display:inline;">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="switch_to_whatsapp">
                            <button type="submit" style="background:none;border:none;padding:0;font-size:12.5px;color:#25D366;cursor:pointer;text-decoration:underline;">
                                Send code via WhatsApp instead (<?php echo htmlspecialchars($maskedPhone); ?>)
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div>
                    <button type="button" id="toggleModeBtn" style="background:none;border:none;padding:0;font-size:12.5px;color:#94a3b8;cursor:pointer;text-decoration:underline;">
                        Use emergency recovery backup code
                    </button>
                </div>
            </div>

            <footer class="auth-card-foot" style="margin-top:24px;">
                <a href="2fa-verify.php?action=cancel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Cancel and sign in
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
    var form = document.getElementById('tfaForm');
    var finalCodeInput = document.getElementById('twoFactorFinalCode');
    var boxes = Array.from(document.querySelectorAll('.otp-box'));
    var standardSection = document.getElementById('standardOtpSection');
    var recoverySection = document.getElementById('recoveryCodeSection');
    var recoveryInput = document.getElementById('recoveryCodeInput');
    var toggleBtn = document.getElementById('toggleModeBtn');
    var isRecovery = false;

    // Segmented input box handlers: auto-advance, backspace, paste
    boxes.forEach(function(box, index) {
        box.addEventListener('input', function(e) {
            var val = box.value.replace(/[^0-9]/g, '');
            box.value = val ? val.charAt(0) : '';

            if (box.value && index < boxes.length - 1) {
                boxes[index + 1].focus();
            }
            updateFinalCode();
        });

        box.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !box.value && index > 0) {
                boxes[index - 1].focus();
            }
        });

        box.addEventListener('paste', function(e) {
            e.preventDefault();
            var pasted = (e.clipboardData || window.clipboardData).getData('text');
            var digits = pasted.replace(/[^0-9]/g, '').slice(0, 6);
            if (digits.length > 0) {
                for (var i = 0; i < boxes.length; i++) {
                    boxes[i].value = digits[i] || '';
                }
                var focusIndex = Math.min(digits.length, boxes.length - 1);
                boxes[focusIndex].focus();
                updateFinalCode();

                // Auto-submit if all 6 digits pasted
                if (digits.length === 6) {
                    form.submit();
                }
            }
        });
    });

    function updateFinalCode() {
        if (isRecovery) {
            finalCodeInput.value = recoveryInput.value.trim();
        } else {
            finalCodeInput.value = boxes.map(function(b) { return b.value; }).join('');
        }
    }

    form.addEventListener('submit', function(e) {
        updateFinalCode();
        if (!finalCodeInput.value) {
            e.preventDefault();
            if (isRecovery) {
                recoveryInput.focus();
            } else {
                boxes[0].focus();
            }
        }
    });

    // Toggle Recovery Code Mode
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            isRecovery = !isRecovery;

            if (isRecovery) {
                standardSection.style.display = 'none';
                recoverySection.style.display = 'block';
                toggleBtn.textContent = 'Use standard 6-digit verification code';
                recoveryInput.focus();
            } else {
                standardSection.style.display = 'block';
                recoverySection.style.display = 'none';
                toggleBtn.textContent = 'Use emergency recovery backup code';
                boxes[0].focus();
            }
            updateFinalCode();
        });
    }

    // Countdown Timer for Expiry Badge
    var timerEl = document.getElementById('otpCountdownTimer');
    if (timerEl) {
        var duration = <?php echo ($method === 'email') ? '600' : '300'; ?>; // in seconds
        var timerInterval = setInterval(function() {
            duration--;
            if (duration <= 0) {
                clearInterval(timerInterval);
                timerEl.textContent = '00:00 (Expired)';
            } else {
                var m = Math.floor(duration / 60);
                var s = duration % 60;
                timerEl.textContent = (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            }
        }, 1000);
    }

    // Resend OTP Countdown (for Email OTP)
    var resendBtn = document.getElementById('resendBtn');
    var resendTimerText = document.getElementById('resendTimerText');
    if (resendBtn && resendTimerText) {
        var resendWait = 60;
        resendBtn.disabled = true;
        resendBtn.style.opacity = '0.5';
        resendBtn.style.cursor = 'not-allowed';

        var resendInterval = setInterval(function() {
            resendWait--;
            if (resendWait <= 0) {
                clearInterval(resendInterval);
                resendBtn.disabled = false;
                resendBtn.style.opacity = '1';
                resendBtn.style.cursor = 'pointer';
                resendTimerText.textContent = '';
            } else {
                resendTimerText.textContent = '(' + resendWait + 's)';
            }
        }, 1000);
    }
});
</script>

<script src="<?php echo $assetBase; ?>/assets/js/auth.js"></script>
</body>
</html>
