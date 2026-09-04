<?php
/**
 * ============================================================
 *  Workspace — Analysis
 * ============================================================
 *  Reads the rating responses and turns them into a growth and
 *  progress picture: how much feedback came in, whether the score
 *  is climbing, which companies are pulling ahead or slipping,
 *  what customers keep talking about and what to do next.
 *
 *  Everything on this page is derived from real rows in
 *  `ratings` / `customers` — nothing is hard-coded.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

/* ---------- filters ---------- */
$days       = admin_period_days($_GET['days'] ?? 90);
$company_id = isset($_GET['company_id']) ? (int) $_GET['company_id'] : 0;

$companies_list = admin_companies($conn, $tenant_id, $is_tenant);

// A tenant may only analyse its own companies.
$valid_company = false;
foreach ($companies_list as $row) {
    if ((int) $row['id'] === $company_id) {
        $valid_company = true;
        break;
    }
}
if (!$valid_company) {
    $company_id = 0;
}

$where = admin_scope_sql($tenant_id, $is_tenant);
if ($company_id > 0) {
    $where .= ' AND r.company_id = ' . $company_id . ' ';
}
// The per-company table filters on customers rather than ratings.
$company_where = admin_scope_sql($tenant_id, $is_tenant);
if ($company_id > 0) {
    $company_where .= ' AND c.id = ' . $company_id . ' ';
}

/* ---------- data ---------- */
$current   = admin_response_stats($conn, $where, $days, 0);
$previous  = admin_response_stats($conn, $where, $days, 1);
$trend     = admin_monthly_trend($conn, $where, 12);
$stars     = admin_star_distribution($conn, $where, $days);
$perform   = admin_company_performance($conn, $company_where, $days);
$comments  = admin_recent_comments($conn, $where, $days, 200);
$keywords  = admin_keyword_insights($comments);
$insights  = admin_insights($current, $previous, $perform, $keywords, $days);

$lifetime_responses = (int) admin_scalar(
    $conn,
    "SELECT COUNT(*) FROM ratings r JOIN customers c ON c.id = r.company_id WHERE 1 = 1" . $where,
    0
);

$volume_growth = admin_growth($current['responses'], $previous['responses']);
$score_growth  = ($previous['avg_rating'] > 0)
    ? round($current['avg_rating'] - $previous['avg_rating'], 2)
    : null;
$promoter_share = $current['responses'] > 0
    ? round(($current['promoters'] / $current['responses']) * 100, 1)
    : 0;
$prev_promoter_share = $previous['responses'] > 0
    ? round(($previous['promoters'] / $previous['responses']) * 100, 1)
    : 0;
$promoter_move = $previous['responses'] > 0
    ? round($promoter_share - $prev_promoter_share, 1)
    : null;
$response_rate = $current['responses'] > 0
    ? round(($current['commented'] / $current['responses']) * 100, 1)
    : 0;
$per_week = $days > 0 ? round(($current['responses'] / $days) * 7, 1) : 0;

$improving = 0;
$slipping  = 0;
$active_companies = 0;
foreach ($perform as $row) {
    if ($row['momentum'] === 'improving') {
        $improving++;
    }
    if ($row['momentum'] === 'slipping') {
        $slipping++;
    }
    if ($row['responses'] > 0) {
        $active_companies++;
    }
}

// Low scores worth a call back
$followups = [];
foreach ($comments as $row) {
    if ((int) $row['rating'] <= 2 && count($followups) < 5) {
        $followups[] = $row;
    }
}

$star_total = array_sum($stars);

