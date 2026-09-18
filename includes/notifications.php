<?php
/**
 * ============================================================
 *  Notifications — one inbox, two audiences
 * ============================================================
 *  Every notice lives in the `notifications` table and belongs to
 *  exactly one audience:
 *
 *    · audience = 'platform'  → the control center bell (superadmin/)
 *    · audience = 'tenant'    → the workspace bell (admin/), one row
 *                               per `tenant_id`, so a workspace can
 *                               never read another workspace's inbox.
 *
 *  Nothing in the app has to remember to "send" a notification:
 *  notifications_sync() looks at the live tables (reviews, Q&A,
 *  invoices, quote requests, subscriptions …), files what is new and
 *  retires what has since been handled. Because every row carries a
 *  `dedupe_key`, running the sync again never doubles up, and a
 *  dismissed notice stays dismissed. The sync is throttled to once
 *  every NOTIFICATIONS_SYNC_EVERY seconds per audience, so a page
 *  render stays cheap.
 *
 *  A module that wants to announce something right away can call
 *  notifications_add() directly — see the writers in api/ and
 *  superadmin/*.php. Both paths end in the same table.
 *
 *  Reading is one place too: notifications_fetch() / notifications_count()
 *  take the same $args (audience, tenant_id, status, type, search,
 *  dates, limit, offset), so the bell dropdown and the full inbox page
 *  cannot drift apart. The pages then own the read state:
 *  notifications_set_read(), notifications_mark_all_read(),
 *  notifications_remove() and notifications_purge_read().
 *
 *  Like the other shared helpers this file is defensive: a missing
 *  table, a column an old install never migrated, or a database that
 *  refuses DDL yields an empty inbox instead of a fatal error.
 *
 *  Requires: config/database.php ($conn), includes/sa_helpers.php.
 *  The workspace module keys (teamHasAccess) and the control center
 *  permission keys (sa_can) are optional — they are honoured when the
 *  session provides them.
 */

require_once __DIR__ . '/sa_helpers.php';

/** How often a panel may re-scan the live tables (seconds). */
if (!defined('NOTIFICATIONS_SYNC_EVERY')) {
    define('NOTIFICATIONS_SYNC_EVERY', 90);
}

/** How far back a sync looks for events worth filing (days). */
if (!defined('NOTIFICATIONS_LOOKBACK_DAYS')) {
    define('NOTIFICATIONS_LOOKBACK_DAYS', 30);
}

/* ============================================================
   Schema
   ============================================================ */

if (!function_exists('notifications_ensure_schema')) {
    /**
     * Create `notifications` when it is missing. Runs at most once per
     * request, never throws, and remembers success for the session —
     * the same self-healing pattern as admin_ensure_schema().
     */
    function notifications_ensure_schema($conn)
    {
        static $done = false;
        if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
            return false;
        }
        $done = true;

        if (function_exists('sa_ensure_platform_feedback_schema')) {
            sa_ensure_platform_feedback_schema($conn);
        }

        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['notifications_schema_ok'])) {
            return true;
        }

        $ok = (bool) @$conn->query(
            "CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                audience VARCHAR(20) NOT NULL DEFAULT 'tenant' COMMENT 'platform (control center) | tenant (workspace)',
                tenant_id INT NOT NULL DEFAULT 0 COMMENT '0 for platform-wide rows',
                type VARCHAR(40) NOT NULL,
                title VARCHAR(190) NOT NULL,
                message VARCHAR(500) NULL,
                link VARCHAR(255) NULL COMMENT 'relative to the panel that owns the row',
                icon VARCHAR(30) NULL,
                tone VARCHAR(20) NOT NULL DEFAULT 'info' COMMENT 'info | success | warning | danger',
                entity_type VARCHAR(40) NULL,
                entity_id INT NULL,
                dedupe_key VARCHAR(190) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME NULL,
                deleted_at DATETIME NULL COMMENT 'dismissed rows are kept so the sync cannot resurrect them',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_notif_dedupe (dedupe_key),
                INDEX idx_notif_scope (audience, tenant_id, is_read, created_at),
                INDEX idx_notif_type (type),
                INDEX idx_notif_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if ($ok && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['notifications_schema_ok'] = 1;
        }
        return $ok;
    }
}

/* ============================================================
   Catalogue: what a type looks like
   ============================================================ */

if (!function_exists('notifications_type_catalog')) {
    /**
     * Every notification kind the app files.
     *
     *   label       chip text
     *   icon        sa_icon() name (also drawn by notifications_icon())
     *   tone        info | success | warning | danger
     *   audiences   who may see it
     *   sa          super admin permission key that gates the type
     *   team        workspace module key that gates the type
     */
    function notifications_type_catalog()
    {
        return [
            'review_new'           => ['label' => 'New review',            'icon' => 'star',      'tone' => 'success', 'audiences' => ['tenant'],                              'sa' => null,          'team' => 'ratings'],
            'review_critical'      => ['label' => 'Critical review',       'icon' => 'alert',     'tone' => 'danger',  'audiences' => ['tenant'],                              'sa' => null,          'team' => 'ratings'],
            'review_reply'         => ['label' => 'Reply due',             'icon' => 'message',   'tone' => 'warning', 'audiences' => ['tenant'],                              'sa' => null,          'team' => 'ratings'],
            'review_escalated'     => ['label' => 'Escalated review',      'icon' => 'shield',    'tone' => 'danger',  'audiences' => ['tenant'],                              'sa' => null,          'team' => 'ratings'],
            'qa_unanswered'        => ['label' => 'Question to answer',    'icon' => 'inbox',     'tone' => 'info',    'audiences' => ['tenant'],                              'sa' => null,          'team' => 'qa'],
            'quote_new'            => ['label' => 'Quote request',         'icon' => 'inbox',     'tone' => 'warning', 'audiences' => ['platform'],                            'sa' => 'quotes',     'team' => null],
            'payment_pending'      => ['label' => 'Payment to confirm',    'icon' => 'dollar',    'tone' => 'warning', 'audiences' => ['platform', 'tenant'],                  'sa' => 'finance',    'team' => 'subscription'],
            'invoice_open'         => ['label' => 'Invoice to settle',     'icon' => 'file-text', 'tone' => 'info',    'audiences' => ['platform', 'tenant'],                  'sa' => 'finance',    'team' => 'subscription'],
            'invoice_overdue'      => ['label' => 'Overdue invoice',       'icon' => 'alert',     'tone' => 'danger',  'audiences' => ['platform', 'tenant'],                  'sa' => 'finance',    'team' => 'subscription'],
            'plan_request_pending' => ['label' => 'Plan change',           'icon' => 'layers',    'tone' => 'info',    'audiences' => ['tenant'],                              'sa' => null,          'team' => 'subscription'],
            'subscription_expiring'=> ['label' => 'Renewal due',           'icon' => 'calendar',  'tone' => 'warning', 'audiences' => ['platform', 'tenant'],                  'sa' => 'subscriptions', 'team' => 'subscription'],
            'subscription_expired' => ['label' => 'Subscription ended',    'icon' => 'alert',     'tone' => 'danger',  'audiences' => ['platform', 'tenant'],                  'sa' => 'subscriptions', 'team' => 'subscription'],
            'review_reported'      => ['label' => 'Reported review',       'icon' => 'flag',      'tone' => 'danger',  'audiences' => ['platform'],                            'sa' => 'reviews',    'team' => null],
            'tenant_new'           => ['label' => 'New workspace',         'icon' => 'building',  'tone' => 'info',    'audiences' => ['platform'],                            'sa' => 'tenants',         'team' => null],
            'tenant_setup_pending' => ['label' => 'Setup not finished',    'icon' => 'mail',      'tone' => 'warning', 'audiences' => ['platform'],                            'sa' => 'tenants',         'team' => null],
            'support_ticket_new'   => ['label' => 'New support ticket',    'icon' => 'message',   'tone' => 'warning', 'audiences' => ['platform'],                            'sa' => 'support_tickets', 'team' => null],
            'support_ticket_reply' => ['label' => 'Support reply',         'icon' => 'message',   'tone' => 'info',    'audiences' => ['tenant'],                              'sa' => null,              'team' => 'support'],
        ];
    }
}

