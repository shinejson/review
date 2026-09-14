<?php
require_once 'config/database.php';
$c = $GLOBALS['conn'];
$res = $c->query('SHOW CREATE TABLE ratings');
if ($res && $row = $res->fetch_row()) {
    echo $row[1] . PHP_EOL;
} else {
    echo 'ratings table missing' . PHP_EOL;
}
