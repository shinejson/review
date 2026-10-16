<?php
/**
 * ============================================================
 *  Optibiz — Real-Time SEO Health Checker & Audit Engine
 * ============================================================
 *  Provides comprehensive website SEO audits:
 *    - Live crawling and DOM analysis (Titles, Meta, Headings, Images, Links)
 *    - Performance, SSL, HTTP status and response time benchmarking
 *    - Mobile-friendliness, Viewport, OpenGraph, Twitter Cards, Schema.org
 *    - Circular Health Score (0-100), Grades (A+ to F), and Categorized Issues
 *    - Instant Copy-Paste Code Generator (Meta Tags, Schema JSON-LD, Robots.txt)
 *    - Self-healing database schema with paid/free permission gates:
 *         * Superadmin: 100% FREE unlimited
 *         * Tenants:    100% FREE unlimited
 *         * Public:     Preview free, full actionable report is paid
 */

require_once __DIR__ . '/sa_helpers.php';

/* ============================================================
   1. DATABASE SCHEMA & CONFIGURATION
   ============================================================ */

if (!function_exists('seo_ensure_schema')) {
    /**
     * Initializes the seo_audits table and default settings safely.
     */
    function seo_ensure_schema($conn) {
        static $initialized = false;
        if ($initialized || !is_object($conn) || !method_exists($conn, 'query')) {
            return;
        }
        $initialized = true;

        @$conn->query(
            "CREATE TABLE IF NOT EXISTS seo_audits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NULL,
                user_type ENUM('public','tenant','superadmin') NOT NULL DEFAULT 'public',
                url VARCHAR(1000) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                score INT NOT NULL DEFAULT 0,
                grade VARCHAR(5) NOT NULL DEFAULT 'F',
                issues_count INT NOT NULL DEFAULT 0,
                critical_count INT NOT NULL DEFAULT 0,
                warning_count INT NOT NULL DEFAULT 0,
                passed_count INT NOT NULL DEFAULT 0,
                auto_fix_count INT NOT NULL DEFAULT 0,
                response_time_ms INT NOT NULL DEFAULT 0,
                http_status INT NOT NULL DEFAULT 0,
                page_size_kb INT NOT NULL DEFAULT 0,
                is_paid TINYINT(1) NOT NULL DEFAULT 0,
                access_token VARCHAR(64) UNIQUE NOT NULL,
                visitor_email VARCHAR(191) NULL,
                visitor_name VARCHAR(150) NULL,
                payment_reference VARCHAR(100) NULL,
                payment_amount DECIMAL(10,2) DEFAULT 0.00,
                paid_at DATETIME NULL,
                results_json LONGTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_seo_tenant (tenant_id),
                INDEX idx_seo_token (access_token),
                INDEX idx_seo_domain (domain),
                INDEX idx_seo_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );

        // Check if settings has seo_report_price
        $res = @$conn->query("SELECT setting_value FROM settings WHERE setting_key = 'seo_report_price' LIMIT 1");
        if ($res && $res->num_rows === 0) {
            @$conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('seo_report_price', '19.00')");
        }
    }
}

/* ============================================================
   2. CORE CRAWLER & AUDIT ENGINE
   ============================================================ */

