<?php
/**
 * Optibiz REST API v1 Configuration
 */
defined('OPTIBIZ_API_V1') or define('OPTIBIZ_API_V1', true);
defined('RATE_ROOT_PATH') or define('RATE_ROOT_PATH', dirname(__DIR__, 2));

// Environment-aware JWT secret — NO fallback. A high-entropy secret MUST be
// provided via the OPTIBIZ_JWT_SECRET environment variable. Falling back to
// a hard-coded value would let anyone who reads the source forge tokens.
$jwtSecret = getenv('OPTIBIZ_JWT_SECRET');
if (!$jwtSecret) {
    $jwtSecret = getenv('JWT_SECRET');      // common alternative variable name
}
if (!$jwtSecret || strlen($jwtSecret) < 32) {
    // Fail hard so misconfiguration is caught at deploy time, not silently
    // used against production traffic.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "FATAL: OPTIBIZ_JWT_SECRET is not set or is too short (<32 chars).\n");
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'status' => 503, 'message' => 'API configuration error. Contact your administrator.']);
    exit;
}
define('API_JWT_SECRET', $jwtSecret);
define('API_JWT_ISSUER', 'optibiz-api');
define('API_JWT_AUDIENCE', 'optibiz-mobile-app');

// Token lifetimes (in seconds)
define('API_JWT_ACCESS_EXPIRY', 86400);        // 24 Hours
define('API_JWT_REFRESH_EXPIRY', 2592000);     // 30 Days

// Rate Limiting Defaults
define('API_RATE_LIMIT_SUBMIT_MAX', 5);        // 5 submissions
define('API_RATE_LIMIT_SUBMIT_WINDOW', 600);   // per 10 minutes (600s)
define('API_RATE_LIMIT_GENERAL_MAX', 60);      // 60 requests
define('API_RATE_LIMIT_GENERAL_WINDOW', 60);   // per minute (60s)