if (!function_exists('notifications_type_meta')) {
    /** Catalogue entry for one type, with safe fallbacks. */
    function notifications_type_meta($type)
    {
        $catalog = notifications_type_catalog();
        $type = (string) $type;
        if (isset($catalog[$type])) {
            return $catalog[$type];
        }
        return ['label' => $type !== '' ? ucfirst(str_replace('_', ' ', $type)) : 'Notice',
                'icon' => 'bell', 'tone' => 'info', 'audiences' => ['platform', 'tenant'], 'sa' => null, 'team' => null];
    }
}

if (!function_exists('notifications_tone_class')) {
    /** Tone, clamped to the four the stylesheets know about. */
    function notifications_tone_class($tone)
    {
        $tone = (string) $tone;
        return in_array($tone, ['info', 'success', 'warning', 'danger'], true) ? $tone : 'info';
    }
}

if (!function_exists('notifications_icon')) {
    /**
     * Feather-style inline SVG for the workspace panel, drawn from the
     * same path data as sa_icon() so both panels look identical.
     */
    function notifications_icon($name, $size = 16)
    {
        $p = [
            'star'      => ['<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>', 'fill'],
            'message'   => ['<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', 'stroke'],
            'inbox'     => ['<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>', 'stroke'],
            'dollar'    => ['<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>', 'stroke'],
            'alert'     => ['<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>', 'stroke'],
            'shield'    => ['<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>', 'stroke'],
            'layers'    => ['<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>', 'stroke'],
            'calendar'  => ['<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>', 'stroke'],
            'file-text' => ['<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>', 'stroke'],
            'flag'      => ['<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>', 'stroke'],
            'building'  => ['<path d="M3 21h18"/><path d="M5 21V7l7-4v18"/><path d="M19 21V11l-7-4"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/>', 'stroke'],
            'mail'      => ['<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/>', 'stroke'],
            'bell'      => ['<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>', 'stroke'],
            'check'     => ['<polyline points="20 6 9 17 4 12"/>', 'stroke'],
        ];
        $key = isset($p[$name]) ? $name : 'bell';
        $fill = $p[$key][1] === 'fill' ? 'currentColor' : 'none';
        $size = (int) $size > 0 ? (int) $size : 16;
        return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="' . $fill
             . '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
             . 'aria-hidden="true">' . $p[$key][0] . '</svg>';
    }
}

/* ============================================================
   Who may see what
   ============================================================ */

if (!function_exists('notifications_allowed_types')) {
    /**
     * Type keys this session may read, for one audience.
     *
     * The control center gates a type by its permission key (a finance
     * clerk does not get billing notices) and the workspace gates it by
     * the module the team member can open. Types without a key — and
     * accounts without a permission list — see everything.
     *
     * @return string[]
     */
    function notifications_allowed_types($conn, $audience)
    {
        $audience = ($audience === 'platform') ? 'platform' : 'tenant';
        $out = [];
        foreach (notifications_type_catalog() as $key => $meta) {
            if (!in_array($audience, $meta['audiences'], true)) {
                continue;
            }
            if ($audience === 'platform' && $meta['sa'] !== null
                && function_exists('sa_can') && !sa_can($meta['sa'], $conn)) {
                continue;
            }
            if ($audience === 'tenant' && $meta['team'] !== null
                && function_exists('teamHasAccess') && !teamHasAccess($meta['team'])) {
                continue;
            }
            $out[] = $key;
        }
        return $out;
    }
}

/* ============================================================
   Writing
   ============================================================ */

if (!function_exists('notifications_str')) {
    /** Quote a value for the inline SQL these helpers build. */
    function notifications_str($conn, $value)
    {
        $value = (string) $value;
        if (is_object($conn) && method_exists($conn, 'real_escape_string')) {
            return "'" . $conn->real_escape_string($value) . "'";
        }
        return "'" . addslashes($value) . "'";
    }
}

if (!function_exists('notifications_like')) {
    /** A quoted %…% LIKE pattern, escaped the same way as every value. */
    function notifications_like($conn, $value)
    {
        $value = trim((string) $value);
        if (is_object($conn) && method_exists($conn, 'real_escape_string')) {
            $value = $conn->real_escape_string($value);
        } else {
            $value = addslashes($value);
        }
        return "'%" . $value . "%'";
    }
}

if (!function_exists('notifications_add')) {
    /**
     * File one notice.
     *
     *   notifications_add($conn, [
     *       'audience'  => 'tenant',            // or 'platform'
     *       'tenant_id' => 18,                  // required for 'tenant'
     *       'type'      => 'review_new',
     *       'title'     => 'New 5-star review',
     *       'message'   => 'Kofi rated Volta Logistics',
     *       'link'      => 'ratings.php?star=5',
     *       'entity'    => ['rating', 44],      // lets the reaper retire it
     *       'dedupe_key'=> 't:18:review_new:44',// skip when unchanged
     *       'created_at'=> '2026-09-14 10:02:11',// keep the real event time
     *   ]);
     *
     * @return int the row id, or 0 when skipped / failed
     */
    function notifications_add($conn, $args)
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return 0;
        }
        notifications_ensure_schema($conn);

        $audience = (isset($args['audience']) && $args['audience'] === 'platform') ? 'platform' : 'tenant';
        $tenant_id = isset($args['tenant_id']) ? (int) $args['tenant_id'] : 0;
        if ($audience === 'tenant' && $tenant_id <= 0) {
            return 0; // a workspace notice without a workspace would leak
        }

        $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) (isset($args['type']) ? $args['type'] : '')));
        $title = trim((string) (isset($args['title']) ? $args['title'] : ''));
        if ($type === '' || $title === '') {
            return 0;
        }
        $meta = notifications_type_meta($type);

        $message = trim((string) (isset($args['message']) ? $args['message'] : ''));
        $link = trim((string) (isset($args['link']) ? $args['link'] : ''));
        $icon = (isset($args['icon']) && $args['icon'] !== '') ? (string) $args['icon'] : $meta['icon'];
        $tone = notifications_tone_class(isset($args['tone']) && $args['tone'] !== '' ? $args['tone'] : $meta['tone']);

        $entity_type = null;
        $entity_id = null;
        if (isset($args['entity']) && is_array($args['entity'])) {
            $entity_type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) reset($args['entity'])));
            $entity_id = (int) (count($args['entity']) > 1 ? (int) next($args['entity']) : 0);
            if ($entity_type === '') {
                $entity_type = null;
            }
            if ($entity_id <= 0) {
                $entity_id = null;
            }
        }

        $dedupe = isset($args['dedupe_key']) ? trim((string) $args['dedupe_key']) : '';
        $dedupe = $dedupe !== '' ? substr($dedupe, 0, 190) : null;

        $created = isset($args['created_at']) ? trim((string) $args['created_at']) : '';

        $cols = 'audience, tenant_id, type, title, message, link, icon, tone, entity_type, entity_id, dedupe_key';
        $vals = implode(', ', [
            notifications_str($conn, $audience),
            (int) $tenant_id,
            notifications_str($conn, $type),
            notifications_str($conn, mb_substr_safe($title, 190)),
            $message !== '' ? notifications_str($conn, mb_substr_safe($message, 500)) : 'NULL',
            $link !== '' ? notifications_str($conn, mb_substr_safe($link, 255)) : 'NULL',
            notifications_str($conn, mb_substr_safe($icon, 30)),
            notifications_str($conn, $tone),
            $entity_type !== null ? notifications_str($conn, $entity_type) : 'NULL',
            $entity_id !== null ? (int) $entity_id : 'NULL',
            $dedupe !== null ? notifications_str($conn, $dedupe) : 'NULL',
        ]);
        if ($created !== '' && strtotime($created)) {
            $cols .= ', created_at';
            $vals .= ', ' . notifications_str($conn, date('Y-m-d H:i:s', strtotime($created)));
        }

        /* INSERT IGNORE + the unique dedupe_key is the whole point: the
           same event can be filed a hundred times and lands once. */
        $made = @$conn->query('INSERT IGNORE INTO notifications (' . $cols . ') VALUES (' . $vals . ')');
        if (!$made || (int) $conn->affected_rows === 0) {
            return 0;
        }
        return (int) $conn->insert_id;
    }
}

