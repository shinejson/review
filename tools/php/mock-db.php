<?php
/**
 * ============================================================
 *  Mock mysqli connection for the PHP render harness
 * ============================================================
 *  Lets the REAL superadmin pages run without a MySQL server:
 *  tools/php/render.php copies the app to a temp directory,
 *  replaces config/database.php with this mock and executes each
 *  page, so templates, helpers and shell are exercised exactly as
 *  they would be in production.
 *
 *  Unmatched SQL is reported on STDERR so missing fixtures are
 *  obvious instead of silently rendering empty pages.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

class MockResult
{
    public $rows;
    public $num_rows;
    private $pos = 0;

    public function __construct($rows = [])
    {
        $this->rows = array_map(['MockResult', 'aliasAggregates'], array_values($rows));
        $this->num_rows = count($this->rows);
    }

    /**
     * The public pages read aggregate columns through lowercase aliases
     * ("COUNT(*) as count"). Expose both spellings so a fixture row works
     * whichever way the query projected it.
     */
    private static function aliasAggregates($row)
    {
        if (!is_array($row)) {
            return $row;
        }
        $map = ['COUNT(*)' => 'count', 'AVG(rating)' => 'avg', 'SUM(price)' => 'total'];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $row) && !array_key_exists($to, $row)) {
                $row[$to] = $row[$from];
            }
        }
        return $row;
    }

    public function fetch_assoc()
    {
        return $this->pos < $this->num_rows ? $this->rows[$this->pos++] : null;
    }

    public function fetch_all()
    {
        return $this->rows;
    }

    public function data_seek($i)
    {
        $this->pos = (int) $i;
    }

    public function free()
    {
    }
}

class MockStmt
{
    public $error = '';
    public $errno = 0;
    public $insert_id = 0;
    public $affected_rows = 1;
    public $sql;
    private $conn;
    private $types = '';
    private $params = [];
    private $result = null;

    public function __construct($conn, $sql)
    {
        $this->conn = $conn;
        $this->sql = $sql;
    }

    public function bind_param($types, &...$params)
    {
        $this->types = $types;
        $this->params = &$params;
        return true;
    }

    public function bind_result(&...$vars)
    {
        return true;
    }

    public function execute()
    {
        $values = [];
        foreach ($this->params as $i => $unused) {
            $values[] = $this->params[$i];
        }
        $this->conn->logWrite($this->sql, $values);
        $this->result = new MockResult($this->conn->match($this->sql, $values));
        return true;
    }

    public function get_result()
    {
        return $this->result;
    }

    public function fetch()
    {
        return $this->result ? $this->result->fetch_assoc() : null;
    }

    public function close()
    {
        return true;
    }
}

class MockMysqli
{
    public $connect_error = null;
    public $insert_id = 99;
    public $affected_rows = 1;
    public $error = '';
    public $errno = 0;
    public $server_info = '8.0.0-mock';

    private $data;
    private $unmatched = [];
    private $writes = [];

    public function __construct($host = null, $user = null, $pass = null, $db = null)
    {
        $this->data = require dirname(__DIR__) . '/tools/php/dataset.php';
    }

    public function set_charset($c)
    {
        return true;
    }

    public function real_escape_string($s)
    {
        return addslashes((string) $s);
    }

    public function prepare($sql)
    {
        return new MockStmt($this, $sql);
    }

