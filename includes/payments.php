<?php
/**
 * ============================================================
 *  Optibiz — Payment integrations & billing engine
 * ============================================================
 *  Everything money-related lives here:
 *
 *    • the schema (gateways, invoices, the payment ledger, refunds,
 *      webhook events) with self-healing migrations
 *    • the gateway catalogue + per-gateway configuration
 *    • the checkout / verify / webhook drivers for Paystack and
 *      Flutterwave, plus the manual bank-transfer fallback
 *    • invoice numbering, issuing and the "apply to subscription"
 *      routine that extends a tenant's plan once money is confirmed
 *    • the aggregates the super admin financial centre reads
 *
 *  Money the gateway captures is never applied to a subscription on
 *  its own: a tenant payment lands in the ledger as `pending` and the
 *  platform owner confirms it (see pay_confirm_payment()). That keeps
 *  the plan and expiry date under human control, matching the way
 *  plan changes already work through `subscription_requests`.
 *
 *  Requires includes/sa_helpers.php (sa_query, sa_scalar, money …).
 */

require_once __DIR__ . '/sa_helpers.php';

/* ============================================================
   1. SCHEMA
   ============================================================ */

if (!function_exists('pay_columns')) {
    /**
     * Column names of a table, cached per request.
     * Returns [] when the table does not exist.
     *
     * The cache lives in $GLOBALS so pay_add_columns() can extend it after
     * an ALTER — otherwise a later read in the same request would still see
     * the pre-migration column list.
     */
    function pay_columns($conn, $table)
    {
        if (!isset($GLOBALS['__pay_col_cache'])) {
            $GLOBALS['__pay_col_cache'] = [];
        }
        $cache = &$GLOBALS['__pay_col_cache'];

        $table = preg_replace('/[^a-z0-9_]/i', '', (string) $table);
        if ($table === '' || !is_object($conn) || !method_exists($conn, 'query')) {
            return [];
        }
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $cols = [];
        $res = @$conn->query('SHOW COLUMNS FROM `' . $table . '`');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (isset($row['Field'])) {
                    $cols[] = $row['Field'];
                }
            }
            if (method_exists($res, 'close')) {
                $res->close();
            }
        }
        $cache[$table] = $cols;
        return $cols;
    }
}

if (!function_exists('pay_add_columns')) {
    /**
     * Add any missing columns to a table in a single ALTER.
     * MySQL 5.7 has no "ADD COLUMN IF NOT EXISTS", so we diff first.
     * $defs = ['column' => 'INT NULL DEFAULT 0', ...]
     */
    function pay_add_columns($conn, $table, array $defs)
    {
        $have = pay_columns($conn, $table);
        if (!$have) {
            return false; // table missing — created elsewhere
        }
        $add = [];
        foreach ($defs as $column => $definition) {
            if (!in_array($column, $have, true)) {
                $add[] = 'ADD COLUMN `' . $column . '` ' . $definition;
            }
        }
        if (!$add) {
            return true;
        }

        $sql = 'ALTER TABLE `' . $table . '` ' . implode(', ', $add);
        $ok = (bool) @$conn->query($sql);
        if ($ok) {
            $key = preg_replace('/[^a-z0-9_]/i', '', (string) $table);
            if (!isset($GLOBALS['__pay_col_cache'])) {
                $GLOBALS['__pay_col_cache'] = [];
            }
            $GLOBALS['__pay_col_cache'][$key] = array_merge(
                $GLOBALS['__pay_col_cache'][$key],
                array_keys($defs)
            );
            $GLOBALS['__pay_col_cache'][$key] = array_values(array_unique($GLOBALS['__pay_col_cache'][$key]));
        }
        return $ok;
    }
}

