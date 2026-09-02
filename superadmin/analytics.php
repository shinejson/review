<?php
/**
 * ============================================================
 *  Super Admin — Analytics
 * ============================================================
 *  Revenue, growth, engagement and plan analytics across the
 *  whole platform, with a selectable look-back window.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();

/* ---------- window ---------- */
$months = (int) ($_GET['months'] ?? 12);
if (!in_array($months, [6, 12, 24], true)) {
    $months = 12;
}

$m       = sa_metrics($conn);
$trend   = sa_revenue_trend($conn, $months);
$ratings = sa_ratings_trend($conn, min($months * 30, 180));
$plans   = sa_plan_distribution($conn);
$stars   = sa_star_distribution($conn);
$top     = sa_top_companies($conn, 8);

/* Monthly engagement: ratings per month */
$rating_months = [];
foreach (sa_query(
    $conn,
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt, AVG(rating) AS avg_rating
       FROM ratings GROUP BY ym",
    'ratings'
) as $r) {
    $rating_months[$r['ym']] = $r;
}

/* Tenant acquisition per month */
$acq_months = [];
foreach (sa_query(
    $conn,
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
       FROM tenants GROUP BY ym",
    'tenants'
) as $r) {
    $acq_months[$r['ym']] = (int) $r['cnt'];
}

$labels = [];
$mrr_series = [];
$acq_series = [];
$rating_series = [];
$avg_series = [];
$table_rows = [];
foreach ($trend as $row) {
    $labels[] = $months > 12 ? $row['label'] : $row['label'];
    $mrr_series[] = round((float) $row['mrr'], 2);
    $acq_series[] = (int) $row['new_tenants'];
    $rc = isset($rating_months[$row['ym']]) ? (int) $rating_months[$row['ym']]['cnt'] : 0;
    $av = isset($rating_months[$row['ym']]) ? round((float) $rating_months[$row['ym']]['avg_rating'], 2) : 0;
    $rating_series[] = $rc;
    $avg_series[] = $av;
    $table_rows[] = [
        'label' => $row['label_long'],
        'new_tenants' => (int) $row['new_tenants'],
        'tenants' => (int) $row['tenants'],
        'new_mrr' => (float) $row['new_mrr'],
        'mrr' => (float) $row['mrr'],
        'ratings' => $rc,
        'avg' => $av,
    ];
}

/* Growth deltas: first vs last month of the window */
$first_mrr = $mrr_series ? (float) $mrr_series[0] : 0.0;
$last_mrr = $mrr_series ? (float) end($mrr_series) : 0.0;
$mrr_growth = $first_mrr > 0 ? (($last_mrr - $first_mrr) / $first_mrr) * 100 : ($last_mrr > 0 ? 100.0 : 0.0);
$first_tenants = $trend ? (int) $trend[0]['tenants'] : 0;
$last_tenants = $trend ? (int) end($trend)['tenants'] : 0;
$tenant_growth = $first_tenants > 0 ? (($last_tenants - $first_tenants) / $first_tenants) * 100 : 0.0;
$window_ratings = array_sum($rating_series);
$window_revenue = array_sum(array_map(function ($r) { return $r['new_mrr']; }, $table_rows));

/* Distributions */
$plan_segments = [];
$palette = ['var(--sa-lime)', 'var(--sa-info)', 'var(--sa-violet)', 'var(--sa-warning)', 'var(--sa-success)'];
foreach ($plans as $i => $p) {
    $plan_segments[] = [
        'label' => $p['plan_name'],
        'value' => (int) $p['tenants'],
        'display' => sa_money($p['mrr']) . ' MRR',
        'color' => $palette[$i % count($palette)],
    ];
}
$status_segments = [
    ['label' => 'Active', 'value' => $m['status']['active'], 'color' => 'var(--sa-success)'],
    ['label' => 'Trial', 'value' => $m['status']['trial'], 'color' => 'var(--sa-warning)'],
    ['label' => 'Inactive', 'value' => $m['status']['inactive'], 'color' => 'var(--sa-danger)'],
    ['label' => 'Cancelled', 'value' => $m['status']['cancelled'], 'color' => 'var(--sa-faint)'],
];

