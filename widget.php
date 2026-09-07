<?php
/**
 * Optibiz Embeddable Rating & Review Widget
 *
 * Usage:
 *   <iframe src="https://yourdomain.com/widget.php?tenant=3&theme=light&layout=card"
 *           width="100%" height="340" frameborder="0" style="border:none;overflow:hidden;border-radius:12px;"></iframe>
 *
 * Parameters:
 *   - tenant:       Tenant workspace ID (e.g. ?tenant=3)
 *   - company:      Company ID (e.g. ?company=4)
 *   - theme:        light | dark (default: light)
 *   - layout:       card | badge (default: card)
 *   - compact:      1 (shortcut for layout=badge)
 *   - hide_reviews: 1 (hides customer testimonials in card layout)
 */

// Allow embedding in external iframes
if (!headers_sent()) {
    header('X-Frame-Options: ALLOWALL');
    header("Content-Security-Policy: frame-ancestors *;");
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Request Parameters
$tenant_id    = isset($_GET['tenant']) ? (int)$_GET['tenant'] : 0;
$company_id   = isset($_GET['company']) ? (int)$_GET['company'] : 0;
$theme        = isset($_GET['theme']) && strtolower(trim($_GET['theme'])) === 'dark' ? 'dark' : 'light';
$layout       = isset($_GET['layout']) && strtolower(trim($_GET['layout'])) === 'badge' ? 'badge' : 'card';
if (!empty($_GET['compact'])) {
    $layout = 'badge';
}
$show_reviews = empty($_GET['hide_reviews']);

$company     = null;
$tenant_info = null;

// Resolve Tenant & Company
if ($tenant_id > 0) {
    $t_stmt = $conn->prepare("SELECT id, company_name, email, logo FROM tenants WHERE id = ? LIMIT 1");
    $t_stmt->bind_param("i", $tenant_id);
    $t_stmt->execute();
    $tenant_info = $t_stmt->get_result()->fetch_assoc();

    $c_stmt = $conn->prepare("SELECT * FROM customers WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
    $c_stmt->bind_param("i", $tenant_id);
    $c_stmt->execute();
    $company = $c_stmt->get_result()->fetch_assoc();
} elseif ($company_id > 0) {
    $c_stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $c_stmt->bind_param("i", $company_id);
    $c_stmt->execute();
    $company = $c_stmt->get_result()->fetch_assoc();

    if ($company && !empty($company['tenant_id'])) {
        $t_stmt = $conn->prepare("SELECT id, company_name, email, logo FROM tenants WHERE id = ? LIMIT 1");
        $t_stmt->bind_param("i", $company['tenant_id']);
        $t_stmt->execute();
        $tenant_info = $t_stmt->get_result()->fetch_assoc();
        $tenant_id   = (int)$company['tenant_id'];
    }
}

if (!$company && !$tenant_info) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:sans-serif;background:transparent;color:#94a3b8;text-align:center;padding:20px;font-size:13px;}</style></head><body>No review profile found for this widget.</body></html>';
    exit;
}

// Brand Details
$brand_name     = !empty($tenant_info['company_name']) ? $tenant_info['company_name'] : ($company['company_name'] ?? 'Verified Business');
$brand_logo     = !empty($tenant_info['logo']) ? $tenant_info['logo'] : ($company['logo'] ?? '');
$brand_initials = strtoupper(substr($brand_name, 0, 2));

// Review & Score Stats
$actual_company_id = $company ? (int)$company['id'] : 0;
$avg_rating        = $actual_company_id > 0 ? getAverageRating($actual_company_id, $conn) : 5.0;
$total_ratings     = $actual_company_id > 0 ? getRatingCount($actual_company_id, $conn) : 0;

// Top Testimonials (Prefer Verified Reviews with Comments)
$top_reviews = [];
if ($actual_company_id > 0 && $show_reviews) {
    $r_stmt = $conn->prepare("SELECT customer_name, rating, comment, is_verified, verification_type, created_at 
                              FROM ratings 
                              WHERE company_id = ? AND comment != '' AND comment IS NOT NULL 
                              ORDER BY is_verified DESC, rating DESC, created_at DESC 
                              LIMIT 2");
    $r_stmt->bind_param("i", $actual_company_id);
    $r_stmt->execute();
    $top_reviews = $r_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Target URL for clicks (tagged with &src=widget for analytics)
$public_url = 'rate/index.php?' . ($tenant_id > 0 ? 'tenant=' . $tenant_id : 'company=' . $actual_company_id) . '&src=widget';

// Log widget view event
if ($actual_company_id > 0) {
    @logAnalyticsEvent($conn, (int)($tenant_info['id'] ?? $tenant_id), $actual_company_id, 'widget_view', 'embed_widget', $layout, 'widget');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($brand_name); ?> Reviews Widget</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: transparent;
            color: <?php echo $theme === 'dark' ? '#f8fafc' : '#0f172a'; ?>;
            line-height: 1.4;
            -webkit-font-smoothing: antialiased;
        }

        a { text-decoration: none; color: inherit; }

        /* ============================================================
           THEME VARIABLES
           ============================================================ */
        <?php if ($theme === 'dark'): ?>
        :root {
            --w-bg: #091a27;
            --w-surface: #0f2438;
            --w-border: #1e3a5f;
            --w-ink: #f8fafc;
            --w-muted: #94a3b8;
            --w-star: #fbbf24;
            --w-lime: #c2f542;
            --w-verified-bg: rgba(34, 197, 94, 0.18);
            --w-verified-color: #4ade80;
            --w-verified-border: rgba(34, 197, 94, 0.35);
            --w-btn-bg: #c2f542;
            --w-btn-text: #091a27;
            --w-btn-hover: #b4ea35;
            --w-shadow: 0 4px 20px rgba(0,0,0,0.35);
        }
        <?php else: ?>
        :root {
            --w-bg: #ffffff;
            --w-surface: #f8fafc;
            --w-border: #e2e8f0;
            --w-ink: #0f172a;
            --w-muted: #64748b;
            --w-star: #f59e0b;
            --w-lime: #10b981;
            --w-verified-bg: #dcfce7;
            --w-verified-color: #15803d;
            --w-verified-border: #86efac;
            --w-btn-bg: #0f172a;
            --w-btn-text: #ffffff;
            --w-btn-hover: #1e293b;
            --w-shadow: 0 2px 14px rgba(15, 23, 42, 0.06);
        }
        <?php endif; ?>

        /* ============================================================
           COMPACT BADGE LAYOUT
           ============================================================ */
        .widget-badge {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: var(--w-bg);
            border: 1px solid var(--w-border);
            border-radius: 99px;
            padding: 8px 18px 8px 10px;
            box-shadow: var(--w-shadow);
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
            max-width: 100%;
        }
        .widget-badge:hover {
            transform: translateY(-1px);
            border-color: var(--w-star);
        }
        .badge-logo {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--w-surface);
            display: grid;
            place-items: center;
            font-weight: 800;
            font-size: 13px;
            color: var(--w-ink);
            flex-shrink: 0;
            border: 1px solid var(--w-border);
        }
        .badge-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .badge-score {
            font-size: 15px;
            font-weight: 800;
            color: var(--w-ink);
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .badge-stars {
            color: var(--w-star);
            font-size: 14px;
            letter-spacing: 1px;
        }
        .badge-meta {
            font-size: 12px;
            color: var(--w-muted);
            white-space: nowrap;
        }
        .badge-tag {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: var(--w-verified-bg);
            color: var(--w-verified-color);
            border: 1px solid var(--w-verified-border);
            border-radius: 99px;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
        }
        .badge-tag svg {
            width: 10px;
            height: 10px;
            fill: currentColor;
        }

        /* ============================================================
           CARD LAYOUT
           ============================================================ */
        .widget-card {
            background: var(--w-bg);
            border: 1px solid var(--w-border);
            border-radius: 16px;
            padding: 20px 22px;
            box-shadow: var(--w-shadow);
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-width: 100%;
        }

        /* Top Header */
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .card-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .card-logo {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            object-fit: cover;
            background: var(--w-surface);
            display: grid;
            place-items: center;
            font-weight: 800;
            font-size: 14px;
            color: var(--w-ink);
            flex-shrink: 0;
            border: 1px solid var(--w-border);
        }
        .card-brand-text {
            min-width: 0;
        }
        .card-brand-name {
            font-size: 15px;
            font-weight: 800;
            color: var(--w-ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .card-badge-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 1px;
        }

        /* Score & Stars Block */
        .score-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 14px;
            background: var(--w-surface);
            border-radius: 12px;
            border: 1px solid var(--w-border);
        }
        .score-big {
            font-size: 32px;
            font-weight: 900;
            color: var(--w-ink);
            line-height: 1;
        }
        .score-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .score-stars {
            color: var(--w-star);
            font-size: 17px;
            letter-spacing: 1.5px;
            line-height: 1;
        }
        .score-count {
            font-size: 12px;
            color: var(--w-muted);
            font-weight: 500;
        }

        /* Review Snippets */
        .reviews-stream {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .review-snippet {
            padding: 10px 12px;
            border-radius: 10px;
            background: var(--w-surface);
            border: 1px solid var(--w-border);
            font-size: 12.5px;
        }
        .review-snippet-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
        }
        .review-author {
            font-weight: 700;
            color: var(--w-ink);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .review-text {
            color: var(--w-muted);
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            font-style: italic;
        }

        /* Footer & CTA */
        .card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding-top: 4px;
            border-top: 1px solid var(--w-border);
        }
        .trust-tag {
            font-size: 11px;
            color: var(--w-muted);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
        }
        .trust-tag strong {
            color: var(--w-ink);
        }
        .cta-btn {
            background: var(--w-btn-bg);
            color: var(--w-btn-text);
            font-size: 12px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all .2s ease;
        }
        .cta-btn:hover {
            background: var(--w-btn-hover);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>

<?php if ($layout === 'badge'): ?>
    <!-- ============================================================
         COMPACT BADGE WIDGET
         ============================================================ -->
    <a href="<?php echo htmlspecialchars($public_url); ?>" target="_blank" rel="noopener" class="widget-badge" title="View customer reviews on Optibiz">
        <?php if (!empty($brand_logo)): ?>
            <img src="<?php echo htmlspecialchars($brand_logo); ?>" alt="<?php echo htmlspecialchars($brand_name); ?>" class="badge-logo">
        <?php else: ?>
            <div class="badge-logo"><?php echo htmlspecialchars($brand_initials); ?></div>
        <?php endif; ?>

        <div class="badge-info">
            <span class="badge-score">
                <?php echo number_format($avg_rating, 1); ?>
                <span class="badge-stars">
                    <?php
                    $full = floor($avg_rating);
                    for ($i = 0; $i < $full; $i++) echo '★';
                    for ($i = $full; $i < 5; $i++) echo '☆';
                    ?>
                </span>
            </span>

            <span class="badge-meta">
                (<?php echo number_format($total_ratings); ?> review<?php echo $total_ratings == 1 ? '' : 's'; ?>)
            </span>

            <span class="badge-tag">
                <svg viewBox="0 0 512 512"><path d="M504 256c0 137-111 248-248 248S8 393 8 256 119 8 256 8s248 111 248 248zM227.3 387.3l184-184c6.2-6.2 6.2-16.4 0-22.6l-22.6-22.6c-6.2-6.2-16.4-6.2-22.6 0L216 308.1l-70.1-70.1c-6.2-6.2-16.4-6.2-22.6 0l-22.6 22.6c-6.2 6.2-6.2 16.4 0 22.6l104 104c6.2 6.2 16.4 6.2 22.6 0z"/></svg>
                Optibiz Verified
            </span>
        </div>
    </a>

<?php else: ?>
    <!-- ============================================================
         FULL CARD WIDGET
         ============================================================ -->
    <div class="widget-card">
        <!-- Brand Header -->
        <div class="card-header">
            <div class="card-brand">
                <?php if (!empty($brand_logo)): ?>
                    <img src="<?php echo htmlspecialchars($brand_logo); ?>" alt="<?php echo htmlspecialchars($brand_name); ?>" class="card-logo">
                <?php else: ?>
                    <div class="card-logo"><?php echo htmlspecialchars($brand_initials); ?></div>
                <?php endif; ?>
                <div class="card-brand-text">
                    <div class="card-brand-name"><?php echo htmlspecialchars($brand_name); ?></div>
                    <div class="card-badge-row">
                        <span class="badge-tag">
                            <svg viewBox="0 0 512 512"><path d="M504 256c0 137-111 248-248 248S8 393 8 256 119 8 256 8s248 111 248 248zM227.3 387.3l184-184c6.2-6.2 6.2-16.4 0-22.6l-22.6-22.6c-6.2-6.2-16.4-6.2-22.6 0L216 308.1l-70.1-70.1c-6.2-6.2-16.4-6.2-22.6 0l-22.6 22.6c-6.2 6.2-6.2 16.4 0 22.6l104 104c6.2 6.2 16.4 6.2 22.6 0z"/></svg>
                            Verified Business
                        </span>
                    </div>
                </div>
            </div>
            <a href="<?php echo htmlspecialchars($public_url); ?>" target="_blank" rel="noopener" class="cta-btn">
                Rate Us ↗
            </a>
        </div>

        <!-- Rating Score Hero -->
        <div class="score-row">
            <div class="score-big"><?php echo number_format($avg_rating, 1); ?></div>
            <div class="score-details">
                <div class="score-stars">
                    <?php
                    $full = floor($avg_rating);
                    for ($i = 0; $i < $full; $i++) echo '★';
                    for ($i = $full; $i < 5; $i++) echo '☆';
                    ?>
                </div>
                <div class="score-count">
                    Based on <strong><?php echo number_format($total_ratings); ?></strong> customer review<?php echo $total_ratings == 1 ? '' : 's'; ?>
                </div>
            </div>
        </div>

        <!-- Verified Reviews Stream -->
        <?php if (!empty($top_reviews)): ?>
            <div class="reviews-stream">
                <?php foreach ($top_reviews as $rev): ?>
                    <div class="review-snippet">
                        <div class="review-snippet-head">
                            <span class="review-author">
                                <?php echo htmlspecialchars($rev['customer_name'] ?: 'Customer'); ?>
                                <?php if (!empty($rev['is_verified'])): ?>
                                    <span class="badge-tag" style="padding:1px 5px;font-size:9.5px;" title="Verified Customer">✓ Verified</span>
                                <?php endif; ?>
                            </span>
                            <span style="color:var(--w-star);font-size:12px;">
                                <?php echo str_repeat('★', (int)$rev['rating']); ?>
                            </span>
                        </div>
                        <p class="review-text">&ldquo;<?php echo htmlspecialchars($rev['comment']); ?>&rdquo;</p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="card-footer">
            <span class="trust-tag">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Verified by <strong>Optibiz</strong>
            </span>
            <a href="<?php echo htmlspecialchars($public_url); ?>" target="_blank" rel="noopener" style="font-size:11.5px;color:var(--w-muted);text-decoration:underline;">
                See all <?php echo number_format($total_ratings); ?> reviews &rarr;
            </a>
        </div>
    </div>
<?php endif; ?>

</body>
</html>
