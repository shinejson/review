<?php
/**
 * ============================================================
 *  Optibiz workspace (tenant admin) — shared helpers
 * ============================================================
 *  Powers admin/analysis.php, admin/social.php and
 *  admin/subscription.php:
 *
 *    - a self-healing schema for the three tables those screens
 *      need (so an existing install keeps working without a
 *      manual SQL import),
 *    - tolerant query wrappers: a missing table or a failing
 *      query returns empty data instead of a fatal error,
 *    - the analytics maths (growth, momentum, keywords),
 *    - small SVG/markup builders that reuse the classes already
 *      defined in assets/css/admin-dashboard.css.
 *
 *  Requires a mysqli connection in $conn (config/database.php)
 *  and includes/sa_helpers.php for sa_e()/sa_num()/sa_money().
 */

require_once __DIR__ . '/sa_helpers.php';

/* ============================================================
   Schema
   ============================================================ */

if (!function_exists('admin_ensure_schema')) {
    /**
     * Create the workspace tables when they are missing. Runs at
     * most once per request and never throws: on a locked-down
     * database the pages simply fall back to their empty states.
     */
    function admin_ensure_schema($conn)
    {
        static $done = false;
        if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
            return;
        }
        $done = true;

        // Once per signed-in session is enough: the statements below are
        // idempotent, but there is no reason to re-issue them on every click.
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['admin_schema_ok'])) {
            return;
        }

        @$conn->query(
            "CREATE TABLE IF NOT EXISTS subscription_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                current_plan_id INT NULL,
                requested_plan_id INT NOT NULL,
                direction ENUM('upgrade','downgrade','same') DEFAULT 'upgrade',
                note TEXT NULL,
                status ENUM('pending','approved','declined','cancelled') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                INDEX idx_sub_req_tenant (tenant_id),
                INDEX idx_sub_req_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        @$conn->query(
            "CREATE TABLE IF NOT EXISTS social_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                platform VARCHAR(30) NOT NULL,
                account_name VARCHAR(150) NULL,
                account_ref VARCHAR(190) NULL,
                access_token TEXT NULL,
                status ENUM('connected','disabled') DEFAULT 'connected',
                last_error TEXT NULL,
                last_used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_tenant_platform (tenant_id, platform)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        @$conn->query(
            "CREATE TABLE IF NOT EXISTS social_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                company_id INT NULL,
                rating_id INT NULL,
                platform VARCHAR(30) NOT NULL,
                content TEXT NOT NULL,
                status ENUM('draft','published','failed') DEFAULT 'draft',
                remote_id VARCHAR(190) NULL,
                remote_url VARCHAR(255) NULL,
                error TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                published_at DATETIME NULL,
                INDEX idx_social_posts_tenant (tenant_id),
                INDEX idx_social_posts_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['admin_schema_ok'] = 1;
        }
    }
}

/* ============================================================
   Tolerant query wrappers
   ============================================================ */

if (!function_exists('admin_rows')) {
    /** Run a SELECT and return every row; [] when it fails. */
    function admin_rows($conn, $sql)
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return [];
        }
        $res = @$conn->query($sql);
        if (!$res || !is_object($res)) {
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        if (method_exists($res, 'close')) {
            @$res->close();
        }
        return $rows;
    }
}

if (!function_exists('admin_row')) {
    /** First row of a SELECT, or [] when there is none. */
    function admin_row($conn, $sql)
    {
        $rows = admin_rows($conn, $sql);
        return $rows ? $rows[0] : [];
    }
}

if (!function_exists('admin_scalar')) {
    /** First column of the first row, or $default. */
    function admin_scalar($conn, $sql, $default = 0)
    {
        $row = admin_row($conn, $sql);
        if (!$row) {
            return $default;
        }
        $value = reset($row);
        return $value === null ? $default : $value;
    }
}

if (!function_exists('admin_exec')) {
    /** Run a write statement; true on success. */
    function admin_exec($conn, $sql)
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return false;
        }
        return (bool) @$conn->query($sql);
    }
}

if (!function_exists('admin_str')) {
    /** Quote a value for inline SQL (the pages build read-only SQL). */
    function admin_str($conn, $value)
    {
        $value = (string) $value;
        if (is_object($conn) && method_exists($conn, 'real_escape_string')) {
            return "'" . $conn->real_escape_string($value) . "'";
        }
        return "'" . addslashes($value) . "'";
    }
}

