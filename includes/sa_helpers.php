<?php
/**
 * ============================================================
 *  Optibiz Super Admin — data + presentation helpers
 * ============================================================
 *  Every superadmin page includes this file once. It keeps the
 *  queries, number formatting and SVG chart building out of the
 *  templates so the pages stay readable.
 *
 *  All helpers are defensive: if a table is missing (e.g. an old
 *  database without `quote_requests`) or a query fails, they
 *  return empty data instead of throwing a fatal error.
 *
 *  Requires a mysqli connection in $conn (config/database.php).
 */

if (!function_exists('sa_table_exists')) {
    /** Cached check for whether a table exists in the current database. */
    function sa_table_exists($conn, $table)
    {
        static $cache = [];
        if (!$conn) {
            return false;
        }
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        $result = @$conn->query(
            "SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = '" . $table . "'"
        );
        $cache[$table] = ($result && (int) $result->fetch_assoc()['c'] > 0);
        return $cache[$table];
    }
}

if (!function_exists('sa_query')) {
    /**
     * Run a query and return every row as an array.
     * Returns [] on failure or when a required table is missing.
     */
    function sa_query($conn, $sql, $required_tables = [])
    {
        if (!$conn) {
            return [];
        }
        foreach ((array) $required_tables as $t) {
            if (!sa_table_exists($conn, $t)) {
                return [];
            }
        }
        $result = @$conn->query($sql);
        if (!$result) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
}

if (!function_exists('sa_one')) {
    /** Run a query and return the first row (or []). */
    function sa_one($conn, $sql, $required_tables = [])
    {
        $rows = sa_query($conn, $sql, $required_tables);
        return $rows ? $rows[0] : [];
    }
}

if (!function_exists('sa_scalar')) {
    /** Run a query and return the first column of the first row. */
    function sa_scalar($conn, $sql, $default = 0, $required_tables = [])
    {
        $row = sa_one($conn, $sql, $required_tables);
        if (!$row) {
            return $default;
        }
        $value = reset($row);
        return $value === null ? $default : $value;
    }
}

/* ------------------------------------------------------------
   Formatting
   ------------------------------------------------------------ */

if (!function_exists('sa_setting')) {
    /** Read a value from the `settings` table (cached per request). */
    function sa_setting($conn, $key, $default = '')
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach (sa_query($conn, "SELECT setting_key, setting_value FROM settings", 'settings') as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        }
        return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
    }
}

if (!function_exists('sa_currency_symbol')) {
    /** Currency symbol, configurable via the `currency_symbol` setting. */
    function sa_currency_symbol($conn = null)
    {
        global $conn;
        $symbol = $conn ? sa_setting($conn, 'currency_symbol', '$') : '$';
        return $symbol !== '' ? $symbol : '$';
    }
}

if (!function_exists('sa_money')) {
    /** 1234.5 -> "$1,234.50" (symbol from settings). */
    function sa_money($amount, $decimals = 2)
    {
        global $conn;
        return sa_currency_symbol($conn) . number_format((float) $amount, $decimals);
    }
}

if (!function_exists('sa_money_short')) {
    /** 12400 -> "$12.4k" for tight spaces like chart axes. */
    function sa_money_short($amount)
    {
        global $conn;
        $symbol = sa_currency_symbol($conn);
        $n = (float) $amount;
        $abs = abs($n);
        if ($abs >= 1000000) {
            return $symbol . round($n / 1000000, 1) . 'M';
        }
        if ($abs >= 1000) {
            return $symbol . round($n / 1000, 1) . 'k';
        }
        return $symbol . round($n, $abs < 100 && fmod($n, 1) ? 2 : 0);
    }
}

if (!function_exists('sa_num')) {
    /** 1234 -> "1,234" */
    function sa_num($n)
    {
        return number_format((float) $n);
    }
}

if (!function_exists('sa_pct')) {
    /** Safe percentage: 3 of 12 -> 25.0 */
    function sa_pct($part, $total, $decimals = 1)
    {
        $total = (float) $total;
        if ($total <= 0) {
            return 0.0;
        }
        return round(((float) $part / $total) * 100, $decimals);
    }
}

if (!function_exists('sa_e')) {
    /** Escape for HTML output. */
    function sa_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sa_initials')) {
    /** "ABC Corporation" -> "AB" (up to 2 chars, letters or digits). */
    function sa_initials($name, $max = 2)
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '') {
            return '??';
        }
        $words = explode(' ', $name);
        if (count($words) === 1) {
            return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $words[0]), 0, $max));
        }
        $out = '';
        foreach ($words as $w) {
            $clean = preg_replace('/[^A-Za-z0-9]/', '', $w);
            if ($clean !== '') {
                $out .= $clean[0];
            }
            if (strlen($out) >= $max) {
                break;
            }
        }
        return strtoupper($out !== '' ? $out : substr($name, 0, $max));
    }
}

if (!function_exists('sa_stars')) {
    /** Render a 5-star row (filled up to $rating). */
    function sa_stars($rating, $show_number = true)
    {
        $rating = (float) $rating;
        $star  = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        $html  = '<span class="sa-stars" role="img" aria-label="' . sa_e(number_format($rating, 1)) . ' out of 5">';
        for ($i = 1; $i <= 5; $i++) {
            $html .= '<span class="' . ($i <= round($rating) ? 'on' : 'off') . '">' . $star . '</span>';
        }
        $html .= '</span>';
        if ($show_number) {
            $html .= '<span class="sa-star-num">' . number_format($rating, 1) . '</span>';
        }
        return $html;
    }
}

/* ------------------------------------------------------------
   Dates
   ------------------------------------------------------------ */

