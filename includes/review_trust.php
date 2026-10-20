<?php
/**
 * ============================================================
 *  Optibiz — Review Trust Layer
 * ============================================================
 *  Everything that lets a reader trust a review:
 *
 *    - category scores (service, cleanliness, value, speed)
 *    - QR verification tied to a business/location/visit
 *    - one review per customer per business per time window
 *    - duplicate-text, burst, device and network clustering signals
 *    - a moderation queue shared by automatic flags and user reports
 *    - photo attachments held back until a human approves them
 *
 *  Existing columns and pages keep working: a review that is not
 *  flagged is published exactly as before.
 */

require_once __DIR__ . '/functions.php';

if (!defined('OPTIBIZ_REVIEW_WINDOW_DAYS')) {
    define('OPTIBIZ_REVIEW_WINDOW_DAYS', 30);
}
if (!defined('OPTIBIZ_REVIEW_MAX_PHOTOS')) {
    define('OPTIBIZ_REVIEW_MAX_PHOTOS', 5);
}

/**
 * Add a column only when it is missing. Used for both fresh installs
 * (database.sql already declares everything) and older databases.
 */
if (!function_exists('review_trust_add_column')) {
    function review_trust_add_column($conn, $table, $column, $definition)
    {
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return;
        }
        $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
        $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return;
        }
        $check = @$conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        if ($check && (int) $check->num_rows > 0) {
            return;
        }
        @$conn->query("ALTER TABLE `{$safeTable}` ADD COLUMN `{$safeColumn}` {$definition}");
    }
}

if (!function_exists('review_trust_schema_ready')) {
    /**
     * Cheap guard for hot paths (public review listings): one SHOW COLUMNS
     * instead of the full self-healing pass. The migration is the real
     * source of truth; this only avoids paying for ~20 probes per request.
     */
    function review_trust_schema_ready($conn)
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        if (!is_object($conn) || !method_exists($conn, 'query')) {
            return $ready = false;
        }
        $col = @$conn->query("SHOW COLUMNS FROM ratings LIKE 'moderation_status'");
        $hasColumn = $col && (int) $col->num_rows > 0;
        $tbl = @$conn->query("SHOW TABLES LIKE 'review_qr_tokens'");
        $hasTable = $tbl && (int) $tbl->num_rows > 0;
        return $ready = ($hasColumn && $hasTable);
    }
}

