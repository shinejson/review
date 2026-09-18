Security review

> **Status (2026-09-18): all findings below have been remediated.**
> This file was renamed from the misspelled `secuity.md` to `security.md`.
> A remediation log is appended at the end. Verify the operator checklist
> (JWT secret, APP_BASE_URL, DB credentials via env) before public deploy.

Severity	Finding	Evidence
Critical	Public uploads can become remote code execution. The public review endpoint accepts attacker-controlled MIME types and file extensions, then stores files under web-accessible uploads/. A .php filename with a spoofed image MIME type may execute on the server.	[functions.php (line 603)](C:/xampp/htdocs/rate/includes/functions.php:603), [submit_rating.php (line 289)](C:/xampp/htdocs/rate/api/submit_rating.php:289)
Critical	JWT authentication has a known hard-coded fallback secret. An attacker can forge access or refresh tokens for any valid tenant and access tenant API data, including reviews and customer email addresses. Issuer/audience are also not verified.	[config.php (line 8)](C:/xampp/htdocs/rate/api/v1/config.php:8), [jwt.php (line 59)](C:/xampp/htdocs/rate/api/v1/helpers/jwt.php:59), [auth.php (line 33)](C:/xampp/htdocs/rate/api/v1/middleware/auth.php:33)
High	Password-reset links are built from the untrusted Host header. If the web server accepts an arbitrary host, a reset email can point to an attacker-controlled domain and leak its reset token.	[functions.php (line 2284)](C:/xampp/htdocs/rate/includes/functions.php:2284), [functions.php (line 2950)](C:/xampp/htdocs/rate/includes/functions.php:2950)
High	Any signed-in tenant can post an “official” reply to another tenant’s review. The endpoint only checks whether some session exists; it never confirms ownership of the review’s company.	[submit_reply.php (line 42)](C:/xampp/htdocs/rate/api/submit_reply.php:42)
High	“Verified” reviews can be forged. Any non-empty MoMo reference immediately marks a review verified; public follow/like requests can also mark another customer’s review verified using supplied IDs.	[submit_rating.php (line 231)](C:/xampp/htdocs/rate/api/submit_rating.php:231), [customer_engage.php (line 29)](C:/xampp/htdocs/rate/api/customer_engage.php:29), [functions.php (line 1530)](C:/xampp/htdocs/rate/includes/functions.php:1530)
High	Team-member API logins effectively become tenant-admin tokens. Refresh tokens omit the staff identity and refresh into tenant_admin; disabled staff can retain API access until expiry.	[login.php (line 46)](C:/xampp/htdocs/rate/api/v1/auth/login.php:46), [refresh.php (line 54)](C:/xampp/htdocs/rate/api/v1/auth/refresh.php:54), [auth.php (line 67)](C:/xampp/htdocs/rate/api/v1/middleware/auth.php:67)
High	Public review, report, vote, reply, and engagement endpoints lack meaningful anti-abuse controls. Attackers can spam reviews; three reports from distinct IPs automatically hide a review.	[submit_rating.php (line 5)](C:/xampp/htdocs/rate/api/submit_rating.php:5), [report.php (line 12)](C:/xampp/htdocs/rate/api/report.php:12), [functions.php (line 578)](C:/xampp/htdocs/rate/includes/functions.php:578)
Medium	Several authenticated admin actions lack CSRF validation, including creating team users, changing settings, editing company information, and sending bulk customer emails. SameSite=Lax helps but is not sufficient as the sole defense.	[team.php (line 43)](C:/xampp/htdocs/rate/admin/team.php:43), [customers.php (line 110)](C:/xampp/htdocs/rate/admin/customers.php:110), [settings.php (line 18)](C:/xampp/htdocs/rate/admin/settings.php:18)
Medium	API CORS reflects any Origin while allowing credentials. Restrict this to an explicit allow-list; do not combine arbitrary origins with credentials.	[response.php (line 8)](C:/xampp/htdocs/rate/api/v1/helpers/response.php:8)
Medium	Debug endpoints and installation documentation are publicly reachable. They expose database schema details and publish default-login guidance.	[_svc_check.php (line 1)](C:/xampp/htdocs/rate/_svc_check.php:1), [diag_schema.php (line 1)](C:/xampp/htdocs/rate/diag_schema.php:1), [INSTALLATION.md (line 104)](C:/xampp/htdocs/rate/INSTALLATION.md:104)
Medium	Production database settings use the MySQL root account with an empty password, and database errors are shown directly.	[database.php (line 3)](C:/xampp/htdocs/rate/config/database.php:3)


