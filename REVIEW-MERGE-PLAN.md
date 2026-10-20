# Merging the product review into Optibiz

This document maps the seven-part review onto what this repository actually
contains, what changed on this branch, and what is still open. It is written
to be read alongside the code, not instead of it.

**Status legend**

| Status | Meaning |
| --- | --- |
| **Merged** | Working in this repository now, with tests or a lint pass. |
| **Partial** | A real part exists; the rest needs product or an external service. |
| **App-side** | Cannot be done here — the Android code is not in this repository. |
| **Open** | Nothing built yet; scoped below. |

---

## 0. Reality check

The repository is a PHP 8 / MySQL multi-tenant SaaS: public review pages,
a tenant admin panel, a super-admin control centre, and a JSON API under
`api/v1/`. The Android app the review talks about (Room, Hilt, FCM, Play
Integrity) is **not** in this checkout — `optibiz/` is empty. Everything
below therefore lands in one of two places:

1. **This repository** — accounts, verification, moderation, notifications,
   discovery, analytics, billing.
2. **The Android repository** — screens, Room caching, Hilt modules, FCM
   client, Play Integrity, package rename, Crashlytics.

Already present before this work, and reused rather than rebuilt: JWT auth,
`password_hash()` accounts, QR stand and print pages, review invites,
SMS/WhatsApp sending, Paystack/Flutterwave integrations, an admin notification
bell, CSV exports, and a privacy-shielded dispute tab for super admins.

---

## 1. Foundation — real backend, real accounts

| Review item | Status | Where it lives now | What remains |
| --- | --- | --- | --- |
| API as source of truth, Room as cache | **Partial** | `api/v1/` is a real REST surface: auth, reviews, search, replies, notifications. | The app still needs to treat Room as a cache and reconcile on refresh. App-side. |
| Real account recovery by email/SMS | **Merged** | `includes/app_otp.php`; `api/v1/auth/otp/{request,verify}.php`; `api/v1/auth/customer/reset_password.php`. 6-digit codes, HMAC-hashed at rest, 10-minute TTL, 5 attempts, 3 codes per destination per 10 minutes, 10 per IP per hour. Delivery failure burns the code. | Configure an SMTP account and an SMS provider in **Super Admin → Settings**; both transports already exist. |
| Google Sign-In | **Merged (server)** | `includes/google_auth.php`; `api/v1/auth/customer/google.php`. Verifies the ID token against Google's JWKS (RS256, `kid`, `aud`, `iss`, `exp`, `email_verified`). | The app must call the Google SDK and post the ID token. App-side. |
| Remove seeded accounts from release builds | **Merged** | `database.sql` has no demo users. The only fixtures are `tools/php/dataset.php`, now CLI-only, behind `tools/.htaccess`, and never loaded by the app. | Keep `tools/` out of release archives; the guard already refuses HTTP execution. |
| PBKDF2 with a high iteration count | **Merged** | `includes/password.php`: `pbkdf2_sha256$<iterations>$<salt>$<hash>`, 600,000 iterations by default (`OPTIBIZ_PBKDF2_ITERATIONS`, clamped 100k–5M). Every login path uses it and silently upgrades legacy bcrypt hashes on successful sign-in. | Nothing. Lower the count only for tests (`tests/run.php` uses 100k). |
| Account deletion | **Merged** | In-app: `api/v1/auth/customer/delete.php` (password or one-time code). Web: `account-deletion.php` (code to email/SMS, then confirm). Both call `optibiz_app_delete_account()`, which anonymises the account, revokes sessions and device tokens, and keeps reviews without personal identifiers. | Publish the URL in the Play Console data-deletion field. |
| Privacy policy for Play + Ghana DPA | **Merged** | `privacy.php` describes the data actually stored, the lawful bases under Act 843, retention, sharing, and the Data Protection Commission complaint route. Linked from the public footers and the sitemap. | Have counsel review; then set the real `support_email` / `privacy@` address. |

**Decision recorded:** passwords are hashed in PHP, not delegated to an auth
provider. Moving to a provider later is a migration, not a rewrite — the
`password_hash` column stores a scheme-prefixed string.

---

## 2. Trust and anti-fake-review