if (!function_exists('review_trust_ensure_schema')) {
    function review_trust_ensure_schema($conn)
    {
        static $done = false;
        if ($done || !is_object($conn)) {
            return;
        }
        $done = true;
        if (review_trust_schema_ready($conn)) {
            return;
        }

        // Ratings: identity, categories, verification, abuse and moderation.
        review_trust_add_column($conn, 'ratings', 'customer_user_id', "BIGINT UNSIGNED NULL COMMENT 'app_users.id when the reviewer was signed in'");
        review_trust_add_column($conn, 'ratings', 'category_service', "TINYINT UNSIGNED NULL COMMENT '1-5, optional'");
        review_trust_add_column($conn, 'ratings', 'category_cleanliness', "TINYINT UNSIGNED NULL COMMENT '1-5, optional'");
        review_trust_add_column($conn, 'ratings', 'category_value', "TINYINT UNSIGNED NULL COMMENT '1-5, optional'");
        review_trust_add_column($conn, 'ratings', 'category_speed', "TINYINT UNSIGNED NULL COMMENT '1-5, optional'");
        review_trust_add_column($conn, 'ratings', 'qr_token_id', "BIGINT UNSIGNED NULL COMMENT 'review_qr_tokens.id for per-visit QR'");
        review_trust_add_column($conn, 'ratings', 'source', "VARCHAR(20) NOT NULL DEFAULT 'web'");
        review_trust_add_column($conn, 'ratings', 'device_hash', 'CHAR(64) NULL');
        review_trust_add_column($conn, 'ratings', 'ip_hash', 'CHAR(64) NULL');
        review_trust_add_column($conn, 'ratings', 'abuse_score', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
        review_trust_add_column($conn, 'ratings', 'abuse_flags', 'VARCHAR(255) NULL');
        review_trust_add_column($conn, 'ratings', 'moderation_status', "ENUM('published','queued','removed') NOT NULL DEFAULT 'published'");
        review_trust_add_column($conn, 'ratings', 'photos_approved', 'TINYINT(1) NOT NULL DEFAULT 0');
        review_trust_add_column($conn, 'ratings', 'photos', "TEXT NULL COMMENT 'JSON array of photo paths'");
        review_trust_add_column($conn, 'ratings', 'helpful_count', 'INT NOT NULL DEFAULT 0');

        @$conn->query("CREATE TABLE IF NOT EXISTS review_qr_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT NOT NULL DEFAULT 0,
            company_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            kind ENUM('location','visit') NOT NULL DEFAULT 'visit',
            label VARCHAR(120) NOT NULL DEFAULT '',
            staff_ref VARCHAR(120) NULL,
            max_uses INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '0 = unlimited',
            uses INT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_by_kind VARCHAR(20) NULL,
            created_by_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            UNIQUE KEY uniq_qr_hash (token_hash),
            KEY idx_qr_company (company_id),
            KEY idx_qr_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS review_moderation_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            rating_id INT NOT NULL,
            source ENUM('auto','report','business','photo') NOT NULL DEFAULT 'auto',
            reason VARCHAR(120) NOT NULL,
            details VARCHAR(500) NULL,
            status ENUM('open','approved','removed','dismissed') NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            resolved_by_kind VARCHAR(20) NULL,
            resolved_by_id BIGINT UNSIGNED NULL,
            resolution_note VARCHAR(500) NULL,
            KEY idx_mod_status (status, created_at),
            KEY idx_mod_rating (rating_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS helpful_votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rating_id INT NOT NULL,
            voter_ip VARCHAR(64) NOT NULL,
            voter_user_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_vote (rating_id, voter_ip),
            KEY idx_vote_user (voter_user_id),
            KEY idx_vote_rating (rating_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS reported_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rating_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            details VARCHAR(500) NULL,
            reporter_ip VARCHAR(45) NOT NULL,
            reporter_user_id BIGINT UNSIGNED NULL,
            status ENUM('open','actioned','dismissed') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_report_rating (rating_id),
            KEY idx_report_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        review_trust_add_column($conn, 'reported_reviews', 'details', 'VARCHAR(500) NULL');
        review_trust_add_column($conn, 'reported_reviews', 'reporter_user_id', 'BIGINT UNSIGNED NULL');
        review_trust_add_column($conn, 'reported_reviews', 'status', "ENUM('open','actioned','dismissed') NOT NULL DEFAULT 'open'");

        // Distance-based discovery needs coordinates on the business profile.
        review_trust_add_column($conn, 'customers', 'latitude', 'DECIMAL(10,7) NULL');
        review_trust_add_column($conn, 'customers', 'longitude', 'DECIMAL(10,7) NULL');
        review_trust_add_column($conn, 'customers', 'photos_approved', 'TINYINT(1) NOT NULL DEFAULT 0');
    }
}

/* ============================================================
   QR verification
   ============================================================ */

if (!function_exists('review_qr_secret')) {
    function review_qr_secret()
    {
        $secret = getenv('OPTIBIZ_QR_SECRET');
        if (!$secret) {
            $secret = getenv('OPTIBIZ_JWT_SECRET');
        }
        if (!$secret) {
            $secret = hash('sha256', __FILE__ . (defined('DB_PASS') ? DB_PASS : ''));
        }
        return (string) $secret;
    }
}

if (!function_exists('review_qr_b64')) {
    function review_qr_b64($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
if (!function_exists('review_qr_b64_decode')) {
    function review_qr_b64_decode($data)
    {
        $data = strtr((string) $data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($data, true);
    }
}

/**
 * A printable, stateless location token. No row is created per scan, so a
 * counter stand can be printed once and used by every customer. The token
 * proves the scan happened at the business, not that money changed hands —
 * per-visit tokens below provide that stronger proof.
 */
if (!function_exists('review_qr_location_token')) {
    function review_qr_location_token($companyId, $tenantId, $label = 'Counter stand', $days = 365)
    {
        $expires = time() + max(1, (int) $days) * 86400;
        $payload = [
            'v'        => 1,
            'c'        => (int) $companyId,
            't'        => (int) $tenantId,
            'label'    => substr((string) $label, 0, 80),
            'kind'     => 'location',
            'exp'      => $expires,
        ];
        $body = review_qr_b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = review_qr_b64(hash_hmac('sha256', 'location|' . $body, review_qr_secret(), true));
        return 'obqv1.' . $body . '.' . $signature;
    }
}

if (!function_exists('review_qr_location_verify')) {
    function review_qr_location_verify($token, $companyId = 0)
    {
        if (strpos($token, 'obqv1.') !== 0) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $expected = review_qr_b64(hash_hmac('sha256', 'location|' . $parts[1], review_qr_secret(), true));
        if (!hash_equals($expected, $parts[2])) {
            return null;
        }
        $payload = json_decode((string) review_qr_b64_decode($parts[1]), true);
        if (!is_array($payload) || ($payload['v'] ?? 0) !== 1 || ($payload['kind'] ?? '') !== 'location') {
            return null;
        }
        if ((int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        if ($companyId > 0 && (int) ($payload['c'] ?? 0) !== (int) $companyId) {
            return null;
        }
        return [
            'valid'    => true,
            'kind'     => 'location',
            'company_id' => (int) ($payload['c'] ?? 0),
            'tenant_id'  => (int) ($payload['t'] ?? 0),
            'label'    => (string) ($payload['label'] ?? ''),
            'token_id' => null,
            'row'      => null,
        ];
    }
}

/**
 * Issue a single-use (or limited-use) per-visit token, e.g. printed on a
 * receipt or sent in an SMS after a purchase. Only the hash is stored.
 *
 * @return array{token:string,url:string,id:int,expires_at:string}|null
 */
function review_qr_issue_visit_token($conn, $companyId, $tenantId, $label = '', $staffRef = '', $ttlHours = 72, $maxUses = 1, $createdByKind = 'system', $createdById = 0)
{
    review_trust_ensure_schema($conn);
    $companyId = (int) $companyId;
    $tenantId = (int) $tenantId;
    if ($companyId <= 0) {
        return null;
    }

    $raw = 'obq_' . bin2hex(random_bytes(18));
    $hash = hash('sha256', $raw);
    $expiresAt = date('Y-m-d H:i:s', time() + max(1, (int) $ttlHours) * 3600);
    $label = substr((string) $label, 0, 120);
    $staffRef = substr((string) $staffRef, 0, 120);
    $kind = 'visit';
    $maxUses = max(1, (int) $maxUses);
    $createdByKind = substr((string) $createdByKind, 0, 20);

    $stmt = $conn->prepare("INSERT INTO review_qr_tokens
        (tenant_id, company_id, token_hash, kind, label, staff_ref, max_uses, expires_at, created_by_kind, created_by_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iissssissi', $tenantId, $companyId, $hash, $kind, $label, $staffRef, $maxUses, $expiresAt, $createdByKind, $createdById);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();

    $url = function_exists('getCompanyPublicRatingUrl')
        ? getCompanyPublicRatingUrl($companyId, '', ['qv' => $raw, 'src' => 'qr'])
        : '';

    return ['token' => $raw, 'url' => $url, 'id' => $id, 'expires_at' => $expiresAt];
}

/**
 * Validate a QR token supplied by the app or the rating page.
 *
 * @return array|null Null when the token is invalid for this business.
 */
function review_qr_verify($conn, $token, $companyId = 0)
{
    $token = trim((string) $token);
    if ($token === '' || strlen($token) > 300) {
        return null;
    }

    if (strpos($token, 'obqv1.') === 0) {
        return review_qr_location_verify($token, $companyId);
    }

    if (strpos($token, 'obq_') !== 0) {
        return null;
    }

    review_trust_ensure_schema($conn);
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT * FROM review_qr_tokens WHERE token_hash = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }
    if ($companyId > 0 && (int) $row['company_id'] !== (int) $companyId) {
        return null;
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return null;
    }
    if ((int) $row['max_uses'] > 0 && (int) $row['uses'] >= (int) $row['max_uses']) {
        return null;
    }

    return [
        'valid'      => true,
        'kind'       => (string) $row['kind'],
        'company_id' => (int) $row['company_id'],
        'tenant_id'  => (int) $row['tenant_id'],
        'label'      => (string) $row['label'],
        'token_id'   => (int) $row['id'],
        'row'        => $row,
    ];
}

/** Consume one use of a DB-backed visit token. */
function review_qr_consume($conn, array $qr)
{
    if (empty($qr['token_id'])) {
        return true; // stateless location tokens have no counter
    }
    $stmt = $conn->prepare("UPDATE review_qr_tokens SET uses = uses + 1, last_used_at = NOW() WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $id = (int) $qr['token_id'];
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool) $ok;
}

/** Extract a QR token from JSON/body/query without trusting its contents. */
function review_qr_from_input(array $input = [])
{
    foreach (['qr_token', 'qv', 'visit_token', 'qr'] as $key) {
        if (!empty($input[$key]) && is_string($input[$key])) {
            return trim($input[$key]);
        }
    }
    return '';
}

/* ============================================================
   Category scores
   ============================================================ */

/**
 * Read optional 1-5 category scores. Accepts either flat keys
 * (category_service) or a nested `categories` object.
 *
 * @return array{service:?int,cleanliness:?int,value:?int,speed:?int}
 */
function review_categories_from_input(array $input)
{
    $nested = isset($input['categories']) && is_array($input['categories']) ? $input['categories'] : [];
    $map = [
        'service'     => ['category_service', 'service'],
        'cleanliness' => ['category_cleanliness', 'cleanliness'],
        'value'       => ['category_value', 'value'],
        'speed'       => ['category_speed', 'speed'],
    ];
    $out = [];
    foreach ($map as $key => $names) {
        $value = null;
        foreach ($names as $name) {
            if (isset($input[$name]) && $input[$name] !== '') {
                $value = (int) $input[$name];
                break;
            }
            if (isset($nested[$key]) && $nested[$key] !== '') {
                $value = (int) $nested[$key];
                break;
            }
        }
        $out[$key] = ($value !== null && $value >= 1 && $value <= 5) ? $value : null;
    }
    return $out;
}

/* ============================================================
   One review per customer per window
   ============================================================ */

function review_window_days()
{
    $configured = getenv('OPTIBIZ_REVIEW_WINDOW_DAYS');
    $days = $configured !== false ? (int) $configured : OPTIBIZ_REVIEW_WINDOW_DAYS;
    return max(1, min(365, $days));
}

/**
 * Find an existing review by the same person in the current window.
 * Identity preference: signed-in account → email → device (weakest,
 * used only for anonymous QR submissions).
 *
 * @return array|null The existing row, or null.
 */
function review_find_recent_by_customer($conn, $companyId, $userId = 0, $email = '', $deviceHash = '')
{
    review_trust_ensure_schema($conn);
    $companyId = (int) $companyId;
    if ($companyId <= 0) {
        return null;
    }
    $cutoff = date('Y-m-d H:i:s', time() - review_window_days() * 86400);

    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT id, rating, created_at, moderation_status FROM ratings
            WHERE company_id = ? AND customer_user_id = ? AND created_at >= ?
              AND moderation_status <> 'removed' ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iis', $companyId, $userId, $cutoff);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }

    $email = strtolower(trim((string) $email));
    if ($email !== '') {
        $stmt = $conn->prepare("SELECT id, rating, created_at, moderation_status FROM ratings
            WHERE company_id = ? AND LOWER(TRIM(customer_email)) = ? AND created_at >= ?
              AND moderation_status <> 'removed' ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iss', $companyId, $email, $cutoff);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }

    $deviceHash = trim((string) $deviceHash);
    if ($deviceHash !== '') {
        $stmt = $conn->prepare("SELECT id, rating, created_at, moderation_status FROM ratings
            WHERE company_id = ? AND device_hash = ? AND created_at >= ?
              AND moderation_status <> 'removed' ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iss', $companyId, $deviceHash, $cutoff);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }

    return null;
}

/* ============================================================
   Abuse signals
   ============================================================ */

function review_normalise_text($text)
{
    $text = strtolower(trim((string) $text));
    $text = preg_replace('/https?:\/\/\S+/i', ' ', $text);
    $text = preg_replace('/[^a-z0-9\s]/i', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Score a review against the patterns that indicate fake or coordinated
 * reviews. The score is advisory: content is queued for a human, not
 * deleted automatically, unless the signal is overwhelming.
 *
 * @return array{score:int,flags:string[],queue:bool,hide:bool}
 */
function review_abuse_assess($conn, $companyId, array $data)
{
    review_trust_ensure_schema($conn);
    $companyId = (int) $companyId;
    $score = 0;
    $flags = [];

    $comment = (string) ($data['comment'] ?? '');
    $deviceHash = (string) ($data['device_hash'] ?? '');
    $ipHash = (string) ($data['ip_hash'] ?? '');
    $userId = (int) ($data['user_id'] ?? 0);
    $hasQr = !empty($data['has_qr']);

    // 1. Review burst for this business in the last hour.
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM ratings WHERE company_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    if ($stmt) {
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $lastHour = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        if ($lastHour >= 40) {
            $score += 40;
            $flags[] = 'burst_40_1h';
        } elseif ($lastHour >= 15) {
            $score += 25;
            $flags[] = 'burst_15_1h';
        }
    }

    // 2. Duplicate or near-duplicate text recently submitted for this business.
    $normalised = review_normalise_text($comment);
    if (strlen($normalised) >= 20) {
        $stmt = $conn->prepare("SELECT comment FROM ratings WHERE company_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY id DESC LIMIT 150");
        if ($stmt) {
            $stmt->bind_param('i', $companyId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $other = review_normalise_text($row['comment'] ?? '');
                if ($other === '' || strlen($other) < 20) {
                    continue;
                }
                if ($other === $normalised) {
                    $score += 45;
                    $flags[] = 'duplicate_text';
                    break;
                }
                similar_text($normalised, $other, $percent);
                if ($percent >= 90.0) {
                    $score += 25;
                    $flags[] = 'near_duplicate_text';
                    break;
                }
            }
            $stmt->close();
        }
    }

    // 3. The same installation submitting for several accounts.
    if ($deviceHash !== '') {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT customer_user_id) AS cnt FROM ratings
            WHERE device_hash = ? AND customer_user_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        if ($stmt) {
            $stmt->bind_param('s', $deviceHash);
            $stmt->execute();
            $accounts = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stmt->close();
            if ($accounts >= 3) {
                $score += 25;
                $flags[] = 'device_clustering';
            }
        }
    }

    // 4. The same network writing many reviews for one business in a day.
    if ($ipHash !== '') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM ratings
            WHERE ip_hash = ? AND company_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        if ($stmt) {
            $stmt->bind_param('si', $ipHash, $companyId);
            $stmt->execute();
            $network = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stmt->close();
            if ($network >= 5) {
                $score += 20;
                $flags[] = 'ip_clustering';
            }
        }
    }

    // 5. Brand-new account with no QR proof.
    if ($userId > 0 && !$hasQr) {
        $stmt = $conn->prepare("SELECT created_at FROM app_users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && strtotime((string) $row['created_at']) > time() - 86400) {
                $score += 10;
                $flags[] = 'new_account';
            }
        }
    }

    // 6. Promotional links in the review body.
    if (preg_match('~https?://|www\.|wa\.me|t\.me|bit\.ly~i', $comment)) {
        $score += 10;
        $flags[] = 'contains_link';
    }

    $score = min(100, $score);
    return [
        'score' => $score,
        'flags' => array_values(array_unique($flags)),
        'queue' => $score >= 50,
        'hide'  => $score >= 85,
    ];
}

/* ============================================================
   Moderation queue
   ============================================================ */

function review_queue_add($conn, $ratingId, $source, $reason, $details = '')
{
    review_trust_ensure_schema($conn);
    $source = in_array($source, ['auto', 'report', 'business', 'photo'], true) ? $source : 'auto';
    $reason = substr((string) $reason, 0, 120);
    $details = substr((string) $details, 0, 500);
    $ratingId = (int) $ratingId;

    $stmt = $conn->prepare("INSERT INTO review_moderation_queue (rating_id, source, reason, details)
        VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('isss', $ratingId, $source, $reason, $details);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool) $ok;
}

/**
 * Resolve a queue item and apply the same decision to the review.
 *
 * @param string $action approve|remove|dismiss
 */
function review_moderation_decide($conn, $ratingId, $action, $note = '', $actorKind = 'admin', $actorId = 0)
{
    review_trust_ensure_schema($conn);
    $ratingId = (int) $ratingId;
    if ($ratingId <= 0) {
        return false;
    }
    $action = strtolower($action);
    if (!in_array($action, ['approve', 'remove', 'dismiss'], true)) {
        return false;
    }

    if ($action === 'remove') {
        $stmt = $conn->prepare("UPDATE ratings SET moderation_status = 'removed', reported = 1, photos_approved = 0 WHERE id = ? LIMIT 1");
    } elseif ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE ratings SET moderation_status = 'published', reported = 0, photos_approved = 1 WHERE id = ? LIMIT 1");
    } else {
        $stmt = $conn->prepare("UPDATE ratings SET moderation_status = 'published', reported = 0 WHERE id = ? LIMIT 1");
    }
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $ratingId);
    $ok = $stmt->execute();
    $stmt->close();

    $queueStatus = $action === 'remove' ? 'removed' : ($action === 'approve' ? 'approved' : 'dismissed');
    $note = substr((string) $note, 0, 500);
    $actorKind = substr((string) $actorKind, 0, 20);
    $actorId = (int) $actorId;
    $stmt = $conn->prepare("UPDATE review_moderation_queue
        SET status = ?, resolved_at = NOW(), resolved_by_kind = ?, resolved_by_id = ?, resolution_note = ?
        WHERE rating_id = ? AND status = 'open'");
    if ($stmt) {
        $stmt->bind_param('ssisi', $queueStatus, $actorKind, $actorId, $note, $ratingId);
        $stmt->execute();
        $stmt->close();
    }

    return (bool) $ok;
}

/** A user report: surfaced publicly only after several independent reports. */
function review_report($conn, $ratingId, $reason, $details = '', $reporterUserId = 0, $reporterIp = '', $reporterEmail = '')
{
    review_trust_ensure_schema($conn);
    $ratingId = (int) $ratingId;
    $reason = substr(trim((string) $reason), 0, 255);
    $details = substr(trim((string) $details), 0, 500);
    if ($ratingId <= 0 || $reason === '') {
        return ['success' => false, 'message' => 'Choose a reason for the report.'];
    }

    $stmt = $conn->prepare("SELECT id, company_id, customer_email, customer_user_id, moderation_status FROM ratings WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Could not check that review.'];
    }
    $stmt->bind_param('i', $ratingId);
    $stmt->execute();
    $rating = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$rating) {
        return ['success' => false, 'message' => 'Review not found.'];
    }
    if ($reporterUserId > 0 && (int) ($rating['customer_user_id'] ?? 0) === $reporterUserId) {
        return ['success' => false, 'message' => 'You cannot report your own review.'];
    }

    $ip = $reporterIp !== '' ? $reporterIp : ($_SERVER['REMOTE_ADDR'] ?? '');
    $stmt = $conn->prepare("SELECT id FROM reported_reviews WHERE rating_id = ? AND (reporter_ip = ? OR (reporter_user_id IS NOT NULL AND reporter_user_id = ?)) LIMIT 1");
    if ($stmt) {
        $uid = (int) $reporterUserId;
        $stmt->bind_param('isi', $ratingId, $ip, $uid);
        $stmt->execute();
        $already = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($already) {
            return ['success' => true, 'message' => 'You have already reported this review. Our team is looking into it.'];
        }
    }

    $stmt = $conn->prepare("INSERT INTO reported_reviews (rating_id, reason, details, reporter_ip, reporter_user_id)
        VALUES (?, ?, ?, ?, NULLIF(?, 0))");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Could not file the report.'];
    }
    $uid = (int) $reporterUserId;
    $stmt->bind_param('issss', $ratingId, $reason, $details, $ip, $uid);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return ['success' => false, 'message' => 'Could not file the report.'];
    }

    review_queue_add($conn, $ratingId, 'report', $reason, $details);

    // Do not hide a review after one report — that enables mass-report
    // takedowns. Three independent reports move it into the hidden queue
    // for a human decision.
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT COALESCE(NULLIF(reporter_user_id, 0), reporter_ip)) AS cnt FROM reported_reviews WHERE rating_id = ? AND status = 'open'");
    if ($stmt) {
        $stmt->bind_param('i', $ratingId);
        $stmt->execute();
        $reports = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        if ($reports >= 3) {
            $conn->query("UPDATE ratings SET moderation_status = 'queued' WHERE id = " . $ratingId . " AND moderation_status = 'published'");
        }
    }

    return ['success' => true, 'message' => 'Thank you. Our moderation team will review this.'];
}

/** Store photos and hold them for approval. */
function review_attach_photos($conn, $ratingId, array $files)
{
    $paths = [];
    $count = count($files['name'] ?? []);
    $limit = min($count, OPTIBIZ_REVIEW_MAX_PHOTOS);
    for ($i = 0; $i < $limit; $i++) {
        if (empty($files['name'][$i])) {
            continue;
        }
        $file = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'size'     => $files['size'][$i],
        ];
        $result = uploadReviewPhoto($file, $ratingId);
        if (!empty($result['success'])) {
            $paths[] = $result['path'];
        }
    }
    if ($paths) {
        $json = json_encode($paths);
        $stmt = $conn->prepare("UPDATE ratings SET photos = ?, photos_approved = 0 WHERE id = ? LIMIT 1");
        if ($stmt) {
            $id = (int) $ratingId;
            $stmt->bind_param('si', $json, $id);
            $stmt->execute();
            $stmt->close();
        }
        review_queue_add($conn, $ratingId, 'photo', 'photo_review', count($paths) . ' photo(s) awaiting approval');
    }
    return $paths;
}

/** Public representation: pending photos are never exposed. */
function review_public_photos($row)
{
    if (empty($row['photos']) || empty($row['photos_approved'])) {
        return [];
    }
    $decoded = json_decode((string) $row['photos'], true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
}

/** Toggle a helpful vote for this identity. */
function review_toggle_helpful($conn, $ratingId, $voterUserId = 0, $voterIp = '')
{
    review_trust_ensure_schema($conn);
    $ratingId = (int) $ratingId;
    if ($ratingId <= 0) {
        return ['success' => false, 'message' => 'Review not found.'];
    }

    $voterUserId = (int) $voterUserId;
    $voterKey = $voterUserId > 0 ? ('u:' . $voterUserId) : (string) $voterIp;
    if ($voterKey === '') {
        return ['success' => false, 'message' => 'Could not identify the voter.'];
    }
    $voterKey = substr($voterKey, 0, 64);

    $stmt = $conn->prepare("SELECT id, customer_user_id FROM ratings WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $ratingId);
    $stmt->execute();
    $rating = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$rating) {
        return ['success' => false, 'message' => 'Review not found.'];
    }
    if ($voterUserId > 0 && (int) ($rating['customer_user_id'] ?? 0) === $voterUserId) {
        return ['success' => false, 'message' => 'You cannot mark your own review as helpful.'];
    }

    $stmt = $conn->prepare("SELECT id FROM helpful_votes WHERE rating_id = ? AND voter_ip = ? LIMIT 1");
    $stmt->bind_param('is', $ratingId, $voterKey);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("DELETE FROM helpful_votes WHERE id = ? LIMIT 1");
        $voteId = (int) $existing['id'];
        $stmt->bind_param('i', $voteId);
        $stmt->execute();
        $stmt->close();
        $conn->query("UPDATE ratings SET helpful_count = GREATEST(0, helpful_count - 1) WHERE id = " . $ratingId);
        $liked = false;
    } else {
        $stmt = $conn->prepare("INSERT INTO helpful_votes (rating_id, voter_ip, voter_user_id) VALUES (?, ?, NULLIF(?, 0))");
        $stmt->bind_param('isi', $ratingId, $voterKey, $voterUserId);
        $stmt->execute();
        $stmt->close();
        $conn->query("UPDATE ratings SET helpful_count = helpful_count + 1 WHERE id = " . $ratingId);
        $liked = true;
    }

    $count = (int) $conn->query("SELECT helpful_count FROM ratings WHERE id = " . $ratingId)->fetch_assoc()['helpful_count'];
    return ['success' => true, 'liked' => $liked, 'count' => $count, 'message' => $liked ? 'Marked as helpful.' : 'Vote removed.'];
}