/* ============================================================
   Scope: which companies may this session see?
   ============================================================ */

if (!function_exists('admin_trim')) {
    /** Shorten text for a table cell, mbstring or not. */
    function admin_trim($text, $length = 110, $ellipsis = '…')
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        if (function_exists('mb_strlen')) {
            if (mb_strlen($text, 'UTF-8') <= $length) {
                return $text;
            }
            return rtrim(mb_substr($text, 0, $length, 'UTF-8')) . $ellipsis;
        }
        if (strlen($text) <= $length) {
            return $text;
        }
        return rtrim(substr($text, 0, $length)) . $ellipsis;
    }
}

if (!function_exists('admin_scope_sql')) {
    /**
     * SQL fragment appended to a query that joins `customers c`.
     * Tenants only ever see their own companies; the global admin
     * sees everything.
     */
    function admin_scope_sql($tenant_id, $is_tenant, $alias = 'c')
    {
        if (!$is_tenant) {
            return '';
        }
        return ' AND ' . $alias . '.tenant_id = ' . (int) $tenant_id . ' ';
    }
}

if (!function_exists('admin_companies')) {
    /** Companies visible to this session. */
    function admin_companies($conn, $tenant_id, $is_tenant)
    {
        return admin_rows(
            $conn,
            "SELECT c.id, c.company_name, c.category_id
               FROM customers c
              WHERE 1 = 1" . admin_scope_sql($tenant_id, $is_tenant) . "
              ORDER BY c.company_name ASC"
        );
    }
}

/* ============================================================
   Analysis
   ============================================================ */

if (!function_exists('admin_periods')) {
    /** Selectable reporting windows for admin/analysis.php. */
    function admin_periods()
    {
        return [
            30  => 'Last 30 days',
            90  => 'Last 90 days',
            180 => 'Last 6 months',
            365 => 'Last 12 months',
        ];
    }
}

if (!function_exists('admin_period_days')) {
    /** Normalise the ?days= parameter. */
    function admin_period_days($value, $default = 90)
    {
        $days = (int) $value;
        return array_key_exists($days, admin_periods()) ? $days : $default;
    }
}

if (!function_exists('admin_response_stats')) {
    /**
     * Headline numbers for one window.
     * $offset shifts the window back (0 = current, 1 = previous).
     */
    function admin_response_stats($conn, $where, $days, $offset = 0)
    {
        $from = (int) $days * ((int) $offset + 1);
        $to   = (int) $days * (int) $offset;
        $range = " AND r.created_at >= DATE_SUB(NOW(), INTERVAL " . $from . " DAY)";
        if ($to > 0) {
            $range .= " AND r.created_at < DATE_SUB(NOW(), INTERVAL " . $to . " DAY)";
        }

        $row = admin_row(
            $conn,
            "SELECT COUNT(*) AS responses,
                    AVG(r.rating) AS avg_rating,
                    SUM(CASE WHEN r.rating >= 4 THEN 1 ELSE 0 END) AS promoters,
                    SUM(CASE WHEN r.rating = 3 THEN 1 ELSE 0 END) AS passives,
                    SUM(CASE WHEN r.rating <= 2 THEN 1 ELSE 0 END) AS detractors,
                    SUM(CASE WHEN r.comment IS NOT NULL AND r.comment <> '' THEN 1 ELSE 0 END) AS commented,
                    COUNT(DISTINCT r.company_id) AS companies
               FROM ratings r
               JOIN customers c ON c.id = r.company_id
              WHERE 1 = 1" . $where . $range
        );

        return [
            'responses'  => (int) ($row['responses'] ?? 0),
            'avg_rating' => round((float) ($row['avg_rating'] ?? 0), 2),
            'promoters'  => (int) ($row['promoters'] ?? 0),
            'passives'   => (int) ($row['passives'] ?? 0),
            'detractors' => (int) ($row['detractors'] ?? 0),
            'commented'  => (int) ($row['commented'] ?? 0),
            'companies'  => (int) ($row['companies'] ?? 0),
        ];
    }
}

if (!function_exists('admin_growth')) {
    /** Percentage change between two numbers (null when undefined). */
    function admin_growth($current, $previous)
    {
        $current  = (float) $current;
        $previous = (float) $previous;
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : null;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }
}

