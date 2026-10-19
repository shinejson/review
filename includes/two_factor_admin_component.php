<?php
/**
 * Optibiz - Two-Factor Authentication Management Card for Tenant Admin
 * Included inside Tab 3 (Security & Password) in admin/settings.php
 */

$tfa_contact = tfa_get_user_contact($conn, $tfa_user_type, $tfa_user_id);
$tfa_user_email = $tfa_contact['email'] ?? ($tfa_user_email ?? '');
$tfa_user_phone = $tfa_contact['phone'] ?? '';
$tfa_user_label = $tfa_contact['name'] ?? ($tfa_user_label ?? 'User');

$tfa_status = tfa_get_status($conn, $tfa_user_type, $tfa_user_id);
$tfa_enabled = $tfa_status['is_enabled'];
$tfa_method = $tfa_status['method'];
$tfa_remaining_codes = $tfa_status['remaining_backup_codes'];
$tfa_total_codes = $tfa_status['total_backup_codes'] ?? 8;
$tfa_new_codes = $_SESSION['tfa_just_enabled_backup_codes'] ?? null;
unset($_SESSION['tfa_just_enabled_backup_codes']);

// Prepare fresh setup secret if not already active
$tfa_setup = tfa_prepare_totp_setup($tfa_user_label, 'Optibiz');
$current_setup_mode = in_array($_GET['setup'] ?? '', ['totp', 'whatsapp', 'email'], true) ? $_GET['setup'] : 'totp';
?>