if (!function_exists('mb_substr_safe')) {
    /** Trim to a length, with or without the mbstring extension. */
    function mb_substr_safe($text, $length)
    {
        $text = (string) $text;
        if (function_exists('mb_substr') && function_exists('mb_strlen') && mb_strlen($text) > $length) {
            return mb_substr($text, 0, $length - 1) . '…';
        }
        if (strlen($text) > $length) {
            return substr($text, 0, $length - 1) . '…';
        }
        return $text;
    }
}

/* ============================================================
   Sync: look at what is happening and file what is new
   ============================================================ */

if (!function_exists('notifications_sync')) {
    /**
     * Refresh one inbox from the live data, then retire what has been
     * handled. Returns how many rows were filed.
     *
     * @param int  $tenant_id  workspace to refresh (0 for 'platform')
     * @param bool $force      ignore the throttle ("Refresh" button)
     */
    function notifications_sync($conn, $audience, $tenant_id = 0, $force = false)
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return 0;
        }
        $audience = ($audience === 'platform') ? 'platform' : 'tenant';
        $tenant_id = (int) $tenant_id;
        notifications_ensure_schema($conn);

        if (!$force) {
            $key = $audience . ':' . $tenant_id;
            $last = (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['notif_sync'][$key]))
                ? (int) $_SESSION['notif_sync'][$key]
                : 0;
            if (time() - $last < NOTIFICATIONS_SYNC_EVERY) {
                return 0;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['notif_sync'][$key] = time();
            }
        }

        $made = 0;
        if ($audience === 'platform') {
            $made = notifications_sync_platform($conn);
        } elseif ($tenant_id > 0) {
            $made = notifications_sync_tenant($conn, $tenant_id);
        }

        notifications_reap($conn, $audience, $audience === 'tenant' ? $tenant_id : 0);

        return $made;
    }
}

if (!function_exists('notifications_lookback')) {
    /** "created_at >= …" fragment for the sync window. */
    function notifications_lookback($days = null)
    {
        $days = $days !== null ? (int) $days : 30;
        return "DATE_SUB(NOW(), INTERVAL " . max(1, $days) . " DAY)";
    }
}

