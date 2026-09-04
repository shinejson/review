<?php
/**
 * ============================================================
 *  User sessions — sign-in tracking and sign-out
 * ============================================================
 *  Shared by the super admin control center (`superadmin/`) and
 *  the tenant admin workspace (`admin/`). One row in
 *  `user_sessions` describes one browser sign-in: who it belongs
 *  to, which panel it was used in, when it started, when it was
 *  last used and how it ended.
 *
 *  What the panels get from this file:
 *    auth_login_session()         record a sign-in (login.php)
 *    auth_session_enforce()       idle + absolute expiry, and
 *                                 honouring a revocation issued
 *                                 from another screen
 *    auth_session_logout()        sign this browser out
 *    auth_session_logout_others() "sign out everywhere else"
 *    auth_revoke_user_sessions()  sign somebody else out
 *    auth_sessions_for()          the list behind the screens
 *
 *  Everything here is defensive: when the `user_sessions` table
 *  is missing (an old installation that has not been migrated)
 *  the panels keep working from the session variables alone and
 *  only the session list stays empty.
 */

/* ---------- policy (override by defining them before this file) ---------- */
if (!defined('AUTH_IDLE_TIMEOUT_SUPERADMIN'))     define('AUTH_IDLE_TIMEOUT_SUPERADMIN', 30 * 60);        // 30 minutes
if (!defined('AUTH_IDLE_TIMEOUT_ADMIN'))          define('AUTH_IDLE_TIMEOUT_ADMIN', 2 * 60 * 60);         // 2 hours
if (!defined('AUTH_ABSOLUTE_TIMEOUT_SUPERADMIN')) define('AUTH_ABSOLUTE_TIMEOUT_SUPERADMIN', 12 * 60 * 60); // 12 hours
if (!defined('AUTH_ABSOLUTE_TIMEOUT_ADMIN'))      define('AUTH_ABSOLUTE_TIMEOUT_ADMIN', 14 * 24 * 60 * 60); // 14 days
if (!defined('AUTH_SESSION_TOUCH_EVERY'))         define('AUTH_SESSION_TOUCH_EVERY', 60);                 // seconds

/* ============================================================
 *  Session bootstrapping
 * ============================================================ */

/** True when the current request arrived over TLS. */
function auth_is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if ((int) (isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 0) === 443) return true;
    return strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https';
}

/**
 * Start the session with hardened cookie flags. Safe to call more
 * than once — and a no-op when the session is already open (the
 * preview harness opens it before the pages run).
 */
function auth_session_start() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $secure = auth_is_https();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; SameSite=Lax', '', $secure, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    @session_start();
}

/** New session id, same data — call it right after a successful sign-in. */
function auth_regenerate_session() {
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }
    @session_regenerate_id(true);
}

/* ============================================================
 *  Schema
 * ============================================================ */

/** The CREATE statement used to self-heal an unmigrated database. */
function auth_sessions_ddl() {
    return "CREATE TABLE IF NOT EXISTS user_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_token VARCHAR(64) NOT NULL,
        portal VARCHAR(20) NOT NULL,
        user_id INT NOT NULL,
        user_label VARCHAR(120) NULL,
        user_kind VARCHAR(20) NOT NULL DEFAULT 'admin',
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP NULL,
        logged_out_at TIMESTAMP NULL,
        logout_reason VARCHAR(20) NULL,
        INDEX idx_user_sessions_token (session_token),
        INDEX idx_user_sessions_user (portal, user_id, logged_out_at)
    )";
}

/**
 * Create `user_sessions` when it is missing, so an existing
 * installation does not need a manual migration. Runs at most once
 * per request.
 */
function auth_ensure_session_schema($conn) {
    static $checked = false;
    if ($checked || !is_object($conn) || !method_exists($conn, 'query')) {
        return auth_session_table_ok($conn);
    }
    $checked = true;
    if (!auth_session_table_ok($conn)) {
        @$conn->query(auth_sessions_ddl());
    }
    return auth_session_table_ok($conn);
}

/** Cached answer to "is the user_sessions table installed?". */
function auth_session_table_ok($conn) {
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $exists = false;
    if (!is_object($conn) || !method_exists($conn, 'query')) {
        return $exists;
    }
    $res = @$conn->query(
        "SELECT COUNT(*) AS c FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'user_sessions'"
    );
    if ($res) {
        $row = $res->fetch_assoc();
        $exists = $row && (int) $row['c'] > 0;
        $res->close();
    }
    return $exists;
}

