<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/two_factor.php';

// If already signed in as super admin, proceed to dashboard
if (isSuperAdminLoggedIn()) {
    redirect('index.php');
}

$pending = tfa_get_pending_setup_session();
if (!$pending || ($pending['portal'] ?? '') !== 'superadmin') {
    tfa_clear_pending_setup_session();
    redirect('login.php');
}

$userContact = tfa_get_user_contact($conn, $userType, $userId);
$displayEmail = !empty($pending['target_email']) ? $pending['target_email'] : ($userContact['email'] ?? '');
$displayPhone = !empty($pending['target_phone']) ? $pending['target_phone'] : ($userContact['phone'] ?? '');
$displayName  = !empty($pending['target_name']) ? $pending['target_name'] : ($userContact['name'] ?: 'Super Admin');
$initial      = tfa_get_user_initial($displayName);
$maskedEmail  = !empty($displayEmail) ? tfa_mask_email($displayEmail) : 'your email';
$maskedPhone  = !empty($displayPhone) ? tfa_mask_phone($displayPhone) : 'your phone';

$error     = '';
$success   = '';
$activeTab = 'totp';
$waDirectUrl = '';

// Handle cancel request
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    tfa_clear_pending_setup_session();
    redirect('login.php');
}

// Prepare TOTP Secret & QR
$totpData = tfa_prepare_totp_setup($displayEmail ?: $displayName, 'Optibiz SuperAdmin');
$secret = $totpData['secret'];
$qrUrl = $totpData['qr_url'];
$formattedSecret = $totpData['formatted'];

// Handle WhatsApp OTP dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_whatsapp_code') {
    $activeTab = 'whatsapp';
    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please try again.';
    } else {
        $phoneInput = trim($_POST['phone_number'] ?? $displayPhone);
        $emailInput = trim($_POST['email'] ?? $displayEmail);

        if (!empty($emailInput)) {
            tfa_update_user_contact($conn, $userType, $userId, $emailInput, null);
            $displayEmail = $emailInput;
            $maskedEmail  = tfa_mask_email($displayEmail);
        }

        if (empty($phoneInput)) {
            $error = 'Please enter a valid WhatsApp phone number with country code (e.g. +233 24 555 0118).';
        } else {
            $res = tfa_send_whatsapp_otp($conn, $userType, $userId, $phoneInput, $displayName);
            if (!empty($res['success'])) {
                $displayPhone = $res['phone'];
                $maskedPhone  = $res['masked'];
                $waDirectUrl  = $res['wa_url'] ?? '';
                $success = 'A 6-digit verification code was sent to your WhatsApp at ' . htmlspecialchars($maskedPhone) . '.';
            } else {
                $error = 'Could not send WhatsApp code: ' . htmlspecialchars($res['message'] ?? 'Please check number format.');
            }
        }
    }
}

// Handle Email OTP dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_email_code') {
    $activeTab = 'email';
    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please try again.';
    } else {
        $emailInput = trim($_POST['email'] ?? $displayEmail);
        if (empty($emailInput) || !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            tfa_update_user_contact($conn, $userType, $userId, $emailInput, null);
            $displayEmail = $emailInput;
            $maskedEmail  = tfa_mask_email($displayEmail);

            $res = tfa_send_email_otp($conn, $userType, $userId, $displayEmail, $displayName);
            if (!empty($res['success'])) {
                $success = 'A 6-digit confirmation code was sent to ' . htmlspecialchars($maskedEmail) . '.';
            } else {
                $error = 'Could not send verification email. ' . htmlspecialchars($res['message'] ?? 'Please check SMTP settings.');
            }
        }
    }
}

