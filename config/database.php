<?php
// Helper to read environment variables reliably across CLI, Apache, FastCGI, and hosts with putenv disabled
if (!function_exists('optibiz_env')) {
    function optibiz_env($key, $default = '') {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        if (function_exists('getenv')) {
            $val = @getenv($key);
            if ($val !== false && $val !== '') {
                return $val;
            }
        }
        return $default;
    }
}

// Lightweight .env file loader for environments where server env vars cannot be set directly.
// Checks .env as well as env (for environments where dotfiles are hidden or renamed).
$envCandidates = [
    dirname(__DIR__) . '/.env',
    dirname(__DIR__) . '/env',
    dirname(__DIR__) . '/.env.local',
    dirname(__DIR__) . '/.env.production',
];

foreach ($envCandidates as $envFile) {
    if (is_file($envFile) && is_readable($envFile)) {
        $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (strpos($line, '=') !== false) {
                    list($envName, $envVal) = explode('=', $line, 2);
                    $envName = trim($envName);
                    $envVal  = trim($envVal);
                    // Strip inline comment if not quoted
                    if (!preg_match('/^([\'"]).*\1$/', $envVal)) {
                        if (strpos($envVal, '#') !== false) {
                            $parts = explode('#', $envVal, 2);
                            $envVal = trim($parts[0]);
                        }
                    }
                    $envVal = trim($envVal, " \t\n\r\0\x0B\"'");

                    // If existing value is a dummy/placeholder like your-production-domain.com, allow override
                    $existing = $_ENV[$envName] ?? $_SERVER[$envName] ?? '';
                    $isPlaceholder = ($existing === '' || strpos($existing, 'your-production-domain.com') !== false || strpos($existing, 'yourdomain.com') !== false);

                    if ($isPlaceholder || (!array_key_exists($envName, $_SERVER) && !array_key_exists($envName, $_ENV))) {
                        $_ENV[$envName] = $envVal;
                        $_SERVER[$envName] = $envVal;
                        if (function_exists('putenv')) {
                            @putenv("$envName=$envVal");
                        }
                    }
                }
            }
        }
    }
}

// Error reporting: never display raw PHP errors in production to avoid leaking sensitive paths and credentials
$isDev = (optibiz_env('APP_ENV') === 'development');
if (!$isDev) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Database configuration — read credentials from the environment.
// Never hard-code production credentials in source; the defaults below
// are dev-only and MUST be overridden via environment variables.
define('DB_HOST', optibiz_env('OPTIBIZ_DB_HOST', 'localhost'));
define('DB_USER', optibiz_env('OPTIBIZ_DB_USER', 'root'));
define('DB_PASS', optibiz_env('OPTIBIZ_DB_PASS', ''));
define('DB_NAME', optibiz_env('OPTIBIZ_DB_NAME', 'company_rating_saas'));

// Application base URL — used for password-reset links, canonical URLs, and emails.
// MUST be set in production to the canonical public URL. Falls back
// to a safe relative path during local development only.
define('APP_BASE_URL', optibiz_env('APP_BASE_URL', ''));

// Public alias for the tenant admin panel. Requests to the real
// /rate/admin/... path are refused by .htaccess; the panel is served at
// /rate/<ADMIN_PATH_ALIAS>/... instead.
// If you change this value, update the matching RewriteRule slug in
// .htaccess (or set OPTIBIZ_ADMIN_PATH_ALIAS and keep both in sync).
$adminAlias = optibiz_env('OPTIBIZ_ADMIN_PATH_ALIAS');
define('ADMIN_PATH_ALIAS', ($adminAlias && preg_match('/^[a-z0-9]{12,40}$/', $adminAlias))
    ? $adminAlias
    : 'p7xk2mqw9vrt4zhn');

// Create connection safely
try {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception($conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    die('A database connection error occurred. Please try again later.');
}
?>