if (!function_exists('admin_delta_badge')) {
    /** "↑ 12.4%" / "↓ 3.1%" / "– no change" as coloured markup. */
    function admin_delta_badge($delta, $suffix = '%', $invert = false)
    {
        if ($delta === null) {
            return '<span class="admin-delta is-flat">– no prior data</span>';
        }
        $delta = (float) $delta;
        if (abs($delta) < 0.05) {
            return '<span class="admin-delta is-flat">– flat</span>';
        }
        $up   = $delta > 0;
        $good = $invert ? !$up : $up;
        return '<span class="admin-delta ' . ($good ? 'is-up' : 'is-down') . '">'
            . ($up ? '↑' : '↓') . ' ' . sa_e(number_format(abs($delta), 1)) . sa_e($suffix)
            . '</span>';
    }
}

if (!function_exists('admin_monthly_trend')) {
    /** Responses + average score per month for the last $months months. */
    function admin_monthly_trend($conn, $where, $months = 12)
    {
        $months = max(3, min(24, (int) $months));
        $rows = admin_rows(
            $conn,
            "SELECT DATE_FORMAT(r.created_at, '%Y-%m') AS ym,
                    COUNT(*) AS responses,
                    AVG(r.rating) AS avg_rating
               FROM ratings r
               JOIN customers c ON c.id = r.company_id
              WHERE r.created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL " . ($months - 1) . " MONTH)"
            . $where . "
              GROUP BY ym
              ORDER BY ym ASC"
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $key = isset($row['ym']) ? (string) $row['ym'] : '';
            if ($key !== '') {
                $byMonth[$key] = $row;
            }
        }

        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $stamp = strtotime('first day of -' . $i . ' month');
            $key   = date('Y-m', $stamp);
            $row   = isset($byMonth[$key]) ? $byMonth[$key] : [];
            $series[] = [
                'key'        => $key,
                'label'      => date('M', $stamp),
                'responses'  => (int) ($row['responses'] ?? 0),
                'avg_rating' => round((float) ($row['avg_rating'] ?? 0), 2),
            ];
        }
        return $series;
    }
}

if (!function_exists('admin_star_distribution')) {
    /** 5→1 star counts for the window. */
    function admin_star_distribution($conn, $where, $days)
    {
        $rows = admin_rows(
            $conn,
            "SELECT r.rating, COUNT(*) AS total
               FROM ratings r
               JOIN customers c ON c.id = r.company_id
              WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL " . (int) $days . " DAY)" . $where . "
              GROUP BY r.rating"
        );
        $out = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($rows as $row) {
            $star = isset($row['rating']) ? (int) $row['rating'] : 0;
            if (isset($out[$star])) {
                $out[$star] = isset($row['total']) ? (int) $row['total'] : 0;
            }
        }
        return $out;
    }
}

