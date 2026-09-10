<?php
/**
 * ============================================================
 *  Super Admin — Printable Invoice / Payment Receipt
 * ============================================================
 *  Clean, executive printable invoice and receipt for recorded
 *  subscription payments (offline wires, mobile money, cash).
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();
require_sa_permission('subscriptions');

$payment_id = (int) ($_GET['id'] ?? 0);
if (!$payment_id) {
    sa_flash('error', 'Invalid payment identifier.');
    redirect('subscriptions.php');
}

sa_ensure_payments_schema($conn);

$payment = sa_one(
    $conn,
    "SELECT sp.*, t.company_name, t.email, t.phone, t.subscription_status, t.subscription_start_date, t.subscription_end_date,
            p.plan_name, p.price AS plan_price
       FROM subscription_payments sp
       JOIN tenants t ON sp.tenant_id = t.id
       LEFT JOIN subscription_plans p ON p.id = t.plan_id
      WHERE sp.id = " . $payment_id,
    ['subscription_payments', 'tenants']
);

if (!$payment) {
    sa_flash('error', 'Payment record not found.');
    redirect('subscriptions.php');
}

$site_name = sa_setting($conn, 'site_name', 'Optibiz');
$logo_url  = sa_platform_logo($conn);
$currency  = sa_currency_symbol($conn);
$receipt_no = $payment['receipt_number'];
$date_issued = date('F j, Y', strtotime($payment['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo sa_e($receipt_no); ?> — <?php echo sa_e($site_name); ?></title>
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-light: #f8fafc;
            --success: #16a34a;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f1f5f9;
            color: var(--text-main);
            line-height: 1.5;
            padding: 24px;
        }

        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .receipt-topbar {
            background: #ffffff;
            padding: 14px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }

        .receipt-topbar a, .receipt-topbar button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.15s;
        }

        .btn-back {
            color: var(--text-muted);
            background: transparent;
            border: 1px solid var(--border-color);
        }
        .btn-back:hover {
            color: var(--text-main);
            background: var(--bg-light);
        }

        .btn-print {
            background: var(--primary);
            color: #ffffff;
            border: 1px solid var(--primary);
        }
        .btn-print:hover {
            background: var(--primary-dark);
        }

        .receipt-body {
            padding: 40px;
        }

        .receipt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 30px;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 30px;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo img {
            max-height: 48px;
            width: auto;
        }

        .brand-logo h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.5px;
        }

        .brand-logo p {
            font-size: 12px;
            color: var(--text-muted);
        }

        .receipt-title-block {
            text-align: right;
        }

        .receipt-title-block h2 {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .receipt-title-block .receipt-id {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
            margin-top: 4px;
        }

        .receipt-badge {
            display: inline-block;
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 10px;
            border-radius: 999px;
            margin-top: 6px;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 35px;
        }

        .meta-box h4 {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.75px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .meta-box p {
            font-size: 14px;
            line-height: 1.6;
        }

        .meta-box strong {
            color: var(--text-main);
            font-weight: 600;
        }

        .table-wrap {
            margin-bottom: 35px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: var(--bg-light);
            text-align: left;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border-color);
        }

        th.num, td.num {
            text-align: right;
        }

        td {
            padding: 16px;
            font-size: 13.5px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }

        .item-name {
            font-weight: 600;
            color: var(--text-main);
        }

        .item-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .totals-block {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 35px;
        }

        .totals-table {
            width: 320px;
        }

        .totals-table tr td {
            padding: 8px 0;
            border-bottom: none;
        }

        .totals-table tr.grand-total td {
            padding-top: 12px;
            border-top: 2px solid var(--border-color);
            font-size: 18px;
            font-weight: 800;
            color: var(--primary);
        }

        .notes-card {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px 20px;
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        .notes-card strong {
            color: var(--text-main);
        }

        .receipt-footer {
            border-top: 1px solid var(--border-color);
            padding-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-muted);
        }

        .stamp-box {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 2px dashed #22c55e;
            color: #15803d;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .receipt-topbar {
                display: none !important;
            }
            .receipt-container {
                box-shadow: none;
                border: none;
                max-width: 100%;
            }
            .receipt-body {
                padding: 20px 0;
            }
        }
    </style>
</head>
<body>

<div class="receipt-container">
    <header class="receipt-topbar">
        <a class="btn-back" href="subscriptions.php">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Subscriptions
        </a>
        <button type="button" class="btn-print" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print / Save as PDF
        </button>
    </header>

    <div class="receipt-body">
        <div class="receipt-header">
            <div class="brand-logo">
                <?php if (!empty($logo_url)): ?>
                    <img src="<?php echo sa_e($logo_url); ?>" alt="<?php echo sa_e($site_name); ?>">
                <?php endif; ?>
                <div>
                    <h1><?php echo sa_e($site_name); ?></h1>
                    <p>Business Reputation &amp; Growth SaaS</p>
                </div>
            </div>
            <div class="receipt-title-block">
                <h2>Official Receipt</h2>
                <div class="receipt-id"><?php echo sa_e($receipt_no); ?></div>
                <span class="receipt-badge">● Paid &amp; Settled</span>
            </div>
        </div>

        <div class="meta-grid">
            <div class="meta-box">
                <h4>Billed To (Customer)</h4>
                <p>
                    <strong><?php echo sa_e($payment['company_name']); ?></strong><br>
                    <?php if (!empty($payment['email'])): ?>
                        Email: <?php echo sa_e($payment['email']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($payment['phone'])): ?>
                        Phone: <?php echo sa_e($payment['phone']); ?><br>
                    <?php endif; ?>
                    Workspace ID: #<?php echo (int) $payment['tenant_id']; ?>
                </p>
            </div>

            <div class="meta-box">
                <h4>Payment Information</h4>
                <p>
                    <strong>Date Issued:</strong> <?php echo sa_e($date_issued); ?><br>
                    <strong>Payment Method:</strong> <?php echo sa_e($payment['payment_method']); ?><br>
                    <strong>Transaction Ref:</strong> <?php echo sa_e($payment['transaction_ref'] ?: 'N/A'); ?><br>
                    <strong>Recorded By:</strong> <?php echo sa_e($payment['recorded_by'] ?: 'Super Admin'); ?>
                </p>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item Description</th>
                        <th>Period / Duration</th>
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <div class="item-name"><?php echo sa_e($payment['plan_name'] ?: 'Software Subscription'); ?> Plan</div>
                            <div class="item-sub">
                                Optibiz Business Review &amp; Reputation Platform Access
                                <?php if (!empty($payment['subscription_end_date'])): ?>
                                    &middot; Active through <?php echo sa_e(date('M j, Y', strtotime($payment['subscription_end_date']))); ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ((int)$payment['months_extended'] > 0): ?>
                                +<?php echo (int)$payment['months_extended']; ?> Month<?php echo (int)$payment['months_extended'] === 1 ? '' : 's'; ?> Extension
                            <?php else: ?>
                                Subscription Term Payment
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <strong><?php echo sa_e(sa_money($payment['amount'])); ?></strong>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="totals-block">
            <table class="totals-table">
                <tr>
                    <td>Subtotal:</td>
                    <td class="num"><?php echo sa_e(sa_money($payment['amount'])); ?></td>
                </tr>
                <tr>
                    <td>Taxes &amp; Fees (0%):</td>
                    <td class="num"><?php echo sa_e(sa_money(0)); ?></td>
                </tr>
                <tr class="grand-total">
                    <td>Total Paid:</td>
                    <td class="num"><?php echo sa_e(sa_money($payment['amount'])); ?></td>
                </tr>
            </table>
        </div>

        <?php if (!empty($payment['notes'])): ?>
            <div class="notes-card">
                <strong>Payment Notes / Remarks:</strong><br>
                <?php echo nl2br(sa_e($payment['notes'])); ?>
            </div>
        <?php endif; ?>

        <footer class="receipt-footer">
            <div>
                <p>Thank you for choosing <?php echo sa_e($site_name); ?>. For billing support, contact support.</p>
                <p style="font-size:11px;margin-top:2px">Generated electronically on <?php echo date('Y-m-d H:i:s'); ?>.</p>
            </div>
            <div class="stamp-box">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                VERIFIED PAYMENT
            </div>
        </footer>
    </div>
</div>

</body>
</html>
