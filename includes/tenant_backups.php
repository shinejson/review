<?php
/**
 * ============================================================
 *  Workspace (tenant) backups
 * ============================================================
 *  The platform owner can dump the whole database
 *  (superadmin/backups.php). A workspace gets its own, narrower
 *  export instead: everything that belongs to *this* tenant, as
 *  one JSON file the tenant can download and keep.
 *
 *  Files live in  backups/tenants/<tenant id>/  next to the
 *  platform dumps, and every export is registered in the
 *  `tenant_backups` table so the history survives a restart and
 *  the activity log has a row to show.
 *
 *  Secrets are never exported: passwords, setup tokens, social
 *  access tokens and ad-platform CAPI tokens are stripped from
 *  the rows before they are written.
 */

require_once __DIR__ . '/sa_helpers.php';
require_once __DIR__ . '/logging_helpers.php';

/* Rows written per section before the export marks it truncated. */
if (!defined('TENANT_BACKUP_ROW_CAP')) {
    define('TENANT_BACKUP_ROW_CAP', 5000);
}

if (!function_exists('tenant_backup_root')) {
    /** Folder that holds the per-workspace backup folders. */
    function tenant_backup_root()
    {
        return dirname(__DIR__) . '/backups/tenants';
    }
}

if (!function_exists('tenant_backup_dir')) {
    /** Folder for one workspace (created on demand). */
    function tenant_backup_dir($tenant_id)
    {
        return tenant_backup_root() . '/' . (int) $tenant_id;
    }
}

if (!function_exists('tenant_backup_sections')) {
    /**
     * What a workspace backup contains.
     *
     * key   => [
     *   label  : shown in the UI
     *   table  : table used for the "is this installed?" probe
     *   sql    : SELECT the export runs ({tid} = tenant id)
     *   strip  : columns removed before writing the file
     *   order  : weight when listing the sections
     * ]
     */
    function tenant_backup_sections()
    {
        return [
            'workspace' => [
                'label' => 'Workspace profile & subscription',
                'table' => 'tenants',
                'sql'   => 'SELECT * FROM tenants WHERE id = {tid} LIMIT 1',
                'strip' => ['password', 'setup_token', 'setup_token_expires'],
                'order' => 10,
            ],
            'branches' => [
                'label' => 'Branches (company profiles)',
                'table' => 'customers',
                'sql'   => 'SELECT * FROM customers WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 20,
            ],
            'questions' => [
                'label' => 'Rating questions',
                'table' => 'rating_questions',
                'sql'   => 'SELECT * FROM rating_questions WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 30,
            ],
            'reviews' => [
                'label' => 'Reviews & replies',
                'table' => 'ratings',
                'sql'   => 'SELECT r.* FROM ratings r JOIN customers c ON c.id = r.company_id WHERE c.tenant_id = {tid} ORDER BY r.id ASC',
                'strip' => [],
                'order' => 40,
            ],
            'review_customers' => [
                'label' => 'Signed-up customers',
                'table' => 'site_customers',
                'sql'   => 'SELECT * FROM site_customers WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 50,
            ],
            'services' => [
                'label' => 'Services catalogue',
                'table' => 'services',
                'sql'   => 'SELECT * FROM services WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 60,
            ],
            'team' => [
                'label' => 'Team members (no passwords)',
                'table' => 'team_members',
                'sql'   => 'SELECT * FROM team_members WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => ['password'],
                'order' => 70,
            ],
            'qa' => [
                'label' => 'Community questions',
                'table' => 'community_questions',
                'sql'   => 'SELECT * FROM community_questions WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 80,
            ],
            'review_invites' => [
                'label' => 'Review invites sent',
                'table' => 'review_invites',
                'sql'   => 'SELECT * FROM review_invites WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 90,
            ],
            'social_accounts' => [
                'label' => 'Connected social accounts (no tokens)',
                'table' => 'social_accounts',
                'sql'   => 'SELECT * FROM social_accounts WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => ['access_token'],
                'order' => 100,
            ],
            'social_posts' => [
                'label' => 'Social posts',
                'table' => 'social_posts',
                'sql'   => 'SELECT * FROM social_posts WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 110,
            ],
            'ad_tracking' => [
                'label' => 'Ad tracking settings (no CAPI token)',
                'table' => 'tenant_ad_configs',
                'sql'   => 'SELECT * FROM tenant_ad_configs WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => ['meta_capi_token'],
                'order' => 120,
            ],
            'subscription_requests' => [
                'label' => 'Plan change requests',
                'table' => 'subscription_requests',
                'sql'   => 'SELECT * FROM subscription_requests WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 130,
            ],
            'invoices' => [
                'label' => 'Invoices',
                'table' => 'payment_invoices',
                'sql'   => 'SELECT * FROM payment_invoices WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 140,
            ],
            'payments' => [
                'label' => 'Payments received',
                'table' => 'subscription_payments',
                'sql'   => 'SELECT * FROM subscription_payments WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 150,
            ],
            'analytics' => [
                'label' => 'Analytics events',
                'table' => 'analytics_events',
                'sql'   => 'SELECT * FROM analytics_events WHERE tenant_id = {tid} ORDER BY id ASC',
                'strip' => [],
                'order' => 160,
            ],
        ];
    }
}