if (!function_exists('admin_company_performance')) {
    /**
     * One row per company: volume, score, promoter share and the
     * score movement between this window and the one before it.
     */
    function admin_company_performance($conn, $where, $days)
    {
        $days = (int) $days;
        $rows = admin_rows(
            $conn,
            "SELECT c.id,
                    c.company_name,
                    cat.name AS category_name,
                    COUNT(r.id) AS lifetime_responses,
                    AVG(r.rating) AS lifetime_avg,
                    MAX(r.created_at) AS last_response,
                    SUM(CASE WHEN r.created_at >= DATE_SUB(NOW(), INTERVAL " . $days . " DAY) THEN 1 ELSE 0 END) AS responses,
                    AVG(CASE WHEN r.created_at >= DATE_SUB(NOW(), INTERVAL " . $days . " DAY) THEN r.rating END) AS avg_rating,
                    SUM(CASE WHEN r.created_at >= DATE_SUB(NOW(), INTERVAL " . $days . " DAY) AND r.rating >= 4 THEN 1 ELSE 0 END) AS promoters,
                    SUM(CASE WHEN r.created_at >= DATE_SUB(NOW(), INTERVAL " . $days . " DAY) AND r.rating <= 2 THEN 1 ELSE 0 END) AS detractors,
                    SUM(CASE WHEN r.created_at < DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
                              AND r.created_at >= DATE_SUB(NOW(), INTERVAL " . ($days * 2) . " DAY) THEN 1 ELSE 0 END) AS prev_responses,
                    AVG(CASE WHEN r.created_at < DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
                              AND r.created_at >= DATE_SUB(NOW(), INTERVAL " . ($days * 2) . " DAY) THEN r.rating END) AS prev_avg
               FROM customers c
               LEFT JOIN ratings r ON r.company_id = c.id
               LEFT JOIN categories cat ON cat.id = c.category_id
              WHERE 1 = 1" . $where . "
              GROUP BY c.id, c.company_name, cat.name
              ORDER BY responses DESC, lifetime_responses DESC, c.company_name ASC"
        );

        $out = [];
        foreach ($rows as $row) {
            $responses = (int) ($row['responses'] ?? 0);
            $avg       = isset($row['avg_rating']) ? round((float) $row['avg_rating'], 2) : null;
            $prevAvg   = isset($row['prev_avg']) ? round((float) $row['prev_avg'], 2) : null;
            $scoreMove = ($avg !== null && $prevAvg !== null) ? round($avg - $prevAvg, 2) : null;

            if ($scoreMove === null) {
                $momentum = $responses > 0 ? 'new' : 'quiet';
            } elseif ($scoreMove >= 0.15) {
                $momentum = 'improving';
            } elseif ($scoreMove <= -0.15) {
                $momentum = 'slipping';
            } else {
                $momentum = 'steady';
            }

            $out[] = [
                'id'             => (int) ($row['id'] ?? 0),
                'company_name'   => (string) ($row['company_name'] ?? 'Unnamed company'),
                'category_name'  => isset($row['category_name']) ? (string) $row['category_name'] : '',
                'responses'      => $responses,
                'prev_responses' => (int) ($row['prev_responses'] ?? 0),
                'volume_growth'  => admin_growth($responses, (int) ($row['prev_responses'] ?? 0)),
                'avg_rating'     => $avg,
                'prev_avg'       => $prevAvg,
                'score_move'     => $scoreMove,
                'momentum'       => $momentum,
                'promoters'      => (int) ($row['promoters'] ?? 0),
                'detractors'     => (int) ($row['detractors'] ?? 0),
                'lifetime'       => (int) ($row['lifetime_responses'] ?? 0),
                'lifetime_avg'   => isset($row['lifetime_avg']) ? round((float) $row['lifetime_avg'], 2) : null,
                'last_response'  => isset($row['last_response']) ? $row['last_response'] : null,
            ];
        }
        return $out;
    }
}

if (!function_exists('admin_recent_comments')) {
    /** Latest commented responses in the window. */
    function admin_recent_comments($conn, $where, $days, $limit = 200, $extra = '')
    {
        return admin_rows(
            $conn,
            "SELECT r.id, r.rating, r.comment, r.customer_name, r.customer_email,
                    r.created_at, r.company_id, c.company_name
               FROM ratings r
               JOIN customers c ON c.id = r.company_id
              WHERE r.comment IS NOT NULL AND r.comment <> ''
                AND r.created_at >= DATE_SUB(NOW(), INTERVAL " . (int) $days . " DAY)"
            . $where . $extra . "
              ORDER BY r.created_at DESC
              LIMIT " . (int) $limit
        );
    }
}

if (!function_exists('admin_stopwords')) {
    /** Words that carry no signal in a review. */
    function admin_stopwords()
    {
        return array_flip([
            'the', 'and', 'was', 'were', 'are', 'for', 'with', 'that', 'this', 'they', 'them', 'their',
            'have', 'has', 'had', 'but', 'not', 'you', 'your', 'our', 'from', 'about', 'when', 'what',
            'who', 'how', 'all', 'any', 'can', 'could', 'would', 'should', 'will', 'just', 'very',
            'too', 'get', 'got', 'was', 'been', 'being', 'there', 'here', 'than', 'then', 'them',
            'some', 'more', 'most', 'much', 'many', 'also', 'because', 'after', 'before', 'over',
            'into', 'out', 'off', 'per', 'via', 'did', 'does', 'doing', 'done', 'its', 'it\'s',
            'we', 'i', 'me', 'my', 'he', 'she', 'his', 'her', 'him', 'us', 'as', 'at', 'by', 'in',
            'is', 'of', 'on', 'or', 'to', 'be', 'do', 'so', 'if', 'no', 'up', 'an', 'a',
        ]);
    }
}