if (!function_exists('notifications_sync_platform')) {
    /**
     * Control center inbox: the queues the platform owner works —
     * quotes, money to confirm, subscriptions about to lapse, flagged
     * reviews and workspaces that never finished setup.
     *
     * Each scan is guarded by sa_table_exists() so an install that has
     * not run a migration simply skips that source.
     *
     * @return int rows filed
     */
    function notifications_sync_platform($conn)
    {
        $made = 0;
        $window = notifications_lookback(NOTIFICATIONS_LOOKBACK_DAYS);

        /* 1 — quote requests still sitting in the pipeline */
        if (sa_table_exists($conn, 'quote_requests')) {
            $rows = sa_query($conn,
                "SELECT id, public_id, company_name, contact_person, created_at
                   FROM quote_requests
                  WHERE status = 'pending' AND created_at >= {$window}
                  ORDER BY created_at DESC LIMIT 25",
                'quote_requests');
            foreach ($rows as $q) {
                if (empty($q['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $q += ['public_id' => '', 'contact_person' => '', 'company_name' => '', 'created_at' => ''];
                $ref = !empty($q['public_id']) ? $q['public_id'] : ('#' . (int) $q['id']);
                $who = trim((string) (isset($q['contact_person']) ? $q['contact_person'] : ''));
                $made += notifications_add($conn, [
                    'audience'   => 'platform',
                    'type'       => 'quote_new',
                    'title'      => 'New quote request ' . $ref,
                    'message'    => trim((string) $q['company_name'] . ($who !== '' ? ' — asked by ' . $who : '')),
                    'link'       => 'quote_requests.php?id=' . (int) $q['id'],
                    'entity'     => ['quote_request', (int) $q['id']],
                    'dedupe_key' => 'p:quote_new:' . (int) $q['id'],
                    'created_at' => isset($q['created_at']) ? $q['created_at'] : '',
                ]) ? 1 : 0;
            }
        }

        /* 2 — money a gateway captured but nobody has confirmed */
        if (sa_table_exists($conn, 'subscription_payments')) {
            $rows = sa_query($conn,
                "SELECT id, tenant_id, receipt_number, amount, currency, payer_name, created_at
                   FROM subscription_payments
                  WHERE status = 'pending' AND created_at >= {$window}
                  ORDER BY created_at DESC LIMIT 25",
                'subscription_payments');
            foreach ($rows as $p) {
                if (empty($p['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $p += ['receipt_number' => '', 'payer_name' => '', 'currency' => '', 'created_at' => '', 'amount' => 0];
                $who = trim((string) (isset($p['payer_name']) ? $p['payer_name'] : ''));
                $made += notifications_add($conn, [
                    'audience'   => 'platform',
                    'type'       => 'payment_pending',
                    'title'      => 'Payment waiting to be confirmed',
                    'message'    => trim('Receipt ' . (string) $p['receipt_number'] . ' for '
                        . sa_money($p['amount'])
                        . ($who !== '' ? ' from ' . $who : '')),
                    'link'       => 'finance.php#approvals',
                    'tenant_id'  => (int) $p['tenant_id'],
                    'entity'     => ['subscription_payment', (int) $p['id']],
                    'dedupe_key' => 'p:payment_pending:' . (int) $p['id'],
                    'created_at' => isset($p['created_at']) ? $p['created_at'] : '',
                ]) ? 1 : 0;
            }
        }

        /* 3 — invoices past their due date */
        if (sa_table_exists($conn, 'payment_invoices')) {
            $rows = sa_query($conn,
                "SELECT id, tenant_id, invoice_number, total, currency, status, due_date, created_at
                   FROM payment_invoices
                  WHERE status IN ('overdue','open','processing')
                    AND due_date IS NOT NULL AND due_date < CURDATE()
                    AND created_at >= {$window}
                  ORDER BY due_date ASC LIMIT 25",
                'payment_invoices');
            foreach ($rows as $i) {
                if (empty($i['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $i += ['invoice_number' => '', 'currency' => '', 'total' => 0, 'due_date' => '', 'created_at' => ''];
                $made += notifications_add($conn, [
                    'audience'   => 'platform',
                    'type'       => 'invoice_overdue',
                    'title'      => 'Invoice overdue',
                    'message'    => 'Invoice ' . (string) $i['invoice_number'] . ' for '
                        . sa_money($i['total']) . ' was due ' . sa_date($i['due_date']),
                    'link'       => 'finance.php?invoice=' . (int) $i['id'],
                    'tenant_id'  => (int) $i['tenant_id'],
                    'entity'     => ['payment_invoice', (int) $i['id']],
                    'dedupe_key' => 'p:invoice_overdue:' . (int) $i['id'],
                    'created_at' => isset($i['created_at']) ? $i['created_at'] : '',
                ]) ? 1 : 0;
            }
        }

        /* 4 — subscriptions that lapse within a month, or already did */
        if (sa_table_exists($conn, 'tenants')) {
            $rows = sa_query($conn,
                "SELECT id, company_name, subscription_status, subscription_end_date
                   FROM tenants
                  WHERE subscription_status IN ('active','trial')
                    AND subscription_end_date IS NOT NULL
                    AND subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                  ORDER BY subscription_end_date ASC LIMIT 25",
                'tenants');
            foreach ($rows as $t) {
                if (empty($t['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $t += ['company_name' => '', 'subscription_end_date' => '', 'subscription_status' => ''];
                $expired = strtotime((string) $t['subscription_end_date']) < strtotime('today');
                $days = sa_days_until($t['subscription_end_date']);
                $type = $expired ? 'subscription_expired' : 'subscription_expiring';
                $made += notifications_add($conn, [
                    'audience'   => 'platform',
                    'type'       => $type,
                    'title'      => $expired
                        ? ($t['company_name'] . ' has lapsed')
                        : ($t['company_name'] . ' renews soon'),
                    'message'    => $expired
                        ? ('Subscription ended ' . sa_date($t['subscription_end_date']) . ' — ' . abs((int) $days) . ' day(s) ago')
                        : ('Ends ' . sa_date($t['subscription_end_date']) . ' — ' . (int) $days . ' day(s) left'),
                    'link'       => 'subscriptions.php?view=due',
                    'tenant_id'  => (int) $t['id'],
                    'entity'     => ['tenant', (int) $t['id']],
                    'dedupe_key' => 'p:' . $type . ':' . (int) $t['id'] . ':' . $t['subscription_end_date'],
                ]) ? 1 : 0;
            }

            /* 5 — fresh signups, and accounts stuck before setup */
            $rows = sa_query($conn,
                "SELECT id, company_name, email, created_at, email_verified_at
                   FROM tenants
                  WHERE created_at >= " . notifications_lookback(7) . "
                  ORDER BY created_at DESC LIMIT 25",
                'tenants');
            foreach ($rows as $t) {
                if (empty($t['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $t += ['company_name' => '', 'email' => '', 'created_at' => '', 'email_verified_at' => null];
                $made += notifications_add($conn, [
                    'audience'   => 'platform',
                    'type'       => 'tenant_new',
                    'title'      => 'New workspace: ' . $t['company_name'],
                    'message'    => 'Signed up ' . sa_time_ago(isset($t['created_at']) ? $t['created_at'] : '') . ' as ' . (string) $t['email'],
                    'link'       => 'tenant_details.php?id=' . (int) $t['id'],
                    'tenant_id'  => (int) $t['id'],
                    'entity'     => ['tenant', (int) $t['id']],
                    'dedupe_key' => 'p:tenant_new:' . (int) $t['id'],
                    'created_at' => isset($t['created_at']) ? $t['created_at'] : '',
                ]) ? 1 : 0;

                if (empty($t['email_verified_at'])) {
                    $made += notifications_add($conn, [
                        'audience'   => 'platform',
                        'type'       => 'tenant_setup_pending',
                        'title'      => $t['company_name'] . ' never set a password',
                        'message'    => 'The setup link was sent to ' . (string) $t['email'] . ' and has not been used yet',
                        'link'       => 'tenant_details.php?id=' . (int) $t['id'],
                        'tenant_id'  => (int) $t['id'],
                        'entity'     => ['tenant', (int) $t['id']],
                        'dedupe_key' => 'p:tenant_setup:' . (int) $t['id'] . ':' . substr((string) $t['created_at'], 0, 10),
                    ]) ? 1 : 0;
                }
            }
        }

        /* 6 — support tickets a tenant filed that still need attention */
        if (sa_table_exists($conn, 'platform_feedback')) {
            $rows = sa_query($conn,
                "SELECT f.id, f.subject, f.priority, f.created_at, f.status, f.last_reply_at,
                        t.company_name AS tname, t.id AS tid
                   FROM platform_feedback f
                   JOIN tenants t ON t.id = f.tenant_id
                  WHERE f.status IN ('open','in_progress')
                    AND (f.last_reply_by = 'tenant' OR f.last_reply_by IS NULL)
                    AND f.created_at >= {$window}
                  ORDER BY FIELD(f.priority,'urgent','high','medium','low'), f.created_at ASC
                  LIMIT 25",
                'platform_feedback');
            foreach ($rows as $f) {
                if (empty($f['id'])) continue;
                $f += ['subject' => '', 'priority' => 'medium', 'created_at' => '', 'tname' => '', 'tid' => 0, 'last_reply_at' => null];
                $made += notifications_add($conn, [
                    'audience'   => 'platform',
                    'type'       => 'support_ticket_new',
                    'title'      => 'Support ticket #T-' . (int)$f['id'] . ' awaits reply',
                    'message'    => ($f['tname'] !== '' ? $f['tname'] . ' — ' : '')
                        . mb_substr_safe((string)$f['subject'], 120),
                    'link'       => 'support.php?id=' . (int)$f['id'],
                    'tenant_id'  => (int)$f['tid'],
                    'entity'     => ['platform_feedback', (int)$f['id']],
                    'dedupe_key' => 'p:support_ticket:' . (int)$f['id'] . ':' . (string)($f['last_reply_at'] ?? $f['created_at']),
                    'tone'       => in_array((string)$f['priority'], ['urgent','high'], true) ? 'danger' : 'warning',
                    'created_at' => isset($f['created_at']) ? $f['created_at'] : '',
                ]) ? 1 : 0;
            }
        }

        /* 7 — reviews a customer reported */
        if (sa_table_exists($conn, 'ratings') && notifications_has_column($conn, 'ratings', 'reported')) {
            $rows = sa_query($conn,
                "SELECT r.id, r.rating, r.customer_name, r.created_at, c.company_name, c.tenant_id
                   FROM ratings r
                   JOIN customers c ON r.company_id = c.id
                  WHERE r.reported = 1 AND r.created_at >= {$window}
                  ORDER BY r.created_at DESC LIMIT 25",
                ['ratings', 'customers']);
            foreach ($rows as $r) {
                if (empty($r['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $r += ['customer_name' => '', 'company_name' => '', 'tenant_id' => 0, 'rating' => 0, 'created_at' => ''];
                $made += notifications_add($conn, [
                    'audience'   => 'platform',
                    'type'       => 'review_reported',
                    'title'      => 'Review reported for moderation',
                    'message'    => (string) $r['customer_name'] . ' flagged a ' . (int) $r['rating'] . '-star review on '
                        . (string) $r['company_name'],
                    'link'       => 'reviews.php?filter=reported',
                    'tenant_id'  => (int) $r['tenant_id'],
                    'entity'     => ['rating', (int) $r['id']],
                    'dedupe_key' => 'p:review_reported:' . (int) $r['id'],
                    'created_at' => isset($r['created_at']) ? $r['created_at'] : '',
                ]) ? 1 : 0;
            }
        }

        return $made;
    }
}

if (!function_exists('notifications_has_column')) {
    /**
     * Does a column exist? Cached per request. Older installs are
     * missing the columns later migrations added (ratings.reported
     * for one), and those sources are simply skipped.
     */
    function notifications_has_column($conn, $table, $column)
    {
        static $cache = [];
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return false;
        }
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $res = @$conn->query("SHOW COLUMNS FROM `" . preg_replace('/[^a-z0-9_]/i', '', $table) . "` LIKE '"
            . preg_replace('/[^a-z0-9_]/i', '', $column) . "'");
        $cache[$key] = ($res && is_object($res) && $res->num_rows > 0);
        if ($res && method_exists($res, 'close')) {
            @$res->close();
        }
        return $cache[$key];
    }
}

if (!function_exists('notifications_sync_tenant')) {
    /**
     * Workspace inbox: what this tenant's customers and billing just did.
     * Every query is filtered by the tenant — through `customers` for
     * reviews, because ratings hang off a company profile, not a tenant.
     *
     * @return int rows filed
     */
    function notifications_sync_tenant($conn, $tenant_id)
    {
        $tenant_id = (int) $tenant_id;
        if ($tenant_id <= 0 || !is_object($conn)) {
            return 0;
        }
        $made = 0;
        $prefix = 't:' . $tenant_id . ':';

        /* 1 — recent reviews: new praise, bad scores, unanswered complaints */
        if (sa_table_exists($conn, 'ratings')) {
            $rows = sa_query($conn,
                "SELECT r.id, r.rating, r.customer_name, r.comment, r.admin_reply, r.created_at,
                        r.is_escalated, r.escalation_status, c.company_name
                   FROM ratings r
                   JOIN customers c ON r.company_id = c.id
                  WHERE c.tenant_id = {$tenant_id}
                    AND r.created_at >= " . notifications_lookback(14) . "
                  ORDER BY r.created_at DESC LIMIT 40",
                ['ratings', 'customers']);
            foreach ($rows as $r) {
                if (empty($r['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $r += ['customer_name' => '', 'comment' => '', 'company_name' => '', 'created_at' => '',
                       'admin_reply' => null, 'is_escalated' => 0, 'escalation_status' => 'none', 'id' => 0];
                $id = (int) $r['id'];
                $stars = (int) $r['rating'];
                $who = (string) $r['customer_name'];
                $what = (string) (isset($r['company_name']) ? $r['company_name'] : 'your business');
                $when = isset($r['created_at']) ? $r['created_at'] : '';

                $made += notifications_add($conn, [
                    'audience'   => 'tenant',
                    'tenant_id'  => $tenant_id,
                    'type'       => $stars <= 2 ? 'review_critical' : 'review_new',
                    'title'      => $stars . '-star review from ' . $who,
                    'message'    => notifications_excerpt((string) (isset($r['comment']) ? $r['comment'] : ''), $what),
                    'link'       => $stars <= 2 ? 'ratings.php?star=' . max(1, $stars) : 'ratings.php',
                    'entity'     => ['rating', $id],
                    'dedupe_key' => $prefix . 'review:' . $id,
                    'created_at' => $when,
                ]) ? 1 : 0;

                /* a low score with no reply yet is a to-do, not news */
                $replied = trim((string) (isset($r['admin_reply']) ? $r['admin_reply'] : ''));
                if ($stars <= 3 && $replied === '' && strtotime((string) $when) < time() - 86400) {
                    $made += notifications_add($conn, [
                        'audience'   => 'tenant',
                        'tenant_id'  => $tenant_id,
                        'type'       => 'review_reply',
                        'title'      => 'A ' . $stars . '-star review is still unanswered',
                        'message'    => $who . ' wrote to ' . $what . ' a day ago — a public reply is expected',
                        'link'       => 'ratings.php?star=' . max(1, $stars),
                        'entity'     => ['rating', $id],
                        'dedupe_key' => $prefix . 'review_reply:' . $id,
                        'created_at' => $when,
                    ]) ? 1 : 0;
                }

                if (!empty($r['is_escalated']) && (isset($r['escalation_status']) ? $r['escalation_status'] : '') === 'pending') {
                    $made += notifications_add($conn, [
                        'audience'   => 'tenant',
                        'tenant_id'  => $tenant_id,
                        'type'       => 'review_escalated',
                        'title'      => 'Escalated complaint needs resolution',
                        'message'    => $who . ' asked to resolve this privately before it goes public',
                        'link'       => 'ratings.php?escalation=needs_resolution',
                        'tone'       => 'danger',
                        'entity'     => ['rating', $id],
                        'dedupe_key' => $prefix . 'review_escalated:' . $id,
                        'created_at' => $when,
                    ]) ? 1 : 0;
                }
            }
        }

        /* 2 — community questions nobody has answered */
        if (sa_table_exists($conn, 'community_questions')) {
            $rows = sa_query($conn,
                "SELECT id, customer_name, question_text, created_at
                   FROM community_questions
                  WHERE tenant_id = {$tenant_id}
                    AND (official_answer IS NULL OR official_answer = '')
                    AND created_at >= " . notifications_lookback(14) . "
                  ORDER BY created_at DESC LIMIT 20",
                'community_questions');
            foreach ($rows as $q) {
                if (empty($q['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $made += notifications_add($conn, [
                    'audience'   => 'tenant',
                    'tenant_id'  => $tenant_id,
                    'type'       => 'qa_unanswered',
                    'title'      => 'New question from ' . (string) $q['customer_name'],
                    'message'    => notifications_excerpt((string) $q['question_text'], 'your Q&A board'),
                    'link'       => 'qa.php?filter=unanswered',
                    'entity'     => ['community_question', (int) $q['id']],
                    'dedupe_key' => $prefix . 'qa:' . (int) $q['id'],
                    'created_at' => isset($q['created_at']) ? $q['created_at'] : '',
                ]) ? 1 : 0;
            }
        }

        /* 3 — a plan change waiting for the platform to approve it */
        if (sa_table_exists($conn, 'subscription_requests')) {
            $rows = sa_query($conn,
                "SELECT id, direction, created_at FROM subscription_requests
                  WHERE tenant_id = {$tenant_id} AND status = 'pending'
                  ORDER BY created_at DESC LIMIT 10",
                'subscription_requests');
            foreach ($rows as $s) {
                if (empty($s['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $s += ['direction' => 'same', 'created_at' => ''];
                $made += notifications_add($conn, [
                    'audience'   => 'tenant',
                    'tenant_id'  => $tenant_id,
                    'type'       => 'plan_request_pending',
                    'title'      => ucfirst((string) $s['direction']) . ' request is pending',
                    'message'    => 'The Optibiz team still has to approve the plan change you filed',
                    'link'       => 'subscription.php',
                    'entity'     => ['subscription_request', (int) $s['id']],
                    'dedupe_key' => $prefix . 'plan_request:' . (int) $s['id'],
                    'created_at' => isset($s['created_at']) ? $s['created_at'] : '',
                ]) ? 1 : 0;
            }
        }

        /* 4 — invoices this workspace still owes */
        if (sa_table_exists($conn, 'payment_invoices')) {
            $rows = sa_query($conn,
                "SELECT id, invoice_number, total, currency, status, due_date, created_at
                   FROM payment_invoices
                  WHERE tenant_id = {$tenant_id}
                    AND status IN ('open','processing','overdue')
                    AND created_at >= " . notifications_lookback(NOTIFICATIONS_LOOKBACK_DAYS) . "
                  ORDER BY created_at DESC LIMIT 10",
                'payment_invoices');
            foreach ($rows as $i) {
                if (empty($i['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $i += ['invoice_number' => '', 'currency' => '', 'total' => 0, 'due_date' => '', 'status' => '', 'created_at' => ''];
                $overdue = (string) $i['status'] === 'overdue'
                    || (!empty($i['due_date']) && strtotime((string) $i['due_date']) < strtotime('today'));
                $made += notifications_add($conn, [
                    'audience'   => 'tenant',
                    'tenant_id'  => $tenant_id,
                    'type'       => $overdue ? 'invoice_overdue' : 'invoice_open',
                    'title'      => $overdue ? 'Invoice ' . $i['invoice_number'] . ' is overdue' : 'Invoice ' . $i['invoice_number'] . ' is unpaid',
                    'message'    => sa_money($i['total'])
                        . (!empty($i['due_date']) ? ' — due ' . sa_date($i['due_date']) : ''),
                    'link'       => 'invoice_view.php?id=' . (int) $i['id'],
                    'entity'     => ['payment_invoice', (int) $i['id']],
                    'dedupe_key' => $prefix . 'invoice:' . (int) $i['id'],
                    'created_at' => isset($i['created_at']) ? $i['created_at'] : '',
                ]) ? 1 : 0;
            }
        }

        /* 5 — a payment sent but not yet reflected on the account */
        if (sa_table_exists($conn, 'subscription_payments')) {
            $rows = sa_query($conn,
                "SELECT id, receipt_number, amount, currency, created_at
                   FROM subscription_payments
                  WHERE tenant_id = {$tenant_id} AND status = 'pending'
                    AND created_at >= " . notifications_lookback(NOTIFICATIONS_LOOKBACK_DAYS) . "
                  ORDER BY created_at DESC LIMIT 10",
                'subscription_payments');
            foreach ($rows as $p) {
                if (empty($p['id'])) {
                    continue; // nothing to point at, nothing to file
                }
                $p += ['receipt_number' => '', 'currency' => '', 'amount' => 0, 'created_at' => ''];
                $made += notifications_add($conn, [
                    'audience'   => 'tenant',
                    'tenant_id'  => $tenant_id,
                    'type'       => 'payment_pending',
                    'title'      => 'Payment awaiting confirmation',
                    'message'    => 'Receipt ' . (string) $p['receipt_number'] . ' for '
                        . sa_money($p['amount']) . ' is with the billing team',
                    'link'       => 'subscription.php',
                    'entity'     => ['subscription_payment', (int) $p['id']],
                    'dedupe_key' => $prefix . 'payment:' . (int) $p['id'],
                    'created_at' => isset($p['created_at']) ? $p['created_at'] : '',
                ]) ? 1 : 0;
            }
        }

        /* 6 — platform replies to the workspace's support tickets */
        if (sa_table_exists($conn, 'platform_feedback')) {
            $rows = sa_query($conn,
                "SELECT id, subject, priority, replied_at, last_reply_at, last_reply_by, status
                   FROM platform_feedback
                  WHERE tenant_id = {$tenant_id}
                    AND last_reply_by = 'superadmin'
                    AND last_reply_at IS NOT NULL
                    AND last_reply_at >= " . notifications_lookback(NOTIFICATIONS_LOOKBACK_DAYS) . "
                  ORDER BY last_reply_at DESC LIMIT 10",
                'platform_feedback');
            foreach ($rows as $f) {
                if (empty($f['id'])) continue;
                $f += ['subject' => '', 'priority' => 'medium', 'replied_at' => null, 'last_reply_at' => null];
                $reply_time = !empty($f['last_reply_at']) ? $f['last_reply_at'] : $f['replied_at'];
                $made += notifications_add($conn, [
                    'audience'   => 'tenant',
                    'tenant_id'  => $tenant_id,
                    'type'       => 'support_ticket_reply',
                    'title'      => 'Platform replied to ticket #T-' . (int)$f['id'],
                    'message'    => mb_substr_safe((string)$f['subject'], 180),
                    'link'       => 'support.php?id=' . (int)$f['id'],
                    'entity'     => ['platform_feedback', (int)$f['id']],
                    'dedupe_key' => $prefix . 'support_reply:' . (int)$f['id'] . ':' . (string)$reply_time,
                    'created_at' => $reply_time ?: '',
                ]) ? 1 : 0;
            }
        }

        /* 7 — the workspace's own renewal date */
        if (sa_table_exists($conn, 'tenants')) {
            $t = sa_one($conn,
                "SELECT id, subscription_status, subscription_end_date
                   FROM tenants WHERE id = {$tenant_id} LIMIT 1",
                'tenants');
            if ($t && !empty($t['subscription_end_date'])
                && in_array((string) $t['subscription_status'], ['active', 'trial'], true)) {
                $days = sa_days_until($t['subscription_end_date']);
                $expired = $days < 0;
                if ($expired || $days <= 14) {
                    $made += notifications_add($conn, [
                        'audience'   => 'tenant',
                        'tenant_id'  => $tenant_id,
                        'type'       => $expired ? 'subscription_expired' : 'subscription_expiring',
                        'title'      => $expired
                            ? 'Your subscription ended ' . sa_date($t['subscription_end_date'])
                            : 'Renew in ' . (int) $days . ' day' . ((int) $days === 1 ? '' : 's'),
                        'message'    => $expired
                            ? 'Renew to keep collecting reviews — the public rating page is paused'
                            : 'Plan renews on ' . sa_date($t['subscription_end_date']) . '. Update your payment method in Billing if anything changed',
                        'link'       => 'subscription.php',
                        'entity'     => ['tenant', $tenant_id],
                        'dedupe_key' => $prefix . 'subscription:' . $t['subscription_end_date'],
                    ]) ? 1 : 0;
                }
            }
        }

        return $made;
    }
}

if (!function_exists('notifications_trim')) {
    /** One line, at most $length characters — for the bell dropdown. */
    function notifications_trim($text, $length = 70)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        return mb_substr_safe($text, max(10, (int) $length));
    }
}

if (!function_exists('notifications_excerpt')) {
    /** "“They fixed the van in an hour” — on your branch", or just the place. */
    function notifications_excerpt($text, $where)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        if ($text === '') {
            return 'No written comment — ' . $where;
        }
        return '“' . (strlen($text) > 120 ? substr($text, 0, 119) . '…' : $text) . '”';
    }
}

/* ============================================================
   Retiring notices whose reason has gone away
   ============================================================ */

if (!function_exists('notifications_reap')) {
    /**
     * Drop notices whose underlying condition has been resolved — a
     * review that has since been answered, a payment just confirmed, a
     * quote moved out of 'pending'. Types are only reaped when the
     * table they point at exists, and only for rows that carry an
     * entity id.
     */
    function notifications_reap($conn, $audience, $tenant_id = 0)
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return false;
        }
        $audience = ($audience === 'platform') ? 'platform' : 'tenant';
        $scope = "audience = '" . $audience . "'";
        if ($audience === 'tenant') {
            $scope .= ' AND tenant_id = ' . (int) $tenant_id;
        }

        if ($audience === 'platform') {
            $checks = [
                'quote_new'            => ['quote_requests', "SELECT 1 FROM quote_requests q WHERE q.id = n.entity_id AND q.status = 'pending'"],
                'payment_pending'      => ['subscription_payments', "SELECT 1 FROM subscription_payments p WHERE p.id = n.entity_id AND p.status = 'pending'"],
                'invoice_overdue'      => ['payment_invoices', "SELECT 1 FROM payment_invoices i WHERE i.id = n.entity_id AND i.status IN ('open','processing','overdue')"],
                'review_reported'      => ['ratings', "SELECT 1 FROM ratings r WHERE r.id = n.entity_id AND r.reported = 1"],
                'subscription_expiring'=> ['tenants', "SELECT 1 FROM tenants t WHERE t.id = n.entity_id AND t.subscription_status IN ('active','trial') AND t.subscription_end_date IS NOT NULL AND t.subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"],
                'subscription_expired' => ['tenants', "SELECT 1 FROM tenants t WHERE t.id = n.entity_id AND t.subscription_end_date IS NOT NULL AND t.subscription_end_date < CURDATE()"],
                'tenant_setup_pending' => ['tenants', "SELECT 1 FROM tenants t WHERE t.id = n.entity_id AND t.email_verified_at IS NULL"],
                'support_ticket_new'   => ['platform_feedback', "SELECT 1 FROM platform_feedback f WHERE f.id = n.entity_id AND f.status IN ('open','in_progress') AND (f.last_reply_by = 'tenant' OR f.last_reply_by IS NULL)"],
            ];
        } else {
            $checks = [
                'review_reply'      => ['ratings', "SELECT 1 FROM ratings r WHERE r.id = n.entity_id AND (r.admin_reply IS NULL OR r.admin_reply = '')"],
                'review_escalated'  => ['ratings', "SELECT 1 FROM ratings r WHERE r.id = n.entity_id AND r.is_escalated = 1 AND r.escalation_status = 'pending'"],
                'qa_unanswered'     => ['community_questions', "SELECT 1 FROM community_questions q WHERE q.id = n.entity_id AND (q.official_answer IS NULL OR q.official_answer = '')"],
                'plan_request_pending' => ['subscription_requests', "SELECT 1 FROM subscription_requests s WHERE s.id = n.entity_id AND s.status = 'pending'"],
                'payment_pending'   => ['subscription_payments', "SELECT 1 FROM subscription_payments p WHERE p.id = n.entity_id AND p.status = 'pending'"],
                'invoice_open'      => ['payment_invoices', "SELECT 1 FROM payment_invoices i WHERE i.id = n.entity_id AND i.status IN ('open','processing','overdue')"],
                'invoice_overdue'   => ['payment_invoices', "SELECT 1 FROM payment_invoices i WHERE i.id = n.entity_id AND i.status IN ('open','processing','overdue')"],
                'support_ticket_reply' => ['platform_feedback', "SELECT 1 FROM platform_feedback f WHERE f.id = n.entity_id AND f.last_reply_by = 'superadmin'"],
                'subscription_expiring' => ['tenants', "SELECT 1 FROM tenants t WHERE t.id = n.tenant_id AND t.subscription_status IN ('active','trial') AND t.subscription_end_date >= CURDATE() AND t.subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)"],
                'subscription_expired'  => ['tenants', "SELECT 1 FROM tenants t WHERE t.id = n.tenant_id AND t.subscription_end_date < CURDATE()"],
            ];
        }

        foreach ($checks as $type => $check) {
            list($table, $alive) = $check;
            if ($table === 'ratings' && $type === 'review_reported' && !notifications_has_column($conn, 'ratings', 'reported')) {
                continue;
            }
            if ($table === 'platform_feedback' && !notifications_has_column($conn, 'platform_feedback', 'last_reply_by')) {
                continue;
            }
            if (!sa_table_exists($conn, $table)) {
                continue;
            }
            try {
                @$conn->query(
                    'DELETE n FROM notifications n
                      WHERE ' . $scope . "
                        AND n.type = '" . $type . "'
                        AND n.entity_id IS NOT NULL
                        AND NOT EXISTS (" . $alive . ")"
                );
            } catch (\Throwable $e) {
                // Ignore query error if table or column is unmigrated
            }
        }

        /* tombstones older than a quarter are noise */
        @$conn->query('DELETE FROM notifications WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');

        return true;
    }
}

/* ============================================================
   Reading
   ============================================================ */

if (!function_exists('notifications_conditions')) {
    /**
     * WHERE fragment shared by every reader.
     *
     * $args: audience, tenant_id, scope_all, status (all|unread|read),
     *        type, types[], search, start, end.
     *
     * Pass scope_all only where a platform account legitimately reads
     * across workspaces (a legacy `admins` session in the workspace
     * panel). Without it, a tenant_id of 0 matches nothing.
     */
    function notifications_conditions($conn, $args = [])
    {
        $audience = (isset($args['audience']) && $args['audience'] === 'platform') ? 'platform' : 'tenant';
        $c = ["audience = '" . $audience . "'", 'deleted_at IS NULL'];

        /* Platform rows belong to the whole control center: a tenant_id
           here is a drill-down, never a boundary. Workspace rows are the
           opposite — the tenant id IS the boundary, and 0 matches nothing. */
        $tenant_id = isset($args['tenant_id']) ? (int) $args['tenant_id'] : 0;
        if ($audience === 'platform' || !empty($args['scope_all'])) {
            if ($tenant_id > 0) {
                $c[] = 'tenant_id = ' . $tenant_id;
            }
        } else {
            $c[] = 'tenant_id = ' . $tenant_id;
        }

        if (isset($args['status']) && ($args['status'] === 'unread' || $args['status'] === 'read')) {
            $c[] = 'is_read = ' . ($args['status'] === 'read' ? 1 : 0);
        }

        if (isset($args['type']) && $args['type'] !== '') {
            $c[] = 'type = ' . notifications_str($conn, $args['type']);
        }

        if (isset($args['types']) && is_array($args['types'])) {
            if (!$args['types']) {
                $c[] = '1 = 0';
            } else {
                $list = [];
                foreach ($args['types'] as $t) {
                    $list[] = notifications_str($conn, $t);
                }
                $c[] = 'type IN (' . implode(', ', $list) . ')';
            }
        }

        if (isset($args['search']) && trim((string) $args['search']) !== '') {
            $like = notifications_like($conn, $args['search']);
            $c[] = "(title LIKE " . $like . " OR message LIKE " . $like . ")";
        }

        if (!empty($args['start'])) {
            $c[] = 'DATE(created_at) >= ' . notifications_str($conn, $args['start']);
        }
        if (!empty($args['end'])) {
            $c[] = 'DATE(created_at) <= ' . notifications_str($conn, $args['end']);
        }

        return implode(' AND ', $c);
    }
}

if (!function_exists('notifications_fetch')) {
    /**
     * Read rows for an inbox.
     *
     * @param array $args  filters (see notifications_conditions) plus
     *                     limit, offset, order ('recent'|'oldest')
     * @return array[]
     */
    function notifications_fetch($conn, $args = [])
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return [];
        }
        notifications_ensure_schema($conn);

        $where = notifications_conditions($conn, $args);
        $order = (isset($args['order']) && $args['order'] === 'oldest') ? 'ASC' : 'DESC';
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 25;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        /* The whole inbox floats unread to the top; a list already narrowed
           to read or unread — or a search — simply runs newest/oldest first. */
        $status = isset($args['status']) ? $args['status'] : 'all';
        $lead = ($status === 'all' || $status === '') ? 'is_read ASC, ' : '';

        return sa_query($conn,
            'SELECT id, audience, tenant_id, type, title, message, link, icon, tone,
                    entity_type, entity_id, is_read, read_at, created_at
               FROM notifications
              WHERE ' . $where . '
              ORDER BY ' . $lead . 'created_at ' . $order . ', id ' . $order . '
              LIMIT ' . $limit . ' OFFSET ' . $offset);
    }
}

if (!function_exists('notifications_count')) {
    /** How many rows match the same filters — for the pager. */
    function notifications_count($conn, $args = [])
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return 0;
        }
        notifications_ensure_schema($conn);
        $row = sa_one($conn, 'SELECT COUNT(*) AS cnt FROM notifications WHERE '
            . notifications_conditions($conn, $args));
        return (int) (isset($row['cnt']) ? $row['cnt'] : 0);
    }
}

if (!function_exists('notifications_unread_count')) {
    /** Unread rows for this inbox — the bell badge. */
    function notifications_unread_count($conn, $audience, $tenant_id = 0, $scope_all = false)
    {
        return (int) notifications_count($conn, [
            'audience'  => $audience,
            'tenant_id' => $tenant_id,
            'scope_all' => $scope_all,
            'status'    => 'unread',
            'types'     => notifications_allowed_types($conn, $audience),
        ]);
    }
}

if (!function_exists('notifications_bell')) {
    /**
     * The most recent unread notices for the topbar dropdown.
     *
     * @return array ['items' => array[], 'unread' => int]
     */
    function notifications_bell($conn, $audience, $tenant_id = 0, $limit = 5, $scope_all = false)
    {
        $args = [
            'audience'  => $audience,
            'tenant_id' => $tenant_id,
            'scope_all' => $scope_all,
            'status'    => 'unread',
            'types'     => notifications_allowed_types($conn, $audience),
            'limit'     => max(1, (int) $limit),
        ];
        return [
            'items'  => notifications_fetch($conn, $args),
            'unread' => notifications_unread_count($conn, $audience, $tenant_id, $scope_all),
        ];
    }
}

if (!function_exists('notifications_breakdown')) {
    /**
     * Per-type counts for the filter bar:
     *   [ 'review_new' => ['total' => 12, 'unread' => 3], … ]
     */
    function notifications_breakdown($conn, $args = [])
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return [];
        }
        notifications_ensure_schema($conn);
        $rows = sa_query($conn,
            'SELECT type, COUNT(*) AS total, SUM(is_read = 0) AS unread
               FROM notifications
              WHERE ' . notifications_conditions($conn, array_diff_key($args, ['type' => 1, 'status' => 1])) . '
              GROUP BY type
              ORDER BY total DESC');
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['type']] = [
                'total'  => (int) $r['total'],
                'unread' => (int) $r['unread'],
            ];
        }
        return $out;
    }
}

if (!function_exists('notifications_find')) {
    /**
     * One row, but only when it belongs to this inbox. The tenant check
     * is what makes `?open=ID` safe to hand to a browser.
     */
    function notifications_find($conn, $id, $audience, $tenant_id = 0, $scope_all = false)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return [];
        }
        $row = sa_one($conn, 'SELECT id, audience, tenant_id, type, title, message, link, icon, tone, is_read, created_at, deleted_at
                                FROM notifications WHERE id = ' . $id . ' AND '
            . notifications_conditions($conn, [
                'audience'  => $audience,
                'tenant_id' => $tenant_id,
                'scope_all' => $scope_all,
            ]));
        return $row ?: [];
    }
}

/* ============================================================
   Read state
   ============================================================ */

if (!function_exists('notifications_scope_sql')) {
    /** "audience = … AND tenant_id = … AND id IN (…)" for a write. */
    function notifications_scope_sql($conn, $ids, $audience, $tenant_id = 0, $scope_all = false)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $ids), function ($v) {
            return $v > 0;
        }));
        if (!$ids) {
            return '';
        }
        $audience = ($audience === 'platform') ? 'platform' : 'tenant';
        $where = 'audience = \'' . $audience . "'";
        if ($audience === 'tenant' && !$scope_all) {
            $where .= ' AND tenant_id = ' . (int) $tenant_id;
        } elseif ($tenant_id > 0) {
            $where .= ' AND tenant_id = ' . (int) $tenant_id;
        }
        return $where . ' AND id IN (' . implode(', ', $ids) . ') AND deleted_at IS NULL';
    }
}

if (!function_exists('notifications_set_read')) {
    /**
     * Flip one or many rows between read and unread.
     *
     * @param int|int[] $ids
     * @return int rows changed
     */
    function notifications_set_read($conn, $ids, $read, $audience, $tenant_id = 0, $scope_all = false)
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return 0;
        }
        $where = notifications_scope_sql($conn, $ids, $audience, $tenant_id, $scope_all);
        if ($where === '') {
            return 0;
        }
        $read = (int) (bool) $read;
        @$conn->query('UPDATE notifications SET is_read = ' . $read . ', read_at = '
            . ($read ? 'NOW()' : 'NULL') . ' WHERE ' . $where);
        return (int) $conn->affected_rows;
    }
}

if (!function_exists('notifications_mark_all_read')) {
    /**
     * Clear the whole inbox, or just one type of it.
     *
     * @return int rows changed
     */
    function notifications_mark_all_read($conn, $audience, $tenant_id = 0, $scope_all = false, $type = '')
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return 0;
        }
        $args = ['audience' => $audience, 'tenant_id' => $tenant_id, 'scope_all' => $scope_all, 'status' => 'unread'];
        if ($type !== '') {
            $args['type'] = $type;
        }
        @$conn->query('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE '
            . notifications_conditions($conn, $args));
        return (int) $conn->affected_rows;
    }
}

if (!function_exists('notifications_remove')) {
    /**
     * Dismiss rows. Soft, on purpose: the tombstone keeps the
     * dedupe_key, so the sync cannot file the same thing again.
     *
     * @param int|int[] $ids
     * @return int rows dismissed
     */
    function notifications_remove($conn, $ids, $audience, $tenant_id = 0, $scope_all = false)
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return 0;
        }
        $where = notifications_scope_sql($conn, $ids, $audience, $tenant_id, $scope_all);
        if ($where === '') {
            return 0;
        }
        @$conn->query('UPDATE notifications SET deleted_at = NOW(), is_read = 1, read_at = NOW() WHERE ' . $where);
        return (int) $conn->affected_rows;
    }
}

if (!function_exists('notifications_purge_read')) {
    /**
     * Really delete read notices older than $days, so the table does
     * not grow forever. Returns how many went.
     */
    function notifications_purge_read($conn, $days = 30, $audience = null, $tenant_id = 0, $scope_all = false)
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return 0;
        }
        $args = ['tenant_id' => $tenant_id, 'scope_all' => $scope_all, 'status' => 'read'];
        if ($audience !== null) {
            $args['audience'] = $audience;
        }
        $days = max(0, (int) $days);
        @$conn->query('DELETE FROM notifications WHERE '
            . notifications_conditions($conn, $args)
            . ($days > 0 ? ' AND created_at < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)' : ''));
        return (int) $conn->affected_rows;
    }
}

/* ============================================================
   Display helpers
   ============================================================ */

if (!function_exists('notifications_tenant_names')) {
    /**
     * company_name per tenant_id for a page of platform notices, in one
     * query. Rows without a workspace (platform-wide) simply miss.
     */
    function notifications_tenant_names($conn, $rows)
    {
        $ids = [];
        foreach ($rows as $r) {
            if (!empty($r['tenant_id'])) {
                $ids[(int) $r['tenant_id']] = true;
            }
        }
        if (!$ids || !sa_table_exists($conn, 'tenants')) {
            return [];
        }
        $found = sa_query($conn, 'SELECT id, company_name FROM tenants WHERE id IN ('
            . implode(', ', array_keys($ids)) . ')', 'tenants');
        $out = [];
        foreach ($found as $t) {
            $out[(int) $t['id']] = $t['company_name'];
        }
        return $out;
    }
}

if (!function_exists('notifications_link')) {
    /**
     * Where an item points. Notices store a panel-relative link; a row
     * without one (or a type the reader may not open) returns ''.
     */
    function notifications_link($conn, $row, $audience, $tenant_id = 0, $scope_all = false)
    {
        $link = isset($row['link']) ? trim((string) $row['link']) : '';
        if ($link === '' || strpos($link, '..') !== false || preg_match('#^(https?:|//|/)#i', $link)) {
            return ''; // only ever a relative link inside the same panel
        }
        $type = isset($row['type']) ? (string) $row['type'] : '';
        if ($type !== '' && !in_array($type, notifications_allowed_types($conn, $audience), true)) {
            return '';
        }
        return $link;
    }
}

if (!function_exists('notifications_open_url')) {
    /**
     * The click-through URL: the inbox marks the row read and then hops
     * to the module, so the badge count is right on the way back.
     */
    function notifications_open_url($id, $fallback = '')
    {
        $id = (int) $id;
        if ($id <= 0) {
            return $fallback;
        }
        return 'notifications.php?open=' . $id;
    }
}
