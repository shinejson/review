<?php
/**
 * Widget click beacon — increments clicks_total
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
if (file_exists(dirname(__DIR__).'/includes/monetization.php')) { require_once dirname(__DIR__).'/includes/monetization.php'; if(function_exists('monetization_ensure_schema')) @monetization_ensure_schema($conn); }

$key = trim($_POST['widget_key'] ?? $_GET['key'] ?? $_GET['widget_key'] ?? $_REQUEST['key'] ?? $_REQUEST['widget_key'] ?? '');
if ($key === '') {
    // support key in query string for widget.php click handler (?key=xxx)
    $key = trim($_GET['key'] ?? '');
}
if ($key === '') { http_response_code(400); echo json_encode(['error'=>'missing key']); exit; }
$esc = $conn->real_escape_string($key);
@$conn->query("UPDATE widget_configs SET clicks_total = clicks_total + 1 WHERE widget_key='".$esc."' LIMIT 1");
if (function_exists('sa_one')) {
    $wc = @sa_one($conn, "SELECT tenant_id, company_id FROM widget_configs WHERE widget_key='".$esc."' LIMIT 1", 'widget_configs');
    if ($wc) @logAnalyticsEvent($conn, (int)$wc['tenant_id'], (int)$wc['company_id'], 'widget_click', 'widget_js', 'click', $key);
}
header('Content-Type: application/json');
echo json_encode(['ok'=>true]);
