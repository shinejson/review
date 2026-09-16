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
        $map = [
            'COUNT(*)'    => ['count', 'cnt'],
            'AVG(rating)' => ['avg', 'avg_rating'],
            'SUM(price)'  => ['total'],
        ];
        foreach ($map as $from => $targets) {
            if (!array_key_exists($from, $row)) {
                continue;
            }
            foreach ($targets as $to) {
                if (!array_key_exists($to, $row)) {
                    $row[$to] = $row[$from];
                }
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

    /** mysqli_result aliases for free() — auth.php calls close() after
     *  probing the super_admins schema. */
    public function free_result()
    {
    }

    public function close()
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

    /** Customer rows plus the category_id the public pages expect and
     *  the tenant_company join superadmin/customers.php reads. */
    private function customersWithCategory()
    {
        $byName = [];
        foreach ($this->data['categories'] as $cat) {
            $byName[$cat['name']] = $cat['id'];
        }
        $tenantName = [];
        foreach ($this->data['tenants'] as $t) {
            $tenantName[(int) $t['id']] = $t['company_name'];
        }
        $out = [];
        foreach ($this->data['customers'] as $c) {
            $c['category_id'] = isset($byName[$c['category_name']]) ? $byName[$c['category_name']] : 1;
            $c['tenant_company'] = isset($tenantName[(int) $c['tenant_id']])
                ? $tenantName[(int) $c['tenant_id']] : null;
            $out[] = $c;
        }
        return $out;
    }

    /**
     * Project a fixture row onto the columns the statement asked for, so
     * "SELECT password FROM …" and "SELECT * FROM …" both work.
     */
    private function project($row, $sql)
    {
        preg_match('/^SELECT (.+?) FROM/i', $sql, $cols);
        $out = [];
        foreach (array_map('trim', explode(',', isset($cols[1]) ? $cols[1] : '*')) as $col) {
            $col = trim($col, '` ');
            // "i.*" / "sp.*" — a wildcard on a joined table
            if (preg_match('/^[a-z]+\s*\.\s*\*$/i', $col)) {
                return $row;
            }
            if ($col === '*') {
                return $row;
            }
            if (strpos($col, '(') !== false) {
                continue;                       // aggregates are handled earlier
            }
            $alias = null;
            if (preg_match('/^(.+?)\s+AS\s+([a-z_][a-z0-9_]*)$/i', $col, $m)) {
                $col = trim($m[1]);
                $alias = $m[2];
            }
            if (strpos($col, '.') !== false) {
                $col = trim(substr($col, strrpos($col, '.') + 1), '` ');
            }
            $key = $alias !== null ? $alias : $col;
            if (array_key_exists($col, $row)) {
                $out[$key] = $row[$col];
            } elseif ($alias !== null && array_key_exists($alias, $row)) {
                $out[$alias] = $row[$alias];
            }
        }
        return $out;
    }

    private function monthKey($dateStr)
    {
        $ts = strtotime($dateStr);
        return $ts ? date('Y-m', $ts) : '';
    }

    /* ------------------------------------------------------------
       Billing fixtures
       ------------------------------------------------------------ */

    /** Fixture rows for one of the billing tables. */
    private function payRows($tbl)
    {
        return (isset($this->data[$tbl]) && is_array($this->data[$tbl])) ? $this->data[$tbl] : [];
    }

    /** Attach the tenant / invoice / plan columns the billing screens project. */
    private function payDecorate($tbl, $rows)
    {
        $D = $this->data;
        $tenants = [];
        foreach ($this->tenants() as $t) {
            $tenants[(int) $t['id']] = $t;
        }
        $invoices = [];
        foreach ($D['payment_invoices'] as $inv) {
            $invoices[(int) $inv['id']] = $inv;
        }
        $plans = [];
        foreach ($D['subscription_plans'] as $p) {
            $plans[(int) $p['id']] = $p['plan_name'];
        }

        $out = [];
        foreach ($rows as $row) {
            if (isset($row['tenant_id']) && isset($tenants[(int) $row['tenant_id']])) {
                $t = $tenants[(int) $row['tenant_id']];
                $row['company_name'] = $t['company_name'];
                $row['tenant_email'] = $t['email'];
                $row['tenant_phone'] = isset($t['phone']) ? $t['phone'] : '';
                $row['email'] = $t['email'];
            }
            if (!empty($row['invoice_id']) && isset($invoices[(int) $row['invoice_id']])) {
                $inv = $invoices[(int) $row['invoice_id']];
                $row['invoice_number'] = $inv['invoice_number'];
                $row['invoice_months'] = $inv['months'];
                $row['invoice_status'] = $inv['status'];
                if (empty($row['plan_id'])) {
                    $row['plan_id'] = $inv['plan_id'];
                }
                if (empty($row['subject'])) {
                    $row['subject'] = $inv['subject'];
                }
                if (empty($row['months'])) {
                    $row['months'] = $inv['months'];
                }
            }
            if (!array_key_exists('invoice_number', $row)) {
                $row['invoice_number'] = null;      // LEFT JOIN with nothing attached
            }
            if ($tbl === 'payment_invoices') {
                /* "(SELECT COUNT(*) … ) AS pending_payments" — the approval badge */
                $row['pending_payments'] = 0;
                foreach ($D['subscription_payments'] as $pay) {
                    if ((int) $pay['invoice_id'] === (int) $row['id'] && $pay['status'] === 'pending') {
                        $row['pending_payments']++;
                    }
                }
            }
            if (!array_key_exists('plan_name', $row)) {
                $row['plan_name'] = (!empty($row['plan_id']) && isset($plans[(int) $row['plan_id']]))
                    ? $plans[(int) $row['plan_id']] : null;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Read a billing column for a predicate, honouring the table alias. */
    private function payColValue($row, $prefix, $col)
    {
        if ($prefix === 'i') {
            if ($col === 'status' && array_key_exists('invoice_status', $row)) {
                return $row['invoice_status'];
            }
            if ($col === 'id' && array_key_exists('invoice_id', $row)) {
                return $row['invoice_id'];
            }
            if ($col === 'months' && array_key_exists('invoice_months', $row)) {
                return $row['invoice_months'];
            }
        }
        if ($prefix === 't' && $col === 'id' && array_key_exists('tenant_id', $row)) {
            return $row['tenant_id'];
        }
        return array_key_exists($col, $row) ? $row[$col] : null;
    }

    /** Apply the WHERE clauses the billing screens build. */
    private function payWhere($tbl, $rows, $s, $o)
    {
        $preds = [];

        if (preg_match_all("/\b(?:(t|i|sp|r|p)\.)?([a-z_]+)\s*=\s*'([^']*)'/i", $o, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { $preds[] = [$m[1], $m[2], '=', $m[3]]; }
        }
        if (preg_match_all("/\b(?:(t|i|sp|r|p)\.)?([a-z_]+)\s*(?:<>|!=)\s*'([^']*)'/i", $o, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { $preds[] = [$m[1], $m[2], '!=', $m[3]]; }
        }
        if (preg_match_all('/\b(?:(t|i|sp|r|p)\.)?([a-z_]+)\s*=\s*(\d+)(?![\w.])/i', $o, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { $preds[] = [$m[1], $m[2], '=', $m[3]]; }
        }
        /* IN lists are read from the raw SQL: their brackets sit inside a
           pair the subquery stripper blanks out. */
        if (preg_match_all('/\b(?:(t|i|sp|r|p)\.)?([a-z_]+)\s+IN\s*\(([^)]*)\)/i', $s, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $vals = [];
                foreach (explode(',', $m[3]) as $v) {
                    $v = trim($v);
                    $vals[] = is_numeric($v) ? $v : trim($v, "' ");
                }
                $preds[] = [$m[1], $m[2], 'IN', $vals];
            }
        }
        if (preg_match_all("/\b(?:(t|i|sp|r|p)\.)?([a-z_]+)\s+LIKE\s*'([^']*)'/i", $o, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { $preds[] = [$m[1], $m[2], 'LIKE', trim($m[3], '%')]; }
        }
        if (preg_match_all('/\b(?:(t|i|sp|r|p)\.)?([a-z_]+)\s+IS\s+NOT\s+NULL/i', $o, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { $preds[] = [$m[1], $m[2], 'NOTNULL', null]; }
        }
        if (preg_match_all('/\b(?:(t|i|sp|r|p)\.)?([a-z_]+)\s+IS\s+NULL/i', $o, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { $preds[] = [$m[1], $m[2], 'ISNULL', null]; }
        }
        /* Date windows live inside DATE_SUB(...)/CURDATE() calls, which the
           subquery stripper blanks out, so they are read from the raw SQL. */
        if ($this->has($s, 'due_date < CURDATE()')) {
            $preds[] = [null, 'due_date', 'PAST', null];
        }

        $from = null;
        $to = null;
        if (preg_match('/created_at >= DATE_SUB\(CURDATE\(\), INTERVAL (\d+) DAY\)/', $s, $m)) {
            $from = strtotime('-' . (int) $m[1] . ' day');
        }
        if (preg_match('/created_at < DATE_SUB\(CURDATE\(\), INTERVAL (\d+) DAY\)/', $s, $m)) {
            $to = strtotime('-' . (int) $m[1] . ' day');
        }
        if (preg_match("/created_at >= '(\d{4}-\d{2}-\d{2})[^']*'/", $s, $m)) {
            $from = strtotime($m[1]);
        }

        $out = [];
        foreach ($rows as $row) {
            $ok = true;
            foreach ($preds as $p) {
                $val = $this->payColValue($row, $p[0], $p[1]);
                switch ($p[2]) {
                    case '=':
                        $ok = is_numeric($p[3]) ? ((float) $val === (float) $p[3]) : ((string) $val === (string) $p[3]);
                        break;
                    case '!=':
                        $ok = (string) $val !== (string) $p[3];
                        break;
                    case 'IN':
                        $ok = false;
                        foreach ($p[3] as $want) {
                            if ((string) $val === (string) $want) { $ok = true; break; }
                        }
                        break;
                    case 'LIKE':
                        $needle = function_exists('mb_strtolower') ? mb_strtolower($p[3]) : strtolower($p[3]);
                        $ok = $needle === '' || strpos(strtolower((string) $val), $needle) !== false;
                        break;
                    case 'NOTNULL':
                        $ok = $val !== null && $val !== '';
                        break;
                    case 'ISNULL':
                        $ok = $val === null || $val === '';
                        break;
                    case 'PAST':
                        $ok = !empty($val) && strtotime((string) $val) < strtotime('today');
                        break;
                }
                if (!$ok) {
                    break;
                }
            }
            if ($ok && ($from !== null || $to !== null)) {
                $ts = strtotime((string) $this->payColValue($row, null, 'created_at'));
                if ($from !== null && $ts < $from) { $ok = false; }
                if ($to !== null && $ts >= $to) { $ok = false; }
            }
            if ($ok) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Monthly buckets: SUM(amount|total) plus COUNT(*). */
    private function payByMonth($tbl, $rows)
    {
        $col = $tbl === 'payment_invoices' ? 'total' : 'amount';
        $agg = [];
        foreach ($rows as $r) {
            $ym = $this->monthKey($r['created_at']);
            if (!isset($agg[$ym])) {
                $agg[$ym] = ['ym' => $ym, 'total' => 0, 'c' => 0];
            }
            $agg[$ym]['total'] += (float) (isset($r[$col]) ? $r[$col] : 0);
            $agg[$ym]['c']++;
        }
        $out = [];
        foreach ($agg as $ym => $a) {
            $out[] = [
                'ym' => $ym,
                'total' => $a['total'],
                'SUM(' . $col . ')' => $a['total'],
                'c' => $a['c'],
                'cnt' => $a['c'],
                'COUNT(*)' => $a['c'],
            ];
        }
        return $out;
    }

    /** Grouped totals (gateway breakdown, payment methods). */
    private function payByGroup($rows, $col, $withSource = false)
    {
        $agg = [];
        foreach ($rows as $r) {
            $value = isset($r[$col]) && $r[$col] !== null && $r[$col] !== '' ? (string) $r[$col] : 'offline';
            $key = $withSource ? $value . '|' . (isset($r['source']) ? $r['source'] : '') : $value;
            if (!isset($agg[$key])) {
                $agg[$key] = [$col => $value, 'payments' => 0, 'total' => 0];
                if ($withSource) {
                    $agg[$key]['source'] = isset($r['source']) ? $r['source'] : '';
                }
            }
            $agg[$key]['payments']++;
            $agg[$key]['total'] += (float) (isset($r['amount']) ? $r['amount'] : 0);
        }
        usort($agg, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });
        return array_values($agg);
    }

    /** Revenue per plan. */
    private function payByPlan($rows)
    {
        $agg = [];
        foreach ($rows as $r) {
            $plan = (!empty($r['plan_name'])) ? (string) $r['plan_name'] : 'No plan';
            if (!isset($agg[$plan])) {
                $agg[$plan] = ['plan_name' => $plan, 'payments' => 0, 'total' => 0];
            }
            $agg[$plan]['payments']++;
            $agg[$plan]['total'] += (float) (isset($r['amount']) ? $r['amount'] : 0);
        }
        usort($agg, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });
        return array_values($agg);
    }

    /** One entry point for every billing statement. */
    private function payQuery($tbl, $s, $o)
    {
        $rows = $this->payWhere($tbl, $this->payDecorate($tbl, $this->payRows($tbl)), $s, $o);
        $D = $this->data;

        if (preg_match('/GROUP BY\s+ym/i', $o)) {
            return $this->payByMonth($tbl, $rows);
        }
        if (preg_match('/GROUP BY\s+gateway_key/i', $o)) {
            return $this->payByGroup($rows, 'gateway_key', $this->has($o, 'source'));
        }
        if (preg_match('/GROUP BY\s+payment_method/i', $o)) {
            return $this->payByGroup($rows, 'payment_method');
        }
        if (preg_match('/GROUP BY\s+plan_name/i', $o)) {
            foreach ($rows as $i => $r) {
                if (empty($r['plan_name']) && !empty($r['plan_id'])) {
                    foreach ($D['subscription_plans'] as $p) {
                        if ((int) $p['id'] === (int) $r['plan_id']) {
                            $rows[$i]['plan_name'] = $p['plan_name'];
                        }
                    }
                }
            }
            return $this->payByPlan($rows);
        }

        if (preg_match('/^\s*SELECT\s+COUNT\s*\(/i', $s)) {
            $n = count($rows);
            return [['COUNT(*)' => $n, 'c' => $n, 'cnt' => $n, 'count' => $n]];
        }
        if (preg_match('/^\s*SELECT\s+COALESCE\s*\(\s*SUM\s*\(\s*(?:[a-z]+\.)?([a-z_]+)\s*\)/i', $s, $m)) {
            $col = $m[1];
            $sum = 0.0;
            foreach ($rows as $r) {
                $sum += (float) (isset($r[$col]) ? $r[$col] : 0);
            }
            return [['SUM(' . $col . ')' => $sum, 'total' => $sum, 'value' => $sum]];
        }
        if (preg_match('/^\s*SELECT\s+COALESCE\s*\(\s*MAX\s*\(\s*([a-z_]+)\s*\)/i', $s, $m)) {
            $max = 0;
            foreach ($rows as $r) {
                $max = max($max, (int) (isset($r['id']) ? $r['id'] : 0));
            }
            return [['MAX(' . $m[1] . ')' => $max, 'max_id' => $max]];
        }

        if ($this->has($o, 'ORDER BY sp.created_at DESC') || $this->has($o, 'ORDER BY r.created_at DESC')) {
            usort($rows, function ($a, $b) {
                return strcmp((string) $b['created_at'], (string) $a['created_at']);
            });
        } elseif ($this->has($o, 'ORDER BY id DESC')) {
            usort($rows, function ($a, $b) {
                return (int) $b['id'] <=> (int) $a['id'];
            });
        }

        if (preg_match('/LIMIT (\d+)(?:\s+OFFSET (\d+))?/i', $o, $m)) {
            $rows = array_slice($rows, isset($m[2]) ? (int) $m[2] : 0, (int) $m[1]);
        }
        return array_map(function ($r) use ($s) { return $this->project($r, $s); }, $rows);
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
        $this->log('sql', preg_replace('/\s+/', ' ', trim($sql)));

        /* ---- DDL: the app self-heals its schema, the mock already has it ---- */
        if (preg_match('/^\s*(CREATE|ALTER|DROP)\s+TABLE/i', $s)) {
            return [];
        }

        /* ---- super admin schema probe (sa_ensure_user_schema) ---- */
        if (stripos($s, 'SHOW COLUMNS FROM super_admins') === 0) {
            // the permission columns are part of the migrated schema
            $cols = ['id', 'username', 'password', 'email', 'created_at', 'permissions', 'is_owner'];
            return array_map(function ($c) {
                return ['Field' => $c, 'Type' => 'text', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''];
            }, $cols);
        }
        /* ---- activity log schema probe (sa_logs_ensure_schema) ---- */
        if (stripos($s, 'SHOW COLUMNS FROM system_logs') === 0) {
            $cols = ['id', 'portal', 'tenant_id', 'user_id', 'user_label', 'action', 'description',
                     'entity_type', 'entity_id', 'ip_address', 'user_agent', 'created_at'];
            if (preg_match("/LIKE '([a-z_]+)'/i", $s, $lm)) {
                $cols = array_values(array_filter($cols, function ($c) use ($lm) {
                    return $c === $lm[1];
                }));
            }
            return array_map(function ($c) {
                return ['Field' => $c, 'Type' => 'text', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''];
            }, $cols);
        }
        /* ---- workspace backup schema probe ---- */
        if (stripos($s, 'SHOW COLUMNS FROM tenant_backups') === 0) {
            $cols = ['id', 'tenant_id', 'filename', 'format', 'size_bytes', 'table_count',
                     'record_count', 'created_by_label', 'created_at'];
            return array_map(function ($c) {
                return ['Field' => $c, 'Type' => 'text', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''];
            }, $cols);
        }

        /* ---- workspace schema probe (ensureWhatsappColumn) ---- */
        if (stripos($s, 'SHOW COLUMNS FROM customers') === 0) {
            // the fixture database is already migrated to database.sql
            $cols = ['id', 'tenant_id', 'company_name', 'category_id', 'email', 'phone', 'whatsapp_number', 'website', 'created_at'];
            if (preg_match("/LIKE '([a-z_]+)'/i", $s, $lm)) {
                $cols = array_values(array_filter($cols, function ($c) use ($lm) {
                    return $c === $lm[1];
                }));
            }
            return array_map(function ($c) {
                return ['Field' => $c, 'Type' => 'text', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''];
            }, $cols);
        }
        if ($tbl === 'super_admins' && $this->has($s, 'COUNT(*)') && $this->has($s, 'is_owner = 1')) {
            return [['COUNT(*)' => 1, 'c' => 1]];   // the dataset owner account
        }

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
            $known = ['super_admins', 'subscription_plans', 'tenants', 'admins', 'categories', 'customers', 'ratings', 'settings', 'quote_requests',
                      'subscription_requests', 'social_accounts', 'social_posts', 'user_sessions', 'system_logs', 'tenant_backups',
                      'payment_gateways', 'payment_invoices', 'subscription_payments', 'payment_refunds', 'payment_events'];
            $c = (isset($m[1]) && in_array($m[1], $known, true)) ? 1 : 0;
            return [['c' => $c]];
        }

        /* ---- billing schema probe (pay_add_columns on the ledger) ---- */
        if (preg_match('/^SHOW COLUMNS FROM `?([a-z_]+)`?/i', $s, $sc)
            && in_array($sc[1], ['payment_gateways', 'payment_invoices', 'subscription_payments', 'payment_refunds', 'payment_events'], true)) {
            // The fixture database is already migrated, so no ALTER is issued.
            $cols = ['id', 'tenant_id', 'invoice_id', 'receipt_number', 'amount', 'currency', 'fee',
                     'payment_method', 'gateway_key', 'gateway_reference', 'transaction_ref', 'channel',
                     'status', 'source', 'payer_name', 'payer_email', 'payer_phone', 'months_extended',
                     'notes', 'reject_reason', 'recorded_by', 'verified_by', 'verified_at', 'paid_at', 'created_at'];
            return array_map(function ($c) {
                return ['Field' => $c, 'Type' => 'text', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''];
            }, $cols);
        }

        /* ---- recurring revenue (financial centre MRR / ARPU) ---- */
        if ($tbl === 'tenants' && $this->has($s, 'SUM(subscription_price)') && !$this->has($s, 'GROUP BY')) {
            $rows = $this->tenants();
            if (preg_match("/subscription_status = '([a-z]+)'/", $s, $m)) {
                $status = $m[1];
                $rows = array_values(array_filter($rows, function ($t) use ($status) {
                    return $t['subscription_status'] === $status;
                }));
            }
            $sum = 0.0;
            foreach ($rows as $t) {
                $sum += (float) $t['subscription_price'];
            }
            return [['SUM(subscription_price)' => $sum, 'mrr' => $sum, 'total' => $sum]];
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
                'system_logs' => count($D['system_logs']),
                'tenant_backups' => count($D['tenant_backups']),
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
            if ($this->has($o, 'username = ?') || $this->has($o, 'email = ?')) {
                // A login lookup: an unknown account must return no rows,
                // otherwise the caller signs in as whoever is first.
                $needle = $values ? (string) $values[0] : '';
                foreach ($D['super_admins'] as $candidate) {
                    if ($candidate['username'] === $needle || $candidate['email'] === $needle) {
                        return [$this->project($candidate, $s)];
                    }
                }
                return [];
            }
            $row = $D['super_admins'][0];
            foreach ($D['super_admins'] as $candidate) {
                if ((int) $candidate['id'] === $id) {
                    $row = $candidate;
                    break;
                }
            }
            // Honour the projected columns so "SELECT password …" works
            return [$this->project($row, $s)];
        }

        /* ---- tenant admins (admin/ panel) ---- */
        if ($tbl === 'admins') {
            $row = $D['admins'][0];
            if ($this->has($o, 'username = ?') || $this->has($o, 'email = ?')) {
                $needle = $values ? (string) $values[0] : '';
                foreach ($D['admins'] as $candidate) {
                    if ($candidate['username'] === $needle || $candidate['email'] === $needle) {
                        return [$this->project($candidate, $s)];
                    }
                }
                return [];
            }
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
            return [$this->project($row, $s)];
        }

        /* ---- categories ---- */
        if ($tbl === 'categories') {
            if ($this->has($o, 'COUNT(*)') && !$this->has($o, 'company_count')) {
                return [['COUNT(*)' => count($D['categories']), 'count' => count($D['categories'])]];
            }
            // superadmin/categories.php projects per-category company and
            // quote-request counts; the public wizard reads the plain list
            $out = [];
            foreach ($D['categories'] as $cat) {
                $n = 0;
                foreach ($this->customersWithCategory() as $c) {
                    if ((int) $c['category_id'] === (int) $cat['id']) {
                        $n++;
                    }
                }
                $q = 0;
                foreach ($D['quote_requests'] as $qr) {
                    if ((int) $qr['category_id'] === (int) $cat['id']) {
                        $q++;
                    }
                }
                $out[] = array_merge($cat, ['company_count' => $n, 'count' => $n, 'quote_count' => $q]);
            }
            return $out;
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
            if (preg_match('/WHERE (?:p\.)?id = (\d+)/', $o, $m)) {
                $id = (int) $m[1];
                $rows = array_values(array_filter($rows, function ($r) use ($id) {
                    return (int) $r['id'] === $id;
                }));
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
            /* workspace analysis: headline stats for one window */
            if ($this->has($s, 'AS responses') && $this->has($s, 'promoters')) {
                $prev = $this->has($s, 'AND r.created_at < DATE_SUB');
                return [[
                    'responses'  => $prev ? 138 : 164,
                    'avg_rating' => $prev ? 4.42 : 4.61,
                    'promoters'  => $prev ? 104 : 132,
                    'passives'   => $prev ? 22 : 21,
                    'detractors' => $prev ? 12 : 11,
                    'commented'  => $prev ? 96 : 118,
                    'companies'  => 5,
                ]];
            }
            /* workspace analysis: month-by-month trend */
            if ($this->has($s, 'AS ym')) {
                $volume = [42, 48, 51, 60, 58, 66, 71, 69, 78, 84, 91, 97];
                $score  = [4.10, 4.18, 4.22, 4.31, 4.28, 4.40, 4.44, 4.49, 4.52, 4.55, 4.60, 4.66];
                $out = [];
                for ($i = 11; $i >= 0; $i--) {
                    $out[] = [
                        'ym'         => date('Y-m', strtotime('first day of -' . $i . ' month')),
                        'responses'  => $volume[11 - $i],
                        'avg_rating' => $score[11 - $i],
                    ];
                }
                return $out;
            }
            if ($this->has($s, 'AVG(r.rating)') && $this->has($s, 'r.rating = 5')) {
                return [['COUNT(*)' => 88]];
            }
            if ($this->has($s, 'GROUP BY r.rating')) {
                $out = [];
                foreach ([5 => 88, 4 => 61, 3 => 27, 2 => 12, 1 => 8] as $rating => $cnt) {
                    $out[] = ['rating' => $rating, 'cnt' => $cnt, 'total' => $cnt];
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
                $byTenant = [];
                foreach ($this->customersWithCategory() as $c) {
                    $byId[(int) $c['id']] = $c['company_name'];
                    $byTenant[(int) $c['id']] = (int) $c['tenant_id'];
                }
                /* c.tenant_id = N scopes the join to one workspace — the
                   backup export counts on it. */
                $tid = 0;
                if (preg_match('/c\.tenant_id = (\d+)/', $s, $mt)) {
                    $tid = (int) $mt[1];
                }
                $out = [];
                foreach ($D['ratings'] as $r) {
                    $cid = (int) $r['company_id'];
                    if ($tid !== 0 && (isset($byTenant[$cid]) ? $byTenant[$cid] : 0) !== $tid) {
                        continue;
                    }
                    $row = $r;
                    $row['company_name'] = isset($byId[$cid]) ? $byId[$cid] : '';
                    $out[] = $row;
                }
                usort($out, function ($a, $b) {
                    return (int) $a['id'] <=> (int) $b['id'];
                });
                return $out;
            }
            if ($this->has($s, 'ORDER BY r.created_at DESC')) {
                return $D['ratings_recent'];
            }
            return $D['ratings_recent'];
        }

        /* ---- customers ---- */
        if ($tbl === 'customers') {
            /* public directory (companies.php): c.* + category/tenant join
               + a per-company review summary in subqueries. Matched before
               the COUNT(*) branches, which would otherwise read the counts
               inside those subqueries as a table total. */
            if ($this->has($s, 'AS tenant_banner')) {
                $rows = [];
                foreach ($this->customersWithCategory() as $c) {
                    $c['tenant_name'] = $c['tenant_company'];
                    $rows[] = $c;
                }
                usort($rows, function ($a, $b) {
                    return strcmp($a['company_name'], $b['company_name']);
                });
                return $rows;
            }
            if ($this->has($s, 'COUNT(*)') && $this->has($s, 'category_id IS NULL')) {
                return [['COUNT(*)' => 0]];
            }
            if ($this->has($s, 'COUNT(*)') && $this->has($s, 'category_id')) {
                preg_match('/category_id = (\d+)/', $s, $m);
                $cat = $m ? (int) $m[1] : 0;
                $n = 0;
                foreach ($this->customersWithCategory() as $c) {
                    if ((int) $c['category_id'] === $cat) {
                        $n++;
                    }
                }
                return [['COUNT(*)' => $n]];
            }
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
            /* workspace analysis: one row per company, window vs window */
            if ($this->has($s, 'prev_avg')) {
                preg_match('/c.tenant_id = (\d+)/', $s, $mt);
                $tid = $mt ? (int) $mt[1] : 0;
                $sample = [
                    [64, 4.82, 4.61, 58, 2],
                    [41, 4.58, 4.66, 33, 3],
                    [38, 4.71, 4.44, 31, 1],
                    [29, 4.10, 4.38, 18, 5],
                    [24, 4.55, 4.52, 19, 2],
                ];
                $rows = [];
                $i = 0;
                foreach ($this->customersWithCategory() as $c) {
                    if ($tid !== 0 && (int) $c['tenant_id'] !== $tid) {
                        continue;
                    }
                    $set = $sample[$i % count($sample)];
                    $i++;
                    $rows[] = [
                        'id'                 => $c['id'],
                        'company_name'       => $c['company_name'],
                        'category_name'      => $c['category_name'],
                        'lifetime_responses' => $c['rating_count'],
                        'lifetime_avg'       => $c['avg_rating'],
                        'last_response'      => date('Y-m-d H:i:s', strtotime('-' . ($i * 2) . ' day')),
                        'responses'          => $set[0],
                        'avg_rating'         => $set[1],
                        'promoters'          => $set[3],
                        'detractors'         => $set[4],
                        'prev_responses'     => (int) round($set[0] * 0.82),
                        'prev_avg'           => $set[2],
                    ];
                }
                usort($rows, function ($a, $b) {
                    return $b['responses'] <=> $a['responses'];
                });
                return $rows;
            }
            if ($this->has($s, 'GROUP BY c.id') || $this->has($s, 'cat.name AS category_name')) {
                preg_match('/c.tenant_id = (\d+)/', $s, $m);
                $tid = $m ? (int) $m[1] : 0;
                preg_match('/c.category_id = (\d+)/', $s, $mc);
                $cid = $mc ? (int) $mc[1] : 0;
                $rows = [];
                foreach ($this->customersWithCategory() as $c) {
                    if (($tid === 0 || (int) $c['tenant_id'] === $tid)
                        && ($cid === 0 || (int) $c['category_id'] === $cid)) {
                        $rows[] = $c;
                    }
                }
                usort($rows, function ($a, $b) {
                    return $b['rating_count'] <=> $a['rating_count'];
                });
                return $rows;
            }
            /* plain workspace list: SELECT * FROM customers WHERE tenant_id = N
               (the workspace backup export) — a tenant may only ever get its
               own rows back, which is what the export relies on. */
            if (preg_match('/tenant_id = (\d+)/', $s, $mt)) {
                $tid  = (int) $mt[1];
                $rows = array_values(array_filter($this->customersWithCategory(), function ($c) use ($tid) {
                    return (int) $c['tenant_id'] === $tid;
                }));
                usort($rows, function ($a, $b) {
                    return (int) $a['id'] <=> (int) $b['id'];
                });
                if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                    $rows = array_slice($rows, 0, (int) $m[1]);
                }
                return $rows;
            }
            return $this->customersWithCategory();
        }

        /* ---- tenants ---- */
        if ($tbl === 'tenants') {
            /* Renewal radar (superadmin/finance.php): the workspaces closest
               to expiry plus how many invoices are still open on each. */
            if ($this->has($s, 'AS open_invoices')) {
                $open = [];
                foreach ($D['payment_invoices'] as $inv) {
                    if (in_array($inv['status'], ['open', 'overdue', 'processing'], true)) {
                        $tid = (int) $inv['tenant_id'];
                        $open[$tid] = (isset($open[$tid]) ? $open[$tid] : 0) + 1;
                    }
                }
                $out = [];
                foreach ($this->tenants() as $t) {
                    if (!in_array($t['subscription_status'], ['active', 'trial'], true)) {
                        continue;
                    }
                    if (empty($t['subscription_end_date'])) {
                        continue;
                    }
                    $t['open_invoices'] = isset($open[(int) $t['id']]) ? $open[(int) $t['id']] : 0;
                    $out[] = $t;
                }
                usort($out, function ($a, $b) {
                    return strcmp((string) $a['subscription_end_date'], (string) $b['subscription_end_date']);
                });
                if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                    $out = array_slice($out, 0, (int) $m[1]);
                }
                return $out;
            }
            // full plan + tenant row (admin/subscription.php, admin/index.php)
            if ($this->has($s, 'p.plan_name') && preg_match('/t.id = (\d+)/', $s, $mf)) {
                foreach ($this->tenants() as $t) {
                    if ((int) $t['id'] === (int) $mf[1]) {
                        $t['features'] = $this->planField($t['plan_id'], 'features');
                        return [$t];
                    }
                }
                return [];
            }
            // plan limit lookup for a single tenant (superadmin/customers.php)
            if ($this->has($s, 'p.max_customers') && preg_match('/t.id = (\d+)/', $s, $m)) {
                foreach ($this->tenants() as $t) {
                    if ((int) $t['id'] === (int) $m[1]) {
                        return [['max_customers' => $t['max_customers']]];
                    }
                }
                return [];
            }

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

            // tenant login / password lookups (admin/login.php, admin/settings.php)
            if ($this->has($o, 'username = ?') || $this->has($o, 'email = ?')) {
                $needle = $values ? (string) $values[0] : '';
                foreach ($this->tenants() as $t) {
                    if ($t['username'] === $needle || $t['email'] === $needle) {
                        return [$t];
                    }
                }
                return [];
            }
            if ($this->has($o, 'SELECT password') && $values) {
                foreach ($this->tenants() as $t) {
                    if ((int) $t['id'] === (int) $values[0]) {
                        return [['password' => $t['password']]];
                    }
                }
                return [['password' => '']];
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

        /* ---- plan change requests (admin/subscription.php, superadmin) ---- */
        if ($tbl === 'subscription_requests') {
            $rows = $D['subscription_requests'];
            if (preg_match('/sr\.tenant_id = (\d+)/', $s, $m) || preg_match('/tenant_id = (\d+)/', $s, $m)) {
                $tid = (int) $m[1];
                $rows = array_values(array_filter($rows, function ($r) use ($tid) {
                    return (int) $r['tenant_id'] === $tid;
                }));
            }
            if ($this->has($s, "status = 'pending'")) {
                $rows = array_values(array_filter($rows, function ($r) {
                    return $r['status'] === 'pending';
                }));
            }
            if ($this->has($s, 'COUNT(*)')) {
                return [['COUNT(*)' => count($rows)]];
            }
            if (preg_match('/sr\.id = (\d+)|WHERE id = (\d+)/', $s, $m)) {
                $id = (int) (isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1]);
                $rows = array_values(array_filter($rows, function ($r) use ($id) {
                    return (int) $r['id'] === $id;
                }));
            }
            if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                $rows = array_slice($rows, 0, (int) $m[1]);
            }
            return $rows;
        }

        /* ---- social connections + post library (admin/social.php) ---- */
        if ($tbl === 'social_accounts') {
            $rows = $D['social_accounts'];
            if (preg_match('/tenant_id = (\d+)/', $s, $m)) {
                $tid = (int) $m[1];
                $rows = array_values(array_filter($rows, function ($r) use ($tid) {
                    return (int) $r['tenant_id'] === $tid;
                }));
            }
            if (preg_match("/platform = '([a-z]+)'/", $s, $m)) {
                $platform = $m[1];
                $rows = array_values(array_filter($rows, function ($r) use ($platform) {
                    return $r['platform'] === $platform;
                }));
            }
            return $rows;
        }

        if ($tbl === 'social_posts') {
            $rows = $D['social_posts'];
            if (preg_match('/sp\.tenant_id = (\d+)|tenant_id = (\d+)/', $s, $m)) {
                $tid = (int) (isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1]);
                $rows = array_values(array_filter($rows, function ($r) use ($tid) {
                    return (int) $r['tenant_id'] === $tid;
                }));
            }
            if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                $rows = array_slice($rows, 0, (int) $m[1]);
            }
            return $rows;
        }

        /* ---- user sessions (sign-in tracking, includes/session.php) ---- */
        if ($tbl === 'user_sessions') {
            $rows = isset($D['user_sessions']) ? $D['user_sessions'] : [];

            /* The suites can simulate a revocation issued from another
               screen: the row the harness is signed in with comes back
               closed, which must bounce it to the login screen. */
            if (getenv('SA_SESSION_REVOKED') === '1') {
                foreach ($rows as &$row) {
                    if ($row['session_token'] === 'preview-session-token') {
                        $row['logged_out_at'] = date('Y-m-d H:i:s');
                        $row['logout_reason'] = 'revoked';
                    }
                }
                unset($row);
            }

            $portal = null;
            if (preg_match("/portal = '([a-z_]+)'/", $s, $m)) {
                $portal = $m[1];                                    // literal (auth_session_counts)
            } elseif ($this->has($s, 'portal = ?')) {
                $portal = $values ? (string) $values[0] : null;     // prepared
            }

            $token = null;
            if ($this->has($s, 'session_token = ?')) {
                $token = $values ? (string) $values[0] : null;
            } elseif (preg_match("/session_token = '([^']+)'/", $s, $m)) {
                $token = $m[1];
            }

            $userId = null;
            if ($this->has($s, 'user_id = ?')) {
                $userId = isset($values[1]) ? (int) $values[1] : (isset($values[0]) ? (int) $values[0] : null);
            } elseif (preg_match('/user_id = (\d+)/', $s, $m)) {
                $userId = (int) $m[1];
            }

            /* SELECT user_id, COUNT(*) AS sessions … GROUP BY user_id */
            if ($this->has($o, 'GROUP BY user_id')) {
                $ids = [];
                if (preg_match('/user_id IN \(([\d,\s]+)\)/', $s, $m)) {
                    $ids = array_map('intval', array_map('trim', explode(',', $m[1])));
                }
                $counts = [];
                foreach ($rows as $r) {
                    if (!empty($r['logged_out_at'])) continue;
                    if ($portal !== null && $r['portal'] !== $portal) continue;
                    if ($ids && !in_array((int) $r['user_id'], $ids, true)) continue;
                    $uid = (int) $r['user_id'];
                    $counts[$uid] = isset($counts[$uid]) ? $counts[$uid] + 1 : 1;
                }
                $out = [];
                foreach ($counts as $uid => $n) {
                    $out[] = ['user_id' => $uid, 'sessions' => $n, 'COUNT(*)' => $n];
                }
                return $out;
            }

            $onlyLive = stripos($s, 'logged_out_at IS NULL') !== false;
            $match = array_values(array_filter($rows, function ($r) use ($portal, $token, $userId, $onlyLive) {
                if ($portal !== null && $r['portal'] !== $portal) return false;
                if ($token !== null && $r['session_token'] !== $token) return false;
                if ($userId !== null && (int) $r['user_id'] !== $userId) return false;
                if ($onlyLive && !empty($r['logged_out_at'])) return false;
                return true;
            }));

            if ($this->has($o, 'COUNT(*)')) {
                return [['COUNT(*)' => count($match), 'c' => count($match)]];
            }
            $rows = array_map(function ($r) use ($s) { return $this->project($r, $s); }, $match);
            if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                $rows = array_slice($rows, 0, (int) $m[1]);
            }
            return $rows;
        }

        /* ============================================================
           Activity log (includes/logging_helpers.php): the workspace
           view in admin/logs.php (filtered by tenant_id) and the
           platform view in superadmin/logs.php.
           ============================================================ */
        if ($tbl === 'system_logs') {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $s)) {
                return [];   // writes are recorded by logWrite()
            }

            $portal = null;
            if (preg_match("/portal = '([a-z_]+)'/", $o, $m)) {
                $portal = $m[1];
            }
            $tenant = null;
            if (preg_match('/tenant_id = (\d+)/', $s, $m)) {
                $tenant = (int) $m[1];
            }
            $userId = null;
            if (preg_match('/user_id = (\d+)/', $s, $m)) {
                $userId = (int) $m[1];
            }
            $action = null;
            if (preg_match("/action = '([^']+)'/", $s, $m)) {
                $action = $m[1];
            }
            $entityType = null;
            if (preg_match("/entity_type = '([^']+)'/", $s, $m)) {
                $entityType = $m[1];
            }
            $entityId = null;
            if (preg_match('/entity_id = (\d+)/', $s, $m)) {
                $entityId = (int) $m[1];
            }
            $label = null;
            if (preg_match("/user_label = '([^']+)'/", $s, $m)) {
                $label = $m[1];
            }
            $from = null;
            if (preg_match("/DATE\(created_at\) >= '([^']+)'/", $s, $m)) {
                $from = $m[1];
            }
            $to = null;
            if (preg_match("/DATE\(created_at\) <= '([^']+)'/", $s, $m)) {
                $to = $m[1];
            }
            $needle = null;
            if (preg_match("/LIKE '%([^']*)%'/", $s, $m)) {
                $needle = strtolower($m[1]);
            }

            $match = array_values(array_filter($D['system_logs'], function ($r) use ($portal, $tenant, $userId, $action, $entityType, $entityId, $label, $from, $to, $needle) {
                if ($portal !== null && $r['portal'] !== $portal) return false;
                // tenant_id NULL (platform-wide) never matches a tenant filter,
                // which is exactly what keeps the workspace log scoped.
                if ($tenant !== null && (int) $r['tenant_id'] !== $tenant) return false;
                if ($userId !== null && (int) $r['user_id'] !== $userId) return false;
                if ($action !== null && $r['action'] !== $action) return false;
                if ($entityType !== null && $r['entity_type'] !== $entityType) return false;
                if ($entityId !== null && (int) $r['entity_id'] !== $entityId) return false;
                if ($label !== null && $r['user_label'] !== $label) return false;
                if ($from !== null && substr((string) $r['created_at'], 0, 10) < $from) return false;
                if ($to !== null && substr((string) $r['created_at'], 0, 10) > $to) return false;
                if ($needle !== null) {
                    $hay = strtolower($r['action'] . ' ' . $r['description'] . ' ' . $r['user_label']);
                    if (strpos($hay, $needle) === false) return false;
                }
                return true;
            }));

            /* SELECT action, COUNT(*) … GROUP BY action (filter dropdown) */
            if ($this->has($s, 'GROUP BY action')) {
                $counts = [];
                foreach ($match as $r) {
                    $counts[$r['action']] = isset($counts[$r['action']]) ? $counts[$r['action']] + 1 : 1;
                }
                $out = [];
                foreach ($counts as $a => $n) {
                    $out[] = ['action' => $a, 'count' => $n, 'COUNT(*)' => $n];
                }
                usort($out, function ($a, $b) {
                    return ($b['count'] <=> $a['count']) ?: strcmp($a['action'], $b['action']);
                });
                if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                    $out = array_slice($out, 0, (int) $m[1]);
                }
                return $out;
            }

            /* SELECT user_label, COUNT(*) … GROUP BY user_label */
            if ($this->has($s, 'GROUP BY user_label')) {
                $counts = [];
                foreach ($match as $r) {
                    $lbl = (string) $r['user_label'];
                    if ($lbl === '') continue;
                    $counts[$lbl] = isset($counts[$lbl]) ? $counts[$lbl] + 1 : 1;
                }
                $out = [];
                foreach ($counts as $lbl => $n) {
                    $out[] = ['user_label' => $lbl, 'count' => $n, 'COUNT(*)' => $n];
                }
                usort($out, function ($a, $b) {
                    return ($b['count'] <=> $a['count']) ?: strcmp($a['user_label'], $b['user_label']);
                });
                if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                    $out = array_slice($out, 0, (int) $m[1]);
                }
                return $out;
            }

            if ($this->has($s, 'COUNT(*)')) {
                $n = count($match);
                return [['COUNT(*)' => $n, 'count' => $n, 'c' => $n, 'cnt' => $n]];
            }

            $asc = $this->has($o, 'ORDER BY created_at ASC');
            usort($match, function ($a, $b) use ($asc) {
                $cmp = strcmp((string) $a['created_at'], (string) $b['created_at']);
                if ($cmp === 0) {
                    $cmp = (int) $a['id'] <=> (int) $b['id'];
                }
                return $asc ? $cmp : -$cmp;
            });
            if (preg_match('/LIMIT (\d+)(?:\s+OFFSET (\d+))?/i', $s, $m)) {
                $match = array_slice($match, isset($m[2]) ? (int) $m[2] : 0, (int) $m[1]);
            }
            return array_map(function ($r) use ($s) { return $this->project($r, $s); }, $match);
        }

        /* ============================================================
           Workspace backups (includes/tenant_backups.php), the screen
           the tenant sees in admin/backups.php.
           ============================================================ */
        if ($tbl === 'tenant_backups') {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $s)) {
                return [];   // registered by logWrite()
            }

            $tenant = null;
            if (preg_match('/tenant_id = (\d+)/', $s, $m)) {
                $tenant = (int) $m[1];
            }
            $id = null;
            if (preg_match('/\bid = (\d+)/', $s, $m)) {
                $id = (int) $m[1];
            }

            $match = array_values(array_filter($D['tenant_backups'], function ($r) use ($tenant, $id) {
                if ($tenant !== null && (int) $r['tenant_id'] !== $tenant) return false;
                if ($id !== null && (int) $r['id'] !== $id) return false;
                return true;
            }));

            if ($this->has($s, 'COUNT(*)')) {
                $n = count($match);
                return [['COUNT(*)' => $n, 'count' => $n, 'c' => $n, 'cnt' => $n]];
            }

            usort($match, function ($a, $b) {
                $cmp = strcmp((string) $a['created_at'], (string) $b['created_at']);
                if ($cmp === 0) {
                    $cmp = (int) $a['id'] <=> (int) $b['id'];
                }
                return -$cmp;
            });
            if (preg_match('/LIMIT (\d+)/i', $s, $m)) {
                $match = array_slice($match, 0, (int) $m[1]);
            }
            return array_map(function ($r) use ($s) { return $this->project($r, $s); }, $match);
        }

        /* ============================================================
           Payments & billing (includes/payments.php and the screens
           that read it: superadmin/finance.php, payment_gateways.php,
           admin/subscription.php, admin/payment_checkout.php)
           ============================================================ */
        if (in_array($tbl, ['payment_gateways', 'payment_invoices', 'subscription_payments', 'payment_refunds', 'payment_events'], true)) {
            return $this->payQuery($tbl, $s, $o);
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
