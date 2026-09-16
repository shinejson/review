<?php
/**
 * Optibiz REST API - Standardized JSON Response & CORS Helper
 */
require_once dirname(__DIR__) . '/config.php';

// Automatically handle CORS and Preflight OPTIONS for all API requests
function api_init_cors() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With, X-Device-Fingerprint, X-Api-Key");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 86400"); // 24 hours preflight cache
    header("Content-Type: application/json; charset=UTF-8");

    // Preflight request terminates immediately with 204 No Content
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Send standard successful JSON response
function api_send_success($data = null, $message = 'Success', $status = 200, array $meta = []) {
    http_response_code($status);
    $response = [
        'success'   => true,
        'status'    => $status,
        'message'   => $message,
        'timestamp' => time()
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    if (!empty($meta)) {
        $response['meta'] = $meta;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Send standard error JSON response
function api_send_error($message = 'An error occurred', $status = 400, $errors = null) {
    http_response_code($status);
    $response = [
        'success'   => false,
        'status'    => $status,
        'message'   => $message,
        'timestamp' => time()
    ];

    if ($errors !== null) {
        $response['errors'] = $errors;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Parse request body supporting both JSON and multipart form data
function api_get_request_body() {
    $input = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }
    }

    // Merge with standard POST / GET if available
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = array_merge($_POST, $input);
    }

    return $input;
}

// Extract real client IP
function api_get_client_ip() {
    $keys = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',  // Proxies / Load Balancers
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'            // Standard PHP IP
    ];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip  = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '127.0.0.1';
}

// Initialize CORS on include
api_init_cors();