// Handle Setup Confirmation & Activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'confirm_setup')) {
    $chosenMethod = in_array($_POST['chosen_method'] ?? 'totp', ['totp', 'whatsapp', 'email'], true) ? $_POST['chosen_method'] : 'totp';
    $activeTab = $chosenMethod;

    if (!sa_csrf_ok()) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($code === '' && isset($_POST['d0'], $_POST['d1'], $_POST['d2'], $_POST['d3'], $_POST['d4'], $_POST['d5'])) {
            $code = trim($_POST['d0'] . $_POST['d1'] . $_POST['d2'] . $_POST['d3'] . $_POST['d4'] . $_POST['d5']);
        }

        if ($code === '') {
            $error = 'Please enter the 6-digit code to confirm setup.';
        } else {
            $codeVerified = false;

            if ($chosenMethod === 'totp') {
                $codeVerified = tfa_verify_totp($secret, $code);
            } elseif ($chosenMethod === 'email') {
                $codeVerified = tfa_verify_email_otp($conn, $userType, $userId, $code);
            } elseif ($chosenMethod === 'whatsapp') {
                $codeVerified = tfa_verify_whatsapp_otp($conn, $userType, $userId, $code);
            }

            if ($codeVerified) {
                // Generate 8 emergency recovery backup codes
                $backup = tfa_generate_backup_codes(8);

                // Enable 2FA in database
                $enabled = tfa_enable(
                    $conn,
                    $userType,
                    $userId,
                    $chosenMethod,
                    ($chosenMethod === 'totp' ? $secret : ''),
                    $backup['hashed'],
                    ($chosenMethod === 'whatsapp' ? $displayPhone : null)
                );

                if ($enabled) {
                    // Check if user chose to trust this device for 30 days
                    if (!empty($_POST['trust_device'])) {
                        tfa_trust_device($conn, $userType, $userId, 30);
                    }

                    // Populate authenticated session data
                    $sessionData = $pending['session_data'] ?? [];
                    foreach ($sessionData as $k => $v) {
                        $_SESSION[$k] = $v;
                    }

                    // Register session in user_sessions table
                    $authUserId   = (int)($sessionData['auth_user_id'] ?? $userId);
                    $authLabel    = (string)($sessionData['auth_label'] ?? 'superadmin');
                    $authUserKind = (string)($sessionData['auth_user_kind'] ?? 'super_admin');
                    auth_login_session($conn, 'superadmin', $authUserId, $authLabel, $authUserKind);

                    // Store backup codes in session for display under settings
                    $_SESSION['tfa_first_setup_backup_codes'] = $backup['plain'];
                    sa_flash('success', 'Two-Factor Authentication has been successfully enabled for this Super Admin account.');

                    // Clear pending setup session and setup secret
                    unset($_SESSION['tfa_setup_secret'], $_SESSION['last_whatsapp_otp']);
                    tfa_clear_pending_setup_session();
                    redirect('index.php');
                } else {
                    $error = 'Failed to activate 2FA in the database. Please try again.';
                }
            } else {
                $error = 'Invalid 6-digit code. Please verify your code and try again.';
            }
        }
    }
}