if (!function_exists('pay_ensure_schema')) {
    /**
     * Create/upgrade every billing table. Runs once per request and is
     * safe to call from any page that touches payments.
     */
    function pay_ensure_schema($conn)
    {
        static $done = false;
        if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
            return;
        }
        $done = true;

        /* ---- gateway configuration (super admin owns this) ---- */
        @$conn->query(
            "CREATE TABLE IF NOT EXISTS payment_gateways (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gateway_key VARCHAR(40) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                mode ENUM('test','live') NOT NULL DEFAULT 'test',
                public_key VARCHAR(255) NULL,
                secret_key VARCHAR(255) NULL,
                webhook_secret VARCHAR(255) NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'GHS',
                bank_name VARCHAR(140) NULL,
                account_name VARCHAR(140) NULL,
                account_number VARCHAR(80) NULL,
                instructions TEXT NULL,
                connection_status VARCHAR(20) NOT NULL DEFAULT 'unverified',
                connection_note VARCHAR(255) NULL,
                last_checked_at DATETIME NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_gateway_key (gateway_key),
                INDEX idx_gateway_enabled (is_enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        /* ---- invoices ---- */
        @$conn->query(
            "CREATE TABLE IF NOT EXISTS payment_invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_number VARCHAR(40) NOT NULL,
                tenant_id INT NOT NULL,
                plan_id INT NULL,
                purpose ENUM('new','renewal','upgrade','downgrade','addon') NOT NULL DEFAULT 'renewal',
                subject VARCHAR(190) NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(8) NOT NULL DEFAULT '',
                months INT NOT NULL DEFAULT 12,
                period_start DATE NULL,
                period_end DATE NULL,
                status ENUM('draft','open','processing','paid','overdue','cancelled','refunded') NOT NULL DEFAULT 'open',
                gateway_key VARCHAR(40) NULL,
                checkout_reference VARCHAR(120) NULL,
                due_date DATE NULL,
                paid_at DATETIME NULL,
                issued_by VARCHAR(120) NULL,
                confirmed_by VARCHAR(120) NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_invoice_number (invoice_number),
                INDEX idx_invoice_tenant (tenant_id),
                INDEX idx_invoice_status (status),
                INDEX idx_invoice_created (created_at),
                INDEX idx_invoice_reference (checkout_reference)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        /* ---- the money ledger ---- */
        // subscription_payments already existed for hand-recorded offline
        // payments; it becomes the single ledger for gateway money too.
        if (function_exists('sa_ensure_payments_schema')) {
            sa_ensure_payments_schema($conn);
        } elseif (!pay_columns($conn, 'subscription_payments')) {
            @$conn->query(
                "CREATE TABLE IF NOT EXISTS subscription_payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id INT NOT NULL,
                    receipt_number VARCHAR(32) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    payment_method VARCHAR(50) NOT NULL DEFAULT 'Bank Wire',
                    transaction_ref VARCHAR(100) NULL,
                    months_extended INT NOT NULL DEFAULT 0,
                    notes TEXT NULL,
                    recorded_by VARCHAR(100) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_tenant_pmt (tenant_id),
                    INDEX idx_receipt_num (receipt_number)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }
        pay_add_columns($conn, 'subscription_payments', [
            'invoice_id'        => 'INT NULL',
            'gateway_key'       => 'VARCHAR(40) NULL',
            'gateway_reference' => 'VARCHAR(120) NULL',
            'currency'          => 'VARCHAR(8) NOT NULL DEFAULT \'\'',
            'fee'               => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'channel'           => 'VARCHAR(40) NULL',
            'status'            => "VARCHAR(20) NOT NULL DEFAULT 'confirmed'",
            'source'            => "VARCHAR(20) NOT NULL DEFAULT 'offline'",
            'payer_name'        => 'VARCHAR(140) NULL',
            'payer_email'       => 'VARCHAR(160) NULL',
            'payer_phone'       => 'VARCHAR(40) NULL',
            'paid_at'           => 'DATETIME NULL',
            'verified_at'       => 'DATETIME NULL',
            'verified_by'       => 'VARCHAR(120) NULL',
            'reject_reason'     => 'VARCHAR(255) NULL',
        ]);

        /* ---- refunds & credit adjustments ---- */
        @$conn->query(
            "CREATE TABLE IF NOT EXISTS payment_refunds (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                payment_id INT NULL,
                invoice_id INT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(8) NOT NULL DEFAULT '',
                kind ENUM('refund','credit') NOT NULL DEFAULT 'refund',
                reason VARCHAR(255) NULL,
                status ENUM('pending','processed','rejected') NOT NULL DEFAULT 'pending',
                gateway_key VARCHAR(40) NULL,
                gateway_reference VARCHAR(120) NULL,
                requested_by VARCHAR(120) NULL,
                processed_by VARCHAR(120) NULL,
                processed_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_refund_tenant (tenant_id),
                INDEX idx_refund_payment (payment_id),
                INDEX idx_refund_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        /* ---- webhook / callback audit trail ---- */
        @$conn->query(
            "CREATE TABLE IF NOT EXISTS payment_events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                gateway_key VARCHAR(40) NULL,
                event_type VARCHAR(80) NULL,
                reference VARCHAR(120) NULL,
                invoice_id INT NULL,
                payment_id INT NULL,
                signature_valid TINYINT(1) NOT NULL DEFAULT 0,
                ip_address VARCHAR(45) NULL,
                payload MEDIUMTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_gateway (gateway_key),
                INDEX idx_event_reference (reference),
                INDEX idx_event_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        /* ---- seed the three integrations once ---- */
        $seeded = (int) sa_scalar($conn, "SELECT COUNT(*) FROM payment_gateways", 0, 'payment_gateways');
        if ($seeded === 0) {
            foreach (pay_gateway_catalog() as $key => $meta) {
                $name = pay_sql_escape($conn, $meta['label']);
                $note = pay_sql_escape($conn, $meta['summary']);
                $curr = pay_sql_escape($conn, sa_currency_code($conn));
                $order = (int) $meta['order'];
                @$conn->query(
                    "INSERT INTO payment_gateways
                        (gateway_key, display_name, is_enabled, mode, currency, instructions, connection_status, connection_note, sort_order)
                     VALUES
                        ('" . pay_sql_escape($conn, $key) . "', '" . $name . "', 0, 'test',
                         '" . $curr . "', '" . $note . "', 'unverified', 'Not configured yet.', " . $order . ")"
                );
            }
        }
    }
}

if (!function_exists('pay_sql_escape')) {
    /** Escape a value for the few places we build SQL directly. */
    function pay_sql_escape($conn, $value)
    {
        if (is_object($conn) && method_exists($conn, 'real_escape_string')) {
            return $conn->real_escape_string((string) $value);
        }
        return addslashes((string) $value);
    }
}

/* ============================================================
   2. GATEWAY CATALOGUE
   ============================================================ */

if (!function_exists('pay_gateway_catalog')) {
    /**
     * The integrations the platform ships with. Adding an entry here
     * plus a driver in pay_driver_*() is all a new gateway needs.
     */
    function pay_gateway_catalog()
    {
        return [
            'paystack' => [
                'label'      => 'Paystack',
                'driver'     => 'paystack',
                'summary'    => 'Cards, mobile money, bank transfer and USSD across Ghana, Nigeria, Kenya and South Africa.',
                'region'     => 'Africa',
                'methods'    => 'Card · Mobile money · Bank · USSD',
                'currencies' => ['GHS', 'NGN', 'ZAR', 'KES', 'USD', 'XOF'],
                'docs'       => 'https://paystack.com/docs/api/',
                'keys'       => ['public_key', 'secret_key', 'webhook_secret'],
                'webhook_help' => 'Paystack signs each webhook with HMAC SHA512 using your secret key and sends it in the x-paystack-signature header.',
                'order'      => 1,
            ],
            'flutterwave' => [
                'label'      => 'Flutterwave',
                'driver'     => 'flutterwave',
                'summary'    => 'Cards, mobile money, bank accounts and Barion wallets in 30+ African markets.',
                'region'     => 'Africa',
                'methods'    => 'Card · Mobile money · Bank · Wallet',
                'currencies' => ['GHS', 'NGN', 'KES', 'ZAR', 'UGX', 'TZS', 'USD', 'EUR', 'GBP'],
                'docs'       => 'https://developer.flutterwave.com/docs',
                'keys'       => ['public_key', 'secret_key', 'webhook_secret'],
                'webhook_help' => 'Flutterwave echoes your “secret hash” back in the verif-hash header on every webhook.',
                'order'      => 2,
            ],
            'bank_transfer' => [
                'label'      => 'Bank transfer / Manual',
                'driver'     => 'manual',
                'summary'    => 'Publish bank or mobile-money details, then confirm the transfer yourself before the plan activates.',
                'region'     => 'Anywhere',
                'methods'    => 'Bank transfer · Cash · Cheque',
                'currencies' => [],
                'docs'       => '',
                'keys'       => ['bank_name', 'account_name', 'account_number', 'instructions'],
                'webhook_help' => 'Manual payments have no webhook — you confirm them from the financial centre.',
                'order'      => 3,
            ],
        ];
    }
}

if (!function_exists('pay_gateway_label')) {
    /** Human label for a gateway key, safe for any unknown key. */
    function pay_gateway_label($key)
    {
        $catalog = pay_gateway_catalog();
        $key = (string) $key;
        if (isset($catalog[$key])) {
            return $catalog[$key]['label'];
        }
        return $key === '' ? 'Unassigned' : ucfirst(str_replace('_', ' ', $key));
    }
}

if (!function_exists('pay_gateway_driver')) {
    /** Driver name ('paystack' | 'flutterwave' | 'manual') for a gateway key. */
    function pay_gateway_driver($key)
    {
        $catalog = pay_gateway_catalog();
        $key = (string) $key;
        return isset($catalog[$key]) ? $catalog[$key]['driver'] : 'manual';
    }
}

if (!function_exists('pay_is_api_gateway')) {
    /** True when the gateway can take a payment online without a human. */
    function pay_is_api_gateway($key)
    {
        return in_array(pay_gateway_driver($key), ['paystack', 'flutterwave'], true);
    }
}

/* ============================================================
   3. CONFIGURATION
   ============================================================ */

if (!function_exists('pay_gateways')) {
    /**
     * Every configured gateway, ordered for display, merged with the
     * catalogue defaults so a half-migrated row still renders.
     */
    function pay_gateways($conn, $enabled_only = false)
    {
        pay_ensure_schema($conn);
        $where = $enabled_only ? ' WHERE is_enabled = 1' : '';
        $rows = sa_query($conn, "SELECT * FROM payment_gateways" . $where . " ORDER BY sort_order ASC, id ASC", 'payment_gateways');

        $catalog = pay_gateway_catalog();
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['gateway_key'];
            $meta = isset($catalog[$key]) ? $catalog[$key] : [];
            $out[$key] = array_merge([
                'gateway_key'  => $key,
                'display_name' => isset($meta['label']) ? $meta['label'] : $key,
                'driver'       => isset($meta['driver']) ? $meta['driver'] : 'manual',
                'is_enabled'   => 0,
                'mode'         => 'test',
                'currency'     => sa_currency_code($conn),
                'public_key'   => '',
                'secret_key'   => '',
                'webhook_secret' => '',
                'bank_name'    => '',
                'account_name' => '',
                'account_number' => '',
                'instructions' => '',
                'connection_status' => 'unverified',
                'connection_note' => '',
                'last_checked_at' => null,
            ], $row);
            $out[$key]['driver']  = isset($meta['driver']) ? $meta['driver'] : 'manual';
            $out[$key]['methods'] = isset($meta['methods']) ? $meta['methods'] : '';
            $out[$key]['currencies'] = isset($meta['currencies']) ? $meta['currencies'] : [];
            $out[$key]['docs']    = isset($meta['docs']) ? $meta['docs'] : '';
            $out[$key]['ready']   = pay_gateway_ready($out[$key]);
        }
        return $out;
    }
}

if (!function_exists('pay_gateway')) {
    /** One gateway row (with catalogue meta), or null. */
    function pay_gateway($conn, $key)
    {
        $all = pay_gateways($conn);
        $key = (string) $key;
        return isset($all[$key]) ? $all[$key] : null;
    }
}

if (!function_exists('pay_gateway_ready')) {
    /**
     * Is the gateway usable as configured? Returns true/false.
     * A manual gateway only needs the details a tenant must read.
     */
    function pay_gateway_ready($gw)
    {
        if (!$gw || empty($gw['is_enabled'])) {
            return false;
        }
        if ($gw['driver'] === 'manual') {
            return trim((string) $gw['account_number']) !== '' || trim((string) $gw['instructions']) !== '';
        }
        return trim((string) $gw['secret_key']) !== '';
    }
}

if (!function_exists('pay_gateway_blockers')) {
    /** Human list of what still stops a gateway from taking money. */
    function pay_gateway_blockers($gw)
    {
        $blockers = [];
        if (!$gw) {
            return ['Not installed.'];
        }
        if (empty($gw['is_enabled'])) {
            $blockers[] = 'Integration is switched off.';
        }
        if ($gw['driver'] === 'manual') {
            if (trim((string) $gw['account_number']) === '' && trim((string) $gw['instructions']) === '') {
                $blockers[] = 'Add the account number or the payment instructions tenants should follow.';
            }
            return $blockers;
        }
        if (trim((string) $gw['secret_key']) === '') {
            $blockers[] = 'Secret key is missing.';
        }
        if (trim((string) $gw['webhook_secret']) === '' && $gw['driver'] === 'flutterwave') {
            $blockers[] = 'Webhook secret hash is missing — payments will not be verified automatically.';
        }
        return $blockers;
    }
}

if (!function_exists('pay_gateway_save')) {
    /**
     * Persist the editable fields of one gateway.
     * $data keys not present are left untouched; pass '' to clear.
     */
    function pay_gateway_save($conn, $key, array $data)
    {
        pay_ensure_schema($conn);
        $key = preg_replace('/[^a-z_]/', '', strtolower((string) $key));
        if ($key === '') {
            return false;
        }

        $existing = sa_one($conn, "SELECT * FROM payment_gateways WHERE gateway_key = '" . pay_sql_escape($conn, $key) . "' LIMIT 1", 'payment_gateways');

        $catalog  = pay_gateway_catalog();
        $meta     = isset($catalog[$key]) ? $catalog[$key] : [];
        $editable = [
            'display_name', 'is_enabled', 'mode', 'public_key', 'secret_key', 'webhook_secret',
            'currency', 'bank_name', 'account_name', 'account_number', 'instructions', 'sort_order',
        ];

        $sets = [];
        foreach ($editable as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($field === 'is_enabled') {
                $sets[] = "is_enabled = " . (empty($value) ? 0 : 1);
            } elseif ($field === 'sort_order') {
                $sets[] = 'sort_order = ' . (int) $value;
            } elseif ($field === 'mode') {
                $sets[] = "mode = '" . ($value === 'live' ? 'live' : 'test') . "'";
            } else {
                $sets[] = $field . " = '" . pay_sql_escape($conn, (string) $value) . "'";
            }
        }

        if ($existing) {
            if (!$sets) {
                return true;
            }
            return (bool) @$conn->query(
                "UPDATE payment_gateways SET " . implode(', ', $sets) . ", updated_at = NOW()
                  WHERE gateway_key = '" . pay_sql_escape($conn, $key) . "'"
            );
        }

        $label = isset($meta['label']) ? $meta['label'] : $key;
        return (bool) @$conn->query(
            "INSERT INTO payment_gateways (gateway_key, display_name, is_enabled, mode, currency, connection_status, connection_note, sort_order)
             VALUES ('" . pay_sql_escape($conn, $key) . "', '" . pay_sql_escape($conn, $label) . "', 0, 'test',
                     '" . pay_sql_escape($conn, sa_currency_code($conn)) . "', 'unverified', 'Not configured yet.',
                     " . (isset($meta['order']) ? (int) $meta['order'] : 9) . ")"
        );
    }
}

if (!function_exists('pay_gateway_webhook_url')) {
    /**
     * The absolute webhook URL to paste into the gateway dashboard.
     * Falls back to the configured public base URL when the request is
     * coming from a CLI / test harness.
     */
    function pay_gateway_webhook_url($conn, $key)
    {
        return pay_public_base_url($conn) . '/api/payment_webhook.php?gateway=' . rawurlencode($key);
    }
}

if (!function_exists('pay_public_base_url')) {
    /** Best-effort absolute base URL of this installation (no trailing slash). */
    function pay_public_base_url($conn = null)
    {
        $configured = $conn ? sa_setting($conn, 'public_base_url', '') : '';
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if ($host === '') {
            return 'https://your-domain.example';
        }
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';

        // dirname of the running script, trimmed back to the app root
        $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $dir    = str_replace('\\', '/', dirname($script));
        foreach (['/superadmin', '/admin', '/api'] as $panel) {
            if (substr($dir, -strlen($panel)) === $panel) {
                $dir = substr($dir, 0, -strlen($panel));
                break;
            }
        }
        return rtrim($scheme . '://' . $host . rtrim($dir, '/'), '/');
    }
}

/* ============================================================
   4. HTTP
   ============================================================ */

if (!function_exists('pay_http_json')) {
    /**
     * Minimal JSON HTTP client. Uses cURL when the extension is
     * available and falls back to the stream wrapper otherwise, so the
     * integrations work on shared hosting either way.
     *
     * @return array ['ok' => bool, 'status' => int, 'data' => mixed, 'error' => string, 'raw' => string]
     */
    function pay_http_json($method, $url, $payload = null, array $headers = [], $timeout = 25)
    {
        $method  = strtoupper($method);
        $body    = $payload === null ? null : (is_string($payload) ? $payload : json_encode($payload));
        $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        $out     = ['ok' => false, 'status' => 0, 'data' => null, 'error' => '', 'raw' => ''];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $opts);
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            $out['status'] = $status;
            $out['raw']    = is_string($raw) ? $raw : '';
            if ($raw === false) {
                $out['error'] = $err !== '' ? $err : 'The gateway could not be reached.';
                return $out;
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method'        => $method,
                    'header'        => implode("\r\n", $headers),
                    'content'       => $body === null ? '' : $body,
                    'timeout'       => $timeout,
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $raw = @file_get_contents($url, false, $context);
            $status = 0;
            if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
            $out['status'] = $status;
            $out['raw']    = is_string($raw) ? $raw : '';
            if ($raw === false) {
                $out['error'] = 'The gateway could not be reached from this server.';
                return $out;
            }
        }

        $decoded = json_decode($out['raw'], true);
        $out['data'] = is_array($decoded) ? $decoded : null;

        if ($out['status'] < 200 || $out['status'] >= 300) {
            $out['error'] = pay_http_error_message($out['data'], $out['status']);
            return $out;
        }
        if ($out['data'] === null) {
            $out['error'] = 'The gateway returned an unexpected response.';
            return $out;
        }
        $out['ok'] = true;
        return $out;
    }
}

if (!function_exists('pay_http_error_message')) {
    /** Pull a readable message out of a gateway error body. */
    function pay_http_error_message($data, $status)
    {
        if (is_array($data)) {
            if (!empty($data['message']) && is_string($data['message'])) {
                return $data['message'];
            }
            if (!empty($data['error']) && is_string($data['error'])) {
                return $data['error'];
            }
            if (!empty($data['data']['message']) && is_string($data['data']['message'])) {
                return $data['data']['message'];
            }
        }
        return $status > 0
            ? 'The gateway rejected the request (HTTP ' . $status . ').'
            : 'The gateway could not be reached.';
    }
}

/* ============================================================
   5. GATEWAY DRIVERS
   ============================================================ */

if (!function_exists('pay_driver_checkout')) {
    /**
     * Ask the gateway for a hosted checkout URL.
     *
     * @return array ['ok' => bool, 'url' => '', 'reference' => '', 'message' => '']
     */
    function pay_driver_checkout($gw, array $invoice, array $tenant, $callback_url)
    {
        $fail = ['ok' => false, 'url' => '', 'reference' => '', 'message' => ''];
        if (!$gw) {
            $fail['message'] = 'That payment method is not available.';
            return $fail;
        }

        $reference = 'OPT-' . strtoupper(bin2hex(random_bytes(4))) . '-' . (int) $invoice['id'];
        $amount    = (float) $invoice['total'];
        $currency  = $gw['currency'] !== '' ? strtoupper($gw['currency']) : strtoupper(sa_currency_code(null));
        $email     = trim((string) $tenant['email']);
        $name      = trim((string) $tenant['company_name']);
        $subject   = (string) ($invoice['subject'] !== '' ? $invoice['subject'] : 'Subscription payment');

        if ($amount <= 0) {
            $fail['message'] = 'This invoice has nothing left to pay.';
            return $fail;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fail['message'] = 'Add a billing email to the workspace before paying online.';
            return $fail;
        }

        if ($gw['driver'] === 'paystack') {
            $res = pay_http_json('POST', 'https://api.paystack.co/transaction/initialize', [
                'email'        => $email,
                'amount'       => (int) round($amount * 100),   // minor units
                'currency'     => $currency,
                'reference'    => $reference,
                'callback_url' => $callback_url,
                'metadata'     => [
                    'invoice_number' => (string) $invoice['invoice_number'],
                    'tenant'         => $name,
                    'custom_fields'  => [[
                        'display_name'  => 'Invoice',
                        'variable_name' => 'invoice_number',
                        'value'         => (string) $invoice['invoice_number'],
                    ]],
                ],
            ], ['Authorization: Bearer ' . $gw['secret_key']]);

            if (!$res['ok']) {
                $fail['message'] = $res['error'];
                return $fail;
            }
            $url = isset($res['data']['data']['authorization_url']) ? $res['data']['data']['authorization_url'] : '';
            if ($url === '') {
                $fail['message'] = 'Paystack did not return a checkout link.';
                return $fail;
            }
            return ['ok' => true, 'url' => $url, 'reference' => $reference, 'message' => ''];
        }

        if ($gw['driver'] === 'flutterwave') {
            $res = pay_http_json('POST', 'https://api.flutterwave.com/v3/payments', [
                'tx_ref'        => $reference,
                'amount'        => round($amount, 2),
                'currency'      => $currency,
                'redirect_url'  => $callback_url,
                'payment_options' => 'card,mobilemoney,banktransfer,ussd',
                'customer'      => ['email' => $email, 'name' => $name],
                'customizations' => [
                    'title'       => mb_substr($subject, 0, 60),
                    'description' => 'Invoice ' . $invoice['invoice_number'],
                ],
                'meta'          => ['invoice_number' => (string) $invoice['invoice_number']],
            ], ['Authorization: Bearer ' . $gw['secret_key']]);

            if (!$res['ok']) {
                $fail['message'] = $res['error'];
                return $fail;
            }
            $url = isset($res['data']['data']['link']) ? $res['data']['data']['link'] : '';
            if ($url === '') {
                $fail['message'] = 'Flutterwave did not return a checkout link.';
                return $fail;
            }
            return ['ok' => true, 'url' => $url, 'reference' => $reference, 'message' => ''];
        }

        $fail['message'] = 'This payment method is settled manually — follow the instructions shown.';
        return $fail;
    }
}

if (!function_exists('pay_driver_verify')) {
    /**
     * Confirm with the gateway that a reference really was paid, and
     * return the details we store on the ledger.
     *
     * @return array ['ok','paid','amount','currency','channel','payer_name','payer_email','fee','message','raw']
     */
    function pay_driver_verify($gw, $reference)
    {
        $out = [
            'ok' => false, 'paid' => false, 'amount' => 0.0, 'currency' => '',
            'channel' => '', 'payer_name' => '', 'payer_email' => '', 'fee' => 0.0,
            'message' => '', 'raw' => [],
        ];

        if (!$gw || $reference === '') {
            $out['message'] = 'Nothing to verify.';
            return $out;
        }

        if ($gw['driver'] === 'paystack') {
            $res = pay_http_json('GET', 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference), null,
                ['Authorization: Bearer ' . $gw['secret_key']]);
            if (!$res['ok']) {
                $out['message'] = $res['error'];
                $out['raw'] = is_array($res['data']) ? $res['data'] : [];
                return $out;
            }
            $d = isset($res['data']['data']) && is_array($res['data']['data']) ? $res['data']['data'] : [];
            $out['raw']     = $d;
            $out['ok']      = true;
            $out['paid']    = isset($d['status']) && $d['status'] === 'success';
            $out['amount']  = isset($d['amount']) ? ((float) $d['amount'] / 100) : 0.0;
            $out['currency'] = isset($d['currency']) ? strtoupper((string) $d['currency']) : '';
            $out['channel'] = isset($d['channel']) ? (string) $d['channel'] : '';
            $out['fee']     = isset($d['fees']) ? ((float) $d['fees'] / 100) : 0.0;
            if (isset($d['customer']) && is_array($d['customer'])) {
                $out['payer_email'] = isset($d['customer']['email']) ? (string) $d['customer']['email'] : '';
                $out['payer_name']  = isset($d['customer']['first_name'])
                    ? trim($d['customer']['first_name'] . ' ' . (isset($d['customer']['last_name']) ? $d['customer']['last_name'] : ''))
                    : '';
            }
            $out['message'] = $out['paid'] ? 'Payment confirmed by Paystack.' : 'Paystack reports this transaction as ' . (isset($d['status']) ? $d['status'] : 'unpaid') . '.';
            return $out;
        }

        if ($gw['driver'] === 'flutterwave') {
            // Verify by reference keeps working no matter which callback style was used.
            $res = pay_http_json('GET', 'https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference), null,
                ['Authorization: Bearer ' . $gw['secret_key']]);
            if (!$res['ok']) {
                $out['message'] = $res['error'];
                $out['raw'] = is_array($res['data']) ? $res['data'] : [];
                return $out;
            }
            $d = isset($res['data']['data']) && is_array($res['data']['data']) ? $res['data']['data'] : [];
            $out['raw']      = $d;
            $out['ok']       = true;
            $out['paid']     = isset($d['status']) && $d['status'] === 'successful';
            $out['amount']   = isset($d['amount']) ? (float) $d['amount'] : 0.0;
            $out['currency'] = isset($d['currency']) ? strtoupper((string) $d['currency']) : '';
            $out['channel']  = isset($d['payment_type']) ? (string) $d['payment_type'] : '';
            $out['fee']      = isset($d['app_fee']) ? (float) $d['app_fee'] : 0.0;
            if (isset($d['customer']) && is_array($d['customer'])) {
                $out['payer_email'] = isset($d['customer']['email']) ? (string) $d['customer']['email'] : '';
                $out['payer_name']  = isset($d['customer']['name']) ? (string) $d['customer']['name'] : '';
            }
            $out['message'] = $out['paid'] ? 'Payment confirmed by Flutterwave.' : 'Flutterwave reports this transaction as ' . (isset($d['status']) ? $d['status'] : 'unpaid') . '.';
            return $out;
        }

        $out['message'] = 'Manual payments are confirmed by the platform owner.';
        return $out;
    }
}

if (!function_exists('pay_driver_test')) {
    /**
     * "Test connection" — a cheap authenticated call that proves the
     * keys work. Nothing is charged.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    function pay_driver_test($gw)
    {
        if (!$gw) {
            return ['ok' => false, 'message' => 'Unknown integration.'];
        }

        if ($gw['driver'] === 'manual') {
            $blockers = pay_gateway_blockers(array_merge($gw, ['is_enabled' => 1]));
            return $blockers
                ? ['ok' => false, 'message' => implode(' ', $blockers)]
                : ['ok' => true, 'message' => 'Bank transfer details are ready to publish to tenants.'];
        }

        if (trim((string) $gw['secret_key']) === '') {
            return ['ok' => false, 'message' => 'Enter the secret key first, then test again.'];
        }

        if ($gw['driver'] === 'paystack') {
            $res = pay_http_json('GET', 'https://api.paystack.co/balance', null,
                ['Authorization: Bearer ' . $gw['secret_key']], 15);
            if ($res['ok']) {
                $cur = $gw['currency'] !== '' ? strtoupper($gw['currency']) : strtoupper(sa_currency_code(null));
                $amount = 0.0;
                foreach ((array) (isset($res['data']['data']) ? $res['data']['data'] : []) as $entry) {
                    if (isset($entry['currency']) && strtoupper($entry['currency']) === $cur) {
                        $amount = ((float) $entry['balance']) / 100;
                    }
                }
                return ['ok' => true, 'message' => ucfirst($gw['mode']) . ' keys work — available balance '
                    . sa_money($amount) . ' ' . $cur . '.'];
            }
            return ['ok' => false, 'message' => $res['error'] . ' Check that you pasted the ' . $gw['mode'] . ' secret key.'];
        }

        if ($gw['driver'] === 'flutterwave') {
            $res = pay_http_json('GET', 'https://api.flutterwave.com/v3/balances', null,
                ['Authorization: Bearer ' . $gw['secret_key']], 15);
            if ($res['ok']) {
                $cur = $gw['currency'] !== '' ? strtoupper($gw['currency']) : strtoupper(sa_currency_code(null));
                $amount = 0.0;
                foreach ((array) (isset($res['data']['data']) ? $res['data']['data'] : []) as $entry) {
                    if (isset($entry['currency']) && strtoupper($entry['currency']) === $cur) {
                        $amount = (float) $entry['available_balance'];
                    }
                }
                return ['ok' => true, 'message' => ucfirst($gw['mode']) . ' keys work — available balance '
                    . sa_money($amount) . ' ' . $cur . '.'];
            }
            return ['ok' => false, 'message' => $res['error'] . ' Check that you pasted the ' . $gw['mode'] . ' secret key.'];
        }

        return ['ok' => false, 'message' => 'No connection test available for this integration.'];
    }
}

if (!function_exists('pay_webhook_signature_ok')) {
    /**
     * Validate an incoming webhook.
     *
     * Paystack : x-paystack-signature = HMAC-SHA512(raw body, secret key)
     * Flutterwave: verif-hash header must equal the configured secret hash
     *
     * @param array $headers lower-cased header name => value
     */
    function pay_webhook_signature_ok($gw, $raw_body, array $headers)
    {
        if (!$gw) {
            return false;
        }
        if ($gw['driver'] === 'paystack') {
            $sent = isset($headers['x-paystack-signature']) ? $headers['x-paystack-signature'] : '';
            if ($sent === '' || $gw['secret_key'] === '') {
                return false;
            }
            $expected = hash_hmac('sha512', $raw_body, $gw['secret_key']);
            return hash_equals($expected, strtolower($sent));
        }
        if ($gw['driver'] === 'flutterwave') {
            $sent = isset($headers['verif-hash']) ? $headers['verif-hash'] : '';
            $expected = (string) $gw['webhook_secret'];
            if ($sent === '' || $expected === '') {
                return false;
            }
            return hash_equals($expected, $sent);
        }
        return false;
    }
}

/* ============================================================
   6. INVOICES
   ============================================================ */

if (!function_exists('pay_next_invoice_number')) {
    /** INV-2026-0007, based on the highest invoice id so far. */
    function pay_next_invoice_number($conn)
    {
        $next = (int) sa_scalar($conn, "SELECT COALESCE(MAX(id), 0) FROM payment_invoices", 0, 'payment_invoices') + 1;
        return 'INV-' . date('Y') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('pay_invoice_create')) {
    /**
     * Issue an invoice for a tenant.
     *
     * Required: tenant_id. Everything else has a sensible default drawn
     * from the tenant's current plan.
     *
     * @return array ['ok' => bool, 'id' => int, 'invoice_number' => '', 'message' => '']
     */
    function pay_invoice_create($conn, array $data)
    {
        pay_ensure_schema($conn);

        $tenant_id = (int) (isset($data['tenant_id']) ? $data['tenant_id'] : 0);
        if (!$tenant_id) {
            return ['ok' => false, 'id' => 0, 'invoice_number' => '', 'message' => 'An invoice needs a workspace.'];
        }

        $tenant = sa_one($conn, "SELECT * FROM tenants WHERE id = " . $tenant_id . " LIMIT 1", 'tenants');
        if (!$tenant) {
            return ['ok' => false, 'id' => 0, 'invoice_number' => '', 'message' => 'That workspace no longer exists.'];
        }

        $plan_id = isset($data['plan_id']) ? (int) $data['plan_id'] : (int) $tenant['plan_id'];
        $plan    = $plan_id
            ? sa_one($conn, "SELECT * FROM subscription_plans WHERE id = " . $plan_id . " LIMIT 1", 'subscription_plans')
            : [];

        $months = isset($data['months']) ? max(1, min(36, (int) $data['months'])) : 12;
        $amount = isset($data['amount'])
            ? (float) $data['amount']
            : (float) (isset($plan['price']) ? $plan['price'] : $tenant['subscription_price']);
        if ($months > 1 && !isset($data['amount'])) {
            $amount = $amount * $months;
        }

        $discount = isset($data['discount']) ? max(0, (float) $data['discount']) : 0.0;
        $tax      = isset($data['tax']) ? max(0, (float) $data['tax']) : 0.0;
        $total    = max(0, $amount - $discount + $tax);

        $purpose = isset($data['purpose']) ? (string) $data['purpose'] : 'renewal';
        if (!in_array($purpose, ['new', 'renewal', 'upgrade', 'downgrade', 'addon'], true)) {
            $purpose = 'renewal';
        }

        $currency  = isset($data['currency']) && $data['currency'] !== '' ? strtoupper((string) $data['currency']) : strtoupper(sa_currency_code($conn));
        $due_date  = isset($data['due_date']) && $data['due_date'] !== '' ? (string) $data['due_date'] : date('Y-m-d', strtotime('+14 days'));
        $subject   = isset($data['subject']) && $data['subject'] !== ''
            ? (string) $data['subject']
            : (isset($plan['plan_name']) && $plan['plan_name'] !== '' ? $plan['plan_name'] . ' plan — ' . $months . ' month' . ($months === 1 ? '' : 's') : 'Subscription payment');
        $number    = pay_next_invoice_number($conn);
        $status    = (isset($data['status']) && in_array($data['status'], ['draft', 'open'], true)) ? (string) $data['status'] : 'open';
        $notes     = isset($data['notes']) ? (string) $data['notes'] : '';
        $issued_by = isset($data['issued_by']) ? (string) $data['issued_by'] : '';

        $stmt = $conn->prepare(
            "INSERT INTO payment_invoices
                (invoice_number, tenant_id, plan_id, purpose, subject, amount, discount, tax, total, currency,
                 months, status, due_date, issued_by, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) {
            return ['ok' => false, 'id' => 0, 'invoice_number' => '', 'message' => 'Could not create the invoice.'];
        }
        $stmt->bind_param(
            'siissddddsissss',
            $number, $tenant_id, $plan_id, $purpose, $subject, $amount, $discount, $tax, $total, $currency,
            $months, $status, $due_date, $issued_by, $notes
        );
        $ok = $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return [
            'ok' => (bool) $ok,
            'id' => $id,
            'invoice_number' => $number,
            'message' => $ok ? 'Invoice ' . $number . ' issued.' : 'Could not create the invoice.',
        ];
    }
}

if (!function_exists('pay_invoice')) {
    /** One invoice joined with the tenant and plan, or []. */
    function pay_invoice($conn, $id)
    {
        return sa_one(
            $conn,
            "SELECT i.*, t.company_name, t.email AS tenant_email, t.phone AS tenant_phone,
                    t.subscription_status, t.subscription_end_date, t.public_id AS tenant_public_id,
                    p.plan_name
               FROM payment_invoices i
               JOIN tenants t ON t.id = i.tenant_id
               LEFT JOIN subscription_plans p ON p.id = i.plan_id
              WHERE i.id = " . (int) $id . " LIMIT 1",
            ['payment_invoices', 'tenants']
        );
    }
}

if (!function_exists('pay_invoice_open_for_tenant')) {
    /** The tenant's oldest invoice still waiting for money, or []. */
    function pay_invoice_open_for_tenant($conn, $tenant_id)
    {
        return sa_one(
            $conn,
            "SELECT * FROM payment_invoices
              WHERE tenant_id = " . (int) $tenant_id . "
                AND status IN ('open','overdue','processing')
              ORDER BY (status = 'processing') DESC, due_date ASC, id ASC LIMIT 1",
            ['payment_invoices']
        );
    }
}

if (!function_exists('pay_invoice_find_by_reference')) {
    /** Invoice matching a gateway reference (callback / webhook lookup). */
    function pay_invoice_find_by_reference($conn, $reference)
    {
        return sa_one(
            $conn,
            "SELECT * FROM payment_invoices
              WHERE checkout_reference = '" . pay_sql_escape($conn, $reference) . "' LIMIT 1",
            ['payment_invoices']
        );
    }
}

if (!function_exists('pay_invoice_set_status')) {
    /** Move an invoice to a new status, optionally stamping extra columns. */
    function pay_invoice_set_status($conn, $invoice_id, $status, array $extra = [])
    {
        $allowed = ['draft', 'open', 'processing', 'paid', 'overdue', 'cancelled', 'refunded'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $sets = ["status = '" . pay_sql_escape($conn, $status) . "'"];
        foreach ($extra as $column => $value) {
            if (!preg_match('/^[a-z_]+$/', (string) $column)) {
                continue;
            }
            $sets[] = $column . " = '" . pay_sql_escape($conn, (string) $value) . "'";
        }
        return (bool) @$conn->query(
            "UPDATE payment_invoices SET " . implode(', ', $sets) . ", updated_at = NOW()
              WHERE id = " . (int) $invoice_id
        );
    }
}

if (!function_exists('pay_invoice_apply')) {
    /**
     * Apply a confirmed invoice to the workspace: set the plan, move the
     * expiry forward by the invoiced months and make it active.
     *
     * Only ever called after the money is confirmed, and idempotent —
     * an invoice that already has `paid_at` is not applied twice.
     */
    function pay_invoice_apply($conn, $invoice, $actor = '', $force = false)
    {
        $invoice = is_array($invoice) ? $invoice : pay_invoice($conn, (int) $invoice);
        if (!$invoice) {
            return false;
        }
        if (!empty($invoice['paid_at']) && !$force) {
            return true; // already applied
        }

        $tenant_id = (int) $invoice['tenant_id'];
        $months    = max(1, (int) $invoice['months']);
        $plan_id   = (int) $invoice['plan_id'];

        $plan = $plan_id
            ? sa_one($conn, "SELECT * FROM subscription_plans WHERE id = " . $plan_id . " LIMIT 1", 'subscription_plans')
            : [];

        $sets = [
            "subscription_status = 'active'",
            "subscription_end_date = DATE_ADD(GREATEST(COALESCE(subscription_end_date, CURDATE()), CURDATE()), INTERVAL " . $months . " MONTH)",
            "subscription_start_date = COALESCE(subscription_start_date, CURDATE())",
        ];
        if ($plan) {
            $price = (float) $plan['price'];
            $sets[] = 'plan_id = ' . (int) $plan['id'];
            $sets[] = 'subscription_price = ' . number_format($price, 2, '.', '');
        }

        $ok = (bool) @$conn->query("UPDATE tenants SET " . implode(', ', $sets) . " WHERE id = " . $tenant_id);
        if ($ok) {
            pay_invoice_set_status($conn, (int) $invoice['id'], 'paid', [
                'paid_at'      => date('Y-m-d H:i:s'),
                'confirmed_by' => $actor !== '' ? $actor : 'Super Admin',
            ]);
            $end = sa_scalar($conn, "SELECT subscription_end_date FROM tenants WHERE id = " . $tenant_id, '', 'tenants');
            $note = 'Subscription activated from invoice ' . $invoice['invoice_number']
                . ($end ? ' — active through ' . $end : '') . '.';
            pay_log_event($conn, [
                'gateway_key' => (string) $invoice['gateway_key'],
                'event_type'  => 'invoice.applied',
                'reference'   => (string) $invoice['checkout_reference'],
                'invoice_id'  => (int) $invoice['id'],
                'payload'     => $note,
            ]);
        }
        return $ok;
    }
}

/* ============================================================
   7. LEDGER
   ============================================================ */

if (!function_exists('pay_next_receipt_number')) {
    /** RCP-2026-0007, derived from the ledger's high-water mark. */
    function pay_next_receipt_number($conn)
    {
        $next = (int) sa_scalar($conn, "SELECT COALESCE(MAX(id), 0) FROM subscription_payments", 0, 'subscription_payments') + 1;
        return 'RCP-' . date('Y') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('pay_record')) {
    /**
     * Write one row into the money ledger.
     *
     * @param array $data tenant_id, amount, status, payment_method/gateway_key,
     *                    invoice_id, months_extended, references, payer details…
     * @return int the new payment id (0 on failure)
     */
    function pay_record($conn, array $data)
    {
        pay_ensure_schema($conn);

        $tenant_id = (int) (isset($data['tenant_id']) ? $data['tenant_id'] : 0);
        $amount    = (float) (isset($data['amount']) ? $data['amount'] : 0);
        if (!$tenant_id || $amount <= 0) {
            return 0;
        }

        $status = isset($data['status']) ? (string) $data['status'] : 'pending';
        if (!in_array($status, ['pending', 'confirmed', 'failed', 'refunded'], true)) {
            $status = 'pending';
        }

        $gateway_key = isset($data['gateway_key']) ? (string) $data['gateway_key'] : '';
        $method      = isset($data['payment_method']) && $data['payment_method'] !== ''
            ? (string) $data['payment_method']
            : pay_gateway_label($gateway_key);

        $receipt = isset($data['receipt_number']) && $data['receipt_number'] !== ''
            ? (string) $data['receipt_number']
            : pay_next_receipt_number($conn);

        $source      = isset($data['source']) ? (string) $data['source'] : (pay_is_api_gateway($gateway_key) ? 'gateway' : 'offline');
        $invoice_id  = isset($data['invoice_id']) ? (int) $data['invoice_id'] : 0;
        $reference   = isset($data['gateway_reference']) ? (string) $data['gateway_reference'] : (isset($data['transaction_ref']) ? (string) $data['transaction_ref'] : '');
        $months      = isset($data['months_extended']) ? max(0, (int) $data['months_extended']) : 0;
        $currency    = isset($data['currency']) ? strtoupper((string) $data['currency']) : strtoupper(sa_currency_code($conn));
        $fee         = isset($data['fee']) ? (float) $data['fee'] : 0.0;
        $channel     = isset($data['channel']) ? (string) $data['channel'] : '';
        $payer_name  = isset($data['payer_name']) ? (string) $data['payer_name'] : '';
        $payer_email = isset($data['payer_email']) ? (string) $data['payer_email'] : '';
        $payer_phone = isset($data['payer_phone']) ? (string) $data['payer_phone'] : '';
        $notes       = isset($data['notes']) ? (string) $data['notes'] : '';
        $actor       = isset($data['recorded_by']) ? (string) $data['recorded_by'] : '';
        $paid_at     = $status === 'confirmed'
            ? (isset($data['paid_at']) && $data['paid_at'] !== '' ? (string) $data['paid_at'] : date('Y-m-d H:i:s'))
            : (isset($data['paid_at']) && $data['paid_at'] !== '' ? (string) $data['paid_at'] : null);

        $stmt = $conn->prepare(
            "INSERT INTO subscription_payments
                (tenant_id, invoice_id, receipt_number, amount, currency, fee, payment_method, gateway_key,
                 gateway_reference, transaction_ref, channel, status, source, payer_name, payer_email, payer_phone,
                 months_extended, notes, recorded_by, paid_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param(
            'iisdsdssssssssssisss',
            $tenant_id, $invoice_id, $receipt, $amount, $currency, $fee, $method, $gateway_key,
            $reference, $reference, $channel, $status, $source, $payer_name, $payer_email, $payer_phone,
            $months, $notes, $actor, $paid_at
        );
        $ok = $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $ok ? $id : 0;
    }
}

if (!function_exists('pay_payment')) {
    /** One ledger row joined with tenant + invoice, or []. */
    function pay_payment($conn, $id)
    {
        return sa_one(
            $conn,
            "SELECT sp.*, t.company_name, t.email AS tenant_email, t.phone AS tenant_phone,
                    i.invoice_number, i.months AS invoice_months, p.plan_name
               FROM subscription_payments sp
               JOIN tenants t ON t.id = sp.tenant_id
               LEFT JOIN payment_invoices i ON i.id = sp.invoice_id
               LEFT JOIN subscription_plans p ON p.id = i.plan_id
              WHERE sp.id = " . (int) $id . " LIMIT 1",
            ['subscription_payments', 'tenants']
        );
    }
}

if (!function_exists('pay_payment_by_reference')) {
    /** Ledger row for a gateway reference — used to keep webhooks idempotent. */
    function pay_payment_by_reference($conn, $gateway_key, $reference)
    {
        if ($reference === '') {
            return [];
        }
        return sa_one(
            $conn,
            "SELECT * FROM subscription_payments
              WHERE gateway_reference = '" . pay_sql_escape($conn, $reference) . "'
                AND gateway_key = '" . pay_sql_escape($conn, $gateway_key) . "'
              ORDER BY id DESC LIMIT 1",
            ['subscription_payments']
        );
    }
}

if (!function_exists('pay_confirm_payment')) {
    /**
     * Super admin approval: mark the payment confirmed and apply the
     * invoice to the subscription.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    function pay_confirm_payment($conn, $payment_id, $actor = 'Super Admin')
    {
        pay_ensure_schema($conn);
        $payment = pay_payment($conn, (int) $payment_id);
        if (!$payment) {
            return ['ok' => false, 'message' => 'That payment no longer exists.'];
        }
        if ($payment['status'] === 'confirmed') {
            return ['ok' => false, 'message' => 'That payment is already confirmed.'];
        }

        $ok = (bool) @$conn->query(
            "UPDATE subscription_payments
                SET status = 'confirmed',
                    verified_at = NOW(),
                    verified_by = '" . pay_sql_escape($conn, $actor) . "',
                    paid_at = COALESCE(paid_at, NOW())
              WHERE id = " . (int) $payment_id
        );
        if (!$ok) {
            return ['ok' => false, 'message' => 'Could not confirm that payment.'];
        }

        $applied = false;
        if (!empty($payment['invoice_id'])) {
            $invoice = pay_invoice($conn, (int) $payment['invoice_id']);
            if ($invoice) {
                // Extend by whatever the invoice sold, so an upgrade and a
                // renewal behave the same way.
                $applied = pay_invoice_apply($conn, $invoice, $actor);
            }
        }

        pay_log_event($conn, [
            'gateway_key' => (string) $payment['gateway_key'],
            'event_type'  => 'payment.confirmed',
            'reference'   => (string) $payment['gateway_reference'],
            'invoice_id'  => (int) $payment['invoice_id'],
            'payment_id'  => (int) $payment_id,
            'signature_valid' => 1,
            'payload'     => 'Confirmed by ' . $actor,
        ]);

        return [
            'ok' => true,
            'message' => 'Payment ' . $payment['receipt_number'] . ' confirmed'
                . ($applied ? ' and the subscription updated.' : '.'),
        ];
    }
}

if (!function_exists('pay_reject_payment')) {
    /**
     * Super admin refusal (or a gateway decline). The money is not
     * treated as collected; the invoice goes back to `open`.
     */
    function pay_reject_payment($conn, $payment_id, $actor = 'Super Admin', $reason = '')
    {
        pay_ensure_schema($conn);
        $payment = pay_payment($conn, (int) $payment_id);
        if (!$payment) {
            return ['ok' => false, 'message' => 'That payment no longer exists.'];
        }

        $ok = (bool) @$conn->query(
            "UPDATE subscription_payments
                SET status = 'failed',
                    verified_at = NOW(),
                    verified_by = '" . pay_sql_escape($conn, $actor) . "',
                    reject_reason = '" . pay_sql_escape($conn, $reason) . "'
              WHERE id = " . (int) $payment_id
        );
        if (!$ok) {
            return ['ok' => false, 'message' => 'Could not update that payment.'];
        }

        if (!empty($payment['invoice_id'])) {
            $invoice = pay_invoice($conn, (int) $payment['invoice_id']);
            if ($invoice && $invoice['status'] === 'processing') {
                pay_invoice_set_status($conn, (int) $invoice['id'], 'open');
            }
        }

        pay_log_event($conn, [
            'gateway_key' => (string) $payment['gateway_key'],
            'event_type'  => 'payment.rejected',
            'reference'   => (string) $payment['gateway_reference'],
            'invoice_id'  => (int) $payment['invoice_id'],
            'payment_id'  => (int) $payment_id,
            'payload'     => trim('Rejected by ' . $actor . ($reason !== '' ? ' — ' . $reason : '')),
        ]);

        return ['ok' => true, 'message' => 'Payment ' . $payment['receipt_number'] . ' marked as not collected.'];
    }
}

if (!function_exists('pay_refund_create')) {
    /**
     * Record a refund or a credit note against a payment.
     * Money already left the account, so the refund is stored as
     * `processed` straight away and the payment is flagged.
     */
    function pay_refund_create($conn, array $data)
    {
        pay_ensure_schema($conn);

        $payment_id = (int) (isset($data['payment_id']) ? $data['payment_id'] : 0);
        $payment    = $payment_id ? pay_payment($conn, $payment_id) : [];
        $tenant_id  = (int) (isset($data['tenant_id']) ? $data['tenant_id'] : (isset($payment['tenant_id']) ? $payment['tenant_id'] : 0));
        $amount     = (float) (isset($data['amount']) ? $data['amount'] : 0);
        if (!$tenant_id || $amount <= 0) {
            return ['ok' => false, 'message' => 'A refund needs a workspace and an amount.'];
        }

        $kind = (isset($data['kind']) && $data['kind'] === 'credit') ? 'credit' : 'refund';
        $currency = isset($data['currency']) && $data['currency'] !== ''
            ? strtoupper((string) $data['currency'])
            : (isset($payment['currency']) && $payment['currency'] !== '' ? strtoupper((string) $payment['currency']) : strtoupper(sa_currency_code($conn)));
        $gateway_key = isset($data['gateway_key']) ? (string) $data['gateway_key'] : (isset($payment['gateway_key']) ? (string) $payment['gateway_key'] : '');
        $reference   = isset($data['gateway_reference']) ? (string) $data['gateway_reference'] : (isset($payment['gateway_reference']) ? (string) $payment['gateway_reference'] : '');
        $reason      = isset($data['reason']) ? (string) $data['reason'] : '';
        $actor       = isset($data['processed_by']) ? (string) $data['processed_by'] : 'Super Admin';
        $invoice_id  = (int) (isset($data['invoice_id']) ? $data['invoice_id'] : (isset($payment['invoice_id']) ? $payment['invoice_id'] : 0));

        $stmt = $conn->prepare(
            "INSERT INTO payment_refunds
                (tenant_id, payment_id, invoice_id, amount, currency, kind, reason, status, gateway_key,
                 gateway_reference, requested_by, processed_by, processed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'processed', ?, ?, ?, ?, NOW(), NOW())"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not record the refund.'];
        }
        $pid = $payment_id ? $payment_id : 0;
        $stmt->bind_param('iiidsssssss', $tenant_id, $pid, $invoice_id, $amount, $currency, $kind, $reason, $gateway_key, $reference, $actor, $actor);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok && $payment_id) {
            @$conn->query("UPDATE subscription_payments SET status = 'refunded' WHERE id = " . (int) $payment_id);
        }
        if ($ok && $invoice_id) {
            $invoice = pay_invoice($conn, $invoice_id);
            if ($invoice && $invoice['status'] === 'paid') {
                pay_invoice_set_status($conn, $invoice_id, 'refunded');
            }
        }

        return ['ok' => (bool) $ok, 'message' => $ok ? 'Refund recorded.' : 'Could not record the refund.'];
    }
}

/* ============================================================
   7b. GATEWAY SETTLEMENT (checkout callback + webhook)
   ============================================================ */

if (!function_exists('pay_start_checkout')) {
    /**
     * Begin a hosted checkout for an invoice and remember the reference.
     *
     * @return array ['ok' => bool, 'url' => '', 'reference' => '', 'message' => '']
     */
    function pay_start_checkout($conn, $gateway_key, array $invoice, $callback_url)
    {
        $gw = pay_gateway($conn, $gateway_key);
        if (!$gw || !$gw['ready']) {
            return ['ok' => false, 'url' => '', 'reference' => '', 'message' => 'That payment method is not available right now.'];
        }

        $tenant = sa_one($conn, "SELECT * FROM tenants WHERE id = " . (int) $invoice['tenant_id'] . " LIMIT 1", 'tenants');
        if (!$tenant) {
            return ['ok' => false, 'url' => '', 'reference' => '', 'message' => 'This workspace no longer exists.'];
        }

        $result = pay_driver_checkout($gw, $invoice, $tenant, $callback_url);

        if ($result['ok']) {
            // Store the reference on the invoice so the callback, the webhook
            // and the tenant can all find their way back to it.
            pay_invoice_set_status($conn, (int) $invoice['id'], $invoice['status'] === 'overdue' ? 'overdue' : 'open', [
                'gateway_key'        => $gateway_key,
                'checkout_reference' => $result['reference'],
            ]);
            pay_log_event($conn, [
                'gateway_key'     => $gateway_key,
                'event_type'      => 'checkout.started',
                'reference'       => $result['reference'],
                'invoice_id'      => (int) $invoice['id'],
                'signature_valid' => 1,
                'payload'         => 'Tenant opened ' . $gw['display_name'] . ' checkout.',
            ]);
        }

        return $result;
    }
}

if (!function_exists('pay_settle_gateway_payment')) {
    /**
     * Verify a gateway reference and, when the money is really there,
     * write it into the ledger as `pending` and park the invoice in
     * `processing` for the platform owner to confirm.
     *
     * Called by both the browser callback and the webhook, so it is
     * idempotent: a reference that is already on the ledger is never
     * recorded twice.
     *
     * @return array ['ok','paid','recorded','duplicate','message','payment_id','invoice']
     */
    function pay_settle_gateway_payment($conn, $gateway_key, $reference, $event_type = 'callback')
    {
        pay_ensure_schema($conn);
        $out = [
            'ok' => false, 'paid' => false, 'recorded' => false, 'duplicate' => false,
            'message' => '', 'payment_id' => 0, 'invoice' => [], 'verify' => [],
        ];

        $reference = trim((string) $reference);
        if ($reference === '') {
            $out['message'] = 'No payment reference was supplied.';
            return $out;
        }

        $gw = pay_gateway($conn, $gateway_key);
        if (!$gw) {
            $out['message'] = 'Unknown payment integration.';
            return $out;
        }

        $invoice = pay_invoice_find_by_reference($conn, $reference);

        // Already handled (webhook arrived before the browser, or a replay).
        $existing = pay_payment_by_reference($conn, $gateway_key, $reference);
        if ($existing) {
            $out['ok']       = true;
            $out['duplicate'] = true;
            $out['recorded'] = true;
            $out['paid']     = ($existing['status'] !== 'failed');
            $out['payment_id'] = (int) $existing['id'];
            $out['invoice']  = $invoice;
            $out['message']  = 'This payment has already been recorded.';
            return $out;
        }

        $verify = pay_driver_verify($gw, $reference);
        $out['verify'] = $verify;

        if (!$verify['ok']) {
            pay_log_event($conn, [
                'gateway_key' => $gateway_key,
                'event_type'  => $event_type . '.verify_failed',
                'reference'   => $reference,
                'invoice_id'  => $invoice ? (int) $invoice['id'] : 0,
                'payload'     => $verify['message'],
            ]);
            $out['message'] = $verify['message'];
            return $out;
        }

        // The gateway answered, so we know the truth either way.
        if (!$verify['paid']) {
            pay_log_event($conn, [
                'gateway_key'     => $gateway_key,
                'event_type'      => $event_type . '.not_paid',
                'reference'       => $reference,
                'invoice_id'      => $invoice ? (int) $invoice['id'] : 0,
                'signature_valid' => 1,
                'payload'         => $verify['message'],
            ]);
            $out['ok'] = true;
            $out['message'] = $verify['message'];
            return $out;
        }

        if (!$invoice) {
            // Paid, but we cannot tie it to a bill — keep the evidence.
            pay_log_event($conn, [
                'gateway_key'     => $gateway_key,
                'event_type'      => $event_type . '.unmatched',
                'reference'       => $reference,
                'signature_valid' => 1,
                'payload'         => json_encode([
                    'message' => 'Payment verified but no invoice matches this reference.',
                    'amount'  => $verify['amount'],
                    'currency' => $verify['currency'],
                    'payload' => $verify['raw'],
                ]),
            ]);
            $out['ok']      = true;
            $out['paid']    = true;
            $out['message'] = 'Payment verified, but no invoice matches reference ' . $reference . '.';
            return $out;
        }

        $expected = (float) $invoice['total'];
        $received = (float) $verify['amount'];
        $note = '';
        if ($received + 0.01 < $expected) {
            $note = 'Short payment: ' . sa_money($received) . ' of ' . sa_money($expected) . ' expected.';
        } elseif ($received > $expected + 0.01) {
            $note = 'Overpayment: ' . sa_money($received) . ' against ' . sa_money($expected) . '.';
        }

        $payment_id = pay_record($conn, [
            'tenant_id'         => (int) $invoice['tenant_id'],
            'invoice_id'        => (int) $invoice['id'],
            'amount'            => $received > 0 ? $received : $expected,
            'status'            => 'pending',          // the owner confirms it
            'payment_method'    => $gw['display_name'],
            'gateway_key'       => $gateway_key,
            'gateway_reference' => $reference,
            'channel'           => $verify['channel'],
            'currency'          => $verify['currency'] !== '' ? $verify['currency'] : (string) $invoice['currency'],
            'fee'               => $verify['fee'],
            'payer_name'        => $verify['payer_name'],
            'payer_email'       => $verify['payer_email'],
            'months_extended'   => (int) $invoice['months'],
            'notes'             => trim('Captured via ' . $gw['display_name'] . '. ' . $note),
            'source'            => 'gateway',
            'paid_at'           => date('Y-m-d H:i:s'),
        ]);

        if (!$payment_id) {
            $out['message'] = 'The payment was verified but could not be recorded.';
            return $out;
        }

        pay_invoice_set_status($conn, (int) $invoice['id'], 'processing', [
            'gateway_key'        => $gateway_key,
            'checkout_reference' => $reference,
        ]);

        pay_log_event($conn, [
            'gateway_key'     => $gateway_key,
            'event_type'      => $event_type . '.paid',
            'reference'       => $reference,
            'invoice_id'      => (int) $invoice['id'],
            'payment_id'      => $payment_id,
            'signature_valid' => 1,
            'payload'         => $note !== '' ? $note : 'Payment captured and awaiting confirmation.',
        ]);

        $out['ok']         = true;
        $out['paid']       = true;
        $out['recorded']   = true;
        $out['payment_id'] = $payment_id;
        $out['invoice']    = pay_invoice($conn, (int) $invoice['id']);
        $out['message']    = 'Payment received — it is now waiting for confirmation.';
        return $out;
    }
}

/* ============================================================
   8. EVENTS
   ============================================================ */

if (!function_exists('pay_log_event')) {
    /** Append to the webhook / callback audit trail. Never throws. */
    function pay_log_event($conn, array $data)
    {
        if (!is_object($conn) || !method_exists($conn, 'prepare')) {
            return 0;
        }
        pay_ensure_schema($conn);

        $payload = isset($data['payload']) ? $data['payload'] : '';
        if (is_array($payload)) {
            $payload = json_encode($payload);
        }
        $payload = (string) $payload;
        if (strlen($payload) > 60000) {
            $payload = substr($payload, 0, 60000) . '…[truncated]';
        }

        $stmt = $conn->prepare(
            "INSERT INTO payment_events
                (gateway_key, event_type, reference, invoice_id, payment_id, signature_valid, ip_address, payload, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) {
            return 0;
        }
        $gateway   = isset($data['gateway_key']) ? (string) $data['gateway_key'] : '';
        $type      = isset($data['event_type']) ? (string) $data['event_type'] : '';
        $reference = isset($data['reference']) ? (string) $data['reference'] : '';
        $invoice   = (int) (isset($data['invoice_id']) ? $data['invoice_id'] : 0);
        $payment   = (int) (isset($data['payment_id']) ? $data['payment_id'] : 0);
        $valid     = !empty($data['signature_valid']) ? 1 : 0;
        $ip        = isset($data['ip_address']) ? (string) $data['ip_address'] : (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');

        $stmt->bind_param('sssiiiss', $gateway, $type, $reference, $invoice, $payment, $valid, $ip, $payload);
        $ok = $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();
        return $ok ? $id : 0;
    }
}

if (!function_exists('pay_events')) {
    /** Recent gateway events for the financial centre's audit panel. */
    function pay_events($conn, $limit = 12, $gateway = '')
    {
        $where = $gateway !== '' ? " WHERE gateway_key = '" . pay_sql_escape($conn, $gateway) . "'" : '';
        return sa_query(
            $conn,
            "SELECT * FROM payment_events" . $where . " ORDER BY id DESC LIMIT " . max(1, (int) $limit),
            ['payment_events']
        );
    }
}

/* ============================================================
   9. ANALYTICS
   ============================================================ */

if (!function_exists('pay_summary')) {
    /**
     * The headline numbers for the financial centre.
     *
     * "collected" only counts confirmed money, so a gateway charge that
     * is still waiting for approval never inflates revenue.
     */
    function pay_summary($conn, $days = 30)
    {
        pay_ensure_schema($conn);
        $days = max(1, min(3650, (int) $days));

        $collected_total  = (float) sa_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status = 'confirmed'", 0, 'subscription_payments');
        $collected_window = (float) sa_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status = 'confirmed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . $days . " DAY)", 0, 'subscription_payments');
        $collected_prev   = (float) sa_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status = 'confirmed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($days * 2) . " DAY) AND created_at < DATE_SUB(CURDATE(), INTERVAL " . $days . " DAY)", 0, 'subscription_payments');

        $pending_count  = (int) sa_scalar($conn, "SELECT COUNT(*) FROM subscription_payments WHERE status = 'pending'", 0, 'subscription_payments');
        $pending_amount = (float) sa_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status = 'pending'", 0, 'subscription_payments');
        $failed_amount  = (float) sa_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status = 'failed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . $days . " DAY)", 0, 'subscription_payments');

        $refunded_total  = (float) sa_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM payment_refunds WHERE status = 'processed'", 0, 'payment_refunds');
        $refunded_window = (float) sa_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM payment_refunds WHERE status = 'processed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . $days . " DAY)", 0, 'payment_refunds');

        $mrr = (float) sa_scalar($conn, "SELECT COALESCE(SUM(subscription_price),0) FROM tenants WHERE subscription_status = 'active'", 0, 'tenants');
        $paying = (int) sa_scalar($conn, "SELECT COUNT(*) FROM tenants WHERE subscription_status = 'active'", 0, 'tenants');
        $trial  = (int) sa_scalar($conn, "SELECT COUNT(*) FROM tenants WHERE subscription_status = 'trial'", 0, 'tenants');
        $total_tenants = (int) sa_scalar($conn, "SELECT COUNT(*) FROM tenants", 0, 'tenants');

        $open_invoices       = (int) sa_scalar($conn, "SELECT COUNT(*) FROM payment_invoices WHERE status IN ('open','overdue')", 0, 'payment_invoices');
        $open_invoice_amount = (float) sa_scalar($conn, "SELECT COALESCE(SUM(total),0) FROM payment_invoices WHERE status IN ('open','overdue')", 0, 'payment_invoices');
        $processing_count    = (int) sa_scalar($conn, "SELECT COUNT(*) FROM payment_invoices WHERE status = 'processing'", 0, 'payment_invoices');
        $paid_invoices       = (int) sa_scalar($conn, "SELECT COUNT(*) FROM payment_invoices WHERE status = 'paid'", 0, 'payment_invoices');
        $issued_invoices     = (int) sa_scalar($conn, "SELECT COUNT(*) FROM payment_invoices", 0, 'payment_invoices');
        $overdue_count       = (int) sa_scalar($conn, "SELECT COUNT(*) FROM payment_invoices WHERE status IN ('open','overdue') AND due_date IS NOT NULL AND due_date < CURDATE()", 0, 'payment_invoices');

        $gateway_collected = (float) sa_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status = 'confirmed' AND source = 'gateway'", 0, 'subscription_payments');
        $offline_collected = (float) sa_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE status = 'confirmed' AND source <> 'gateway'", 0, 'subscription_payments');
        $fees_window       = (float) sa_scalar($conn, "SELECT COALESCE(SUM(fee),0) FROM subscription_payments WHERE status = 'confirmed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . $days . " DAY)", 0, 'subscription_payments');

        $expiring = (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM tenants
              WHERE subscription_status IN ('active','trial')
                AND subscription_end_date IS NOT NULL
                AND subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
            0,
            'tenants'
        );

        $delta = 0.0;
        if ($collected_prev > 0) {
            $delta = (($collected_window - $collected_prev) / $collected_prev) * 100;
        } elseif ($collected_window > 0) {
            $delta = 100.0;
        }

        return [
            'days'               => $days,
            'collected_total'    => $collected_total,
            'collected_window'   => $collected_window,
            'collected_prev'     => $collected_prev,
            'collected_delta'    => round($delta, 1),
            'gateway_collected'  => $gateway_collected,
            'offline_collected'  => $offline_collected,
            'pending_count'      => $pending_count,
            'pending_amount'     => $pending_amount,
            'failed_amount'      => $failed_amount,
            'refunded_total'     => $refunded_total,
            'refunded_window'    => $refunded_window,
            'fees_window'        => $fees_window,
            'mrr'                => $mrr,
            'arr'                => $mrr * 12,
            'paying'             => $paying,
            'trial'              => $trial,
            'total_tenants'      => $total_tenants,
            'arpu'               => $paying > 0 ? $mrr / $paying : 0.0,
            'open_invoices'      => $open_invoices,
            'open_invoice_amount' => $open_invoice_amount,
            'processing_count'   => $processing_count,
            'paid_invoices'      => $paid_invoices,
            'issued_invoices'    => $issued_invoices,
            'overdue_count'      => $overdue_count,
            'expiring'           => $expiring,
            'conversion'         => $issued_invoices > 0 ? sa_pct($paid_invoices, $issued_invoices) : 0.0,
        ];
    }
}

if (!function_exists('pay_revenue_series')) {
    /**
     * Confirmed money per month for the trend chart.
     * @return array ['labels' => [], 'values' => [], 'refunds' => [], 'rows' => []]
     */
    function pay_revenue_series($conn, $months = 12)
    {
        pay_ensure_schema($conn);
        $months = max(1, min(36, (int) $months));

        $labels = $values = $refunds = $counts = $rows = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key   = date('Y-m', strtotime('-' . $i . ' month'));
            $label = date('M y', strtotime($key . '-01'));
            $labels[$key]  = $label;
            $values[$key]  = 0.0;
            $refunds[$key] = 0.0;
            $counts[$key]  = 0;
        }

        $first = array_key_first($labels);
        $paid = sa_query(
            $conn,
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total, COUNT(*) AS c
               FROM subscription_payments
              WHERE status = 'confirmed' AND created_at >= '" . $first . "-01 00:00:00'
              GROUP BY ym",
            ['subscription_payments']
        );
        foreach ($paid as $row) {
            if (isset($values[$row['ym']])) {
                $values[$row['ym']]  = (float) $row['total'];
                $counts[$row['ym']]  = (int) $row['c'];
            }
        }

        $refund_rows = sa_query(
            $conn,
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
               FROM payment_refunds
              WHERE status = 'processed' AND created_at >= '" . $first . "-01 00:00:00'
              GROUP BY ym",
            ['payment_refunds']
        );
        foreach ($refund_rows as $row) {
            if (isset($refunds[$row['ym']])) {
                $refunds[$row['ym']] = (float) $row['total'];
            }
        }

        foreach ($labels as $key => $label) {
            $rows[] = [
                'key'     => $key,
                'label'   => $label,
                'total'   => $values[$key],
                'refunds' => $refunds[$key],
                'count'   => $counts[$key],
                'net'     => $values[$key] - $refunds[$key],
            ];
        }

        return [
            'labels'  => array_values($labels),
            'values'  => array_values($values),
            'refunds' => array_values($refunds),
            'rows'    => $rows,
        ];
    }
}

if (!function_exists('pay_gateway_breakdown')) {
    /** Confirmed money grouped by the integration that took it. */
    function pay_gateway_breakdown($conn, $days = 0)
    {
        pay_ensure_schema($conn);
        $where = "WHERE status = 'confirmed'";
        if ($days > 0) {
            $where .= " AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . (int) $days . " DAY)";
        }
        $rows = sa_query(
            $conn,
            "SELECT gateway_key, source, COUNT(*) AS payments, COALESCE(SUM(amount),0) AS total
               FROM subscription_payments " . $where . "
              GROUP BY gateway_key, source
              ORDER BY total DESC",
            ['subscription_payments']
        );

        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['gateway_key'];
            $label = $key === '' || $key === null
                ? ($row['source'] === 'gateway' ? 'Other gateway' : 'Manual / bank')
                : pay_gateway_label($key);
            if (!isset($out[$key])) {
                $out[$key] = ['key' => $key, 'label' => $label, 'total' => 0.0, 'payments' => 0];
            }
            $out[$key]['total']    += (float) $row['total'];
            $out[$key]['payments'] += (int) $row['payments'];
        }
        return array_values($out);
    }
}

if (!function_exists('pay_method_breakdown')) {
    /** Confirmed money grouped by payment channel (card, momo, bank…). */
    function pay_method_breakdown($conn, $days = 0)
    {
        pay_ensure_schema($conn);
        $where = "WHERE status = 'confirmed'";
        if ($days > 0) {
            $where .= " AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . (int) $days . " DAY)";
        }
        $rows = sa_query(
            $conn,
            "SELECT payment_method, COUNT(*) AS payments, COALESCE(SUM(amount),0) AS total
               FROM subscription_payments " . $where . "
              GROUP BY payment_method ORDER BY total DESC LIMIT 8",
            ['subscription_payments']
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'label'    => $row['payment_method'] !== '' && $row['payment_method'] !== null ? $row['payment_method'] : 'Unspecified',
                'total'    => (float) $row['total'],
                'payments' => (int) $row['payments'],
            ];
        }
        return $out;
    }
}

if (!function_exists('pay_plan_breakdown')) {
    /** Confirmed money grouped by the plan it paid for. */
    function pay_plan_breakdown($conn, $limit = 6)
    {
        pay_ensure_schema($conn);
        $rows = sa_query(
            $conn,
            "SELECT COALESCE(p.plan_name, 'No plan') AS plan_name,
                    COUNT(*) AS payments, COALESCE(SUM(sp.amount),0) AS total
               FROM subscription_payments sp
               LEFT JOIN payment_invoices i ON i.id = sp.invoice_id
               LEFT JOIN subscription_plans p ON p.id = i.plan_id
              WHERE sp.status = 'confirmed'
              GROUP BY plan_name
              ORDER BY total DESC
              LIMIT " . max(1, (int) $limit),
            ['subscription_payments']
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'label' => (string) $row['plan_name'],
                'total' => (float) $row['total'],
                'count' => (int) $row['payments'],
            ];
        }
        return $out;
    }
}

if (!function_exists('pay_ledger')) {
    /**
     * Ledger rows for the financial centre table.
     * $filters: status, gateway, tenant_id, q (reference/tenant), from, to
     */
    function pay_ledger($conn, array $filters = [], $limit = 100, $offset = 0)
    {
        pay_ensure_schema($conn);
        $where = pay_ledger_where($conn, $filters);

        return sa_query(
            $conn,
            "SELECT sp.*, t.company_name, t.email AS tenant_email, i.invoice_number
               FROM subscription_payments sp
               JOIN tenants t ON t.id = sp.tenant_id
               LEFT JOIN payment_invoices i ON i.id = sp.invoice_id
             " . $where . "
              ORDER BY sp.created_at DESC, sp.id DESC
              LIMIT " . max(1, (int) $limit) . " OFFSET " . max(0, (int) $offset),
            ['subscription_payments', 'tenants']
        );
    }
}

if (!function_exists('pay_ledger_count')) {
    /** Total rows matching the same filters, for pagination. */
    function pay_ledger_count($conn, array $filters = [])
    {
        $where = pay_ledger_where($conn, $filters);
        return (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM subscription_payments sp
               JOIN tenants t ON t.id = sp.tenant_id
               LEFT JOIN payment_invoices i ON i.id = sp.invoice_id
             " . $where,
            0,
            ['subscription_payments', 'tenants']
        );
    }
}

if (!function_exists('pay_ledger_where')) {
    /** Shared WHERE builder for the ledger list + its count. */
    function pay_ledger_where($conn, array $filters)
    {
        $where = [];
        $status = isset($filters['status']) ? (string) $filters['status'] : '';
        if (in_array($status, ['pending', 'confirmed', 'failed', 'refunded'], true)) {
            $where[] = "sp.status = '" . pay_sql_escape($conn, $status) . "'";
        }
        $gateway = isset($filters['gateway']) ? (string) $filters['gateway'] : '';
        if ($gateway !== '') {
            $where[] = "sp.gateway_key = '" . pay_sql_escape($conn, $gateway) . "'";
        }
        $tenant = (int) (isset($filters['tenant_id']) ? $filters['tenant_id'] : 0);
        if ($tenant) {
            $where[] = 'sp.tenant_id = ' . $tenant;
        }
        $from = isset($filters['from']) ? (string) $filters['from'] : '';
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = "sp.created_at >= '" . $from . " 00:00:00'";
        }
        $to = isset($filters['to']) ? (string) $filters['to'] : '';
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = "sp.created_at <= '" . $to . " 23:59:59'";
        }
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $like = pay_sql_escape($conn, $q);
            $where[] = "(sp.receipt_number LIKE '%" . $like . "%'
                      OR sp.gateway_reference LIKE '%" . $like . "%'
                      OR sp.transaction_ref LIKE '%" . $like . "%'
                      OR t.company_name LIKE '%" . $like . "%'
                      OR i.invoice_number LIKE '%" . $like . "%')";
        }
        return $where ? ' WHERE ' . implode(' AND ', $where) : '';
    }
}