/* ============================================================
 *  Who is signed in
 * ============================================================ */

/** 'superadmin', 'admin' or null. */
function auth_current_portal() {
    if (!empty($_SESSION['super_admin_id'])) return 'superadmin';
    if (!empty($_SESSION['admin_id']) || !empty($_SESSION['tenant_id'])) return 'admin';
    return null;
}

/** The id this session belongs to inside its portal. */
function auth_current_user_id($portal = null) {
    $portal = $portal ?: auth_current_portal();
    if ($portal === 'superadmin') return (int) (isset($_SESSION['super_admin_id']) ? $_SESSION['super_admin_id'] : 0);
    if (!empty($_SESSION['admin_id'])) return (int) $_SESSION['admin_id'];
    return (int) (isset($_SESSION['tenant_id']) ? $_SESSION['tenant_id'] : 0);
}

/** 'super_admin', 'admin' or 'tenant'. */
function auth_current_user_kind($portal = null) {
    $portal = $portal ?: auth_current_portal();
    if ($portal === 'superadmin') return 'super_admin';
    return !empty($_SESSION['admin_id']) ? 'admin' : 'tenant';
}

/** Human readable owner of the session. */
function auth_current_user_label($portal = null) {
    $portal = $portal ?: auth_current_portal();
    if ($portal === 'superadmin') {
        return (string) (isset($_SESSION['super_admin_username']) ? $_SESSION['super_admin_username'] : 'Super admin');
    }
    if (!empty($_SESSION['admin_username'])) return (string) $_SESSION['admin_username'];
    if (!empty($_SESSION['tenant_name']))    return (string) $_SESSION['tenant_name'];
    return 'Workspace user';
}

function auth_client_ip() {
    $ip = (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
    return $ip !== '' ? substr($ip, 0, 45) : null;
}

function auth_client_agent() {
    $ua = (string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
    return $ua !== '' ? substr($ua, 0, 255) : null;
}

/** Short label for a user agent, for the session lists. */
function auth_describe_agent($user_agent) {
    $ua = (string) $user_agent;
    if (trim($ua) === '') return 'Unknown device';
    $browser = 'Browser';
    if (stripos($ua, 'Edg/') !== false)          $browser = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false)      $browser = 'Opera';
    elseif (stripos($ua, 'Chrome/') !== false)   $browser = 'Chrome';
    elseif (stripos($ua, 'Firefox/') !== false)  $browser = 'Firefox';
    elseif (stripos($ua, 'Safari/') !== false)   $browser = 'Safari';
    $os = '';
    if (stripos($ua, 'Windows') !== false)       $os = 'Windows';
    elseif (stripos($ua, 'Android') !== false)   $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false
         || stripos($ua, 'iPad') !== false)      $os = 'iOS';
    elseif (stripos($ua, 'Mac OS X') !== false)  $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false)     $os = 'Linux';
    return trim($os . ' · ' . $browser, ' ·');
}

/* ============================================================
 *  Tokens
 * ============================================================ */