$robots    = 'noindex, nofollow';
$BASE      = '../';
$GLOBALS['BASE'] = $BASE;
$pageTitle = 'Setup Super Admin Two-Factor Authentication';
$extraCss = ['assets/css/auth.css'];
$login_logo_url = function_exists('sa_platform_logo') ? sa_platform_logo($conn) : '';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="auth-shell">

    <button type="button" class="auth-theme-toggle" data-sa-theme aria-pressed="false" aria-label="Switch theme" title="Switch theme">
        <span class="auth-theme-thumb">
            <span class="icon-moon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
            <span class="icon-sun"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg></span>
        </span>
    </button>

    <!-- Brand panel -->
    <aside class="auth-brand">
        <a class="auth-logo" href="<?php echo $BASE; ?>index.php">
            <span class="auth-logo-badge">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            </span>
            <span class="auth-logo-name">
                <strong>Optibiz</strong>
                <span>Control Center</span>
            </span>
        </a>

        <div class="auth-brand-body">
            <h1>Protected root access <em>is required</em>.</h1>
            <p>Every Super Administrator account must configure Two-Factor Authentication before accessing system infrastructure, revenue engines, and customer tenant databases.</p>

            <ul class="auth-points">
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Compatible with Google Authenticator, Microsoft Authenticator &amp; Email OTP
                </li>
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Trust this workstation for 30 days to streamline access
                </li>
                <li>
                    <span class="auth-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                    Hashed single-use emergency backup recovery keys
                </li>
            </ul>
        </div>

        <p class="auth-brand-foot">&copy; <?php echo date('Y'); ?> Optibiz &middot; Platform Control Center</p>
    </aside>

    <!-- Form panel -->
    <main class="auth-panel">
        <section class="auth-card auth-card-relative" style="max-width: 520px;">

            <!-- Back Arrow -->
            <a href="2fa-setup.php?action=cancel" class="auth-card-back" aria-label="Cancel and return to login" title="Cancel and return to login">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            </a>

            <!-- Logo -->
            <div class="auth-logo-center">
                <?php if (!empty($login_logo_url)): ?>
                    <img src="<?php echo htmlspecialchars($login_logo_url); ?>" alt="Optibiz" class="auth-custom-logo">
                <?php else: ?>
                    <span class="auth-logo-badge auth-badge-lg">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    </span>
                <?php endif; ?>
            </div>

            <!-- User Avatar Pill -->
            <div style="display:flex;justify-content:center;margin: 8px 0 14px 0;">
                <div class="user-identity-capsule">
                    <span class="user-capsule-avatar"><?php echo htmlspecialchars($initial); ?></span>
                    <span class="user-capsule-name"><?php echo htmlspecialchars($displayName); ?></span>
                </div>
            </div>

            <div style="text-align:center;margin-bottom:18px;">
                <h2 class="tfa-heading">Configure Super Admin 2FA</h2>
                <p class="tfa-subheading">
                    Complete multi-factor enrollment to unlock your control center session.
                </p>
            </div>

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

            <!-- Method Switcher Pills -->
            <div class="tfa-switcher-bar">
                <button type="button" id="tabTotp" class="method-tab <?php echo $activeTab === 'totp' ? 'active' : ''; ?>">
                    📱 Authenticator App
                </button>
                <button type="button" id="tabWhatsApp" class="method-tab <?php echo $activeTab === 'whatsapp' ? 'active' : ''; ?>">
                    💬 WhatsApp 2FA
                </button>
                <button type="button" id="tabEmail" class="method-tab <?php echo $activeTab === 'email' ? 'active' : ''; ?>">
                    ✉️ Email OTP
                </button>
            </div>

            <!-- TOTP Method Container -->
            <div id="containerTotp" style="display:<?php echo $activeTab === 'totp' ? 'block' : 'none'; ?>;">
                <div class="tfa-card-box">
                    <p class="tfa-card-text">
                        1. Scan this QR code with <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong>, or <strong>1Password</strong>:
                    </p>
                    <div style="display:inline-block;padding:10px;background:#ffffff;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                        <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="Scan 2FA QR Code" width="160" height="160" style="display:block;">
                    </div>
                    <p class="tfa-card-text" style="margin:12px 0 6px 0;">Or enter this manual secret key into your app:</p>
                    <div class="tfa-secret-badge">
                        <code><?php echo htmlspecialchars($formattedSecret); ?></code>
                    </div>
                </div>
            </div>

            <!-- WhatsApp Method Container -->
            <div id="containerWhatsApp" style="display:<?php echo $activeTab === 'whatsapp' ? 'block' : 'none'; ?>;">
                <div class="tfa-card-box" style="text-align:left;">
                    <form method="POST" id="sendWhatsAppOtpForm" action="2fa-setup.php">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="send_whatsapp_code">

                        <p class="tfa-card-text" style="margin-bottom:12px;font-size:13.5px;">
                            We will send a 6-digit verification code directly to your <strong>WhatsApp</strong>.
                        </p>

                        <div style="margin-bottom:12px;">
                            <label class="tfa-field-label" style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:var(--ob-muted);">
                                WhatsApp Phone Number <span style="color:#ef4444;">*</span>
                            </label>
                            <div style="position:relative;">
                                <input type="tel" name="phone_number" id="setupWhatsAppPhone" value="<?php echo htmlspecialchars($displayPhone); ?>" placeholder="+233 24 555 0118" required style="width:100%;box-sizing:border-box;padding:10px 14px;border-radius:8px;border:1px solid rgba(148,163,184,0.3);background:rgba(15,23,42,0.5);color:#fff;font-size:14px;font-weight:600;">
                            </div>
                            <span style="font-size:11.5px;color:var(--ob-muted);display:block;margin-top:4px;">Include your country code (e.g. +233 for Ghana, +1 for US/Canada, +44 for UK).</span>
                        </div>

                        <?php if (empty($displayEmail)): ?>
                        <div style="margin-bottom:14px;">
                            <label class="tfa-field-label" style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:var(--ob-muted);">
                                Email Address <span style="color:#ef4444;">*</span>
                            </label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($displayEmail); ?>" placeholder="you@example.com" required style="width:100%;box-sizing:border-box;padding:10px 14px;border-radius:8px;border:1px solid rgba(148,163,184,0.3);background:rgba(15,23,42,0.5);color:#fff;font-size:14px;font-weight:600;">
                        </div>
                        <?php endif; ?>

                        <div style="text-align:center;margin-top:14px;">
                            <button type="submit" id="btnSendSetupWhatsApp" style="background:#25D366;color:#0b2e13;font-weight:800;font-size:13.5px;padding:10px 22px;border-radius:8px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 3px 12px rgba(37,211,102,0.3);">
                                <svg viewBox="0 0 24 24" fill="currentColor" style="width:17px;height:17px;" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2m.01 1.67c2.2 0 4.26.86 5.82 2.42a8.23 8.23 0 0 1 2.41 5.83c0 4.54-3.7 8.24-8.24 8.24-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.19 8.19 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24m4.52 11.66c-.25-.13-1.47-.72-1.7-.81-.23-.08-.39-.13-.56.13-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.13-1.06-.39-2.02-1.25-.75-.67-1.26-1.5-1.41-1.75-.14-.25-.02-.39.11-.51.11-.11.25-.29.37-.44.13-.14.17-.25.25-.42.08-.17.04-.31-.02-.44-.06-.13-.56-1.34-.76-1.84-.2-.49-.4-.42-.56-.43h-.48c-.17 0-.44.06-.67.31-.23.25-.88.86-.88 2.1 0 1.24.9 2.44 1.03 2.61.13.17 1.77 2.71 4.3 3.79.6.26 1.07.41 1.44.53.61.19 1.16.17 1.6.1 1.03-.15 1.47-1.18 1.47-1.72 0-.25-.13-.39-.38-.52z"/></svg>
                                Send 6-Digit WhatsApp Code
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Email Method Container -->
            <div id="containerEmail" style="display:<?php echo $activeTab === 'email' ? 'block' : 'none'; ?>;">
                <div class="tfa-card-box" style="text-align:left;">
                    <form method="POST" id="sendEmailOtpForm" action="2fa-setup.php">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="send_email_code">

                        <p class="tfa-card-text" style="font-size:13.5px;margin:0 0 12px 0;">
                            Receive one-time passcodes at <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>.
                        </p>

                        <div style="margin-bottom:12px;">
                            <label class="tfa-field-label" style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:var(--ob-muted);">
                                Verify / Update Email Address
                            </label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($displayEmail); ?>" placeholder="you@example.com" required style="width:100%;box-sizing:border-box;padding:10px 14px;border-radius:8px;border:1px solid rgba(148,163,184,0.3);background:rgba(15,23,42,0.5);color:#fff;font-size:14px;font-weight:600;">
                        </div>

                        <div style="text-align:center;margin-top:14px;">
                            <button type="submit" id="btnSendSetupEmail" style="background:#1e293b;border:1px solid #c2f542;color:#c2f542;font-weight:700;font-size:13px;padding:9px 20px;border-radius:8px;cursor:pointer;">
                                Send 6-Digit Email Code
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Confirmation Code Form -->
            <form method="POST" id="confirmSetupForm" class="auth-form" action="2fa-setup.php">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="confirm_setup">
                <input type="hidden" name="chosen_method" id="chosenMethodInput" value="<?php echo htmlspecialchars($activeTab); ?>">
                <input type="hidden" name="code" id="twoFactorFinalCode" value="">

                <label class="tfa-step-label" id="confirmLabel">
                    <?php 
                        if ($activeTab === 'whatsapp') {
                            echo '2. Enter the 6-digit WhatsApp code to confirm';
                        } elseif ($activeTab === 'email') {
                            echo '2. Enter the 6-digit email code to confirm';
                        } else {
                            echo '2. Enter the 6-digit code to confirm and activate';
                        }
                    ?>
                </label>

                <!-- 6 Segmented Boxes -->
                <div class="otp-boxes-grid" id="otpBoxesGrid">
                    <input type="text" name="d0" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code" autofocus>
                    <input type="text" name="d1" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" name="d2" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" name="d3" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" name="d4" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    <input type="text" name="d5" class="otp-box" maxlength="1" pattern="[0-9]" inputmode="numeric">
                </div>

                <!-- Trust this device for 30 days Card -->
                <div class="trust-device-box">
                    <label class="trust-device-check-row">
                        <input type="checkbox" name="trust_device" value="1" checked class="trust-device-checkbox">
                        <div class="trust-device-copy">
                            <span class="trust-device-title">Trust this device for 30 days</span>
                            <span class="trust-device-desc">Skip the code on this device. New sign-ins still alert you.</span>
                        </div>
                    </label>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="auth-submit btn-verify-code" id="authSubmit">
                    <span class="auth-submit-label">COMPLETE SETUP &amp; SIGN IN</span>
                    <span class="auth-spinner" aria-hidden="true"></span>
                </button>
            </form>

            <footer class="auth-card-foot" style="margin-top:20px;">
                <a href="2fa-setup.php?action=cancel">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Cancel and return to login
                </a>
                <span class="auth-secure">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Security Enforced
                </span>
            </footer>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var tabTotp = document.getElementById('tabTotp');
    var tabWhatsApp = document.getElementById('tabWhatsApp');
    var tabEmail = document.getElementById('tabEmail');
    var containerTotp = document.getElementById('containerTotp');
    var containerWhatsApp = document.getElementById('containerWhatsApp');
    var containerEmail = document.getElementById('containerEmail');
    var chosenMethodInput = document.getElementById('chosenMethodInput');
    var confirmLabel = document.getElementById('confirmLabel');

    var form = document.getElementById('confirmSetupForm');
    var finalCodeInput = document.getElementById('twoFactorFinalCode');
    var boxes = Array.from(document.querySelectorAll('.otp-box'));

    tabTotp.addEventListener('click', function() {
        tabTotp.classList.add('active');
        tabWhatsApp.classList.remove('active');
        tabEmail.classList.remove('active');
        containerTotp.style.display = 'block';
        containerWhatsApp.style.display = 'none';
        containerEmail.style.display = 'none';
        chosenMethodInput.value = 'totp';
        confirmLabel.textContent = '2. Enter the 6-digit code from your app to confirm';
        boxes[0].focus();
    });

    tabWhatsApp.addEventListener('click', function() {
        tabWhatsApp.classList.add('active');
        tabTotp.classList.remove('active');
        tabEmail.classList.remove('active');
        containerWhatsApp.style.display = 'block';
        containerTotp.style.display = 'none';
        containerEmail.style.display = 'none';
        chosenMethodInput.value = 'whatsapp';
        confirmLabel.textContent = '2. Enter the 6-digit WhatsApp code to confirm';
        boxes[0].focus();
    });

    tabEmail.addEventListener('click', function() {
        tabEmail.classList.add('active');
        tabTotp.classList.remove('active');
        tabWhatsApp.classList.remove('active');
        containerEmail.style.display = 'block';
        containerTotp.style.display = 'none';
        containerWhatsApp.style.display = 'none';
        chosenMethodInput.value = 'email';
        confirmLabel.textContent = '2. Enter the 6-digit email code to confirm';
        boxes[0].focus();
    });

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

                if (digits.length === 6) {
                    form.submit();
                }
            }
        });
    });

    function updateFinalCode() {
        finalCodeInput.value = boxes.map(function(b) { return b.value; }).join('');
    }

    form.addEventListener('submit', function(e) {
        updateFinalCode();
        if (!finalCodeInput.value || finalCodeInput.value.length < 6) {
            e.preventDefault();
            boxes[0].focus();
        }
    });
});
</script>

<script src="<?php echo $BASE; ?>assets/js/auth.js"></script>
</body>
</html>