if (!function_exists('pay_invoices')) {
    /**
     * Invoice rows for the financial centre table.
     * $filters: status, tenant_id, q
     */
    function pay_invoices($conn, array $filters = [], $limit = 100, $offset = 0)
    {
        pay_ensure_schema($conn);
        $where = pay_invoice_where($conn, $filters);
        return sa_query(
            $conn,
            "SELECT i.*, t.company_name, t.email AS tenant_email, p.plan_name,
                    (SELECT COUNT(*) FROM subscription_payments sp WHERE sp.invoice_id = i.id AND sp.status = 'pending') AS pending_payments
               FROM payment_invoices i
               JOIN tenants t ON t.id = i.tenant_id
               LEFT JOIN subscription_plans p ON p.id = i.plan_id
             " . $where . "
              ORDER BY FIELD(i.status, 'processing', 'overdue', 'open', 'draft', 'paid', 'cancelled', 'refunded'), i.due_date ASC, i.id DESC
              LIMIT " . max(1, (int) $limit) . " OFFSET " . max(0, (int) $offset),
            ['payment_invoices', 'tenants']
        );
    }
}

if (!function_exists('pay_invoice_count')) {
    function pay_invoice_count($conn, array $filters = [])
    {
        $where = pay_invoice_where($conn, $filters);
        return (int) sa_scalar(
            $conn,
            "SELECT COUNT(*) FROM payment_invoices i JOIN tenants t ON t.id = i.tenant_id " . $where,
            0,
            ['payment_invoices', 'tenants']
        );
    }
}

