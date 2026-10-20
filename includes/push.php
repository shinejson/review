<?php
/**
 * Customer push notifications and workspace review alerts.
 *
 * Two delivery channels:
 *   1. In-app inbox — every app user gets a durable row in `app_notifications`
 *      so nothing is lost when a device is offline or push is disabled.
 *   2. FCM HTTP v1 — attempted for every registered device token when the
 *      service account is configured. Unconfigured means "inbox only", not
 *      an error.
 *
 * Business-facing low-review alerts go through the existing workspace
 * notifications table (`includes/notifications.php`), which the admin bell
 * already reads. A dedicated staff app would register its own devices.
 */
require_once __DIR__ . '/app_auth.php';

if (!function_exists('push_ensure_schema')) {
    function push_ensure_schema($conn)
    {
        static $done = false;
        if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
            return;
        }
        $done = true;
        @$conn->query("CREATE TABLE IF NOT EXISTS app_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'system',
            title VARCHAR(190) NOT NULL,
            body VARCHAR(500) NULL,
            link VARCHAR(255) NULL,
            data_json TEXT NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_app_notif_user (user_id, read_at, created_at),
            CONSTRAINT fk_app_notif_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('push_fcm_config')) {
    /**
     * Service-account credentials, read from
     *   OPTIBIZ_FCM_SERVICE_JSON       path to the JSON key file
     *   OPTIBIZ_FCM_SERVICE_JSON_RAW   the JSON itself
     * Both are deliberately outside the web root / git.
     */
    function push_fcm_config()
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }
        $config = [];
        $raw = '';
        $path = getenv('OPTIBIZ_FCM_SERVICE_JSON');
        if ($path && is_readable($path)) {
            $raw = (string) @file_get_contents($path);
        }
        if ($raw === '') {
            $raw = (string) getenv('OPTIBIZ_FCM_SERVICE_JSON_RAW');
        }
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['client_email']) && !empty($decoded['private_key'])) {
                $config = $decoded;
            }
        }
        return $config;
    }
}

if (!function_exists('push_fcm_access_token')) {
    function push_fcm_access_token()
    {
        static $token = null;
        static $expires = 0;
        if ($token !== null && time() < $expires - 60) {
            return $token;
        }
        $config = push_fcm_config();
        if (empty($config)) {
            return '';
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $config['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $config['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $b64 = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };
        $unsigned = $b64(json_encode($header)) . '.' . $b64(json_encode($claims));

        $key = @openssl_pkey_get_private((string) $config['private_key']);
        if (!$key || !@openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return '';
        }
        $assertion = $unsigned . '.' . $b64($signature);

        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $body,
                'timeout'       => 6,
                'ignore_errors' => true,
            ],
        ]);
        $url = $claims['aud'];
        $response = @file_get_contents($url, false, $ctx);
        if (!$response) {
            return '';
        }
        $decoded = json_decode($response, true);
        if (empty($decoded['access_token'])) {
            return '';
        }
        $token = (string) $decoded['access_token'];
        $expires = $now + (int) ($decoded['expires_in'] ?? 3600);
        return $token;
    }
}

if (!function_exists('push_fcm_send')) {
    /**
     * @return array{sent:int,failed:int,skipped:bool}
     */
    function push_fcm_send(array $tokens, $title, $body, array $data = [])
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => true];
        $config = push_fcm_config();
        if (empty($config) || empty($tokens)) {
            return $result;
        }
        $access = push_fcm_access_token();
        if ($access === '') {
            return $result;
        }
        $result['skipped'] = false;
        $project = (string) ($config['project_id'] ?? '');
        if ($project === '') {
            $result['skipped'] = true;
            return $result;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($project) . '/messages:send';
        $tokens = array_slice(array_values(array_unique(array_filter($tokens))), 0, 25);

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token'        => $token,
                    'notification' => ['title' => (string) $title, 'body' => (string) $body],
                    'android'      => ['priority' => 'high', 'notification' => ['channel_id' => 'reviews']],
                    'apns'         => ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => ['sound' => 'default']]],
                ],
            ];
            if (!empty($data)) {
                $payload['message']['data'] = array_map('strval', $data);
            }
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Authorization: Bearer {$access}\r\nContent-Type: application/json\r\n",
                    'content'       => json_encode($payload),
                    'timeout'       => 6,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $ctx);
            $success = false;
            if ($response !== false) {
                $decoded = json_decode($response, true);
                $success = isset($decoded['name']);
                $status = 0;
                if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) {
                    $status = (int) $m[1];
                }
                if (!$success && in_array($status, [404, 410], true)) {
                    push_disable_token($token);
                }
            }
            $success ? $result['sent']++ : $result['failed']++;
        }
        return $result;
    }
}

