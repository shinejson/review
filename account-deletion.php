<?php
/**
 * Public account deletion (Google Play requirement).
 *
 * A customer can delete their account without the app installed:
 *   1. request a one-time code for the email or phone on the account,
 *   2. verify it, which yields the account,
 *   3. confirm deletion, which anonymises the account and revokes sessions.
 *
 * The page never says whether an address or number belongs to an account:
 * step 1 returns the same message either way, and only a valid code can
 * reach step 3.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/app_auth.php';
require_once __DIR__ . '/includes/app_otp.php';

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    @session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}

$siteName = function_exists('sa_setting') ? sa_setting($conn, 'site_name', 'Optibiz') : 'Optibiz';
$supportEmail = function_exists('sa_setting') ? sa_setting($conn, 'support_email', '') : '';
if ($supportEmail === '' && function_exists('sa_setting')) {
    $supportEmail = sa_setting($conn, 'admin_email', '');
}
$supportEmail = $supportEmail !== '' ? $supportEmail : 'support@example.com';
$base = function_exists('getAppBaseUrl') ? rtrim(getAppBaseUrl(), '/') : '';

if (empty($_SESSION['deletion_csrf'])) {
    $_SESSION['deletion_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['deletion_csrf'];

$step = 'request';          // request | verify | done
$notice = '';
$noticeType = 'info';
$pending = $_SESSION['deletion_pending'] ?? null;

if (!empty($pending) && !empty($pending['verified'])) {
    $step = 'confirm';
} elseif (!empty($pending)) {
    $step = 'verify';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $posted = (string) ($_POST['csrf'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($csrf, $posted)) {
        $notice = 'Your session expired. Please start again.';
        $noticeType = 'error';
        $step = 'request';
        unset($_SESSION['deletion_pending']);
    } elseif ($action === 'request_code') {
        $channel = ($_POST['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';
        $destination = trim((string) ($_POST['destination'] ?? ''));

        $result = optibiz_otp_request($conn, $channel, $destination, 'delete_account');
        if (!empty($result['success'])) {
            $_SESSION['deletion_pending'] = [
                'channel'     => $channel,
                'destination' => $destination,
                'verified'    => false,
                'requested'   => time(),
            ];
            $step = 'verify';
            $notice = 'If an account matches, a 6-digit code is on its way. It expires in '
                . (int) round(($result['expires_in'] ?? 600) / 60) . ' minutes.';
            $noticeType = 'success';
        } else {
            $notice = (string) ($result['message'] ?? 'Could not send a code. Try again in a few minutes.');
            $noticeType = 'error';
            $step = 'request';
        }
    } elseif ($action === 'verify_code' && is_array($pending)) {
        $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
        $result = optibiz_otp_verify(
            $conn,
            (string) ($pending['channel'] ?? 'email'),
            (string) ($pending['destination'] ?? ''),
            'delete_account',
            (string) $code
        );
        if (!empty($result['success']) && !empty($result['user'])) {
            $_SESSION['deletion_pending']['verified'] = true;
            $_SESSION['deletion_pending']['user_id'] = (int) $result['user']['id'];
            $step = 'confirm';
            $notice = 'Code confirmed. Review what will be removed, then delete the account.';
            $noticeType = 'success';
        } else {
            $notice = (string) ($result['message'] ?? 'That code is not valid. Request a new one.');
            $noticeType = 'error';
            $step = 'verify';
        }
    } elseif ($action === 'delete_account' && is_array($pending) && !empty($pending['verified'])) {
        $confirm = strtoupper(trim((string) ($_POST['confirm'] ?? '')));
        if ($confirm !== 'DELETE') {
            $notice = 'Type DELETE in the box to confirm.';
            $noticeType = 'error';
            $step = 'confirm';
        } else {
            $userId = (int) ($pending['user_id'] ?? 0);
            $result = optibiz_app_delete_account($conn, $userId, 'customer request from the public deletion page', 'web');
            if (!empty($result['success'])) {
                unset($_SESSION['deletion_pending']);
                $step = 'done';
            } else {
                $notice = (string) ($result['message'] ?? 'The account could not be deleted. Contact support.');
                $noticeType = 'error';
                $step = 'confirm';
            }
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION['deletion_pending']);
        $step = 'request';
    }
}

function deletion_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en-GH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Delete your account — <?php echo deletion_e($siteName); ?></title>
<meta name="description" content="Delete your <?php echo deletion_e($siteName); ?> account and personal data without reinstalling the app.">
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="<?php echo deletion_e($base); ?>/account-deletion.php">
<style>
    :root { --navy:#0b1b2b; --lime:#c2f542; --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --paper:#f8fafc; }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--paper); color:var(--ink); font-family:"Plus Jakarta Sans",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; line-height:1.65; }
    a { color:#0f5fa8; }
    a:focus-visible, button:focus-visible, input:focus-visible { outline:3px solid #0f5fa8; outline-offset:2px; }
    .top { background:var(--navy); padding:16px 24px; }
    .top-inner { max-width:820px; margin:0 auto; display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; }
    .brand { color:#fff; text-decoration:none; font-weight:800; display:inline-flex; align-items:center; gap:10px; }
    .brand-mark { width:32px; height:32px; border-radius:8px; background:var(--lime); color:var(--navy); display:grid; place-items:center; font-weight:900; }
    .top nav a { color:#cbd5e1; text-decoration:none; font-size:13px; font-weight:600; margin-left:16px; }
    .wrap { max-width:760px; margin:0 auto; padding:38px 24px 64px; }
    h1 { font-size:clamp(26px,4vw,38px); letter-spacing:-0.03em; margin:0 0 8px; }
    .lead { color:var(--muted); margin:0 0 24px; font-size:15.5px; max-width:640px; }
    .steps { display:flex; gap:8px; margin-bottom:22px; }
    .steps span { height:5px; flex:1; border-radius:999px; background:#e2e8f0; }
    .steps span.done { background:#16a34a; }
    .steps span.current { background:var(--navy); }
    .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:26px 28px; margin-bottom:16px; }
    .card h2 { font-size:18px; margin:0 0 6px; letter-spacing:-0.01em; }
    .hint { color:var(--muted); font-size:13px; margin:0 0 16px; }
    label { display:block; font-weight:700; font-size:12.5px; text-transform:uppercase; letter-spacing:0.05em; color:#475569; margin:14px 0 6px; }
    input[type=email], input[type=tel], input[type=text] {
        width:100%; padding:12px 13px; border:1.5px solid #cbd5e1; border-radius:9px; font:inherit; font-size:15px; background:#fff;
    }
    input[type=email]:focus, input[type=tel]:focus, input[type=text]:focus { border-color:#0f5fa8; }
    .channel { display:flex; gap:10px; flex-wrap:wrap; margin-top:8px; }
    .channel label { margin:0; text-transform:none; letter-spacing:0; font-size:13.5px; font-weight:600; border:1.5px solid #cbd5e1; border-radius:9px; padding:9px 14px; display:flex; align-items:center; gap:8px; cursor:pointer; color:#334155; }
    .channel input { accent-color:#0f5fa8; }
    .btn { display:inline-block; width:100%; margin-top:20px; padding:13px 18px; border:0; border-radius:9px; background:var(--navy); color:#fff; font:inherit; font-weight:800; font-size:14.5px; cursor:pointer; }
    .btn:hover { opacity:0.95; }
    .btn.danger { background:#b42318; }
    .btn.ghost { background:#fff; color:#334155; border:1.5px solid #cbd5e1; margin-top:10px; }
    .notice { border-radius:9px; padding:12px 14px; font-size:14px; margin:0 0 16px; }
    .notice.info { background:#f0f9ff; border:1px solid #bae6fd; color:#075985; }
    .notice.success { background:#ecfdf3; border:1px solid #abefc6; color:#05603a; }
    .notice.error { background:#fef3f2; border:1px solid #fecdca; color:#912018; }
    .summary { background:#f8fafc; border:1px solid var(--line); border-radius:10px; padding:14px 16px; font-size:14px; }
    .summary ul { margin:8px 0 0; padding-left:20px; }
    .summary li { margin:5px 0; }
    .keep { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:13px 15px; font-size:13.5px; color:#78350f; margin-top:14px; }
    .meta { color:var(--muted); font-size:12px; margin-top:14px; }
    footer { border-top:1px solid var(--line); background:#fff; padding:20px 24px; font-size:12.5px; color:var(--muted); }
    .footer-inner { max-width:760px; margin:0 auto; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    @media (max-width:560px) { .card { padding:20px 17px; } }
</style>
</head>
<body>
<header class="top">
    <div class="top-inner">
        <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">★</span> <?php echo deletion_e($siteName); ?></a>
        <nav aria-label="Primary">
            <a href="index.php">Home</a>
            <a href="privacy.php">Privacy</a>
        </nav>
    </div>
</header>

<main class="wrap">
    <h1>Delete your account</h1>
    <p class="lead">This removes your <?php echo deletion_e($siteName); ?> account and the personal details attached to it. You do not need the app installed.</p>

    <div class="steps" aria-hidden="true">
        <span class="<?php echo $step === 'request' ? 'current' : 'done'; ?>"></span>
        <span class="<?php echo $step === 'verify' ? 'current' : ($step === 'confirm' || $step === 'done' ? 'done' : ''); ?>"></span>
        <span class="<?php echo $step === 'confirm' ? 'current' : ($step === 'done' ? 'done' : ''); ?>"></span>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="notice <?php echo deletion_e($noticeType); ?>" role="status"><?php echo deletion_e($notice); ?></div>
    <?php endif; ?>

    <?php if ($step === 'request'): ?>
        <section class="card">
            <h2>1. Prove it is your account</h2>
            <p class="hint">We send a one-time code to the email address or phone number on the account. The message is the same whether or not an account exists, so this page cannot be used to check who has an account here.</p>
            <form method="post" novalidate>
                <input type="hidden" name="csrf" value="<?php echo deletion_e($csrf); ?>">
                <input type="hidden" name="action" value="request_code">
                <div class="channel" role="radiogroup" aria-label="Where to send the code">
                    <label><input type="radio" name="channel" value="email" checked> Email</label>
                    <label><input type="radio" name="channel" value="sms"> Phone (SMS)</label>
                </div>
                <label for="destination">Email address or phone number</label>
                <input id="destination" name="destination" type="text" autocomplete="email" inputmode="email" required
                       placeholder="you@example.com or 024 000 0000">
                <button class="btn" type="submit">Send code</button>
            </form>
            <p class="meta">If you no longer have access to the email or phone on the account, email
                <a href="mailto:<?php echo deletion_e($supportEmail); ?>"><?php echo deletion_e($supportEmail); ?></a>
                and we will verify your identity another way.</p>
        </section>

    <?php elseif ($step === 'verify'): ?>
        <section class="card">
            <h2>2. Enter the code</h2>
            <p class="hint">A 6-digit code was sent to the contact on your account. It expires after 10 minutes and can be used once.</p>
            <form method="post" novalidate>
                <input type="hidden" name="csrf" value="<?php echo deletion_e($csrf); ?>">
                <input type="hidden" name="action" value="verify_code">
                <label for="code">6-digit code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                       pattern="[0-9]{6}" maxlength="6" required placeholder="123456">
                <button class="btn" type="submit">Confirm code</button>
            </form>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo deletion_e($csrf); ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="btn ghost" type="submit">Use a different address or number</button>
            </form>
        </section>

    <?php elseif ($step === 'confirm'): ?>
        <section class="card">
            <h2>3. Confirm deletion</h2>
            <p class="hint">This cannot be undone. Here is exactly what happens:</p>
            <div class="summary">
                <strong>Removed from the account</strong>
                <ul>
                    <li>Your name, email address, phone number and password</li>
                    <li>Your Google Sign-In link and profile photo</li>
                    <li>Push tokens and every signed-in session</li>
                    <li>Pending verification codes</li>
                </ul>
            </div>
            <div class="keep">
                <strong>What stays:</strong> reviews you posted remain part of each business's rating history, but without your name, email, account id or device identifier. Moderation records for an open report may be kept for a limited period to prevent repeat abuse.
            </div>
            <form method="post" novalidate>
                <input type="hidden" name="csrf" value="<?php echo deletion_e($csrf); ?>">
                <input type="hidden" name="action" value="delete_account">
                <label for="confirm">Type DELETE to confirm</label>
                <input id="confirm" name="confirm" type="text" autocomplete="off" required placeholder="DELETE">
                <button class="btn danger" type="submit">Delete my account permanently</button>
            </form>
        </section>

    <?php else: ?>
        <section class="card">
            <h2>Your account has been deleted</h2>
            <p class="hint">We have anonymised the account and signed it out everywhere. Reviews you left are still visible to the businesses you reviewed, without your personal details.</p>
            <p class="meta">Need a record of this request? Email <a href="mailto:<?php echo deletion_e($supportEmail); ?>"><?php echo deletion_e($supportEmail); ?></a> within 30 days and we will confirm the deletion.</p>
            <a class="btn" href="index.php" style="text-align:center;text-decoration:none;">Back to <?php echo deletion_e($siteName); ?></a>
        </section>
    <?php endif; ?>

    <p class="meta">Deleting an account is different from deleting your business's workspace. Business owners should contact support to close a workspace, because it affects staff and billing.</p>
</main>

<footer>
    <div class="footer-inner">
        <span>&copy; <?php echo date('Y'); ?> <?php echo deletion_e($siteName); ?></span>
        <span><a href="privacy.php">Privacy Policy</a> · <a href="index.php">Home</a></span>
    </div>
</footer>
</body>
</html>