$top_bars = [];
foreach ($top as $c) {
    $top_bars[] = [
        'label' => $c['company_name'],
        'value' => (int) $c['rating_count'],
        'meta' => sa_num($c['rating_count']) . ' ratings · ' . number_format((float) $c['avg_rating'], 1) . '★',
    ];
}

$star_items = [];
foreach ($stars as $star => $count) {
    $star_items[] = [
        'label' => $star . ' star',
        'value' => (int) $count,
        'meta' => sa_num($count) . ' · ' . number_format(sa_pct($count, $m['ratings_total'], 0)) . '%',
        'color' => $star >= 4 ? 'linear-gradient(90deg,#a8e030,#c2f542)'
            : ($star === 3 ? 'linear-gradient(90deg,#f59e0b,#fbbf24)' : 'linear-gradient(90deg,#ef4444,#f87171)'),
    ];
}

$heat_cells = array_map(function ($r) {
    return ['label' => $r['label'], 'value' => $r['count']];
}, array_slice($ratings, -84));

/* Rating totals per tenant (for the league table) */
$tenant_league = sa_query(
    $conn,
    "SELECT t.id, t.company_name, t.subscription_status, p.plan_name,
            COUNT(DISTINCT c.id) AS companies,
            COUNT(r.id) AS rating_count,
            COALESCE(AVG(r.rating), 0) AS avg_rating
       FROM tenants t
       LEFT JOIN subscription_plans p ON p.id = t.plan_id
       LEFT JOIN customers c ON c.tenant_id = t.id
       LEFT JOIN ratings r ON r.company_id = c.id
      GROUP BY t.id, t.company_name, t.subscription_status, p.plan_name
      ORDER BY rating_count DESC, avg_rating DESC",
    ['tenants', 'subscription_plans', 'customers', 'ratings']
);

/* ---------- page meta ---------- */
$pageTitle = 'Analytics';
$pageHeading = 'Analytics';
$pageSubtitle = 'Revenue, growth and engagement across every tenant.';
$activePage = 'analytics';
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
            <span>Analytics</span>
        </div>
        <h2>Platform analytics</h2>
        <p>Everything below covers the last <?php echo (int) $months; ?> months.</p>
    </div>
    <div class="sa-head-actions">
        <div class="sa-chips">
<?php foreach ([6 => '6 mo', 12 => '12 mo', 24 => '24 mo'] as $val => $lbl): ?>
            <a class="sa-chip<?php echo $months === $val ? ' active' : ''; ?>" href="analytics.php?months=<?php echo $val; ?>" aria-pressed="<?php echo $months === $val ? 'true' : 'false'; ?>"><?php echo $lbl; ?></a>
<?php endforeach; ?>
        </div>
        <button type="button" class="sa-btn sa-btn-ghost" data-sa-export="#monthlyTable" data-sa-export-name="optibiz-monthly-analytics">
            <?php echo sa_icon('download'); ?> Export
        </button>
    </div>
</div>

