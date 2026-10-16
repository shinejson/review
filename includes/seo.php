<?php
/**
 * ============================================================
 *  Optibiz — shared SEO / social metadata helper
 * ============================================================
 *
 * Every public page should call `seo_head([...])` inside its <head>.
 * It renders, in one place and consistently:
 *
 *   - <title>                  (caller keeps full control of the copy)
 *   - canonical URL            (absolute, built from trusted config only)
 *   - robots directives        (incl. max-image-preview:large)
 *   - Open Graph tags          (Facebook, LinkedIn, WhatsApp, Slack…)
 *   - Twitter/X card tags
 *   - favicon + apple-touch-icon + theme-color
 *   - JSON-LD structured data  (Organization, WebSite, BreadcrumbList…)
 *
 * Security note
 * -------------
 * Canonical / OG URLs are NEVER built straight from $_SERVER['HTTP_HOST'].
 * The Host header is attacker controllable, and an absolute URL that echoes
 * it back can be used for cache poisoning and password-reset link hijacking.
 * We resolve the base in this order and stop at the first that works:
 *
 *   1. APP_BASE_URL           — operator-configured, trusted
 *   2. getPlatformBaseUrl()   — validated against OPTIBIZ_ALLOWED_HOSTS
 *   3. getAppWebRoot()        — host-relative path (e.g. "/rate"), used as a
 *                               last resort so the canonical is still valid
 *                               (relative canonicals are legal per RFC 6596)
 *
 * Requires: config/database.php (for $conn) and includes/functions.php
 * (which pulls in sa_helpers.php, giving us sa_setting()).
 */