if (!function_exists('sa_date')) {
    /** "2026-03-04 11:22:33" -> "Mar 04, 2026" (or the fallback). */
    function sa_date($value, $format = 'M d, Y', $fallback = '&mdash;')
    {
        if (!$value || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return $fallback;
        }
        $ts = strtotime($value);
        return $ts ? date($format, $ts) : $fallback;
    }
}

if (!function_exists('sa_time_ago')) {
    /** Human friendly relative time. */
    function sa_time_ago($value)
    {
        if (!$value) {
            return '&mdash;';
        }
        $ts = strtotime($value);
        if (!$ts) {
            return '&mdash;';
        }
        $diff = time() - $ts;
        $future = $diff < 0;
        $diff = abs($diff);
        $steps = [
            [31536000, 'year'],
            [2592000, 'month'],
            [604800, 'week'],
            [86400, 'day'],
            [3600, 'hour'],
            [60, 'minute'],
        ];
        foreach ($steps as $s) {
            if ($diff >= $s[0]) {
                $n = (int) floor($diff / $s[0]);
                $text = $n . ' ' . $s[1] . ($n > 1 ? 's' : '');
                return $future ? 'in ' . $text : $text . ' ago';
            }
        }
        return $future ? 'soon' : 'just now';
    }
}

if (!function_exists('sa_days_until')) {
    /** Whole days from today until $date (negative = overdue). */
    function sa_days_until($date)
    {
        if (!$date) {
            return null;
        }
        $ts = strtotime($date);
        if (!$ts) {
            return null;
        }
        return (int) floor(($ts - strtotime(date('Y-m-d'))) / 86400);
    }
}

if (!function_exists('sa_renewal_badge')) {
    /**
     * Badge describing how close a subscription is to renewal/expiry.
     * Returns [html, kind] where kind is expired|due|soon|ok|none.
     */
    function sa_renewal_badge($end_date, $auto_renew)
    {
        $days = sa_days_until($end_date);
        if ($days === null) {
            return ['<span class="sa-badge sa-badge-cancelled">No end date</span>', 'none'];
        }
        if ($days < 0) {
            $label = 'Expired ' . abs($days) . 'd ago';
            return ['<span class="sa-badge sa-badge-expired">' . sa_e($label) . '</span>', 'expired'];
        }
        if ($days === 0) {
            return ['<span class="sa-badge sa-badge-trial">Ends today</span>', 'due'];
        }
        if ($days <= 30) {
            $label = $days . 'd left' . ($auto_renew ? ' · auto-renew' : '');
            $badge = $auto_renew ? 'sa-badge-trial' : 'sa-badge-expired';
            return ['<span class="sa-badge ' . $badge . '">' . sa_e($label) . '</span>', $days <= 7 ? 'due' : 'soon'];
        }
        return ['<span class="sa-badge sa-badge-active">' . sa_e($days . 'd left') . '</span>', 'ok'];
    }
}

/* ------------------------------------------------------------
   Status helpers
   ------------------------------------------------------------ */

if (!function_exists('sa_status_badge')) {
    /** Subscription / quote status -> coloured badge. */
    function sa_status_badge($status, $label = null)
    {
        $status = (string) $status;
        $map = [
            'active'    => 'sa-badge-active',
            'trial'     => 'sa-badge-trial',
            'inactive'  => 'sa-badge-inactive',
            'cancelled' => 'sa-badge-cancelled',
            'pending'   => 'sa-badge-pending',
            'contacted' => 'sa-badge-contacted',
            'converted' => 'sa-badge-converted',
            'rejected'  => 'sa-badge-rejected',
        ];
        $class = isset($map[$status]) ? $map[$status] : 'sa-badge-cancelled';
        $text  = $label !== null ? $label : ucfirst($status !== '' ? $status : 'unknown');
        return '<span class="sa-badge ' . $class . '">' . sa_e($text) . '</span>';
    }
}

if (!function_exists('sa_status_color')) {
    /** CSS var name for a status (used by charts / bars). */
    function sa_status_color($status)
    {
        $map = [
            'active'    => 'var(--sa-success)',
            'trial'     => 'var(--sa-warning)',
            'inactive'  => 'var(--sa-danger)',
            'cancelled' => 'var(--sa-faint)',
            'pending'   => 'var(--sa-warning)',
            'contacted' => 'var(--sa-info)',
            'converted' => 'var(--sa-success)',
            'rejected'  => 'var(--sa-danger)',
        ];
        return isset($map[$status]) ? $map[$status] : 'var(--sa-accent)';
    }
}

if (!function_exists('sa_delta')) {
    /**
     * Change indicator pill.
     * $value > 0 up, < 0 down, 0 flat. $invert flips good/bad colours
     * (e.g. for churn, where a rise is bad).
     */
    function sa_delta($value, $suffix = '%', $invert = false, $decimals = 1)
    {
        $v = (float) $value;
        if (abs($v) < 0.05) {
            $class = 'sa-delta-flat';
            $icon  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            $sign  = '';
        } elseif ($v > 0) {
            $class = $invert ? 'sa-delta-down' : 'sa-delta-up';
            $icon  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>';
            $sign  = '+';
        } else {
            $class = $invert ? 'sa-delta-up' : 'sa-delta-down';
            $icon  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';
            $sign  = '';
        }
        return '<span class="sa-delta ' . $class . '">' . $icon . $sign
            . number_format($v, $decimals) . sa_e($suffix) . '</span>';
    }
}

/* ------------------------------------------------------------
   Chart building blocks (pure SVG / CSS — no JS library needed)
   ------------------------------------------------------------ */

