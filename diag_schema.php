<?php
// TEMP DIAGNOSTIC - remove after use
require_once dirname(__DIR__) . '/config/database.php';
echo "DB=" . DB_NAME . "\n";
$r = $conn->query('DESCRIBE ratings');
if (!$r) { echo 'ERR:' . $conn->error . "\n"; exit; }
while ($row = $r->fetch_assoc()) { echo $row['Field'] . '|' . $row['Type'] . "\n"; }
echo "---services---\n";
$r2 = $conn->query('DESCRIBE services');
if ($r2) { while ($row = $r2->fetch_assoc()) { echo $row['Field'] . '|' . $row['Type'] . "\n"; } }
else { echo 'no services table: ' . $conn->error . "\n"; }