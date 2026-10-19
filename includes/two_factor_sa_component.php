<?php
/**
 * Optibiz - Two-Factor Authentication Component for Super Admin Control Center
 * Included inside the Account tab in superadmin/settings.php
 */

$sa_user_id = (int)($_SESSION['super_admin_id'] ?? 0);
$sa_user_type = 'superadmin';
$sa_user_email = $admin['email'] ?? ($_SESSION['super_admin_email'] ?? '');
$sa_user_label = $admin['username'] ?? ($_SESSION['super_admin_username'] ?? 'Super Admin');

$sa_tfa_status = tfa_get_status($conn, $sa_user_type, $sa_user_id);
$sa_tfa_enabled = $sa_tfa_status['is_enabled'];
$sa_tfa_method = $sa_tfa_status['method'];
$sa_tfa_remaining_codes = $sa_tfa_status['remaining_backup_codes'];
$sa_tfa_total_codes = $sa_tfa_status['total_backup_codes'] ?? 8;
$sa_tfa_new_codes = $_SESSION['tfa_sa_new_backup_codes'] ?? null;
unset($_SESSION['tfa_sa_new_backup_codes']);

// Prepare TOTP setup state
$sa_tfa_setup = tfa_prepare_totp_setup($sa_user_label, 'Optibiz Control Center');
$sa_current_setup_mode = $_GET['setup'] ?? 'totp';
?>

