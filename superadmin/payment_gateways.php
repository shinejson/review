<?php
/**
 * ============================================================
 *  Super Admin — Payment integrations
 * ============================================================
 *  The platform owner's control panel for how tenants pay:
 *
 *    • Paystack and Flutterwave API keys (test / live)
 *    • a manual bank-transfer profile
 *    • which integration is offered by default
 *    • the webhook URL each provider dashboard needs
 *    • a connection test that proves the keys work before going live
 *
 *  Only a super admin with the `gateways` permission can open this
 *  page, and the secret keys are never echoed back into the markup —
 *  a blank key field means "keep the stored value".
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/payments.php';

requireSuperAdminLogin();
require_sa_permission('gateways');

pay_ensure_schema($conn);

$actor = isset($_SESSION['super_admin_username']) ? (string) $_SESSION['super_admin_username'] : 'Super Admin';

/** Write a single value into the settings table. */
if (!function_exists('pay_set_setting')) {
    function pay_set_setting($conn, $key, $value)
    {
        $exists = (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM settings WHERE setting_key = '" . $conn->real_escape_string($key) . "'",
            0,
            'settings'
        );
        if ($exists > 0) {
            $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->bind_param('ss', $value, $key);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
            $stmt->close();
        }
        return true;
    }
}

/* ============================================================
   POST handlers
   ============================================================ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('payment_gateways.php');
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $key    = isset($_POST['gateway_key']) ? preg_replace('/[^a-z_]/', '', strtolower((string) $_POST['gateway_key'])) : '';
    $gateway = $key !== '' ? pay_gateway($conn, $key) : null;

    if (!$gateway) {
        sa_flash('error', 'That payment integration is not installed.');
        redirect('payment_gateways.php');
    }

    /* ---- save the credentials ---- */
    if ($action === 'save_gateway') {
        $data = [
            'display_name' => trim((string) ($_POST['display_name'] ?? $gateway['display_name'])),
            'is_enabled'   => !empty($_POST['is_enabled']) ? 1 : 0,
            'mode'         => ($_POST['mode'] ?? 'test') === 'live' ? 'live' : 'test',
            'currency'     => strtoupper(substr(trim((string) ($_POST['currency'] ?? $gateway['currency'])), 0, 8)),
        ];

        // A blank secret field means "leave the stored key alone" — that way
        // saving the form never wipes a key the owner cannot see.
        foreach (['public_key', 'secret_key', 'webhook_secret'] as $field) {
            if (!array_key_exists($field, $_POST)) {
                continue;
            }
            $value = trim((string) $_POST[$field]);
            if ($value === '') {
                continue;
            }
            $data[$field] = $value;
        }
        foreach (['bank_name', 'account_name', 'account_number', 'instructions'] as $field) {
            if (array_key_exists($field, $_POST)) {
                $data[$field] = trim((string) $_POST[$field]);
            }
        }

        // Turning an integration on without its credentials would leave
        // tenants staring at a broken checkout, so refuse it up front.
        if ($data['is_enabled']) {
            $probe = array_merge($gateway, $data);
            $blockers = pay_gateway_blockers(array_merge($probe, ['is_enabled' => 1]));
            if ($blockers) {
                pay_gateway_save($conn, $key, array_merge($data, ['is_enabled' => 0]));
                sa_flash('warning', 'Saved, but the integration stayed switched off: ' . implode(' ', $blockers));
                redirect('payment_gateways.php#gw-' . $key);
            }
        }

        pay_gateway_save($conn, $key, $data);

        if (!empty($_POST['make_default'])) {
            pay_set_setting($conn, 'default_gateway', $key);
        }

        sa_flash('success', $gateway['display_name'] . ' settings saved.');
        redirect('payment_gateways.php#gw-' . $key);
    }

    /* ---- test the connection ---- */
    if ($action === 'test_gateway') {
        $result = pay_driver_test(array_merge($gateway, [
            'is_enabled' => !empty($gateway['is_enabled']) ? 1 : 0,
        ]));
        @$conn->query(
            "UPDATE payment_gateways
                SET connection_status = '" . ($result['ok'] ? 'ok' : 'failed') . "',
                    connection_note = '" . pay_sql_escape($conn, substr($result['message'], 0, 250)) . "',
                    last_checked_at = NOW()
              WHERE gateway_key = '" . pay_sql_escape($conn, $key) . "'"
        );
        pay_log_event($conn, [
            'gateway_key' => $key,
            'event_type'  => 'gateway.test',
            'payload'     => $result['message'],
            'signature_valid' => $result['ok'] ? 1 : 0,
        ]);
        sa_flash($result['ok'] ? 'success' : 'error', $gateway['display_name'] . ': ' . $result['message']);
        redirect('payment_gateways.php#gw-' . $key);
    }

    /* ---- make this the default integration ---- */
    if ($action === 'make_default') {
        pay_set_setting($conn, 'default_gateway', $key);
        sa_flash('success', $gateway['display_name'] . ' is now the default payment method.');
        redirect('payment_gateways.php');
    }

    /* ---- switch it on or off ---- */
    if ($action === 'toggle_gateway') {
        $on = !empty($_POST['enable']);
        if ($on) {
            $blockers = pay_gateway_blockers(array_merge($gateway, ['is_enabled' => 1]));
            if ($blockers) {
                sa_flash('error', 'Cannot switch on ' . $gateway['display_name'] . ': ' . implode(' ', $blockers));
                redirect('payment_gateways.php#gw-' . $key);
            }
        }
        pay_gateway_save($conn, $key, ['is_enabled' => $on ? 1 : 0]);
        sa_flash('success', $gateway['display_name'] . ($on ? ' is live for tenants.' : ' has been switched off.'));
        redirect('payment_gateways.php#gw-' . $key);
    }

    redirect('payment_gateways.php');
}

