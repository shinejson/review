<?php
/**
 * ============================================================
 *  Super Admin — Platform settings
 * ============================================================
 *  Branding, defaults, the super admin account and a small
 *  health panel that shows what the database actually contains.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();
require_sa_permission('settings');

/* Which settings this screen is allowed to write */
$sa_setting_fields = [
    'site_name'        => ['label' => 'Platform name', 'type' => 'text', 'hint' => 'Shown in emails, the header and the footer.'],
    'admin_email'      => ['label' => 'Admin email', 'type' => 'email', 'hint' => 'Where system notifications are sent.'],
    'support_email'    => ['label' => 'Support email', 'type' => 'email', 'hint' => 'Published to tenants as the support contact.'],
    'currency_symbol'  => ['label' => 'Currency symbol', 'type' => 'text', 'hint' => 'Used everywhere money is shown ($, €, £, GH₵…).'],
    'ratings_per_page' => ['label' => 'Ratings per page', 'type' => 'number', 'hint' => 'Pagination size on the public and tenant views.'],
    'trial_days'       => ['label' => 'Default trial length (days)', 'type' => 'number', 'hint' => 'Suggested length when creating a tenant.'],
];

/* ---------- POST handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('settings.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    /* Handle images encoded as data-URIs by the branded upload UI */
    if (!function_exists('sa_store_brand_upload')) {
        function sa_store_brand_upload($raw, $kind, &$err = '')
        {
            $err = '';
            if (!is_string($raw) || $raw === '') return '';

            if (strpos($raw, 'data:') === 0) {
                if (!preg_match('~^data:([a-z]+/[a-z0-9.+-]+);base64,([A-Za-z0-9+/=]+)$~i', $raw, $m)) {
                    $err = 'The uploaded image data was not recognised.';
                    return '';
                }
                $mime = strtolower($m[1]);
                $data = base64_decode($m[2], true);
                if ($data === false || $data === '') {
                    $err = 'The uploaded image was empty or corrupted.';
                    return '';
                }

                $ext = [
                    'image/png'  => 'png',
                    'image/jpeg' => 'jpg',
                    'image/jpg'  => 'jpg',
                    'image/webp' => 'webp',
                    'image/svg+xml' => 'svg',
                    'image/x-icon'  => 'ico',
                    'image/vnd.microsoft.icon' => 'ico',
                ];
                $extension = isset($ext[$mime]) ? $ext[$mime] : '';
                if ($extension === '') {
                    $err = 'Unsupported image type. Use PNG, JPG, SVG, ICO or WebP.';
                    return '';
                }

                $limits = sa_upload_limits();
                $allowed = $limits[$kind]['ext'];
                $max = $limits[$kind]['max'];
                if (!in_array($extension, $allowed, true)) {
                    $err = 'A ' . $kind . ' must be ' . implode(', ', $allowed) . '.';
                    return '';
                }
                if (strlen($data) > $max) {
                    $err = 'The ' . $kind . ' is too large (max ' . $limits[$kind]['maxLabel'] . ').';
                    return '';
                }

                // SVG guard: strip executable bits and scripts
                if ($extension === 'svg') {
                    $svgCheck = strtolower(substr($data, 0, 4096));
                    if (preg_match('~<(script|foreignobject)|onload=|javascript:~i', $svgCheck)) {
                        $err = 'The SVG contains disallowed content.';
                        return '';
                    }
                }

                $fname = $kind . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
                if (file_put_contents(sa_uploads_dir() . DIRECTORY_SEPARATOR . $fname, $data) === false) {
                    $err = 'Could not write the uploaded file to disk.';
                    return '';
                }
                return $fname;
            }

            $err = 'Invalid upload payload.';
            return '';
        }
    }

    if ($action === 'save_brand_assets') {
        $saved = 0;
        $skipped = [];

        foreach (['logo_asset', 'favicon_asset'] as $key) {
            $kind = $key === 'logo_asset' ? 'logo' : 'favicon';
            $posted = isset($_POST[$key]) ? (string) $_POST[$key] : '';

            if ($posted === '') {
                // Clearing the value removes the file reference.
                $value = '';
            } elseif (preg_match('~^https?://~i', $posted)) {
                $value = trim($posted);
            } else {
                $value = sa_store_brand_upload($posted, $kind, $errText);
                if ($errText !== '') {
                    $skipped[] = ucfirst($kind) . ': ' . $errText;
                    continue;
                }
            }

            $exists = (int) sa_scalar($conn, "SELECT COUNT(*) FROM settings WHERE setting_key = '"
                . $conn->real_escape_string($key) . "'", 0, 'settings');
            if ($exists > 0) {
                $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->bind_param("ss", $value, $key);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->bind_param("ss", $key, $value);
                $stmt->execute();
                $stmt->close();
            }
            $saved++;
        }

        // Remove the previous brand file when the asset was replaced or removed.
        if (!$skipped) {
            foreach (['logo_asset' => 'logo', 'favicon_asset' => 'favicon'] as $key => $kind) {
                $newVal = sa_setting($conn, $key, '');
                $oldVal = $prev_brand[$key] ?? '';
                if ($oldVal === '' || $oldVal === $newVal) continue;
                if (preg_match('~^https?://~i', $oldVal)) continue;

                $oldName = basename($oldVal);
                $oldAbs  = sa_uploads_dir() . DIRECTORY_SEPARATOR . $oldName;
                if ($oldName !== '' && strpos($oldName, $kind . '-') === 0 && is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
            }
        }

        sa_flash($skipped ? 'warning' : 'success', $skipped
            ? 'Saved, but: ' . implode(' ', $skipped)
            : 'Branding assets saved.');
        redirect('settings.php');
    }

    if ($action === 'save_settings') {
        $saved = 0;
        $skipped = [];
        foreach ($sa_setting_fields as $key => $meta) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $value = trim((string) $_POST[$key]);
            if ($meta['type'] === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = $meta['label'] . ' (invalid email)';
                continue;
            }
            if ($meta['type'] === 'number') {
                $value = (string) max(0, (int) $value);
            }

            $exists = (int) sa_scalar(
                $conn,
                "SELECT COUNT(*) FROM settings WHERE setting_key = '" . $conn->real_escape_string($key) . "'",
                0,
                'settings'
            );
            if ($exists > 0) {
                $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->bind_param("ss", $value, $key);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->bind_param("ss", $key, $value);
                $stmt->execute();
                $stmt->close();
            }
            $saved++;
        }
        sa_flash($skipped ? 'warning' : 'success', $saved . ' setting' . ($saved === 1 ? '' : 's') . ' saved.'
            . ($skipped ? ' Skipped: ' . implode(', ', $skipped) . '.' : ''));
        redirect('settings.php');
    }

    if ($action === 'save_email_settings') {
        $driver          = in_array($_POST['mail_driver'] ?? 'smtp', ['smtp', 'mail'], true) ? $_POST['mail_driver'] : 'smtp';
        $from_name       = trim(sanitize($_POST['mail_from_name'] ?? 'Optibiz'));
        $from_email      = trim(sanitize($_POST['mail_from_email'] ?? ''));
        $smtp_host       = trim(sanitize($_POST['smtp_host'] ?? ''));
        $smtp_port       = max(1, min(65535, (int) ($_POST['smtp_port'] ?? 587)));
        $smtp_encryption = in_array($_POST['smtp_encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_encryption'] : 'tls';
        $smtp_username   = trim($_POST['smtp_username'] ?? '');
        $smtp_password   = (string) ($_POST['smtp_password'] ?? '');

        if ($from_email !== '' && !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
            sa_flash('error', 'From Email must be a valid email address.');
            redirect('settings.php');
        }

        $email_settings = [
            'mail_driver'     => $driver,
            'mail_from_name'  => $from_name,
            'mail_from_email' => $from_email,
            'smtp_host'       => $smtp_host,
            'smtp_port'       => (string) $smtp_port,
            'smtp_encryption' => $smtp_encryption,
            'smtp_username'   => $smtp_username,
        ];

        // Only update password if a new one is typed
        if ($smtp_password !== '') {
            $email_settings['smtp_password'] = $smtp_password;
        }

        foreach ($email_settings as $key => $val) {
            $exists = (int) sa_scalar($conn, "SELECT COUNT(*) FROM settings WHERE setting_key = '" . $conn->real_escape_string($key) . "'", 0, 'settings');
            if ($exists > 0) {
                $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->bind_param("ss", $val, $key);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->bind_param("ss", $key, $val);
                $stmt->execute();
                $stmt->close();
            }
        }

        sa_flash('success', 'Email & SMTP configuration saved successfully.');
        redirect('settings.php');
    }

    if ($action === 'send_test_email') {
        $recipient = trim(sanitize($_POST['test_recipient'] ?? ''));
        if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            sa_flash('error', 'Please provide a valid recipient email address to send the test.');
            redirect('settings.php');
        }

        $site_name = sa_setting($conn, 'site_name', 'Optibiz');
        $subject = 'Test message from ' . $site_name . ' Control Center';
        $body = '<p>Hello,</p>'
              . '<p>This is a <strong>live test message</strong> sent from your <strong>' . htmlspecialchars($site_name) . '</strong> platform control center.</p>'
              . '<p>If you are reading this email, your outgoing email delivery and SMTP configuration are active and working properly!</p>'
              . '<div style="margin:20px 0;padding:16px;background:rgba(255,255,255,0.04);border:1px solid rgba(148,163,184,0.2);border-radius:10px;">'
              . '<table role="presentation" cellpadding="4" cellspacing="0" border="0" style="width:100%;font-size:13px;color:#cbd5e1;">'
              . '<tr><td style="width:130px;color:#94a3b8;font-weight:600;">Sent at:</td><td>' . date('Y-m-d H:i:s T') . '</td></tr>'
              . '<tr><td style="color:#94a3b8;font-weight:600;">Sending Driver:</td><td>' . strtoupper(sa_setting($conn, 'mail_driver', 'smtp')) . '</td></tr>'
              . '<tr><td style="color:#94a3b8;font-weight:600;">SMTP Host:</td><td>' . htmlspecialchars(sa_setting($conn, 'smtp_host', 'localhost')) . ':' . sa_setting($conn, 'smtp_port', '587') . '</td></tr>'
              . '<tr><td style="color:#94a3b8;font-weight:600;">Encryption:</td><td>' . strtoupper(sa_setting($conn, 'smtp_encryption', 'tls')) . '</td></tr>'
              . '<tr><td style="color:#94a3b8;font-weight:600;">From:</td><td>' . htmlspecialchars(sa_setting($conn, 'mail_from_name', $site_name) . ' <' . sa_setting($conn, 'mail_from_email', 'noreply@localhost') . '>') . '</td></tr>'
              . '</table>'
              . '</div>'
              . '<p style="color:#94a3b8;font-size:13px;">You can now securely send account invitations, password resets, and automated notification alerts to your tenants.</p>';

        $fullHtml = sa_render_email_template('Email Connection Test Successful', $body, $site_name);
        $res = sa_send_mail($recipient, $subject, $fullHtml, $conn);

        if ($res['success']) {
            sa_flash('success', 'Test email dispatched successfully to ' . $recipient . '! Check your inbox.');
        } else {
            sa_flash('error', 'Failed to send test email: ' . $res['message']);
        }
        redirect('settings.php');
    }

    if ($action === 'save_admin') {
        $username = sanitize($_POST['username'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $id = (int) ($_SESSION['super_admin_id'] ?? 0);
        if ($username === '' || !$id) {
            sa_flash('error', 'A username is required.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sa_flash('error', 'That email address does not look valid.');
        } else {
            $stmt = $conn->prepare("UPDATE super_admins SET username = ?, email = ? WHERE id = ?");
            $stmt->bind_param("ssi", $username, $email, $id);
            $stmt->execute();
            sa_flash($stmt->error ? 'error' : 'success', $stmt->error ? 'Could not save: ' . $stmt->error : 'Account details updated.');
            $stmt->close();
        }
        redirect('settings.php');
    }

    if ($action === 'change_password') {
        $id = (int) ($_SESSION['super_admin_id'] ?? 0);
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $hash = (string) sa_scalar($conn, "SELECT password FROM super_admins WHERE id = " . $id, '', 'super_admins');
        if (!$id || $hash === '' || !password_verify($current, $hash)) {
            sa_flash('error', 'Your current password is not correct.');
        } elseif (strlen($new) < 8) {
            sa_flash('error', 'The new password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            sa_flash('error', 'The two new passwords do not match.');
        } else {
            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE super_admins SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $new_hash, $id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Password changed. Use it the next time you sign in.');
        }
        redirect('settings.php');
    }
}

/* ---------- data ---------- */
$settings = [];
foreach (sa_query($conn, "SELECT setting_key, setting_value FROM settings", 'settings') as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$prev_brand = [
    'logo_asset'    => isset($settings['logo_asset']) ? $settings['logo_asset'] : '',
    'favicon_asset' => isset($settings['favicon_asset']) ? $settings['favicon_asset'] : '',
];
$logo_stored   = isset($settings['logo_asset']) ? $settings['logo_asset'] : '';
$favicon_stored = isset($settings['favicon_asset']) ? $settings['favicon_asset'] : '';
$logo_url      = sa_platform_logo($conn);
$favicon_url   = sa_platform_favicon($conn);
$admin = sa_one($conn, "SELECT id, username, email, created_at FROM super_admins WHERE id = " . (int) ($_SESSION['super_admin_id'] ?? 0), 'super_admins');
$mail_cfg = sa_get_mail_config($conn);

$tables = ['super_admins', 'subscription_plans', 'tenants', 'admins', 'categories', 'customers', 'ratings', 'settings', 'quote_requests'];
$health = [];
foreach ($tables as $t) {
    $exists = sa_table_exists($conn, $t);
    $health[] = [
        'table' => $t,
        'exists' => $exists,
        'rows' => $exists ? (int) sa_scalar($conn, "SELECT COUNT(*) FROM `" . $t . "`", 0) : 0,
    ];
}
$missing = [];
foreach ($health as $h) {
    if (!$h['exists']) {
        $missing[] = $h['table'];
    }
}

/* ---------- page meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = 'Settings';
$pageHeading = 'Platform settings';
$pageSubtitle = 'Branding, defaults, your account and database health.';
$activePage = 'settings';
$BASE = '../';
$extraCss = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Settings</span>
        </div>
        <h2>Settings</h2>
        <p>Changes apply to the whole platform immediately.</p>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<?php if ($missing): ?>
<div class="sa-alert sa-alert-warning" data-sa-alert>
    <?php echo sa_icon('alert'); ?>
    <div>
        <strong>Missing database tables</strong>
        <?php echo sa_e(implode(', ', $missing)); ?> — import the matching block from
        <span class="sa-mono">database.sql</span> to unlock those features.
    </div>
</div>
<?php endif; ?>

<!-- ============ TAB NAV ============ -->
<nav class="sa-tabs" role="tablist" aria-label="Settings sections">
    <button class="sa-tab-btn is-active" role="tab" aria-selected="true"  aria-controls="tab-platform" id="tabBtn-platform" onclick="saTab('platform')">
        <?php echo sa_icon('zap'); ?> Platform
    </button>
    <button class="sa-tab-btn" role="tab" aria-selected="false" aria-controls="tab-email"    id="tabBtn-email"    onclick="saTab('email')">
        <?php echo sa_icon('mail'); ?> Email & SMTP
    </button>
    <button class="sa-tab-btn" role="tab" aria-selected="false" aria-controls="tab-account"  id="tabBtn-account"  onclick="saTab('account')">
        <?php echo sa_icon('key'); ?> Account
    </button>
    <button class="sa-tab-btn" role="tab" aria-selected="false" aria-controls="tab-database" id="tabBtn-database" onclick="saTab('database')">
        <?php echo sa_icon('server'); ?> Database
        <?php if ($missing): ?>
        <span class="sa-badge sa-badge-inactive" style="margin-left:2px;padding:2px 7px;font-size:10px;">!</span>
        <?php endif; ?>
    </button>
</nav>

<!-- ============ TAB: PLATFORM ============ -->
<div class="sa-tab-panel is-active" id="tab-platform" role="tabpanel" aria-labelledby="tabBtn-platform">
    <div class="sa-grid sa-split-2-1">
        <!-- Platform defaults -->
        <section class="sa-card">
            <form method="POST" action="settings.php" class="sa-form">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="save_settings">
                <div class="sa-card-head">
                    <div>
                        <h3>Platform defaults</h3>
                        <p>Branding, currency and the numbers the platform runs on</p>
                    </div>
                    <span class="sa-kpi-icon" style="--kpi-accent:var(--sa-accent);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)"><?php echo sa_icon('zap'); ?></span>
                </div>
                <div class="sa-card-pad">
                    <div class="sa-form-grid">
<?php foreach ($sa_setting_fields as $key => $meta): ?>
                        <div class="sa-field">
                            <label for="s_<?php echo sa_e($key); ?>"><?php echo sa_e($meta['label']); ?></label>
                            <input id="s_<?php echo sa_e($key); ?>" type="<?php echo sa_e($meta['type']); ?>" name="<?php echo sa_e($key); ?>"
                                   value="<?php echo sa_e(isset($settings[$key]) ? $settings[$key] : ''); ?>"
                                   <?php echo $meta['type'] === 'number' ? 'min="0" step="1"' : ''; ?>>
                            <span class="sa-hint"><?php echo sa_e($meta['hint']); ?></span>
                        </div>
<?php endforeach; ?>
                    </div>

                    <div class="sa-section-title" style="margin:24px 0 12px">Appearance</div>
                    <div class="sa-flex" style="gap:14px;flex-wrap:wrap">
                        <button type="button" class="sa-btn sa-btn-ghost" data-sa-theme>
                            <?php echo sa_icon('sun'); ?> Switch to light theme
                        </button>
                        <span class="sa-muted" style="font-size:12.6px">Your choice is remembered in this browser only.</span>
                    </div>
                </div>
                <div class="sa-card-foot">
                    <span>Stored in the <span class="sa-mono">settings</span> table</span>
                    <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('save'); ?> Save settings</button>
                </div>
            </form>
        </section>

        <!-- Branding & assets -->
        <section class="sa-card" style="grid-column:1/-1">
            <form method="POST" action="settings.php" class="sa-form" enctype="multipart/form-data" id="brandAssetsForm">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="save_brand_assets">
                <div class="sa-card-head">
                    <div>
                        <h3>Branding &amp; assets</h3>
                        <p>Logo and favicon shown across the platform (sidebar, login, public site and browser tab).</p>
                    </div>
                    <span class="sa-kpi-icon" style="--kpi-accent:var(--sa-accent);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)"><?php echo sa_icon('star'); ?></span>
                </div>
                <div class="sa-card-pad">
                    <div class="sa-form-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px">
                        <!-- Logo -->
                        <div class="sa-field">
                            <label for="brandLogo">Logo</label>
                            <div class="sa-brand-preview">
                                <div class="sa-brand-preview-box sa-brand-preview-box--logo">
                                    <?php if ($logo_url): ?>
                                        <img src="<?php echo sa_e($logo_url); ?>" alt="Current platform logo">
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" data-sa-pick="#brandLogo"><?php echo sa_icon('upload'); ?> Choose image</button>
                                    <span class="sa-hint" id="brandLogoName">No logo set</span>
                                    <input type="file" id="brandLogo" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden data-sa-brand-picker data-preview-target="brandLogoName" data-target="#brandLogoField">
                                </div>
                            </div>
                            <span class="sa-hint" style="display:block;margin-top:6px">PNG, JPG, SVG or WebP up to 2 MB. Shown in the sidebar, login page and public header.</span>
                            <input type="hidden" id="brandLogoField" name="logo_asset" value="<?php echo sa_e($logo_url ? $logo_url : ($logo_stored ?? '')); ?>">
                        </div>
<!-- Favicon -->
                        <div class="sa-field">
                            <label for="brandFavicon">Favicon</label>
                            <div class="sa-brand-preview">
                                <div class="sa-brand-preview-box sa-brand-preview-box--favicon">
                                    <?php if ($favicon_url): ?>
                                        <img src="<?php echo sa_e($favicon_url); ?>" alt="Current favicon">
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" data-sa-pick="#brandFavicon"><?php echo sa_icon('upload'); ?> Choose image</button>
                                    <span class="sa-hint" id="brandFaviconName">No favicon set</span>
                                    <input type="file" id="brandFavicon" accept="image/png,image/x-icon,image/svg+xml" hidden data-sa-brand-picker data-preview-target="brandFaviconName" data-target="#brandFaviconField">
                                </div>
                            </div>
                            <span class="sa-hint" style="display:block;margin-top:6px">PNG, ICO or SVG up to 512 KB. Shown in the browser tab.</span>
                            <input type="hidden" id="brandFaviconField" name="favicon_asset" value="<?php echo sa_e($favicon_url ? $favicon_url : ($favicon_stored ?? '')); ?>">
                        </div>
                    </div>

                    <div class="sa-muted" style="margin-top:16px;font-size:12.3px">
                        <?php echo sa_icon('info'); ?>
                        <span>Also supports an external URL (paste into the field above) — e.g. <span class="sa-mono">https://cdn.example.com/logo.png</span>.</span>
                    </div>
                </div>
                <div class="sa-card-foot">
                    <span>Stored in the <span class="sa-mono">settings</span> table</span>
                    <button type="submit" class="sa-btn sa-btn-primary" id="brandAssetsSave"><?php echo sa_icon('save'); ?> Save branding</button>
                </div>
            </form>
        </section>

        <!-- Quick info sidebar -->
        <div class="sa-stack">
            <section class="sa-card sa-card-pad" style="display:flex;flex-direction:column;gap:18px;">
                <div>
                    <div class="sa-section-title" style="margin:0 0 12px">Platform info</div>
                    <dl class="sa-kv-list">
                        <div class="sa-kv-row">
                            <dt>PHP version</dt>
                            <dd><?php echo sa_e(PHP_VERSION); ?></dd>
                        </div>
                        <div class="sa-kv-row">
                            <dt>Server time</dt>
                            <dd><?php echo sa_e(date('M d, Y H:i')); ?></dd>
                        </div>
                        <div class="sa-kv-row">
                            <dt>Database</dt>
                            <dd><?php echo sa_e(defined('DB_NAME') ? DB_NAME : '—'); ?></dd>
                        </div>
                        <div class="sa-kv-row">
                            <dt>Tables present</dt>
                            <dd><?php echo sa_e(count($health) - count($missing)); ?> / <?php echo sa_e(count($health)); ?></dd>
                        </div>
                    </dl>
                </div>
            </section>
        </div>
    </div>
</div>

<!-- ============ TAB: EMAIL ============ -->
<div class="sa-tab-panel" id="tab-email" role="tabpanel" aria-labelledby="tabBtn-email">

    <section class="sa-card">
        <form method="POST" action="settings.php" class="sa-form" id="saMailForm">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="save_email_settings">

            <div class="sa-card-head">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span class="sa-kpi-icon" style="--kpi-accent:var(--sa-accent);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
                        <?php echo sa_icon('mail'); ?>
                    </span>
                    <div>
                        <h3 style="margin:0;">Email &amp; SMTP configuration</h3>
                        <p style="margin:3px 0 0;">Outbound system email delivery, notification routing and SMTP credentials.</p>
                    </div>
                </div>
                <div class="sa-card-head-actions">
                    <span class="sa-badge <?php echo ($mail_cfg['mail_driver'] === 'smtp') ? 'sa-badge-active' : 'sa-badge-pending'; ?>">
                        <?php echo strtoupper($mail_cfg['mail_driver']); ?> <?php echo ($mail_cfg['mail_driver'] === 'smtp') ? 'Delivery' : 'Native'; ?>
                    </span>
                </div>
            </div>

            <div class="sa-card-pad">
                <!-- Driver & presets row -->
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:22px;padding-bottom:18px;border-bottom:1px solid var(--sa-line);">
                    <div class="sa-field" style="margin-bottom:0;min-width:260px;">
                        <label for="mail_driver" style="font-weight:700;">Mail sending driver</label>
                        <select id="mail_driver" name="mail_driver" class="sa-select" onchange="toggleMailDriver(this.value)" style="width:100%;padding:9px 12px;border-radius:var(--sa-radius-xs);border:1px solid var(--sa-line);background:var(--sa-surface-2);color:var(--sa-text);font-family:inherit;font-size:13.5px;">
                            <option value="smtp" <?php echo ($mail_cfg['mail_driver'] === 'smtp') ? 'selected' : ''; ?>>SMTP Server (Recommended — Gmail, Outlook, cPanel, SendGrid, Mailgun)</option>
                            <option value="mail" <?php echo ($mail_cfg['mail_driver'] === 'mail') ? 'selected' : ''; ?>>PHP mail() (Built-in server sendmail)</option>
                        </select>
                    </div>
                    <div id="smtpPresets" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span class="sa-muted" style="font-size:12px;font-weight:600;">Quick presets:</span>
                        <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" onclick="applySmtpPreset('gmail')">Gmail</button>
                        <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" onclick="applySmtpPreset('office365')">Office 365</button>
                        <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" onclick="applySmtpPreset('mailgun')">Mailgun</button>
                        <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" onclick="applySmtpPreset('localhost')">Localhost</button>
                    </div>
                </div>

                <!-- Sender identity -->
                <div class="sa-section-title" style="margin:0 0 14px;">Sender identity</div>
                <div class="sa-form-grid" style="grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;margin-bottom:22px;">
                    <div class="sa-field">
                        <label for="mail_from_name">From name</label>
                        <input id="mail_from_name" type="text" name="mail_from_name" value="<?php echo sa_e($mail_cfg['mail_from_name']); ?>" required placeholder="Optibiz Platform">
                        <span class="sa-hint">Friendly sender name visible in recipient inboxes.</span>
                    </div>
                    <div class="sa-field">
                        <label for="mail_from_email">From email</label>
                        <input id="mail_from_email" type="email" name="mail_from_email" value="<?php echo sa_e($mail_cfg['mail_from_email']); ?>" required placeholder="notifications@yourdomain.com">
                        <span class="sa-hint">Must be authorized by your SMTP server domain.</span>
                    </div>
                </div>

                <!-- SMTP credentials (hidden when PHP mail active) -->
                <div id="smtpSettingsWrapper" style="<?php echo ($mail_cfg['mail_driver'] === 'mail') ? 'display:none;' : ''; ?>">
                    <div class="sa-section-title" style="margin:0 0 14px;">SMTP connection credentials</div>
                    <div class="sa-form-grid" style="grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:16px;margin-bottom:16px;">
                        <div class="sa-field">
                            <label for="smtp_host">SMTP host</label>
                            <input id="smtp_host" type="text" name="smtp_host" value="<?php echo sa_e($mail_cfg['smtp_host']); ?>" placeholder="smtp.gmail.com">
                            <span class="sa-hint">Hostname or IP (e.g. smtp.gmail.com).</span>
                        </div>
                        <div class="sa-field">
                            <label for="smtp_port">Port</label>
                            <input id="smtp_port" type="number" name="smtp_port" value="<?php echo sa_e($mail_cfg['smtp_port']); ?>" min="1" max="65535" placeholder="587">
                            <span class="sa-hint">587 (TLS), 465 (SSL), or 25.</span>
                        </div>
                        <div class="sa-field">
                            <label for="smtp_encryption">Encryption</label>
                            <select id="smtp_encryption" name="smtp_encryption" style="width:100%;padding:9px 12px;border-radius:var(--sa-radius-xs);border:1px solid var(--sa-line);background:var(--sa-surface-2);color:var(--sa-text);font-family:inherit;font-size:13.5px;">
                                <option value="tls" <?php echo ($mail_cfg['smtp_encryption'] === 'tls') ? 'selected' : ''; ?>>TLS / STARTTLS (Port 587 — Recommended)</option>
                                <option value="ssl" <?php echo ($mail_cfg['smtp_encryption'] === 'ssl') ? 'selected' : ''; ?>>SSL / SMTPS (Port 465)</option>
                                <option value="none" <?php echo ($mail_cfg['smtp_encryption'] === 'none') ? 'selected' : ''; ?>>None / Unencrypted (Port 25 or local)</option>
                            </select>
                            <span class="sa-hint">Socket encryption during the SMTP handshake.</span>
                        </div>
                    </div>
                    <div class="sa-form-grid" style="grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;">
                        <div class="sa-field">
                            <label for="smtp_username">SMTP username</label>
                            <input id="smtp_username" type="text" name="smtp_username" value="<?php echo sa_e($mail_cfg['smtp_username']); ?>" placeholder="user@gmail.com" autocomplete="off">
                            <span class="sa-hint">Full email address for SMTP authentication.</span>
                        </div>
                        <div class="sa-field">
                            <label for="smtp_password">SMTP password / App password</label>
                            <div style="position:relative;">
                                <input id="smtp_password" type="password" name="smtp_password" placeholder="<?php echo !empty($mail_cfg['smtp_password']) ? '•••••••••••• (Leave blank to keep current)' : 'Enter SMTP password'; ?>" autocomplete="new-password">
                                <button type="button" onclick="togglePasswordVisibility('smtp_password', this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--sa-muted);cursor:pointer;padding:4px;display:grid;place-items:center;" title="Toggle visibility">
                                    <?php echo sa_icon('eye'); ?>
                                </button>
                            </div>
                            <span class="sa-hint">For Gmail with 2FA, generate an <strong>App Password</strong>.</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sa-card-foot">
                <span class="sa-muted" style="font-size:12.6px;">Saved in the <span class="sa-mono">settings</span> table &middot; applies immediately.</span>
                <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('save'); ?> Save email settings</button>
            </div>
        </form>
    </section>

    <!-- Test dispatch card -->
    <section class="sa-card" style="margin-top:16px;">
        <form method="POST" action="settings.php" class="sa-form">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="send_test_email">
            <div class="sa-card-head" style="padding:16px 24px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="sa-kpi-icon" style="width:32px;height:32px;--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
                        <?php echo sa_icon('send'); ?>
                    </span>
                    <div>
                        <h4 style="margin:0;font-size:14.5px;">Test email dispatch</h4>
                        <p style="margin:2px 0 0;font-size:12.4px;color:var(--sa-muted);">Verify SMTP handshake and delivery by sending a live test message.</p>
                    </div>
                </div>
            </div>
            <div class="sa-card-pad" style="padding:18px 24px;">
                <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
                    <div class="sa-field" style="flex:1;min-width:280px;margin-bottom:0;">
                        <label for="test_recipient" style="font-size:12.6px;">Recipient address</label>
                        <input id="test_recipient" type="email" name="test_recipient" value="<?php echo sa_e($admin ? $admin['email'] : ''); ?>" required placeholder="you@example.com">
                    </div>
                    <button type="submit" class="sa-btn sa-btn-ghost" style="padding:10px 22px;">
                        <?php echo sa_icon('send'); ?> Dispatch test email
                    </button>
                </div>
            </div>
        </form>
    </section>
</div>

<!-- ============ TAB: ACCOUNT ============ -->
<div class="sa-tab-panel" id="tab-account" role="tabpanel" aria-labelledby="tabBtn-account">
    <div class="sa-grid sa-split-2-1">

        <!-- Account details -->
        <section class="sa-card">
            <form method="POST" action="settings.php" class="sa-form">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="save_admin">
                <div class="sa-card-head">
                    <div>
                        <h3>Your account</h3>
                        <p>Signed in as <?php echo sa_e($admin ? $admin['username'] : 'super admin'); ?></p>
                    </div>
                    <span class="sa-user-avatar" style="width:38px;height:38px;border-radius:12px"><?php echo sa_e(sa_initials($admin ? $admin['username'] : 'SA')); ?></span>
                </div>
                <div class="sa-card-pad sa-form" style="gap:14px">
                    <div class="sa-field">
                        <label for="a_username">Username</label>
                        <input id="a_username" type="text" name="username" value="<?php echo sa_e($admin ? $admin['username'] : ''); ?>" required>
                    </div>
                    <div class="sa-field">
                        <label for="a_email">Email</label>
                        <input id="a_email" type="email" name="email" value="<?php echo sa_e($admin ? $admin['email'] : ''); ?>">
                    </div>
                    <div class="sa-field">
                        <label>Member since</label>
                        <div class="sa-muted" style="font-size:13px"><?php echo sa_e(sa_date($admin ? $admin['created_at'] : '', 'M d, Y')); ?></div>
                    </div>
                </div>
                <div class="sa-card-foot">
                    <span>Used to sign in at <span class="sa-mono">/superadmin/login.php</span></span>
                    <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('save'); ?> Save account</button>
                </div>
            </form>
        </section>

        <!-- Change password -->
        <section class="sa-card">
            <form method="POST" action="settings.php" class="sa-form">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="change_password">
                <div class="sa-card-head">
                    <div>
                        <h3>Change password</h3>
                        <p>Minimum 8 characters</p>
                    </div>
                    <span class="sa-kpi-icon" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)"><?php echo sa_icon('key'); ?></span>
                </div>
                <div class="sa-card-pad sa-form" style="gap:14px">
                    <div class="sa-field">
                        <label for="p_current">Current password</label>
                        <input id="p_current" type="password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="sa-field">
                        <label for="p_new">New password</label>
                        <input id="p_new" type="password" name="new_password" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="sa-field">
                        <label for="p_confirm">Confirm new password</label>
                        <input id="p_confirm" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                    </div>
                </div>
                <div class="sa-card-foot">
                    <span>Hashed with PHP's bcrypt</span>
                    <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('shield'); ?> Update password</button>
                </div>
            </form>
        </section>

    </div>
</div>

<!-- ============ TAB: DATABASE ============ -->
<div class="sa-tab-panel" id="tab-database" role="tabpanel" aria-labelledby="tabBtn-database">
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Database health</h3>
                <p>Tables found in <span class="sa-mono"><?php echo sa_e(defined('DB_NAME') ? DB_NAME : 'the configured database'); ?></span></p>
            </div>
            <div class="sa-card-head-actions">
                <span class="sa-pill"><?php echo sa_e(sa_num(count($health) - count($missing))); ?> / <?php echo sa_e(sa_num(count($health))); ?> present</span>
                <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" onclick="window.location.reload()"><?php echo sa_icon('refresh'); ?> Re-check</button>
            </div>
        </div>
        <div class="sa-table-wrap">
            <table class="sa-table" data-sa-sortable-table>
                <thead scope="col">
                    <tr>
                        <th scope="col">Table</th>
                        <th scope="col">Status</th>
                        <th data-sa-sort="2" data-type="num" scope="col" aria-sort="none">Rows</th>
                        <th scope="col">Where it is used</th>
                    </tr>
                </thead>
                <tbody>
<?php
$usage = [
    'super_admins'       => 'This control center',
    'subscription_plans' => 'Plans & pricing',
    'tenants'            => 'Tenants, subscriptions, revenue',
    'admins'             => 'Tenant admin logins',
    'categories'         => 'Company categories',
    'customers'          => 'Companies listed by tenants',
    'ratings'            => 'Ratings, analytics, engagement',
    'settings'           => 'This page',
    'quote_requests'     => 'Sales pipeline',
];
foreach ($health as $h): ?>
                    <tr>
                        <td class="sa-mono"><?php echo sa_e($h['table']); ?></td>
                        <td><?php echo $h['exists']
                            ? '<span class="sa-badge sa-badge-active">Present</span>'
                            : '<span class="sa-badge sa-badge-inactive">Missing</span>'; ?></td>
                        <td class="num"><?php echo $h['exists'] ? sa_e(sa_num($h['rows'])) : '<span class="sa-faint">—</span>'; ?></td>
                        <td class="sa-muted"><?php echo sa_e(isset($usage[$h['table']]) ? $usage[$h['table']] : ''); ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="sa-card-foot">
            <span>PHP <?php echo sa_e(PHP_VERSION); ?> &middot; server time <?php echo sa_e(date('M d, Y H:i')); ?></span>
            <span>Schema lives in <span class="sa-mono">database.sql</span></span>
        </div>
    </section>
</div>

<script>
/* ---- Tab system ---- */
function saTab(id) {
    document.querySelectorAll('.sa-tab-btn').forEach(function(btn) {
        var active = btn.id === 'tabBtn-' + id;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('.sa-tab-panel').forEach(function(panel) {
        panel.classList.toggle('is-active', panel.id === 'tab-' + id);
    });
    try { location.replace('#tab=' + id); } catch(e) {}
}

/* Restore tab from URL hash on load */
(function () {
    var hash = location.hash.replace('#tab=', '').replace('#', '');
    var valid = ['platform', 'email', 'account', 'database'];
    if (valid.indexOf(hash) !== -1) {
        saTab(hash);
    }
})();

/* ---- Email/SMTP helpers ---- */
function toggleMailDriver(driver) {
    var wrapper = document.getElementById('smtpSettingsWrapper');
    var presets = document.getElementById('smtpPresets');
    if (wrapper) wrapper.style.display = (driver === 'mail') ? 'none' : 'block';
    if (presets) presets.style.display = (driver === 'mail') ? 'none' : 'flex';
}

function applySmtpPreset(type) {
    var host   = document.getElementById('smtp_host');
    var port   = document.getElementById('smtp_port');
    var enc    = document.getElementById('smtp_encryption');
    var driver = document.getElementById('mail_driver');
    if (driver) driver.value = 'smtp';
    toggleMailDriver('smtp');
    var presets = {
        gmail:     { host: 'smtp.gmail.com',     port: '587', enc: 'tls' },
        office365: { host: 'smtp.office365.com', port: '587', enc: 'tls' },
        mailgun:   { host: 'smtp.mailgun.org',   port: '587', enc: 'tls' },
        localhost: { host: 'localhost',           port: '1025', enc: 'none' },
    };
    if (presets[type]) {
        if (host) host.value = presets[type].host;
        if (port) port.value = presets[type].port;
        if (enc)  enc.value  = presets[type].enc;
    }
}

function togglePasswordVisibility(fieldId, btn) {
    var field = document.getElementById(fieldId);
    if (!field) return;
    field.type = (field.type === 'password') ? 'text' : 'password';
    btn.style.color = (field.type === 'text') ? 'var(--sa-accent)' : 'var(--sa-muted)';
}
</script>

<script>
/* Branding upload: clicking "Choose image" opens the file input; on select
   we read the file, create an object URL, show a live preview and stamp the
   data-URI into the hidden field that gets POSTed. */
(function () {
    document.querySelectorAll('[data-sa-pick]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.querySelector(btn.getAttribute('data-sa-pick'));
            if (target) target.click();
        });
    });

    document.querySelectorAll('[data-sa-brand-picker]').forEach(function (input) {
        var previewBox = input.closest('.sa-brand-preview').querySelector(
            '.sa-brand-preview-box'
        );
        var nameEl = document.getElementById(input.getAttribute('data-preview-target') || '');
        var hidden = document.querySelector(input.getAttribute('data-target'));

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            var url = URL.createObjectURL(file);
            previewBox.innerHTML = '<img src="' + url + '" alt="Preview">';
            if (nameEl) nameEl.textContent = file.name;

            var reader = new FileReader();
            reader.onload = function () {
                if (hidden) hidden.value = reader.result;
            };
            reader.readAsDataURL(file);
        });
    });
})();
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