<!-- Two-Factor Authentication Card -->
<div class="form-card" style="margin-top:24px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;padding-bottom:16px;border-bottom:1px solid var(--line);">
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;background:rgba(99,102,241,0.12);color:#6366f1;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="11" r="3"/></svg>
                </span>
                <h3 style="margin:0;font-size:18px;">Two-Factor Authentication (2FA)</h3>
            </div>
            <p class="muted" style="margin:0;font-size:13.5px;">Add an extra layer of defense against credential theft and unauthorized access.</p>
        </div>
        <div>
            <?php if ($tfa_enabled): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;background:rgba(16,185,129,0.12);color:#10b981;padding:6px 14px;border-radius:99px;font-size:12.5px;font-weight:700;border:1px solid rgba(16,185,129,0.3);">
                    <span style="font-size:9px;">●</span> Active &middot; <?php 
                        if ($tfa_method === 'whatsapp') {
                            echo 'WhatsApp 2FA';
                        } elseif ($tfa_method === 'email') {
                            echo 'Email OTP';
                        } else {
                            echo 'Authenticator App (TOTP)';
                        }
                    ?>
                </span>
            <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:6px;background:rgba(239,68,68,0.08);color:#ef4444;padding:6px 14px;border-radius:99px;font-size:12.5px;font-weight:700;border:1px solid rgba(239,68,68,0.25);">
                    <span style="font-size:9px;">○</span> Disabled
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($tfa_new_codes)): ?>
        <!-- Prominent Backup Codes Display Banner -->
        <div style="margin:20px 0;padding:20px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:12px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;color:#d97706;font-weight:700;font-size:15px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Save Your Emergency Backup Recovery Codes Now
            </div>
            <p style="margin:0 0 14px;font-size:13px;color:var(--ink);line-height:1.5;">
                If you lose access to your authenticator app, phone, or email, these codes are the <strong>only way</strong> to regain access to your account. Each code can only be used once.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;margin-bottom:16px;">
                <?php foreach ($tfa_new_codes as $code): ?>
                    <div style="background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-family:monospace;font-size:14px;font-weight:700;text-align:center;letter-spacing:1px;color:var(--ink);">
                        <?php echo htmlspecialchars($code); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary" onclick="tfaCopyBackupCodes()" id="copyBackupCodesBtn" style="font-size:12.5px;padding:8px 16px;">
                    📋 Copy All Codes
                </button>
                <button type="button" class="btn btn-secondary" onclick="tfaDownloadBackupCodes()" style="font-size:12.5px;padding:8px 16px;">
                    📥 Download (.txt)
                </button>
            </div>
        </div>
        <textarea id="rawBackupCodes" style="display:none;"><?php echo htmlspecialchars(implode("\n", $tfa_new_codes)); ?></textarea>
    <?php endif; ?>

    <?php if (!$tfa_enabled): ?>
        <!-- Setup Flow -->
        <div style="margin-top:20px;">
            <p style="font-size:13.5px;color:var(--muted);margin-bottom:16px;">
                Select your preferred two-factor authentication method:
            </p>

            <!-- Method Selector Tabs -->
            <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
                <button type="button" class="btn <?php echo ($current_setup_mode === 'totp') ? 'btn-primary' : 'btn-secondary'; ?>" onclick="tfaShowSetup('totp')" id="btnSetupTotp" style="padding:10px 18px;font-size:13px;">
                    📱 Authenticator App (Recommended)
                </button>
                <button type="button" class="btn <?php echo ($current_setup_mode === 'whatsapp') ? 'btn-primary' : 'btn-secondary'; ?>" onclick="tfaShowSetup('whatsapp')" id="btnSetupWhatsapp" style="padding:10px 18px;font-size:13px;">
                    💬 WhatsApp 2FA
                </button>
                <button type="button" class="btn <?php echo ($current_setup_mode === 'email') ? 'btn-primary' : 'btn-secondary'; ?>" onclick="tfaShowSetup('email')" id="btnSetupEmail" style="padding:10px 18px;font-size:13px;">
                    ✉ Email Verification Codes
                </button>
            </div>

            <!-- TOTP Setup Section -->
            <div id="setupTotpSection" style="<?php echo ($current_setup_mode !== 'totp') ? 'display:none;' : ''; ?>">
                <div style="display:grid;grid-template-columns:auto 1fr;gap:24px;align-items:start;background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:20px;">
                    <div style="text-align:center;">
                        <img src="<?php echo htmlspecialchars($tfa_setup['qr_url']); ?>" alt="2FA QR Code" width="160" height="160" style="border-radius:10px;border:1px solid var(--line);background:#fff;padding:6px;display:block;">
                        <span class="muted" style="font-size:11px;display:block;margin-top:6px;">Scan with Authenticator</span>
                    </div>
                    <div>
                        <h4 style="margin:0 0 8px 0;font-size:15px;">Step 1: Scan QR Code or Enter Key</h4>
                        <p class="muted" style="font-size:13px;margin:0 0 12px 0;">
                            Scan the QR code with Google Authenticator, Microsoft Authenticator, 1Password, or Apple Passwords.
                        </p>
                        <div style="margin-bottom:16px;">
                            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;color:var(--muted);">Secret Key (for manual entry):</label>
                            <div style="display:inline-flex;align-items:center;gap:8px;background:var(--card-bg, #fff);border:1px solid var(--line);padding:6px 12px;border-radius:8px;">
                                <code style="font-family:monospace;font-size:14px;font-weight:700;color:var(--ink);letter-spacing:1px;" id="totpSecretText"><?php echo htmlspecialchars($tfa_setup['formatted']); ?></code>
                                <button type="button" onclick="tfaCopySecretKey()" class="btn btn-secondary" style="padding:3px 10px;font-size:11.5px;">Copy</button>
                            </div>
                        </div>

                        <h4 style="margin:16px 0 8px 0;font-size:15px;">Step 2: Enter 6-Digit Code</h4>
                        <form method="POST" action="settings.php">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="tfa_enable_totp">
                            <input type="hidden" name="secret" value="<?php echo htmlspecialchars($tfa_setup['secret']); ?>">
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <input type="text" name="code" placeholder="123456" maxlength="6" inputmode="numeric" required
                                       style="width:140px;font-family:monospace;font-size:17px;font-weight:700;letter-spacing:3px;text-align:center;padding:9px 12px;border-radius:8px;border:1px solid var(--line);">
                                <button type="submit" class="btn btn-primary" style="padding:10px 22px;">
                                    Confirm &amp; Enable 2FA
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- WhatsApp Setup Section -->
            <div id="setupWhatsappSection" style="<?php echo ($current_setup_mode !== 'whatsapp') ? 'display:none;' : ''; ?>">
                <div style="background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:20px;">
                    <h4 style="margin:0 0 8px 0;font-size:15px;">💬 WhatsApp Two-Factor Authentication</h4>
                    <p class="muted" style="font-size:13px;margin:0 0 16px 0;">
                        Receive a secure 6-digit one-time passcode on WhatsApp every time you log in to your account.
                    </p>

                    <!-- Send Code Form -->
                    <form method="POST" action="settings.php" style="margin-bottom:18px;">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="tfa_send_whatsapp_code">
                        <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:6px;">Your WhatsApp Phone Number (with country code):</label>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <input type="tel" name="phone_number" value="<?php echo htmlspecialchars($tfa_user_phone); ?>" placeholder="+233 24 123 4567" required
                                   style="width:240px;padding:9px 12px;border-radius:8px;border:1px solid var(--line);font-size:14px;">
                            <button type="submit" class="btn btn-secondary" style="padding:9px 18px;color:#25d366;border-color:rgba(37,211,102,0.4);">
                                💬 Send Code via WhatsApp
                            </button>
                        </div>
                        <span class="muted" style="font-size:11.5px;display:block;margin-top:4px;">Include your country dial code (e.g. +233, +1, +44, +234).</span>
                    </form>

                    <?php if (!empty($_SESSION['last_whatsapp_otp'])): ?>
                        <div style="margin:12px 0 18px;padding:10px 14px;background:rgba(37,211,102,0.08);border:1px solid rgba(37,211,102,0.3);border-radius:8px;font-size:12.5px;color:#15803d;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                            <span><strong>WhatsApp Simulation:</strong> Latest dispatched code is <code style="font-weight:700;letter-spacing:1px;"><?php echo htmlspecialchars($_SESSION['last_whatsapp_otp']); ?></code></span>
                            <a href="https://wa.me/<?php echo preg_replace('/\D+/', '', $_SESSION['last_whatsapp_phone'] ?? ''); ?>?text=<?php echo urlencode('Your Optibiz 2FA code is ' . $_SESSION['last_whatsapp_otp']); ?>" target="_blank" class="btn btn-secondary" style="padding:2px 8px;font-size:11px;">Open wa.me Link &rarr;</a>
                        </div>
                    <?php endif; ?>

                    <!-- Confirm OTP Form -->
                    <form method="POST" action="settings.php" style="padding-top:14px;border-top:1px solid var(--line);">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="tfa_confirm_whatsapp_otp">
                        <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:6px;">Enter the 6-digit code received on WhatsApp:</label>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <input type="text" name="code" placeholder="123456" maxlength="6" inputmode="numeric" required
                                   style="width:140px;font-family:monospace;font-size:17px;font-weight:700;letter-spacing:3px;text-align:center;padding:9px 12px;border-radius:8px;border:1px solid var(--line);">
                            <button type="submit" class="btn btn-primary" style="padding:10px 22px;">
                                Verify &amp; Enable WhatsApp 2FA
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Email OTP Setup Section -->
            <div id="setupEmailSection" style="<?php echo ($current_setup_mode !== 'email') ? 'display:none;' : ''; ?>">
                <div style="background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:20px;">
                    <h4 style="margin:0 0 8px 0;font-size:15px;">Email Verification Codes</h4>
                    <p class="muted" style="font-size:13px;margin:0 0 16px 0;">
                        Each time you sign in, a 6-digit one-time code will be sent to your verified email:
                        <strong><?php echo htmlspecialchars($tfa_user_email ?: 'your email'); ?></strong>.
                    </p>

                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                        <form method="POST" action="settings.php" style="margin:0;">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="action" value="tfa_send_email_code">
                            <input type="hidden" name="email" value="<?php echo htmlspecialchars($tfa_user_email); ?>">
                            <button type="submit" class="btn btn-secondary" style="padding:9px 18px;">
                                ✉ Send Verification Code to My Email
                            </button>
                        </form>
                    </div>

                    <form method="POST" action="settings.php" style="padding-top:14px;border-top:1px solid var(--line);">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="tfa_confirm_email_otp">
                        <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:6px;">Enter the 6-digit code received by email:</label>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <input type="text" name="code" placeholder="123456" maxlength="6" inputmode="numeric" required
                                   style="width:140px;font-family:monospace;font-size:17px;font-weight:700;letter-spacing:3px;text-align:center;padding:9px 12px;border-radius:8px;border:1px solid var(--line);">
                            <button type="submit" class="btn btn-primary" style="padding:10px 22px;">
                                Verify &amp; Enable Email 2FA
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- 2FA Active Status & Management Controls -->
        <div style="margin-top:20px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;margin-bottom:20px;">
                <div style="padding:16px;background:var(--bg);border:1px solid var(--line);border-radius:10px;">
                    <span class="muted" style="font-size:12px;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;display:block;margin-bottom:4px;">Primary Method</span>
                    <strong style="font-size:15px;color:var(--ink);">
                        <?php 
                            if ($tfa_method === 'whatsapp') {
                                echo '💬 WhatsApp 2FA';
                            } elseif ($tfa_method === 'email') {
                                echo '✉ Email Verification Codes';
                            } else {
                                echo '📱 Authenticator App (TOTP)';
                            }
                        ?>
                    </strong>
                    <p class="muted" style="margin:4px 0 0;font-size:12.5px;">
                        <?php 
                            if ($tfa_method === 'whatsapp') {
                                echo 'Codes delivered to ' . htmlspecialchars(tfa_mask_phone($tfa_user_phone));
                            } elseif ($tfa_method === 'email') {
                                echo 'Codes delivered to ' . htmlspecialchars(tfa_mask_email($tfa_user_email));
                            } else {
                                echo 'Google Authenticator, Microsoft Authenticator, 1Password';
                            }
                        ?>
                    </p>
                </div>
                <div style="padding:16px;background:var(--bg);border:1px solid var(--line);border-radius:10px;">
                    <span class="muted" style="font-size:12px;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;display:block;margin-bottom:4px;">Emergency Recovery Codes</span>
                    <strong style="font-size:15px;color:var(--ink);"><?php echo (int)$tfa_remaining_codes; ?> of <?php echo (int)$tfa_total_codes; ?> remaining</strong>
                    <p class="muted" style="margin:4px 0 0;font-size:12.5px;">
                        Single-use emergency codes for sign-in if you lose your phone.
                    </p>
                </div>
            </div>

            <!-- Management Actions -->
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding-top:16px;border-top:1px solid var(--line);">
                <button type="button" class="btn btn-secondary" onclick="tfaTogglePanel('regenCodesPanel')" style="padding:9px 18px;font-size:13px;">
                    🔄 Regenerate Recovery Codes
                </button>
                <button type="button" class="btn btn-secondary" onclick="tfaTogglePanel('switchMethodPanel')" style="padding:9px 18px;font-size:13px;">
                    ⇄ Switch 2FA Method
                </button>
                <button type="button" class="btn btn-secondary" onclick="tfaTogglePanel('disableTfaPanel')" style="padding:9px 18px;font-size:13px;color:#ef4444;border-color:rgba(239,68,68,0.3);">
                    ✕ Disable 2FA
                </button>
            </div>

            <!-- Panel: Regenerate Recovery Codes -->
            <div id="regenCodesPanel" style="display:none;margin-top:16px;padding:18px;background:var(--bg);border:1px solid var(--line);border-radius:10px;">
                <h4 style="margin:0 0 6px 0;font-size:14.5px;">Regenerate Emergency Recovery Codes</h4>
                <p class="muted" style="font-size:12.5px;margin:0 0 12px 0;">This will invalidate all current backup codes and generate 8 new single-use codes. Enter your current password to confirm:</p>
                <form method="POST" action="settings.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="action" value="tfa_regenerate_backup_codes">
                    <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password"
                           style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;width:200px;">
                    <button type="submit" class="btn btn-primary" style="padding:8px 18px;font-size:13px;">Regenerate Codes</button>
                    <button type="button" class="btn btn-secondary" onclick="tfaTogglePanel('regenCodesPanel')" style="padding:8px 14px;font-size:13px;">Cancel</button>
                </form>
            </div>

            <!-- Panel: Switch Method -->
            <div id="switchMethodPanel" style="display:none;margin-top:16px;padding:18px;background:var(--bg);border:1px solid var(--line);border-radius:10px;">
                <h4 style="margin:0 0 6px 0;font-size:14.5px;">Switch 2FA Method</h4>
                <p class="muted" style="font-size:12.5px;margin:0 0 12px 0;">
                    Currently active: <strong><?php 
                        if ($tfa_method === 'whatsapp') {
                            echo 'WhatsApp 2FA';
                        } elseif ($tfa_method === 'email') {
                            echo 'Email OTP';
                        } else {
                            echo 'Authenticator App (TOTP)';
                        }
                    ?></strong>. Choose your new primary method:
                </p>
                <form method="POST" action="settings.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="action" value="tfa_switch_method">
                    <select name="new_method" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--card-bg, #fff);">
                        <?php if ($tfa_method !== 'totp'): ?>
                            <option value="totp">📱 Authenticator App (TOTP)</option>
                        <?php endif; ?>
                        <?php if ($tfa_method !== 'whatsapp'): ?>
                            <option value="whatsapp">💬 WhatsApp 2FA</option>
                        <?php endif; ?>
                        <?php if ($tfa_method !== 'email'): ?>
                            <option value="email">✉ Email Verification Codes</option>
                        <?php endif; ?>
                    </select>
                    <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password"
                           style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;width:200px;">
                    <button type="submit" class="btn btn-primary" style="padding:8px 18px;font-size:13px;">
                        Confirm Switch
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="tfaTogglePanel('switchMethodPanel')" style="padding:8px 14px;font-size:13px;">Cancel</button>
                </form>
            </div>

            <!-- Panel: Disable 2FA -->
            <div id="disableTfaPanel" style="display:none;margin-top:16px;padding:18px;background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.25);border-radius:10px;">
                <h4 style="margin:0 0 6px 0;font-size:14.5px;color:#ef4444;">Disable Two-Factor Authentication</h4>
                <p class="muted" style="font-size:12.5px;margin:0 0 12px 0;">This removes the additional security verification when signing into your workspace. Enter your current password to confirm:</p>
                <form method="POST" action="settings.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="action" value="tfa_disable">
                    <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password"
                           style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;width:200px;">
                    <button type="submit" class="btn btn-secondary" style="padding:8px 18px;font-size:13px;background:#ef4444;color:#fff;border-color:#ef4444;">
                        Permanently Disable 2FA
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="tfaTogglePanel('disableTfaPanel')" style="padding:8px 14px;font-size:13px;">Cancel</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function tfaShowSetup(mode) {
    var totpSec  = document.getElementById('setupTotpSection');
    var waSec    = document.getElementById('setupWhatsappSection');
    var emailSec = document.getElementById('setupEmailSection');
    var btnTotp  = document.getElementById('btnSetupTotp');
    var btnWa    = document.getElementById('btnSetupWhatsapp');
    var btnEmail = document.getElementById('btnSetupEmail');

    if (totpSec)  totpSec.style.display  = (mode === 'totp') ? 'block' : 'none';
    if (waSec)    waSec.style.display    = (mode === 'whatsapp') ? 'block' : 'none';
    if (emailSec) emailSec.style.display = (mode === 'email') ? 'block' : 'none';

    if (btnTotp)  btnTotp.className  = (mode === 'totp') ? 'btn btn-primary' : 'btn btn-secondary';
    if (btnWa)    btnWa.className    = (mode === 'whatsapp') ? 'btn btn-primary' : 'btn btn-secondary';
    if (btnEmail) btnEmail.className = (mode === 'email') ? 'btn btn-primary' : 'btn btn-secondary';
}

