<?php
// Helper to read environment variables reliably across CLI, Apache, FastCGI, and hosts with putenv disabled
if (!function_exists('optibiz_env')) {
    function optibiz_env($key, $default = '', $allowEmpty = false) {
        if (array_key_exists($key, $_ENV) && ($allowEmpty || $_ENV[$key] !== '')) {
            return $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && ($allowEmpty || $_SERVER[$key] !== '')) {
            return $_SERVER[$key];
        }
        if (function_exists('getenv')) {
            $val = @getenv($key);
            if ($val !== false && ($allowEmpty || $val !== '')) {
                return $val;
            }
        }
        return $default;
    }
}

// Lightweight .env file loader for environments where server env vars cannot be set directly.
// Checks .env.local first (for local overrides), then .env, env, and .env.production.
$envCandidates = [
    dirname(__DIR__) . '/.env.local',
    dirname(__DIR__) . '/.env',
    dirname(__DIR__) . '/env',
    dirname(__DIR__) . '/.env.production',
];

$loadedEnvKeys = [];
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

                    // If an earlier candidate file (e.g. .env.local) already defined this key, do not overwrite it
                    if (isset($loadedEnvKeys[$envName])) {
                        continue;
                    }

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
                    $loadedEnvKeys[$envName] = true;
                }
            }
        }
    }
}

// Local custom database configuration override if present (ignored in git)
if (is_file(__DIR__ . '/database.local.php')) {
    include_once __DIR__ . '/database.local.php';
}

// Detect if running on localhost / developer workstation
$rawHttpHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$isLocalhost = (
    strpos($rawHttpHost, 'localhost') !== false ||
    strpos($rawHttpHost, '127.0.0.1') !== false ||
    $rawHttpHost === '::1' ||
    (PHP_SAPI === 'cli' && empty(optibiz_env('RAILWAY_ENVIRONMENT')) && empty(optibiz_env('DYNO')) && empty(getenv('KUBERNETES_SERVICE_HOST')))
);

// Error reporting: never display raw PHP errors in production to avoid leaking sensitive paths and credentials
$isDev = (optibiz_env('APP_ENV') === 'development') || ($isLocalhost && optibiz_env('APP_ENV') !== 'production');
if (!$isDev) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

// Database configuration — read credentials from the environment.
// Supports both OPTIBIZ_DB_* and standard Railway/Docker MYSQL* variables.
$resolvedHost = optibiz_env('OPTIBIZ_DB_HOST', optibiz_env('MYSQLHOST', 'localhost'));
$resolvedUser = optibiz_env('OPTIBIZ_DB_USER', optibiz_env('MYSQLUSER', 'root'));
$resolvedPass = optibiz_env('OPTIBIZ_DB_PASS', optibiz_env('MYSQLPASSWORD', '', true), true);
$resolvedName = optibiz_env('OPTIBIZ_DB_NAME', optibiz_env('MYSQLDATABASE', 'company_rating_saas'));
$resolvedPort = (int)optibiz_env('OPTIBIZ_DB_PORT', optibiz_env('MYSQLPORT', '3306'));

// If running on localhost and the host is still pointing to the remote/internal InfinityFree host
// (which cannot resolve or connect from outside InfinityFree's servers), use local XAMPP MySQL credentials.
if ($isLocalhost && $resolvedHost === 'sql107.infinityfree.com') {
    $resolvedHost = '127.0.0.1';
    $resolvedUser = 'root';
    $resolvedPass = '';
    $resolvedName = 'company_rating_saas';
    $resolvedPort = 3306;
}

if (!defined('DB_HOST')) define('DB_HOST', $resolvedHost);
if (!defined('DB_USER')) define('DB_USER', $resolvedUser);
if (!defined('DB_PASS')) define('DB_PASS', $resolvedPass);
if (!defined('DB_NAME')) define('DB_NAME', $resolvedName);
if (!defined('DB_PORT')) define('DB_PORT', $resolvedPort > 0 ? $resolvedPort : 3306);

// Application base URL — used for password-reset links, canonical URLs, and emails.
// Automatically resolves Railway public domain if present and APP_BASE_URL is not set.
$publicDomain = optibiz_env('RAILWAY_PUBLIC_DOMAIN', '');
$defaultBaseUrl = $publicDomain ? ('https://' . $publicDomain) : '';
$resolvedBaseUrl = optibiz_env('APP_BASE_URL', $defaultBaseUrl);

// If running on localhost, automatically align APP_BASE_URL to the active request host and port
if ($isLocalhost) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($rawHttpHost !== '') {
        $resolvedBaseUrl = $scheme . '://' . $rawHttpHost . '/rate';
    } elseif ($resolvedBaseUrl === '' || strpos($resolvedBaseUrl, 'optibiz.rf.gd') !== false) {
        $resolvedBaseUrl = 'http://localhost:8080/rate';
    }
}
if (!defined('APP_BASE_URL')) define('APP_BASE_URL', $resolvedBaseUrl);

// Public alias for the tenant admin panel. Requests to the real
// /rate/admin/... path are refused by .htaccess; the panel is served at
// /rate/<ADMIN_PATH_ALIAS>/... instead.
// If you change this value, update the matching RewriteRule slug in
// .htaccess (or set OPTIBIZ_ADMIN_PATH_ALIAS and keep both in sync).
$adminAlias = optibiz_env('OPTIBIZ_ADMIN_PATH_ALIAS');
if (!defined('ADMIN_PATH_ALIAS')) {
    define('ADMIN_PATH_ALIAS', ($adminAlias && preg_match('/^[a-z0-9]{12,40}$/', $adminAlias))
        ? $adminAlias
        : 'p7xk2mqw9vrt4zhn');
}

// Create connection safely
try {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    if ($conn->connect_error) {
        throw new Exception($conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    // If the configured host failed and we are running on localhost / dev machine,
    // attempt local MySQL fallback (127.0.0.1:3306, user root, empty pass)
    if ($isLocalhost && DB_HOST !== '127.0.0.1' && DB_HOST !== 'localhost') {
        try {
            $fallbackHost = '127.0.0.1';
            $fallbackUser = 'root';
            $fallbackPass = '';
            $fallbackName = DB_NAME ?: 'company_rating_saas';
            $fallbackPort = 3306;
            $fallbackConn = @new mysqli($fallbackHost, $fallbackUser, $fallbackPass, $fallbackName, $fallbackPort);
            if (!$fallbackConn->connect_error) {
                $fallbackConn->set_charset("utf8mb4");
                $conn = $fallbackConn;
                goto optibiz_db_connected;
            }
        } catch (Throwable $fallbackEx) {
            // Local fallback also failed; proceed to normal error response below
        }
    }

    error_log('Database connection failed: ' . $e->getMessage());
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    if ($isLocalhost) {
        die('A database connection error occurred on localhost: ' . htmlspecialchars($e->getMessage()) . '<br><br>Make sure MySQL is running in your XAMPP Control Panel and database "' . htmlspecialchars(DB_NAME) . '" exists.');
    } else {
        die('A database connection error occurred. Please try again later.');
    }
}
optibiz_db_connected:
?>