/* ---------- page ---------- */
$robots    = 'noindex, nofollow';
$BASE      = '../';
$pageTitle = 'Analysis';
$activeNav = 'analysis';
include __DIR__ . '/_shell.php';
?>
        <div class="page-header">
            <div>
                <h1>Response analysis</h1>
                <p>How your companies are growing and progressing, straight from customer responses.</p>
            </div>

            <form method="GET" class="filter-form">
                <select name="company_id" onchange="this.form.submit()" aria-label="Filter by company">
                    <option value="0">All companies</option>
                    <?php foreach ($companies_list as $row): ?>
                        <option value="<?php echo (int) $row['id']; ?>" <?php echo $company_id === (int) $row['id'] ? 'selected' : ''; ?>>
                            <?php echo sa_e($row['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="days" onchange="this.form.submit()" aria-label="Reporting period">
                    <?php foreach (admin_periods() as $value => $label): ?>
                        <option value="<?php echo (int) $value; ?>" <?php echo $days === (int) $value ? 'selected' : ''; ?>>
                            <?php echo sa_e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn btn-secondary">Apply</button></noscript>
            </form>
        </div>

        <div class="metric-grid">
            <div class="metric-card">
                <div class="metric-icon blue">✉</div>
                <span>Responses collected</span>
                <strong><?php echo sa_e(sa_num($current['responses'])); ?></strong>
                <small><?php echo admin_delta_badge($volume_growth); ?> vs previous <?php echo (int) $days; ?> days</small>
            </div>
            <div class="metric-card">
                <div class="metric-icon amber">★</div>
                <span>Average score</span>
                <strong><?php echo $current['avg_rating'] > 0 ? number_format($current['avg_rating'], 2) : '—'; ?><i>/ 5.0</i></strong>
                <small><?php echo admin_delta_badge($score_growth, ' ★'); ?> vs previous period</small>
            </div>
            <div class="metric-card">
                <div class="metric-icon green">↑</div>
                <span>Promoter share</span>
                <strong><?php echo number_format($promoter_share, 1); ?><i>%</i></strong>
                <small><?php echo admin_delta_badge($promoter_move, ' pts'); ?> 4 &amp; 5 star reviews</small>
            </div>
            <div class="metric-card">
                <div class="metric-icon purple">◔</div>
                <span>Companies improving</span>
                <strong><?php echo sa_e(sa_num($improving)); ?><i>/ <?php echo sa_e(sa_num(max(1, count($perform)))); ?></i></strong>
                <small><?php echo $slipping > 0 ? sa_e(sa_num($slipping)) . ' slipping' : 'No company is slipping'; ?></small>
            </div>
        </div>

        <div class="chart-grid">
            <section class="panel performance-panel">
                <div class="panel-head">
                    <div>
                        <h2>Growth over the last 12 months</h2>
                        <p class="muted">Response volume against the average score customers give you</p>
                    </div>
                    <span class="admin-chip"><?php echo sa_e(sa_num($lifetime_responses)); ?> lifetime responses</span>
                </div>
                <div class="chart-legend">
                    <span><i class="legend-line lime"></i> Responses</span>
                    <span><i class="legend-line blue-line"></i> Average score</span>
                </div>
                <?php echo admin_trend_chart($trend); ?>
            </section>

            <section class="panel score-panel">
                <div class="panel-head">
                    <div>
                        <h2>Score breakdown</h2>
                        <p class="muted">Last <?php echo (int) $days; ?> days</p>
                    </div>
                    <span class="total-score"><?php echo $current['avg_rating'] > 0 ? number_format($current['avg_rating'], 1) : '0.0'; ?> <b>★</b></span>
                </div>
                <?php
                $bar_colors = [5 => '#c2f542', 4 => '#7dd3fc', 3 => '#fbbf24', 2 => '#fb923c', 1 => '#f87171'];
                foreach ($stars as $star => $count):
                    $pct = $star_total > 0 ? round(($count / $star_total) * 100) : 0;
                ?>
                    <div class="score-row">
                        <span><?php echo (int) $star; ?> star<?php echo $star === 1 ? '' : 's'; ?></span>
                        <div><i style="width:<?php echo $pct; ?>%;background:<?php echo $bar_colors[$star]; ?>"></i></div>
                        <b><?php echo $pct; ?>%</b>
                    </div>
                <?php endforeach; ?>
                <div class="admin-stat-strip">
                    <div><span>Detractors</span><strong><?php echo sa_e(sa_num($current['detractors'])); ?></strong></div>
                    <div><span>With a comment</span><strong><?php echo number_format($response_rate, 0); ?>%</strong></div>
                    <div><span>Per week</span><strong><?php echo number_format($per_week, 1); ?></strong></div>
                </div>
                <a class="panel-link" href="ratings.php">Open every review →</a>
            </section>
        </div>

        <section class="panel" style="margin-bottom:20px;">
            <div class="panel-head">
                <div>
                    <h2>Company growth &amp; progress</h2>
                    <p class="muted">Each company compared with the previous <?php echo (int) $days; ?> days</p>
                </div>
                <span class="admin-chip"><?php echo sa_e(sa_num($active_companies)); ?> active of <?php echo sa_e(sa_num(count($perform))); ?></span>
            </div>

            <div class="admin-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Company</th>
                            <th scope="col">Responses</th>
                            <th scope="col">Volume change</th>
                            <th scope="col">Score</th>
                            <th scope="col">Score movement</th>
                            <th scope="col">Promoters</th>
                            <th scope="col">Momentum</th>
                            <th scope="col">Last response</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($perform): ?>
                        <?php foreach ($perform as $row): ?>
                            <?php $promo = $row['responses'] > 0 ? round(($row['promoters'] / $row['responses']) * 100) : 0; ?>
                            <tr>
                                <td>
                                    <div class="table-title"><?php echo sa_e($row['company_name']); ?></div>
                                    <div class="table-meta"><?php echo sa_e($row['category_name'] !== '' ? $row['category_name'] : 'Uncategorised'); ?>
                                        · <?php echo sa_e(sa_num($row['lifetime'])); ?> lifetime</div>
                                </td>
                                <td class="table-title"><?php echo sa_e(sa_num($row['responses'])); ?></td>
                                <td><?php echo admin_delta_badge($row['volume_growth']); ?></td>
                                <td>
                                    <div class="table-rating">
                                        ★ <span><?php echo $row['avg_rating'] !== null ? number_format($row['avg_rating'], 2) : '—'; ?></span>
                                    </div>
                                </td>
                                <td><?php echo admin_delta_badge($row['score_move'], ' ★'); ?></td>
                                <td>
                                    <div class="admin-mini-bar" title="<?php echo (int) $promo; ?>% promoters">
                                        <i style="width:<?php echo (int) $promo; ?>%"></i>
                                    </div>
                                    <div class="table-meta"><?php echo (int) $promo; ?>% · <?php echo sa_e(sa_num($row['detractors'])); ?> low</div>
                                </td>
                                <td><?php echo admin_momentum_badge($row['momentum']); ?></td>
                                <td class="table-meta">
                                    <?php echo $row['last_response'] ? sa_e(date('M d, Y', strtotime($row['last_response']))) : 'Never'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="table-empty">No companies to analyse yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="bottom-grid">
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>What customers are talking about</h2>
                        <p class="muted">Words that repeat across <?php echo sa_e(sa_num(count($comments))); ?> written responses</p>
                    </div>
                </div>

                <?php if (!empty($keywords['praise']) || !empty($keywords['problems'])): ?>
                    <div class="admin-keyword-block">
                        <h4>Praised</h4>
                        <?php if ($keywords['praise']): ?>
                            <div class="admin-chips">
                                <?php foreach ($keywords['praise'] as $item): ?>
                                    <span class="admin-chip is-good">
                                        <?php echo sa_e($item['word']); ?> <b><?php echo (int) $item['count']; ?></b>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="muted">No repeated praise yet.</p>
                        <?php endif; ?>
                    </div>
                    <div class="admin-keyword-block">
                        <h4>Complained about</h4>
                        <?php if ($keywords['problems']): ?>
                            <div class="admin-chips">
                                <?php foreach ($keywords['problems'] as $item): ?>
                                    <span class="admin-chip is-bad">
                                        <?php echo sa_e($item['word']); ?> <b><?php echo (int) $item['count']; ?></b>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="muted">Nothing negative repeats — good sign.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No written comments in this period yet.</div>
                <?php endif; ?>

                <hr class="divider">

                <h4 class="admin-subhead">Needs a follow-up call</h4>
                <?php if ($followups): ?>
                    <?php foreach ($followups as $row): ?>
                        <div class="review-row">
                            <div class="review-avatar"><?php echo sa_e(strtoupper(substr((string) $row['customer_name'], 0, 1))); ?></div>
                            <div class="review-body">
                                <strong><?php echo sa_e($row['customer_name']); ?></strong>
                                <small><?php echo sa_e($row['company_name']); ?> · <?php echo sa_e(date('M d, Y', strtotime($row['created_at']))); ?></small>
                                <p><?php echo sa_e($row['comment']); ?></p>
                            </div>
                            <span class="review-stars"><?php echo str_repeat('★', (int) $row['rating']); ?> <b><?php echo (int) $row['rating']; ?>.0</b></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No 1 or 2 star responses in this period.</div>
                <?php endif; ?>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>What to do next</h2>
                        <p class="muted">Generated from the numbers above</p>
                    </div>
                </div>

                <?php if ($insights): ?>
                    <?php foreach ($insights as $insight): ?>
                        <div class="admin-insight is-<?php echo sa_e($insight['tone']); ?>">
                            <span class="admin-insight-dot"></span>
                            <div>
                                <strong><?php echo sa_e($insight['title']); ?></strong>
                                <p><?php echo sa_e($insight['body']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">Nothing needs your attention right now.</div>
                <?php endif; ?>

                <a class="btn btn-primary" style="margin-top:8px;" href="social.php">Turn good reviews into posts</a>
            </section>
        </div>
<?php include __DIR__ . '/_shell_footer.php'; ?>