if (!function_exists('sa_sparkline')) {
    /**
     * Tiny area sparkline for KPI cards.
     * $values  numeric[]
     * $width/$height  viewBox size
     */
    function sa_sparkline($values, $width = 88, $height = 30)
    {
        $values = array_values(array_map('floatval', (array) $values));
        if (count($values) < 2) {
            return '';
        }
        $max = max($values);
        $min = min($values);
        $range = ($max - $min) > 0 ? ($max - $min) : 1;
        $stepX = $width / (count($values) - 1);

        $points = [];
        foreach ($values as $i => $v) {
            $x = $i * $stepX;
            $y = $height - 2 - (($v - $min) / $range) * ($height - 5);
            $points[] = [round($x, 2), round($y, 2)];
        }

        // Smooth with quadratic midpoints
        $line = 'M ' . $points[0][0] . ' ' . $points[0][1];
        for ($i = 1; $i < count($points); $i++) {
            $prev = $points[$i - 1];
            $cur  = $points[$i];
            $mx   = ($prev[0] + $cur[0]) / 2;
            $line .= ' Q ' . $prev[0] . ' ' . $prev[1] . ' ' . $mx . ' ' . (($prev[1] + $cur[1]) / 2);
            $line .= ' T ' . $cur[0] . ' ' . $cur[1];
        }
        $area = $line . ' L ' . $width . ' ' . $height . ' L 0 ' . $height . ' Z';

        return '<svg class="sa-spark" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<path class="area" d="' . $area . '"/>'
            . '<path class="line" d="' . $line . '"/>'
            . '</svg>';
    }
}

if (!function_exists('sa_line_chart')) {
    /**
     * Area/line chart.
     * $labels   ['Mar', 'Apr', ...]
     * $series    [['name' => 'MRR', 'values' => [...], 'color' => 'lime',
     *              'format' => 'money', 'dashed' => false], ...]
     * $options   ['height' => 260, 'format' => 'money', 'max' => null,
     *              'show_dots' => true]
     */
    function sa_line_chart($labels, $series, $options = [])
    {
        $labels = array_values((array) $labels);
        $n = count($labels);
        if ($n === 0 || !$series) {
            return '<div class="sa-empty"><p>No data to chart yet.</p></div>';
        }

        $height    = isset($options['height']) ? (int) $options['height'] : 260;
        $format    = isset($options['format']) ? $options['format'] : 'number';
        $padLeft   = 46;
        $padRight  = 16;
        $padTop    = 18;
        $padBottom = 30;
        $width     = 760;
        $plotW     = $width - $padLeft - $padRight;
        $plotH     = $height - $padTop - $padBottom;

        // Value range across all series
        $all = [];
        foreach ($series as $s) {
            foreach ((array) $s['values'] as $v) {
                $all[] = (float) $v;
            }
        }
        $max = isset($options['max']) && $options['max'] !== null
            ? (float) $options['max']
            : max($all + [0]);
        $min = min(0, min($all + [0]));
        if ($max <= $min) {
            $max = $min + 1;
        }
        // Round the top of the scale up to a friendly number
        $niceMax = sa_nice_max($max);

        $x = function ($i) use ($n, $padLeft, $plotW) {
            return $n > 1 ? $padLeft + ($i * $plotW) / ($n - 1) : $padLeft + $plotW / 2;
        };
        $y = function ($v) use ($min, $niceMax, $padTop, $plotH) {
            return $padTop + $plotH - ((($v - $min) / ($niceMax - $min)) * $plotH);
        };

        // A page can hold several charts, so each one gets its own gradient id.
        // The id is published on the <svg> as a custom property and
        // .sa-chart .area-fill inherits it (see superadmin.css).
        static $sa_chart_seq = 0;
        $gradient_id = 'saAreaGradient' . (++$sa_chart_seq);

        $svg  = '<div class="sa-chart" data-sa-chart="line">';
        $svg .= '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Trend chart"'
             .  ' style="--sa-area: url(#' . $gradient_id . ')">';
        $svg .= '<defs><linearGradient id="' . $gradient_id . '" x1="0" y1="0" x2="0" y2="1">'
             .  '<stop offset="0%" stop-color="#c2f542" stop-opacity="0.42"/>'
             .  '<stop offset="100%" stop-color="#c2f542" stop-opacity="0"/>'
             .  '</linearGradient></defs>';

        // Horizontal grid + Y labels
        $ticks = 4;
        for ($t = 0; $t <= $ticks; $t++) {
            $value = $min + (($niceMax - $min) * $t) / $ticks;
            $yy = $y($value);
            $svg .= '<line class="grid-line" x1="' . $padLeft . '" y1="' . round($yy, 1)
                 .  '" x2="' . ($width - $padRight) . '" y2="' . round($yy, 1) . '"/>';
            $svg .= '<text class="axis-label" x="' . ($padLeft - 9) . '" y="' . round($yy + 3.5, 1)
                 .  '" text-anchor="end">' . sa_e(sa_axis_value($value, $format)) . '</text>';
        }

        // X labels (thin out when crowded)
        $skip = $n > 14 ? ceil($n / 8) : 1;
        foreach ($labels as $i => $label) {
            if ($i % $skip !== 0 && $i !== $n - 1) {
                continue;
            }
            $svg .= '<text class="axis-label" x="' . round($x($i), 1) . '" y="' . ($height - 8)
                 .  '" text-anchor="middle">' . sa_e($label) . '</text>';
        }

        foreach ($series as $si => $s) {
            $values = array_map('floatval', (array) $s['values']);
            $values = array_pad($values, $n, 0);
            $color  = isset($s['color']) ? $s['color'] : 'lime';
            $class  = ($si === 0 && $color === 'lime' && empty($s['dashed'])) ? 'line-stroke' : 'line-stroke-2';
            if (!empty($s['dashed']) && $si === 0) {
                $class = 'line-stroke-2';
            }

            $line = '';
            foreach ($values as $i => $v) {
                $line .= ($i === 0 ? 'M ' : ' L ') . round($x($i), 1) . ' ' . round($y($v), 1);
            }

            // Area fill for the primary series only
            if ($si === 0 && $color === 'lime' && empty($s['dashed'])) {
                $area = $line . ' L ' . round($x($n - 1), 1) . ' ' . ($padTop + $plotH)
                      . ' L ' . round($x(0), 1) . ' ' . ($padTop + $plotH) . ' Z';
                $svg .= '<path class="area-fill" d="' . $area . '"/>';
            }
            $svg .= '<path class="' . $class . '" d="' . $line . '" style="--sa-dash:' . (2400 + $n * 60) . '"/>';

            // Points + hover tooltips
            if (empty($options['show_dots']) || $options['show_dots'] !== false) {
                foreach ($values as $i => $v) {
                    $tip = (isset($labels[$i]) ? $labels[$i] . ': ' : '') . sa_axis_value($v, isset($s['format']) ? $s['format'] : $format);
                    $tx = round($x($i), 1);
                    $ty = round($y($v), 1);
                    $tipW = max(58, strlen($tip) * 5.6 + 16);
                    $tipX = min(max($tx - $tipW / 2, 2), $width - $tipW - 2);
                    $tipY = max($ty - 34, 2);
                    $svg .= '<g class="point">'
                         .  '<circle class="dot" cx="' . $tx . '" cy="' . $ty . '" r="3.6"/>'
                         .  '<g class="tip">'
                         .  '<rect class="tip-bg" x="' . round($tipX, 1) . '" y="' . round($tipY, 1)
                         .  '" width="' . round($tipW, 1) . '" height="22" rx="7"/>'
                         .  '<text class="tip-text" x="' . round($tipX + $tipW / 2, 1) . '" y="' . round($tipY + 15, 1)
                         .  '" text-anchor="middle">' . sa_e($tip) . '</text>'
                         .  '</g></g>';
                }
            }
        }

        $svg .= '</svg></div>';
        return $svg;
    }
}