/** The random token that identifies this browser's session row. */
function auth_session_token($create = true) {
    if (!empty($_SESSION['session_token'])) {
        return (string) $_SESSION['session_token'];
    }
    if (!$create) {
        return '';
    }
    $_SESSION['session_token'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['session_token'];
}

/**
 * One-shot token that has to travel with a sign-out request, so a
 * third party cannot sign somebody out with a bare <img> tag.
 */
function auth_logout_token() {
    if (empty($_SESSION['logout_token'])) {
        $_SESSION['logout_token'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['logout_token'];
}

/** Sign-out URL for the panel the current script lives in. */
function auth_logout_url() {
    return 'logout.php?t=' . rawurlencode(auth_logout_token());
}

/** Does the current request carry a valid sign-out token? */
function auth_logout_request_ok() {
    $sent = '';
    foreach (['logout_token', 't'] as $key) {
        if (!empty($_POST[$key])) { $sent = (string) $_POST[$key]; break; }
    }
    if ($sent === '' && !empty($_GET['t'])) {
        $sent = (string) $_GET['t'];
    }
    $token = isset($_SESSION['logout_token']) ? (string) $_SESSION['logout_token'] : '';
    if ($sent === '' || $token === '') {
        return false;
    }
    return hash_equals($token, $sent);
}

/* ============================================================
 *  Recording a sign-in
 * ============================================================ */

/**
 * Record a successful sign-in. Called by both login pages after the
 * password check passed; it also rotates the session id and issues
 * the tokens the session needs.
 */
function auth_login_session($conn, $portal = null, $user_id = 0, $label = '', $kind = '') {
    auth_session_start();
    auth_regenerate_session();

    $portal  = $portal ?: (auth_current_portal() ?: 'admin');
    $user_id = (int) ($user_id ?: auth_current_user_id($portal));
    $kind    = $kind !== '' ? $kind : auth_current_user_kind($portal);
    $label   = $label !== '' ? $label : auth_current_user_label($portal);

    $now = time();
    $_SESSION['session_token']        = bin2hex(random_bytes(32));
    $_SESSION['session_portal']       = $portal;
    $_SESSION['session_started_at']   = $now;
    $_SESSION['session_last_seen_at'] = $now;
    $_SESSION['session_last_touch']   = $now;
    auth_logout_token();

    if (!auth_ensure_session_schema($conn)) {
        return false;
    }
    $token = (string) $_SESSION['session_token'];
    $label = substr($label, 0, 120);
    $ip    = auth_client_ip();
    $agent = auth_client_agent();
    $stmt  = @$conn->prepare(
        'INSERT INTO user_sessions
            (session_token, portal, user_id, user_label, user_kind, ip_address, user_agent, created_at, last_seen_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ssissss', $token, $portal, $user_id, $label, $kind, $ip, $agent);
    $ok = @$stmt->execute();
    $stmt->close();

    /* Housekeeping: drop this account's closed sessions from more than a
       month ago, so the table does not grow without bound. */
    $stmt = @$conn->prepare(
        'DELETE FROM user_sessions
          WHERE portal = ? AND user_id = ? AND logged_out_at IS NOT NULL
            AND logged_out_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
    );
    if ($stmt) {
        $stmt->bind_param('si', $portal, $user_id);
        @$stmt->execute();
        $stmt->close();
    }
    return (bool) $ok;
}

/**
 * Adopt a session that predates session tracking: give it a token
 * and a row so it can be listed and revoked like any other.
 */
function auth_adopt_session($conn, $portal = null) {
    $portal = $portal ?: auth_current_portal();
    if (!$portal) {
        return false;
    }
    $now = time();
    if (empty($_SESSION['session_started_at']))   $_SESSION['session_started_at']   = $now;
    if (empty($_SESSION['session_last_seen_at'])) $_SESSION['session_last_seen_at'] = $now;
    $_SESSION['session_last_touch'] = $now;
    return auth_login_session($conn, $portal, auth_current_user_id($portal), auth_current_user_label($portal), auth_current_user_kind($portal));
}

/* ============================================================
 *  Reading sessions back
 * ============================================================ */

/** The row behind the current browser, or null. */
function auth_session_row($conn, $token) {
    if (!auth_session_table_ok($conn) || $token === '') {
        return null;
    }
    $stmt = @$conn->prepare('SELECT id, portal, user_id, user_kind, logged_out_at, logout_reason FROM user_sessions WHERE session_token = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $token);
    @$stmt->execute();
    $res  = $stmt->get_result();
    $row  = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/** Active sessions of one user, most recently used first. */
function auth_sessions_for($conn, $portal, $user_id, $limit = 8) {
    if (!auth_session_table_ok($conn)) {
        return [];
    }
    $limit = max(1, (int) $limit);
    $stmt  = @$conn->prepare(
        'SELECT id, session_token, user_label, user_kind, ip_address, user_agent, created_at, last_seen_at
           FROM user_sessions
          WHERE portal = ? AND user_id = ? AND logged_out_at IS NULL
          ORDER BY last_seen_at DESC
          LIMIT ' . $limit
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('si', $portal, $user_id);
    @$stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

/** How many live sessions a user has. */
function auth_session_count($conn, $portal, $user_id) {
    if (!auth_session_table_ok($conn)) {
        return 0;
    }
    $stmt = @$conn->prepare('SELECT COUNT(*) AS c FROM user_sessions WHERE portal = ? AND user_id = ? AND logged_out_at IS NULL');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('si', $portal, $user_id);
    @$stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? (int) $row['c'] : 0;
}

/** user_id => live session count, for a list of users. */
function auth_session_counts($conn, $portal, array $user_ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    if (!$ids || !auth_session_table_ok($conn)) {
        return [];
    }
    $sql = 'SELECT user_id, COUNT(*) AS sessions
              FROM user_sessions
             WHERE portal = \'' . (is_object($conn) && method_exists($conn, 'real_escape_string') ? $conn->real_escape_string($portal) : $portal) . '\'
               AND logged_out_at IS NULL
               AND user_id IN (' . implode(', ', $ids) . ')
             GROUP BY user_id';
    $res = @$conn->query($sql);
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[(int) $row['user_id']] = (int) $row['sessions'];
        }
        $res->close();
    }
    return $out;
}

/* ============================================================
 *  Signing out
 * ============================================================ */

/**
 * Drop the PHP session completely: data, cookie and id. Called by
 * every sign-out path.
 */
function auth_destroy_session() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $expiry = time() - 42000;
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), '', [
                'expires'  => $expiry,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => isset($params['samesite']) ? $params['samesite'] : 'Lax',
            ]);
        } else {
            setcookie(session_name(), '', $expiry, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
}

/**
 * Sign the current browser out and close its `user_sessions` row.
 * $reason: user | idle | absolute | revoked | password
 */
function auth_session_logout($conn = null, $reason = 'user', $mark_row = true) {
    if ($conn === null && isset($GLOBALS['conn'])) {
        $conn = $GLOBALS['conn'];
    }
    $token = isset($_SESSION['session_token']) ? (string) $_SESSION['session_token'] : '';
    if ($mark_row && $token !== '' && auth_session_table_ok($conn)) {
        $stmt = @$conn->prepare('UPDATE user_sessions SET logged_out_at = NOW(), logout_reason = ? WHERE session_token = ? AND logged_out_at IS NULL');
        if ($stmt) {
            $stmt->bind_param('ss', $reason, $token);
            @$stmt->execute();
            $stmt->close();
        }
    }
    auth_destroy_session();
    return $reason;
}

/**
 * Close every other live session of the signed-in user — the
 * "sign out everywhere else" button. Returns how many were closed.
 */
function auth_session_logout_others($conn, $portal = null, $user_id = 0, $reason = 'revoked') {
    $portal  = $portal ?: auth_current_portal();
    $user_id = (int) ($user_id ?: auth_current_user_id($portal));
    if (!$portal || $user_id <= 0 || !auth_session_table_ok($conn)) {
        return 0;
    }
    $token = auth_session_token(false);
    $count = auth_session_count($conn, $portal, $user_id);
    if ($token !== '' && ($row = auth_session_row($conn, $token))) {
        $count = max(0, $count - 1);
    }
    $stmt = @$conn->prepare(
        'UPDATE user_sessions SET logged_out_at = NOW(), logout_reason = ?
          WHERE portal = ? AND user_id = ? AND logged_out_at IS NULL AND session_token <> ?'
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('ssis', $reason, $portal, $user_id, $token);
    @$stmt->execute();
    $stmt->close();
    return $count;
}

/**
 * Close every live session of another account — used by the super
 * admin Users screen to push somebody out. Returns the count.
 */
function auth_revoke_user_sessions($conn, $portal, $user_id, $reason = 'revoked') {
    $user_id = (int) $user_id;
    if ($user_id <= 0 || !auth_session_table_ok($conn)) {
        return 0;
    }
    $count = auth_session_count($conn, $portal, $user_id);
    $stmt  = @$conn->prepare('UPDATE user_sessions SET logged_out_at = NOW(), logout_reason = ? WHERE portal = ? AND user_id = ? AND logged_out_at IS NULL');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('ssi', $reason, $portal, $user_id);
    @$stmt->execute();
    $stmt->close();
    return $count;
}

/* ============================================================
 *  Timeouts
 * ============================================================ */

/** Send the visitor to the login screen of the panel they are in. */
function auth_redirect_to_login($query = '') {
    if (!headers_sent()) {
        header('Location: login.php' . ($query !== '' ? '?' . $query : ''));
    }
    exit();
}

/**
 * Keep the session alive, and end it when it has run out of time or
 * when somebody revoked it. Called from requireLogin() and
 * requireSuperAdminLogin(), so it runs once per request.
 */
function auth_session_enforce($conn = null) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $portal = auth_current_portal();
    if (!$portal) {
        return;   // not signed in — requireLogin() deals with that
    }
    if ($conn === null && isset($GLOBALS['conn'])) {
        $conn = $GLOBALS['conn'];
    }
    $idle     = $portal === 'superadmin' ? AUTH_IDLE_TIMEOUT_SUPERADMIN : AUTH_IDLE_TIMEOUT_ADMIN;
    $absolute = $portal === 'superadmin' ? AUTH_ABSOLUTE_TIMEOUT_SUPERADMIN : AUTH_ABSOLUTE_TIMEOUT_ADMIN;
    $now      = time();

    if (empty($_SESSION['session_started_at'])) {
        // A session that predates session tracking: start the clock and
        // adopt it so it can be listed and revoked like any other.
        $_SESSION['session_portal']       = $portal;
        $_SESSION['session_started_at']   = $now;
        $_SESSION['session_last_seen_at'] = $now;
        if (empty($_SESSION['session_token'])) {
            auth_adopt_session($conn, $portal);
        }
        return;
    }

    $last = isset($_SESSION['session_last_seen_at']) ? (int) $_SESSION['session_last_seen_at'] : $now;
    if ($now - $last > $idle) {
        auth_session_logout($conn, 'idle');
        auth_redirect_to_login('expired=idle');
    }
    if ($now - (int) $_SESSION['session_started_at'] > $absolute) {
        auth_session_logout($conn, 'absolute');
        auth_redirect_to_login('expired=session');
    }

    $_SESSION['session_last_seen_at'] = $now;
    auth_session_touch($conn);
}

/**
 * Refresh `last_seen_at` (at most once a minute) and act on a
 * revocation: a row that has been closed from another screen ends
 * this browser immediately.
 */
function auth_session_touch($conn) {
    $token = auth_session_token(false);
    if ($token === '' || !auth_session_table_ok($conn)) {
        return;
    }
    $row = auth_session_row($conn, $token);
    if (!$row) {
        return;   // unknown row (pruned or never recorded) — leave it alone
    }
    if (!empty($row['logged_out_at'])) {
        $reason = !empty($row['logout_reason']) ? (string) $row['logout_reason'] : 'revoked';
        auth_session_logout($conn, $reason, false);
        auth_redirect_to_login('signed_out=' . rawurlencode($reason));
    }
    $last_touch = isset($_SESSION['session_last_touch']) ? (int) $_SESSION['session_last_touch'] : 0;
    if (time() - $last_touch < AUTH_SESSION_TOUCH_EVERY) {
        return;
    }
    $_SESSION['session_last_touch'] = time();
    $stmt = @$conn->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE session_token = ? AND logged_out_at IS NULL');
    if ($stmt) {
        $stmt->bind_param('s', $token);
        @$stmt->execute();
        $stmt->close();
    }
}

/* ============================================================
 *  Messages
 * ============================================================ */

/**
 * The notice a login screen shows after a redirect back to it:
 * login.php?signed_out=1, ?expired=idle, ?signed_out=revoked …
 * Returns ['type' => …, 'text' => …] or null.
 */
function auth_login_notice() {
    if (!empty($_GET['signed_out'])) {
        $reason = (string) $_GET['signed_out'];
        if ($reason === 'revoked') {
            return ['type' => 'info', 'text' => 'You were signed out by an administrator.'];
        }
        if ($reason === 'password') {
            return ['type' => 'info', 'text' => 'Your password changed, so every session was signed out. Please sign in again.'];
        }
        if ($reason === 'invalid') {
            return ['type' => 'info', 'text' => 'That sign-out link has already been used or is no longer valid.'];
        }
        return ['type' => 'info', 'text' => 'You have been signed out.'];
    }
    if (!empty($_GET['expired'])) {
        if ((string) $_GET['expired'] === 'idle') {
            return ['type' => 'info', 'text' => 'For your security the session ended after a period of inactivity. Please sign in again.'];
        }
        return ['type' => 'info', 'text' => 'Your session reached its maximum age and was closed. Please sign in again.'];
    }
    return null;
}
?>