/* ============================================================
   Data
   ============================================================ */
$gateways     = pay_gateways($conn);
$default_key  = sa_setting($conn, 'default_gateway', '');
$catalog      = pay_gateway_catalog();
$currency     = sa_currency_code($conn);

$live_count   = 0;
$ready_count  = 0;
$blocked      = [];
foreach ($gateways as $key => $gw) {
    if (!empty($gw['is_enabled'])) {
        $live_count++;
    }
    if ($gw['ready']) {
        $ready_count++;
    } else {
        foreach (pay_gateway_blockers($gw) as $blocker) {
            $blocked[] = $gw['display_name'] . ': ' . $blocker;
        }
    }
}

/* Last 6 gateway events, so a failed test is visible without leaving the page */
$events = pay_events($conn, 6);

/* ============================================================
   Page
   ============================================================ */
$robots       = 'noindex, nofollow';
$pageTitle    = 'Payment integrations';
$pageHeading  = 'Payment integrations';
$pageSubtitle = 'Configure how tenants pay for their subscription.';
$activePage   = 'gateways';
$BASE         = '../';
$extraCss     = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Payment integrations</span>
        </div>
        <h2>Payment integrations</h2>
        <p>Connections, keys and the manual bank-transfer profile tenants see at checkout.</p>
    </div>
    <div class="sa-head-actions">
        <a class="sa-btn sa-btn-ghost" href="finance.php">
            <?php echo sa_icon('dollar'); ?> Financial centre
        </a>
        <a class="sa-btn sa-btn-ghost" href="subscriptions.php">
            <?php echo sa_icon('card'); ?> Subscriptions
        </a>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<!-- ============ POSTURE ============ -->
