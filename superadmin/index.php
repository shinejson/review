<?php
/**
 * ============================================================
 *  Super Admin — Control Center (dashboard)
 * ============================================================
 *  KPIs, revenue & engagement trends, tenant health, renewals,
 *  top rated companies, quote pipeline and a live activity feed.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();

/* ---------- data ---------- */
$m          = sa_metrics($conn);
$trend      = sa_revenue_trend($conn, 12);
$ratings    = sa_ratings_trend($conn, 56);
$plans      = sa_plan_distribution($conn);
$stars      = sa_star_distribution($conn);
$top        = sa_top_companies($conn, 6);
$expiring   = sa_expiring_subscriptions($conn, 30, 6);
$activity   = sa_recent_activity($conn, 8);
$quotes     = sa_query(
    $conn,
    "SELECT q.id, q.company_name, q.contact_person, q.email, q.status, q.created_at,
            p.plan_name
       FROM quote_requests q
       LEFT JOIN subscription_plans p ON p.id = q.plan_id
      ORDER BY q.created_at DESC
      LIMIT 5",
    ['quote_requests', 'subscription_plans']
);
$recent_tenants = sa_query(
    $conn,
    "SELECT t.id, t.company_name, t.email, t.subscription_status, t.subscription_price,
            t.created_at, p.plan_name
       FROM tenants t
       LEFT JOIN subscription_plans p ON p.id = t.plan_id
      ORDER BY t.created_at DESC
      LIMIT 8",
    ['tenants', 'subscription_plans']
);

/* ---------- derived numbers ---------- */
$mrr_now  = $trend ? (float) end($trend)['mrr'] : $m['mrr'];
$mrr_prev = count($trend) > 1 ? (float) $trend[count($trend) - 2]['mrr'] : 0.0;
$mrr_delta = $mrr_prev > 0 ? (($mrr_now - $mrr_prev) / $mrr_prev) * 100 : ($mrr_now > 0 ? 100.0 : 0.0);

$tenants_now  = $trend ? (int) end($trend)['tenants'] : $m['tenants_total'];
$tenants_prev = count($trend) > 1 ? (int) $trend[count($trend) - 2]['tenants'] : 0;
$tenants_delta = $tenants_prev > 0 ? (($tenants_now - $tenants_prev) / $tenants_prev) * 100 : 0.0;

$spark_mrr     = array_map(function ($r) { return $r['mrr']; }, $trend);
$spark_tenants = array_map(function ($r) { return $r['tenants']; }, $trend);
$spark_ratings = array_map(function ($r) { return $r['count']; }, $ratings);

$trend_mrr   = array_map(function ($r) { return round($r['mrr'], 2); }, $trend);
$trend_new   = array_map(function ($r) { return $r['new_tenants']; }, $trend);
$trend_label = array_map(function ($r) { return $r['label']; }, $trend);

$ratings_weeks = array_slice($ratings, -28);
$ratings_spark = array_map(function ($r) { return $r['count']; }, $ratings_weeks);
$heat_cells    = array_map(function ($r) {
    return ['label' => $r['label'], 'value' => $r['count']];
}, array_slice($ratings, -54));

$plan_segments = [];
$plan_palette  = ['var(--sa-lime)', 'var(--sa-info)', 'var(--sa-violet)', 'var(--sa-warning)', 'var(--sa-success)'];
foreach ($plans as $i => $p) {
    $plan_segments[] = [
        'label'   => $p['plan_name'],
        'value'   => (int) $p['tenants'],
        'display' => (int) $p['tenants'] . ' tenants',
        'color'   => $plan_palette[$i % count($plan_palette)],
    ];
}

$status_segments = [
    ['label' => 'Active',    'value' => $m['status']['active'],    'color' => 'var(--sa-success)'],
    ['label' => 'Trial',     'value' => $m['status']['trial'],     'color' => 'var(--sa-warning)'],
    ['label' => 'Inactive',  'value' => $m['status']['inactive'],  'color' => 'var(--sa-danger)'],
    ['label' => 'Cancelled', 'value' => $m['status']['cancelled'], 'color' => 'var(--sa-faint)'],
];

$top_bars = [];
foreach ($top as $c) {
    $top_bars[] = [
        'label' => $c['company_name'],
        'value' => (int) $c['rating_count'],
        'meta'  => sa_num($c['rating_count']) . ' ratings · ' . number_format((float) $c['avg_rating'], 1) . '★',
    ];
}