| Review item | Status | Where it lives now | What remains |
| --- | --- | --- | --- |
| Verified reviews via QR tied to the location | **Merged** | Two token kinds in `includes/review_trust.php`: a signed, reusable **location** token (`obqv1.…`, HMAC, company-bound, expiry) and a single-use **visit** token stored as a SHA-256 hash. `api/v1/qr/issue.php` issues both. `admin/qr_stand.php` and `admin/qr_print.php` now embed the signed token; `rate/index.php` forwards it, and both `api/submit_rating.php` and `api/v1/ratings/submit.php` verify and consume it. | Add a "staff member" dropdown to visit codes if you want per-staff analytics; the column (`staff_ref`) already exists. |
| Rate limits: one review per customer per business per window | **Merged** | `review_find_recent_by_customer()` matches account id, email, or device hash inside a rolling window (`OPTIBIZ_REVIEW_WINDOW_DAYS`, default 30). The v1 endpoint returns `409 customer_review_window`; the legacy endpoint now delegates to the same helper. Plus 5 submissions / 10 minutes per IP+device and 20 / hour per IP. | Choose the window per plan if you want a shorter one for paid tiers. |
| User reports | **Merged** | `api/v1/reviews/report.php` (10/hour/IP, optional app identity) delegates to `review_report()`. Reports are deduplicated by reporter and never hide a review on their own. | App UI for the report sheet. App-side. |
| Business disputes | **Merged** | `api/v1/business/dispute.php` — workspace bearer + `ratings` permission, ownership check, one open dispute per review, enters the same queue. | Tenant UI button on the review row (the moderation page already lists disputes). |
| Admin moderation queue | **Merged** | `admin/moderation.php` (new) lists open queue items and reports, with **Publish / Hide / Dismiss**, an internal note, and an audit entry. `review_moderation_decide()` applies the decision to the review and the queue. Super admin keeps its privacy-shielded dispute tab. | Add the same queue to the super-admin panel if platform staff should see auto-flagged items across tenants. |
| Abuse detection: bursts, duplicate text, device/account clustering | **Merged (heuristics)** | `review_abuse_assess()` scores: ≥15 submissions/hour, ≥40/day, duplicate text, ≥3 accounts on one device, ≥5 reviews/day per IP, brand-new account, links in the text. Score ≥50 → queue; ≥85 → hidden pending review. Every signal is stored in `abuse_score` / `abuse_flags`. | Tune thresholds with real data; add a review of the false-positive rate after launch. |
| Play Integrity attestation | **App-side** | Not in this repository. | Android: request a Play Integrity token and send it with submissions; server-side verification needs a Google Cloud project and a new verifier. |

**Policy decision recorded:** a single report never hides a review (mass-report
takedowns), and a business dispute never hides a review while it is open.

---

## 3. Customer expectations

| Review item | Status | Where it lives now | What remains |
| --- | --- | --- | --- |
| Category ratings + overall stars | **Merged** | `category_service`, `category_cleanliness`, `category_value`, `category_speed` (1–5, optional) on `ratings`. Parsed from flat or nested input, stored by both submission endpoints, averaged by `api/v1/reviews/public.php`. | Add the four pickers to the web review form (app-side too). |
| Photo attachments with moderation | **Merged** | `review_attach_photos()` stores paths with `photos_approved = 0`; `review_public_photos()` returns only approved photos; approving a review approves its photos. | Add an approve/reject control per photo if partial approval is needed. |
| Search and discovery filters | **Merged** | `api/v1/businesses/search.php`: text/category filters, minimum rating, verified-only, radius using Haversine distance, sorted by relevance/rating/reviews/distance/newest. | Populate `customers.latitude/longitude` (columns now exist; the admin form does not yet set them). |
| Sorting + helpful votes | **Merged** | Public feed sorts `newest / oldest / highest / lowest / helpful`; `api/v1/reviews/helpful.php` toggles a vote with per-identity dedupe and keeps `helpful_count` in step. Legacy `markHelpful()` delegates. | App UI. App-side. |
| Push notifications on business replies | **Merged** | `includes/push.php` writes a durable `app_notifications` row and sends FCM HTTP v1 when credentials are configured. `api/v1/business/reply.php` and legacy `api/submit_reply.php` notify the reviewer. `api/v1/notifications/{list,read}.php` power the in-app inbox. | Set `OPTIBIZ_FCM_SERVICE_JSON` (service-account JSON path) and register device tokens via `api/v1/auth/customer/device.php`. |
| Dark mode | **Partial** | The admin and super-admin shells have a working theme toggle. The public review pages are light-only. | Decide whether the public pages need a dark theme. |
| Accessibility | **Partial** | The privacy and deletion pages use semantic landmarks, labelled inputs, visible focus rings and AA contrast. The older public pages have not been audited. | Run the existing `tools/check-a11y.js` over the public pages and fix the findings. |
| Offline drafts | **App-side** | Nothing server-side. | Room draft table + sync conflict rule. App-side. |

