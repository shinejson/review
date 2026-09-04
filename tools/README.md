# Preview tooling

The super admin screens are ordinary PHP pages that read from MySQL. This folder
lets you see and test them **without a web server or a database**.

## 0. Run everything

```bash
node tools/test-all.js
```

Renders and tests the super admin panel, then the tenant admin panel, both login
forms and the public APIs, then audits the CSS and the accessibility of the
rendered markup, and finally checks every SQL statement the app issued against
`database.sql`. This is the quickest way to confirm a change did not break
anything.

The mock answers queries by pattern, so it cannot spot a mistyped column name.
`check-sql.js` can: it re-runs the harness with `SA_SQL_DUMP` set, collects every
statement, resolves table aliases and asserts each referenced column exists in
the schema. Pass `SA_SQL_DUMP_FILE=/path/to/dump.txt` to check a dump you already
captured instead of rendering again.

## 1. Render the real PHP (recommended)

```bash
npm install php-cli          # WebAssembly PHP 8.2, anywhere on disk
PHP_CLI=/path/to/node_modules/php-cli/php-cli.js node tools/render-php-preview.js
node tools/preview-server.js # http://localhost:4173
```

`render-php-preview.js` copies the app to `.preview-build/`, swaps
`config/database.php` for `tools/php/mock-db.php` (an in-memory mysqli stand-in
fed by `tools/php/dataset.php`) and then executes every super admin page through
the WASM PHP binary. It also replays the POST handlers — create, edit, status
changes, plan toggles, quote conversion, settings saves, password change — and
asserts the exact SQL each one issues, including the guards that must *not*
write (deleting a plan that is in use, a forged CSRF token, a wrong current
password).

The rendered HTML lands in `.preview/` and is what the preview server serves, so
what you click through is the markup the shipped templates produce.

`SA_EMPTY_DB=1` renders the same pages against a database that has the schema but
no rows, which is what a brand-new install sees — every screen must still render
its empty states instead of dividing by zero or reading a missing array key.

If PHP is unavailable the script exits with a message; use the static fallback.

## 2. Static fallback

```bash
node tools/build-preview.js
node tools/preview-server.js
```

`build-preview.js` writes a hand-built mirror of the same screens from
`tools/preview-data.js`. Use it only when the WASM PHP runtime cannot be
installed — the PHP render is authoritative.

## 3. Serving

`tools/preview-server.js [port]` binds `0.0.0.0` (default port 4173) and
rewrites `*.php` URLs to the rendered `*.html`, so every link and form target in
the preview resolves.

## Files

| File | Purpose |
| --- | --- |
| `test-all.js` | Runs every suite below in one go |
| `render-php-preview.js` | Renders + tests the real super admin pages, their POST handlers, a fresh-install (empty database) render, signed-out redirects and edge cases |
| `check-browser.js` | Loads each rendered page in jsdom, runs the real `superadmin.js` and asserts theme, collapse, filtering and sorting behave |
| `check-other-pages.js` | Tenant admin panel, panel isolation, login forms and `api/` endpoints |
| `check-css.js` | Audits the rendered pages for undefined `var(--…)` and unstyled classes; lists `.sa-*` selectors nothing uses |
| `check-a11y.js` | Audits labels, landmarks, `scope`/`aria-sort`, dialog names, ARIA values, heading order and duplicate ids |
| `check-sql.js` | Captures every statement the app issues and verifies each table and column exists in `database.sql` |
| `build-preview.js` | Static fallback mirror |
| `preview-server.js` | Static server with `.php` → `.html` rewrites |
| `preview-data.js` | Sample data for the static mirror |
| `php/dataset.php` | Sample rows for the mock database |
| `php/mock-db.php` | mysqli stand-in: matches SQL, returns fixture rows |
| `php/bootstrap.php` | Auto-prepended: session, `$_GET`/`$_POST`, and who is signed in (`SA_ANONYMOUS`, `SA_NO_SUPER`, `SA_ADMIN_ID`, `SA_POST`, `SA_EMPTY_DB`) |

### Session controls

`bootstrap.php` also seeds what `includes/session.php` expects from a session
that `login.php` recorded, so the suites can exercise sign-out:

| Variable | Effect |
| --- | --- |
| `SA_SESSION_TOKEN` | Token of the session the harness is signed in with (default `preview-session-token`, which has a live row in `dataset.php`) |
| `SA_LOGOUT_TOKEN` | Token the logout endpoints accept (default `preview-logout-token`) |
| `SA_SESSION_REVOKED=1` | The signed-in row comes back closed, as if another screen had signed it out |
| `SA_SESSION_AGE` / `SA_SESSION_IDLE` | Age of the session and seconds since it was last used, for the idle/absolute timeout checks |
| `SA_SESSION_DUMP=/path` | Writes what is left of `$_SESSION` when the script ends — how the suites prove a sign-out really cleared it |

None of these files are loaded by the application at runtime.
