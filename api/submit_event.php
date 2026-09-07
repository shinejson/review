<?php
/**
 * ============================================================
 *  Telemetry & Analytics Event Collector API
 * ============================================================
 *  Receives real-time asynchronous event beacons from rating
 *  pages, QR stand scans, widgets, and WhatsApp interaction links.
 *  Uses sendBeacon / keepalive fetch for zero client-side latency.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

// Support both JSON body (from sendBeacon / fetch) and standard POST
$payload = [];
$raw_input = file_get_contents('php://input');
if (!empty($raw_input)) {
    $decoded = json_decode($raw_input, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if (empty($payload) && !empty($_POST)) {
    $payload = $_POST;
}

$event_type = sanitize($payload['event_type'] ?? '');
if ($event_type === '') {
    echo json_encode(['success' => false, 'message' => 'Missing event_type']);
    exit;
}

$company_id = (int)($payload['company_id'] ?? 0);
$tenant_id  = (int)($payload['tenant_id'] ?? 0);

// Resolve tenant_id or company_id if either is missing
if ($company_id > 0 && $tenant_id <= 0) {
    $stmt = $conn->prepare("SELECT tenant_id FROM customers WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $tenant_id = (int)$row['tenant_id'];
        }
    }
} elseif ($tenant_id > 0 && $company_id <= 0) {
    $stmt = $conn->prepare("SELECT id FROM customers WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $company_id = (int)$row['id'];
        }
    }
}

if ($tenant_id <= 0 && $company_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Unidentified tenant or company']);
    exit;
}

$event_category = sanitize($payload['event_category'] ?? '');
$event_label    = sanitize($payload['event_label'] ?? '');
$traffic_source = sanitize($payload['traffic_source'] ?? 'direct');
$page_url       = sanitize($payload['page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
$referrer       = sanitize($payload['referrer'] ?? '');
$session_id     = sanitize($payload['session_id'] ?? '');
$visitor_ip     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$user_agent     = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Prevent crawler spam / bot hits
if (preg_match('/(bot|crawl|spider|slurp|facebookexternalhit|whatsapp|preview)/i', $user_agent)) {
    echo json_encode(['success' => true, 'ignored' => 'crawler']);
    exit;
}

$event_id = logAnalyticsEvent(
    $conn,
    $tenant_id,
    $company_id,
    $event_type,
    $event_category,
    $event_label,
    $traffic_source,
    $page_url,
    $referrer,
    $session_id,
    $visitor_ip,
    $user_agent
);

echo json_encode([
    'success'  => (bool)$event_id,
    'event_id' => $event_id
]);
exit;