if (!function_exists('pay_invoice_where')) {
    function pay_invoice_where($conn, array $filters)
    {
        $where = [];
        $status = isset($filters['status']) ? (string) $filters['status'] : '';
        $statuses = ['draft', 'open', 'processing', 'paid', 'overdue', 'cancelled', 'refunded'];
        if (in_array($status, $statuses, true)) {
            $where[] = "i.status = '" . pay_sql_escape($conn, $status) . "'";
        }
        $tenant = (int) (isset($filters['tenant_id']) ? $filters['tenant_id'] : 0);
        if ($tenant) {
            $where[] = 'i.tenant_id = ' . $tenant;
        }
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $like = pay_sql_escape($conn, $q);
            $where[] = "(i.invoice_number LIKE '%" . $like . "%' OR t.company_name LIKE '%" . $like . "%' OR i.subject LIKE '%" . $like . "%')";
        }
        return $where ? ' WHERE ' . implode(' AND ', $where) : '';
    }
}

if (!function_exists('pay_refunds')) {
    /** Refund rows for the financial centre. */
    function pay_refunds($conn, $limit = 50)
    {
        pay_ensure_schema($conn);
        return sa_query(
            $conn,
            "SELECT r.*, t.company_name, i.invoice_number
               FROM payment_refunds r
               JOIN tenants t ON t.id = r.tenant_id
               LEFT JOIN payment_invoices i ON i.id = r.invoice_id
              ORDER BY r.created_at DESC, r.id DESC
              LIMIT " . max(1, (int) $limit),
            ['payment_refunds', 'tenants']
        );
    }
}

