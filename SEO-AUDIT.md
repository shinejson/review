# Optibiz — SEO & Deployment Readiness Review

**Scope:** `index.php` (SEO deep-dive) + the other public marketing pages, plus a
general pass on deployment/market-standard readiness.
**Date:** 2026-10-16 · **Branch:** `arena/3af3410a-review`
**Deployment assumed:** `https://YOUR-DOMAIN/rate/` (matches `RewriteBase /rate/`
in `.htaccess` and `APP_BASE_URL` in `.env.example`)

---

## 1. Executive summary

The product and the page content are strong — clear value proposition, real
social proof pulled from the database, and a well-structured single-page
narrative. What was missing was **everything a search engine or a social
network needs in order to interpret the page**.

Concretely, before this review the public pages had:

| Signal | Before | After |
|---|---|---|
| `<link rel="canonical">` | ❌ none on any marketing page | ✅ |
| Open Graph (`og:*`) | ❌ none | ✅ |
| Twitter/X card | ❌ none | ✅ |
| JSON-LD structured data | ❌ none | ✅ Organization, WebSite, SoftwareApplication, FAQPage, BreadcrumbList |
| Favicon / apple-touch-icon | ❌ none | ✅ generated brand icon set |
| `robots.txt` | ❌ none | ✅ added (must be published at domain root) |
| `sitemap.xml` | ❌ none | ✅ dynamic, includes every company profile |
| `lang` on `<html>` | `en` | ✅ `en-GH` |
| Skip link / `<main>` landmark | ❌ | ✅ |
| Dead `href="#"` links | 9 | ✅ 0 |

**The single most important thing to understand:** none of these were
"nice-to-haves". Without a canonical URL you risk duplicate-content dilution
across `/rate/`, `/rate/index.php` and every query-string variant. Without
Open Graph, every WhatsApp/LinkedIn share of Optibiz renders as a bare grey
link. Without structured data, Google has to guess what Optibiz *is*.

Also flagged: **one urgent, non-SEO security issue** (§5.1) — the repository is
public and contains a live database dump.

---

## 2. SEO findings — `index.php`

### 2.1 Critical (now fixed)

#### 2.1.1 No canonical URL
Every public URL variant (`/rate/`, `/rate/index.php`, `/rate/?utm_source=…`)
was a separate "page" to Google. Added `<link rel="canonical">` on all five
public pages.

#### 2.1.2 No Open Graph or Twitter card
Shares on WhatsApp, LinkedIn, Facebook, Slack and X rendered with no title,
no description and no image. Added full `og:*` + `twitter:*` blocks, backed by
a generated 1200×630 `assets/images/og-default.png` (social networks do **not**
render SVG for `og:image`, which is why a PNG was generated).

#### 2.1.3 No structured data
Added a JSON-LD `@graph`:
- **Organization** — name, url, logo, contactPoint, `sameAs` social profiles
- **WebSite** — ties the site to the brand name
- **SoftwareApplication** — category, feature list, and a real `Offer` built
  from the cheapest active subscription plan and the configured currency
- **FAQPage** — 6 questions, rendered from the *same* PHP array as the visible
  accordion, so the markup can never drift from the page copy

> **Deliberate omission:** I did **not** put `aggregateRating` on
> `SoftwareApplication`. The ratings in your database describe *your customers'
> businesses*, not Optibiz itself. Publishing them as ratings *of* Optibiz is a
> self-serving-review policy violation and risks a structured-data manual
> action. The aggregate score is displayed as on-page text instead, which is
> perfectly fine. The rating schema on the individual company pages
> (`rate/index.php`) *is* legitimate and was already implemented correctly.

> **On FAQ rich results:** Google restricted FAQ rich snippets to authoritative
> government/health sites in 2023, so do not expect star-style FAQ accordions
> in the SERP. The markup is still worth having — Bing uses it, and it feeds
> AI Overviews and LLM answer engines.

#### 2.1.4 No favicon
No icon meant no branding in browser tabs, bookmarks, or mobile SERPs, and
404s in the server log for `/favicon.ico`. Generated a complete set from the
brand palette (`tools/build-favicon.js`): SVG, 32/192/512 PNG, 180 apple-touch,
and an ICO wrapper. The tenant-uploaded favicon from
**Control Center → Settings → Branding** still takes priority.

#### 2.1.5 No `robots.txt` / `sitemap.xml`
Added both. `sitemap.php` is generated live, so every new company profile
(there are UUID-addressed pages that are only linked from paginated directory
listings) is discoverable without waiting for crawl discovery.

### 2.2 Important (now fixed)

