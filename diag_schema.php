<?php
// TEMP DIAGNOSTIC - remove after use. Restricted to local / CLI access so
// database schema details and errors are never exposed to the public web.
$remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$is_local = PHP_SAPI === 'cli' || in_array($remote, ['127.0.0.1', '::1'], true);
if (!$is_local) {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config/database.php';
$r = $conn->query('DESCRIBE ratings');
if (!$r) { echo "ERR: schema unavailable\n"; exit; }
while ($row = $r->fetch_assoc()) { echo $row['Field'] . '|' . $row['Type'] . "\n"; }
echo "---services---\n";
$r2 = $conn->query('DESCRIBE services');
if ($r2) { while ($row = $r2->fetch_assoc()) { echo $row['Field'] . '|' . $row['Type'] . "\n"; } }
else { echo "no services table\n"; }