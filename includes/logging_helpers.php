<?php
/**
 * ============================================================
 *  Activity logging — shared helpers
 * ============================================================
 *  One table (`system_logs`) records what happens in the
 *  platform, and these helpers are the only writers/readers:
 *
 *    - sa_log_activity() / sa_log_write()  … write an event
 *    - admin_log_activity()                … write a *workspace*
 *                                            event in one line
 *    - sa_get_logs() / sa_get_logs_count() … read them back
 *    - sa_get_log_actions()                … filter options
 *    - sa_export_logs_csv()                … download
 *
 *  Every row can carry a `tenant_id`, which is what lets a
 *  workspace see its own history (admin/logs.php) while the
 *  platform owner still sees everything, per tenant
 *  (superadmin/logs.php).
 *
 *  The helpers are defensive: an install that has not run the
 *  migration yet simply gets an empty list instead of a fatal.
 */

require_once __DIR__ . '/sa_helpers.php';

/* ============================================================
   Schema
   ============================================================ */

if (!function_exists('sa_logs_ensure_schema')) {
    /**
     * Make sure `system_logs` has the tenant column. Runs at most
     * once per request and never throws (a locked-down database
     * falls back to platform-wide logging).
     */
    function sa_logs_ensure_schema($conn)
    {
        static $done = false;
        if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
            return;
        }
        $done = true;

        $cols = @$conn->query('SHOW COLUMNS FROM system_logs');
        if (!$cols) {
            return; // table not installed yet
        }
        $names = [];
        while ($row = $cols->fetch_assoc()) {
            if (isset($row['Field'])) {
                $names[] = $row['Field'];
            }
        }
        $cols->close();

        if (!in_array('tenant_id', $names, true)) {
            @$conn->query(
                'ALTER TABLE system_logs
                    ADD COLUMN tenant_id INT NULL AFTER portal,
                    ADD INDEX idx_logs_tenant (tenant_id)'
            );
        }
    }
}

/* ============================================================
   Writing
   ============================================================ */