<!-- Two-Factor Authentication Card for Super Admin -->
<section class="sa-card" style="margin-top:20px;">
    <div class="sa-card-head">
        <div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;background:rgba(194,245,66,0.12);color:#c2f542;border:1px solid rgba(194,245,66,0.25);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="11" r="3"/></svg>
                </span>
                <h3 style="margin:0;">Two-Factor Authentication (2FA)</h3>
            </div>
            <p style="margin:4px 0 0;">Cryptographic multi-factor security for platform super administrators.</p>
        </div>
        <div>
            <?php if ($sa_tfa_enabled): ?>
                <span class="sa-badge sa-badge-active" style="padding:6px 14px;font-size:12.5px;">
                    ● Active &middot; <?php echo $sa_tfa_method === 'email' ? 'Email OTP' : 'Authenticator App (TOTP)'; ?>
                </span>
            <?php else: ?>
                <span class="sa-badge sa-badge-inactive" style="padding:6px 14px;font-size:12.5px;">
                    ○ Disabled
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="sa-card-pad">
        <?php if (!empty($sa_tfa_new_codes)): ?>
            <!-- Prominent Backup Codes Display Banner -->
            <div style="margin-bottom:24px;padding:20px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:12px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;color:#d97706;font-weight:700;font-size:15px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Save Your Super Admin Emergency Backup Recovery Codes
                </div>
                <p style="margin:0 0 14px;font-size:13px;color:#cbd5e1;line-height:1.5;">
                    Keep these emergency recovery codes in a secure, offline password manager. Each recovery code can only be used once to regain access if you lose your authenticator app.
                </p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;margin-bottom:16px;">
                    <?php foreach ($sa_tfa_new_codes as $code): ?>
                        <div style="background:#0a1a2a;border:1px solid rgba(148,163,184,0.2);border-radius:8px;padding:8px 12px;font-family:monospace;font-size:14px;font-weight:700;text-align:center;letter-spacing:1px;color:#c2f542;">
                            <?php echo htmlspecialchars($code); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <button type="button" class="sa-btn sa-btn-secondary" onclick="saCopyBackupCodes()" id="saCopyBackupCodesBtn" style="font-size:12.5px;">
                        📋 Copy All Codes
                    </button>
                    <button type="button" class="sa-btn sa-btn-secondary" onclick="saDownloadBackupCodes()" style="font-size:12.5px;">
                        📥 Download (.txt)
                    </button>
                </div>
            </div>
            <textarea id="saRawBackupCodes" style="display:none;"><?php echo htmlspecialchars(implode("\n", $sa_tfa_new_codes)); ?></textarea>
        <?php endif; ?>

        <?php if (!$sa_tfa_enabled): ?>
            <!-- Setup Flow -->
            <div>
                <p style="font-size:13.5px;color:#94a3b8;margin-bottom:16px;">
                    Select your preferred two-factor authentication method:
                </p>

                <!-- Method Selector Tabs -->
                <div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
                    <button type="button" class="sa-btn <?php echo $sa_current_setup_mode !== 'email' ? 'sa-btn-primary' : 'sa-btn-ghost'; ?>" onclick="saShowTfaSetup('totp')" id="saBtnSetupTotp" style="font-size:13px;">
                        📱 Authenticator App (Recommended)
                    </button>
                    <button type="button" class="sa-btn <?php echo $sa_current_setup_mode === 'email' ? 'sa-btn-primary' : 'sa-btn-ghost'; ?>" onclick="saShowTfaSetup('email')" id="saBtnSetupEmail" style="font-size:13px;">
                        ✉ Email Verification Codes
                    </button>
                </div>

                <!-- TOTP Setup Section -->
                <div id="saSetupTotpSection" style="<?php echo $sa_current_setup_mode === 'email' ? 'display:none;' : ''; ?>">
                    <div style="display:grid;grid-template-columns:auto 1fr;gap:24px;align-items:start;background:#081726;border:1px solid rgba(148,163,184,0.18);border-radius:12px;padding:22px;">
                        <div style="text-align:center;">
                            <img src="<?php echo htmlspecialchars($sa_tfa_setup['qr_url']); ?>" alt="2FA QR Code" width="160" height="160" style="border-radius:10px;border:1px solid rgba(148,163,184,0.25);background:#fff;padding:6px;display:block;">
                            <span class="sa-muted" style="font-size:11px;display:block;margin-top:6px;">Scan with Authenticator</span>
                        </div>
                        <div>
                            <h4 style="margin:0 0 8px 0;font-size:15px;color:#fff;">Step 1: Scan QR Code or Enter Key</h4>
                            <p class="sa-muted" style="font-size:13px;margin:0 0 12px 0;">
                                Compatible with Google Authenticator, Microsoft Authenticator, Apple Keychain, and 1Password.
                            </p>
                            <div style="margin-bottom:16px;">
                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:#94a3b8;">Secret Key (for manual entry):</label>
                                <div style="display:inline-flex;align-items:center;gap:8px;background:#0d2338;border:1px solid rgba(148,163,184,0.22);padding:6px 14px;border-radius:8px;">
                                    <code style="font-family:monospace;font-size:14px;font-weight:700;color:#c2f542;letter-spacing:1px;" id="saTotpSecretText"><?php echo htmlspecialchars($sa_tfa_setup['formatted']); ?></code>
                                    <button type="button" onclick="saCopySecretKey()" class="sa-btn sa-btn-ghost" style="padding:2px 8px;font-size:11.5px;">Copy</button>
                                </div>
                            </div>

                            <h4 style="margin:16px 0 8px 0;font-size:15px;color:#fff;">Step 2: Enter 6-Digit Code</h4>
                            <form method="POST" action="settings.php">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="sa_tfa_enable_totp">
                                <input type="hidden" name="secret" value="<?php echo htmlspecialchars($sa_tfa_setup['secret']); ?>">
                                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                    <input type="text" name="code" placeholder="123456" maxlength="6" inputmode="numeric" required
                                           style="width:140px;font-family:monospace;font-size:17px;font-weight:700;letter-spacing:3px;text-align:center;padding:9px 12px;border-radius:8px;border:1px solid rgba(148,163,184,0.25);background:#0a1a2a;color:#fff;">
                                    <button type="submit" class="sa-btn sa-btn-primary" style="padding:10px 22px;">
                                        Confirm &amp; Enable 2FA
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Email OTP Setup Section -->
                <div id="saSetupEmailSection" style="<?php echo $sa_current_setup_mode !== 'email' ? 'display:none;' : ''; ?>">
                    <div style="background:#081726;border:1px solid rgba(148,163,184,0.18);border-radius:12px;padding:22px;">
                        <h4 style="margin:0 0 8px 0;font-size:15px;color:#fff;">Email Verification Codes</h4>
                        <p class="sa-muted" style="font-size:13px;margin:0 0 16px 0;">
                            Each time you sign in to the platform control center, a 6-digit one-time code will be dispatched to:
                            <strong style="color:#e2e8f0;"><?php echo htmlspecialchars($sa_user_email ?: 'your email'); ?></strong>.
                        </p>

                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                            <form method="POST" action="settings.php" style="margin:0;">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="sa_tfa_send_email_code">
                                <button type="submit" class="sa-btn sa-btn-ghost" style="padding:9px 18px;">
                                    ✉ Send Verification Code to My Email
                                </button>
                            </form>
                        </div>

                        <form method="POST" action="settings.php" style="padding-top:14px;border-top:1px solid rgba(148,163,184,0.14);">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="sa_tfa_confirm_email_otp">
                            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:6px;color:#cbd5e1;">Enter the 6-digit code received by email:</label>
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <input type="text" name="code" placeholder="123456" maxlength="6" inputmode="numeric" required
                                       style="width:140px;font-family:monospace;font-size:17px;font-weight:700;letter-spacing:3px;text-align:center;padding:9px 12px;border-radius:8px;border:1px solid rgba(148,163,184,0.25);background:#0a1a2a;color:#fff;">
                                <button type="submit" class="sa-btn sa-btn-primary" style="padding:10px 22px;">
                                    Verify &amp; Enable Email 2FA
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Active 2FA Status and Controls -->
            <div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;margin-bottom:20px;">
                    <div style="padding:16px;background:#081726;border:1px solid rgba(148,163,184,0.18);border-radius:10px;">
                        <span class="sa-muted" style="font-size:11px;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;display:block;margin-bottom:4px;">Primary Method</span>
                        <strong style="font-size:15px;color:#eef2f7;">
                            <?php echo $sa_tfa_method === 'email' ? '✉ Email Verification Codes' : '📱 Authenticator App (TOTP)'; ?>
                        </strong>
                        <p class="sa-muted" style="margin:4px 0 0;font-size:12.5px;">
                            <?php echo $sa_tfa_method === 'email' ? 'Delivered to ' . htmlspecialchars(tfa_mask_email($sa_user_email)) : 'Google Authenticator, Microsoft Authenticator, 1Password'; ?>
                        </p>
                    </div>
                    <div style="padding:16px;background:#081726;border:1px solid rgba(148,163,184,0.18);border-radius:10px;">
                        <span class="sa-muted" style="font-size:11px;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;display:block;margin-bottom:4px;">Emergency Recovery Codes</span>
                        <strong style="font-size:15px;color:#eef2f7;"><?php echo (int)$sa_tfa_remaining_codes; ?> of <?php echo (int)$sa_tfa_total_codes; ?> remaining</strong>
                        <p class="sa-muted" style="margin:4px 0 0;font-size:12.5px;">
                            Single-use emergency codes if you lose access to your authenticator app.
                        </p>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding-top:16px;border-top:1px solid rgba(148,163,184,0.14);">
                    <button type="button" class="sa-btn sa-btn-secondary" onclick="saToggleTfaPanel('saRegenCodesPanel')" style="font-size:13px;">
                        🔄 Regenerate Recovery Codes
                    </button>
                    <button type="button" class="sa-btn sa-btn-secondary" onclick="saToggleTfaPanel('saSwitchMethodPanel')" style="font-size:13px;">
                        ⇄ Switch 2FA Method
                    </button>
                    <button type="button" class="sa-btn sa-btn-ghost" onclick="saToggleTfaPanel('saDisableTfaPanel')" style="font-size:13px;color:#f87171;border-color:rgba(239,68,68,0.3);">
                        ✕ Disable 2FA
                    </button>
                </div>

                <!-- Panel: Regenerate Recovery Codes -->
                <div id="saRegenCodesPanel" style="display:none;margin-top:16px;padding:18px;background:#081726;border:1px solid rgba(148,163,184,0.18);border-radius:10px;">
                    <h4 style="margin:0 0 6px 0;font-size:14.5px;color:#fff;">Regenerate Emergency Recovery Codes</h4>
                    <p class="sa-muted" style="font-size:12.5px;margin:0 0 12px 0;">This invalidates all previous codes and issues 8 fresh recovery codes. Enter your current password to confirm:</p>
                    <form method="POST" action="settings.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="sa_tfa_regenerate_backup_codes">
                        <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password"
                               style="padding:8px 12px;border:1px solid rgba(148,163,184,0.25);border-radius:8px;font-size:13px;background:#0a1a2a;color:#fff;width:200px;">
                        <button type="submit" class="sa-btn sa-btn-primary" style="padding:8px 18px;font-size:13px;">Regenerate Codes</button>
                        <button type="button" class="sa-btn sa-btn-ghost" onclick="saToggleTfaPanel('saRegenCodesPanel')" style="padding:8px 14px;font-size:13px;">Cancel</button>
                    </form>
                </div>

                <!-- Panel: Switch Method -->
                <div id="saSwitchMethodPanel" style="display:none;margin-top:16px;padding:18px;background:#081726;border:1px solid rgba(148,163,184,0.18);border-radius:10px;">
                    <h4 style="margin:0 0 6px 0;font-size:14.5px;color:#fff;">Switch 2FA Method</h4>
                    <p class="sa-muted" style="font-size:12.5px;margin:0 0 12px 0;">
                        Currently active: <strong><?php echo $sa_tfa_method === 'email' ? 'Email OTP' : 'Authenticator App (TOTP)'; ?></strong>.
                        Switch to <strong><?php echo $sa_tfa_method === 'email' ? 'Authenticator App (TOTP)' : 'Email OTP'; ?></strong>:
                    </p>
                    <form method="POST" action="settings.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="sa_tfa_switch_method">
                        <input type="hidden" name="new_method" value="<?php echo $sa_tfa_method === 'email' ? 'totp' : 'email'; ?>">
                        <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password"
                               style="padding:8px 12px;border:1px solid rgba(148,163,184,0.25);border-radius:8px;font-size:13px;background:#0a1a2a;color:#fff;width:200px;">
                        <button type="submit" class="sa-btn sa-btn-primary" style="padding:8px 18px;font-size:13px;">
                            Confirm Switch to <?php echo $sa_tfa_method === 'email' ? 'Authenticator App' : 'Email OTP'; ?>
                        </button>
                        <button type="button" class="sa-btn sa-btn-ghost" onclick="saToggleTfaPanel('saSwitchMethodPanel')" style="padding:8px 14px;font-size:13px;">Cancel</button>
                    </form>
                </div>

                <!-- Panel: Disable 2FA -->
                <div id="saDisableTfaPanel" style="display:none;margin-top:16px;padding:18px;background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.25);border-radius:10px;">
                    <h4 style="margin:0 0 6px 0;font-size:14.5px;color:#f87171;">Disable Two-Factor Authentication</h4>
                    <p class="sa-muted" style="font-size:12.5px;margin:0 0 12px 0;">This removes 2FA protection from your super admin account. Enter your current password to confirm:</p>
                    <form method="POST" action="settings.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="sa_tfa_disable">
                        <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password"
                               style="padding:8px 12px;border:1px solid rgba(148,163,184,0.25);border-radius:8px;font-size:13px;background:#0a1a2a;color:#fff;width:200px;">
                        <button type="submit" class="sa-btn" style="padding:8px 18px;font-size:13px;background:#ef4444;color:#fff;border-color:#ef4444;">
                            Permanently Disable 2FA
                        </button>
                        <button type="button" class="sa-btn sa-btn-ghost" onclick="saToggleTfaPanel('saDisableTfaPanel')" style="padding:8px 14px;font-size:13px;">Cancel</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