$star_items = [];
foreach ($stars as $star => $count) {
    $star_items[] = [
        'label' => $star . ' star',
        'value' => (int) $count,
        'meta'  => sa_num($count) . ' · ' . number_format(sa_pct($count, $m['ratings_total'], 0)) . '%',
        'color' => $star >= 4 ? 'linear-gradient(90deg,#a8e030,#c2f542)'
            : ($star === 3 ? 'linear-gradient(90deg,#f59e0b,#fbbf24)' : 'linear-gradient(90deg,#ef4444,#f87171)'),
    ];
}

/* ---------- page meta ---------- */
$pageTitle    = 'Control Center';
$pageHeading  = 'Control center';
$pageSubtitle = 'Platform health, revenue and tenant activity at a glance.';
$activePage   = 'dashboard';
$BASE         = '../';
$extraCss     = ['assets/css/superadmin.css'];
$bodyClass    = 'sa-body';
$searchTarget = '#tenantsTable';
$searchPlaceholder = 'Filter recent tenants…';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<!-- ============ PAGE HEAD ============ -->
<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Dashboard</span>
        </div>
        <h2>Welcome back, <?php echo sa_e($sa_admin_name); ?></h2>
        <p><?php echo sa_e(sa_num($m['tenants_total'])); ?> tenants on the platform,
           <?php echo sa_e(sa_num($m['paying'])); ?> paying and
           <?php echo sa_e(sa_money($m['mrr'])); ?> in monthly recurring revenue.</p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-ghost" data-sa-export="#tenantsTable" data-sa-export-name="optibiz-recent-tenants">
            <?php echo sa_icon('download'); ?> Export
        </button>
        <a class="sa-btn sa-btn-primary" href="tenants.php">
            <?php echo sa_icon('plus'); ?> Add tenant
        </a>
    </div>
</div>

<?php if ($m['quotes_pending'] > 0 || $m['expiring_soon'] > 0): ?>
<!-- ============ ATTENTION NEEDED ============ -->
<div class="sa-alert sa-alert-warning" data-sa-alert>
    <?php echo sa_icon('alert'); ?>
    <div>
        <strong>Needs your attention</strong>
        <?php
        $bits = [];
        if ($m['quotes_pending'] > 0) {
            $bits[] = '<a href="quote_requests.php" style="color:inherit;font-weight:700;text-decoration:underline">'
                . sa_e(sa_num($m['quotes_pending']) . ' pending quote request' . ($m['quotes_pending'] === 1 ? '' : 's')) . '</a>';
        }
        if ($m['expired'] > 0) {
            $bits[] = sa_e(sa_num($m['expired']) . ' subscription' . ($m['expired'] === 1 ? '' : 's') . ' already past its end date');
        }
        $soon_left = max(0, $m['expiring_soon'] - $m['expired']);
        if ($soon_left > 0) {
            $bits[] = '<a href="subscriptions.php" style="color:inherit;font-weight:700;text-decoration:underline">'
                . sa_e(sa_num($soon_left) . ' renewing or expiring within 30 days') . '</a>';
        }
        echo implode(' &middot; ', $bits) . '.';
        ?>
    </div>
</div>
<?php endif; ?>