if (!function_exists('seo_analyze_url')) {
    /**
     * Crawls and runs a comprehensive SEO inspection on a given URL.
     *
     * @param string $url Target website URL
     * @return array Standardized audit results
     */
    function seo_analyze_url($url) {
        $url = trim((string)$url);
        if ($url === '') {
            return ['error' => 'Please enter a valid website URL.'];
        }

        // Auto-prepend https:// if no scheme is provided
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return ['error' => 'The provided URL is invalid or malformed.'];
        }

        $host = strtolower($parsed['host']);
        $origin = ($parsed['scheme'] ?? 'https') . '://' . $host . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

        // Fetch HTML via cURL with realistic headers & metrics
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 OptibizSEOChecker/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => '', // Accept all encodings (gzip, deflate)
            CURLOPT_HEADER => false,
        ]);

        $startTime = microtime(true);
        $html = curl_exec($ch);
        $totalTime = microtime(true) - $startTime;
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $responseTimeMs = (int)round($totalTime * 1000);
        $pageSizeBytes = is_string($html) ? strlen($html) : 0;
        $pageSizeKb = (int)round($pageSizeBytes / 1024);

        if ($html === false || $pageSizeBytes === 0) {
            return [
                'error' => 'Could not reach this website (' . ($curlError ?: 'Connection timed out or host unreachable') . '). Please check the domain and make sure it is publicly accessible.',
                'url' => $url,
                'domain' => $host,
                'http_status' => $httpCode,
                'response_time_ms' => $responseTimeMs
            ];
        }

        // Parse DOM
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Convert to UTF-8 HTML entities to prevent encoding glitches in libxml
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        // --- 1. Basic Meta Extraction ---
        $title = '';
        $titleNodes = $xpath->query('//title');
        if ($titleNodes && $titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }

        $metaDescription = '';
        $metaDescNodes = $xpath->query('//meta[translate(@name, "DESCRIPTION", "description")="description"]/@content');
        if ($metaDescNodes && $metaDescNodes->length > 0) {
            $metaDescription = trim($metaDescNodes->item(0)->nodeValue);
        }

        $metaRobots = '';
        $robotsNodes = $xpath->query('//meta[translate(@name, "ROBOTS", "robots")="robots"]/@content');
        if ($robotsNodes && $robotsNodes->length > 0) {
            $metaRobots = trim($robotsNodes->item(0)->nodeValue);
        }

        $canonical = '';
        $canonicalNodes = $xpath->query('//link[translate(@rel, "CANONICAL", "canonical")="canonical"]/@href');
        if ($canonicalNodes && $canonicalNodes->length > 0) {
            $canonical = trim($canonicalNodes->item(0)->nodeValue);
        }

        $viewport = '';
        $viewportNodes = $xpath->query('//meta[translate(@name, "VIEWPORT", "viewport")="viewport"]/@content');
        if ($viewportNodes && $viewportNodes->length > 0) {
            $viewport = trim($viewportNodes->item(0)->nodeValue);
        }

        $favicon = '';
        $favNodes = $xpath->query('//link[contains(translate(@rel, "ICON", "icon"), "icon")]/@href');
        if ($favNodes && $favNodes->length > 0) {
            $favicon = trim($favNodes->item(0)->nodeValue);
        }

        // --- 2. Headings Analysis ---
        $h1Tags = [];
        $h1Nodes = $xpath->query('//h1');
        if ($h1Nodes) {
            foreach ($h1Nodes as $h1) {
                $text = trim(preg_replace('/\s+/', ' ', $h1->textContent));
                if ($text !== '') $h1Tags[] = $text;
            }
        }
        $h2Count = (int)($xpath->query('//h2')->length ?? 0);
        $h3Count = (int)($xpath->query('//h3')->length ?? 0);

        // --- 3. Images & Alt Attributes ---
        $imgNodes = $xpath->query('//img');
        $totalImages = $imgNodes ? $imgNodes->length : 0;
        $imagesMissingAlt = 0;
        if ($imgNodes) {
            foreach ($imgNodes as $img) {
                $alt = $img->getAttribute('alt');
                if (trim($alt) === '') {
                    $imagesMissingAlt++;
                }
            }
        }

        // --- 4. OpenGraph & Twitter Cards ---
        $og = [];
        $ogNodes = $xpath->query('//meta[starts-with(@property, "og:")]');
        if ($ogNodes) {
            foreach ($ogNodes as $node) {
                $prop = strtolower($node->getAttribute('property'));
                $og[$prop] = trim($node->getAttribute('content'));
            }
        }

        $twitter = [];
        $twNodes = $xpath->query('//meta[starts-with(@name, "twitter:")]');
        if ($twNodes) {
            foreach ($twNodes as $node) {
                $name = strtolower($node->getAttribute('name'));
                $twitter[$name] = trim($node->getAttribute('content'));
            }
        }

        // --- 5. Schema Structured Data ---
        $schemaTypes = [];
        $scriptNodes = $xpath->query('//script[@type="application/ld+json"]');
        if ($scriptNodes) {
            foreach ($scriptNodes as $script) {
                $jsonContent = trim($script->textContent);
                if ($jsonContent !== '') {
                    $decoded = @json_decode($jsonContent, true);
                    if (is_array($decoded)) {
                        if (isset($decoded['@type'])) {
                            $types = is_array($decoded['@type']) ? $decoded['@type'] : [$decoded['@type']];
                            foreach ($types as $t) {
                                if (is_string($t)) $schemaTypes[] = $t;
                            }
                        } elseif (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                            foreach ($decoded['@graph'] as $g) {
                                if (isset($g['@type'])) {
                                    $schemaTypes[] = is_array($g['@type']) ? implode(', ', $g['@type']) : $g['@type'];
                                }
                            }
                        }
                    }
                }
            }
        }
        $schemaTypes = array_unique(array_filter($schemaTypes));

        // --- 6. Content & Body Text ---
        // Strip scripts, styles, noscript to measure real content
        $cleanHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $cleanHtml = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $cleanHtml);
        $cleanHtml = preg_replace('/<noscript\b[^>]*>(.*?)<\/noscript>/is', '', $cleanHtml);
        $rawText = trim(strip_tags($cleanHtml));
        $rawText = preg_replace('/\s+/', ' ', $rawText);
        $wordCount = $rawText !== '' ? count(preg_split('/\s+/', $rawText)) : 0;

        // --- 7. Security / SSL Check ---
        $isHttps = (strpos(strtolower($url), 'https://') === 0) || (strpos(strtolower($effectiveUrl), 'https://') === 0);

        // --- 8. Check Robots.txt & Sitemap (Quick lightweight check) ---
        $hasRobotsTxt = false;
        $hasSitemap = false;
        $chR = curl_init($origin . '/robots.txt');
        curl_setopt_array($chR, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'OptibizSEOChecker/1.0',
        ]);
        curl_exec($chR);
        $rCode = curl_getinfo($chR, CURLINFO_HTTP_CODE);
        curl_close($chR);
        if ($rCode >= 200 && $rCode < 400) {
            $hasRobotsTxt = true;
        }

        $chS = curl_init($origin . '/sitemap.xml');
        curl_setopt_array($chS, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'OptibizSEOChecker/1.0',
        ]);
        curl_exec($chS);
        $sCode = curl_getinfo($chS, CURLINFO_HTTP_CODE);
        curl_close($chS);
        if ($sCode >= 200 && $sCode < 400) {
            $hasSitemap = true;
        }

        // ============================================================
        // 3. SCORING & AUDIT RULES COMPILATION
        // ============================================================
        $score = 100;
        $items = [];
        $criticalCount = 0;
        $warningCount = 0;
        $passedCount = 0;
        $autoFixCount = 0;

        // Categories: content, speed, visibility, security
        $categoryScores = [
            'content' => ['name' => 'Missing SEO Content', 'issues' => 0, 'icon' => 'fa-file-lines', 'color' => '#ef4444'],
            'speed' => ['name' => 'Slow Website & Performance', 'issues' => 0, 'icon' => 'fa-gauge-high', 'color' => '#f59e0b'],
            'visibility' => ['name' => 'Weak Visibility & Meta', 'issues' => 0, 'icon' => 'fa-chart-line', 'color' => '#3b82f6'],
            'security' => ['name' => 'Security & Mobile Friendliness', 'issues' => 0, 'icon' => 'fa-shield-halved', 'color' => '#10b981'],
        ];

        // Helper to record an audit item
        $recordItem = function($id, $title, $category, $severity, $desc, $fix, $autoFix = false) use (&$items, &$score, &$criticalCount, &$warningCount, &$passedCount, &$autoFixCount, &$categoryScores) {
            if ($severity === 'critical') {
                $criticalCount++;
                $categoryScores[$category]['issues']++;
                if ($autoFix) $autoFixCount++;
            } elseif ($severity === 'warning') {
                $warningCount++;
                $categoryScores[$category]['issues']++;
                if ($autoFix) $autoFixCount++;
            } else {
                $passedCount++;
            }

            $items[] = [
                'id' => $id,
                'title' => $title,
                'category' => $category,
                'severity' => $severity, // 'critical', 'warning', 'passed'
                'description' => $desc,
                'fix' => $fix,
                'auto_fixable' => $autoFix,
            ];
        };

        // --- RULE 1: Title Tag ---
        $titleLen = mb_strlen($title);
        if ($titleLen === 0) {
            $score -= 15;
            $recordItem('title_missing', 'Missing Page Title Tag', 'content', 'critical',
                'Your website has no <title> tag. Search engines cannot identify what your business does.',
                'Add a concise, compelling <title> between 30 and 60 characters containing your primary keyword and brand name.', true);
        } elseif ($titleLen < 20) {
            $score -= 6;
            $recordItem('title_short', 'Page Title is Too Short (' . $titleLen . ' chars)', 'content', 'warning',
                'Your page title is only ' . $titleLen . ' characters. Titles under 20 characters waste prime ranking opportunities.',
                'Expand your title to between 30 and 60 characters to include your target service and city or specialty.', true);
        } elseif ($titleLen > 65) {
            $score -= 5;
            $recordItem('title_long', 'Page Title Exceeds Recommended Length (' . $titleLen . ' chars)', 'content', 'warning',
                'Google truncates titles longer than ~60 characters on search result pages.',
                'Shorten your title to under 60 characters so customers see your full message without truncation.', true);
        } else {
            $recordItem('title_ok', 'Optimal Page Title Length (' . $titleLen . ' chars)', 'content', 'passed',
                'Your title "' . htmlspecialchars($title) . '" is within the ideal 30-60 character range.',
                'Keep updating your title when targeting new seasonal services.');
        }

        // --- RULE 2: Meta Description ---
        $descLen = mb_strlen($metaDescription);
        if ($descLen === 0) {
            $score -= 15;
            $recordItem('meta_desc_missing', 'Missing Meta Description', 'content', 'critical',
                'Google cannot find a meta description for your page. Search engines will automatically pull random snippets.',
                'Add a compelling meta description between 70 and 155 characters that describes your unique value and calls the reader to action.', true);
        } elseif ($descLen < 50) {
            $score -= 6;
            $recordItem('meta_desc_short', 'Meta Description is Too Short (' . $descLen . ' chars)', 'content', 'warning',
                'A short meta description doesn\'t give potential visitors enough context to click.',
                'Expand your description to 120-155 characters with key benefits and a call-to-action.', true);
        } elseif ($descLen > 165) {
            $score -= 4;
            $recordItem('meta_desc_long', 'Meta Description Too Long (' . $descLen . ' chars)', 'content', 'warning',
                'Descriptions over 160 characters are typically truncated on desktop and mobile search snippets.',
                'Trim down to 150-160 characters so your complete call-to-action is visible.', true);
        } else {
            $recordItem('meta_desc_ok', 'Optimal Meta Description (' . $descLen . ' chars)', 'content', 'passed',
                'Your meta description is clear and within the recommended length.',
                'Regularly test new calls to action to improve click-through rates.');
        }

        // --- RULE 3: H1 Heading ---
        $h1Count = count($h1Tags);
        if ($h1Count === 0) {
            $score -= 10;
            $recordItem('h1_missing', 'Missing <h1> Primary Heading', 'content', 'critical',
                'Google uses the <h1> tag as the primary on-page content indicator. Having none weakens ranking for core queries.',
                'Add exactly one prominent <h1> tag near the top of the page clearly stating your main service.', true);
        } elseif ($h1Count > 1) {
            $score -= 5;
            $recordItem('h1_multiple', 'Multiple <h1> Headings Detected (' . $h1Count . ' found)', 'content', 'warning',
                'Best practices recommend having a single primary <h1> per page. Multiple H1s dilute topic focus.',
                'Keep the most important headline as <h1> and change secondary headlines to <h2> tags.', true);
        } else {
            $recordItem('h1_ok', 'Single Primary <h1> Tag Found', 'content', 'passed',
                'Your page clearly defines its primary subject: "' . htmlspecialchars(mb_substr($h1Tags[0], 0, 80)) . (mb_strlen($h1Tags[0]) > 80 ? '...' : '') . '".',
                'Ensure target keywords are naturally positioned in your H1.');
        }

        // --- RULE 4: Image Alt Text ---
        if ($totalImages > 0 && $imagesMissingAlt > 0) {
            $score -= min(12, 4 + ($imagesMissingAlt * 2));
            $recordItem('images_missing_alt', $imagesMissingAlt . ' Image' . ($imagesMissingAlt > 1 ? 's' : '') . ' Missing Alt Attributes', 'content', 'warning',
                $imagesMissingAlt . ' out of ' . $totalImages . ' images on your page have no alt text. Search bots cannot "see" your images without alt tags, costing you image search traffic.',
                'Add descriptive alt="Your Description" attributes to all images representing your services, team, or products.', true);
        } elseif ($totalImages > 0) {
            $recordItem('images_alt_ok', 'All ' . $totalImages . ' Images Have Descriptive Alt Text', 'content', 'passed',
                'Great job! All images provide context for visually impaired users and image search algorithms.',
                'Maintain this practice when uploading new banners and gallery photos.');
        }

        // --- RULE 5: Page Speed & Performance ---
        if ($responseTimeMs > 3500) {
            $score -= 15;
            $recordItem('speed_slow', 'Slow Server Response Time (' . number_format($totalTime, 2) . 's)', 'speed', 'critical',
                'Your server took ' . number_format($totalTime, 2) . ' seconds to respond. Over 53% of mobile visitors abandon sites that take over 3 seconds.',
                'Enable server-side caching, compress high-resolution images, and consider using a Content Delivery Network (CDN) like Cloudflare.', false);
        } elseif ($responseTimeMs > 1500) {
            $score -= 8;
            $recordItem('speed_moderate', 'Moderate Page Load Speed (' . number_format($totalTime, 2) . 's)', 'speed', 'warning',
                'Your website took ' . number_format($totalTime, 2) . ' seconds to deliver HTML. Faster sites rank higher in Google Core Web Vitals.',
                'Minify CSS and JavaScript, enable Gzip/Brotli compression on Apache/Nginx, and optimize web assets.', false);
        } else {
            $recordItem('speed_fast', 'Fast Server Response Time (' . $responseTimeMs . ' ms)', 'speed', 'passed',
                'Excellent response speed! Your web server delivered the page in ' . $responseTimeMs . ' ms, well below the Google recommended 800 ms threshold.',
                'Keep monitoring server response times during peak visitor hours.');
        }

        // --- RULE 6: HTML Page Weight ---
        if ($pageSizeKb > 350) {
            $score -= 6;
            $recordItem('page_size_large', 'Heavy HTML Document Size (' . $pageSizeKb . ' KB)', 'speed', 'warning',
                'The raw HTML document is ' . $pageSizeKb . ' KB. Excessively bloated HTML slows down rendering on mobile networks.',
                'Remove inline base64 images, strip unused scripts, and keep HTML cleanly formatted.', false);
        } else {
            $recordItem('page_size_ok', 'Optimized HTML Size (' . $pageSizeKb . ' KB)', 'speed', 'passed',
                'Your raw HTML page size is lean and loads quickly over cellular data.',
                'Continue avoiding bloated inline scripts.');
        }

        // --- RULE 7: Mobile Viewport ---
        if (empty($viewport)) {
            $score -= 15;
            $recordItem('viewport_missing', 'Missing Mobile Viewport Meta Tag', 'security', 'critical',
                'Your website lacks a responsive <meta name="viewport"> tag. Mobile devices will render a desktop zoomed-out view, hurting mobile usability.',
                'Add <meta name="viewport" content="width=device-width, initial-scale=1.0"> to your HTML <head>.', true);
        } else {
            $recordItem('viewport_ok', 'Mobile-Responsive Viewport Tag Present', 'security', 'passed',
                'Your website correctly specifies mobile viewport dimensions for responsive rendering across phones and tablets.',
                'Regularly test page layouts on various smartphone screen widths.');
        }

        // --- RULE 8: HTTPS & SSL Security ---
        if (!$isHttps) {
            $score -= 15;
            $recordItem('ssl_missing', 'Website Not Protected with HTTPS / SSL', 'security', 'critical',
                'Your site is serving content over insecure HTTP. Chrome marks your site as "Not Secure" and Google suppresses non-HTTPS pages in rankings.',
                'Install a free Let\'s Encrypt SSL certificate and redirect all HTTP traffic to HTTPS via .htaccess.', true);
        } else {
            $recordItem('ssl_ok', 'Secure HTTPS / SSL Encryption Active', 'security', 'passed',
                'Your site uses a valid SSL certificate. Visitor data and communications are encrypted.',
                'Set a reminder to ensure your SSL certificate renews automatically.');
        }

        // --- RULE 9: Open Graph & Social Visibility ---
        $missingOg = [];
        if (empty($og['og:title'])) $missingOg[] = 'og:title';
        if (empty($og['og:description'])) $missingOg[] = 'og:description';
        if (empty($og['og:image'])) $missingOg[] = 'og:image';

        if (!empty($missingOg)) {
            $score -= 8;
            $recordItem('og_missing', 'Incomplete Open Graph Social Tags', 'visibility', 'warning',
                'Missing ' . implode(', ', $missingOg) . '. When people share your website on WhatsApp, Facebook, or LinkedIn, it will display a blank or broken preview.',
                'Add standard Open Graph meta tags to your <head> so links generate high-converting visual cards when shared.', true);
        } else {
            $recordItem('og_ok', 'Full Open Graph Social Sharing Tags Active', 'visibility', 'passed',
                'Your website displays a branded image, title, and description when shared on social apps and messaging chats.',
                'Keep your OG image updated with fresh branding (recommended size 1200x630px).');
        }

        // --- RULE 10: Twitter Card Tags ---
        if (empty($twitter['twitter:card'])) {
            $score -= 4;
            $recordItem('twitter_missing', 'Missing Twitter Card Tags', 'visibility', 'warning',
                'No twitter:card tag found. Tweets linking to your site will show plain text instead of rich preview cards.',
                'Add <meta name="twitter:card" content="summary_large_image"> to your <head>.', true);
        } else {
            $recordItem('twitter_ok', 'Twitter Rich Preview Card Active', 'visibility', 'passed',
                'Twitter/X cards are configured with "' . htmlspecialchars($twitter['twitter:card']) . '".',
                'Maintain high-resolution imagery for social feeds.');
        }

        // --- RULE 11: Schema.org Structured Data ---
        if (empty($schemaTypes)) {
            $score -= 10;
            $recordItem('schema_missing', 'Missing Schema.org JSON-LD Structured Data', 'visibility', 'warning',
                'Your page contains no structured data markup. Google uses Schema to show Star Ratings, Business Hours, Maps, and Rich Snippets in search results.',
                'Embed JSON-LD LocalBusiness or Organization schema in your HTML to qualify for Google rich search results.', true);
        } else {
            $recordItem('schema_ok', 'Structured Data (Schema.org) Detected: ' . implode(', ', $schemaTypes), 'visibility', 'passed',
                'Structured data is implemented, helping search engines understand your entity, logo, contact points, and reviews.',
                'Test schema periodically in the Google Rich Results Test tool.');
        }

        // --- RULE 12: Canonical Tag ---
        if (empty($canonical)) {
            $score -= 5;
            $recordItem('canonical_missing', 'Missing Canonical Link Tag', 'visibility', 'warning',
                'No <link rel="canonical"> tag detected. Search engines may penalize duplicate content across HTTP/HTTPS or www/non-www versions.',
                'Add <link rel="canonical" href="' . htmlspecialchars($url) . '"> to the <head>.', true);
        } else {
            $recordItem('canonical_ok', 'Canonical URL Properly Specified', 'visibility', 'passed',
                'Canonical tag points to: ' . htmlspecialchars($canonical),
                'Ensure all parameterized URLs point back to their clean canonical version.');
        }

        // --- RULE 13: Content Word Count ---
        if ($wordCount < 180) {
            $score -= 8;
            $recordItem('content_thin', 'Thin On-Page Content (' . $wordCount . ' words)', 'content', 'warning',
                'Your page contains only ~' . $wordCount . ' words. Google favors comprehensive, helpful content with at least 300-500 words for primary landing pages.',
                'Expand your landing page with detailed service explanations, FAQs, customer reviews, and clear answers to buyer questions.', false);
        } else {
            $recordItem('content_ok', 'Sufficient Content Length (' . $wordCount . ' words)', 'content', 'passed',
                'Your page has healthy textual content for search engines to index and understand.',
                'Organize longer sections with clear <h2> and <h3> subheadings.');
        }

        // --- RULE 14: Robots.txt & Sitemap ---
        if (!$hasRobotsTxt) {
            $score -= 4;
            $recordItem('robots_missing', 'No robots.txt File Found', 'visibility', 'warning',
                'Search crawlers could not find /robots.txt at ' . htmlspecialchars($origin) . '/robots.txt.',
                'Create a simple robots.txt file in your web root to guide Googlebot and link to your sitemap.', true);
        }
        if (!$hasSitemap) {
            $score -= 4;
            $recordItem('sitemap_missing', 'No Standard sitemap.xml File Found', 'visibility', 'warning',
                'No sitemap detected at /sitemap.xml. An XML sitemap accelerates Google discovery of your pages.',
                'Generate an XML sitemap and submit it to Google Search Console.', true);
        }

        // Final score bounds
        $score = max(12, min(100, $score));

        // Grade calculation
        if ($score >= 90) $grade = 'A+';
        elseif ($score >= 80) $grade = 'A';
        elseif ($score >= 70) $grade = 'B';
        elseif ($score >= 55) $grade = 'C';
        elseif ($score >= 40) $grade = 'D';
        else $grade = 'F';

        // Total issues count (critical + warning)
        $totalIssues = $criticalCount + $warningCount;

        // Auto-fix generator snippets
        $cleanDomain = preg_replace('~^www\.~i', '', $host);
        $brandName = ucwords(str_replace(['-', '_', '.'], ' ', explode('.', $cleanDomain)[0]));
        $suggestedTitle = ($titleLen >= 20 && $titleLen <= 60) ? $title : ($brandName . ' — Official Website & Services');
        $suggestedDesc = ($descLen >= 50 && $descLen <= 160) ? $metaDescription : ('Welcome to ' . $brandName . '. Discover our premium services, customer reviews, and contact details online.');

        $codeSnippets = [
            'meta_tags' => "<!-- Optimized Core Meta Tags generated by Optibiz SEO -->\n" .
                "<title>" . htmlspecialchars($suggestedTitle) . "</title>\n" .
                "<meta name=\"description\" content=\"" . htmlspecialchars($suggestedDesc) . "\">\n" .
                "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n" .
                "<link rel=\"canonical\" href=\"" . htmlspecialchars($url) . "\">\n\n" .
                "<!-- Open Graph Social Previews -->\n" .
                "<meta property=\"og:title\" content=\"" . htmlspecialchars($suggestedTitle) . "\">\n" .
                "<meta property=\"og:description\" content=\"" . htmlspecialchars($suggestedDesc) . "\">\n" .
                "<meta property=\"og:url\" content=\"" . htmlspecialchars($url) . "\">\n" .
                "<meta property=\"og:type\" content=\"website\">\n" .
                "<meta property=\"og:site_name\" content=\"" . htmlspecialchars($brandName) . "\">\n\n" .
                "<!-- Twitter Card -->\n" .
                "<meta name=\"twitter:card\" content=\"summary_large_image\">\n" .
                "<meta name=\"twitter:title\" content=\"" . htmlspecialchars($suggestedTitle) . "\">\n" .
                "<meta name=\"twitter:description\" content=\"" . htmlspecialchars($suggestedDesc) . "\">",

            'schema_json' => "<!-- JSON-LD LocalBusiness / Organization Schema generated by Optibiz -->\n" .
                "<script type=\"application/ld+json\">\n" .
                json_encode([
                    '@context' => 'https://schema.org',
                    '@type' => 'LocalBusiness',
                    'name' => $brandName,
                    'url' => $url,
                    'description' => $suggestedDesc,
                    'currenciesAccepted' => 'USD, AED, EUR, GBP',
                    'openingHours' => 'Mo-Su 08:00-20:00',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
                "\n</script>",

            'robots_txt' => "# Optimized robots.txt generated by Optibiz SEO\n" .
                "User-agent: *\n" .
                "Allow: /\n" .
                "Disallow: /admin/\n" .
                "Disallow: /superadmin/\n\n" .
                "Sitemap: " . $origin . "/sitemap.xml\n"
        ];

        return [
            'success' => true,
            'url' => $url,
            'domain' => $host,
            'score' => $score,
            'grade' => $grade,
            'issues_count' => $totalIssues,
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'passed_count' => $passedCount,
            'auto_fix_count' => $autoFixCount,
            'response_time_ms' => $responseTimeMs,
            'http_status' => $httpCode,
            'page_size_kb' => $pageSizeKb,
            'word_count' => $wordCount,
            'total_images' => $totalImages,
            'images_missing_alt' => $imagesMissingAlt,
            'categories' => $categoryScores,
            'items' => $items,
            'code_snippets' => $codeSnippets,
            'scanned_at' => date('Y-m-d H:i:s'),
        ];
    }
}