if (!defined('OPTIBIZ_SEO_LOADED')) {
    define('OPTIBIZ_SEO_LOADED', true);

    /** Absolute base URL with no trailing slash — '' when it cannot be trusted. */
    if (!function_exists('seo_base_url')) {
        function seo_base_url()
        {
            // 1. Operator-configured canonical origin. Most reliable.
            if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
                $url = rtrim((string) APP_BASE_URL, '/');
                if (preg_match('~^https?://~i', $url)) {
                    return $url;
                }
            }

            // 2. Host-allow-list validated reconstruction.
            if (function_exists('getPlatformBaseUrl')) {
                $url = getPlatformBaseUrl();
                if (!empty($url)) {
                    return rtrim($url, '/');
                }
            }

            // 3. Fall back to a host-relative base such as "/rate".
            if (function_exists('getAppWebRoot')) {
                return rtrim((string) getAppWebRoot(), '/');
            }

            return '';
        }
    }

    /**
     * Absolute base URL, using the request host as a last resort.
     *
     * Only use this where a relative URL is INVALID — i.e. sitemaps, which
     * must contain absolute URLs. The Host header is sanitised hard here
     * (strict hostname pattern, no credentials, no path injection) and this is
     * only reached when neither APP_BASE_URL nor OPTIBIZ_ALLOWED_HOSTS is
     * configured. Never use it to build a link that is emailed to a user.
     *
     * @return string Absolute base URL, or '' when nothing trustworthy exists.
     */
    if (!function_exists('seo_absolute_base_url')) {
        function seo_absolute_base_url()
        {
            $base = seo_base_url();
            if ($base !== '' && preg_match('~^https?://~i', $base)) {
                return $base;
            }

            // seo_base_url() returned a host-relative path (or nothing) because
            // the operator has not configured APP_BASE_URL. Fall back to the
            // request host, but validate it first.
            $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
            if ($host === '') {
                return '';
            }
            // Strip any port for validation, then re-attach the validated value.
            if (!preg_match('~^\[?[a-zA-Z0-9._:\-\]]+\]?(?::\d{1,5})?$~', $host) || strlen($host) > 255) {
                return '';
            }
            // Reject anything that could smuggle a path, credentials or CRLF.
            if (preg_match('~[/\\\\@\s]~', $host)) {
                return '';
            }

            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
                || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
            $scheme = $https ? 'https' : 'http';

            // $base is the host-relative app root such as "/rate" (or '').
            return rtrim($scheme . '://' . $host . $base, '/');
        }
    }

    /** Absolute (or root-relative) URL for an app-relative path. */
    if (!function_exists('seo_url')) {
        function seo_url($path = '')
        {
            $path = ltrim((string) $path, '/');
            $base = seo_base_url();
            if ($base === '') {
                return $path === '' ? '/' : '/' . $path;
            }
            return $path === '' ? $base . '/' : $base . '/' . $path;
        }
    }

    /**
     * Read a platform setting without exploding on pages that have no
     * database connection yet (install / error states).
     */
    if (!function_exists('seo_setting')) {
        function seo_setting($key, $default = '')
        {
            if (!function_exists('sa_setting')) {
                return $default;
            }
            $conn = isset($GLOBALS['conn']) ? $GLOBALS['conn'] : null;
            if (!$conn) {
                return $default;
            }
            try {
                $value = sa_setting($conn, $key, $default);
            } catch (Throwable $e) {
                return $default;
            }
            return ($value === '' || $value === null) ? $default : $value;
        }
    }

    /**
     * The public brand name.
     *
     * Comes from Control Center → Settings → Branding (`site_name`) so the
     * schema stays in sync if you rebrand. The default row created by
     * database.sql is the placeholder "Company Rating SaaS"; if that is still
     * in place we fall back to the real brand rather than publishing a
     * nonsensical Organization name in the structured data.
     */
    if (!function_exists('seo_platform_name')) {
        function seo_platform_name()
        {
            $name = trim((string) seo_setting('site_name', 'Optibiz'));
            if ($name === '' || strcasecmp($name, 'Company Rating SaaS') === 0) {
                return 'Optibiz';
            }
            return $name;
        }
    }

    /** Collapse whitespace and hard-truncate to a sensible SERP length. */
    if (!function_exists('seo_clip')) {
        function seo_clip($text, $max = 160)
        {
            $text = trim(preg_replace('~\s+~u', ' ', (string) $text));
            if ($text === '') {
                return '';
            }
            if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
                $cut = mb_substr($text, 0, $max - 1, 'UTF-8');
                // Trim back to the last word boundary so we do not split a word.
                if (preg_match('~^(.*)\s\S*$~us', $cut, $m) && $m[1] !== '') {
                    $cut = $m[1];
                }
                return rtrim($cut, ' ,;:.-') . '…';
            }
            if (strlen($text) > $max) {
                return rtrim(substr($text, 0, $max - 3)) . '...';
            }
            return $text;
        }
    }

    /** Turn a stored favicon/logo value into an absolute URL when possible. */
    if (!function_exists('seo_asset_url')) {
        function seo_asset_url($value, $fallbackPath = '')
        {
            $value = trim((string) $value);
            if ($value !== '' && preg_match('~^https?://~i', $value)) {
                return $value;
            }
            if ($value !== '' && function_exists('sa_brand_asset')) {
                $resolved = sa_brand_asset($value);
                if ($resolved !== '') {
                    $value = $resolved;
                }
            }
            if ($value === '') {
                return $fallbackPath === '' ? '' : seo_url($fallbackPath);
            }
            // Relative path under the app root.
            return seo_url(ltrim($value, '/'));
        }
    }

    /**
     * Render the full SEO <head> block.
     *
     * @param array $o {
     *   @type string $title        Page title. Required.
     *   @type string $description  Meta description. Required for good SEO.
     *   @type string $path         App-relative path, '' for the homepage.
     *   @type string $image        og:image (absolute, or app-relative).
     *   @type string $image_alt    og:image:alt.
     *   @type string $type         og:type, default 'website'.
     *   @type string $robots       Default 'index, follow, max-image-preview:large'.
     *   @type array  $jsonld       Extra JSON-LD nodes (single node or list).
     *   @type array  $breadcrumbs  [['Home', ''], ['Pricing', 'pricing.php']]
     *   @type string $twitter_site @twitter handle for the platform, e.g. '@optibiz'.
     *   @type bool   $omit_org     Skip the site-wide Organization/WebSite graph.
     * }
     */
    if (!function_exists('seo_head')) {
        function seo_head(array $o)
        {
            $siteName = isset($o['site_name']) ? (string) $o['site_name'] : seo_platform_name();
            $title    = isset($o['title']) ? trim((string) $o['title']) : $siteName;
            $desc     = seo_clip(isset($o['description']) ? $o['description'] : '', 158);
            $path     = isset($o['path']) ? (string) $o['path'] : '';
            $canonical = isset($o['canonical']) && $o['canonical'] !== ''
                ? (string) $o['canonical']
                : seo_url($path);
            $type     = isset($o['type']) ? (string) $o['type'] : 'website';
            $robots   = isset($o['robots']) ? (string) $o['robots']
                : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';

            // --- Social image -------------------------------------------------
            $image     = isset($o['image']) && $o['image'] !== ''
                ? $o['image']
                : 'assets/images/og-default.png';
            $imageUrl  = seo_asset_url($image);
            // Default the alt text to the title, but do not repeat the brand
            // when the title already contains it ("…Platform — Optibiz").
            $imageAlt  = isset($o['image_alt']) ? trim((string) $o['image_alt'])
                : (stripos($title, $siteName) !== false ? $title : ($title . ' — ' . $siteName));
            // Dimensions matter: without them Facebook/X cannot reserve the
            // right slot and may delay rendering the card. Override whenever
            // you pass a custom 'image' whose size differs from the default.
            $imageW    = (int) (isset($o['image_width']) ? $o['image_width'] : 1200);
            $imageH    = (int) (isset($o['image_height']) ? $o['image_height'] : 630);
            $imageType = isset($o['image_type']) ? (string) $o['image_type'] : 'image/png';

            // --- Favicon ------------------------------------------------------
            // A tenant can override the icon from Control Center → Branding.
            $favicon = seo_asset_url(seo_setting('favicon_asset', ''), 'assets/images/favicon/favicon.svg');
            $pngIcon = seo_url('assets/images/favicon/favicon-192.png');
            $appleIcon = seo_url('assets/images/favicon/apple-touch-icon.png');

            $twitterSite = seo_setting('seo_twitter_site', isset($o['twitter_site']) ? $o['twitter_site'] : '');

            // --- Emit ---------------------------------------------------------
            echo "    <meta name=\"robots\" content=\"" . htmlspecialchars($robots, ENT_QUOTES, 'UTF-8') . "\">\n";
            echo "    <link rel=\"canonical\" href=\"" . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . "\">\n";
            echo "\n";

            echo "    <!-- Favicon / app icons -->\n";
            if ($favicon !== '') {
                echo "    <link rel=\"icon\" href=\"" . htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8') . "\">\n";
            }
            echo "    <link rel=\"icon\" type=\"image/png\" sizes=\"192x192\" href=\"" . htmlspecialchars($pngIcon, ENT_QUOTES, 'UTF-8') . "\">\n";
            echo "    <link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"" . htmlspecialchars($appleIcon, ENT_QUOTES, 'UTF-8') . "\">\n";
            echo "    <meta name=\"theme-color\" content=\"#0f2438\">\n";
            echo "\n";

            echo "    <!-- Open Graph (Facebook, LinkedIn, WhatsApp, Slack) -->\n";
            echo "    <meta property=\"og:site_name\" content=\"" . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . "\">\n";
            echo "    <meta property=\"og:type\" content=\"" . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . "\">\n";
            echo "    <meta property=\"og:title\" content=\"" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "\">\n";
            if ($desc !== '') {
                echo "    <meta property=\"og:description\" content=\"" . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . "\">\n";
            }
            echo "    <meta property=\"og:url\" content=\"" . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . "\">\n";
            if ($imageUrl !== '') {
                echo "    <meta property=\"og:image\" content=\"" . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . "\">\n";
                echo "    <meta property=\"og:image:alt\" content=\"" . htmlspecialchars($imageAlt, ENT_QUOTES, 'UTF-8') . "\">\n";
                echo "    <meta property=\"og:image:type\" content=\"" . htmlspecialchars($imageType, ENT_QUOTES, 'UTF-8') . "\">\n";
                echo "    <meta property=\"og:image:width\" content=\"" . $imageW . "\">\n";
                echo "    <meta property=\"og:image:height\" content=\"" . $imageH . "\">\n";
            }
            echo "    <meta property=\"og:locale\" content=\"en_GH\">\n";
            echo "\n";

            echo "    <!-- Twitter / X card -->\n";
            echo "    <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
            echo "    <meta name=\"twitter:title\" content=\"" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "\">\n";
            if ($desc !== '') {
                echo "    <meta name=\"twitter:description\" content=\"" . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . "\">\n";
            }
            if ($imageUrl !== '') {
                echo "    <meta name=\"twitter:image\" content=\"" . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . "\">\n";
                echo "    <meta name=\"twitter:image:alt\" content=\"" . htmlspecialchars($imageAlt, ENT_QUOTES, 'UTF-8') . "\">\n";
            }
            if ($twitterSite !== '') {
                echo "    <meta name=\"twitter:site\" content=\"" . htmlspecialchars($twitterSite, ENT_QUOTES, 'UTF-8') . "\">\n";
            }
            echo "\n";

            // --- JSON-LD ------------------------------------------------------
            $nodes = array();

            if (empty($o['omit_org'])) {
                $nodes[] = seo_organization_node($siteName);
                $nodes[] = seo_website_node($siteName);
            }

            if (!empty($o['breadcrumbs']) && is_array($o['breadcrumbs'])) {
                $node = seo_breadcrumb_node($o['breadcrumbs'], $siteName);
                if ($node) {
                    $nodes[] = $node;
                }
            }

            if (!empty($o['jsonld'])) {
                $extra = $o['jsonld'];
                // Accept a single node (@type present) or a list of nodes.
                if (isset($extra['@type'])) {
                    $nodes[] = $extra;
                } else {
                    foreach ($extra as $node) {
                        if (is_array($node) && !empty($node['@type'])) {
                            $nodes[] = $node;
                        }
                    }
                }
            }

            if (!empty($nodes)) {
                $graph = array(
                    '@context' => 'https://schema.org',
                    '@graph'   => $nodes,
                );
                $json = json_encode(
                    $graph,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                );
                if ($json !== false) {
                    // Guard against a stray "</script>" breaking out of the element.
                    $json = str_replace('</', '<\/', $json);
                    echo "    <!-- Structured data (JSON-LD) -->\n";
                    echo "    <script type=\"application/ld+json\">\n" . $json . "\n    </script>\n";
                }
            }
        }
    }

    /** Organization node — the entity behind the whole site. */
    if (!function_exists('seo_organization_node')) {
        function seo_organization_node($siteName = 'Optibiz')
        {
            $base = seo_base_url();
            $home = $base === '' ? '/' : $base . '/';

            $node = array(
                '@type' => 'Organization',
                '@id'   => $home . '#organization',
                'name'  => $siteName,
                'url'   => $home,
            );

            $logo = seo_asset_url(seo_setting('logo_asset', ''));
            if ($logo !== '') {
                $node['logo'] = array(
                    '@type'      => 'ImageObject',
                    'url'        => $logo,
                    'caption'    => $siteName,
                );
            } else {
                // No logo configured in Control Center → Branding yet. Fall back
                // to the square brand mark rather than the 1200x630 social card,
                // because Google expects a logo-shaped image here.
                $node['logo'] = array(
                    '@type'   => 'ImageObject',
                    'url'     => seo_url('assets/images/favicon/favicon-512.png'),
                    'caption' => $siteName,
                );
            }

            $sameAs = array();
            foreach (array(
                'social_facebook',
                'social_twitter',
                'social_linkedin',
                'social_instagram',
            ) as $key) {
                $url = trim((string) seo_setting($key, ''));
                if ($url !== '' && preg_match('~^https?://~i', $url)) {
                    $sameAs[] = $url;
                }
            }
            if (!empty($sameAs)) {
                $node['sameAs'] = array_values(array_unique($sameAs));
            }

            $email = trim((string) seo_setting('support_email', ''));
            $phone = trim((string) seo_setting('support_phone', ''));
            if ($email !== '' || $phone !== '') {
                $contact = array('@type' => 'ContactPoint', 'contactType' => 'customer support');
                if ($email !== '') {
                    $contact['email'] = $email;
                }
                if ($phone !== '') {
                    $contact['telephone'] = $phone;
                }
                $contact['availableLanguage'] = array('en');
                $node['contactPoint'] = $contact;
            }

            return $node;
        }
    }

    /** WebSite node — helps Google associate the site with the brand name. */
    if (!function_exists('seo_website_node')) {
        function seo_website_node($siteName = 'Optibiz')
        {
            $base = seo_base_url();
            $home = $base === '' ? '/' : $base . '/';
            return array(
                '@type'       => 'WebSite',
                '@id'         => $home . '#website',
                'url'         => $home,
                'name'        => $siteName,
                'inLanguage'  => 'en-GH',
                'publisher'   => array('@id' => $home . '#organization'),
            );
        }
    }

    /** BreadcrumbList node. `$trail` is a list of [label, path] pairs. */
    if (!function_exists('seo_breadcrumb_node')) {
        function seo_breadcrumb_node(array $trail, $siteName = 'Optibiz')
        {
            if (empty($trail)) {
                return null;
            }
            $items = array();
            $position = 1;
            foreach ($trail as $crumb) {
                $label = isset($crumb[0]) ? (string) $crumb[0] : '';
                $path  = isset($crumb[1]) ? (string) $crumb[1] : '';
                if ($label === '') {
                    continue;
                }
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $position++,
                    'name'     => $label,
                    'item'     => seo_url($path),
                );
            }
            if (empty($items)) {
                return null;
            }
            return array(
                '@type'           => 'BreadcrumbList',
                'itemListElement' => $items,
            );
        }
    }

    /** Convenience: mark utility/transactional pages as non-indexable. */
    if (!function_exists('seo_noindex')) {
        function seo_noindex()
        {
            return 'noindex, nofollow, noarchive, noimageindex';
        }
    }
}
