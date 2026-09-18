<?php
/**
 * Service check (schema sanity). Restricted to local / CLI access only so
 * production schema details are never exposed to the public web.
 */
$remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$is_local = PHP_SAPI === 'cli' || in_array($remote, ['127.0.0.1', '::1'], true);
if (!$is_local) {
    http_response_code(404);
    exit;
}

require_once 'config/database.php';
$c = $GLOBALS['conn'];
$res = $c->query('SHOW CREATE TABLE ratings');
if ($res && $row = $res->fetch_row()) {
    echo $row[1] . PHP_EOL;
} else {
    echo 'ratings table missing' . PHP_EOL;
}