if (!function_exists('admin_sentiment_lexicon')) {
    /** Small positive / negative word lists used to tag keywords. */
    function admin_sentiment_lexicon()
    {
        return [
            'positive' => array_flip([
                'excellent', 'great', 'good', 'amazing', 'awesome', 'fast', 'quick', 'friendly',
                'helpful', 'professional', 'clean', 'polite', 'outstanding', 'perfect', 'love',
                'loved', 'recommend', 'recommended', 'best', 'reliable', 'smooth', 'easy', 'happy',
                'satisfied', 'quality', 'affordable', 'responsive', 'efficient', 'wonderful', 'nice',
            ]),
            'negative' => array_flip([
                'slow', 'late', 'delay', 'delayed', 'rude', 'poor', 'bad', 'worst', 'terrible',
                'expensive', 'costly', 'dirty', 'broken', 'damaged', 'wrong', 'missing', 'waiting',
                'wait', 'unprofessional', 'disappointed', 'disappointing', 'confusing', 'difficult',
                'problem', 'issue', 'issues', 'complaint', 'refund', 'error', 'unhelpful', 'noisy',
            ]),
        ];
    }
}

if (!function_exists('admin_keyword_insights')) {
    /**
     * Frequency of meaningful words across the supplied comments,
     * split into what customers praise and what they complain about.
     */
    function admin_keyword_insights($comments, $limit = 10)
    {
        $stop    = admin_stopwords();
        $lexicon = admin_sentiment_lexicon();
        $counts  = [];
        $scores  = [];
        // With a decent sample, a word has to repeat before it is a theme.
        $min_count = count($comments) >= 10 ? 2 : 1;

        foreach ($comments as $row) {
            $text = strtolower((string) ($row['comment'] ?? ''));
            $text = preg_replace('/[^a-z0-9\s\']/', ' ', $text);
            $words = preg_split('/\s+/', trim($text));
            if (!$words) {
                continue;
            }
            $seen = [];
            foreach ($words as $word) {
                $word = trim($word, "'");
                if (strlen($word) < 4 || isset($stop[$word]) || isset($seen[$word])) {
                    continue;
                }
                $seen[$word] = true;
                $counts[$word] = isset($counts[$word]) ? $counts[$word] + 1 : 1;
                $scores[$word] = isset($scores[$word])
                    ? $scores[$word] + (float) ($row['rating'] ?? 0)
                    : (float) ($row['rating'] ?? 0);
            }
        }

        $praise = [];
        $problems = [];
        arsort($counts);
        foreach ($counts as $word => $count) {
            if ($count < $min_count) {
                continue;
            }
            $avg = $count > 0 ? $scores[$word] / $count : 0;
            $item = ['word' => $word, 'count' => $count, 'avg' => round($avg, 1)];

            if (isset($lexicon['negative'][$word]) || $avg <= 2.9) {
                if (count($problems) < $limit) {
                    $problems[] = $item;
                }
            } elseif (isset($lexicon['positive'][$word]) || $avg >= 4.0) {
                if (count($praise) < $limit) {
                    $praise[] = $item;
                }
            }
            if (count($praise) >= $limit && count($problems) >= $limit) {
                break;
            }
        }

        return ['praise' => $praise, 'problems' => $problems];
    }
}