---

## 4. Business paid features

| Review item | Status | Where it lives now | What remains |
| --- | --- | --- | --- |
| Analytics: rating trends | **Partial** | `admin/analysis.php` already charts rating volume, average and distribution; the public feed exposes averages. | Per-category trend charts (columns exist) and per-location/per-staff breakdowns (needs a staff dimension on `ratings`). |
| AI summaries and suggested replies | **Open** | — | Add a provider (OpenAI/Gemini), a prompt that only sees one business's reviews, a cost cap, and a "draft, not sent" UI. Do not auto-publish. |
| Real-time alerts for 1–2 star reviews | **Partial** | Low scores create a `review_critical` notification in the admin bell, deduped per review. FCM delivery for **business staff** needs a staff app/device table; today only customers have `app_devices`. | Staff mobile app or PWA push subscription. App-side. |
| Printable QR kits, short links, SMS/WhatsApp requests | **Partial** | Signed QR on `admin/qr_stand.php` and `admin/qr_print.php`; short public URLs (`/{uuid}`, `/r/{uuid}`); `review_invites` and SMS/WhatsApp sending already exist. | A print-ready PDF kit (A6 stand, counter card, receipt footer) and bulk import of customer phone numbers. |
| CSV/PDF exports + webhooks | **Partial** | Super-admin review and dispute CSV export exists. | Tenant-level export of its own reviews, a PDF layout, and signed webhooks with retries and a delivery log. |
| Subscription tiers with mobile money (MTN MoMo) | **Partial** | Plans, invoices, Paystack and Flutterwave integrations exist. | A direct MTN MoMo (Collections API) provider if Paystack/Flutterwave coverage is not enough; otherwise document that MoMo is collected through them. |

---

## 5. The Google review-gating warning

**Merged and enforced.** The old flow sent 4–5 star reviewers to Google and
kept 1–3 star reviewers away from it — the exact pattern Google prohibits.
Now:

- `api/v1/ratings/submit.php` always returns `google_review_url` and
  `review_gating: false`.
- `api/submit_rating.php` shows the Google prompt on the standard path and
  inside the low-score follow-up; the batch path no longer checks the score.
- The setting formerly called "Review Booster" is now "offer the Google review
  link", and its threshold is renamed to a **low-score alert threshold**.
  `booster_enabled` / `booster_min_stars` keep their names for database
  compatibility, but their comments and UI now describe alerts, not gating.
- Customer copy in `features.php`, `admin/company.php` and the footer no
  longer promises "route happy customers to Google".

Low scores still produce a private follow-up and a manager alert. That is
allowed; suppressing the public prompt is not.

---

## 6. Engineering quality

| Review item | Status | Notes |
| --- | --- | --- |
| Hilt DI, unit tests, CI | **App-side / Partial** | Hilt is Android-only. This repo has an offline PHP suite (`tests/run.php`, 60 assertions) and browserless UI suites under `tools/`, but no CI workflow file. |
| Crashlytics + analytics | **App-side** | Server-side `analytics_events` exists and is written by review submission. |
| Localization | **Open** | The UI is English-only. Ghana-specific copy is present, but no `i18n` layer. |
| Replace `com.example` package name | **App-side** | Nothing to change in this repository. |

---

## 7. What changed on this branch

**Foundation**