/* ============================================================
   10. TENANT-FACING HELPERS
   ============================================================ */

if (!function_exists('pay_enabled_gateways')) {
    /** Gateways a tenant may actually choose (enabled + fully configured). */
    function pay_enabled_gateways($conn)
    {
        $out = [];
        foreach (pay_gateways($conn, true) as $key => $gw) {
            if ($gw['ready']) {
                $out[$key] = $gw;
            }
        }
        return $out;
    }
}

if (!function_exists('pay_selected_gateway')) {
    /**
     * The gateway to preselect for a tenant: the platform default when it
     * is usable, otherwise the first configured one.
     */
    function pay_selected_gateway($conn, $preferred = '')
    {
        $available = pay_enabled_gateways($conn);
        if (!$available) {
            return null;
        }
        if ($preferred !== '' && isset($available[$preferred])) {
            return $available[$preferred];
        }
        $default = $conn ? sa_setting($conn, 'default_gateway', '') : '';
        if ($default !== '' && isset($available[$default])) {
            return $available[$default];
        }
        return reset($available);
    }
}

/* ============================================================
   11. VIEW HELPERS
   ============================================================ */

if (!function_exists('pay_status_label')) {
    /** Readable label for an invoice *or* payment status. */
    function pay_status_label($status)
    {
        $map = [
            'draft'      => 'Draft',
            'open'       => 'Awaiting payment',
            'processing' => 'Awaiting confirmation',
            'paid'       => 'Paid',
            'overdue'    => 'Overdue',
            'cancelled'  => 'Cancelled',
            'refunded'   => 'Refunded',
            'pending'    => 'Awaiting confirmation',
            'confirmed'  => 'Confirmed',
            'failed'     => 'Not collected',
        ];
        $status = (string) $status;
        return isset($map[$status]) ? $map[$status] : ucfirst($status !== '' ? $status : 'unknown');
    }
}