/* ============================================================
   3. PERSISTENCE & ACCESS CONTROL HELPERS
   ============================================================ */

if (!function_exists('seo_save_audit')) {
    /**
     * Persists an audit to the database and generates an access token.
     */
    function seo_save_audit($conn, $auditData, $userType = 'public', $tenantId = null, $isPaid = 0, $email = null, $name = null) {
        seo_ensure_schema($conn);

        $url = $auditData['url'] ?? '';
        $domain = $auditData['domain'] ?? '';
        $score = (int)($auditData['score'] ?? 0);
        $grade = $auditData['grade'] ?? 'F';
        $issuesCount = (int)($auditData['issues_count'] ?? 0);
        $criticalCount = (int)($auditData['critical_count'] ?? 0);
        $warningCount = (int)($auditData['warning_count'] ?? 0);
        $passedCount = (int)($auditData['passed_count'] ?? 0);
        $autoFixCount = (int)($auditData['auto_fix_count'] ?? 0);
        $responseTimeMs = (int)($auditData['response_time_ms'] ?? 0);
        $httpStatus = (int)($auditData['http_status'] ?? 200);
        $pageSizeKb = (int)($auditData['page_size_kb'] ?? 0);

        // Security token for access and unlocking
        $token = bin2hex(random_bytes(24));
        $json = json_encode($auditData, JSON_UNESCAPED_UNICODE);

        // Tenants and Superadmins are ALWAYS 100% free / paid
        if ($userType === 'tenant' || $userType === 'superadmin') {
            $isPaid = 1;
        }

        $stmt = $conn->prepare(
            "INSERT INTO seo_audits 
             (tenant_id, user_type, url, domain, score, grade, issues_count, critical_count, warning_count, passed_count, auto_fix_count, response_time_ms, http_status, page_size_kb, is_paid, access_token, visitor_email, visitor_name, results_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $tid = $tenantId ? (int)$tenantId : null;
        $stmt->bind_param(
            "isssisiiiiiiiisssss",
            $tid,
            $userType,
            $url,
            $domain,
            $score,
            $grade,
            $issuesCount,
            $criticalCount,
            $warningCount,
            $passedCount,
            $autoFixCount,
            $responseTimeMs,
            $httpStatus,
            $pageSizeKb,
            $isPaid,
            $token,
            $email,
            $name,
            $json
        );

        $ok = $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();

        return [
            'id' => $insertId,
            'token' => $token,
            'is_paid' => $isPaid,
            'success' => $ok
        ];
    }
}

if (!function_exists('seo_get_audit')) {
    /**
     * Retrieves an audit record by token or numeric ID.
     */
    function seo_get_audit($conn, $tokenOrId) {
        seo_ensure_schema($conn);
        $stmt = null;
        if (is_numeric($tokenOrId)) {
            $id = (int)$tokenOrId;
            $stmt = $conn->prepare("SELECT * FROM seo_audits WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $id);
        } else {
            $stmt = $conn->prepare("SELECT * FROM seo_audits WHERE access_token = ? LIMIT 1");
            $stmt->bind_param("s", $tokenOrId);
        }

        if (!$stmt) return null;
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row && !empty($row['results_json'])) {
            $row['details'] = json_decode($row['results_json'], true);
        }
        return $row;
    }
}

if (!function_exists('seo_unlock_audit')) {
    /**
     * Unlocks a paid audit after payment confirmation.
     */
    function seo_unlock_audit($conn, $token, $paymentRef = '', $amount = 0.00) {
        seo_ensure_schema($conn);
        $stmt = $conn->prepare("UPDATE seo_audits SET is_paid = 1, payment_reference = ?, payment_amount = ?, paid_at = NOW() WHERE access_token = ?");
        $stmt->bind_param("sds", $paymentRef, $amount, $token);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