if (!function_exists('sa_bar_chart')) {
    /**
     * Vertical bar chart (optionally grouped: each item may carry
     * 'values' => [a, b] with 'series_names').
     * $items [['label' => 'Mar', 'value' => 120, 'value2' => 40], ...]
     */
    function sa_bar_chart($items, $options = [])
    {
        $items = array_values((array) $items);
        if (!$items) {
            return '<div class="sa-empty"><p>No data to chart yet.</p></div>';
        }
        $height    = isset($options['height']) ? (int) $options['height'] : 230;
        $format    = isset($options['format']) ? $options['format'] : 'number';
        $grouped   = !empty($options['grouped']);
        $padLeft   = 44;
        $padRight  = 14;
        $padTop    = 16;
        $padBottom = 30;
        $width     = 720;
        $plotW     = $width - $padLeft - $padRight;
        $plotH     = $height - $padTop - $padBottom;

        $max = 0;
        foreach ($items as $it) {
            $max = max($max, (float) (isset($it['value']) ? $it['value'] : 0));
            if ($grouped) {
                $max = max($max, (float) (isset($it['value2']) ? $it['value2'] : 0));
            }
        }
        $niceMax = sa_nice_max($max > 0 ? $max : 1);

        $slot = $plotW / count($items);
        $barW = $grouped ? min(20, $slot * 0.3) : min(38, $slot * 0.56);

        $svg  = '<div class="sa-chart" data-sa-chart="bars">';
        $svg .= '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Bar chart">';

        for ($t = 0; $t <= 4; $t++) {
            $value = ($niceMax * $t) / 4;
            $yy = $padTop + $plotH - ($value / $niceMax) * $plotH;
            $svg .= '<line class="grid-line" x1="' . $padLeft . '" y1="' . round($yy, 1)
                 .  '" x2="' . ($width - $padRight) . '" y2="' . round($yy, 1) . '"/>';
            $svg .= '<text class="axis-label" x="' . ($padLeft - 9) . '" y="' . round($yy + 3.5, 1)
                 .  '" text-anchor="end">' . sa_e(sa_axis_value($value, $format)) . '</text>';
        }

        foreach ($items as $i => $it) {
            $cx = $padLeft + $slot * $i + $slot / 2;
            $v1 = (float) (isset($it['value']) ? $it['value'] : 0);
            $h1 = $niceMax > 0 ? max($v1 > 0 ? 3 : 0, ($v1 / $niceMax) * $plotH) : 0;
            $x1 = $grouped ? $cx - $barW - 2 : $cx - $barW / 2;
            $svg .= '<g class="point">'
                 .  '<rect class="bar" x="' . round($x1, 1) . '" y="' . round($padTop + $plotH - $h1, 1)
                 .  '" width="' . round($barW, 1) . '" height="' . round($h1, 1) . '" rx="5" style="animation-delay:' . round($i * 0.045, 2) . 's">'
                 .  '<title>' . sa_e($it['label'] . ' — ' . sa_axis_value($v1, $format)) . '</title></rect>';
            if ($grouped) {
                $v2 = (float) (isset($it['value2']) ? $it['value2'] : 0);
                $h2 = $niceMax > 0 ? max($v2 > 0 ? 3 : 0, ($v2 / $niceMax) * $plotH) : 0;
                $svg .= '<rect class="bar bar-alt" x="' . round($cx + 2, 1) . '" y="' . round($padTop + $plotH - $h2, 1)
                     .  '" width="' . round($barW, 1) . '" height="' . round($h2, 1) . '" rx="5" style="animation-delay:' . round($i * 0.045 + 0.05, 2) . 's">'
                     .  '<title>' . sa_e($it['label'] . ' — ' . sa_axis_value($v2, $format)) . '</title></rect>';
            }
            $svg .= '</g>';
            $svg .= '<text class="axis-label" x="' . round($cx, 1) . '" y="' . ($height - 8)
                 .  '" text-anchor="middle">' . sa_e($it['label']) . '</text>';
        }

        $svg .= '</svg></div>';
        return $svg;
    }
}