if (!function_exists('pay_status_class')) {
    /** Badge class for a status, reusing the shell's badge palette. */
    function pay_status_class($status)
    {
        $map = [
            'draft'      => 'sa-badge-inactive',
            'open'       => 'sa-badge-pending',
            'processing' => 'sa-badge-contacted',
            'paid'       => 'sa-badge-active',
            'confirmed'  => 'sa-badge-active',
            'pending'    => 'sa-badge-contacted',
            'overdue'    => 'sa-badge-cancelled',
            'cancelled'  => 'sa-badge-cancelled',
            'refunded'   => 'sa-badge-trial',
            'failed'     => 'sa-badge-cancelled',
        ];
        $status = (string) $status;
        return isset($map[$status]) ? $map[$status] : 'sa-badge-inactive';
    }
}

if (!function_exists('pay_badge')) {
    /** Rendered status pill for invoices and payments. */
    function pay_badge($status)
    {
        return '<span class="sa-badge ' . pay_status_class($status) . '">'
            . sa_e(pay_status_label($status)) . '</span>';
    }
}

if (!function_exists('pay_channel_label')) {
    /** Tidy up the channel strings gateways return. */
    function pay_channel_label($channel)
    {
        $map = [
            'card'          => 'Card',
            'mobile_money'  => 'Mobile money',
            'mobilemoneygh' => 'Mobile money',
            'momo'          => 'Mobile money',
            'bank'          => 'Bank transfer',
            'banktransfer'  => 'Bank transfer',
            'bank_transfer' => 'Bank transfer',
            'ussd'          => 'USSD',
            'qr'            => 'QR code',
            'wallet'        => 'Wallet',
            'cash'          => 'Cash',
        ];
        $key = strtolower(trim((string) $channel));
        if ($key === '') {
            return '';
        }
        return isset($map[$key]) ? $map[$key] : ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('pay_gateway_mark')) {
    /** Small square brand mark (initials) used in tables and cards. */
    function pay_gateway_mark($key, $size = 30)
    {
        $labels = ['paystack' => 'PS', 'flutterwave' => 'FL', 'bank_transfer' => 'BT'];
        $text = isset($labels[$key]) ? $labels[$key] : strtoupper(substr((string) $key, 0, 2));
        $tone = $key === 'paystack' ? 'var(--sa-info)' : ($key === 'flutterwave' ? 'var(--sa-warning)' : 'var(--sa-accent)');
        return '<span class="sa-method-mark" style="--mark:' . $tone . ';width:' . (int) $size . 'px;height:' . (int) $size
            . 'px;font-size:' . max(9, (int) ($size / 2.9)) . 'px" aria-hidden="true">' . sa_e($text) . '</span>';
    }
}

if (!function_exists('pay_amount')) {
    /**
     * Format an amount with its own currency when we know it, falling back
     * to the platform currency for legacy rows.
     */
    function pay_amount($amount, $currency = '')
    {
        $formatted = sa_money($amount);
        $currency  = strtoupper(trim((string) $currency));
        $platform  = strtoupper(sa_currency_code(null));
        if ($currency === '' || $currency === $platform) {
            return $formatted;
        }
        return $formatted . ' ' . $currency;
    }
}

if (!function_exists('pay_trim')) {
    /** Shorten free text for a table cell. */
    function pay_trim($text, $length = 90, $ellipsis = '…')
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        if ($length <= 0 || mb_strlen($text) <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length)) . $ellipsis;
    }
}