<!-- ============ KPI ROW ============ -->
<div class="sa-grid sa-kpis sa-anim">

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Monthly recurring revenue</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('dollar'); ?></span>
        </div>
        <div class="sa-kpi-value">
            <span data-sa-count="<?php echo sa_e(number_format($m['mrr'], 2, '.', '')); ?>"
                  data-sa-decimals="2" data-sa-prefix="<?php echo sa_e(sa_currency_symbol($conn)); ?>"><?php echo sa_e(sa_money($m['mrr'])); ?></span>
        </div>
        <div class="sa-kpi-foot">
            <?php echo sa_delta($mrr_delta); ?>
            <?php echo sa_sparkline($spark_mrr); ?>
        </div>
        <div class="sa-kpi-note">vs last month · <?php echo sa_e(sa_money($m['arr'], 0)); ?> ARR</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Total tenants</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('building'); ?></span>
        </div>
        <div class="sa-kpi-value">
            <span data-sa-count="<?php echo (int) $m['tenants_total']; ?>"><?php echo sa_e(sa_num($m['tenants_total'])); ?></span>
        </div>
        <div class="sa-kpi-foot">
            <?php echo sa_delta($tenants_delta); ?>
            <?php echo sa_sparkline($spark_tenants); ?>
        </div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_num($m['new_tenants_30d'])); ?> joined in the last 30 days</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-success);--kpi-soft:var(--sa-success-soft);--kpi-line:var(--sa-success-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Active subscriptions</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('card'); ?></span>
        </div>
        <div class="sa-kpi-value">
            <span data-sa-count="<?php echo (int) $m['paying']; ?>"><?php echo sa_e(sa_num($m['paying'])); ?></span>
        </div>
        <div class="sa-kpi-foot">
            <span class="sa-pill"><?php echo sa_e(number_format($m['trial_conversion'], 0)); ?>% of base paying</span>
            <?php echo sa_sparkline($spark_mrr); ?>
        </div>
        <div class="sa-kpi-note"><?php echo sa_e(sa_num($m['status']['trial'])); ?> on trial · <?php echo sa_e(sa_num($m['status']['cancelled'])); ?> cancelled</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Ratings collected</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('star'); ?></span>
        </div>
        <div class="sa-kpi-value">
            <span data-sa-count="<?php echo (int) $m['ratings_total']; ?>"><?php echo sa_e(sa_num($m['ratings_total'])); ?></span>
        </div>
        <div class="sa-kpi-foot">
            <?php echo sa_delta($m['ratings_delta']); ?>
            <?php echo sa_sparkline($ratings_spark, 88, 30); ?>
        </div>
        <div class="sa-kpi-note"><?php echo $m['avg_rating'] > 0 ? sa_stars($m['avg_rating']) : 'No ratings yet'; ?></div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-violet);--kpi-soft:var(--sa-violet-soft);--kpi-line:var(--sa-violet-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Quote pipeline</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('inbox'); ?></span>
        </div>
        <div class="sa-kpi-value">
            <span data-sa-count="<?php echo (int) $m['quotes_pending']; ?>"><?php echo sa_e(sa_num($m['quotes_pending'])); ?></span><small>pending</small>
        </div>
        <div class="sa-kpi-foot">
            <span class="sa-pill"><?php echo sa_e(sa_num($m['quotes_total'])); ?> total requests</span>
            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="quote_requests.php">Review</a>
        </div>
        <div class="sa-kpi-note">Inbound leads from the public “Get started” form</div>
    </article>

</div>

<!-- ============ TRENDS ============ -->
<div class="sa-grid sa-split-2-1">

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Revenue &amp; tenant growth</h3>
                <p>Cumulative MRR against newly signed tenants, last 12 months</p>
            </div>
            <div class="sa-card-head-actions">
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-lime);display:inline-block"></i> MRR</span>
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-info);display:inline-block"></i> New tenants</span>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_line_chart($trend_label, [
                ['name' => 'MRR', 'values' => $trend_mrr, 'color' => 'lime', 'format' => 'money'],
                ['name' => 'New tenants', 'values' => $trend_new, 'color' => 'info', 'format' => 'number', 'dashed' => true],
            ], ['height' => 268, 'format' => 'money']); ?>
        </div>
        <div class="sa-card-foot">
            <span><?php echo sa_e(sa_money($mrr_now)); ?> MRR today &middot; <?php echo sa_e(sa_money($m['arpu'])); ?> average per paying tenant</span>
            <a href="analytics.php">Full analytics <?php echo sa_icon('chevron-right'); ?></a>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Plan distribution</h3>
                <p>Active and trial tenants per plan</p>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_donut($plan_segments, [
                'value' => sa_num($m['paying'] + $m['status']['trial']),
                'label' => 'Subscribed',
            ]); ?>
        </div>
        <div class="sa-card-foot">
            <span><?php echo sa_e(sa_num($m['plans_active'])); ?> plans on sale</span>
            <a href="plans.php">Manage plans <?php echo sa_icon('chevron-right'); ?></a>
        </div>
    </section>

</div>

<!-- ============ TENANT HEALTH + RENEWALS ============ -->
<div class="sa-grid sa-split-2-1 sa-mt">

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Recent tenants</h3>
                <p>Newest companies onboarded to the platform</p>
            </div>
            <div class="sa-card-head-actions">
                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenants.php">View all</a>
            </div>
        </div>

        <div class="sa-table-wrap">
            <table class="sa-table" id="tenantsTable" data-sa-sortable-table>
                <thead scope="col">
                    <tr>
                        <th data-sa-sort="0" scope="col" aria-sort="none">Company</th>
                        <th data-sa-sort="1" scope="col" aria-sort="none">Plan</th>
                        <th data-sa-sort="2" data-type="num" scope="col" aria-sort="none">MRR</th>
                        <th data-sa-sort="3" scope="col" aria-sort="none">Status</th>
                        <th data-sa-sort="4" data-type="date" scope="col" aria-sort="none">Joined</th>
                        <th data-no-export scope="col"><span class="sa-sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