if (!function_exists('push_disable_token')) {
    function push_disable_token($token)
    {
        global $conn;
        if (!is_object($conn) || !method_exists($conn, 'prepare') || $token === '') {
            return;
        }
        $stmt = @$conn->prepare("UPDATE app_devices SET push_enabled = 0 WHERE fcm_token = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $token);
            @$stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('push_user')) {
    /**
     * Durable inbox row + best-effort FCM delivery.
     */
    function push_user($conn, $user_id, $type, $title, $body, $link = '', array $data = [])
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !is_object($conn)) {
            return 0;
        }
        push_ensure_schema($conn);

        $stmt = $conn->prepare("INSERT INTO app_notifications (user_id, type, title, body, link, data_json) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return 0;
        }
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $type)) ?: 'system';
        $title = function_exists('mb_substr') ? mb_substr((string) $title, 0, 190) : substr((string) $title, 0, 190);
        $body = function_exists('mb_substr') ? mb_substr((string) $body, 0, 500) : substr((string) $body, 0, 500);
        $link = $link !== '' ? substr((string) $link, 0, 255) : null;
        $json = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
        $stmt->bind_param('isssss', $user_id, $type, $title, $body, $link, $json);
        $ok = $stmt->execute();
        $id = $ok ? (int) $stmt->insert_id : 0;
        $stmt->close();

        if ($id > 0) {
            $tokens = [];
            $stmt = $conn->prepare("SELECT fcm_token FROM app_devices WHERE user_id = ? AND push_enabled = 1 AND fcm_token IS NOT NULL");
            if ($stmt) {
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    if (!empty($row['fcm_token'])) {
                        $tokens[] = (string) $row['fcm_token'];
                    }
                }
                $stmt->close();
            }
            if ($tokens) {
                push_fcm_send($tokens, $title, $body, $data + ['type' => $type, 'link' => (string) $link]);
            }
        }
        return $id;
    }
}

if (!function_exists('push_notify_review_reply')) {
    function push_notify_review_reply($conn, $user_id, $rating_id, $company_name)
    {
        $company_name = trim((string) $company_name) !== '' ? (string) $company_name : 'The business';
        return push_user(
            $conn,
            (int) $user_id,
            'review_reply',
            $company_name . ' replied to your review',
            'Open the app to read the reply and continue the conversation.',
            '/review/' . (int) $rating_id,
            ['rating_id' => (int) $rating_id]
        );
    }
}

if (!function_exists('push_notify_new_low_review')) {
    /**
     * Workspace alert for a 1–2 star review. Mirrors into the admin bell via
     * the shared notifications table so the owner sees it on their next
     * screen open even if push delivery fails.
     */
    function push_notify_new_low_review($conn, $tenant_id, $company_id, $rating_id, $rating, $company_name = '')
    {
        $tenant_id = (int) $tenant_id;
        $rating = (int) $rating;
        if ($tenant_id <= 0 || $rating < 1) {
            return 0;
        }
        $notifications = __DIR__ . '/notifications.php';
        if (!function_exists('notifications_add') && is_file($notifications)) {
            require_once $notifications;
        }
        if (!function_exists('notifications_add')) {
            return 0;
        }
        return notifications_add($conn, [
            'audience'   => 'tenant',
            'tenant_id'  => $tenant_id,
            'type'       => 'review_critical',
            'title'      => $rating . '★ review needs your attention',
            'message'    => trim((string) $company_name) !== ''
                ? $company_name . ' received a ' . $rating . '★ review. Reply quickly — a fast answer often keeps the customer.'
                : 'A new ' . $rating . '★ review needs a reply.',
            'link'       => 'ratings.php?review=' . (int) $rating_id . '&focus=moderation',
            'tone'       => $rating <= 2 ? 'danger' : 'warning',
            'entity'     => ['rating', (int) $rating_id],
            'dedupe_key' => 'low-review-' . (int) $rating_id,
        ]);
    }
}