function saShowTfaSetup(mode) {
    var totpSec = document.getElementById('saSetupTotpSection');
    var emailSec = document.getElementById('saSetupEmailSection');
    var btnTotp = document.getElementById('saBtnSetupTotp');
    var btnEmail = document.getElementById('saBtnSetupEmail');

    if (mode === 'email') {
        if (totpSec) totpSec.style.display = 'none';
        if (emailSec) emailSec.style.display = 'block';
        if (btnTotp) { btnTotp.classList.remove('sa-btn-primary'); btnTotp.classList.add('sa-btn-ghost'); }
        if (btnEmail) { btnEmail.classList.remove('sa-btn-ghost'); btnEmail.classList.add('sa-btn-primary'); }
    } else {
        if (totpSec) totpSec.style.display = 'block';
        if (emailSec) emailSec.style.display = 'none';
        if (btnTotp) { btnTotp.classList.remove('sa-btn-ghost'); btnTotp.classList.add('sa-btn-primary'); }
        if (btnEmail) { btnEmail.classList.remove('sa-btn-primary'); btnEmail.classList.add('sa-btn-ghost'); }
    }
}

function saToggleTfaPanel(panelId) {
    var p = document.getElementById(panelId);
    if (p) {
        p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'block' : 'none';
    }
}