- `includes/password.php` — PBKDF2 hashing, policy, rehash-on-login.
- `includes/app_auth.php` — app accounts, devices, deletion requests.
- `includes/app_otp.php` — email/SMS one-time codes.
- `includes/google_auth.php` — Google ID-token verification.
- `api/v1/auth/customer/*` — register, login, Google, refresh, me, device,
  reset password, delete.
- `api/v1/auth/otp/*` — request and verify.
- `privacy.php`, `account-deletion.php` — public policy and deletion flow.

**Trust**

- `includes/review_trust.php` — schema, signed QR tokens, review window,
  abuse scoring, reports, moderation, photos, helpful votes.
- `api/v1/ratings/submit.php` — rewritten submission pipeline.
- `api/v1/qr/issue.php` — workspace QR issuance (location and visit).
- `api/v1/reviews/public.php` — published-only feed with summary.
- `api/v1/businesses/search.php` — discovery with distance.
- `api/v1/reviews/report.php`, `helpful.php` — reporting and votes.
- `api/v1/business/reply.php`, `dispute.php` — owner replies and disputes.
- `admin/moderation.php`, nav entry, link from `admin/ratings.php`.
- `api/submit_rating.php` — QR verification, category scores, no gating.
- `api/submit_reply.php` — mirrors official replies, notifies the reviewer.
- `includes/functions.php` — `getReviews()` hides moderated reviews;
  `reportReview()`, `markHelpful()` and `hasCustomerReviewedThisMonth()`
  delegate to the trust layer.

**Notifications**

- `includes/push.php` — FCM HTTP v1, durable inbox, reply and low-score alerts.
- `api/v1/notifications/{list,read}.php`.

**Data and safety**

- `database.sql` — 49 tables, including app accounts, QR tokens, moderation
  queue, helpful votes, reports, and the new review columns.
- `migrations/migrate_review_trust.php` — idempotent migration entry point.
- `tools/php/*` — CLI-only guard; `tools/.htaccess`, `migrations/.htaccess`.

---

## 8. Deploying this merge

1. Run `php migrations/migrate_review_trust.php` (or import the updated
   `database.sql` on a fresh install). The runtime helpers also self-heal the
   schema, but the migration makes the change explicit.
2. Set the environment:
   - `OPTIBIZ_PBKDF2_ITERATIONS` (default 600000)
   - `OPTIBIZ_JWT_SECRET` (already required by `api/v1/config.php`)
   - `OPTIBIZ_QR_SECRET` (falls back to the JWT secret)
   - `OPTIBIZ_REVIEW_WINDOW_DAYS` (default 30)
   - `OPTIBIZ_FCM_SERVICE_JSON` (path to the Firebase service-account JSON;
     omit to keep the in-app inbox without push)
   - `OPTIBIZ_GOOGLE_CLIENT_IDS`
3. Configure SMTP and an SMS provider in the control centre; OTP delivery
   fails closed if neither is configured.
4. Point the Play Console data-deletion URL at `/account-deletion.php`.
5. Confirm the public footer links resolve on the deployed domain.

**Verification**

```bash
node node_modules/php-cli/php-cli.js tests/run.php      # 60 assertions
node tools/check-other-pages.js                        # admin + public APIs
node tools/check-sql.js                                # schema drift
```

The last two suites currently report pre-existing harness gaps unrelated to
this branch (mock coverage for `platform_feedback` / `user_two_factor`, and
several session fixtures). Treat new failures as regressions; the known ones
are listed in the branch notes.

---

## 9. Suggested next five changes

1. **Populate business coordinates** — add latitude/longitude to the admin
   company form and geocode from the existing map URL. Distance search is
   already built but has no data to use.
2. **Category scores on the public review form** — the API and schema accept
   them; the web form does not show the pickers yet.
3. **Tenant review export** — reuse the super-admin CSV writer, scoped to one
   workspace, so the paid "exports" promise is true for tenants.
4. **Super-admin trust queue** — surface `review_moderation_queue` next to the
   existing disputes tab so platform staff can see auto-flagged reviews.
5. **AI reply drafts** — behind a feature flag and a per-tenant cost cap; keep
   a human approval step.

Everything in section 6 that is marked **App-side** belongs in the Android
repository and should be tracked there, not here.
