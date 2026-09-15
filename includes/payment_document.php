<?php
/**
 * ============================================================
 *  Optibiz — Printable invoice / receipt document
 * ============================================================
 *  One renderer shared by both panels so a tenant and the platform
 *  owner always see the same document:
 *
 *      superadmin/invoice_view.php   (platform owner)
 *      admin/invoice_view.php        (workspace)
 *
 *  A `paid` invoice prints as an official receipt; anything else
 *  prints as an invoice with the payment instructions attached.
 *
 *  Usage:
 *      require_once __DIR__ . '/payment_document.php';
 *      echo pay_render_document($conn, $invoice, [
 *          'back_url' => 'finance.php',
 *          'back_label' => 'Back to financials',
 *          'pay_url' => 'payment_checkout.php?invoice=12',   // optional
 *      ]);
 */

require_once __DIR__ . '/payments.php';

if (!function_exists('pay_render_document')) {
    /**
     * @param  array $invoice Row from pay_invoice() (joined with tenant + plan)
     * @param  array $opts    back_url, back_label, pay_url, show_print
     * @return string Complete HTML document
     */
    function pay_render_document($conn, array $invoice, array $opts = [])
    {
        if (!$invoice) {
            return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Not found</title></head>'
                . '<body style="font-family:sans-serif;padding:40px"><h1>Invoice not found</h1>'
                . '<p>This invoice may have been deleted.</p></body></html>';
        }

        $site_name  = sa_setting($conn, 'site_name', 'Optibiz');
        $logo_url   = sa_platform_logo($conn);
        $symbol     = sa_currency_symbol($conn);
        $back_url   = isset($opts['back_url']) ? (string) $opts['back_url'] : '';
        $back_label = isset($opts['back_label']) ? (string) $opts['back_label'] : 'Back';
        $pay_url    = isset($opts['pay_url']) ? (string) $opts['pay_url'] : '';
        $show_print = !isset($opts['show_print']) || $opts['show_print'];

        $is_paid    = ($invoice['status'] === 'paid');
        $is_dead    = in_array($invoice['status'], ['cancelled'], true);
        $title      = $is_paid ? 'Official Receipt' : 'Invoice';
        $currency   = $invoice['currency'] !== '' ? (string) $invoice['currency'] : strtoupper(sa_currency_code($conn));
        $money      = function ($value) use ($invoice, $symbol) {
            return $symbol . number_format((float) $value, 2);
        };

        /* Payments already recorded against this invoice */
        $payments = sa_query(
            $conn,
            "SELECT * FROM subscription_payments WHERE invoice_id = " . (int) $invoice['id'] . " ORDER BY id ASC",
            ['subscription_payments']
        );

        /* Which manual gateway to quote for bank details, if any */
        $manual = null;
        foreach (pay_enabled_gateways($conn) as $gw) {
            if ($gw['driver'] === 'manual') {
                $manual = $gw;
                break;
            }
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo sa_e($invoice['invoice_number']); ?> — <?php echo sa_e($site_name); ?></title>
    <style>
        :root {
            --primary: #0f2438;
            --accent: #84cc16;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-light: #f8fafc;
            --success: #15803d;
            --warning: #b45309;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f1f5f9;
            color: var(--text-main);
            line-height: 1.5;
            padding: 24px;
        }
        .doc-bar {
            max-width: 820px; margin: 0 auto 16px; display: flex; flex-wrap: wrap;
            gap: 10px; align-items: center; justify-content: space-between;
        }
        .doc-bar a, .doc-bar button {
            display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700;
            text-decoration: none; cursor: pointer; padding: 9px 16px; border-radius: 8px;
            border: 1px solid var(--border-color); background: #fff; color: var(--text-muted);
            transition: all 0.15s;
        }
        .doc-bar a:hover, .doc-bar button:hover { color: var(--text-main); background: var(--bg-light); }
        .doc-bar .is-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .doc-bar .is-primary:hover { background: #16324a; color: #fff; }
        .sheet {
            max-width: 820px; margin: 0 auto; background: #fff; border: 1px solid var(--border-color);
            border-radius: 14px; box-shadow: 0 10px 30px rgba(15, 36, 56, 0.08); overflow: hidden;
        }
        .sheet-head {
            padding: 32px 36px 26px; display: flex; justify-content: space-between; gap: 24px;
            align-items: flex-start; border-bottom: 2px solid var(--border-color);
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand img { max-height: 46px; width: auto; }
        .brand h1 { font-size: 21px; font-weight: 800; letter-spacing: -0.4px; }
        .brand p { font-size: 12px; color: var(--text-muted); }
        .doc-title { text-align: right; }
        .doc-title h2 { font-size: 19px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.4px; color: var(--primary); }
        .doc-title .doc-no { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 14px; font-weight: 700; margin-top: 4px; }
        .pill {
            display: inline-block; font-size: 11px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.6px; padding: 4px 11px; border-radius: 999px; margin-top: 8px;
            border: 1px solid;
        }
        .pill.paid { background: #dcfce7; color: var(--success); border-color: #bbf7d0; }
        .pill.open { background: #fef3c7; color: var(--warning); border-color: #fde68a; }
        .pill.dead { background: #f1f5f9; color: var(--text-muted); border-color: var(--border-color); }
        .sheet-body { padding: 30px 36px 36px; }
        .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 22px; margin-bottom: 28px; }
        .meta h4 { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted); margin-bottom: 7px; }
        .meta p { font-size: 13.5px; line-height: 1.65; }
        .meta strong { font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: var(--bg-light); text-align: left; padding: 11px 14px; font-size: 10.5px;
            font-weight: 800; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
        }
        th.num, td.num { text-align: right; }
        td { padding: 14px; font-size: 13.5px; border-bottom: 1px solid var(--border-color); vertical-align: top; }
        .item-name { font-weight: 700; }
        .item-sub { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
        .totals { display: flex; justify-content: flex-end; margin-top: 18px; }
        .totals table { width: 300px; }
        .totals td { padding: 7px 0; border-bottom: none; font-size: 13.5px; }
        .totals tr.grand td { padding-top: 12px; border-top: 2px solid var(--border-color); font-size: 18px; font-weight: 800; color: var(--primary); }
        .note {
            background: var(--bg-light); border: 1px solid var(--border-color); border-radius: 10px;
            padding: 15px 18px; font-size: 13px; color: var(--text-muted); margin-top: 24px;
        }
        .note strong { color: var(--text-main); }
        .note dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 14px; margin-top: 8px; }
        .note dt { font-weight: 700; color: var(--text-main); }
        .sheet-foot {
            border-top: 1px solid var(--border-color); padding: 18px 36px; font-size: 12px;
            color: var(--text-muted); display: flex; flex-wrap: wrap; gap: 10px;
            justify-content: space-between; align-items: center;
        }
        .stamp {
            display: inline-flex; align-items: center; gap: 7px; border: 2px dashed #22c55e; color: var(--success);
            padding: 6px 13px; border-radius: 8px; font-weight: 800; font-size: 11.5px;
            text-transform: uppercase; letter-spacing: 0.6px;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .doc-bar { display: none !important; }
            .sheet { box-shadow: none; border: none; border-radius: 0; max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="doc-bar">
<?php if ($back_url !== ''): ?>
    <a href="<?php echo sa_e($back_url); ?>">&larr; <?php echo sa_e($back_label); ?></a>
<?php else: ?>
    <span></span>
<?php endif; ?>
    <span style="display:flex;gap:10px">
<?php if ($pay_url !== '' && !$is_paid && !$is_dead): ?>
        <a class="is-primary" href="<?php echo sa_e($pay_url); ?>">Pay <?php echo sa_e($money($invoice['total'])); ?></a>
<?php endif; ?>
<?php if ($show_print): ?>
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
<?php endif; ?>
    </span>
</div>

<div class="sheet">
    <div class="sheet-head">
        <div class="brand">
<?php if ($logo_url): ?>
            <img src="<?php echo sa_e($logo_url); ?>" alt="<?php echo sa_e($site_name); ?>">
<?php endif; ?>
            <div>
                <h1><?php echo sa_e($site_name); ?></h1>
                <p>Subscription billing</p>
            </div>
        </div>
        <div class="doc-title">
            <h2><?php echo sa_e($title); ?></h2>
            <div class="doc-no"><?php echo sa_e($invoice['invoice_number']); ?></div>
<?php if ($is_paid): ?>
            <span class="pill paid">&#9679; Paid in full</span>
<?php elseif ($is_dead): ?>
            <span class="pill dead"><?php echo sa_e(pay_status_label($invoice['status'])); ?></span>
<?php else: ?>
            <span class="pill open"><?php echo sa_e(pay_status_label($invoice['status'])); ?></span>
<?php endif; ?>
        </div>
    </div>

    <div class="sheet-body">
        <div class="meta">
            <div>
                <h4>Billed to</h4>
                <p>
                    <strong><?php echo sa_e($invoice['company_name']); ?></strong><br>
<?php if (!empty($invoice['tenant_email'])): ?>
                    <?php echo sa_e($invoice['tenant_email']); ?><br>
<?php endif; ?>
<?php if (!empty($invoice['tenant_phone'])): ?>
                    <?php echo sa_e($invoice['tenant_phone']); ?><br>
<?php endif; ?>
<?php if (!empty($invoice['tenant_public_id'])): ?>
                    Workspace <?php echo sa_e($invoice['tenant_public_id']); ?>
<?php else: ?>
                    Workspace #<?php echo (int) $invoice['tenant_id']; ?>
<?php endif; ?>
                </p>
            </div>

            <div>
                <h4>Details</h4>
                <p>
                    <strong>Issued:</strong> <?php echo sa_e(sa_date($invoice['created_at'])); ?><br>
                    <strong>Due:</strong> <?php echo sa_e(sa_date($invoice['due_date'], 'M j, Y', 'On receipt')); ?><br>
<?php if ($is_paid): ?>
                    <strong>Paid:</strong> <?php echo sa_e(sa_date($invoice['paid_at'])); ?><br>
<?php endif; ?>
                    <strong>Currency:</strong> <?php echo sa_e($currency); ?>
                </p>
            </div>

            <div>
                <h4>Plan</h4>
                <p>
                    <strong><?php echo sa_e($invoice['plan_name'] !== null && $invoice['plan_name'] !== '' ? $invoice['plan_name'] : 'Subscription'); ?></strong><br>
                    <?php echo sa_e(ucfirst($invoice['purpose'])); ?> &middot;
                    <?php echo (int) $invoice['months']; ?> month<?php echo (int) $invoice['months'] === 1 ? '' : 's'; ?><br>
<?php if (!empty($invoice['confirmed_by'])): ?>
                    Confirmed by <?php echo sa_e($invoice['confirmed_by']); ?>
<?php elseif (!empty($invoice['issued_by'])): ?>
                    Issued by <?php echo sa_e($invoice['issued_by']); ?>
<?php endif; ?>
                </p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Period</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div class="item-name"><?php echo sa_e($invoice['subject']); ?></div>
                        <div class="item-sub">Platform access for <?php echo sa_e($invoice['company_name']); ?></div>
                    </td>
                    <td>
                        <?php echo (int) $invoice['months']; ?> month<?php echo (int) $invoice['months'] === 1 ? '' : 's'; ?>
<?php if (!empty($invoice['period_start']) || !empty($invoice['period_end'])): ?>
                        <div class="item-sub">
                            <?php echo sa_e(sa_date($invoice['period_start'])); ?> – <?php echo sa_e(sa_date($invoice['period_end'])); ?>
                        </div>
<?php endif; ?>
                    </td>
                    <td class="num"><strong><?php echo sa_e($money($invoice['amount'])); ?></strong></td>
                </tr>
            </tbody>
        </table>

        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal</td>
                    <td class="num"><?php echo sa_e($money($invoice['amount'])); ?></td>
                </tr>
<?php if ((float) $invoice['discount'] > 0): ?>
                <tr>
                    <td>Discount</td>
                    <td class="num">&minus;<?php echo sa_e($money($invoice['discount'])); ?></td>
                </tr>
<?php endif; ?>
<?php if ((float) $invoice['tax'] > 0): ?>
                <tr>
                    <td>Tax</td>
                    <td class="num"><?php echo sa_e($money($invoice['tax'])); ?></td>
                </tr>
<?php endif; ?>
                <tr class="grand">
                    <td><?php echo $is_paid ? 'Total paid' : 'Total due'; ?></td>
                    <td class="num"><?php echo sa_e($money($invoice['total'])); ?></td>
                </tr>
            </table>
        </div>

<?php if ($payments): ?>
        <div class="note">
            <strong>Payments recorded against this invoice</strong>
            <dl>
<?php foreach ($payments as $p): ?>
                <dt><?php echo sa_e($p['receipt_number']); ?></dt>
                <dd>
                    <?php echo sa_e($money($p['amount'])); ?> &middot;
                    <?php echo sa_e($p['payment_method']); ?>
                    &middot; <?php echo sa_e(pay_status_label($p['status'])); ?>
                    <?php if (!empty($p['gateway_reference'])): ?>
                        &middot; <span style="font-family:ui-monospace,monospace"><?php echo sa_e($p['gateway_reference']); ?></span>
                    <?php endif; ?>
                    &middot; <?php echo sa_e(sa_date($p['created_at'])); ?>
                </dd>
<?php endforeach; ?>
            </dl>
        </div>
<?php endif; ?>

<?php if (!$is_paid && !$is_dead && $manual): ?>
        <div class="note">
            <strong>How to pay</strong>
<?php if (!empty($manual['instructions'])): ?>
            <p style="margin-top:6px"><?php echo nl2br(sa_e($manual['instructions'])); ?></p>
<?php endif; ?>
<?php if ($manual['bank_name'] !== '' || $manual['account_number'] !== ''): ?>
            <dl>
<?php if ($manual['bank_name'] !== ''): ?>
                <dt>Bank</dt><dd><?php echo sa_e($manual['bank_name']); ?></dd>
<?php endif; ?>
<?php if ($manual['account_name'] !== ''): ?>
                <dt>Account name</dt><dd><?php echo sa_e($manual['account_name']); ?></dd>
<?php endif; ?>
<?php if ($manual['account_number'] !== ''): ?>
                <dt>Account number</dt><dd><?php echo sa_e($manual['account_number']); ?></dd>
<?php endif; ?>
                <dt>Reference</dt><dd><?php echo sa_e($invoice['invoice_number']); ?></dd>
            </dl>
<?php endif; ?>
            <p style="margin-top:8px">Always quote <strong><?php echo sa_e($invoice['invoice_number']); ?></strong> so the payment can be matched.</p>
        </div>
<?php endif; ?>

<?php if (!empty($invoice['notes'])): ?>
        <div class="note">
            <strong>Notes</strong>
            <p style="margin-top:6px"><?php echo nl2br(sa_e($invoice['notes'])); ?></p>
        </div>
<?php endif; ?>
    </div>

    <div class="sheet-foot">
        <span>
            Generated <?php echo sa_e(date('M j, Y H:i')); ?>
            <?php if ($is_paid): ?>&middot; keep this receipt for your records<?php endif; ?>
        </span>
<?php if ($is_paid): ?>
        <span class="stamp">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            Verified payment
        </span>
<?php endif; ?>
    </div>
</div>

</body>
</html>
        <?php
        return (string) ob_get_clean();
    }
}
