# 2FA Delivery Audit — WhatsApp & Email OTP

_Date: 2026-10-19 · Scope: 2FA code delivery (WhatsApp + Email), SMTP configuration, credential hygiene_

## TL;DR

1. **WhatsApp codes are never actually sent.** The app has no WhatsApp sending integration at all. It
   generates a code, silently tries the first enabled **SMS** provider (ignoring the result), then
   always tells you *"code sent to WhatsApp"* — even when nothing left the server.
2. **Email codes fail because of SMTP configuration + two code bugs.** The OTP email was sent with
   dark-theme colors but **no dark template wrapper** (nearly invisible on white), Google app
   passwords pasted with spaces were sent to Gmail as-is (authentication fails), and Gmail rejects
   the message when "From email" is not your Gmail address.
3. **A live Gmail app password was committed to the repository** (`app_pass.md`). It must be
   revoked — see [Action checklist](#action-checklist).

---

## 1. WhatsApp OTP — why "sent" but never received

`tfa_send_whatsapp_otp()` in `includes/two_factor.php` did this:

1. Generated a 6-digit code and saved its hash (this part works).
2. Called `sms_send_via_provider()` with the **first enabled SMS provider** — the result was
   **completely ignored**. There is no WhatsApp Business API (Meta Cloud API / Twilio WhatsApp)
   anywhere in the codebase. The built-in providers (Hubtel, mNotify, Africa's Talking, Nalo,
   Twilio) all send **SMS**, not WhatsApp messages.
3. If **no SMS provider** is configured/enabled → **nothing is sent at all**.
4. If the provider is `manual` or has no API key → it returns *"Logged (manual provider)"*
   success **without sending anything**.
5. Regardless of the outcome, the function returned
   `success = true, message = "6-digit verification code sent to WhatsApp at +233…"`.
   The login flow (`admin/login.php`) discarded even that return value and redirected to the
   verify page, which showed a hardcoded *"We've sent a 6-digit verification code to your WhatsApp"*.

So the "sent" confirmation was pure fiction — which exactly matches what you observed.

The only things that "deliver" a WhatsApp code today are development simulations:
`includes/two_factor_admin_component.php` / `two_factor_sa_component.php` display
**"WhatsApp Simulation: Latest dispatched code is XXXXXX"** on-screen with a `wa.me` self-chat
link. These confirm the feature was never finished as real delivery.

### What we changed

- `tfa_send_whatsapp_otp()` now **checks the gateway result** and returns a `delivered` flag with
  an honest message (e.g. *"…could NOT be automatically delivered (No SMS/WhatsApp gateway is
  configured and enabled)"*).
- `admin/2fa-setup.php`, `admin/2fa-verify.php`, `admin/login.php` and `api/v1/auth/login.php`
  now surface that warning instead of claiming the code was sent.

### What you need to decide

Pick one:

| Option | What it means |
|---|---|
| **A. Real SMS delivery** | Configure a real SMS provider (e.g. Hubtel) under Superadmin → SMS Providers. Users receive the code by **SMS**, not WhatsApp. Rename the 2FA option to "SMS / Text message" so users know what to expect. |
| **B. Real WhatsApp delivery** | Integrate the **WhatsApp Business Cloud API** (Meta) or **Twilio WhatsApp channel**. This is a new feature — the current code cannot do it. Requires a verified WhatsApp Business account/template approval for authentication messages. |
| **C. Drop WhatsApp 2FA** | Remove the WhatsApp option and offer **Authenticator app (TOTP)** + **Email** only. TOTP is the most reliable option and needs no gateway. |

Recommendation: **A or C now, B later** if WhatsApp delivery is a hard requirement.

---

## 2. Email OTP — why the mail never arrives

Email delivery path: `tfa_send_email_otp()` → `sa_send_mail()` → `sa_smtp_send()` (custom SMTP
client) using settings from **Superadmin → Settings → Email & SMTP** (stored in the `settings`
table). Four failure points were found:

### 2a. OTP email was unreadable (fixed)

`sa_send_mail()` sends raw HTML on a **white** background, but `tfa_send_email_otp()` styled the
body with light-gray text (`#cbd5e1`) designed for the dark template — and never wrapped it in
`sa_render_email_template()`. The message can land in the inbox looking blank/white, so it reads
as "not received". **Fixed:** the OTP email is now wrapped in the standard dark template.

### 2b. Google app password with spaces (fixed)

Google shows app passwords as `xxxx xxxx xxxx xxxx`. This custom SMTP client sends the stored
password **exactly as entered**, so a pasted password **with spaces** fails authentication
(`535-5.7.8 Username and Password not accepted`). **Fixed:** the mailer now strips spaces when the
value matches the Google 4×4-group format. (Still recommended to save it without spaces.)

### 2c. "From email" must be your Gmail address (fixed)

Gmail's SMTP rejects `MAIL FROM:` when the From address is not the authenticated account (or a
verified alias). The form defaults to `admin@example.com` / `admin@…`, which breaks every send with
*"MAIL FROM rejected"*. **Fixed:** the mailer now falls back to the SMTP username when the From
address is empty or a placeholder. **You should still set "From email" to your Gmail address.**