Immediate remediation order:
1. Disable script execution in uploads/; store uploads outside the web root; verify file contents with finfo/getimagesize; generate server-side extensions and random names.
2. Remove the JWT fallback, require a high-entropy environment secret, validate issuer/audience, and implement server-side refresh-token revocation.
3. Use a configured canonical application URL for email links—never HTTP_HOST.
4. Enforce tenant ownership and permissions in every authenticated API action.
5. Make verification pending until real payment/receipt verification succeeds; use opaque one-time tokens for post-review actions.
6. Add rate limits/CAPTCHA to public write endpoints and CSRF tokens to all admin mutations.
7. Delete or restrict diagnostic files and documentation from the web root; replace default credentials during installation.
## Remediation log (2026-09-18)

1. **Upload RCE — fixed.** `uploadReviewPhoto()` / `uploadReceiptPhoto()` in `includes/functions.php` and the logo/banner closure in `admin/settings.php` now verify content with `finfo` + `getimagesize`, map a server-side extension from the detected MIME (never the client filename), check `is_uploaded_file()`, and use `random_bytes()` filenames. Script execution is blocked by new `uploads/.htaccess`, `uploads/reviews/.htaccess`, and `uploads/receipts/.htaccess` deny rules.
2. **JWT fallback — fixed.** `api/v1/config.php` no longer ships a hard-coded secret; it requires `OPTIBIZ_JWT_SECRET` (or `JWT_SECRET`) of at least 32 chars and fails closed (HTTP 503 / CLI abort) when missing. `JWT::verify()` now validates `iss`/`aud` with `hash_equals()`. `api/v1/middleware/auth.php` uses the token's `role` claim (plus `team_member_id`) instead of hard-coding `tenant_admin`.
3. **Host-header reset links — fixed.** New `APP_BASE_URL` constant in `config/database.php` (from env). `getPlatformBaseUrl()` prefers it, and any `HTTP_HOST` fallback is gated on an `OPTIBIZ_ALLOWED_HOSTS` allow-list. `sendTenantSetupEmail()` no longer rebuilds URLs from `HTTP_HOST`.
4. **Cross-tenant replies — fixed.** `api/submit_reply.php` only sets `is_official = 1` when the signed-in `tenant_id` owns the review's company (checked via `customers.tenant_id`).
5. **Verification forgery — fixed.** A non-empty MoMo reference or receipt upload now records `is_verified = 0` with a `*_pending` verification type; only the tenant's `toggle_verification` action in `admin/ratings.php` grants the badge (stamping `manual` when approving a pending claim). Follow/like verification is bound to an opaque per-review `engage_token` (new `ratings.engage_token` column, auto-migrated): `customer_engage.php` requires the token, `markCustomerEngagement()` validates it with `hash_equals()`, and `submit_rating.php` mints it and embeds it as a `data-engage-token` attribute for the success-page script.
6. **Team-member escalation — fixed.** `api/v1/auth/login.php` embeds `role` + `team_member_id` in refresh tokens; `api/v1/auth/refresh.php` restores them, re-checks `team_members.is_active`, and rotates the claims forward so disabled staff lose API access immediately on refresh.
7. **Anti-abuse — fixed.** New shared `public_rate_limit()` / `public_rate_limit_respond()` in `includes/functions.php` (DB-backed `api_rate_limits` with a file fallback). Applied to `submit_rating` (5 / 10 min), `report` (10 / hr), `helpful` (30 / hr), `submit_reply` (10 / 10 min), `customer_engage` (30 / hr). `reportReview()` no longer auto-hides at 3 reports; reports queue for tenant moderation instead.
8. **Admin CSRF — fixed.** `sa_csrf_ok()` checks added to all POST handlers in `admin/team.php`, `admin/customers.php`, `admin/settings.php` (profile, password, customization, reset), `admin/company.php`, and `admin/ratings.php`; `sa_csrf_field()` hidden inputs added to every POST form in those pages.
9. **CORS — fixed.** `api_init_cors()` only reflects origins on an explicit allow-list (`OPTIBIZ_API_ALLOWED_ORIGINS` env; localhost defaults only when unset, for development) and returns 403 otherwise.
10. **Debug endpoints — fixed.** `_svc_check.php` and `diag_schema.php` return 404 for non-local requests and no longer leak DB names or raw errors. `INSTALLATION.md` default credentials replaced with change-during-install guidance.
11. **DB credentials — fixed.** `config/database.php` reads `OPTIBIZ_DB_HOST/USER/PASS/NAME` from the environment and fails closed with a generic message; details go to `error_log` only.