if (!function_exists('sa_nice_max')) {
    /** Round a chart maximum up to a readable value (120 -> 150, 47 -> 50). */
    function sa_nice_max($max)
    {
        $max = (float) $max;
        if ($max <= 0) {
            return 1;
        }
        $exp = floor(log10($max));
        $pow = pow(10, $exp);
        $frac = $max / $pow;
        if ($frac <= 1) {
            $nice = 1;
        } elseif ($frac <= 1.5) {
            $nice = 1.5;
        } elseif ($frac <= 2) {
            $nice = 2;
        } elseif ($frac <= 2.5) {
            $nice = 2.5;
        } elseif ($frac <= 3) {
            $nice = 3;
        } elseif ($frac <= 4) {
            $nice = 4;
        } elseif ($frac <= 5) {
            $nice = 5;
        } elseif ($frac <= 7.5) {
            $nice = 7.5;
        } else {
            $nice = 10;
        }
        return $nice * $pow;
    }
}

if (!function_exists('sa_axis_value')) {
    /** Compact axis label for a value. */
    function sa_axis_value($value, $format = 'number')
    {
        $value = (float) $value;
        if ($format === 'money') {
            return sa_money_short($value);
        }
        if ($format === 'percent') {
            return rtrim(rtrim(number_format($value, 1), '0'), '.') . '%';
        }
        if ($format === 'decimal') {
            return number_format($value, 1);
        }
        if (abs($value) >= 1000) {
            return sa_money_short($value) === '' ? number_format($value / 1000, 1) . 'k' : number_format($value / 1000, 1) . 'k';
        }
        return (string) (int) round($value);
    }
}

if (!function_exists('sa_donut')) {
    /**
     * Donut chart + legend, built from CSS (no JS).
     * $segments [['label' => 'Active', 'value' => 12, 'color' => 'var(--sa-success)'], ...]
     * $center   ['value' => '18', 'label' => 'Tenants']
     */
    function sa_donut($segments, $center = [], $size = 168)
    {
        $segments = array_values(array_filter((array) $segments, function ($s) {
            return isset($s['value']) && (float) $s['value'] > 0;
        }));
        $total = 0;
        foreach ($segments as $s) {
            $total += (float) $s['value'];
        }

        if ($total <= 0) {
            return '<div class="sa-empty"><p>Nothing to show yet.</p></div>';
        }

        $stroke = 17;
        $r = ($size / 2) - ($stroke / 2) - 2;
        $c = 2 * M_PI * $r;
        $offset = 0;

        $svg  = '<div class="sa-donut-wrap">';
        $svg .= '<div class="sa-donut" style="width:' . $size . 'px;height:' . $size . 'px">';
        $svg .= '<svg viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="Distribution">';
        $svg .= '<circle class="track" cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . round($r, 2) . '"/>';

        foreach ($segments as $i => $s) {
            $len = ((float) $s['value'] / $total) * $c;
            $color = isset($s['color']) ? $s['color'] : 'var(--sa-accent)';
            $svg .= '<circle class="seg" cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . round($r, 2) . '"'
                 .  ' stroke="' . $color . '"'
                 .  ' stroke-dasharray="' . round(max($len - 2.5, 0.5), 2) . ' ' . round($c - max($len - 2.5, 0.5), 2) . '"'
                 .  ' stroke-dashoffset="' . round(-$offset, 2) . '"'
                 .  ' style="animation-delay:' . round($i * 0.09, 2) . 's">'
                 .  '<title>' . sa_e($s['label'] . ': ' . $s['value']) . '</title></circle>';
            $offset += $len;
        }

        $svg .= '</svg>';
        $svg .= '<div class="sa-donut-center">';
        $svg .= '<strong>' . sa_e(isset($center['value']) ? $center['value'] : sa_num($total)) . '</strong>';
        $svg .= '<span>' . sa_e(isset($center['label']) ? $center['label'] : 'Total') . '</span>';
        $svg .= '</div></div>';

        // Legend
        $svg .= '<div class="sa-legend">';
        foreach ($segments as $s) {
            $color = isset($s['color']) ? $s['color'] : 'var(--sa-accent)';
            $svg .= '<div class="sa-legend-row">'
                 .  '<span class="sa-legend-dot" style="--dot:' . $color . '"></span>'
                 .  '<span class="sa-legend-name">' . sa_e($s['label']) . '</span>'
                 .  '<span class="sa-legend-val">' . sa_e(isset($s['display']) ? $s['display'] : sa_num($s['value'])) . '</span>'
                 .  '<span class="sa-legend-pct">' . number_format(sa_pct($s['value'], $total, 0)) . '%</span>'
                 .  '</div>';
        }
        $svg .= '</div></div>';
        return $svg;
    }
}

