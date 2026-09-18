<?php
// Lightweight .env file loader for environments where server env vars cannot be set directly
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($envName, $envVal) = explode('=', $line, 2);
            $envName = trim($envName);
            $envVal  = trim($envVal, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($envName, $_SERVER) && !array_key_exists($envName, $_ENV)) {
                putenv("$envName=$envVal");
                $_ENV[$envName] = $envVal;
                $_SERVER[$envName] = $envVal;
            }
        }
    }
}

// Error reporting: never display raw PHP errors in production to avoid leaking sensitive paths and credentials
$isDev = (getenv('APP_ENV') === 'development');
if (!$isDev) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Database configuration — read credentials from the environment.
// Never hard-code production credentials in source; the defaults below
// are dev-only and MUST be overridden via environment variables.
define('DB_HOST', getenv('OPTIBIZ_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('OPTIBIZ_DB_USER') ?: 'root');
define('DB_PASS', getenv('OPTIBIZ_DB_PASS') ?: '');
define('DB_NAME', getenv('OPTIBIZ_DB_NAME') ?: 'company_rating_saas');

// Application base URL — used for password-reset links and emails.
// MUST be set in production to the canonical public URL. Falls back
// to a safe relative path during local development only.
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: '');

// Public alias for the tenant admin panel. Requests to the real
// /rate/admin/... path are refused by .htaccess; the panel is served at
// /rate/<ADMIN_PATH_ALIAS>/... instead.
// If you change this value, update the matching RewriteRule slug in
// .htaccess (or set OPTIBIZ_ADMIN_PATH_ALIAS and keep both in sync).
$adminAlias = getenv('OPTIBIZ_ADMIN_PATH_ALIAS');
define('ADMIN_PATH_ALIAS', ($adminAlias && preg_match('/^[a-z0-9]{12,40}$/', $adminAlias))
    ? $adminAlias
    : 'p7xk2mqw9vrt4zhn');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Fail closed — never leak raw database error text to the browser.
if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    die('A database connection error occurred. Please try again later.');
}

$conn->set_charset("utf8mb4");
?>