<div class="sa-grid sa-kpis sa-anim">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Live integrations</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('zap'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($live_count)); ?> <small>of <?php echo sa_e(sa_num(count($gateways))); ?></small></div>
        <div class="sa-kpi-note"><?php echo $live_count ? 'Tenants can pay online right now' : 'No tenant can pay online yet'; ?></div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Default method</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('check-circle'); ?></span>
        </div>
        <div class="sa-kpi-value" style="font-size:20px"><?php echo sa_e($default_key !== '' ? pay_gateway_label($default_key) : 'Not set'); ?></div>
        <div class="sa-kpi-note">Preselected at checkout when it is switched on</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Ready to charge</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('shield'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($ready_count)); ?></div>
        <div class="sa-kpi-note">Enabled with every required credential</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-violet);--kpi-soft:var(--sa-violet-soft);--kpi-line:var(--sa-violet-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Billing currency</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('dollar'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e($currency); ?></div>
        <div class="sa-kpi-note"><a href="settings.php">Change in platform settings</a></div>
    </article>
</div>

<?php if ($blocked): ?>
<div class="sa-alert sa-alert-warning" data-sa-alert>
    <?php echo sa_icon('alert'); ?>
    <div>
        <strong>Some integrations still need attention</strong>
        <ul style="margin:6px 0 0;padding-left:18px">
<?php foreach ($blocked as $line): ?>
            <li><?php echo sa_e($line); ?></li>
<?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($live_count === 0): ?>
<div class="sa-alert sa-alert-info" data-sa-alert>
    <?php echo sa_icon('info'); ?>
    <div>
        <strong>No online payments yet</strong>
        Paste a Paystack or Flutterwave secret key below and switch it on. Until then tenants can still
        request a plan change, and you can record what they paid from the financial centre.
    </div>
</div>
<?php endif; ?>

<!-- ============ INTEGRATIONS ============ -->
<?php foreach ($gateways as $key => $gw): ?>
<?php
    $meta      = isset($catalog[$key]) ? $catalog[$key] : [];
    $is_default = ($default_key === $key);
    $is_manual  = ($gw['driver'] === 'manual');
    $has_secret = trim((string) $gw['secret_key']) !== '';
    $blockers   = pay_gateway_blockers($gw);
?>
<section class="sa-card sa-mt sa-card-collapsed" id="gw-<?php echo sa_e($key); ?>" data-card-id="gw-<?php echo sa_e($key); ?>">
    <div class="sa-card-head">
        <div>
            <div style="display:flex;align-items:center;gap:10px">
                <?php echo pay_gateway_mark($key, 34); ?>
                <div>
                    <h3 style="margin:0"><?php echo sa_e($gw['display_name']); ?></h3>
                    <p style="margin:2px 0 0">
                        <?php echo sa_e(isset($meta['methods']) ? $meta['methods'] : ''); ?>
                        <?php if (!empty($meta['region'])): ?> &middot; <?php echo sa_e($meta['region']); ?><?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="sa-card-head-actions">
<?php if ($is_default): ?>
            <span class="sa-badge sa-badge-lime">Default</span>
<?php endif; ?>
<?php if (!empty($gw['is_enabled'])): ?>
            <span class="sa-badge sa-badge-active">Live</span>
<?php else: ?>
            <span class="sa-badge sa-badge-inactive">Off</span>
<?php endif; ?>
<?php if (!empty($gw['connection_status']) && $gw['connection_status'] !== 'unverified'): ?>
            <span class="sa-badge <?php echo $gw['connection_status'] === 'ok' ? 'sa-badge-active' : 'sa-badge-cancelled'; ?>">
                <?php echo $gw['connection_status'] === 'ok' ? 'Tested OK' : 'Test failed'; ?>
            </span>
<?php endif; ?>
            <button type="button" class="sa-card-toggle" aria-label="Expand card" title="Expand" aria-expanded="false"><?php echo sa_icon('chevron-up'); ?></button>
        </div>
    </div>

    <div class="sa-card-pad">
        <div class="sa-grid sa-split-2-1">
            <!-- ---------- credentials ---------- -->
            <form method="POST" action="payment_gateways.php" class="sa-form">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="save_gateway">
                <input type="hidden" name="gateway_key" value="<?php echo sa_e($key); ?>">

                <div class="sa-form-grid">
                    <div class="sa-field">
                        <label for="name-<?php echo sa_e($key); ?>">Display name</label>
                        <input type="text" id="name-<?php echo sa_e($key); ?>" name="display_name"
                               value="<?php echo sa_e($gw['display_name']); ?>" maxlength="100">
                        <span class="sa-hint">Shown to tenants on the checkout screen.</span>
                    </div>

                    <div class="sa-field">
                        <label for="cur-<?php echo sa_e($key); ?>">Currency</label>
                        <input type="text" id="cur-<?php echo sa_e($key); ?>" name="currency"
                               value="<?php echo sa_e($gw['currency']); ?>" maxlength="8" class="sa-field-sm">
                        <span class="sa-hint">
<?php if (!empty($meta['currencies'])): ?>
                            <?php echo sa_e($key); ?> settles in <?php echo sa_e(implode(', ', $meta['currencies'])); ?>.
<?php else: ?>
                            Free text — used on the invoice tenants receive.
<?php endif; ?>
                        </span>
                    </div>

<?php if (!$is_manual): ?>
                    <div class="sa-field">
                        <label for="pub-<?php echo sa_e($key); ?>">Public key</label>
                        <input type="text" id="pub-<?php echo sa_e($key); ?>" name="public_key"
                               value="<?php echo $gw['public_key'] !== '' ? sa_e($gw['public_key']) : ''; ?>"
                               placeholder="<?php echo $has_secret ? 'Stored — paste to replace' : 'pk_test_…'; ?>"
                               autocomplete="off" spellcheck="false" class="sa-mono">
                    </div>

                    <div class="sa-field">
                        <label for="sec-<?php echo sa_e($key); ?>">Secret key</label>
                        <input type="password" id="sec-<?php echo sa_e($key); ?>" name="secret_key"
                               value="" placeholder="<?php echo $has_secret ? '•••••••• stored — leave blank to keep' : 'sk_test_…'; ?>"
                               autocomplete="new-password" spellcheck="false" class="sa-mono">
                        <span class="sa-hint">Never shown again once saved, and never sent to the browser.</span>
                    </div>

                    <div class="sa-field">
                        <label for="wh-<?php echo sa_e($key); ?>">
                            <?php echo $gw['driver'] === 'flutterwave' ? 'Webhook secret hash' : 'Webhook signing secret'; ?>
                        </label>
                        <input type="password" id="wh-<?php echo sa_e($key); ?>" name="webhook_secret"
                               value="" placeholder="<?php echo trim((string) $gw['webhook_secret']) !== '' ? '•••••••• stored — leave blank to keep' : 'Paste the value from the dashboard'; ?>"
                               autocomplete="new-password" spellcheck="false" class="sa-mono">
                        <span class="sa-hint"><?php echo sa_e(isset($meta['webhook_help']) ? $meta['webhook_help'] : ''); ?></span>
                    </div>
<?php else: ?>
                    <div class="sa-field">
                        <label for="bank-<?php echo sa_e($key); ?>">Bank name</label>
                        <input type="text" id="bank-<?php echo sa_e($key); ?>" name="bank_name"
                               value="<?php echo sa_e($gw['bank_name']); ?>" maxlength="140" placeholder="e.g. Ecobank Ghana">
                    </div>

                    <div class="sa-field">
                        <label for="acctname-<?php echo sa_e($key); ?>">Account name</label>
                        <input type="text" id="acctname-<?php echo sa_e($key); ?>" name="account_name"
                               value="<?php echo sa_e($gw['account_name']); ?>" maxlength="140">
                    </div>

                    <div class="sa-field">
                        <label for="acctno-<?php echo sa_e($key); ?>">Account number</label>
                        <input type="text" id="acctno-<?php echo sa_e($key); ?>" name="account_number"
                               value="<?php echo sa_e($gw['account_number']); ?>" maxlength="80" class="sa-mono">
                    </div>

                    <div class="sa-field" style="grid-column:1/-1">
                        <label for="instr-<?php echo sa_e($key); ?>">Payment instructions</label>
                        <textarea id="instr-<?php echo sa_e($key); ?>" name="instructions" rows="4"
                                  style="width:100%"><?php echo sa_e($gw['instructions']); ?></textarea>
                        <span class="sa-hint">Shown on the tenant's invoice. Tell them to quote the invoice number.</span>
                    </div>
<?php endif; ?>
                </div>

                <div class="sa-form-foot">
                    <label class="sa-switch">
                        <input type="checkbox" name="is_enabled" value="1" <?php echo !empty($gw['is_enabled']) ? 'checked' : ''; ?>>
                        <span class="sa-switch-track"></span>
                        <span class="sa-switch-text">Offer this method to tenants</span>
                    </label>

                    <label class="sa-switch">
                        <input type="checkbox" name="make_default" value="1" <?php echo $is_default ? 'checked' : ''; ?>>
                        <span class="sa-switch-track"></span>
                        <span class="sa-switch-text">Default at checkout</span>
                    </label>

                    <span class="sa-field" style="max-width:190px">
                        <label for="mode-<?php echo sa_e($key); ?>">Mode</label>
                        <select id="mode-<?php echo sa_e($key); ?>" name="mode" class="sa-inline-select">
                            <option value="test" <?php echo $gw['mode'] === 'test' ? 'selected' : ''; ?>>Test (sandbox)</option>
                            <option value="live" <?php echo $gw['mode'] === 'live' ? 'selected' : ''; ?>>Live (real money)</option>
                        </select>
                    </span>

                    <button type="submit" class="sa-btn sa-btn-primary">
                        <?php echo sa_icon('save'); ?> Save integration
                    </button>
                </div>
            </form>

            <!-- ---------- setup side panel ---------- -->
            <div>
<?php if ($is_manual): ?>
                <div class="sa-card" style="margin-bottom:14px">
                    <div class="sa-card-head">
                        <div>
                            <h3>How manual payments work</h3>
                        </div>
                    </div>
                    <div class="sa-card-pad">
                        <ol class="sa-checklist">
                            <li>Tenant opens a renewal or upgrade and chooses bank transfer.</li>
                            <li>They see the details you saved here and pay at their bank or wallet.</li>
                            <li>They mark the invoice as paid from their workspace.</li>
                            <li>You confirm it in the financial centre and the plan activates.</li>
                        </ol>
                    </div>
                </div>
<?php else: ?>
                <div class="sa-card" style="margin-bottom:14px">
                    <div class="sa-card-head">
                        <div>
                            <h3>Webhook URL</h3>
                            <p>Paste this into the <?php echo sa_e($gw['display_name']); ?> dashboard</p>
                        </div>
                    </div>
                    <div class="sa-card-pad">
                        <div class="sa-field">
                            <label for="hook-<?php echo sa_e($key); ?>">Endpoint for <?php echo sa_e($gw['display_name']); ?></label>
                            <input type="text" id="hook-<?php echo sa_e($key); ?>" readonly
                                   class="sa-mono" data-sa-select-on-focus
                                   value="<?php echo sa_e(pay_gateway_webhook_url($conn, $key)); ?>">
                            <span class="sa-hint">Select the field to copy the address.</span>
                        </div>
                        <ol class="sa-checklist">
                            <li>Open your <?php echo sa_e($gw['display_name']); ?> dashboard → Settings → API keys &amp; webhooks.</li>
                            <li>Add the URL above as the webhook endpoint.</li>
                            <li>Copy the signing <?php echo $gw['driver'] === 'flutterwave' ? 'hash' : 'secret'; ?> into this page and save.</li>
                            <li>Send a test event — it appears in the activity list below.</li>
                        </ol>
                    </div>
                </div>
<?php endif; ?>

                <div class="sa-card">
                    <div class="sa-card-head">
                        <div>
                            <h3>Connection</h3>
                            <p>Last test result</p>
                        </div>
                    </div>
                    <div class="sa-card-pad">
                        <div class="sa-kv-list">
                            <div class="sa-kv">
                                <span>Status</span>
                                <span><?php echo pay_badge(!empty($gw['is_enabled']) ? 'confirmed' : 'failed'); ?></span>
                            </div>
                            <div class="sa-kv">
                                <span>Mode</span>
                                <span><?php echo $gw['mode'] === 'live' ? 'Live' : 'Test'; ?></span>
                            </div>
                            <div class="sa-kv">
                                <span>Checked</span>
                                <span><?php echo sa_e(!empty($gw['last_checked_at']) ? sa_time_ago($gw['last_checked_at']) : 'Never'); ?></span>
                            </div>
                        </div>
<?php if (!empty($gw['connection_note'])): ?>
                        <p class="sa-hint" style="margin-top:10px"><?php echo sa_e($gw['connection_note']); ?></p>
<?php endif; ?>
<?php if ($blockers): ?>
                        <div class="sa-chips" style="margin-top:10px">
<?php foreach ($blockers as $blocker): ?>
                            <span class="sa-chip"><?php echo sa_e($blocker); ?></span>
<?php endforeach; ?>
                        </div>
<?php endif; ?>
                        <div class="sa-row-actions" style="margin-top:12px">
                            <form method="POST" action="payment_gateways.php" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="test_gateway">
                                <input type="hidden" name="gateway_key" value="<?php echo sa_e($key); ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost">
                                    <?php echo sa_icon('refresh'); ?> Test connection
                                </button>
                            </form>
                            <form method="POST" action="payment_gateways.php" style="display:inline">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_gateway">
                                <input type="hidden" name="gateway_key" value="<?php echo sa_e($key); ?>">
                                <input type="hidden" name="enable" value="<?php echo !empty($gw['is_enabled']) ? '0' : '1'; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm <?php echo !empty($gw['is_enabled']) ? 'sa-btn-ghost' : 'sa-btn-primary'; ?>">
                                    <?php echo sa_icon('zap'); ?> <?php echo !empty($gw['is_enabled']) ? 'Switch off' : 'Switch on'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endforeach; ?>

<!-- ============ RECENT INTEGRATION ACTIVITY ============ -->
<section class="sa-card sa-mt sa-card-collapsed" data-card-id="integration_activity">
    <div class="sa-card-head">
        <div>
            <h3>Integration activity</h3>
            <p>The last few webhooks, callbacks and connection tests</p>
        </div>
        <div class="sa-card-head-actions">
            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="finance.php#activity">
                <?php echo sa_icon('activity'); ?> Full activity log
            </a>
            <button type="button" class="sa-card-toggle" aria-label="Expand card" title="Expand" aria-expanded="false"><?php echo sa_icon('chevron-up'); ?></button>
        </div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table">
            <thead scope="col">
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Integration</th>
                    <th scope="col">Event</th>
                    <th scope="col">Signature</th>
                    <th scope="col">Detail</th>
                </tr>
            </thead>
            <tbody>
<?php if (!$events): ?>
                <tr data-static>
                    <td colspan="5">
                        <div class="sa-empty">
                            <?php echo sa_icon('activity'); ?>
                            <strong>Nothing logged yet</strong>
                            <p>Webhook deliveries and connection tests appear here as they arrive.</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($events as $event): ?>
                <tr>
                    <td><?php echo sa_e(sa_time_ago($event['created_at'])); ?></td>
                    <td><?php echo sa_e(pay_gateway_label($event['gateway_key'])); ?></td>
                    <td><span class="sa-mono"><?php echo sa_e($event['event_type']); ?></span></td>
                    <td>
<?php if (!empty($event['signature_valid'])): ?>
                        <span class="sa-badge sa-badge-active">Verified</span>
<?php else: ?>
                        <span class="sa-badge sa-badge-inactive">Unverified</span>
<?php endif; ?>
                    </td>
                    <td><?php echo sa_e(pay_trim((string) $event['payload'], 90)); ?></td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/_shell_footer.php'; ?>