if (!function_exists('sa_log_write')) {
    /**
     * Write one activity row. This is the low-level writer: the
     * caller passes the user label, so tenant workspaces (whose
     * "user" lives in `tenants`/`team_members`, not in `admins`)
     * are labelled correctly.
     *
     * @param mysqli      $conn
     * @param string      $portal       'superadmin' or 'admin'
     * @param string      $action       short machine name, e.g. 'backup_create'
     * @param string|null $description  human sentence shown in the log
     * @param string|null $entity_type  'tenant', 'rating', 'backup', …
     * @param int|null    $entity_id
     * @param int|null    $tenant_id    workspace the event belongs to
     * @param int|null    $user_id      id inside the portal
     * @param string|null $user_label   display name for that user
     * @return bool
     */
    function sa_log_write($conn, $portal, $action, $description = null, $entity_type = null, $entity_id = null, $tenant_id = null, $user_id = null, $user_label = null)
    {
        if (!$conn || !is_object($conn)) {
            return false;
        }
        $portal      = ($portal === 'superadmin') ? 'superadmin' : 'admin';
        $action      = trim((string) $action);
        if ($action === '') {
            return false;
        }
        $user_id     = $user_id ? (int) $user_id : null;
        $entity_id   = $entity_id ? (int) $entity_id : null;
        $tenant_id   = $tenant_id ? (int) $tenant_id : null;
        $description = ($description === null || $description === '') ? null : (string) $description;
        $entity_type = ($entity_type === null || $entity_type === '') ? null : (string) $entity_type;
        $user_label  = ($user_label === null || $user_label === '') ? null : substr((string) $user_label, 0, 120);

        sa_logs_ensure_schema($conn);

        $ip    = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null;
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $stmt = @$conn->prepare(
            'INSERT INTO system_logs
                (portal, tenant_id, user_id, user_label, action, description, entity_type, entity_id, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('siissssiss', $portal, $tenant_id, $user_id, $user_label, $action, $description, $entity_type, $entity_id, $ip, $agent);
        $ok = @$stmt->execute();
        $stmt->close();

        return (bool) $ok;
    }
}

if (!function_exists('sa_log_activity')) {
    /**
     * Log an activity for a known portal user. Kept for backwards
     * compatibility: the label is looked up in `super_admins` or
     * `admins`.
     *
     * @param mysqli $conn
     * @param string $portal 'superadmin' or 'admin'
     * @param int|null $user_id
     * @param string $action
     * @param string|null $description
     * @param string|null $entity_type
     * @param int|null $entity_id
     * @param int|null $tenant_id
     * @return bool
     */
    function sa_log_activity($conn, $portal, $user_id, $action, $description = null, $entity_type = null, $entity_id = null, $tenant_id = null)
    {
        if (!$conn || !isset($portal) || !isset($action)) {
            return false;
        }

        $user_id = $user_id ? (int) $user_id : 0;
        $user_label = null;
        if ($user_id > 0) {
            $table = ($portal === 'superadmin') ? 'super_admins' : 'admins';
            $row = sa_one($conn, "SELECT username FROM {$table} WHERE id = " . $user_id, $table);
            if ($row && !empty($row['username'])) {
                $user_label = $row['username'];
            }
        }

        return sa_log_write($conn, $portal, $action, $description, $entity_type, $entity_id, $tenant_id, $user_id ?: null, $user_label);
    }
}

if (!function_exists('admin_log_activity')) {
    /**
     * Record a workspace activity from inside the admin panel.
     *
     *     admin_log_activity($conn, 'service_create', 'Added "Home Delivery"');
     *
     * The tenant and the acting user are read from the session, so
     * call sites stay one line and can never forget the tenant — the
     * thing that makes the workspace log tenant-safe.
     *
     * @param mysqli      $conn
     * @param string      $action
     * @param string|null $description
     * @param string|null $entity_type
     * @param int|null    $entity_id
     * @param int|null    $tenant_id   override (e.g. after a switch)
     * @return bool
     */
    function admin_log_activity($conn, $action, $description = null, $entity_type = null, $entity_id = null, $tenant_id = null)
    {
        if (!$conn) {
            return false;
        }

        $tenant_id = $tenant_id ? (int) $tenant_id : (int) (isset($_SESSION['tenant_id']) ? $_SESSION['tenant_id'] : 0);

        $user_id    = null;
        $user_label = null;
        if (!empty($_SESSION['team_member_id'])) {
            $user_id    = (int) $_SESSION['team_member_id'];
            $user_label = (string) ($_SESSION['team_member_name'] ?? $_SESSION['team_member_username'] ?? 'Team member');
            $user_label .= ' (team)';
        } elseif (!empty($_SESSION['tenant_id'])) {
            $user_id    = (int) $_SESSION['tenant_id'];
            $user_label = (string) ($_SESSION['tenant_name'] ?? $_SESSION['tenant_username'] ?? 'Workspace owner');
        } elseif (!empty($_SESSION['admin_id'])) {
            // Legacy platform admin signed into the workspace panel.
            $user_id    = (int) $_SESSION['admin_id'];
            $user_label = (string) ($_SESSION['admin_username'] ?? 'Platform admin');
        }

        if (!empty($_SESSION['impersonator_super_admin_id'])) {
            $user_label = 'Support session — ' . (string) ($_SESSION['impersonator_super_admin_name'] ?? 'Super Admin');
        }

        return sa_log_write($conn, 'admin', $action, $description, $entity_type, $entity_id, $tenant_id ?: null, $user_id, $user_label);
    }
}

/* ============================================================
   Reading
   ============================================================ */

if (!function_exists('sa_log_conditions')) {
    /**
     * Build the WHERE clause shared by every reader.
     *
     * Filters: portal, tenant_id, user_id, action, entity_type,
     *          entity_id, start_date, end_date, search.
     */
    function sa_log_conditions($conn, $portal = null, $filters = [])
    {
        $conditions = ["portal IN ('superadmin', 'admin')"];

        if ($portal && $portal !== 'all') {
            $conditions[] = "portal = '" . $conn->real_escape_string($portal) . "'";
        }

        if (!empty($filters['tenant_id'])) {
            $conditions[] = 'tenant_id = ' . (int) $filters['tenant_id'];
        }

        if (!empty($filters['user_id'])) {
            $conditions[] = 'user_id = ' . (int) $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $conditions[] = "action = '" . $conn->real_escape_string($filters['action']) . "'";
        }

        if (!empty($filters['entity_type'])) {
            $conditions[] = "entity_type = '" . $conn->real_escape_string($filters['entity_type']) . "'";
        }

        if (!empty($filters['user_label'])) {
            $conditions[] = "user_label = '" . $conn->real_escape_string($filters['user_label']) . "'";
        }

        if (!empty($filters['entity_id'])) {
            $conditions[] = 'entity_id = ' . (int) $filters['entity_id'];
        }

        if (!empty($filters['start_date'])) {
            $conditions[] = "DATE(created_at) >= '" . $conn->real_escape_string($filters['start_date']) . "'";
        }

        if (!empty($filters['end_date'])) {
            $conditions[] = "DATE(created_at) <= '" . $conn->real_escape_string($filters['end_date']) . "'";
        }

        if (!empty($filters['search'])) {
            $like = "'%" . $conn->real_escape_string(trim((string) $filters['search'])) . "%'";
            $conditions[] = "(action LIKE {$like} OR description LIKE {$like} OR user_label LIKE {$like})";
        }

        return implode(' AND ', $conditions);
    }
}

if (!function_exists('sa_get_logs')) {
    /**
     * Get system logs with filtering.
     */
    function sa_get_logs($conn, $portal, $filters = [])
    {
        if (!$conn) {
            return [];
        }

        $where = sa_log_conditions($conn, $portal, $filters);

        $limit  = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 100;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        $order  = (!isset($filters['order']) || $filters['order'] === 'asc') ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM system_logs
                 WHERE {$where}
                 ORDER BY created_at {$order}, id {$order}
                 LIMIT {$limit} OFFSET {$offset}";

        $result = @$conn->query($sql);
        if (!$result) {
            return [];
        }

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        return $logs;
    }
}

if (!function_exists('sa_get_logs_count')) {
    /** Count the logs matching the filters. */
    function sa_get_logs_count($conn, $portal = null, $filters = [])
    {
        if (!$conn) {
            return 0;
        }

        $where = sa_log_conditions($conn, $portal, $filters);
        $sql   = "SELECT COUNT(*) as count FROM system_logs WHERE {$where}";

        $result = @$conn->query($sql);
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();
        return (int) ($row['count'] ?? 0);
    }
}

if (!function_exists('sa_get_log_latest')) {
    /** The most recent row matching the filters, or []. */
    function sa_get_log_latest($conn, $portal = null, $filters = [])
    {
        $rows = sa_get_logs($conn, $portal, array_merge($filters, ['limit' => 1, 'offset' => 0]));
        return $rows ? $rows[0] : [];
    }
}

if (!function_exists('sa_get_log_actions')) {
    /**
     * Action types that actually occurred, with counts — the
     * filter dropdown. Pass $filters to scope it to a tenant.
     */
    function sa_get_log_actions($conn, $portal = null, $filters = [], $limit = 60)
    {
        if (!$conn) {
            return [];
        }

        $where = sa_log_conditions($conn, $portal, $filters);
        $limit = max(1, (int) $limit);
        $sql   = "SELECT action, COUNT(*) as count FROM system_logs
                   WHERE {$where}
                   GROUP BY action ORDER BY count DESC, action ASC LIMIT {$limit}";

        $result = @$conn->query($sql);
        if (!$result) {
            return [];
        }

        $actions = [];
        while ($row = $result->fetch_assoc()) {
            $actions[] = $row;
        }
        return $actions;
    }
}

if (!function_exists('sa_get_log_users')) {
    /**
     * Who did things (labels + counts) — the "team member" filter
     * of the workspace log.
     */
    function sa_get_log_users($conn, $portal = null, $filters = [], $limit = 25)
    {
        if (!$conn) {
            return [];
        }

        $where = sa_log_conditions($conn, $portal, $filters);
        $limit = max(1, (int) $limit);
        $sql   = "SELECT user_label, COUNT(*) as count FROM system_logs
                   WHERE {$where} AND user_label IS NOT NULL AND user_label <> ''
                   GROUP BY user_label ORDER BY count DESC, user_label ASC LIMIT {$limit}";

        $result = @$conn->query($sql);
        if (!$result) {
            return [];
        }

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        return $users;
    }
}

if (!function_exists('sa_log_label')) {
    /** Friendly name for an action key ('backup_create' → 'Backup created'). */
    function sa_log_label($action)
    {
        static $labels = [
            'sign_in'            => 'Signed in',
            'sign_out'           => 'Signed out',
            'sessions_revoked'   => 'Signed out other sessions',
            'profile_update'     => 'Workspace profile updated',
            'password_change'    => 'Password changed',
            'branch_create'      => 'Branch created',
            'branch_update'      => 'Branch updated',
            'service_create'     => 'Service added',
            'service_update'     => 'Service updated',
            'service_toggle'     => 'Service visibility changed',
            'service_delete'     => 'Service deleted',
            'team_create'        => 'Team member added',
            'team_update'        => 'Team member updated',
            'team_toggle'        => 'Team member enabled/disabled',
            'team_delete'        => 'Team member removed',
            'plan_request'       => 'Plan change requested',
            'plan_request_cancel'=> 'Plan change request withdrawn',
            'auto_renew'         => 'Auto-renew changed',
            'company_create'     => 'Company profile created',
            'company_update'     => 'Company profile updated',
            'review_create'      => 'Review added',
            'review_reply'       => 'Replied to a review',
            'review_verify'      => 'Review verification changed',
            'review_escalate'    => 'Review escalated/resolved',
            'review_delete'      => 'Review deleted',
            'question_create'    => 'Rating question added',
            'question_update'    => 'Rating question updated',
            'question_delete'    => 'Rating question deleted',
            'qa_answer'          => 'Answered a question',
            'qa_faq'             => 'FAQ published',
            'qa_pin'             => 'Question pinned/unpinned',
            'qa_delete'          => 'Question deleted',
            'social_connect'     => 'Social account connected',
            'social_disconnect'  => 'Social account disconnected',
            'social_publish'     => 'Post published',
            'social_draft'       => 'Post saved as draft',
            'social_delete'      => 'Post deleted',
            'ads_pixels'         => 'Ad tracking updated',
            'ads_test_event'     => 'Ad test event sent',
            'payment_start'      => 'Online payment started',
            'payment_declared'   => 'Payment reported',
            'backup_create'      => 'Backup created',
            'backup_download'    => 'Backup downloaded',
            'backup_delete'      => 'Backup deleted',
        ];

        if (isset($labels[$action])) {
            return $labels[$action];
        }
        return ucfirst(str_replace('_', ' ', (string) $action));
    }
}

if (!function_exists('sa_export_logs_csv')) {
    /**
     * Stream the matching logs back as a CSV download.
     */
    function sa_export_logs_csv($conn, $portal, $filters = [], $filename = 'activity_logs.csv')
    {
        if (!$conn) {
            return false;
        }

        $logs = sa_get_logs($conn, $portal, array_merge($filters, ['limit' => 5000, 'offset' => 0, 'order' => 'desc']));
        if (empty($logs)) {
            return false;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'ID', 'Portal', 'Tenant ID', 'User', 'Action', 'Action label', 'Description',
            'Entity Type', 'Entity ID', 'IP Address', 'User Agent', 'Created At'
        ]);

        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['portal'],
                $log['tenant_id'] ?? '',
                $log['user_label'] ?? 'System',
                $log['action'],
                sa_log_label($log['action']),
                $log['description'] ?? '',
                $log['entity_type'] ?? '',
                $log['entity_id'] ?? '',
                $log['ip_address'] ?? '',
                $log['user_agent'] ?? '',
                $log['created_at'],
            ]);
        }

        fclose($output);
        return true;
    }
}