if (!function_exists('tenant_backup_collect')) {
    /**
     * Run every section for one workspace.
     *
     * @return array { sections: [key => rows], labels: [key => label],
     *                 records: int, tables: int, truncated: [key => bool],
     *                 skipped: [key => table] }
     */
    function tenant_backup_collect($conn, $tenant_id)
    {
        $tenant_id = (int) $tenant_id;
        $sections  = tenant_backup_sections();
        $out       = [
            'sections'  => [],
            'labels'    => [],
            'records'   => 0,
            'tables'    => 0,
            'truncated' => [],
            'skipped'   => [],
        ];

        uasort($sections, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        foreach ($sections as $key => $section) {
            if (!sa_table_exists($conn, $section['table'])) {
                $out['skipped'][$key] = $section['table'];
                continue;   // an old install without that table simply skips it
            }

            $sql = str_replace('{tid}', (string) $tenant_id, $section['sql']);
            $rows = sa_query($conn, $sql);

            $truncated = false;
            if (count($rows) > TENANT_BACKUP_ROW_CAP) {
                $rows      = array_slice($rows, 0, TENANT_BACKUP_ROW_CAP);
                $truncated = true;
            }
            foreach ($rows as $i => $row) {
                foreach ($section['strip'] as $column) {
                    unset($rows[$i][$column]);
                }
            }

            $out['sections'][$key]               = array_values($rows);
            $out['labels'][$key]                 = $section['label'];
            $out['truncated'][$key]              = $truncated;
            $out['records']                     += count($rows);
            $out['tables']++;
        }

        return $out;
    }
}

if (!function_exists('tenant_backup_payload')) {
    /** Assemble the JSON document that goes into the backup file. */
    function tenant_backup_payload($conn, $tenant_id, $collected, $generated_by = '')
    {
        $tenant_id = (int) $tenant_id;
        $profile   = sa_one($conn, 'SELECT * FROM tenants WHERE id = ' . $tenant_id . ' LIMIT 1', 'tenants');
        $profile_row = $profile ?: [];

        foreach (['password', 'setup_token', 'setup_token_expires'] as $secret) {
            unset($profile_row[$secret]);
        }

        $sections = [];
        foreach ($collected['sections'] as $key => $rows) {
            $sections[$key] = [
                'label'     => isset($collected['labels'][$key]) ? $collected['labels'][$key] : $key,
                'records'   => count($rows),
                'truncated' => !empty($collected['truncated'][$key]),
                'rows'      => $rows,
            ];
        }

        return [
            'format'       => 'optibiz-workspace-backup',
            'version'      => 1,
            'generated_at' => date('c'),
            'generated_by' => $generated_by,
            'workspace'    => [
                'id'           => $tenant_id,
                'public_id'    => $profile_row['public_id'] ?? null,
                'company_name' => $profile_row['company_name'] ?? ($_SESSION['tenant_name'] ?? 'Workspace'),
                'email'        => $profile_row['email'] ?? null,
            ],
            'totals'       => [
                'sections' => count($sections),
                'records'  => $collected['records'],
            ],
            'notes'        => [
                'Passwords, setup tokens and API secrets are intentionally excluded.',
                'Restore is done by the platform team: download this file and attach it to a support request.',
            ],
            'sections'     => $sections,
        ];
    }
}

if (!function_exists('tenant_backup_store')) {
    /**
     * Write the payload to disk (gzipped when possible) and register
     * the file in `tenant_backups`.
     *
     * @return array {ok: bool, message: string, id: int, filename: string, size: int, records: int}
     */
    function tenant_backup_store($conn, $tenant_id, $payload, $created_by_label = '')
    {
        $tenant_id = (int) $tenant_id;
        $dir       = tenant_backup_dir($tenant_id);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'The backup folder could not be created on the server.'];
        }

        $json     = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'message' => 'The workspace data could not be encoded.'];
        }

        $gzip     = function_exists('gzencode');
        $filename = 'workspace_' . $tenant_id . '_' . date('Y-m-d_His') . ($gzip ? '.json.gz' : '.json');
        $path     = $dir . '/' . $filename;
        $bytes    = $gzip ? gzencode($json, 6) : $json;

        if (@file_put_contents($path, $bytes) === false) {
            return ['ok' => false, 'message' => 'The backup file could not be written to disk.'];
        }

        $size    = (int) filesize($path);
        $records = (int) ($payload['totals']['records'] ?? 0);
        $tables  = (int) ($payload['totals']['sections'] ?? 0);
        $format  = $gzip ? 'json.gz' : 'json';

        $stmt = @$conn->prepare(
            'INSERT INTO tenant_backups
                (tenant_id, filename, format, size_bytes, table_count, record_count, created_by_label, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        if (!$stmt) {
            @unlink($path);
            return ['ok' => false, 'message' => 'The backup could not be registered in the database.'];
        }
        $stmt->bind_param('issiiis', $tenant_id, $filename, $format, $size, $tables, $records, $created_by_label);
        $ok = @$stmt->execute();
        $id = (int) ($stmt->insert_id ?: ($conn->insert_id ?? 0));
        $stmt->close();

        if (!$ok) {
            @unlink($path);
            return ['ok' => false, 'message' => 'The backup could not be registered in the database.'];
        }

        return [
            'ok'       => true,
            'message'  => 'Backup created: ' . $filename,
            'id'       => $id,
            'filename' => $filename,
            'size'     => $size,
            'records'  => $records,
            'tables'   => $tables,
        ];
    }
}

