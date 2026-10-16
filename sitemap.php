<?php
/**
 * ============================================================
 *  Optibiz — dynamic XML sitemap
 * ============================================================
 *
 * Serves /rate/sitemap.xml (see the rewrite rule in .htaccess).
 *
 * A sitemap does not make a page rank by itself, but it is how you get
 * complete and fast *discovery* — especially for the long tail of company
 * profile pages, which are only ever linked from paginated directory listings
 * and would otherwise take a very long time for crawlers to find.
 *
 * Included:
 *   - the static marketing pages (index, features, pricing, directory, …)
 *   - every public company rating page that has a UUID
 *
 * Notes
 *   - Google's limits are 50,000 URLs and 50 MB (uncompressed) per sitemap.
 *     This generator emits one file and stops at SITEMAP_MAX_URLS; if you outgrow
 *     it, split into a sitemap index (sitemap.xml -> sitemap-1.xml, …).
 *   - Sitemaps MUST contain absolute URLs, so this file uses
 *     seo_absolute_base_url() rather than the relative-capable seo_url().
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo.php';

// Fail quietly with a valid (empty) sitemap instead of a PHP error page,
// which would be cached by crawlers as a broken sitemap.
$base = seo_absolute_base_url();
if ($base === '') {
    header('Content-Type: application/xml; charset=UTF-8');
    header('HTTP/1.1 500 Internal Server Error');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<!-- Sitemap unavailable: set APP_BASE_URL in .env so absolute URLs can be built. -->' . "\n";
    exit;
}

const SITEMAP_MAX_URLS = 50000;

/**
 * W3C datetime for a MySQL date/datetime string. Returns '' when unparseable
 * so we simply omit lastmod rather than emit an invalid value.
 */
function sitemap_w3c_date($value)
{
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false || $ts <= 0) {
        return '';
    }
    return gmdate('Y-m-d\TH:i:s+00:00', $ts);
}

function sitemap_url($loc, $lastmod = '', $changefreq = '', $priority = '')
{
    $loc = htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $out = "  <url>\n    <loc>" . $loc . "</loc>\n";
    if ($lastmod !== '') {
        $out .= '    <lastmod>' . $lastmod . "</lastmod>\n";
    }
    if ($changefreq !== '') {
        $out .= '    <changefreq>' . $changefreq . "</changefreq>\n";
    }
    if ($priority !== '') {
        $out .= '    <priority>' . $priority . "</priority>\n";
    }
    $out .= "  </url>\n";
    return $out;
}

// ---------------------------------------------------------------------------
// Static marketing pages
// ---------------------------------------------------------------------------
$static_pages = array(
    array('',             'daily',   '1.0'),
    array('features.php', 'weekly',  '0.9'),
    array('pricing.php',  'weekly',  '0.9'),
    array('companies.php', 'daily',  '0.9'),
    array('seo-checker.php', 'monthly', '0.7'),
);

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$url_count = 0;
$newest_static = '';

foreach ($static_pages as $page) {
    list($path, $changefreq, $priority) = $page;
    $xml .= sitemap_url(rtrim($base, '/') . '/' . $path, '', $changefreq, $priority);
    $url_count++;
}

// ---------------------------------------------------------------------------
// Public company rating pages
// ---------------------------------------------------------------------------
// lastmod uses the newest review for that business, falling back to the date
// the profile was created. Companies without a UUID have no clean public URL
// and are skipped (they are still reachable through the directory).
$companies_sql = "
    SELECT c.id, c.company_name, c.uuid, c.created_at,
           (SELECT MAX(r.created_at) FROM ratings r
             WHERE r.company_id = c.id AND r.reported = 0) AS last_review_at
      FROM customers c
     WHERE c.uuid IS NOT NULL AND c.uuid <> ''
     ORDER BY c.id ASC
     LIMIT " . (int) SITEMAP_MAX_URLS . "
";

$res = @$conn->query($companies_sql);
if ($res) {
    while (($row = $res->fetch_assoc()) && $url_count < SITEMAP_MAX_URLS) {
        $uuid = trim((string) $row['uuid']);
        if ($uuid === '') {
            continue;
        }
        $lastmod = sitemap_w3c_date($row['last_review_at']);
        if ($lastmod === '') {
            $lastmod = sitemap_w3c_date($row['created_at']);
        }
        $xml .= sitemap_url(rtrim($base, '/') . '/' . $uuid, $lastmod, 'weekly', '0.6');
        $url_count++;
    }
}

$xml .= '</urlset>' . "\n";

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
// Noindex is unnecessary for sitemaps; the cache header stops every crawler
// hit from re-running the company query dozens of times a day.
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');

echo $xml;