if (!function_exists('sa_bar_list')) {
    /**
     * Horizontal ranked bar list.
     * $items [['label' => 'Tech Solutions', 'value' => 42, 'meta' => '4.8 avg',
     *           'max' => null, 'color' => null], ...]
     */
    function sa_bar_list($items, $options = [])
    {
        $items = array_values((array) $items);
        if (!$items) {
            return '<div class="sa-empty"><p>Nothing to show yet.</p></div>';
        }
        $max = 0;
        foreach ($items as $it) {
            $max = max($max, (float) (isset($it['value']) ? $it['value'] : 0));
        }
        $max = isset($options['max']) && $options['max'] ? (float) $options['max'] : ($max > 0 ? $max : 1);
        $format = isset($options['format']) ? $options['format'] : 'number';

        $html = '<div class="sa-bars">';
        foreach ($items as $i => $it) {
            $value = (float) (isset($it['value']) ? $it['value'] : 0);
            $w = max(1.5, ($value / $max) * 100);
            $color = isset($it['color']) ? $it['color'] : null;
            $meta = isset($it['meta']) ? $it['meta'] : sa_axis_value($value, $format);
            $html .= '<div class="sa-bar-row">'
                  .  '<div class="sa-bar-head"><strong>' . sa_e($it['label']) . '</strong>'
                  .  '<span>' . sa_e($meta) . '</span></div>'
                  .  '<div class="sa-bar-track"><i class="sa-bar-fill" style="--w:' . round($w, 2) . '%;--delay:' . round($i * 0.06, 2) . 's'
                  .  ($color ? ';--bar:' . $color : '') . '"></i></div>'
                  .  '</div>';
        }
        return $html . '</div>';
    }
}

if (!function_exists('sa_heatmap')) {
    /**
     * Activity heatmap grid (e.g. ratings per day for the last N weeks).
     * $cells [['label' => 'Mar 4', 'value' => 3], ...]
     */
    function sa_heatmap($cells, $columns = 18)
    {
        $cells = array_values((array) $cells);
        if (!$cells) {
            return '<div class="sa-empty"><p>No activity recorded yet.</p></div>';
        }
        $max = 0;
        foreach ($cells as $c) {
            $max = max($max, (float) $c['value']);
        }
        $html = '<div class="sa-heat" style="grid-template-columns:repeat(' . (int) $columns . ',minmax(0,1fr))">';
        foreach ($cells as $c) {
            $v = (float) $c['value'];
            $level = $max <= 0 ? 0 : (int) ceil(($v / $max) * 4);
            if ($v <= 0) {
                $level = 0;
            }
            $html .= '<span class="sa-heat-cell" data-level="' . $level . '" title="'
                  .  sa_e($c['label'] . ': ' . (int) $v) . '"></span>';
        }
        return $html . '</div>';
    }
}

/* ------------------------------------------------------------
   Metrics
   ------------------------------------------------------------ */

if (!function_exists('sa_metrics')) {
    /**
     * Everything the dashboard needs, in one pass.
     * Returns an associative array; missing tables degrade to zeros.
     */
    function sa_metrics($conn)
    {
        $m = [];

        $status_rows = sa_query(
            $conn,
            "SELECT subscription_status AS status, COUNT(*) AS cnt,
                    COALESCE(SUM(subscription_price),0) AS revenue
               FROM tenants GROUP BY subscription_status",
            'tenants'
        );
        $m['status'] = ['active' => 0, 'trial' => 0, 'inactive' => 0, 'cancelled' => 0];
        $m['mrr'] = 0.0;
        foreach ($status_rows as $row) {
            $key = (string) $row['status'];
            $m['status'][$key] = (int) $row['cnt'];
            if ($key === 'active') {
                $m['mrr'] = (float) $row['revenue'];
            }
        }
        $m['tenants_total'] = array_sum($m['status']);
        $m['paying']        = $m['status']['active'];
        $m['arr']           = $m['mrr'] * 12;
        $m['arpu']          = $m['paying'] > 0 ? $m['mrr'] / $m['paying'] : 0.0;

        $m['customers_total'] = (int) sa_scalar($conn, "SELECT COUNT(*) FROM customers", 0, 'customers');
        $m['ratings_total']   = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings", 0, 'ratings');
        $m['avg_rating']      = (float) sa_scalar($conn, "SELECT AVG(rating) FROM ratings", 0, 'ratings');
        $m['five_star']       = (int) sa_scalar($conn, "SELECT COUNT(*) FROM ratings WHERE rating = 5", 0, 'ratings');

        // New tenants / ratings in the last 30 days
        $m['new_tenants_30d'] = (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM tenants WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            0,
            'tenants'
        );
        $m['new_ratings_30d'] = (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM ratings WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            0,
            'ratings'
        );
        $m['prev_ratings_30d'] = (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM ratings
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                AND created_at <  DATE_SUB(NOW(), INTERVAL 30 DAY)",
            0,
            'ratings'
        );
        $m['ratings_delta'] = $m['prev_ratings_30d'] > 0
            ? (($m['new_ratings_30d'] - $m['prev_ratings_30d']) / $m['prev_ratings_30d']) * 100
            : ($m['new_ratings_30d'] > 0 ? 100.0 : 0.0);

        // Plans
        $m['plans_active'] = (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM subscription_plans WHERE status = 'active'",
            0,
            'subscription_plans'
        );

        // Quote requests
        $m['quotes_total']   = (int) sa_scalar($conn, "SELECT COUNT(*) FROM quote_requests", 0, 'quote_requests');
        $m['quotes_pending'] = (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM quote_requests WHERE status = 'pending'",
            0,
            'quote_requests'
        );

        // Subscriptions expiring in the next 30 days (or already expired)
        $m['expiring_soon'] = (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM tenants
              WHERE subscription_status IN ('active','trial')
                AND subscription_end_date IS NOT NULL
                AND subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
            0,
            'tenants'
        );
        $m['expired'] = (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM tenants
              WHERE subscription_status IN ('active','trial')
                AND subscription_end_date IS NOT NULL
                AND subscription_end_date < CURDATE()",
            0,
            'tenants'
        );

        // Trials that never converted
        $m['trial_conversion'] = $m['tenants_total'] > 0
            ? sa_pct($m['paying'], $m['tenants_total'] - $m['status']['cancelled'], 0)
            : 0;

        return $m;
    }
}

