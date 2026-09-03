<?php
/**
 * Shared HTML head for every page.
 *
 * Optional variables a page can set before including this file:
 *   $pageTitle  string    <title> text
 *   $BASE       string    path prefix back to the app root ('', '../', ...)
 *   $extraCss   string[]  extra stylesheets, resolved through sa_asset()
 *   $theme      string    'dark' | 'light' — server-side default (dark).
 *                         Super admin pages override it from localStorage
 *                         via the bootstrap snippet below.
 *   $bodyClass  string    extra class(es) for <body>
 *   $robots     string    robots meta content, e.g. 'noindex, nofollow'
 *                         for the admin panels. Omit on public pages.
 *   $extraHead  string    raw markup appended to <head>
 */

if (!isset($BASE)) {
    // Work out how deep the running script sits below the app root, so
    // assets resolve correctly for index.php, superadmin/x.php, etc.
    // Define BASE_PATH in config/database.php to override.
    if (defined('BASE_PATH')) {
        $BASE = BASE_PATH;
    } else {
        $__sa_root = dirname(__DIR__);
        $__sa_script = isset($_SERVER['SCRIPT_FILENAME']) && $_SERVER['SCRIPT_FILENAME'] !== ''
            ? dirname($_SERVER['SCRIPT_FILENAME'])
            : getcwd();
        $__sa_depth = 0;
        while ($__sa_script !== $__sa_root && $__sa_depth < 6 && $__sa_script !== '/' && $__sa_script !== '.') {
            $__sa_script = dirname($__sa_script);
            $__sa_depth++;
        }
        $BASE = $__sa_depth > 0 ? str_repeat('../', $__sa_depth) : '';
    }
}
$GLOBALS['BASE'] = $BASE;

if (!function_exists('sa_asset')) {
    /**
     * Build a path to a project asset that works no matter how deep the
     * current page sits, and no matter which subdirectory the app is
     * deployed into (e.g. /htdocs/review/).
     *
     * Local CSS/JS files get a ?v=<file mtime> cache-buster so that edits
     * show up instantly, even with long-lived browser caches.
     */
    function sa_asset($path, $base = null)
    {
        if ($base === null) {
            $base = isset($GLOBALS['BASE']) ? $GLOBALS['BASE'] : '';
        }
        $path = ltrim($path, '/');
        $url  = $base . $path;

        if (strpos($path, '://') === false && preg_match('~\.(?:css|js)$~i', $path)) {
            $file  = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $mtime = is_file($file) ? @filemtime($file) : false;
            if ($mtime) {
                $url .= '?v=' . $mtime;
            }
        }
        return $url;
    }
}

$sa_theme    = (isset($theme) && $theme === 'light') ? 'light' : 'dark';
$sa_page     = isset($pageTitle) ? $pageTitle : 'Optibiz';
$sa_body_cls = isset($bodyClass) ? ' ' . trim($bodyClass) : '';

// Pages written against main's convention echo $assetBase before an asset
// path, so expose the same prefix under that name as well.
$assetBase = rtrim($BASE, '/');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $sa_theme; ?>" class="sa-preload">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php if (!empty($robots)): ?>
    <meta name="robots" content="<?php echo htmlspecialchars($robots); ?>">
<?php endif; ?>
    <title><?php echo htmlspecialchars($sa_page); ?> &middot; Optibiz</title>
    <?php if (function_exists('sa_platform_favicon')): ?>
    <?php $fav_url = sa_platform_favicon($conn); ?>
    <?php if ($fav_url): ?>
    <link rel="icon" href="<?php echo sa_e($fav_url); ?>">
    <?php endif; ?>
    <?php endif; ?>
    <style>
        /* Keeps the first paint from flashing while the saved theme loads */
        html.sa-preload *, html.sa-preload *::before, html.sa-preload *::after {
            transition: none !important;
        }
    </style>
    <link rel="stylesheet" href="<?php echo sa_asset('assets/css/style.css'); ?>">
    <?php if (isset($extraBrandLink)): ?>
    <?php echo $extraBrandLink; ?>
    <?php endif; ?>
<?php if (!empty($extraCss)): ?>
<?php foreach ((array) $extraCss as $extraCssFile): ?>
    <link rel="stylesheet" href="<?php echo sa_asset($extraCssFile); ?>">
<?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($extraHead)): ?>
    <?php echo $extraHead; ?>
<?php endif; ?>
</head>
<body class="<?php echo htmlspecialchars(ltrim($sa_body_cls)); ?>">
<script>
/* Apply the remembered theme before anything paints */
(function () {
    try {
        var t = localStorage.getItem('optibiz-sa-theme');
        if (t === 'light' || t === 'dark') {
            document.documentElement.setAttribute('data-theme', t);
        }
    } catch (e) {}
})();
</script>
