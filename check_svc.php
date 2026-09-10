<?php
$c = file_get_contents('rate/index.php');
$m = strpos($c, '@media (max-width: 850px)');
echo "media pos: $m\n";
echo substr($c, $m, 260) . "\n";
$cs = strpos($c, '</style>');
echo "closing style pos: $cs\n";
echo substr($c, $cs - 200, 200) . "\n";