<!-- ============ KPIs ============ -->
<div class="sa-grid sa-kpis sa-anim">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-lime);--kpi-soft:var(--sa-accent-soft);--kpi-line:var(--sa-accent-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">MRR growth</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('trending-up'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_delta($mrr_growth, '%', false, 1); ?></div>
        <div class="sa-kpi-foot">
            <span class="sa-kpi-note"><?php echo sa_e(sa_money($first_mrr)); ?> → <?php echo sa_e(sa_money($last_mrr)); ?></span>
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Tenant growth</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('building'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_delta($tenant_growth, '%', false, 1); ?></div>
        <div class="sa-kpi-foot">
            <span class="sa-kpi-note"><?php echo sa_e(sa_num($first_tenants)); ?> → <?php echo sa_e(sa_num($last_tenants)); ?> tenants</span>
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Ratings in window</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('star'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($window_ratings)); ?></div>
        <div class="sa-kpi-foot">
            <span class="sa-kpi-note"><?php echo $m['avg_rating'] > 0 ? sa_stars($m['avg_rating']) : 'No ratings yet'; ?></span>
        </div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-violet);--kpi-soft:var(--sa-violet-soft);--kpi-line:var(--sa-violet-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Revenue added</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('dollar'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_money($window_revenue, 0)); ?></div>
        <div class="sa-kpi-foot">
            <span class="sa-kpi-note">New MRR signed in the window</span>
        </div>
    </article>
</div>

<!-- ============ MAIN CHARTS ============ -->
<div class="sa-grid sa-split-2-1">
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Revenue trend</h3>
                <p>Cumulative monthly recurring revenue</p>
            </div>
            <span class="sa-pill"><?php echo sa_e(sa_money($last_mrr)); ?> today</span>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_line_chart($labels, [
                ['name' => 'MRR', 'values' => $mrr_series, 'color' => 'lime', 'format' => 'money'],
            ], ['height' => 280, 'format' => 'money']); ?>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>New tenants</h3>
                <p>Signups per month</p>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php
            $acq_items = [];
            foreach ($trend as $i => $row) {
                $acq_items[] = ['label' => $row['label'], 'value' => (int) $row['new_tenants']];
            }
            echo sa_bar_chart($acq_items, ['height' => 280, 'format' => 'number']);
            ?>
        </div>
    </section>
</div>

<div class="sa-grid sa-split-2-1 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Ratings volume</h3>
                <p>Reviews collected per month with the average score</p>
            </div>
            <div class="sa-card-head-actions">
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-lime);display:inline-block"></i> Volume</span>
                <span class="sa-pill"><i style="width:9px;height:3px;border-radius:2px;background:var(--sa-info);display:inline-block"></i> Avg score</span>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_line_chart($labels, [
                ['name' => 'Ratings', 'values' => $rating_series, 'color' => 'lime', 'format' => 'number'],
                ['name' => 'Average', 'values' => $avg_series, 'color' => 'info', 'format' => 'decimal', 'dashed' => true],
            ], ['height' => 250, 'format' => 'number']); ?>
        </div>
        <div class="sa-card-foot">
            <span><?php echo sa_e(sa_num($window_ratings)); ?> ratings in the last <?php echo (int) $months; ?> months</span>
            <span><?php echo sa_e(sa_num($m['new_ratings_30d'])); ?> in the last 30 days</span>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Score distribution</h3>
                <p>All ratings ever recorded</p>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_bar_list($star_items); ?>
        </div>
        <div class="sa-card-foot">
            <span><?php echo $m['avg_rating'] > 0 ? sa_stars($m['avg_rating']) : 'No data'; ?></span>
            <span><?php echo sa_e(number_format(sa_pct($m['five_star'], $m['ratings_total'], 0))); ?>% five-star</span>
        </div>
    </section>
</div>

<!-- ============ DISTRIBUTIONS ============ -->
<div class="sa-grid sa-cols-3 sa-mt">
    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Plan mix</h3>
                <p>Tenants and MRR per plan</p>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_donut($plan_segments, ['value' => sa_num($m['paying'] + $m['status']['trial']), 'label' => 'Subscribed'], 150); ?>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Lifecycle</h3>
                <p>Status of every tenant</p>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_donut($status_segments, ['value' => sa_num($m['tenants_total']), 'label' => 'Tenants'], 150); ?>
        </div>
    </section>

    <section class="sa-card">
        <div class="sa-card-head">
            <div>
                <h3>Top companies</h3>
                <p>Most reviewed across all tenants</p>
            </div>
        </div>
        <div class="sa-card-pad">
            <?php echo sa_bar_list($top_bars); ?>
        </div>
    </section>
</div>

<!-- ============ HEATMAP ============ -->
<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <h3>Rating activity</h3>
            <p>Each square is one day — darker means more ratings collected</p>
        </div>
        <span class="sa-pill">Last <?php echo count($heat_cells); ?> days</span>
    </div>
    <div class="sa-card-pad">
        <?php echo sa_heatmap($heat_cells, 21); ?>
    </div>
</section>

<!-- ============ TENANT LEAGUE ============ -->
<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <h3>Tenant league table</h3>
            <p>Engagement per tenant, best first</p>
        </div>
        <div class="sa-card-head-actions">
            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" data-sa-export="#leagueTable" data-sa-export-name="optibiz-tenant-league">
                <?php echo sa_icon('download'); ?> CSV
            </button>
        </div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="leagueTable" data-sa-sortable-table>
            <thead>
                <tr>
                    <th data-sa-sort="0">Tenant</th>
                    <th data-sa-sort="1">Plan</th>
                    <th data-sa-sort="2">Status</th>
                    <th data-sa-sort="3" data-type="num">Companies</th>
                    <th data-sa-sort="4" data-type="num">Ratings</th>
                    <th data-sa-sort="5" data-type="num">Avg score</th>
                    <th data-sa-sort="6" data-type="num">Share</th>
                </tr>
            </thead>
            <tbody>
<?php if (!$tenant_league): ?>
                <tr data-static>
                    <td colspan="7">
                        <div class="sa-empty">
                            <?php echo sa_icon('chart'); ?>
                            <strong>No tenant data yet</strong>
                            <p>Create tenants and their companies to see engagement analytics here.</p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($tenant_league as $row): ?>
<?php $share = sa_pct($row['rating_count'], max(1, $m['ratings_total']), 1); ?>
                <tr>
                    <td>
                        <div class="sa-cell-main">
                            <span class="sa-cell-avatar"><?php echo sa_e(sa_initials($row['company_name'])); ?></span>
                            <span class="sa-cell-text"><strong><?php echo sa_e($row['company_name']); ?></strong></span>
                        </div>
                    </td>
                    <td><span class="sa-badge sa-badge-plan"><?php echo sa_e($row['plan_name'] ? $row['plan_name'] : 'No plan'); ?></span></td>
                    <td><?php echo sa_status_badge($row['subscription_status']); ?></td>
                    <td class="num" data-sort-value="<?php echo (int) $row['companies']; ?>"><?php echo sa_e(sa_num($row['companies'])); ?></td>
                    <td class="num" data-sort-value="<?php echo (int) $row['rating_count']; ?>"><?php echo sa_e(sa_num($row['rating_count'])); ?></td>
                    <td data-sort-value="<?php echo sa_e($row['avg_rating']); ?>"><?php echo $row['rating_count'] > 0 ? sa_stars($row['avg_rating']) : '<span class="sa-faint">—</span>'; ?></td>
                    <td style="min-width:130px" data-sort-value="<?php echo sa_e($share); ?>">
                        <div class="sa-progress"><i style="--w:<?php echo sa_e($share); ?>%"></i></div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ============ MONTHLY BREAKDOWN ============ -->
<section class="sa-card sa-mt">
    <div class="sa-card-head">
        <div>
            <h3>Monthly breakdown</h3>
            <p>The numbers behind the charts above</p>
        </div>
        <div class="sa-card-head-actions">
            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" data-sa-export="#monthlyTable" data-sa-export-name="optibiz-monthly-breakdown">
                <?php echo sa_icon('download'); ?> CSV
            </button>
        </div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="monthlyTable" data-sa-sortable-table>
            <thead>
                <tr>
                    <th data-sa-sort="0" data-type="date">Month</th>
                    <th data-sa-sort="1" data-type="num">New tenants</th>
                    <th data-sa-sort="2" data-type="num">Total tenants</th>
                    <th data-sa-sort="3" data-type="num">New MRR</th>
                    <th data-sa-sort="4" data-type="num">Total MRR</th>
                    <th data-sa-sort="5" data-type="num">Ratings</th>
                    <th data-sa-sort="6" data-type="num">Avg score</th>
                </tr>
            </thead>
            <tbody>
<?php if (!$table_rows): ?>
                <tr data-static>
                    <td colspan="7">
                        <div class="sa-empty"><?php echo sa_icon('calendar'); ?><strong>Nothing to report</strong><p>Once tenants and ratings exist, the monthly breakdown fills in automatically.</p></div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach (array_reverse($table_rows) as $row): ?>
                <tr>
                    <td><?php echo sa_e($row['label']); ?></td>
                    <td class="num"><?php echo sa_e(sa_num($row['new_tenants'])); ?></td>
                    <td class="num"><?php echo sa_e(sa_num($row['tenants'])); ?></td>
                    <td class="num" data-export-value="<?php echo sa_e(number_format($row['new_mrr'], 2, '.', '')); ?>"><?php echo sa_e(sa_money($row['new_mrr'])); ?></td>
                    <td class="num" data-export-value="<?php echo sa_e(number_format($row['mrr'], 2, '.', '')); ?>"><?php echo sa_e(sa_money($row['mrr'])); ?></td>
                    <td class="num"><?php echo sa_e(sa_num($row['ratings'])); ?></td>
                    <td class="num"><?php echo $row['avg'] > 0 ? sa_e(number_format($row['avg'], 2)) . ' ★' : '<span class="sa-faint">—</span>'; ?></td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/_shell_footer.php'; ?>