<?php if (!$recent_tenants): ?>
                    <tr>
                        <td colspan="6">
                            <div class="sa-empty">
                                <?php echo sa_icon('building'); ?>
                                <strong>No tenants yet</strong>
                                <p>Once you create your first tenant it will show up here along with its plan and status.</p>
                            </div>
                        </td>
                    </tr>
<?php else: ?>
<?php foreach ($recent_tenants as $t): ?>
<?php
    $search_blob = strtolower(implode(' ', [
        $t['company_name'], $t['email'], $t['plan_name'], $t['subscription_status'],
    ]));
?>
                    <tr data-search="<?php echo sa_e($search_blob); ?>">
                        <td>
                            <div class="sa-cell-main">
                                <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($t['company_name'])); ?></span>
                                <span class="sa-cell-text">
                                    <strong><?php echo sa_e($t['company_name']); ?></strong>
                                    <span><?php echo sa_e($t['email']); ?></span>
                                </span>
                            </div>
                        </td>
                        <td><span class="sa-badge sa-badge-plan"><?php echo sa_e($t['plan_name'] ? $t['plan_name'] : 'No plan'); ?></span></td>
                        <td class="num" data-sort-value="<?php echo sa_e($t['subscription_price']); ?>" data-export-value="<?php echo sa_e($t['subscription_price']); ?>"><?php echo sa_e(sa_money($t['subscription_price'])); ?></td>
                        <td><?php echo sa_status_badge($t['subscription_status']); ?></td>
                        <td data-sort-value="<?php echo sa_e($t['created_at']); ?>">
                            <span title="<?php echo sa_e(sa_date($t['created_at'], 'M d, Y H:i')); ?>"><?php echo sa_time_ago($t['created_at']); ?></span>
                        </td>
                        <td data-no-export>
                            <div class="sa-row-actions">
                                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="tenant_details.php?id=<?php echo (int) $t['id']; ?>" title="Open tenant">
                                    <?php echo sa_icon('eye'); ?> View
                                </a>
                            </div>
                        </td>
                    </tr>
<?php endforeach; ?>
<?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="sa-empty" id="tenantsTableEmpty" hidden>
            <?php echo sa_icon('search'); ?>
            <strong>No matches</strong>
            <p>Try a different company name, email or plan.</p>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Renewals &amp; churn risk</h3>
                <p>Subscriptions ending within 30 days</p>
            </div>
            <span class="sa-pill"><?php echo sa_e(sa_num($m['expiring_soon'])); ?> flagged</span>
        </div>

<?php if (!$expiring): ?>
        <div class="sa-empty">
            <?php echo sa_icon('check-circle'); ?>
            <strong>Nothing expiring soon</strong>
            <p>No active or trial subscription ends in the next 30 days.</p>
        </div>
<?php else: ?>
        <div class="sa-list">
<?php foreach ($expiring as $s): ?>
<?php list($badge_html, $kind) = sa_renewal_badge($s['subscription_end_date'], $s['auto_renew']); ?>
            <a class="sa-list-item" href="tenant_details.php?id=<?php echo (int) $s['id']; ?>">
                <span class="sa-list-icon <?php echo $kind === 'expired' || $kind === 'due' ? 'is-danger' : 'is-warning'; ?>">
                    <?php echo sa_icon($kind === 'ok' ? 'calendar' : 'clock'); ?>
                </span>
                <span class="sa-list-body">
                    <strong><?php echo sa_e($s['company_name']); ?></strong>
                    <span><?php echo sa_e(($s['plan_name'] ? $s['plan_name'] . ' · ' : '') . sa_money($s['subscription_price']) . '/mo'); ?></span>
                </span>
                <span class="sa-list-side">
                    <?php echo $badge_html; ?>
                    <strong style="margin-top:4px"><?php echo sa_e(sa_date($s['subscription_end_date'])); ?></strong>
                </span>
            </a>
<?php endforeach; ?>
        </div>
<?php endif; ?>
        <div class="sa-card-foot">
            <span><?php echo sa_e(sa_num($m['expired'])); ?> already expired</span>
            <a href="subscriptions.php">All subscriptions <?php echo sa_icon('chevron-right'); ?></a>
        </div>
    </section>

</div>