if (!function_exists('sa_revenue_trend')) {
    /**
     * Month-by-month view of the last $months months.
     * Each month carries the tenants created that month plus the
     * cumulative paying base and its monthly recurring revenue.
     */
    function sa_revenue_trend($conn, $months = 12)
    {
        $rows = sa_query(
            $conn,
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                    COUNT(*) AS new_tenants,
                    SUM(CASE WHEN subscription_status = 'active' THEN subscription_price ELSE 0 END) AS new_mrr,
                    SUM(CASE WHEN subscription_status = 'active' THEN 1 ELSE 0 END) AS new_active
               FROM tenants
              GROUP BY ym",
            'tenants'
        );
        $by_month = [];
        foreach ($rows as $r) {
            $by_month[$r['ym']] = $r;
        }

        // Cumulative totals of everything created up to each month
        $all = sa_query(
            $conn,
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                    subscription_price AS price,
                    subscription_status AS status
               FROM tenants",
            'tenants'
        );

        $out = [];
        $cum_tenants = 0;
        $cum_mrr = 0.0;
        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime('first day of -' . $i . ' month');
            $ym = date('Y-m', $ts);
            $info = isset($by_month[$ym]) ? $by_month[$ym] : null;
            foreach ($all as $t) {
                if ($t['ym'] === $ym) {
                    $cum_tenants++;
                    if ($t['status'] === 'active') {
                        $cum_mrr += (float) $t['price'];
                    }
                }
            }
            $out[] = [
                'ym'          => $ym,
                'label'       => date('M', $ts),
                'label_long'  => date('M Y', $ts),
                'new_tenants' => $info ? (int) $info['new_tenants'] : 0,
                'new_active'  => $info ? (int) $info['new_active'] : 0,
                'new_mrr'     => $info ? (float) $info['new_mrr'] : 0.0,
                'tenants'     => $cum_tenants,
                'mrr'         => $cum_mrr,
            ];
        }
        return $out;
    }
}

if (!function_exists('sa_ratings_trend')) {
    /** Ratings per day for the last $days days (for the heatmap / sparkline). */
    function sa_ratings_trend($conn, $days = 56)
    {
        $rows = sa_query(
            $conn,
            "SELECT DATE(created_at) AS d, COUNT(*) AS cnt, AVG(rating) AS avg_rating
               FROM ratings
              WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL " . (int) $days . " DAY)
              GROUP BY d",
            'ratings'
        );
        $by_day = [];
        foreach ($rows as $r) {
            $by_day[$r['d']] = $r;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $ts = strtotime('-' . $i . ' day');
            $d = date('Y-m-d', $ts);
            $out[] = [
                'date'  => $d,
                'label' => date('M j', $ts),
                'count' => isset($by_day[$d]) ? (int) $by_day[$d]['cnt'] : 0,
                'avg'   => isset($by_day[$d]) ? (float) $by_day[$d]['avg_rating'] : 0.0,
            ];
        }
        return $out;
    }
}

if (!function_exists('sa_star_distribution')) {
    /** [5 => count, 4 => count, ... 1 => count] */
    function sa_star_distribution($conn)
    {
        $rows = sa_query($conn, "SELECT rating, COUNT(*) AS cnt FROM ratings GROUP BY rating", 'ratings');
        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $r) {
            $key = (int) $r['rating'];
            if (isset($dist[$key])) {
                $dist[$key] = (int) $r['cnt'];
            }
        }
        krsort($dist);
        return $dist;
    }
}

if (!function_exists('sa_plan_distribution')) {
    /** Tenants per plan (active + trial). */
    function sa_plan_distribution($conn)
    {
        return sa_query(
            $conn,
            "SELECT p.id, p.plan_name, p.price,
                    COUNT(t.id) AS tenants,
                    COALESCE(SUM(CASE WHEN t.subscription_status = 'active' THEN t.subscription_price ELSE 0 END),0) AS mrr
               FROM subscription_plans p
               LEFT JOIN tenants t ON t.plan_id = p.id
                     AND t.subscription_status IN ('active','trial')
              GROUP BY p.id, p.plan_name, p.price
              ORDER BY p.price ASC",
            ['subscription_plans', 'tenants']
        );
    }
}

if (!function_exists('sa_top_companies')) {
    /** Most rated companies with their average score. */
    function sa_top_companies($conn, $limit = 6)
    {
        return sa_query(
            $conn,
            "SELECT c.id, c.company_name, c.tenant_id,
                    COUNT(r.id) AS rating_count,
                    COALESCE(AVG(r.rating),0) AS avg_rating
               FROM customers c
               LEFT JOIN ratings r ON r.company_id = c.id
              GROUP BY c.id, c.company_name, c.tenant_id
              HAVING rating_count > 0
              ORDER BY rating_count DESC, avg_rating DESC
              LIMIT " . (int) $limit,
            ['customers', 'ratings']
        );
    }
}

if (!function_exists('sa_expiring_subscriptions')) {
    /** Subscriptions that already expired or end within $days days. */
    function sa_expiring_subscriptions($conn, $days = 30, $limit = 6)
    {
        return sa_query(
            $conn,
            "SELECT t.id, t.company_name, t.subscription_status, t.auto_renew,
                    t.subscription_end_date, t.subscription_price, p.plan_name
               FROM tenants t
               LEFT JOIN subscription_plans p ON p.id = t.plan_id
              WHERE t.subscription_status IN ('active','trial')
                AND t.subscription_end_date IS NOT NULL
                AND t.subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL " . (int) $days . " DAY)
              ORDER BY t.subscription_end_date ASC
              LIMIT " . (int) $limit,
            ['tenants', 'subscription_plans']
        );
    }
}

