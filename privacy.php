<?php
/**
 * Public privacy policy.
 *
 * Written against what this codebase actually stores: customer accounts,
 * reviews (including QR verification metadata), devices and notifications.
 * It is a working draft for the Ghana Data Protection Act, 2012 (Act 843)
 * and Google Play's user-data requirements — have counsel review it before
 * publication.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$siteName = function_exists('sa_setting') ? sa_setting($conn, 'site_name', 'Optibiz') : 'Optibiz';
$contactEmail = function_exists('sa_setting') ? sa_setting($conn, 'support_email', '') : '';
if ($contactEmail === '' && function_exists('sa_setting')) {
    $contactEmail = sa_setting($conn, 'admin_email', '');
}
$contactEmail = $contactEmail !== '' ? $contactEmail : 'privacy@example.com';
$privacyEmail = 'privacy@' . (strpos($contactEmail, '@') !== false ? substr($contactEmail, strpos($contactEmail, '@') + 1) : 'example.com');
$base = function_exists('getAppBaseUrl') ? rtrim(getAppBaseUrl(), '/') : '';
$updated = '19 October 2026';

$sections = [
    'who'        => 'Who we are',
    'data'       => 'What we collect',
    'why'        => 'Why we use it',
    'sharing'    => 'Who we share it with',
    'retention'  => 'How long we keep it',
    'rights'     => 'Your rights',
    'security'   => 'Security',
    'cookies'    => 'Cookies and local storage',
    'children'   => 'Children',
    'changes'    => 'Changes to this policy',
    'contact'    => 'Contact us',
];
?>
<!DOCTYPE html>
<html lang="en-GH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy Policy — <?php echo htmlspecialchars($siteName); ?></title>
<meta name="description" content="How <?php echo htmlspecialchars($siteName); ?> collects, uses, stores and deletes personal data, and how to exercise your rights under Ghana's Data Protection Act, 2012 (Act 843).">
<link rel="canonical" href="<?php echo htmlspecialchars($base); ?>/privacy.php">
<meta name="robots" content="index, follow">
<style>
    :root {
        --navy: #0b1b2b;
        --navy-soft: #12293f;
        --lime: #c2f542;
        --ink: #1e293b;
        --muted: #64748b;
        --line: #e2e8f0;
        --paper: #f8fafc;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
        margin: 0;
        background: var(--paper);
        color: var(--ink);
        font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        line-height: 1.7;
        font-size: 16px;
    }
    a { color: #0f5fa8; }
    a:focus-visible, button:focus-visible { outline: 3px solid #0f5fa8; outline-offset: 3px; }
    .top {
        background: var(--navy);
        color: #fff;
        padding: 18px 24px;
    }
    .top-inner {
        max-width: 1080px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }
    .brand {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        color: #fff;
        text-decoration: none;
        font-weight: 800;
        letter-spacing: -0.01em;
    }
    .brand-mark {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        background: var(--lime);
        color: var(--navy);
        display: grid;
        place-items: center;
        font-weight: 900;
    }
    .top nav a {
        color: #cbd5e1;
        text-decoration: none;
        font-size: 13.5px;
        font-weight: 600;
        margin-left: 18px;
    }
    .top nav a:hover { color: #fff; }
    .hero {
        background: linear-gradient(135deg, var(--navy) 0%, var(--navy-soft) 100%);
        color: #fff;
        padding: 56px 24px 48px;
    }
    .hero-inner { max-width: 1080px; margin: 0 auto; }
    .eyebrow {
        display: inline-block;
        font-size: 11.5px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        font-weight: 800;
        color: var(--lime);
        margin-bottom: 10px;
    }
    .hero h1 {
        margin: 0 0 12px;
        font-size: clamp(30px, 5vw, 46px);
        line-height: 1.12;
        letter-spacing: -0.03em;
    }
    .hero p { margin: 0; color: #cbd5e1; max-width: 720px; font-size: 15.5px; }
    .meta {
        margin-top: 18px;
        display: flex;
        gap: 10px 22px;
        flex-wrap: wrap;
        font-size: 12.5px;
        color: #94a3b8;
    }
    .meta strong { color: #e2e8f0; font-weight: 700; }
    .layout {
        max-width: 1080px;
        margin: -22px auto 0;
        padding: 0 24px 64px;
        display: grid;
        grid-template-columns: 250px minmax(0, 1fr);
        gap: 24px;
        align-items: start;
    }
    .toc {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 18px 18px 14px;
        position: sticky;
        top: 20px;
    }
    .toc h2 {
        font-size: 11.5px;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--muted);
        margin: 0 0 10px;
    }
    .toc ol { margin: 0; padding-left: 18px; }
    .toc li { margin: 5px 0; font-size: 13.5px; }
    .toc a { color: #334155; text-decoration: none; }
    .toc a:hover { color: #0f5fa8; text-decoration: underline; }
    .card {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 14px;
        padding: 28px 30px;
        margin-bottom: 18px;
    }
    .card h2 {
        margin: 0 0 12px;
        font-size: 21px;
        letter-spacing: -0.02em;
    }
    .card h3 {
        margin: 18px 0 6px;
        font-size: 14.5px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--muted);
    }
    .card p { margin: 0 0 12px; }
    .card p:last-child { margin-bottom: 0; }
    .card ul { margin: 0 0 12px; padding-left: 20px; }
    .card li { margin: 7px 0; font-size: 15px; }
    .table-wrap { overflow-x: auto; margin: 4px 0 14px; }
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        min-width: 560px;
    }
    th, td {
        text-align: left;
        vertical-align: top;
        padding: 10px 12px;
        border-bottom: 1px solid var(--line);
    }
    th {
        background: #f1f5f9;
        color: #334155;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    td:first-child { font-weight: 700; white-space: nowrap; }
    .note {
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        border-left: 4px solid #0284c7;
        border-radius: 10px;
        padding: 14px 16px;
        font-size: 14px;
    }
    .note strong { display: block; margin-bottom: 3px; }
    .cta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        background: var(--navy);
        color: #fff;
        border-radius: 14px;
        padding: 22px 24px;
        margin-top: 4px;
    }
    .cta p { margin: 0; color: #cbd5e1; font-size: 13.5px; max-width: 560px; }
    .cta a {
        display: inline-block;
        background: var(--lime);
        color: var(--navy);
        font-weight: 800;
        text-decoration: none;
        padding: 11px 18px;
        border-radius: 9px;
        font-size: 13.5px;
        white-space: nowrap;
    }
    footer {
        border-top: 1px solid var(--line);
        background: #fff;
        padding: 22px 24px;
        font-size: 12.5px;
        color: var(--muted);
    }
    .footer-inner {
        max-width: 1080px;
        margin: 0 auto;
        display: flex;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    @media (max-width: 860px) {
        .layout { grid-template-columns: 1fr; margin-top: 0; padding-top: 20px; }
        .toc { position: static; }
        .card { padding: 22px 18px; }
    }
    @media print {
        .top, .hero, .toc, footer, .cta { display: none; }
        .layout { grid-template-columns: 1fr; margin: 0; padding: 0; }
        .card { border: 0; padding: 0 0 16px; }
        body { background: #fff; font-size: 12pt; }
    }
</style>
</head>
<body>
<header class="top">
    <div class="top-inner">
        <a class="brand" href="index.php">
            <span class="brand-mark" aria-hidden="true">★</span>
            <span><?php echo htmlspecialchars($siteName); ?></span>
        </a>
        <nav aria-label="Primary">
            <a href="index.php">Home</a>
            <a href="companies.php">Businesses</a>
            <a href="account-deletion.php">Delete account</a>
        </nav>
    </div>
</header>

<section class="hero">
    <div class="hero-inner">
        <span class="eyebrow">Legal</span>
        <h1>Privacy Policy</h1>
        <p>This policy explains what personal data <?php echo htmlspecialchars($siteName); ?> collects, why we need it, who we share it with, and how you can get it corrected or deleted.</p>
        <div class="meta">
            <span><strong>Last updated:</strong> <?php echo htmlspecialchars($updated); ?></span>
            <span><strong>Applies to:</strong> this website, the mobile apps and the business dashboard</span>
            <span><strong>Governing law:</strong> Data Protection Act, 2012 (Act 843), Ghana</span>
        </div>
    </div>
</section>

<main class="layout">
    <aside class="toc" aria-label="On this page">
        <h2>On this page</h2>
        <ol>
            <?php foreach ($sections as $id => $label): ?>
                <li><a href="#<?php echo $id; ?>"><?php echo htmlspecialchars($label); ?></a></li>
            <?php endforeach; ?>
        </ol>
    </aside>

    <div>
        <section class="card" id="who">
            <h2>1. Who we are</h2>
            <p><?php echo htmlspecialchars($siteName); ?> is a customer-feedback and review platform used by businesses, mainly in Ghana, to collect verified feedback and respond to it. We provide the platform; each business decides what it asks its customers and how it follows up.</p>
            <p>For the purposes of Act 843, <?php echo htmlspecialchars($siteName); ?> is the <strong>data controller</strong> for account, device and platform-usage data. A business using the platform is the controller of the customer records it adds to its own workspace.</p>
            <p>Questions about this policy or a data request: <a href="mailto:<?php echo htmlspecialchars($privacyEmail); ?>"><?php echo htmlspecialchars($privacyEmail); ?></a>. General support: <a href="mailto:<?php echo htmlspecialchars($contactEmail); ?>"><?php echo htmlspecialchars($contactEmail); ?></a>.</p>
        </section>

        <section class="card" id="data">
            <h2>2. What we collect</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th scope="col">Category</th><th scope="col">Examples</th><th scope="col">Source</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Account</td><td>Name, email address, phone number, password (stored only as a salted PBKDF2 hash), profile photo, Google account identifier if you sign in with Google</td><td>You</td></tr>
                        <tr><td>Business profile</td><td>Business name, category, address, map pin, hours, contact details, logo</td><td>Business owner or staff</td></tr>
                        <tr><td>Reviews</td><td>Star rating, category scores (service, cleanliness, value, speed), review text, photos you attach, helpful votes, business replies</td><td>You, or a business replying to you</td></tr>
                        <tr><td>Verification</td><td>Whether a review came from a printed QR code or a receipt code, the code's business and time window, and a yes/no verified flag</td><td>Generated when you scan a code</td></tr>
                        <tr><td>Device and notifications</td><td>Hashed device identifier, platform, app version, push token, notification read state</td><td>Your device</td></tr>
                        <tr><td>Technical</td><td>IP address and a hashed network identifier, user agent, timestamps, rate-limit counters</td><td>Your requests</td></tr>
                        <tr><td>Reports and moderation</td><td>The review you reported, the reason you chose, optional details, and the outcome</td><td>You, a business, or an automated abuse check</td></tr>
                        <tr><td>Billing (businesses)</td><td>Plan, invoice status, transaction reference. Card and mobile-money details are handled by the payment provider and never stored on our servers.</td><td>Business owner and payment provider</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="note"><strong>We do not collect your precise location.</strong> A business may enable distance search, but the coordinates you send are used to sort results for that request and are not kept as a location history. QR verification proves a code was scanned; it does not track where you go afterwards.</p>
        </section>

        <section class="card" id="why">
            <h2>3. Why we use it</h2>
            <ul>
                <li><strong>To provide the service</strong> — creating your account, publishing your review, showing business pages, delivering replies. Legal basis: performance of a contract.</li>
                <li><strong>To keep reviews trustworthy</strong> — confirming a visit with a QR or receipt code, one review per customer per business in a rolling window, duplicate-text and device-clustering checks, and human moderation. Legal basis: our legitimate interest in preventing fake reviews, and your consent where verification is optional.</li>
                <li><strong>To notify you</strong> — a push or in-app message when a business replies to your review, or when moderation changes a review you reported. Legal basis: contract and legitimate interest. Marketing messages require your consent and can be turned off at any time.</li>
                <li><strong>To keep the platform safe</strong> — rate limiting, abuse detection, fraud prevention and audit logs. Legal basis: legitimate interest and legal obligation.</li>
                <li><strong>To bill businesses</strong> — invoices, receipts and tax records. Legal basis: contract and legal obligation.</li>
            </ul>
            <p>We do not sell personal data. We do not use your review text to build advertising audiences, and we do not share your email address with other customers.</p>
        </section>

        <section class="card" id="sharing">
            <h2>4. Who we share it with</h2>
            <ul>
                <li><strong>The business you reviewed</strong> sees your review, the name you chose to display, your rating and any photos you attached. Your email address is visible to that business so it can reply or resolve a problem; it is never shown publicly.</li>
                <li><strong>Service providers</strong> that host the platform, send email or SMS one-time codes, deliver push notifications, and process payments. They may only use the data to provide that service to us.</li>
                <li><strong>Google</strong>, only if you choose Google Sign-In, to confirm your identity. We receive your name, email, account identifier and profile photo from Google.</li>
                <li><strong>Authorities or courts</strong> where the law requires it, or where disclosure is necessary to investigate fraud or protect someone's safety.</li>
                <li><strong>A successor organisation</strong> if the platform is merged or sold, subject to this policy continuing to apply.</li>
            </ul>
            <p>Some providers process data outside Ghana. Where that happens we rely on appropriate safeguards (such as standard contractual clauses) and transfer only what the service needs.</p>
        </section>

        <section class="card" id="retention">
            <h2>5. How long we keep it</h2>
            <ul>
                <li><strong>Account data</strong> is kept while your account is open. If you delete your account, we anonymise it: name, email, phone, password hash, Google identifier, avatar and device tokens are removed or replaced.</li>
                <li><strong>Reviews</strong> stay attached to the business's rating history, but after account deletion they no longer carry your name, email, account id or device identifier.</li>
                <li><strong>One-time codes</strong> expire after 10 minutes and are deleted within 24 hours. Rate-limit counters expire automatically after their window.</li>
                <li><strong>Moderation records</strong> (reports, disputes and decisions) are kept while a case is open and for a limited period afterwards to prevent repeat abuse and to show that a decision was made.</li>
                <li><strong>Billing records</strong> are kept for as long as tax and accounting law requires.</li>
            </ul>
        </section>

        <section class="card" id="rights">
            <h2>6. Your rights</h2>
            <p>Under Act 843 you have the right to:</p>
            <ul>
                <li>ask whether we hold personal data about you and receive a copy of it;</li>
                <li>have inaccurate data corrected;</li>
                <li>ask us to delete your account and the personal data attached to it;</li>
                <li>object to processing based on legitimate interests, or ask us to restrict it;</li>
                <li>withdraw consent you previously gave (for example, for marketing messages);</li>
                <li>receive the data you supplied in a structured, commonly used format.</li>
            </ul>
            <p>You can delete your account at any time from the app (Account → Delete account) or from this website at <a href="account-deletion.php">account-deletion.php</a>. Deletion is confirmed with a one-time code sent to your email or phone, so someone with only your email address cannot delete your account.</p>
            <p>For any other request, email <a href="mailto:<?php echo htmlspecialchars($privacyEmail); ?>"><?php echo htmlspecialchars($privacyEmail); ?></a> from the address on the account. We respond within 30 days. If you are not satisfied with our answer you can complain to the <a href="https://dataprotection.org.gh/" target="_blank" rel="noopener noreferrer">Data Protection Commission of Ghana</a>.</p>
            <div class="cta">
                <p>Want your account and personal details removed? The deletion page walks you through a one-time code and confirms when it is done.</p>
                <a href="account-deletion.php">Delete your account</a>
            </div>
        </section>

        <section class="card" id="security">
            <h2>7. Security</h2>
            <p>Passwords are stored as salted PBKDF2-HMAC-SHA256 hashes with a high iteration count — never as plain text. Traffic is encrypted with HTTPS in production. Access to moderation, billing and support tools is restricted by role, and signing out everywhere invalidates existing sessions.</p>
            <p>QR codes are signed and can be limited by time and number of uses. A per-visit code is stored only as a hash, so a leaked database cannot be used to mint new codes. No system is perfectly secure: if you believe your account has been compromised, change your password and contact us.</p>
        </section>

        <section class="card" id="cookies">
            <h2>8. Cookies and local storage</h2>
            <p>We use a session cookie to keep you signed in and a small amount of local storage for preferences such as dark mode and the last filter you used. These are necessary for the service to work.</p>
            <p>If analytics are enabled, they are configured not to collect review text, email addresses or phone numbers. We do not run third-party advertising cookies on review pages.</p>
        </section>

        <section class="card" id="children">
            <h2>9. Children</h2>
            <p>The platform is intended for people aged 18 and over. We do not knowingly collect personal data from children. If you believe a child has created an account, contact us and we will remove it.</p>
        </section>

        <section class="card" id="changes">
            <h2>10. Changes to this policy</h2>
            <p>We may update this policy when the product or the law changes. Material changes will be announced in the app or by email before they take effect. The date at the top of this page always shows the current version, and earlier versions are available on request.</p>
        </section>

        <section class="card" id="contact">
            <h2>11. Contact us</h2>
            <p><strong>Privacy requests:</strong> <a href="mailto:<?php echo htmlspecialchars($privacyEmail); ?>"><?php echo htmlspecialchars($privacyEmail); ?></a><br>
            <strong>Support:</strong> <a href="mailto:<?php echo htmlspecialchars($contactEmail); ?>"><?php echo htmlspecialchars($contactEmail); ?></a></p>
            <p>We may ask for proof that you control the account before we act on a request. That check uses the same one-time code mechanism as sign-in, and we do not keep the code after it expires.</p>
        </section>
    </div>
</main>

<footer>
    <div class="footer-inner">
        <span>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($siteName); ?>. All rights reserved.</span>
        <span><a href="index.php">Home</a> · <a href="privacy.php">Privacy</a> · <a href="account-deletion.php">Delete account</a></span>
    </div>
</footer>
</body>
</html>
