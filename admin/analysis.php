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
$active_tab = $_GET['tab'] ?? 'interactions';
if (!in_array($active_tab, ['interactions', 'responses'])) {
    $active_tab = 'interactions';
}

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

// Telemetry & Interaction Analytics (Feature #6)
ensureAnalyticsTable($conn);
$interaction_metrics = getInteractionMetrics($conn, $tenant_id, $company_id, $days);
$wa_breakdown        = getWhatsAppClicksBreakdown($conn, $tenant_id, $company_id, $days);
$source_breakdown    = getTrafficSourceBreakdown($conn, $tenant_id, $company_id, $days);
$device_breakdown    = getDeviceBreakdown($conn, $tenant_id, $company_id, $days);
$daily_trend         = getDailyInteractionTrend($conn, $tenant_id, $company_id, min(30, $days));

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
                <h1><?php echo $active_tab === 'interactions' ? 'Traffic &amp; WhatsApp Analytics' : 'Response analysis'; ?></h1>
                <p><?php echo $active_tab === 'interactions' ? 'Live visitor engagement, counter QR stand scans, WhatsApp lead clicks, and conversion rates.' : 'How your companies are growing and progressing, straight from customer responses.'; ?></p>
            </div>

            <form method="GET" class="filter-form">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
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

        <!-- Sub-Navigation Switcher -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:24px;border-bottom:1px solid var(--line);padding-bottom:14px;flex-wrap:wrap;">
            <a href="analysis.php?tab=interactions&amp;days=<?php echo (int)$days; ?>&amp;company_id=<?php echo (int)$company_id; ?>" class="btn <?php echo $active_tab === 'interactions' ? 'btn-primary' : 'btn-secondary'; ?>" style="display:inline-flex;align-items:center;gap:8px;padding:9px 18px;font-size:13.5px;text-decoration:none;">
                <span>⚡</span> Traffic &amp; WhatsApp Interactions
                <span style="font-size:10.5px;padding:2px 7px;border-radius:99px;background:<?php echo $active_tab === 'interactions' ? '#22c55e' : '#e2e8f0'; ?>;color:<?php echo $active_tab === 'interactions' ? '#ffffff' : '#475569'; ?>;font-weight:700;">Live Telemetry</span>
            </a>
            <a href="analysis.php?tab=responses&amp;days=<?php echo (int)$days; ?>&amp;company_id=<?php echo (int)$company_id; ?>" class="btn <?php echo $active_tab === 'responses' ? 'btn-primary' : 'btn-secondary'; ?>" style="display:inline-flex;align-items:center;gap:8px;padding:9px 18px;font-size:13.5px;text-decoration:none;">
                <span>📊</span> Response &amp; Rating Breakdown
            </a>
        </div>

        <?php if ($active_tab === 'responses'): ?>
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

        <?php else: 
            $views   = $interaction_metrics['page_views'];
            $uniques = $interaction_metrics['unique_visitors'];
            $wa      = $interaction_metrics['whatsapp_clicks'];
            $qr      = $interaction_metrics['qr_scans'];
            $starts  = $interaction_metrics['form_starts'];
            $submits = $interaction_metrics['reviews_submitted'];
            $ctr     = $interaction_metrics['lead_ctr'];
            $cvr     = $interaction_metrics['review_cvr'];

            $total_wa_breakdown = array_sum(array_column($wa_breakdown, 'click_count'));
            $total_source_views = array_sum(array_column($source_breakdown, 'view_count'));
            $total_device_views = array_sum(array_column($device_breakdown, 'count'));
        ?>

        <!-- 4 KPI Metrics -->
        <div class="metric-grid" style="margin-bottom:24px;">
            <div class="metric-card">
                <div class="metric-icon blue">👁</div>
                <span>Page Views</span>
                <strong><?php echo number_format($views); ?></strong>
                <small><?php echo number_format($uniques); ?> unique visitors</small>
            </div>
            <div class="metric-card" style="border:1.5px solid #86efac;background:#f0fdf4;">
                <div class="metric-icon green">💬</div>
                <span>WhatsApp Leads</span>
                <strong style="color:#15803d;"><?php echo number_format($wa); ?></strong>
                <small><?php echo number_format($ctr, 1); ?>% visitor-to-lead CTR</small>
            </div>
            <div class="metric-card">
                <div class="metric-icon purple">◫</div>
                <span>Counter QR Scans</span>
                <strong><?php echo number_format($qr); ?></strong>
                <small>In-store table &amp; stand scans</small>
            </div>
            <div class="metric-card">
                <div class="metric-icon amber">★</div>
                <span>Reviews Submitted</span>
                <strong><?php echo number_format($submits); ?></strong>
                <small><?php echo number_format($cvr, 1); ?>% completion rate</small>
            </div>
        </div>

        <!-- Conversion Funnel Section -->
        <section class="panel" style="margin-bottom:24px;padding:26px;">
            <div class="panel-head" style="margin-bottom:20px;">
                <div>
                    <h2 style="font-size:18px;margin:0 0 4px;">Visitor-to-Customer Conversion Funnel</h2>
                    <p class="muted" style="margin:0;font-size:13px;">Track customer drop-off across each stage of your rating and inquiry journey over the last <?php echo (int)$days; ?> days.</p>
                </div>
                <span class="admin-chip" style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;">
                    <?php echo number_format($ctr, 1); ?>% WhatsApp Lead Rate
                </span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;">
                <!-- Funnel Step 1 -->
                <div style="background:#f8fafc;border:1px solid var(--line);border-radius:14px;padding:18px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;">1. Page Visits</span>
                        <span style="font-size:11.5px;font-weight:700;color:#2563eb;background:#eff6ff;padding:2px 8px;border-radius:99px;">100%</span>
                    </div>
                    <div style="font-size:26px;font-weight:800;color:var(--ink);margin-bottom:10px;"><?php echo number_format($views); ?></div>
                    <div style="width:100%;height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;">
                        <div style="width:100%;height:100%;background:#3b82f6;border-radius:99px;"></div>
                    </div>
                    <small class="muted" style="display:block;margin-top:8px;font-size:11.5px;">All landing visits</small>
                </div>

                <!-- Funnel Step 2 -->
                <?php 
                    $start_pct = $views > 0 ? round(($starts / $views) * 100, 1) : 0;
                ?>
                <div style="background:#f8fafc;border:1px solid var(--line);border-radius:14px;padding:18px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;">2. Form Interacted</span>
                        <span style="font-size:11.5px;font-weight:700;color:#0891b2;background:#ecfeff;padding:2px 8px;border-radius:99px;"><?php echo $start_pct; ?>%</span>
                    </div>
                    <div style="font-size:26px;font-weight:800;color:var(--ink);margin-bottom:10px;"><?php echo number_format($starts); ?></div>
                    <div style="width:100%;height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;">
                        <div style="width:<?php echo min(100, $start_pct); ?>%;height:100%;background:#06b6d4;border-radius:99px;"></div>
                    </div>
                    <small class="muted" style="display:block;margin-top:8px;font-size:11.5px;">Clicked stars or inputs</small>
                </div>

                <!-- Funnel Step 3 -->
                <?php 
                    $sub_pct = $views > 0 ? round(($submits / $views) * 100, 1) : 0;
                ?>
                <div style="background:#f8fafc;border:1px solid var(--line);border-radius:14px;padding:18px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;">3. Reviews Submitted</span>
                        <span style="font-size:11.5px;font-weight:700;color:#d97706;background:#fffbeb;padding:2px 8px;border-radius:99px;"><?php echo $sub_pct; ?>%</span>
                    </div>
                    <div style="font-size:26px;font-weight:800;color:var(--ink);margin-bottom:10px;"><?php echo number_format($submits); ?></div>
                    <div style="width:100%;height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;">
                        <div style="width:<?php echo min(100, $sub_pct); ?>%;height:100%;background:#f59e0b;border-radius:99px;"></div>
                    </div>
                    <small class="muted" style="display:block;margin-top:8px;font-size:11.5px;">Completed feedback</small>
                </div>

                <!-- Funnel Step 4 -->
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;padding:18px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-size:12px;font-weight:700;color:#15803d;text-transform:uppercase;">4. WhatsApp Leads</span>
                        <span style="font-size:11.5px;font-weight:700;color:#15803d;background:#dcfce7;padding:2px 8px;border-radius:99px;"><?php echo $ctr; ?>%</span>
                    </div>
                    <div style="font-size:26px;font-weight:800;color:#15803d;margin-bottom:10px;"><?php echo number_format($wa); ?></div>
                    <div style="width:100%;height:8px;background:#dcfce7;border-radius:99px;overflow:hidden;">
                        <div style="width:<?php echo min(100, $ctr); ?>%;height:100%;background:#10b981;border-radius:99px;"></div>
                    </div>
                    <small style="display:block;margin-top:8px;font-size:11.5px;color:#166534;font-weight:600;">Started 1-on-1 chat</small>
                </div>
            </div>
        </section>

        <!-- Two-Column Breakdown -->
        <div class="grid-2col" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:24px;margin-bottom:24px;">
            
            <!-- WhatsApp Leads by Placement -->
            <section class="panel" style="padding:24px;">
                <div class="panel-head" style="margin-bottom:16px;">
                    <div>
                        <h2 style="font-size:16px;margin:0 0 4px;">WhatsApp Leads by Placement</h2>
                        <p class="muted" style="margin:0;font-size:12.5px;">Which buttons are prompting customers to reach out on WhatsApp?</p>
                    </div>
                    <span class="admin-chip"><?php echo number_format($wa); ?> total</span>
                </div>

                <?php if (!empty($wa_breakdown)): ?>
                    <div style="display:flex;flex-direction:column;gap:14px;">
                        <?php foreach ($wa_breakdown as $item): 
                            $cat = $item['category'];
                            $cnt = $item['click_count'];
                            $pct = $total_wa_breakdown > 0 ? round(($cnt / $total_wa_breakdown) * 100, 1) : 0;
                            
                            $label = 'Direct WhatsApp Inquiry';
                            $icon  = '💬';
                            if ($cat === 'floating_button') {
                                $label = 'Floating Sticky WhatsApp Button';
                                $icon  = '🟢';
                            } elseif ($cat === 'review_inquiry') {
                                $label = 'Review-Level Inquiries ("Inquire on WhatsApp")';
                                $icon  = '📝';
                            } elseif ($cat === 'qa_inquiry') {
                                $label = 'Community Q&A Inquiries';
                                $icon  = '💡';
                            }
                        ?>
                            <div style="background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid var(--line);">
                                <div style="display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:700;margin-bottom:6px;">
                                    <span><?php echo $icon . ' ' . htmlspecialchars($label); ?></span>
                                    <span><?php echo number_format($cnt); ?> <small class="muted" style="font-weight:normal;">(<?php echo $pct; ?>%)</small></span>
                                </div>
                                <div style="width:100%;height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden;">
                                    <div style="width:<?php echo $pct; ?>%;height:100%;background:#10b981;border-radius:99px;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:30px 10px;text-align:center;">No WhatsApp clicks recorded in this period yet.</div>
                <?php endif; ?>

                <div style="margin-top:16px;background:#ecfdf5;border:1px solid #a7f3d0;padding:12px 14px;border-radius:10px;font-size:12px;color:#065f46;line-height:1.5;">
                    💡 <strong>Conversion Insight:</strong> Review-level inquiry buttons generate the highest qualified leads because customers are reaching out with direct buying intent after reading verified experiences.
                </div>
            </section>

            <!-- Traffic Channels & Devices -->
            <section class="panel" style="padding:24px;">
                <div class="panel-head" style="margin-bottom:16px;">
                    <div>
                        <h2 style="font-size:16px;margin:0 0 4px;">Inbound Traffic Channels &amp; Devices</h2>
                        <p class="muted" style="margin:0;font-size:12.5px;">Where are your page visitors coming from?</p>
                    </div>
                    <span class="admin-chip"><?php echo number_format($views); ?> views</span>
                </div>

                <h4 style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;margin:0 0 10px;">Traffic Channels</h4>
                <?php if (!empty($source_breakdown)): ?>
                    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px;">
                        <?php foreach ($source_breakdown as $src): 
                            $sKey = $src['source'];
                            $sCnt = $src['view_count'];
                            $sPct = $total_source_views > 0 ? round(($sCnt / $total_source_views) * 100, 1) : 0;
                            
                            $sLabel = 'Direct / Organic Link';
                            $sIcon  = '🔗';
                            if ($sKey === 'qr') {
                                $sLabel = 'Counter QR Stand Scan';
                                $sIcon  = '◫';
                            } elseif ($sKey === 'whatsapp_invite') {
                                $sLabel = 'WhatsApp Review Invite Link';
                                $sIcon  = '💬';
                            } elseif ($sKey === 'widget') {
                                $sLabel = 'Website Embed Widget';
                                $sIcon  = '💻';
                            }
                        ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;font-size:13px;">
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    <span><?php echo $sIcon; ?></span>
                                    <strong><?php echo htmlspecialchars($sLabel); ?></strong>
                                </span>
                                <span style="font-weight:700;color:var(--ink);"><?php echo number_format($sCnt); ?> <small class="muted" style="font-weight:normal;">(<?php echo $sPct; ?>%)</small></span>
                            </div>
                            <div style="width:100%;height:5px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin-bottom:4px;">
                                <div style="width:<?php echo $sPct; ?>%;height:100%;background:#6366f1;border-radius:99px;"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:15px;margin-bottom:20px;">No traffic sources recorded yet.</div>
                <?php endif; ?>

                <h4 style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;margin:0 0 10px;">Device Distribution</h4>
                <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:10px;">
                    <?php 
                        $dev_counts = ['mobile' => 0, 'desktop' => 0, 'tablet' => 0];
                        foreach ($device_breakdown as $db) {
                            $dev_counts[$db['device']] = $db['count'];
                        }
                    ?>
                    <div style="background:#f8fafc;padding:12px;border-radius:10px;text-align:center;border:1px solid var(--line);">
                        <div style="font-size:20px;margin-bottom:2px;">📱</div>
                        <strong style="font-size:14px;color:var(--ink);display:block;"><?php echo number_format($dev_counts['mobile']); ?></strong>
                        <span style="font-size:11px;color:var(--muted);">Mobile</span>
                    </div>
                    <div style="background:#f8fafc;padding:12px;border-radius:10px;text-align:center;border:1px solid var(--line);">
                        <div style="font-size:20px;margin-bottom:2px;">💻</div>
                        <strong style="font-size:14px;color:var(--ink);display:block;"><?php echo number_format($dev_counts['desktop']); ?></strong>
                        <span style="font-size:11px;color:var(--muted);">Desktop</span>
                    </div>
                    <div style="background:#f8fafc;padding:12px;border-radius:10px;text-align:center;border:1px solid var(--line);">
                        <div style="font-size:20px;margin-bottom:2px;">📟</div>
                        <strong style="font-size:14px;color:var(--ink);display:block;"><?php echo number_format($dev_counts['tablet']); ?></strong>
                        <span style="font-size:11px;color:var(--muted);">Tablet</span>
                    </div>
                </div>
            </section>

        </div>

        <!-- Daily Trend Timeline -->
        <section class="panel" style="padding:26px;margin-bottom:24px;">
            <div class="panel-head" style="margin-bottom:20px;flex-wrap:wrap;gap:12px;">
                <div>
                    <h2 style="font-size:18px;margin:0 0 4px;">Daily Activity Timeline</h2>
                    <p class="muted" style="margin:0;font-size:13px;">Daily Page Views vs. WhatsApp Conversations initiated over the last <?php echo min(30, (int)$days); ?> days.</p>
                </div>
                <div style="display:flex;align-items:center;gap:14px;font-size:12px;font-weight:700;">
                    <span style="display:inline-flex;align-items:center;gap:6px;color:#2563eb;"><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#3b82f6;"></i> Page Views</span>
                    <span style="display:inline-flex;align-items:center;gap:6px;color:#15803d;"><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#10b981;"></i> WhatsApp Leads</span>
                </div>
            </div>

            <?php 
                $max_views = 1;
                foreach ($daily_trend as $dt) {
                    if ($dt['page_views'] > $max_views) $max_views = $dt['page_views'];
                }
            ?>
            <div style="display:flex;align-items:flex-end;gap:8px;height:160px;padding-top:20px;padding-bottom:10px;border-bottom:1px solid var(--line);overflow-x:auto;">
                <?php foreach ($daily_trend as $d): 
                    $h_view = round(($d['page_views'] / $max_views) * 120);
                    $h_wa   = round(($d['whatsapp_clicks'] / $max_views) * 120);
                ?>
                    <div style="flex:1;min-width:24px;display:flex;flex-direction:column;align-items:center;height:100%;justify-content:flex-end;" title="<?php echo $d['label'] . ': ' . $d['page_views'] . ' views, ' . $d['whatsapp_clicks'] . ' WhatsApp clicks'; ?>">
                        <div style="display:flex;align-items:flex-end;gap:3px;width:100%;justify-content:center;">
                            <div style="width:8px;height:<?php echo max(4, $h_view); ?>px;background:#3b82f6;border-radius:3px 3px 0 0;"></div>
                            <div style="width:8px;height:<?php echo max(2, $h_wa); ?>px;background:#10b981;border-radius:3px 3px 0 0;"></div>
                        </div>
                        <span style="font-size:10px;color:var(--muted);margin-top:6px;white-space:nowrap;transform:rotate(-45deg);transform-origin:left top;"><?php echo $d['label']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

<?php include __DIR__ . '/_shell_footer.php'; ?>