function tfaTogglePanel(panelId) {
    var p = document.getElementById(panelId);
    if (p) {
        p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'block' : 'none';
    }
}

function tfaCopySecretKey() {
    var code = document.getElementById('totpSecretText');
    if (code) {
        var raw = code.textContent.replace(/\s+/g, '');
        navigator.clipboard.writeText(raw).then(function() {
            alert('Secret key copied to clipboard: ' + raw);
        }).catch(function() {
            prompt('Copy secret key:', raw);
        });
    }
}

function tfaCopyBackupCodes() {
    var rawEl = document.getElementById('rawBackupCodes');
    if (rawEl) {
        navigator.clipboard.writeText(rawEl.value).then(function() {
            var btn = document.getElementById('copyBackupCodesBtn');
            if (btn) btn.textContent = '✓ Copied!';
            setTimeout(function(){ if (btn) btn.textContent = '📋 Copy All Codes'; }, 2500);
        }).catch(function() {
            prompt('Copy your backup recovery codes:', rawEl.value);
        });
    }
}

function tfaDownloadBackupCodes() {
    var rawEl = document.getElementById('rawBackupCodes');
    if (!rawEl) return;
    var text = "OPTIBIZ WORKSPACE - EMERGENCY 2FA BACKUP CODES\n"
             + "Generated: " + new Date().toISOString() + "\n"
             + "Keep these codes in a safe, offline location. Each code can be used once.\n\n"
             + rawEl.value + "\n";
    var blob = new Blob([text], {type: 'text/plain'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'optibiz-2fa-backup-codes.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>
