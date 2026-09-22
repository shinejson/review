<?php
/**
 * ============================================================
 *  Subscription Plans & Access Comparison Matrix Component
 * ============================================================
 *  Renders a high-fidelity side-by-side feature comparison table
 *  matching the Subscription Plans Packages Pricing Table design.
 *  Supports public pricing, tenant workspace, superadmin finance,
 *  and superadmin plans contexts.
 */

if (!function_exists('render_subscription_access_chart')) {

    function render_subscription_access_chart($conn, $context = 'public', $options = [])
    {
        // 1. Fetch active plans from database
        $plans = [];
        if ($conn instanceof mysqli) {
            $q = "SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price ASC";
            $res = $conn->query($q);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $plans[] = $row;
                }
            }
        }

        // Fallback if no active plans in DB
        if (empty($plans)) {
            $plans = [
                ['id' => 1, 'plan_name' => 'Starter', 'price' => 29.99, 'max_ratings' => 100, 'max_customers' => 10, 'features' => 'Basic analytics, Email support, 10 customers, 100 ratings/month', 'annual_discount_percent' => 16],
                ['id' => 2, 'plan_name' => 'Professional', 'price' => 79.99, 'max_ratings' => 500, 'max_customers' => 50, 'features' => 'Advanced analytics, Priority support, 50 customers, 500 ratings/month, Custom branding', 'annual_discount_percent' => 20],
                ['id' => 3, 'plan_name' => 'Enterprise', 'price' => 199.99, 'max_ratings' => 9999, 'max_customers' => 999, 'features' => 'Full analytics suite, 24/7 support, Unlimited customers, Unlimited ratings, API access, White label', 'annual_discount_percent' => 25]
            ];
        }

        // Palette presets matching the uploaded reference image:
        // Column 1: Sky Blue / Cyan (#06b6d4 / #0ea5e9)
        // Column 2: Emerald / Mint Green (#10b981 / #059669)
        // Column 3: Vibrant Royal Blue (#0284c7 / #2563eb)
        // Column 4: Coral / Crimson Red (#ef4444 / #dc2626)
        $tier_palettes = [
            0 => [
                'name' => 'cyan',
                'ribbon' => 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%)',
                'solid' => '#0284c7',
                'accent' => '#38bdf8',
                'light' => '#e0f2fe',
                'tag' => 'ONE TIME ONLY'
            ],
            1 => [
                'name' => 'emerald',
                'ribbon' => 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
                'solid' => '#059669',
                'accent' => '#34d399',
                'light' => '#ecfdf5',
                'tag' => 'POPULAR CHOICE'
            ],
            2 => [
                'name' => 'blue',
                'ribbon' => 'linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)',
                'solid' => '#1d4ed8',
                'accent' => '#60a5fa',
                'light' => '#eff6ff',
                'tag' => 'RECOMMENDED'
            ],
            3 => [
                'name' => 'coral',
                'ribbon' => 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)',
                'solid' => '#dc2626',
                'accent' => '#f87171',
                'light' => '#fef2f2',
                'tag' => 'MAXIMUM VALUE'
            ],
        ];

        // Currency format
        $curr_symbol = $options['currency_symbol'] ?? '$';
        $curr_pos = $options['currency_position'] ?? 'before';
        $format_price = function($amount) use ($curr_symbol, $curr_pos) {
            $f = number_format((float)$amount, 2);
            return $curr_pos === 'after' ? $f . ' ' . $curr_symbol : $curr_symbol . $f;
        };

        // Context parameters
        $current_plan_id = (int)($options['current_plan_id'] ?? 0);
        $pending_request = (bool)($options['pending_request'] ?? false);
        $tenant_id = (int)($options['tenant_id'] ?? 0);
        $section_title = $options['title'] ?? 'SUBSCRIPTION PLANS PACKAGES PRICING TABLE';
        $section_sub = $options['subtitle'] ?? 'Compare feature capabilities, usage allowances, and access controls across each tier.';

        // Define the Access Capabilities / Feature Rows
        $features_matrix = [
            [
                'title' => 'Monthly Review Quota',
                'desc'  => 'Verified customer reviews collected & routed per month',
                'eval'  => function($p, $tier) {
                    $max = (int)($p['max_ratings'] ?? 0);
                    if ($max >= 9000 || $tier === 'enterprise') return ['type' => 'badge', 'value' => 'Unlimited', 'state' => true];
                    return ['type' => 'badge', 'value' => number_format($max) . ' / mo', 'state' => true];
                }
            ],
            [
                'title' => 'Profiles & Branch Locations',
                'desc'  => 'Unique business locations, counter profiles & branch dashboards',
                'eval'  => function($p, $tier) {
                    $max = (int)($p['max_customers'] ?? 0);
                    if ($max >= 900 || $tier === 'enterprise') return ['type' => 'badge', 'value' => 'Unlimited', 'state' => true];
                    return ['type' => 'badge', 'value' => number_format($max) . ' Locations', 'state' => true];
                }
            ],
            [
                'title' => 'Staff Team Member Seats',
                'desc'  => 'Dedicated employee and manager logins with permission controls',
                'eval'  => function($p, $tier) {
                    if ($tier === 'starter') return ['type' => 'badge', 'value' => '2 Seats', 'state' => true];
                    if ($tier === 'pro') return ['type' => 'badge', 'value' => '10 Seats', 'state' => true];
                    return ['type' => 'badge', 'value' => 'Unlimited', 'state' => true];
                }
            ],
            [
                'title' => 'Smart Google Review Booster',
                'desc'  => 'Intelligent funnel sends 4-5★ happy customers straight to Google',
                'eval'  => function($p, $tier) {
                    return ['type' => 'check', 'state' => true];
                }
            ],
            [
                'title' => 'Negative Review Shield',
                'desc'  => 'Captures 1-3★ issues internally for immediate customer service recovery',
                'eval'  => function($p, $tier) {
                    return ['type' => 'check', 'state' => true];
                }
            ],
            [
                'title' => 'Printable Counter QR Stands',
                'desc'  => 'Desk/counter scan stands with dynamic routing and live analytics',
                'eval'  => function($p, $tier) {
                    if ($tier === 'starter') return ['type' => 'badge', 'value' => 'Print PDF', 'state' => true];
                    if ($tier === 'pro') return ['type' => 'badge', 'value' => 'Free Acrylic Stand', 'state' => true];
                    return ['type' => 'badge', 'value' => 'Custom Acrylic Kit', 'state' => true];
                }
            ],
            [
                'title' => 'Automated Outreach Engine',
                'desc'  => 'Automated WhatsApp, SMS & Email review collection requests',
                'eval'  => function($p, $tier) {
                    if ($tier === 'starter') return ['type' => 'badge', 'value' => 'Standard Queue', 'state' => true];
                    return ['type' => 'badge', 'value' => 'Priority Multi-Channel', 'state' => true];
                }
            ],
            [
                'title' => 'Social Proof Graphic Studio',
                'desc'  => 'Generate social cards for Instagram, Facebook, and counter screens',
                'eval'  => function($p, $tier) {
                    if ($tier === 'starter') return ['type' => 'badge', 'value' => '3 Core Themes', 'state' => true];
                    return ['type' => 'badge', 'value' => 'Unlimited Themes', 'state' => true];
                }
            ],
            [
                'title' => 'Ads & Conversion Pixels',
                'desc'  => 'Meta Pixel, Google Tag Manager & TikTok retargeting integration',
                'eval'  => function($p, $tier) {
                    if ($tier === 'starter') return ['type' => 'cross', 'state' => false];
                    return ['type' => 'check', 'state' => true];
                }
            ],
            [
                'title' => 'REST API Access & Webhooks',
                'desc'  => 'Secure API endpoints for point-of-sale, CRM, and custom integrations',
                'eval'  => function($p, $tier) {
                    if ($tier === 'enterprise') return ['type' => 'check', 'state' => true];
                    return ['type' => 'cross', 'state' => false];
                }
            ],
            [
                'title' => 'White-Label & Custom Domain',
                'desc'  => 'Remove Optibiz badges, use custom vanity domain, and branded emails',
                'eval'  => function($p, $tier) {
                    if ($tier === 'enterprise') return ['type' => 'check', 'state' => true];
                    return ['type' => 'cross', 'state' => false];
                }
            ],
            [
                'title' => 'Dedicated Support & SLA',
                'desc'  => 'Response time guarantees, onboarding assistance, and account rep',
                'eval'  => function($p, $tier) {
                    if ($tier === 'starter') return ['type' => 'badge', 'value' => 'Email (24-48h)', 'state' => true];
                    if ($tier === 'pro') return ['type' => 'badge', 'value' => 'Priority Chat (<6h)', 'state' => true];
                    return ['type' => 'badge', 'value' => '24/7 Phone & SLA', 'state' => true];
                }
            ],
        ];

        // SVGs for high-contrast, dependency-free icons
        $svg_check = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        $svg_cross = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';

        // Render HTML
        ob_start();
        ?>
        <!-- ============================================================
             SUBSCRIPTION PLANS & ACCESS COMPARISON MATRIX
             ============================================================ -->
        <div class="sub-matrix-wrapper" id="subscription-access-chart">
            <div class="sub-matrix-header-box">
                <h2 class="sub-matrix-title"><?php echo htmlspecialchars($section_title); ?></h2>
                <div class="sub-matrix-title-bar"></div>
                <?php if (!empty($section_sub)): ?>
                <p class="sub-matrix-sub"><?php echo htmlspecialchars($section_sub); ?></p>
                <?php endif; ?>
            </div>

            <div class="sub-matrix-table-card">
                <div class="sub-matrix-scroll">
                    <table class="sub-matrix-table" role="table" aria-label="Subscription plan comparison">
                        <thead>
                            <tr class="sub-matrix-head-row">
                                <!-- Top-Left Header Cell -->
                                <th class="sub-matrix-cell-featurehead" scope="col">
                                    <div class="sub-matrix-featurehead-title">SUBSCRIPTION PLANS</div>
                                    <div class="sub-matrix-featurehead-sub">Features &amp; Privileges</div>
                                </th>

                                <?php 
                                foreach ($plans as $idx => $p): 
                                    $p_name = $p['plan_name'] ?? 'Plan';
                                    $p_price = (float)($p['price'] ?? 0);
                                    $discount = (int)($p['annual_discount_percent'] ?? 0);
                                    if ($discount <= 0) $discount = ($idx === 0 ? 16 : ($idx === 1 ? 20 : 25));

                                    // Palette assignment
                                    $pal = $tier_palettes[$idx % count($tier_palettes)];
                                    if (stripos($p_name, 'enter') !== false) {
                                        $pal = $tier_palettes[3]; // Red
                                    } elseif (stripos($p_name, 'pro') !== false) {
                                        $pal = $tier_palettes[1]; // Emerald
                                    } elseif ($idx === 0) {
                                        $pal = $tier_palettes[0]; // Cyan
                                    }

                                    $is_current = ($current_plan_id > 0 && (int)$p['id'] === $current_plan_id);
                                    $is_recommended = (stripos($p_name, 'pro') !== false || $idx === 1);
                                ?>
                                <th class="sub-matrix-cell-planhead" scope="col" data-plan-id="<?php echo (int)$p['id']; ?>">
                                    <!-- Ribbon Tab (Matching user screenshot) -->
                                    <div class="sub-matrix-ribbon" style="background: <?php echo $pal['ribbon']; ?>;">
                                        <?php echo strtoupper(htmlspecialchars($p_name)); ?>
                                    </div>

                                    <div class="sub-matrix-planhead-body">
                                        <div class="sub-matrix-planhead-term">
                                            <?php echo $format_price($p_price); ?> / PER MONTH
                                        </div>
                                        <div class="sub-matrix-planhead-now" style="color: <?php echo $pal['solid']; ?>;">
                                            Now <?php echo $format_price($p_price); ?>
                                        </div>
                                        <div class="sub-matrix-planhead-discount">
                                            <?php echo (int)$discount; ?>% OFF LIMITED TIME
                                        </div>
                                        <?php if ($is_current): ?>
                                            <div class="sub-matrix-current-badge">YOUR ACTIVE PLAN</div>
                                        <?php endif; ?>
                                    </div>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($features_matrix as $row_idx => $feat): ?>
                            <tr class="sub-matrix-row <?php echo ($row_idx % 2 === 1) ? 'is-striped' : ''; ?>">
                                <!-- Feature Description Cell -->
                                <th class="sub-matrix-cell-feature" scope="row">
                                    <div class="sub-matrix-feat-name"><?php echo htmlspecialchars($feat['title']); ?></div>
                                    <div class="sub-matrix-feat-desc"><?php echo htmlspecialchars($feat['desc']); ?></div>
                                </th>

                                <!-- Plan Value Cells -->
                                <?php 
                                foreach ($plans as $idx => $p): 
                                    $p_name = $p['plan_name'] ?? '';
                                    $tier = (stripos($p_name, 'enter') !== false) ? 'enterprise' : ((stripos($p_name, 'pro') !== false) ? 'pro' : 'starter');
                                    $res = $feat['eval']($p, $tier);

                                    $pal = $tier_palettes[$idx % count($tier_palettes)];
                                    if (stripos($p_name, 'enter') !== false) $pal = $tier_palettes[3];
                                    elseif (stripos($p_name, 'pro') !== false) $pal = $tier_palettes[1];
                                    elseif ($idx === 0) $pal = $tier_palettes[0];
                                ?>
                                <td class="sub-matrix-cell-val">
                                    <?php if ($res['type'] === 'check'): ?>
                                        <!-- Solid Colored Square Checkbox (Matching screenshot) -->
                                        <div class="sub-matrix-icon-box is-check" style="background: <?php echo $pal['solid']; ?>;" title="Included in <?php echo htmlspecialchars($p_name); ?>">
                                            <?php echo $svg_check; ?>
                                        </div>
                                    <?php elseif ($res['type'] === 'cross'): ?>
                                        <!-- Muted Grey Square Cross (Matching screenshot) -->
                                        <div class="sub-matrix-icon-box is-cross" title="Not available on <?php echo htmlspecialchars($p_name); ?>">
                                            <?php echo $svg_cross; ?>
                                        </div>
                                    <?php elseif ($res['type'] === 'badge'): ?>
                                        <!-- Badge with limit / description -->
                                        <div class="sub-matrix-badge-val" style="border-color: <?php echo $pal['solid']; ?>33; background: <?php echo $pal['light']; ?>; color: <?php echo $pal['solid']; ?>;">
                                            <span class="sub-matrix-mini-check" style="background: <?php echo $pal['solid']; ?>;">
                                                <?php echo $svg_check; ?>
                                            </span>
                                            <span><?php echo htmlspecialchars($res['value']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="sub-matrix-foot-row">
                                <td class="sub-matrix-cell-featurefoot">
                                    <div class="sub-matrix-guarantee">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                        <span>Instant setup • Change plans anytime</span>
                                    </div>
                                </td>

                                <?php 
                                foreach ($plans as $idx => $p): 
                                    $p_id = (int)($p['id'] ?? 0);
                                    $p_name = $p['plan_name'] ?? 'Plan';
                                    $pal = $tier_palettes[$idx % count($tier_palettes)];
                                    if (stripos($p_name, 'enter') !== false) $pal = $tier_palettes[3];
                                    elseif (stripos($p_name, 'pro') !== false) $pal = $tier_palettes[1];
                                    elseif ($idx === 0) $pal = $tier_palettes[0];

                                    $is_current = ($current_plan_id > 0 && $p_id === $current_plan_id);
                                    $is_recommended = (stripos($p_name, 'pro') !== false || $idx === 1);
                                ?>
                                <td class="sub-matrix-cell-planfoot">
                                    <?php if ($context === 'public'): ?>
                                        <!-- Public Pricing CTA -->
                                        <button type="button" class="sub-matrix-btn" style="background: <?php echo $pal['solid']; ?>;" onclick="openModal(<?php echo $p_id; ?>)">
                                            ACTIVATE NOW
                                        </button>
                                    <?php elseif ($context === 'tenant'): ?>
                                        <!-- Tenant Workspace CTA -->
                                        <?php if ($is_current): ?>
                                            <button type="button" class="sub-matrix-btn is-disabled" disabled>
                                                CURRENT PLAN
                                            </button>
                                        <?php elseif ($pending_request): ?>
                                            <button type="button" class="sub-matrix-btn is-disabled" disabled>
                                                REQUEST PENDING
                                            </button>
                                        <?php else: ?>
                                            <form method="POST" action="subscription.php" style="margin:0">
                                                <?php if (function_exists('sa_csrf_field')) echo sa_csrf_field(); ?>
                                                <input type="hidden" name="action" value="request_change">
                                                <input type="hidden" name="plan_id" value="<?php echo $p_id; ?>">
                                                <input type="hidden" name="note" value="Plan change requested from comparison matrix">
                                                <button type="submit" class="sub-matrix-btn" style="background: <?php echo $pal['solid']; ?>;" onclick="return confirm('Request switch to <?php echo htmlspecialchars($p_name); ?> plan?');">
                                                    SWITCH PLAN
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif ($context === 'superadmin_finance'): ?>
                                        <!-- Superadmin Finance CTA -->
                                        <a href="finance.php?f_plan=<?php echo $p_id; ?>#invoices" class="sub-matrix-btn" style="background: <?php echo $pal['solid']; ?>; text-decoration:none;">
                                            VIEW INVOICES
                                        </a>
                                    <?php elseif ($context === 'superadmin_plans'): ?>
                                        <!-- Superadmin Plans CTA (Triggers Edit Modal) -->
                                        <button type="button" class="sub-matrix-btn" style="background: <?php echo $pal['solid']; ?>;"
                                                data-sa-edit-plan
                                                data-id="<?php echo $p_id; ?>"
                                                data-name="<?php echo htmlspecialchars($p_name); ?>"
                                                data-price="<?php echo htmlspecialchars($p['price']); ?>"
                                                data-ratings="<?php echo (int)$p['max_ratings']; ?>"
                                                data-customers="<?php echo (int)$p['max_customers']; ?>"
                                                data-status="<?php echo htmlspecialchars($p['status']); ?>"
                                                data-features="<?php echo htmlspecialchars($p['features']); ?>"
                                                data-discount="<?php echo (int)($p['annual_discount_percent'] ?? 0); ?>">
                                            EDIT IN CATALOG
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($is_recommended): ?>
                                        <div class="sub-matrix-recommended-lbl">RECOMMENDED</div>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================================
             SCOPED STYLES FOR SUBSCRIPTION ACCESS MATRIX
             ============================================================ -->
        <style>
        .sub-matrix-wrapper {
            margin: 48px auto 28px;
            max-width: 1200px;
            width: 100%;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #0f172a;
        }
        .sub-matrix-header-box {
            text-align: center;
            margin-bottom: 28px;
        }
        .sub-matrix-title {
            font-size: 26px;
            font-weight: 800;
            color: #0891b2;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 0 0 10px;
        }
        .sub-matrix-title-bar {
            width: 90px;
            height: 3.5px;
            background: #0891b2;
            margin: 0 auto 12px;
            border-radius: 4px;
        }
        .sub-matrix-sub {
            font-size: 14px;
            color: #64748b;
            margin: 0;
            max-width: 640px;
            margin: 0 auto;
        }
        .sub-matrix-table-card {
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.06), 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .sub-matrix-scroll {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .sub-matrix-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            min-width: 780px;
        }

        /* Head Cells */
        .sub-matrix-head-row th {
            padding: 0;
            vertical-align: top;
        }
        .sub-matrix-cell-featurehead {
            width: 32%;
            min-width: 240px;
            padding: 34px 28px 20px !important;
            vertical-align: bottom !important;
            border-right: 1px solid #f1f5f9;
        }
        .sub-matrix-featurehead-title {
            font-size: 20px;
            font-weight: 800;
            color: #0891b2;
            letter-spacing: -0.3px;
        }
        .sub-matrix-featurehead-sub {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
            margin-top: 4px;
        }

        .sub-matrix-cell-planhead {
            text-align: center;
            border-right: 1px solid #f1f5f9;
            background: #fafcff;
        }
        .sub-matrix-cell-planhead:last-child {
            border-right: none;
        }

        /* Ribbon Tab */
        .sub-matrix-ribbon {
            color: #ffffff;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.8px;
            padding: 13px 16px;
            border-radius: 0 0 10px 10px;
            margin: 0 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }
        .sub-matrix-planhead-body {
            padding: 16px 14px 20px;
        }
        .sub-matrix-planhead-term {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sub-matrix-planhead-now {
            font-size: 24px;
            font-weight: 900;
            margin: 4px 0 2px;
            letter-spacing: -0.5px;
        }
        .sub-matrix-planhead-discount {
            font-size: 11.5px;
            font-weight: 700;
            color: #0284c7;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .sub-matrix-current-badge {
            display: inline-block;
            margin-top: 6px;
            background: #10b981;
            color: #ffffff;
            font-size: 10px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 20px;
            letter-spacing: 0.4px;
        }

        /* Body Rows */
        .sub-matrix-row {
            border-top: 1px solid #f1f5f9;
            transition: background-color 0.18s ease;
        }
        .sub-matrix-row:hover {
            background: #f8fafc;
        }
        .sub-matrix-row.is-striped {
            background: #fafbfe;
        }
        .sub-matrix-row.is-striped:hover {
            background: #f1f5f9;
        }

        .sub-matrix-cell-feature {
            padding: 14px 28px;
            border-right: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .sub-matrix-feat-name {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.35;
        }
        .sub-matrix-feat-desc {
            font-size: 12px;
            color: #64748b;
            font-weight: 400;
            margin-top: 2px;
            line-height: 1.4;
        }

        .sub-matrix-cell-val {
            text-align: center;
            vertical-align: middle;
            padding: 12px 14px;
            border-right: 1px solid #f1f5f9;
        }
        .sub-matrix-cell-val:last-child {
            border-right: none;
        }

        /* Square Checkmark Box (Matching uploaded image) */
        .sub-matrix-icon-box {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            transition: transform 0.15s ease;
        }
        .sub-matrix-icon-box:hover {
            transform: scale(1.1);
        }
        .sub-matrix-icon-box.is-check {
            color: #ffffff;
        }
        .sub-matrix-icon-box.is-cross {
            background: #cbd5e1;
            color: #ffffff;
        }

        /* Badge for Quantitative items (e.g. 2 Seats, 100/mo) */
        .sub-matrix-badge-val {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid transparent;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            white-space: nowrap;
        }
        .sub-matrix-mini-check {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            flex-shrink: 0;
        }

        /* Footer Row */
        .sub-matrix-foot-row {
            border-top: 2px solid #e2e8f0;
            background: #ffffff;
        }
        .sub-matrix-cell-featurefoot {
            padding: 22px 28px;
            border-right: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .sub-matrix-guarantee {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
        }
        .sub-matrix-guarantee svg {
            color: #10b981;
            flex-shrink: 0;
        }

        .sub-matrix-cell-planfoot {
            text-align: center;
            padding: 20px 14px 22px;
            border-right: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .sub-matrix-cell-planfoot:last-child {
            border-right: none;
        }

        /* Action Buttons */
        .sub-matrix-btn {
            display: inline-block;
            width: 100%;
            max-width: 180px;
            padding: 10px 14px;
            border-radius: 8px;
            color: #ffffff;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.14);
            transition: all 0.2s ease;
        }
        .sub-matrix-btn:hover:not(.is-disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.22);
            filter: brightness(1.08);
        }
        .sub-matrix-btn.is-disabled {
            background: #94a3b8 !important;
            cursor: not-allowed;
            opacity: 0.75;
            box-shadow: none;
        }
        .sub-matrix-recommended-lbl {
            font-size: 10.5px;
            font-weight: 800;
            color: #64748b;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-top: 8px;
        }

        /* Dark mode compatibility */
        body.dark-mode .sub-matrix-table-card,
        [data-theme="dark"] .sub-matrix-table-card,
        .sa-dark .sub-matrix-table-card {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }
        body.dark-mode .sub-matrix-cell-featurehead,
        [data-theme="dark"] .sub-matrix-cell-featurehead,
        .sa-dark .sub-matrix-cell-featurehead {
            border-color: #334155;
        }
        body.dark-mode .sub-matrix-cell-planhead,
        [data-theme="dark"] .sub-matrix-cell-planhead,
        .sa-dark .sub-matrix-cell-planhead {
            background: #192333;
            border-color: #334155;
        }
        body.dark-mode .sub-matrix-row,
        [data-theme="dark"] .sub-matrix-row,
        .sa-dark .sub-matrix-row {
            border-color: #334155;
        }
        body.dark-mode .sub-matrix-row.is-striped,
        [data-theme="dark"] .sub-matrix-row.is-striped,
        .sa-dark .sub-matrix-row.is-striped {
            background: #233044;
        }
        body.dark-mode .sub-matrix-row:hover,
        [data-theme="dark"] .sub-matrix-row:hover,
        .sa-dark .sub-matrix-row:hover {
            background: #28364d;
        }
        body.dark-mode .sub-matrix-feat-name,
        [data-theme="dark"] .sub-matrix-feat-name,
        .sa-dark .sub-matrix-feat-name {
            color: #f8fafc;
        }
        body.dark-mode .sub-matrix-cell-feature,
        body.dark-mode .sub-matrix-cell-val,
        [data-theme="dark"] .sub-matrix-cell-feature,
        [data-theme="dark"] .sub-matrix-cell-val,
        .sa-dark .sub-matrix-cell-feature,
        .sa-dark .sub-matrix-cell-val {
            border-color: #334155;
        }
        body.dark-mode .sub-matrix-foot-row,
        [data-theme="dark"] .sub-matrix-foot-row,
        .sa-dark .sub-matrix-foot-row {
            background: #1e293b;
            border-color: #334155;
        }
        body.dark-mode .sub-matrix-cell-featurefoot,
        body.dark-mode .sub-matrix-cell-planfoot,
        [data-theme="dark"] .sub-matrix-cell-featurefoot,
        [data-theme="dark"] .sub-matrix-cell-planfoot,
        .sa-dark .sub-matrix-cell-featurefoot,
        .sa-dark .sub-matrix-cell-planfoot {
            border-color: #334155;
        }
        </style>
        <?php
        return ob_get_clean();
    }
}
