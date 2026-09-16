<?php
/**
 * ============================================================
 *  Company Engagement API Endpoint (Follow & Like)
 * ============================================================
 *  Handles instant, interactive visitor & customer follows and
 *  likes for a company on their public rating page.
 *
 *  POST parameters:
 *    action:     'follow' | 'like'
 *    company_id: ID of the company
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action     = strtolower(trim((string)($_POST['action'] ?? '')));
$company_id = (int)($_POST['company_id'] ?? 0);
$user_ip    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$user_agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

if (!in_array($action, ['follow', 'like'], true) || $company_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit;
}

$res = toggleCompanyEngagement($conn, $company_id, $action, $user_ip, $user_agent);

echo json_encode($res);