if (!function_exists('admin_insights')) {
    /**
     * Plain-language "what should we do next" notes built from the
     * numbers already on the page.
     */
    function admin_insights($current, $previous, $companies, $keywords, $days)
    {
        $out = [];
        $volumeGrowth = admin_growth($current['responses'], $previous['responses']);
        $scoreMove = ($current['avg_rating'] > 0 && $previous['avg_rating'] > 0)
            ? round($current['avg_rating'] - $previous['avg_rating'], 2)
            : null;

        if ($current['responses'] === 0) {
            $out[] = [
                'tone'  => 'warn',
                'title' => 'No responses in this window',
                'body'  => 'Share your rating links with recent customers — analysis needs feedback to work with.',
            ];
            return $out;
        }

        if ($volumeGrowth !== null && $volumeGrowth >= 10) {
            $out[] = [
                'tone'  => 'good',
                'title' => 'Feedback volume is growing',
                'body'  => 'You collected ' . sa_num($current['responses']) . ' responses in the last ' . (int) $days
                    . ' days, ' . number_format(abs($volumeGrowth), 1) . '% more than the period before. Keep the collection habit going.',
            ];
        } elseif ($volumeGrowth !== null && $volumeGrowth <= -10) {
            $out[] = [
                'tone'  => 'warn',
                'title' => 'Feedback volume is falling',
                'body'  => 'Responses dropped ' . number_format(abs($volumeGrowth), 1) . '% versus the previous period. '
                    . 'Re-share the rating link at the point of sale or in your receipts.',
            ];
        }

        if ($scoreMove !== null && $scoreMove >= 0.1) {
            $out[] = [
                'tone'  => 'good',
                'title' => 'Satisfaction is trending up',
                'body'  => 'Average score moved from ' . number_format($previous['avg_rating'], 2)
                    . ' to ' . number_format($current['avg_rating'], 2) . '. Whatever changed recently is working.',
            ];
        } elseif ($scoreMove !== null && $scoreMove <= -0.1) {
            $out[] = [
                'tone'  => 'warn',
                'title' => 'Satisfaction is slipping',
                'body'  => 'Average score fell from ' . number_format($previous['avg_rating'], 2)
                    . ' to ' . number_format($current['avg_rating'], 2) . '. Review the low scores below before it spreads.',
            ];
        }

        $promoterShare = $current['responses'] > 0
            ? round(($current['promoters'] / $current['responses']) * 100, 1)
            : 0;
        if ($promoterShare >= 70) {
            $out[] = [
                'tone'  => 'good',
                'title' => number_format($promoterShare, 1) . '% of customers are promoters',
                'body'  => 'Turn those 4 and 5 star comments into social proof from the Social workspace — it is your cheapest lead source.',
            ];
        }
        if ($current['detractors'] > 0) {
            $out[] = [
                'tone'  => 'warn',
                'title' => sa_num($current['detractors']) . ' response' . ($current['detractors'] === 1 ? '' : 's') . ' need follow-up',
                'body'  => 'Reach out to the 1 and 2 star reviewers listed below. A recovered customer usually re-rates higher.',
            ];
        }

        $slipping = [];
        foreach ($companies as $company) {
            if ($company['momentum'] === 'slipping') {
                $slipping[] = $company['company_name'];
            }
        }
        if ($slipping) {
            $out[] = [
                'tone'  => 'warn',
                'title' => count($slipping) . ' compan' . (count($slipping) === 1 ? 'y is' : 'ies are') . ' losing ground',
                'body'  => implode(', ', array_slice($slipping, 0, 4))
                    . (count($slipping) > 4 ? ' and others' : '') . ' scored lower than in the previous period.',
            ];
        }

        if (!empty($keywords['problems'])) {
            $words = [];
            foreach (array_slice($keywords['problems'], 0, 3) as $item) {
                $words[] = $item['word'];
            }
            $out[] = [
                'tone'  => 'info',
                'title' => 'Recurring complaint themes',
                'body'  => 'Customers keep mentioning “' . implode('”, “', $words) . '”. Fixing one of these lifts several scores at once.',
            ];
        }
        if (!empty($keywords['praise'])) {
            $words = [];
            foreach (array_slice($keywords['praise'], 0, 3) as $item) {
                $words[] = $item['word'];
            }
            $out[] = [
                'tone'  => 'info',
                'title' => 'What customers love',
                'body'  => '“' . implode('”, “', $words) . '” dominate your positive reviews — use those words in your marketing copy.',
            ];
        }

        return $out;
    }
}

/* ============================================================
   Presentation helpers (reuse admin-dashboard.css classes)
   ============================================================ */