if (!function_exists('tenant_backup_list')) {
    /** Registered backups for one workspace, newest first. */
    function tenant_backup_list($conn, $tenant_id, $limit = 50)
    {
        $tenant_id = (int) $tenant_id;
        $limit     = max(1, (int) $limit);
        return sa_query(
            $conn,
            'SELECT * FROM tenant_backups WHERE tenant_id = ' . $tenant_id . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit,
            'tenant_backups'
        );
    }
}

if (!function_exists('tenant_backup_find')) {
    /** One registered backup, but only when it belongs to the tenant. */
    function tenant_backup_find($conn, $tenant_id, $id)
    {
        $tenant_id = (int) $tenant_id;
        $id        = (int) $id;
        if ($id <= 0 || $tenant_id <= 0) {
            return [];
        }
        return sa_one(
            $conn,
            'SELECT * FROM tenant_backups WHERE id = ' . $id . ' AND tenant_id = ' . $tenant_id . ' LIMIT 1',
            'tenant_backups'
        );
    }
}

if (!function_exists('tenant_backup_path')) {
    /** Absolute path of a registered backup, or '' when it is not on disk. */
    function tenant_backup_path($row)
    {
        if (empty($row['filename']) || empty($row['tenant_id'])) {
            return '';
        }
        $dir  = tenant_backup_dir($row['tenant_id']);
        $name = basename((string) $row['filename']);
        $path = $dir . '/' . $name;

        $real_dir = realpath($dir);
        $real     = realpath($path);
        if ($real_dir === false || $real === false || strpos($real, $real_dir) !== 0) {
            return '';   // missing, or pointing outside the tenant's own folder
        }
        return $real;
    }
}

if (!function_exists('tenant_backup_delete')) {
    /** Remove one backup (file + registration). False when it is not ours. */
    function tenant_backup_delete($conn, $tenant_id, $id)
    {
        $tenant_id = (int) $tenant_id;
        $id        = (int) $id;
        $row       = tenant_backup_find($conn, $tenant_id, $id);
        if (!$row) {
            return false;
        }

        $path = tenant_backup_path($row);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }

        $stmt = @$conn->prepare('DELETE FROM tenant_backups WHERE id = ? AND tenant_id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $id, $tenant_id);
        $ok = @$stmt->execute();
        $stmt->close();

        return (bool) $ok;
    }
}

if (!function_exists('tenant_backup_summary')) {
    /** Totals for the dashboard cards of admin/backups.php. */
    function tenant_backup_summary($rows)
    {
        $size    = 0;
        $records = 0;
        $latest  = '';
        foreach ($rows as $row) {
            $size    += (int) ($row['size_bytes'] ?? 0);
            $records += (int) ($row['record_count'] ?? 0);
            if ($latest === '' || strcmp((string) $row['created_at'], $latest) > 0) {
                $latest = (string) $row['created_at'];
            }
        }
        return [
            'count'    => count($rows),
            'size'     => $size,
            'records'  => $records,
            'latest'   => $latest,
        ];
    }
}

if (!function_exists('tenant_backup_format_bytes')) {
    /** 1.4 MB / 812 KB / 96 B */
    function tenant_backup_format_bytes($bytes)
    {
        $bytes = (float) $bytes;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return (int) $bytes . ' B';
    }
}