    public function query($sql)
    {
        if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', (string) $sql)) {
            $this->logWrite($sql, []);
        }
        return new MockResult($this->match($sql, []));
    }

    public function close()
    {
        return true;
    }

    public function logWrite($sql, $values)
    {
        if (!preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', (string) $sql)) {
            return; // prepared SELECT: not a write
        }
        $this->writes[] = ['sql' => $sql, 'values' => $values];
        $this->log('write', preg_replace('/\s+/', ' ', trim($sql)) . ' -- ' . json_encode($values));
    }

    public function report()
    {
        return ['unmatched' => $this->unmatched, 'writes' => $this->writes];
    }

    /* ------------------------------------------------------------
       Dataset helpers
       ------------------------------------------------------------ */
    private function norm($sql)
    {
        return preg_replace('/\s+/', ' ', trim((string) $sql));
    }

    private function has($hay, $needle)
    {
        return stripos($hay, $needle) !== false;
    }

    /**
     * Driving table of the outer statement. Scans at parenthesis depth 0 so
     * subqueries — e.g. the "(SELECT COUNT(*) FROM customers …)" inside the
     * tenant listing — cannot masquerade as the main table.
     */
    private function outerTable($sql)
    {
        $len = strlen($sql);
        $depth = 0;
        $firstWord = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                if ($depth > 0) {
                    $depth--;
                }
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($firstWord === '') {
                if (preg_match('/\G[A-Za-z_]+/', $sql, $m, 0, $i)) {
                    $firstWord = strtoupper($m[0]);
                    if (in_array($firstWord, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                        if (preg_match('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([a-z_]+)`?/i', $sql, $mt)) {
                            return strtolower($mt[1]);
                        }
                        return '';
                    }
                }
                continue;
            }
            if ($firstWord === 'SELECT' && preg_match('/\GFROM\s+`?([a-z_]+)`?/i', $sql, $m, 0, $i)) {
                return strtolower($m[1]);
            }
        }
        return '';
    }

    /**
     * SQL with parenthesised subqueries removed, so conditions are only
     * matched against the outer statement. Linear scan: a recursive regex
     * here backtracks catastrophically on real queries.
     */
    private function outer($sql)
    {
        $out = '';
        $depth = 0;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === '(') {
                $depth++;
                if ($depth === 1) {
                    $out .= ' () ';
                }
                continue;
            }
            if ($ch === ')') {
                if ($depth > 0) {
                    $depth--;
                }
                continue;
            }
            if ($depth === 0) {
                $out .= $ch;
            }
        }
        return preg_replace('/\s+/', ' ', $out);
    }

    /** Bcrypt hash for the fixture tenant password (computed once). */
    private function tenantHash()
    {
        static $hash = null;
        if ($hash === null) {
            $hash = password_hash('tenant123', PASSWORD_DEFAULT);
        }
        return $hash;
    }

    private function tenants($withPlan = true)
    {
        $out = [];
        foreach ($this->data['tenants'] as $t) {
            $end = $t['days'] === null ? null : date('Y-m-d', strtotime('today ' . ($t['days'] >= 0 ? '+' : '-') . abs($t['days']) . ' day'));
            $row = [
                'id' => $t['id'],
                'company_name' => $t['company_name'],
                'email' => $t['email'],
                'phone' => $t['phone'],
                'username' => $t['username'],
                'password' => $this->tenantHash(),
                'plan_id' => $t['plan_id'],
                'subscription_status' => $t['subscription_status'],
                'subscription_price' => $t['subscription_price'],
                'subscription_start_date' => date('Y-m-d', strtotime($t['created_at'])),
                'subscription_end_date' => $end,
                'auto_renew' => $t['auto_renew'],
                'created_at' => $t['created_at'],
            ];
            if ($withPlan) {
                $row['plan_name'] = $t['plan_name'];
                $row['plan_price'] = $this->planPrice($t['plan_id']);
                $row['max_ratings'] = $this->planField($t['plan_id'], 'max_ratings');
                $row['max_customers'] = $this->planField($t['plan_id'], 'max_customers');
                $row['customer_count'] = $t['companies'];
            }
            $out[] = $row;
        }
        return $out;
    }

    private function planPrice($id)
    {
        foreach ($this->data['subscription_plans'] as $p) {
            if ((int) $p['id'] === (int) $id) {
                return $p['price'];
            }
        }
        return '0.00';
    }

    private function planField($id, $field)
    {
        foreach ($this->data['subscription_plans'] as $p) {
            if ((int) $p['id'] === (int) $id) {
                return $p[$field];
            }
        }
        return 0;
    }

    /** Customer rows plus the category_id the public pages expect. */
    private function customersWithCategory()
    {
        $byName = [];
        foreach ($this->data['categories'] as $cat) {
            $byName[$cat['name']] = $cat['id'];
        }
        $out = [];
        foreach ($this->data['customers'] as $c) {
            $c['category_id'] = isset($byName[$c['category_name']]) ? $byName[$c['category_name']] : 1;
            $out[] = $c;
        }
        return $out;
    }

    private function monthKey($dateStr)
    {
        $ts = strtotime($dateStr);
        return $ts ? date('Y-m', $ts) : '';
    }

    private function monthList($count)
    {
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $out[] = date('Y-m', strtotime('first day of -' . $i . ' month'));
        }
        return $out;
    }

    private function dayList($count)
    {
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $out[] = date('Y-m-d', strtotime('-' . $i . ' day'));
        }
        return $out;
    }

    private function unmatchedSql($sql)
    {
        $short = preg_replace('/\s+/', ' ', trim($sql));
        if (strlen($short) > 190) {
            $short = substr($short, 0, 190) . '…';
        }
        $this->unmatched[] = $short;
        $this->log('unmatched', $short);
        return [];
    }

    /**
     * Append a diagnostic line to the harness log so the Node runner can
     * report queries the mock did not understand.
     */
    private function log($kind, $message)
    {
        $file = getenv('SA_SQL_LOG');
        if (!$file) {
            return;
        }
        @file_put_contents($file, $kind . "\t" . $message . "\n", FILE_APPEND);
    }

    /* ------------------------------------------------------------
       The matcher
       ------------------------------------------------------------ */
    public function match($sql, $values = [])
    {
        $s = $this->norm($sql);
        $o = $this->outer($s);          // outer query, subqueries stripped
        $tbl = $this->outerTable($s);   // driving table of the outer query
        $D = $this->data;
        $this->log('branch', $tbl !== '' ? $tbl : '(none)');

        /* ---- fresh-install mode: the schema exists but holds no data ---- */
        if (getenv('SA_EMPTY_DB') === '1' && !$this->has($s, 'information_schema.tables')) {
            if ($tbl === 'super_admins') {
                return [$D['super_admins'][0]];   // the signed-in account
            }
            if ($tbl === 'settings') {
                // a couple of rows survived the installer, the rest are absent
                return $this->has($s, 'COUNT(*)')
                    ? [['COUNT(*)' => 1]]
                    : [['setting_key' => 'site_name', 'setting_value' => 'Optibiz']];
            }
            if (preg_match('/^SELECT COUNT\(\*\)/i', $s) || $this->has($o, 'COUNT(*)')) {
                return [['COUNT(*)' => 0, 'count' => 0, 'c' => 0, 'cnt' => 0]];
            }
            return [];
        }

        /* ---- schema probe ---- */
        if ($this->has($s, 'information_schema.tables')) {
            preg_match("/table_name = '([a-z_]+)'/i", $s, $m);
            $known = ['super_admins', 'subscription_plans', 'tenants', 'admins', 'categories', 'customers', 'ratings', 'settings', 'quote_requests'];
            $c = (isset($m[1]) && in_array($m[1], $known, true)) ? 1 : 0;
            return [['c' => $c]];
        }

        /* ---- table row counts (settings health check) ---- */
        if (preg_match('/SELECT COUNT\(\*\) FROM `([a-z_]+)`/i', $s, $m)) {
            $table = $m[1];
            $known = [
                'super_admins' => count($D['super_admins']),
                'subscription_plans' => count($D['subscription_plans']),
                'tenants' => count($D['tenants']),
                'admins' => 2,
                'categories' => count($D['categories']),
                'customers' => 184,
                'ratings' => array_sum($D['star_distribution']),
                'settings' => count($D['settings']),
                'quote_requests' => count($D['quote_requests']),
            ];
            $c = isset($known[$table]) ? $known[$table] : 0;
            return [['COUNT(*)' => $c]];
        }

        /* ---- settings ---- */
        if ($tbl === 'settings') {
            if ($this->has($s, 'COUNT(*)')) {
                preg_match("/setting_key = '([^']+)'/", $s, $m);
                $found = 0;
                foreach (($D['settings'] ?? []) as $row) {
                    if (isset($m[1]) && $row['setting_key'] === $m[1]) {
                        $found = 1;
                    }
                }
                return [['COUNT(*)' => $found]];
            }
            return $D['settings'];
        }

        /* ---- super admins ---- */
        if ($tbl === 'super_admins') {
            $id = $values ? (int) $values[0] : 1;
            if (preg_match('/WHERE id = (\d+)/', $s, $m)) {
                $id = (int) $m[1];
            }
            $row = $D['super_admins'][0];
            foreach ($D['super_admins'] as $candidate) {
                if ((int) $candidate['id'] === $id) {
                    $row = $candidate;
                    break;
                }
            }
            // Honour the projected columns so "SELECT password …" works
            preg_match('/^SELECT (.+?) FROM/i', $s, $cols);
            $out = [];
            foreach (array_map('trim', explode(',', isset($cols[1]) ? $cols[1] : '*')) as $col) {
                $col = trim($col, '` ');
                if ($col === '*') {
                    $out = $row;
                    break;
                }
                if (array_key_exists($col, $row)) {
                    $out[$col] = $row[$col];
                }
            }
            return [$out];
        }

        /* ---- tenant admins (admin/ panel) ---- */
        if ($tbl === 'admins') {
            $row = $D['admins'][0];
            if (preg_match('/username = \'([^\']+)\'/', $s, $mu)) {
                foreach ($D['admins'] as $candidate) {
                    if ($candidate['username'] === $mu[1]) {
                        $row = $candidate;
                        break;
                    }
                }
            }
            if (preg_match('/WHERE id = (\d+)/', $s, $m)) {
                foreach ($D['admins'] as $candidate) {
                    if ((int) $candidate['id'] === (int) $m[1]) {
                        $row = $candidate;
                        break;
                    }
                }
            }
            if ($this->has($o, 'COUNT(*)')) {
                return [['COUNT(*)' => count($D['admins']), 'count' => count($D['admins'])]];
            }
            preg_match('/^SELECT (.+?) FROM/i', $s, $cols);
            $out = [];
            foreach (array_map('trim', explode(',', isset($cols[1]) ? $cols[1] : '*')) as $col) {
                $col = trim($col, '` ');
                if ($col === '*') {
                    $out = $row;
                    break;
                }
                if (array_key_exists($col, $row)) {
                    $out[$col] = $row[$col];
                }
            }
            return [$out];
        }

        /* ---- categories ---- */
        if ($tbl === 'categories') {
            if ($this->has($o, 'COUNT(*)')) {
                return [['COUNT(*)' => count($D['categories']), 'count' => count($D['categories'])]];
            }
            return $D['categories'];
        }

        /* ---- plans ---- */
        if ($tbl === 'subscription_plans') {
            if ($this->has($s, 'SELECT price') && preg_match('/WHERE id = (\d+)/', $s, $m)) {
                return [['price' => $this->planPrice($m[1])]];
            }
            $rows = [];
            foreach ($D['subscription_plans'] as $p) {
                $tenantsOnPlan = 0;
                $trials = 0;
                $planMrr = 0.0;
                foreach ($D['tenants'] as $t) {
                    if ((int) $t['plan_id'] === (int) $p['id']) {
                        if (in_array($t['subscription_status'], ['active', 'trial'], true)) {
                            $tenantsOnPlan++;
                        }
                        if ($t['subscription_status'] === 'trial') {
                            $trials++;
                        }
                        if ($t['subscription_status'] === 'active') {
                            $planMrr += (float) $t['subscription_price'];
                        }
                    }
                }
                $rows[] = array_merge($p, [
                    'tenant_count' => $tenantsOnPlan,
                    'tenants' => $tenantsOnPlan,
                    'trial_count' => $trials,
                    'mrr' => number_format($planMrr, 2, '.', ''),
                ]);
            }
            if ($this->has($s, "status = 'active'")) {
                $rows = array_values(array_filter($rows, function ($r) {
                    return $r['status'] === 'active';
                }));
            }
            return $rows;
        }

        /* ---- quote requests ---- */
        if ($tbl === 'quote_requests') {
            if ($this->has($s, 'GROUP BY status')) {
                $counts = [];
                foreach ($D['quote_requests'] as $q) {
                    if (!isset($counts[$q['status']])) {
                        $counts[$q['status']] = 0;
                    }
                    $counts[$q['status']]++;
                }
                $out = [];
                foreach ($counts as $status => $c) {
                    $out[] = ['status' => $status, 'c' => $c, 'cnt' => $c];
                }
                return $out;
            }
            if ($this->has($s, 'q.id, p.price')) {
                $out = [];
                foreach ($D['quote_requests'] as $q) {
                    if ($q['status'] === 'converted') {
                        $out[] = ['id' => $q['id'], 'price' => $this->planPrice($q['plan_id'])];
                    }
                }
                return $out;
            }
            $rows = $D['quote_requests'];
            if (preg_match("/q.status = '([a-z]+)'/", $s, $m)) {
                $rows = array_values(array_filter($rows, function ($q) use ($m) {
                    return $q['status'] === $m[1];
                }));
            }
            if (preg_match('/WHERE q.id = (\d+)/', $s, $m)) {
                $rows = array_values(array_filter($rows, function ($q) use ($m) {
                    return (int) $q['id'] === (int) $m[1];
                }));
            }
            if ($this->has($s, 'COUNT(*)')) {
                if ($this->has($s, "status = 'pending'")) {
                    $n = count(array_filter($rows, function ($q) {
                        return $q['status'] === 'pending';
                    }));
                    return [['COUNT(*)' => $n]];
                }
                return [['COUNT(*)' => count($rows)]];
            }
            return $rows;
        }

        /* ---- ratings ---- */
        if ($tbl === 'ratings' && !$this->has($o, 'JOIN customers')) {
            if ($this->has($s, 'DATE_FORMAT(created_at')) {
                return $this->ratingsPerMonth();
            }
            if ($this->has($s, 'AVG(rating)') && !$this->has($s, 'GROUP BY')) {
                return [['AVG(rating)' => $this->avgRating(), 'avg' => $this->avgRating(), 'avg_rating' => $this->avgRating()]];
            }
            if ($this->has($s, 'GROUP BY rating')) {
                $out = [];
                foreach ($D['star_distribution'] as $rating => $cnt) {
                    $out[] = ['rating' => $rating, 'cnt' => $cnt, 'count' => $cnt];
                }
                return $out;
            }
            if ($this->has($s, 'GROUP BY DATE(created_at)') || $this->has($s, 'DATE(created_at) AS d')) {
                $perDay = $this->ratingsPerDay();
                $out = [];
                foreach ($perDay as $date => $cnt) {
                    if ($cnt > 0) {
                        $out[] = ['d' => $date, 'cnt' => $cnt, 'avg_rating' => 4.6];
                    }
                }
                return $out;
            }
            if ($this->has($s, 'GROUP BY DATE_FORMAT(created_at')) {
                return $this->ratingsPerMonth();
            }
            if ($this->has($s, 'COUNT(*)')) {
                if ($this->has($s, 'rating = 5')) {
                    return [['COUNT(*)' => $D['star_distribution'][5]]];
                }
                if ($this->has($s, 'INTERVAL 60 DAY') && $this->has($s, 'INTERVAL 30 DAY')) {
                    return [['COUNT(*)' => 93]];
                }
                if ($this->has($s, 'INTERVAL 30 DAY')) {
                    return [['COUNT(*)' => 104]];
                }
                return [['COUNT(*)' => array_sum($D['star_distribution'])]];
            }
            // recent ratings list
            return array_slice($D['ratings_recent'], 0, 12);
        }

        /* ---- ratings joined to customers (tenant scoped) ---- */
        if ($tbl === 'ratings' && $this->has($o, 'JOIN customers')) {
            if ($this->has($s, 'AVG(r.rating)') && $this->has($s, 'r.rating = 5')) {
                return [['COUNT(*)' => 88]];
            }
            if ($this->has($s, 'GROUP BY r.rating')) {
                $out = [];
                foreach ([5 => 88, 4 => 61, 3 => 27, 2 => 12, 1 => 8] as $rating => $cnt) {
                    $out[] = ['rating' => $rating, 'cnt' => $cnt];
                }
                return $out;
            }
            if ($this->has($s, 'AVG(r.rating)') && $this->has($s, 'COUNT(*)')) {
                return [['COUNT(*)' => 196, 'AVG(r.rating)' => 4.6]];
            }
            if ($this->has($s, 'AVG(r.rating)')) {
                return [['AVG(r.rating)' => 4.6]];
            }
            if ($this->has($s, 'INTERVAL 30 DAY')) {
                return [['COUNT(*)' => 42]];
            }
            if ($this->has($s, 'COUNT(*)')) {
                return [['COUNT(*)' => 196]];
            }
            if ($this->has($o, 'r.*') || $this->has($o, 'c.company_name')) {
                $byId = [];
                foreach ($this->customersWithCategory() as $c) {
                    $byId[(int) $c['id']] = $c['company_name'];
                }
                $out = [];
                foreach ($D['ratings'] as $r) {
                    $row = $r;
                    $row['company_name'] = isset($byId[(int) $r['company_id']]) ? $byId[(int) $r['company_id']] : '';
                    $out[] = $row;
                }
                return $out;
            }
            if ($this->has($s, 'ORDER BY r.created_at DESC')) {
                return $D['ratings_recent'];
            }
            return $D['ratings_recent'];
        }

        /* ---- customers ---- */
        if ($tbl === 'customers') {
            if ($this->has($s, 'COUNT(*)') && $this->has($s, 'tenant_id')) {
                preg_match('/tenant_id = (\d+)/', $s, $m);
                $id = $m ? (int) $m[1] : 0;
                $n = 0;
                foreach ($D['tenants'] as $t) {
                    if ((int) $t['id'] === $id) {
                        $n = (int) $t['companies'];
                    }
                }
                return [['COUNT(*)' => $n]];
            }
            if ($this->has($s, 'COUNT(*)')) {
                return [['COUNT(*)' => 184]];
            }
            if ($this->has($s, 'HAVING rating_count')) {
                $rows = [];
                foreach ($this->customersWithCategory() as $i => $c) {
                    $rows[] = [
                        'id' => $c['id'],
                        'company_name' => $c['company_name'],
                        'tenant_id' => $c['tenant_id'],
                        'rating_count' => $c['rating_count'],
                        'avg_rating' => $c['avg_rating'],
                    ];
                }
                usort($rows, function ($a, $b) {
                    return $b['rating_count'] <=> $a['rating_count'];
                });
                if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                    $rows = array_slice($rows, 0, (int) $m[1]);
                }
                return $rows;
            }
            if ($this->has($s, 'GROUP BY c.id') || $this->has($s, 'cat.name AS category_name')) {
                preg_match('/c.tenant_id = (\d+)/', $s, $m);
                $tid = $m ? (int) $m[1] : 0;
                $rows = [];
                foreach ($this->customersWithCategory() as $c) {
                    if ($tid === 0 || (int) $c['tenant_id'] === $tid) {
                        $rows[] = $c;
                    }
                }
                usort($rows, function ($a, $b) {
                    return $b['rating_count'] <=> $a['rating_count'];
                });
                return $rows;
            }
            return $this->customersWithCategory();
        }

        /* ---- tenants ---- */
        if ($tbl === 'tenants') {
            // tenant league table
            if ($this->has($s, 'COUNT(DISTINCT c.id)')) {
                $out = [];
                foreach ($this->tenants() as $t) {
                    $out[] = [
                        'id' => $t['id'],
                        'company_name' => $t['company_name'],
                        'subscription_status' => $t['subscription_status'],
                        'plan_name' => $t['plan_name'],
                        'companies' => $t['customer_count'],
                        'rating_count' => $t['customer_count'] * 5,
                        'avg_rating' => 4.5,
                    ];
                }
                usort($out, function ($a, $b) {
                    return $b['rating_count'] <=> $a['rating_count'];
                });
                return $out;
            }

            // plan distribution
            if ($this->has($s, 'LEFT JOIN tenants t') && $this->has($s, 'GROUP BY p.id')) {
                $out = [];
                foreach ($D['subscription_plans'] as $p) {
                    $n = 0;
                    $planMrr = 0.0;
                    foreach ($D['tenants'] as $t) {
                        if ((int) $t['plan_id'] === (int) $p['id'] && in_array($t['subscription_status'], ['active', 'trial'], true)) {
                            $n++;
                            if ($t['subscription_status'] === 'active') {
                                $planMrr += (float) $t['subscription_price'];
                            }
                        }
                    }
                    $out[] = ['id' => $p['id'], 'plan_name' => $p['plan_name'], 'price' => $p['price'], 'tenants' => $n, 'mrr' => number_format($planMrr, 2, '.', '')];
                }
                return $out;
            }

            // tenant acquisition per month (analytics)
            if ($this->has($s, 'GROUP BY ym') && $this->has($s, 'AS cnt')) {
                $out = [];
                foreach ($this->monthList(24) as $ym) {
                    $n = 0;
                    foreach ($D['tenants'] as $t) {
                        if ($this->monthKey($t['created_at']) === $ym) {
                            $n++;
                        }
                    }
                    if ($n > 0) {
                        $out[] = ['ym' => $ym, 'cnt' => $n];
                    }
                }
                return $out;
            }

            // revenue trend aggregates grouped by month
            if ($this->has($s, 'GROUP BY ym') && $this->has($s, 'COUNT(*)')) {
                $out = [];
                foreach ($this->monthList(24) as $ym) {
                    $new = 0;
                    $newMrr = 0.0;
                    $newActive = 0;
                    foreach ($D['tenants'] as $t) {
                        if ($this->monthKey($t['created_at']) === $ym) {
                            $new++;
                            if ($t['subscription_status'] === 'active') {
                                $newMrr += (float) $t['subscription_price'];
                                $newActive++;
                            }
                        }
                    }
                    if ($new > 0) {
                        $out[] = ['ym' => $ym, 'new_tenants' => $new, 'new_mrr' => number_format($newMrr, 2, '.', ''), 'new_active' => $newActive, 'cnt' => $new];
                    }
                }
                return $out;
            }

            // raw tenant list for cumulative maths
            if ($this->has($s, 'subscription_price AS price')) {
                $out = [];
                foreach ($D['tenants'] as $t) {
                    $out[] = ['ym' => $this->monthKey($t['created_at']), 'price' => $t['subscription_price'], 'status' => $t['subscription_status']];
                }
                return $out;
            }

            // status breakdown
            if ($this->has($s, 'GROUP BY subscription_status')) {
                $agg = [];
                foreach ($D['tenants'] as $t) {
                    $k = $t['subscription_status'];
                    if (!isset($agg[$k])) {
                        $agg[$k] = ['status' => $k, 'cnt' => 0, 'revenue' => 0.0];
                    }
                    $agg[$k]['cnt']++;
                    $agg[$k]['revenue'] += (float) $t['subscription_price'];
                }
                $out = [];
                foreach ($agg as $k => $v) {
                    $out[] = [
                        'status' => $k,
                        'subscription_status' => $k,
                        's' => $k,
                        'cnt' => $v['cnt'],
                        'c' => $v['cnt'],
                        'count' => $v['cnt'],
                        'revenue' => number_format($v['revenue'], 2, '.', ''),
                        'mrr' => number_format($v['revenue'], 2, '.', ''),
                    ];
                }
                return $out;
            }

            // counts (leading COUNT only — listing queries embed a
            // customer-count subquery and must not be treated as aggregates)
            if (preg_match('/^SELECT COUNT\(\*\)/i', $s)) {
                $rows = $this->tenants();
                if (preg_match('/plan_id = (\d+)/', $s, $mp)) {
                    $rows = array_filter($rows, function ($t) use ($mp) {
                        return (int) $t['plan_id'] === (int) $mp[1];
                    });
                    return [['COUNT(*)' => count($rows)]];
                }
                if ($this->has($s, 'auto_renew = 1')) {
                    $rows = array_filter($rows, function ($t) {
                        return (int) $t['auto_renew'] === 1;
                    });
                }
                if (preg_match("/subscription_status = '([a-z]+)'/", $s, $m)) {
                    $rows = array_filter($rows, function ($t) use ($m) {
                        return $t['subscription_status'] === $m[1];
                    });
                }
                if ($this->has($s, 'INTERVAL 30 DAY') && $this->has($s, 'subscription_end_date')) {
                    $rows = array_filter($rows, function ($t) {
                        return in_array($t['subscription_status'], ['active', 'trial'], true) && $t['subscription_end_date']
                            && strtotime($t['subscription_end_date']) <= strtotime('+30 day');
                    });
                }
                if ($this->has($s, 'subscription_end_date < CURDATE()')) {
                    $rows = array_filter($rows, function ($t) {
                        return $t['subscription_end_date'] && strtotime($t['subscription_end_date']) < strtotime('today');
                    });
                }
                if ($this->has($s, 'INTERVAL 30 DAY') && $this->has($s, 'created_at')) {
                    $rows = array_filter($rows, function ($t) {
                        return strtotime($t['created_at']) >= strtotime('-30 day');
                    });
                }
                if ($this->has($s, 'email =') && preg_match("/email = '([^']+)'/", $s, $m)) {
                    $rows = array_filter($rows, function ($t) use ($m) {
                        return $t['email'] === $m[1];
                    });
                    if ($this->has($s, 'id <>') && preg_match('/id <> (\d+)/', $s, $mi)) {
                        $rows = array_filter($rows, function ($t) use ($mi) {
                            return (int) $t['id'] !== (int) $mi[1];
                        });
                    }
                }
                if ($this->has($s, 'username =') && preg_match("/username = '([^']+)'/", $s, $m)) {
                    $rows = array_filter($rows, function ($t) use ($m) {
                        return $t['username'] === $m[1];
                    });
                }
                return [['COUNT(*)' => count($rows)]];
            }

            // single tenant (profile page)
            if (preg_match('/WHERE t\.id = (\d+)/', $s, $m) || ($values && preg_match('/WHERE t\.id = \?/', $s))) {
                $id = isset($m[1]) ? (int) $m[1] : (int) $values[0];
                foreach ($this->tenants() as $t) {
                    if ((int) $t['id'] === $id) {
                        return [$t];
                    }
                }
                return [];
            }
            if ($this->has($s, 'SELECT company_name FROM tenants WHERE id =')) {
                preg_match('/id = (\d+)/', $s, $m);
                $id = isset($m[1]) ? (int) $m[1] : 0;
                foreach ($this->tenants() as $t) {
                    if ((int) $t['id'] === $id) {
                        return [['company_name' => $t['company_name']]];
                    }
                }
                return [['company_name' => '']];
            }

            // expiring subscriptions list
            if ($this->has($s, 'subscription_end_date <=') && $this->has($s, 'ORDER BY t.subscription_end_date')) {
                $rows = array_filter($this->tenants(), function ($t) {
                    return in_array($t['subscription_status'], ['active', 'trial'], true) && $t['subscription_end_date']
                        && strtotime($t['subscription_end_date']) <= strtotime('+30 day');
                });
                usort($rows, function ($a, $b) {
                    return strtotime($a['subscription_end_date']) <=> strtotime($b['subscription_end_date']);
                });
                return array_slice(array_values($rows), 0, 6);
            }

            // subscriptions screen (ordered by end date)
            if ($this->has($s, 't.subscription_end_date IS NULL')) {
                $rows = $this->tenants();
                usort($rows, function ($a, $b) {
                    $x = $a['subscription_end_date'] ? strtotime($a['subscription_end_date']) : PHP_INT_MAX;
                    $y = $b['subscription_end_date'] ? strtotime($b['subscription_end_date']) : PHP_INT_MAX;
                    return $x <=> $y;
                });
                return $rows;
            }

            // main tenant listing with optional status filter
            $rows = $this->tenants();
            if (preg_match("/t.subscription_status = '([a-z]+)'/", $s, $m)) {
                $rows = array_values(array_filter($rows, function ($t) use ($m) {
                    return $t['subscription_status'] === $m[1];
                }));
            }
            if ($this->has($s, "subscription_status IN ('active','trial')") && $this->has($s, 'subscription_end_date <= DATE_ADD')) {
                $rows = array_values(array_filter($rows, function ($t) {
                    return in_array($t['subscription_status'], ['active', 'trial'], true) && $t['subscription_end_date']
                        && strtotime($t['subscription_end_date']) <= strtotime('+30 day');
                }));
            }
            if ($this->has($s, 'LIKE')) {
                preg_match_all("/LIKE '([^']+)'/", $s, $likes);
                if (!empty($likes[1])) {
                    $needle = strtolower(trim($likes[1][0], '%'));
                    $rows = array_values(array_filter($rows, function ($t) use ($needle) {
                        return stripos($t['company_name'], $needle) !== false
                            || stripos($t['email'], $needle) !== false
                            || stripos($t['username'], $needle) !== false;
                    }));
                }
            }
            if ($this->has($s, 'ORDER BY t.created_at DESC')) {
                usort($rows, function ($a, $b) {
                    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
                });
            }
            if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                $rows = array_slice($rows, 0, (int) $m[1]);
            }
            return $rows;
        }

        /* ---- writes ---- */
        if (preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $s)) {
            return [];
        }

        return $this->unmatchedSql($sql);
    }

    /* ------------------------------------------------------------
       Derived series
       ------------------------------------------------------------ */
    private function avgRating()
    {
        $sum = 0;
        $n = 0;
        foreach ($this->data['star_distribution'] as $star => $cnt) {
            $sum += $star * $cnt;
            $n += $cnt;
        }
        return $n ? round($sum / $n, 4) : 0;
    }

    private function ratingsPerDay()
    {
        $days = $this->dayList(count($this->data['ratings_per_day']));
        $out = [];
        foreach ($days as $i => $d) {
            $out[$d] = (int) $this->data['ratings_per_day'][$i];
        }
        return $out;
    }

    private function ratingsPerMonth()
    {
        $out = [];
        foreach ($this->monthList(12) as $i => $ym) {
            $out[] = [
                'ym' => $ym,
                'cnt' => 40 + (($i * 17) % 65),
                'avg_rating' => round(4.2 + (($i * 3) % 6) / 10, 2),
            ];
        }
        return $out;
    }
}

/* The app's config/database.php is replaced by this during a render */
$conn = new MockMysqli();

if (!defined('DB_NAME')) {
    define('DB_NAME', 'company_rating_saas');
}