if (!function_exists('pay_days_ago')) {
    /** "3 days ago" style label, or '—'. */
    function pay_days_ago($datetime)
    {
        if (!$datetime) {
            return '—';
        }
        $ts = strtotime($datetime);
        if (!$ts) {
            return '—';
        }
        $days = (int) floor((time() - $ts) / 86400);
        if ($days <= 0) {
            return 'Today';
        }
        if ($days === 1) {
            return 'Yesterday';
        }
        if ($days < 30) {
            return $days . ' days ago';
        }
        return date('M j, Y', $ts);
    }
}

if (!function_exists('pay_due_label')) {
    /** Human due-date label with urgency for the financial centre. */
    function pay_due_label($invoice)
    {
        $status = isset($invoice['status']) ? $invoice['status'] : '';
        $due    = isset($invoice['due_date']) ? $invoice['due_date'] : '';
        if (in_array($status, ['paid', 'cancelled', 'refunded'], true) || !$due) {
            return '';
        }
        $ts = strtotime($due);
        if (!$ts) {
            return '';
        }
        $days = (int) floor(($ts - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0) {
            return '<span class="sa-due is-overdue">' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' overdue</span>';
        }
        if ($days === 0) {
            return '<span class="sa-due is-soon">Due today</span>';
        }
        if ($days <= 7) {
            return '<span class="sa-due is-soon">Due in ' . $days . ' day' . ($days === 1 ? '' : 's') . '</span>';
        }
        return '<span class="sa-due">Due ' . sa_e(date('M j, Y', $ts)) . '</span>';
    }
}
