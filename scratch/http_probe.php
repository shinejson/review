<?php
// HTTP probe for the alias/403/rewrite behavior. Safe to delete.
function probe($label, $url, $method = 'GET', $body = null, $headers = []) {
    $opts = [
        'http' => [
            'method'        => $method,
            'ignore_errors' => true,
            'timeout'       => 8,
            'header'        => implode("\r\n", $headers),
        ],
    ];
    if ($body !== null) {
        $opts['http']['content'] = $body;
    }
    $ctx  = stream_context_create($opts);
    $res  = @fopen($url, 'rb', false, $ctx);
    $meta = $res ? stream_get_meta_data($res) : [];
    $status = '';
    $loc = '';
    foreach (($meta['wrapper_data'] ?? []) as $h) {
        if (stripos($h, 'HTTP/') === 0) { $status = trim(substr($h, 9)); }
        if (stripos($h, 'Location:') === 0) { $loc = trim(substr($h, 9)); }
    }
    if ($res) { fclose($res); }
    echo str_pad($label, 34) . ' -> ' . ($status ?: 'NO RESPONSE') . ($loc !== '' ? "  Location: {$loc}" : '') . "\n";
    if ($body !== null && $res === false) { /* nothing */ }
    return $status;
}

$base = 'http://localhost:8080/rate';
probe('public index.php',            $base . '/index.php');
probe('real admin (expect 403)',     $base . '/admin/login.php');
probe('alias login.php (expect 200)', $base . '/p7xk2mqw9vrt4zhn/login.php');
probe('alias root (expect 302)',     $base . '/p7xk2mqw9vrt4zhn/');
probe('alias extensionless',         $base . '/p7xk2mqw9vrt4zhn/notifications');
probe('uploads image (expect 200)',  $base . '/uploads/tenant_logo_3_1788528249.png');

// API: show the 503 body so the operator sees the actionable message
$opts = ['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 8,
                    'header' => "Content-Type: application/json\r\n",
                    'content' => '{"username":"x","password":"y"}']];
$ctx = stream_context_create($opts);
$raw = @file_get_contents($base . '/api/v1/auth/login.php', false, $ctx);
echo "api login                        -> body: " . substr((string)$raw, 0, 160) . "\n";