### 2d. SMTP configuration that you must verify

In **Superadmin → Settings → Email & SMTP**, for Gmail:

| Field | Value |
|---|---|
| Mail sending driver | SMTP Server |
| SMTP host | `smtp.gmail.com` |
| SMTP port | `587` |
| Encryption | TLS / STARTTLS |
| SMTP username | your full Gmail address (`…@gmail.com`) |
| SMTP password | the **16-character app password, no spaces** |
| From email | **the same Gmail address** (or a verified alias) |

Then click **"Send test email"** on that page — it shows the real SMTP error if anything is wrong.

Also check: **2-Step Verification must be ON** on the Google account (app passwords only exist
with 2SV), and check the **Spam folder** of the receiving inbox.

---

## 3. Security findings

### 3a. Live credential committed to Git (ACTION REQUIRED)

- `app_pass.md` containing a **live Gmail app password** for `…@gmail.com` was committed to this
  repository (commit `b89c49e "ap password file"`). It was also pasted into chat.
- The file has been **deleted from the working tree**, but it remains in **git history** — anyone
  with repository access can read it. `.htaccess` blocks web access to `*.md`, but that does not
  protect against repo access.
- **You must revoke that app password now**: Google Account → Security → 2-Step Verification →
  App passwords → delete it, then generate a fresh one and enter it only in
  Superadmin → Settings. Never commit credentials — use the `settings` table or `.env`
  (already git-ignored).

### 3b. Development leftovers that weaken 2FA

- `tfa_send_whatsapp_otp()` returns the raw OTP (`code`) and stores it in
  `$_SESSION['last_whatsapp_otp']`; the admin/SA "WhatsApp Simulation" components display that
  OTP on-screen. Fine for local testing, **remove before production** — anyone who can read the
  session/HTML can bypass 2FA.
- `scratch/` test scripts (`test_e2e_2fa.php`, etc.) were **web-accessible PHP** that write to the
  database. A deny-all `scratch/.htaccess` has been added (same convention as `backups/`).

---

## Changes made in this review

| File | Change |
|---|---|
| `includes/two_factor.php` | WhatsApp OTP: gateway result checked, honest `delivered` status/message. Email OTP: wrapped in the dark email template. |
| `includes/mailer.php` | Normalize Google app passwords (strip spaces in 4×4 format). Fall back From-email → SMTP username when placeholder. |
| `admin/2fa-setup.php` | Shows real delivery status instead of a hardcoded "sent" claim. |
| `admin/2fa-verify.php` | Honest resend/switch messages, neutral instructions, warning banner for delivery failures from login. |
| `admin/login.php` | Send results captured; failures now shown on the verify page instead of being discarded. |
| `api/v1/auth/login.php` | Adds `delivery_warning` to the 2FA-required response. |
| `app_pass.md` | **Deleted** (contained a live credential). |
| `scratch/.htaccess` | Deny all web access to dev/test scripts. |

## Action checklist

- [ ] **Revoke the leaked Gmail app password** and create a fresh one (Google → Security).
- [ ] Superadmin → Settings → Email & SMTP: enter Gmail host/port/TLS, username, new app password
      (no spaces), From email = the Gmail address, then **Send test email**.
- [ ] Choose a WhatsApp strategy (A/B/C above). Until then, users should use **Authenticator app**
      or **Email** 2FA — the WhatsApp option will now honestly warn that nothing was delivered.
- [ ] Remove/ disable the "WhatsApp Simulation" OTP-display components before production.
- [ ] Consider purging `app_pass.md` from git history (or treat the credential as permanently
      burned — revocation is what actually protects you).
