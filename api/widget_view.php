<?php
/**
 * Widget view beacon — increments views_total for free-tier quota
 * Called via navigator.sendBeacon from widget.js
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
if (file_exists(dirname(__DIR__).'/includes/monetization.php')) { require_once dirname(__DIR__).'/includes/monetization.php'; if(function_exists('monetization_ensure_schema')) @monetization_ensure_schema($conn); }

$key = trim($_POST['widget_key'] ?? $_GET['key'] ?? $_GET['widget_key'] ?? $_REQUEST['key'] ?? '');
if ($key === '') { http_response_code(400); echo json_encode(['error'=>'missing key']); exit; }
$esc = $conn->real_escape_string($key);
@$conn->query("UPDATE widget_configs SET views_total = views_total + 1 WHERE widget_key='".$esc."' LIMIT 1");
// also log analytics if company known
if (function_exists('sa_one')) {
  $wc = @sa_one($conn, "SELECT tenant_id, company_id FROM widget_configs WHERE widget_key='".$esc."' LIMIT 1", 'widget_configs');
  if ($wc) @logAnalyticsEvent($conn, (int)$wc['tenant_id'], (int)$wc['company_id'], 'widget_view', 'widget_js', 'js_view', $key);
}
header('Content-Type: application/json');
echo json_encode(['ok'=>true]);