<!-- ============ ENGAGEMENT ============ -->
<div class="sa-grid sa-cols-3 sa-mt">

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Top rated companies</h3>
                <p>Most reviewed across all tenants</p>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_bar_list($top_bars); ?>
        </div>
        <div class="sa-card-foot">
            <span><?php echo sa_e(sa_num($m['customers_total'])); ?> companies listed</span>
            <a href="analytics.php">See ranking <?php echo sa_icon('chevron-right'); ?></a>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Rating breakdown</h3>
                <p><?php echo $m['avg_rating'] > 0 ? sa_stars($m['avg_rating']) : 'No ratings recorded yet'; ?></p>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_bar_list($star_items); ?>
        </div>
        <div class="sa-card-foot">
            <span><?php echo sa_e(number_format(sa_pct($m['five_star'], $m['ratings_total'], 0))); ?>% are 5-star</span>
            <span><?php echo sa_e(sa_num($m['new_ratings_30d'])); ?> in the last 30 days</span>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Tenant health</h3>
                <p>Where the <?php echo sa_e(sa_num($m['tenants_total'])); ?> tenants stand</p>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_donut($status_segments, [
                'value' => sa_num($m['tenants_total']),
                'label' => 'Tenants',
            ], 150); ?>
            <div class="sa-section-title" style="margin:22px 0 11px">Rating activity</div>
            <?php echo sa_heatmap($heat_cells, 18); ?>
            <p class="sa-faint" style="font-size:11.6px;margin-top:9px">Last 54 days &middot; darker means more ratings</p>
        </div>
    </section>

</div>

<!-- ============ PIPELINE + ACTIVITY ============ -->
<div class="sa-grid sa-split-2-1 sa-mt">

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Quote requests</h3>
                <p>Inbound interest from the public “Get started” wizard</p>
            </div>
            <div class="sa-card-head-actions">
                <a class="sa-btn sa-btn-sm sa-btn-ghost" href="quote_requests.php">Open pipeline</a>
            </div>
        </div>
<?php if (!$quotes): ?>
        <div class="sa-empty">
            <?php echo sa_icon('inbox'); ?>
            <strong>No quote requests yet</strong>
            <p>Submissions from the public site land here so you can convert them into tenants.</p>
        </div>
<?php else: ?>
        <div class="sa-list">
<?php foreach ($quotes as $q): ?>
            <a class="sa-list-item" href="quote_requests.php?id=<?php echo (int) $q['id']; ?>">
                <span class="sa-list-icon <?php echo $q['status'] === 'converted' ? 'is-success' : ($q['status'] === 'rejected' ? 'is-danger' : 'is-info'); ?>">
                    <?php echo sa_icon($q['status'] === 'converted' ? 'check-circle' : 'file-text'); ?>
                </span>
                <span class="sa-list-body">
                    <strong><?php echo sa_e($q['company_name']); ?></strong>
                    <span><?php echo sa_e(trim(($q['contact_person'] ? $q['contact_person'] . ' · ' : '') . $q['email'], ' ·')); ?></span>
                </span>
                <span class="sa-list-side">
                    <?php echo sa_status_badge($q['status']); ?>
                    <strong style="margin-top:4px"><?php echo sa_e($q['plan_name'] ? $q['plan_name'] : 'No plan'); ?></strong>
                </span>
            </a>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Activity feed</h3>
                <p>Latest events across the platform</p>
            </div>
            <button type="button" class="sa-icon-btn" onclick="window.location.reload()" title="Refresh" aria-label="Refresh">
                <?php echo sa_icon('refresh'); ?>
            </button>
        </div>
<?php if (!$activity): ?>
        <div class="sa-empty">
            <?php echo sa_icon('activity'); ?>
            <strong>Nothing happening yet</strong>
            <p>New tenants, ratings and quote requests will appear here in real time.</p>
        </div>
<?php else: ?>
        <div class="sa-list">
<?php foreach ($activity as $a): ?>
            <div class="sa-list-item">
                <span class="sa-list-icon <?php echo $a['type'] === 'rating' ? 'is-warning' : ($a['type'] === 'quote' ? 'is-info' : ''); ?>">
                    <?php echo sa_icon($a['type'] === 'rating' ? 'star' : ($a['type'] === 'quote' ? 'message' : 'building')); ?>
                </span>
                <span class="sa-list-body">
                    <strong><?php echo sa_e($a['title']); ?></strong>
                    <span><?php echo sa_e($a['meta']); ?></span>
                </span>
                <span class="sa-list-side"><?php echo sa_time_ago($a['at']); ?></span>
            </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </section>

</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>