function saCopySecretKey() {
    var code = document.getElementById('saTotpSecretText');
    if (code) {
        var raw = code.textContent.replace(/\s+/g, '');
        navigator.clipboard.writeText(raw).then(function() {
            alert('Secret key copied to clipboard: ' + raw);
        }).catch(function() {
            prompt('Copy secret key:', raw);
        });
    }
}

function saCopyBackupCodes() {
    var rawEl = document.getElementById('saRawBackupCodes');
    if (rawEl) {
        navigator.clipboard.writeText(rawEl.value).then(function() {
            var btn = document.getElementById('saCopyBackupCodesBtn');
            if (btn) btn.textContent = '✓ Copied!';
            setTimeout(function(){ if (btn) btn.textContent = '📋 Copy All Codes'; }, 2500);
        }).catch(function() {
            prompt('Copy your backup recovery codes:', rawEl.value);
        });
    }
}

function saDownloadBackupCodes() {
    var rawEl = document.getElementById('saRawBackupCodes');
    if (!rawEl) return;
    var text = "OPTIBIZ CONTROL CENTER - SUPER ADMIN 2FA BACKUP CODES\n"
             + "Generated: " + new Date().toISOString() + "\n"
             + "Keep these codes in a safe, offline location. Each code can be used once.\n\n"
             + rawEl.value + "\n";
    var blob = new Blob([text], {type: 'text/plain'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'optibiz-superadmin-2fa-backup-codes.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>