if (!function_exists('admin_trend_chart')) {
    /**
     * Dual-series area/line chart for the monthly trend, rendered
     * with the same markup the dashboard chart uses so it inherits
     * the existing styling (including dark mode).
     *
     * @param array $series rows of ['label', 'responses', 'avg_rating']
     */
    function admin_trend_chart($series)
    {
        $series = array_values($series);
        $count  = count($series);
        if ($count === 0) {
            return '<div class="empty-state">No responses to chart yet.</div>';
        }

        static $seq = 0;
        $seq++;
        $gradient = 'adminTrendFill' . $seq;

        $width  = 700;
        $height = 220;
        $maxResponses = 0;
        foreach ($series as $point) {
            $maxResponses = max($maxResponses, (int) $point['responses']);
        }
        if ($maxResponses <= 0) {
            $maxResponses = 1;
        }

        $x = function ($i) use ($count, $width) {
            return $count > 1 ? round(($i * $width) / ($count - 1), 1) : round($width / 2, 1);
        };
        $yVolume = function ($v) use ($maxResponses, $height) {
            $v = max(0, (float) $v);
            return round($height - (($v / $maxResponses) * ($height - 20)) - 8, 1);
        };
        $yScore = function ($v) use ($height) {
            $v = max(0, min(5, (float) $v));
            return round($height - (($v / 5) * ($height - 20)) - 8, 1);
        };

        $volumePoints = [];
        $scorePoints  = [];
        foreach ($series as $i => $point) {
            $volumePoints[] = $x($i) . ',' . $yVolume($point['responses']);
            $scorePoints[]  = $x($i) . ',' . $yScore($point['avg_rating']);
        }

        $volumeLine = 'M' . implode(' L', $volumePoints);
        $scoreLine  = 'M' . implode(' L', $scorePoints);
        $area = $volumeLine . ' L' . $x($count - 1) . ',' . $height . ' L' . $x(0) . ',' . $height . ' Z';

        $html  = '<div class="chart">';
        $html .= '<div class="y-labels">';
        for ($step = 4; $step >= 0; $step--) {
            $html .= '<span>' . sa_e(sa_num((int) round(($maxResponses / 4) * $step))) . '</span>';
        }
        $html .= '</div>';
        $html .= '<div class="chart-area">';
        $html .= '<div class="grid-lines"><i></i><i></i><i></i><i></i><i></i></div>';
        $html .= '<svg viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" '
              . 'aria-label="Responses and average score by month">';
        $html .= '<defs><linearGradient id="' . $gradient . '" x1="0" x2="0" y1="0" y2="1">'
              . '<stop offset="0" stop-color="#c2f542" stop-opacity=".28"/>'
              . '<stop offset="1" stop-color="#c2f542" stop-opacity="0"/></linearGradient></defs>';
        $html .= '<path d="' . $area . '" fill="url(#' . $gradient . ')"/>';
        $html .= '<path class="trend lime-stroke" d="' . $volumeLine . '"/>';
        $html .= '<path class="trend blue-stroke" d="' . $scoreLine . '"/>';
        $html .= '</svg>';
        $html .= '<div class="x-labels">';
        foreach ($series as $point) {
            $html .= '<span>' . sa_e($point['label']) . '</span>';
        }
        $html .= '</div></div></div>';

        return $html;
    }
}

if (!function_exists('admin_meter')) {
    /** Labelled usage bar: used / limit with a percentage fill. */
    function admin_meter($label, $used, $limit, $note = '')
    {
        $used  = (int) $used;
        $limit = (int) $limit;
        $pct   = $limit > 0 ? min(100, round(($used / $limit) * 100)) : 0;
        $state = $pct >= 90 ? 'is-critical' : ($pct >= 75 ? 'is-warning' : 'is-ok');

        $html  = '<div class="admin-meter ' . $state . '">';
        $html .= '<div class="admin-meter-head"><span>' . sa_e($label) . '</span><strong>'
              . sa_e(sa_num($used)) . ' / ' . ($limit > 0 ? sa_e(sa_num($limit)) : '∞') . '</strong></div>';
        $html .= '<div class="admin-meter-track"><i style="width:' . $pct . '%"></i></div>';
        $html .= '<small>' . sa_e($note !== '' ? $note : $pct . '% of your plan allowance') . '</small>';
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('admin_badge')) {
    /** Small status pill. */
    function admin_badge($label, $tone = 'neutral')
    {
        $allowed = ['neutral', 'good', 'warn', 'bad', 'info'];
        $tone = in_array($tone, $allowed, true) ? $tone : 'neutral';
        return '<span class="admin-badge is-' . $tone . '">' . sa_e($label) . '</span>';
    }
}

if (!function_exists('admin_momentum_badge')) {
    /** Momentum pill used by the growth table. */
    function admin_momentum_badge($momentum)
    {
        $map = [
            'improving' => ['Improving', 'good'],
            'slipping'  => ['Slipping', 'bad'],
            'steady'    => ['Steady', 'info'],
            'new'       => ['New feedback', 'neutral'],
            'quiet'     => ['No responses', 'neutral'],
        ];
        $entry = isset($map[$momentum]) ? $map[$momentum] : $map['quiet'];
        return admin_badge($entry[0], $entry[1]);
    }
}

if (!function_exists('admin_status_tone')) {
    /** Tone for a subscription status. */
    function admin_status_tone($status)
    {
        switch (strtolower((string) $status)) {
            case 'active':
                return 'good';
            case 'trial':
                return 'info';
            case 'inactive':
                return 'warn';
            case 'cancelled':
                return 'bad';
            default:
                return 'neutral';
        }
    }
}