## Operator checklist before public deploy

- Set `OPTIBIZ_JWT_SECRET` (>= 32 random chars) in the environment.
- Set `APP_BASE_URL` to the canonical public URL (used in emails).
- Set `OPTIBIZ_DB_HOST`, `OPTIBIZ_DB_USER`, `OPTIBIZ_DB_PASS`, `OPTIBIZ_DB_NAME` with a least-privilege DB account (not root).
- Set `OPTIBIZ_API_ALLOWED_ORIGINS` and `OPTIBIZ_ALLOWED_HOSTS` for production origins.
- Confirm Apache honours the `uploads/**/.htaccess` deny rules (AllowOverride).

Positive controls observed: prepared statements are used in many database paths, sessions use HttpOnly/Lax cookies and rotate on login, and payment webhooks verify signatures.

---

## Admin panel path aliasing (2026-09-18)

The tenant workspace is no longer served from its real path. Direct browser
requests to `/rate/admin/...` are refused with **403**, and the panel is
served under a non-guessable alias instead:

- Real path (blocked): `/rate/admin/notifications.php`
- Public path (works): `/rate/p7xk2mqw9vrt4zhn/notifications.php`

Implementation notes:

- `.htaccess` blocks any original client request whose path contains
  `/admin/` via `RewriteCond %{THE_REQUEST}` + `[F]`, then internally
  rewrites `p7xk2mqw9vrt4zhn/...` to `admin/...`. Internal rewrites never
  change `THE_REQUEST`, so aliased traffic is unaffected. **Gotcha:** the
  RewriteCond pattern must contain no literal spaces — Apache's config
  tokenizer splits directive arguments on any unescaped space and the
  whole app 500s with "RewriteCond: bad flag delimiters" (verified live
  2026-09-18; fixed by using `\s`/`\S` shorthand).
- The slug is defined once as `ADMIN_PATH_ALIAS` in `config/database.php`
  (override with the `OPTIBIZ_ADMIN_PATH_ALIAS` env var) and must match the
  slug hard-coded in the `.htaccess` rewrite rules.
- All admin-internal links, form actions, redirects, and logout URLs are
  relative, so they automatically stay under the alias — no changes inside
  `admin/` were required.
- Cross-panel links and emails were re-pointed via new helpers
  `admin_path_alias()` / `admin_url()` in `includes/functions.php`:
  public site login buttons, the social-card links on public review pages,
  superadmin's tenant-portal link / impersonation redirect / login links,
  the setup-password and welcome emails, and the ads event-source URL.
- `SCRIPT_NAME` still reflects the rewritten filesystem path, so server-side
  root detection (`includes/payments.php`, `getPlatformBaseUrl()`) and the
  relative session-expiry redirects keep working unchanged.

> This is **obfuscation, not authentication**. It removes a convenient
> target for opportunistic scanners; access control is still enforced by
> the login session, CSRF tokens, and the permission checks.