if (!function_exists('sa_recent_activity')) {
    /**
     * Merged, reverse-chronological activity feed built from
     * tenants, ratings and quote requests.
     */
    function sa_recent_activity($conn, $limit = 7)
    {
        $items = [];

        foreach (sa_query(
            $conn,
            "SELECT company_name, subscription_status, created_at FROM tenants
              ORDER BY created_at DESC LIMIT 12",
            'tenants'
        ) as $r) {
            $items[] = [
                'type'  => 'tenant',
                'title' => $r['company_name'],
                'meta'  => 'New tenant · ' . ucfirst($r['subscription_status']),
                'at'    => $r['created_at'],
            ];
        }

        foreach (sa_query(
            $conn,
            "SELECT r.rating, r.customer_name, c.company_name, r.created_at
               FROM ratings r JOIN customers c ON c.id = r.company_id
              ORDER BY r.created_at DESC LIMIT 12",
            ['ratings', 'customers']
        ) as $r) {
            $items[] = [
                'type'  => 'rating',
                'title' => $r['company_name'],
                'meta'  => $r['rating'] . '-star rating from ' . $r['customer_name'],
                'at'    => $r['created_at'],
                'stars' => (int) $r['rating'],
            ];
        }

        foreach (sa_query(
            $conn,
            "SELECT company_name, status, created_at FROM quote_requests
              ORDER BY created_at DESC LIMIT 12",
            'quote_requests'
        ) as $r) {
            $items[] = [
                'type'  => 'quote',
                'title' => $r['company_name'],
                'meta'  => 'Quote request · ' . ucfirst($r['status']),
                'at'    => $r['created_at'],
            ];
        }

        usort($items, function ($a, $b) {
            return strtotime($b['at']) - strtotime($a['at']);
        });

        return array_slice($items, 0, $limit);
    }
}

if (!function_exists('sa_csrf_token')) {
    /** CSRF token for the current session (created on first use). */
    function sa_csrf_token()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['sa_csrf'])) {
            $_SESSION['sa_csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['sa_csrf'];
    }
}

if (!function_exists('sa_csrf_field')) {
    /** Hidden input to drop inside every POST form. */
    function sa_csrf_field()
    {
        return '<input type="hidden" name="csrf_token" value="' . sa_e(sa_csrf_token()) . '">';
    }
}

if (!function_exists('sa_csrf_ok')) {
    /**
     * Validate the submitted token. The token is created on first render, so
     * every form the shell emits carries one; a POST without a matching token
     * is rejected.
     */
    function sa_csrf_ok()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $sent = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
        if ($sent === '') {
            return false;
        }
        return hash_equals(sa_csrf_token(), $sent);
    }
}

if (!function_exists('sa_flash')) {
    /**
     * Queue a one-shot message for the next page load (PRG pattern).
     * sa_flash('success', 'Tenant created.');
     */
    function sa_flash($type, $message)
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['sa_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('sa_take_flash')) {
    /** Read and clear the queued flash message (returns null when empty). */
    function sa_take_flash()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['sa_flash'])) {
            return null;
        }
        $flash = $_SESSION['sa_flash'];
        unset($_SESSION['sa_flash']);
        return $flash;
    }
}

if (!function_exists('sa_render_flash')) {
    /** Render the queued flash message as a dismissible, auto-hiding alert. */
    function sa_render_flash()
    {
        $flash = sa_take_flash();
        if (!$flash) {
            return '';
        }
        $type = $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : 'success');
        $icon = $type === 'success' ? 'check-circle' : 'alert';
        return '<div class="sa-alert sa-alert-' . $type . '" data-sa-alert data-sa-autohide="7000" role="status">'
            . sa_icon($icon)
            . '<div>' . sa_e($flash['message']) . '</div>'
            . '<button type="button" data-sa-alert-close aria-label="Dismiss" '
            . 'style="margin-left:auto;flex:none;width:26px;height:26px;display:grid;place-items:center;'
            . 'border-radius:8px;border:1px solid currentColor;background:transparent;color:inherit;cursor:pointer;opacity:.7">'
            . '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.6" '
            . 'stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/>'
            . '<line x1="6" y1="6" x2="18" y2="18"/></svg></button>'
            . '</div>';
    }
}

if (!function_exists('sa_unique_username')) {
    /**
     * Build a unique login slug from a company name:
     * "ABC Corporation" -> abc_corporation (then _2, _3, ... on collision).
     */
    function sa_unique_username($conn, $company_name, $ignore_id = 0)
    {
        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $company_name), '_'));
        $base = $base !== '' ? substr($base, 0, 40) : 'tenant';
        $candidate = $base;
        $i = 1;
        while (true) {
            $sql = "SELECT COUNT(*) FROM tenants WHERE username = '"
                 . $conn->real_escape_string($candidate) . "'";
            if ($ignore_id) {
                $sql .= " AND id <> " . (int) $ignore_id;
            }
            if ((int) sa_scalar($conn, $sql, 0, 'tenants') === 0) {
                return $candidate;
            }
            $i++;
            $candidate = $base . '_' . $i;
        }
    }
}

if (!function_exists('sa_tenant_counts')) {
    /** Tenant counts per subscription status, for filter chips. */
    function sa_tenant_counts($conn)
    {
        $counts = ['all' => 0, 'active' => 0, 'trial' => 0, 'inactive' => 0, 'cancelled' => 0];
        foreach (sa_query($conn, "SELECT subscription_status AS s, COUNT(*) AS c FROM tenants GROUP BY subscription_status", 'tenants') as $row) {
            if (isset($counts[$row['s']])) {
                $counts[$row['s']] = (int) $row['c'];
            }
            $counts['all'] += (int) $row['c'];
        }
        return $counts;
    }
}