#### 2.2.1 Nine dead `href="#"` links
Eight placeholder social icons in the team section plus one `href="#"` with an
`onclick` in the footer. Crawlers treat these as soft 404s; screen readers
announce them as broken links. The team section is now data-driven — an icon
only becomes a link when a real URL is configured, so you can fill them in
without touching markup. The footer item is now a real `<button>` (it opens a
dialog; it is not navigation).

#### 2.2.2 Footer social links pointed at generic homepages
`https://twitter.com`, `https://linkedin.com`, etc. — not your profiles. These
now read from the `social_*` settings keys and feed **both** the visible links
and the Organization `sameAs` array. Until they are set the icons are hidden
rather than linking to the wrong place.

#### 2.2.3 Accessibility / semantics
- Added a skip link and a `<main>` landmark.
- `lang="en"` → `lang="en-GH"` (the copy explicitly targets Ghana: *"Ghana's
  leading review and reputation platform"*, Accra-area business names).
- The hero demo card's business name was an `<h3>` sitting directly under the
  `<h1>`, creating an h1 → h3 skip. It is now a `<p>` — it is a *mock* of a
  customer widget, not a document section.
- The one `<img>` on the page now has `width`/`height`, `loading="lazy"` and
  `decoding="async"` (prevents layout shift).

### 2.3 Recommended (not changed — needs your input)

#### 2.3.1 ~4.4 MB of unreferenced images ⚠️
`assets/images/` contains nine images (`hero_phones.jpg` 629 KB,
`how_it_works.jpg` 822 KB, `service_*.jpg` ~730 KB each,
`about_consultants.jpg` 752 KB, `avatar1-3.jpg`) and **none of them is
referenced by a single PHP, CSS or JS file in the repository.** They were
clearly intended for a hero and a "services" section that never landed.

Recommendation: use them (converted to WebP, ~80 % smaller, with explicit
`width`/`height`), or delete them. A hero image would also materially help
engagement — the current hero is text + a CSS mock, and `index.php` contains
exactly one real `<img>`.

#### 2.3.2 Render-blocking Font Awesome
The full Font Awesome 6.5.1 stylesheet loads from cdnjs and blocks first paint.
I added `<link rel="preconnect">`, but the bigger win is to self-host a
**subset** — the page uses roughly 30 icons out of ~2,000. Options, in order of
effort: self-host the 4 CSS + webfont files; or build a subset with
FontAwesome's own tooling; or at minimum load the CDN copy non-blocking.

#### 2.3.3 ~1,500 lines of inline CSS in `<head>`
Every page ships its own full `<style>` block (index, features, pricing,
companies and seo-checker each have one, largely duplicating the same `:root`
palette). This is render-blocking and uncacheable across page views. Moving
them to `assets/css/` with a cache-buster (the repo already has this pattern —
see `sa_asset()` in `includes/header.php`) would let the browser cache the
stylesheet between pages.

#### 2.3.4 Content depth
The homepage is a good overview but ranks for very few queries on its own.
Highest-value additions, in order:
1. **Location landing pages** — you already have `category_id` and
   `location_description` on `customers`. `/rate/restaurants-accra` style pages
   are how directories win local search.
2. **A Google Business Profile** for Optibiz itself, with the same NAP
   (name/address/phone) reused everywhere — NAP consistency is the single
   biggest local-SEO factor you do not control today.
3. **Comparison / "vs" content** and per-feature pages beyond the six cards.

---

## 3. Deployment & market-standard findings

### 3.1 Fixed in `.htaccess`

- **HSTS** — added, but scoped with `env=HTTPS` so it is only ever sent on
  secure responses and can never lock a visitor out over plain HTTP.
- **`X-Frame-Options: SAMEORIGIN`** (not `DENY` — the embeddable review widget
  needs `SAMEORIGIN`) and a `Permissions-Policy` disabling geolocation,
  microphone, camera, payment, usb and serial.
- **`X-XSS-Protection` removed** — deprecated, and it can actively introduce
  issues in legacy browsers.
- **gzip now covers** `application/xml` (the sitemap) and `image/svg+xml`
  (the favicon).
- **`sitemap.xml` → `sitemap.php`** rewrite.
- **HTTPS redirect added but commented out.** I deliberately did not enable it:
  enabling it blind can cause redirect loops depending on where TLS terminates.
  The rule handles `X-Forwarded-Proto` for proxies, so uncommenting it is safe
  once the certificate is live.

### 3.2 Recommended

#### 3.2.1 Content-Security-Policy — roll out report-only first
Every page uses inline `<style>` and inline `<script>`, so a strict CSP would
break the site today. The correct sequence is: deploy
`Content-Security-Policy-Report-Only` with a `report-uri`, collect violations
for a week, then tighten. Do not skip to enforcing.

#### 3.2.2 `RewriteBase /rate/` is hard-coded
If the app is ever deployed at the domain root (which I'd recommend for the
marketing site — `https://domain.com/rate/` is an odd URL for a homepage), the
whole file breaks. Either make it configurable per install or document it
prominently in `INSTALLATION.md`.

#### 3.2.3 Schema changes run on public page load ⚠️
`index.php` line ~82 executes:

```php
@$conn->query("ALTER TABLE subscription_plans ADD COLUMN annual_discount_percent …");
```

A `ALTER TABLE` is a blocking DDL operation, triggered by an anonymous visitor's
request to your highest-traffic page, on every request until the column exists.
Move all schema changes into `migrations/` and run them as a deploy step.

#### 3.2.4 `README.md` is empty (0 bytes)
On a public repository this is the first thing a potential customer,
contributor or evaluator sees.

#### 3.2.5 No CI
`tools/` already contains `check-sql.js`, `check-a11y.js`, `check-css.js` and
friends, but nothing runs them automatically. A GitHub Actions workflow running
PHP lint + those checks on every PR is a half-hour of work and prevents a whole
class of regression.

#### 3.2.6 Custom error pages
`ErrorDocument 404 /404.php` is commented out. Worth adding — a branded 404
that links to the directory and homepage recovers traffic you otherwise lose.

#### 3.2.7 One 2 MB logo committed to `uploads/`
`uploads/tenant_logo_3_1788528249.png` is a 1024×1024 PNG weighing **2.0 MB** in
a public repo. A tenant logo should be ~512×512 WebP or optimised PNG —
roughly 50 KB, a 40× reduction. This is the kind of thing an image-optimisation
step in CI (or a resize-on-upload, which the app does not currently do) prevents
permanently.

#### 3.2.8 Positive: upload hardening is done well
`uploads/.htaccess` blocks script execution, disables the PHP engine for image
MIME types, removes handlers and disables directory listing, with a second
defence-in-depth copy in `uploads/reviews/`. This is the correct pattern for a
user-uploads directory and is better than most projects of this size. Nothing to
change.

---

## 4. What I changed — file by file

| File | Change |
|---|---|
| `includes/seo.php` | **New.** Shared SEO helper: canonical, robots, Open Graph, Twitter card, favicons, theme-color, JSON-LD `@graph`. |
| `index.php` | SEO preamble (title/description/schema), FAQ section + matching `FAQPage` schema, skip link, `<main>`, data-driven team + footer socials, favicon/preconnect, image `loading`/dimensions, heading-order fix. |
| `features.php`, `pricing.php`, `companies.php`, `seo-checker.php` | Same shared head (canonical, OG, Twitter, breadcrumb schema), `lang="en-GH"`, preconnect. |
| `sitemap.php` | **New.** Dynamic XML sitemap: static pages + every company profile with a UUID, `lastmod` from newest review. |
| `robots.txt` | **New.** **Must be published at the domain root** — see the header comment in the file. |
| `.htaccess` | sitemap rewrite, HSTS, X-Frame-Options, Permissions-Policy, removed deprecated header, gzip for XML/SVG, commented HTTPS redirect. |
| `.gitignore` | **New:** `.env`, `*.key`, `*.pem`, database dumps. See §5.1. |
| `assets/images/favicon/`, `assets/images/og-default.png` | **New.** Generated brand assets. |
| `tools/build-favicon.js`, `tools/build-og-image.js` | **New.** Zero-dependency generators for the above (`node tools/build-favicon.js`). |

### How the shared helper works

```php
require_once __DIR__ . '/includes/seo.php';

seo_head([
    'title'       => 'Pricing & Plans — Optibiz',
    'description' => '…',
    'path'        => 'pricing.php',          // '' for the homepage
    'breadcrumbs' => [['Home', ''], ['Pricing', 'pricing.php']],
    'jsonld'      => [$mySchemaNode],
]);
```

**Security note — why canonical URLs are never built from `Host`.**
`$_SERVER['HTTP_HOST']` is attacker-controllable. An absolute URL that echoes it
back enables cache poisoning and password-reset link hijacking. `seo_url()`
resolves the base in this order:

1. `APP_BASE_URL` (operator-configured, trusted)
2. `getPlatformBaseUrl()` — validated against `OPTIBIZ_ALLOWED_HOSTS`
3. a host-relative path such as `/rate` (relative canonicals are legal per RFC 6596)

Only `sitemap.php`, where absolute URLs are **mandatory**, falls back to the
request host — and it validates the header against a strict hostname pattern
first. Verified: a `Host: evil.com/foo@bar baz` header is rejected and produces
an empty base, not a poisoned URL.

---

## 5. Security

### 5.1 🚨 Urgent — public repository contains a live database dump

`backups/backup_2026-09-16_11-59-50.sql.gz` is committed to Git, and
`shinejson/review` is a **public** repository. It contains:

- 34 email addresses
- 8 `customers` rows, 5 `ratings`, 4 `quote_requests`
- 54 `user_sessions` rows
- **1 `super_admins` row** — i.e. a password hash
- 14 `settings` rows

It is *not* web-accessible (`backups/.htaccess` denies all requests — that part
is correct), but it is readable by anyone on the internet via GitHub.

**Remediate:**
1. Rotate the superadmin password and any API keys/secrets immediately.
2. Delete the file and purge it from history:
   ```bash
   git filter-repo --path backups/backup_2026-09-16_11-59-50.sql.gz --invert-paths
   git push --force-with-lease
   ```
   (Or BFG: `bfg --delete-files '*.sql.gz'`.) This rewrites history — coordinate
   with anyone else who has cloned the repo.
3. Consider making the repository private.

I have added `*.sql.gz` and `backups/*.sql.gz` to `.gitignore` so this cannot
recur. I did **not** delete the file myself — if it is your only backup,
removing it should be your decision.

### 5.2 High — `.env` was not gitignored
`config/database.php` loads `.env` for the DB password and JWT secret, but
`.gitignore` did not exclude it — one `git add -A` away from publishing live
credentials. Now ignored (with `!.env.example` preserved).

### 5.3 Medium — admin path alias is committed
`p7xk2mqw9vrt4zhn` is hard-coded in `.htaccess` and `config/database.php`. On a
public repo the "secret" alias is known. It is defence-in-depth, not
authentication, so this is not catastrophic — but set `OPTIBIZ_ADMIN_PATH_ALIAS`
per install and keep the admin routes behind real authentication and, ideally,
IP allow-listing.

### 5.4 Positive notes
`config/database.php` already does several things right: `.env` loading,
`display_errors=0` outside development, and fail-closed DB errors that never
leak connection strings to the browser. `includes/header.php` already emits
`noindex` for admin pages. Nothing here was regressed.

---

## 6. Priority order

| # | Action | Effort |
|---|---|---|
| 1 | Rotate secrets + purge the committed DB dump from Git history | 1 h |
| 2 | Publish `robots.txt` at the **domain root** with your real domain | 5 min |
| 3 | Set `APP_BASE_URL` in `.env` (canonical URLs depend on it) | 5 min |
| 4 | Uncomment the HTTPS redirect in `.htaccess` once TLS is live | 5 min |
| 5 | Set `site_name` = **Optibiz** and upload a logo in Control Center → Branding | 10 min |
| 6 | Move the `ALTER TABLE` in `index.php` into a migration | 1 h |
| 7 | Use or delete the 4.4 MB of unreferenced images | 2 h |
| 8 | Submit `sitemap.xml` in Google Search Console + Bing Webmaster Tools | 15 min |
| 9 | Self-host a Font Awesome subset | 2 h |
| 10 | Extract the inline `<style>` blocks into cacheable CSS files | 4 h |
| 11 | CSP in report-only mode | 3 h |
| 12 | CI workflow + fill in `README.md` | 2 h |

---

## 7. Verification performed

PHP could not be installed in the review sandbox, so the SEO code was
**executed** via a PHP 8.0 runtime (WebAssembly) against the real files:

- `seo_head()` output on the homepage: 18 meta tags, canonical
  `https://example.com/rate/`, JSON-LD parses cleanly and contains
  `Organization, WebSite, SoftwareApplication, FAQPage` with 6 questions and
  `Offer: 99 GHS`.
- Fallback matrix: `APP_BASE_URL` → absolute ✔ · allow-listed host → absolute ✔ ·
  non-listed host → relative fallback ✔ · **hostile `Host` header → rejected** ✔.
- `sitemap.php`: emitted 5 static pages + 2 company pages, correctly skipped a
  row with an empty UUID, and fell back to `created_at` for `lastmod` when a
  company had no reviews.
- Generated assets verified by pixel probe: favicon renders navy
  square+circle+triangle on a lime tile with transparent corners; the OG image
  is 1200×630 with the icon, wordmark and tagline in the expected regions.
- All 7 modified/created PHP files pass a PHP parser; `index.php`,
  `features.php`, `pricing.php`, `companies.php` and `seo-checker.php` all have
  balanced HTML.

**Still worth a smoke test on your server:** load `/rate/`, view source and
confirm the canonical URL shows your real domain (this only happens once
`APP_BASE_URL` is set), then check
`/rate/sitemap.xml` returns XML rather than the "Sitemap unavailable" comment.
