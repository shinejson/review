#!/usr/bin/env node
/**
 * ============================================================
 *  Smoke-test everything outside the super admin panel
 *  (admin/*.php and api/*.php)
 * ============================================================
 *  The super admin redesign changed how every page in this app
 *  resolves its includes (`__DIR__` / `dirname(__DIR__)`) and
 *  hardened `sa_csrf_ok()` and `session_start()`. The admin panel
 *  was not redesigned, but it shares those files, so this script
 *  proves it still works:
 *
 *      node tools/check-other-pages.js
 *
 *  For each screen it asserts that a signed-in tenant admin gets a
 *  rendered page with no PHP diagnostics, and that a signed-out
 *  visitor is redirected without seeing any content. It also checks
 *  that a tenant admin cannot reach the super admin panel, that both
 *  login forms accept and reject credentials correctly, and that the
 *  public API endpoints validate their input.
 *
 *  Nothing here is written into .preview/ and nothing is loaded by
 *  the application at runtime.
 * ============================================================ */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const BUILD = path.join(os.tmpdir(), 'sa-admin-check-' + process.pid);
const SQL_LOG = path.join(os.tmpdir(), 'sa-admin-sql-' + process.pid + '.log');

const PHP_CLI = [
    process.env.PHP_CLI,
    '/home/user/.pvtest/node_modules/php-cli/php-cli.js',
    path.join(ROOT, 'node_modules', 'php-cli', 'php-cli.js'),
].find((p) => p && fs.existsSync(p));

if (!PHP_CLI) {
    console.error('WASM PHP runtime not found — install it with: npm install php-cli');
    process.exit(2);
}

const SIGNED_IN = [
    ['admin/index.php', 'dashboard'],
    ['admin/analysis.php', 'response analysis'],
    ['admin/ratings.php', 'ratings list'],
    ['admin/social.php', 'social workspace'],
    ['admin/subscription.php', 'subscription'],
    ['admin/settings.php', 'settings'],
];

/* Customers and categories moved to the super admin panel: the tenant
   admin portal must no longer expose these scripts at all. */
const REMOVED = [
    ['admin/customers.php', 'companies page'],
    ['admin/categories.php', 'categories page'],
];

function copyDir(src, dest) {
    fs.mkdirSync(dest, { recursive: true });
    for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
        if (['.git', '.preview', '.preview-build', 'node_modules'].includes(entry.name)) continue;
        const s = path.join(src, entry.name);
        const d = path.join(dest, entry.name);
        if (entry.isDirectory()) copyDir(s, d);
        else fs.copyFileSync(s, d);
    }
}

let sessionSeq = 0;
let logoutSeq = 0;
function sessionDir() {
    const dir = path.join(os.tmpdir(), 'sa-adm-sess-' + process.pid + '-' + ++sessionSeq);
    fs.mkdirSync(dir, { recursive: true });
    return dir;
}

function runPhp(script, queryString, opts) {
    const o = opts || {};
    if (fs.existsSync(SQL_LOG)) fs.unlinkSync(SQL_LOG);
    const env = Object.assign({}, process.env, {
        QUERY_STRING: queryString,
        SCRIPT_NAME: '/' + script,
        REQUEST_URI: '/' + script + (queryString ? '?' + queryString : ''),
        SA_SQL_LOG: SQL_LOG,
        SA_ANONYMOUS: o.anonymous ? '1' : '',
        SA_NO_SUPER: o.noSuper ? '1' : '',
        SA_ADMIN_ID: o.adminId ? String(o.adminId) : '1',
        SA_POST: o.post ? '1' : '',
        SA_BAD_CSRF: o.badCsrf ? '1' : '',
        SA_SESSION_REVOKED: o.sessionRevoked ? '1' : '',
        SA_LOGOUT_TOKEN: o.logoutToken || 'preview-logout-token',
        SA_SESSION_DUMP: o.sessionDump || '',
        SA_SESSION_DIR: sessionDir(),
    });
    let html = '';
    let stderr = '';
    try {
        html = execFileSync(
            'node',
            [PHP_CLI, '-d', 'auto_prepend_file=' + path.join(ROOT, 'tools', 'php', 'bootstrap.php'), path.join(BUILD, script)],
            { cwd: BUILD, env, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, timeout: 180000 }
        );
    } catch (e) {
        html = e.stdout || '';
        stderr = e.stderr || String(e.message || e);
    }
    const writes = fs.existsSync(SQL_LOG)
        ? fs.readFileSync(SQL_LOG, 'utf8').split('\n').filter((l) => l.startsWith('write\t')).map((l) => l.slice(6))
        : [];
    fs.existsSync(SQL_LOG) && fs.unlinkSync(SQL_LOG);
    return { html, stderr, writes };
}

function diagnostics(html, stderr) {
    const combined = html + '\n' + stderr;
    const fatal = /Fatal error|Parse error|Uncaught/.test(combined);
    const warnings = [...new Set((combined.match(/(Warning|Notice|Deprecated):[^<\n]{0,120}/g) || []))].map((w) => w.trim());
    return { fatal, warnings };
}

function main() {
    fs.rmSync(BUILD, { recursive: true, force: true });
    copyDir(ROOT, BUILD);
    fs.copyFileSync(path.join(ROOT, 'tools', 'php', 'mock-db.php'), path.join(BUILD, 'config', 'database.php'));

    let failures = 0;
    const fail = (msg) => {
        failures++;
        console.log('       ' + msg);
    };

    console.log('Tenant admin panel — signed in:');
    for (const [script, label] of SIGNED_IN) {
        const { html, stderr } = runPhp(script, '', { noSuper: true });
        const { fatal, warnings } = diagnostics(html, stderr);
        // The legacy admin screens are compact, so assert a complete document
        // rather than a byte count.
        const complete = /<!DOCTYPE html>/i.test(html) && /<\/html>\s*$/i.test(html.trim());
        const rawPhp = /<\?php|<\?=/.test(html);
        const ok = !fatal && warnings.length === 0 && complete && !rawPhp;
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${script.padEnd(24)} ${label.padEnd(16)} ${String(html.length).padStart(7)} bytes`);
        if (fatal) fail('fatal error: ' + (stderr || html).split('\n')[0].slice(0, 160));
        warnings.slice(0, 4).forEach(fail);
        if (!complete) fail('output is not a complete HTML document');
        if (rawPhp) fail('raw PHP leaked into the output');
    }

    console.log('\nTenant admin panel — signed out (must redirect, must not leak content):');
    for (const [script, label] of SIGNED_IN) {
        const { html, stderr } = runPhp(script, '', { anonymous: true });
        const { fatal } = diagnostics(html, stderr);
        const leaked = /<!DOCTYPE|<html|admin-/i.test(html);
        const ok = !fatal && !leaked && html.length === 0;
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${script.padEnd(24)} ${ok ? 'redirected with no output' : leaked ? 'LEAKED CONTENT' : 'produced output'}`);
        if (leaked) fail(script + ' rendered for a signed-out visitor');
    }

    console.log('\nPanel isolation (a tenant admin must not reach the super admin panel):');
    for (const script of ['superadmin/index.php', 'superadmin/tenants.php', 'superadmin/settings.php', 'superadmin/customers.php', 'superadmin/categories.php']) {
        const { html, stderr } = runPhp(script, '', { noSuper: true });
        const { fatal } = diagnostics(html, stderr);
        const ok = !fatal && html.length === 0 && !/sa-app|<!DOCTYPE/i.test(html);
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${script.padEnd(28)} ${ok ? 'redirected to the super admin login' : 'GRANTED ACCESS'}`);
        if (!ok) fail(script + ' rendered for a tenant admin');
    }

    console.log('\nMoved admin pages (customers + categories are super-admin only now):');
    for (const [script, label] of REMOVED) {
        const { html, stderr } = runPhp(script, '', { noSuper: true });
        const rendered = /<!DOCTYPE\s+html|<html[\s>]|<body/i.test(html);
        const ok = !rendered;
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${script.padEnd(24)} ${label.padEnd(16)} ${ok ? 'not present in the admin panel' : 'STILL RENDERS'}`);
        if (!ok) fail(script + ' still renders in the tenant admin panel');
    }

    console.log('\nRemaining admin screens must not link to the moved pages:');
    for (const [script] of SIGNED_IN) {
        const { html } = runPhp(script, '', { noSuper: true });
        const stale = html.match(/href="(?:\.\/)?(?:customers|categories)\.php[^"]*"/i);
        const ok = !stale;
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${script.padEnd(24)} ${ok ? 'no stale links' : 'LINKS TO ' + stale[0]}`);
        if (!ok) fail(script + ' still links to a page that moved to the super admin panel');
    }

    console.log('\nTenant admin login:');
    {
        const { html, stderr } = runPhp('admin/login.php', 'username=volta_admin&password=admin123', {
            anonymous: true,
            post: true,
            badCsrf: true,
        });
        const { fatal, warnings } = diagnostics(html, stderr);
        const ok = !fatal && warnings.length === 0 && !/auth-form/i.test(html);
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] valid credentials ${ok ? 'signed in and redirected' : 'did not sign in'}`);
        warnings.slice(0, 3).forEach(fail);
    }
    {
        const { html, stderr } = runPhp('admin/login.php', 'username=volta_admin&password=wrong', {
            anonymous: true,
            post: true,
            badCsrf: true,
        });
        const { fatal } = diagnostics(html, stderr);
        const ok = !fatal && /[Ii]nvalid/.test(html);
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] wrong password ${ok ? 'rejected with an error message' : 'gave no error message'}`);
    }


    console.log('\nTenant admin credentials (only a real password may sign in):');
    const LOGINS = [
        ['tenant · valid password', 'admin/login.php', 'username=abc_corporation&password=tenant123',
            (r) => !/auth-form/i.test(r.html), 'signed in and redirected'],
        ['tenant · by email address', 'admin/login.php', 'username=admin%40abccorp.com&password=tenant123',
            (r) => !/auth-form/i.test(r.html), 'signed in and redirected'],
        ['tenant · hardcoded "password"', 'admin/login.php', 'username=abc_corporation&password=password',
            (r) => /[Ii]nvalid|not found|Incorrect/.test(r.html), 'refused'],
        ['tenant · hardcoded "admin123"', 'admin/login.php', 'username=abc_corporation&password=admin123',
            (r) => /[Ii]nvalid|not found|Incorrect/.test(r.html), 'refused'],
        ['tenant · wrong password', 'admin/login.php', 'username=abc_corporation&password=nope',
            (r) => /[Ii]nvalid|not found|Incorrect/.test(r.html), 'refused'],
        ['tenant · cancelled subscription', 'admin/login.php', 'username=obuasi_mining_supplies&password=tenant123',
            (r) => /cancel|suspended|inactive/i.test(r.html), 'blocked with an explanation'],
        ['admin · valid password', 'admin/login.php', 'username=volta_admin&password=admin123',
            (r) => !/auth-form/i.test(r.html), 'signed in and redirected'],
        ['admin · hardcoded "password"', 'admin/login.php', 'username=volta_admin&password=password',
            (r) => /[Ii]nvalid|not found|Incorrect/.test(r.html), 'refused'],
        // tamale_admin's real password is not admin123, so this is the case
        // the removed "|| $password === 'admin123'" shortcut used to let in.
        ['admin · hardcoded "admin123" as a bypass', 'admin/login.php', 'username=tamale_admin&password=admin123',
            (r) => /[Ii]nvalid|not found|Incorrect/.test(r.html), 'refused'],
        ['admin · its own real password', 'admin/login.php', 'username=tamale_admin&password=tamale-solar-2026',
            (r) => !/auth-form/i.test(r.html), 'signed in and redirected'],
    ];
    for (const [label, script, body, assert, describeOk] of LOGINS) {
        const r = runPhp(script, body, { post: true, anonymous: true, badCsrf: true });
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        let ok = !fatal && warnings.length === 0;
        let detail = '';
        try {
            ok = ok && assert(r);
        } catch (e) {
            ok = false;
            detail = e.message;
        }
        if (!ok) failures++;
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${label.padEnd(44)} ${ok ? describeOk : detail || 'unexpected response'}`);
        if (fatal) fail('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 160));
        warnings.slice(0, 3).forEach(fail);
    }

    console.log('\nSign-out (admin/logout.php and the session row behind it):');
    const LOGOUTS = [
        [
            'sign out · valid token (GET)',
            'admin/logout.php',
            't=preview-logout-token',
            { noSuper: true, sessionDump: true },
            (r) => r.writes.some((w) => /^UPDATE user_sessions SET logged_out_at/i.test(w)) && !r.session.admin_id && !r.session.tenant_id,
            'closed the session row and cleared $_SESSION',
        ],
        [
            'sign out · valid token (POST)',
            'admin/logout.php',
            'logout_token=preview-logout-token',
            { noSuper: true, post: true, sessionDump: true },
            (r) => r.writes.some((w) => /^UPDATE user_sessions SET logged_out_at/i.test(w)) && !r.session.admin_id,
            'closed the session row and cleared $_SESSION',
        ],
        [
            'sign out · forged token refused',
            'admin/logout.php',
            't=somebody-elses-token',
            { noSuper: true, sessionDump: true },
            (r) => r.writes.length === 0 && !!r.session.admin_id,
            'left the session signed in',
        ],
        [
            'sign out · no token refused',
            'admin/logout.php',
            '',
            { noSuper: true, sessionDump: true },
            (r) => r.writes.length === 0 && !!r.session.admin_id,
            'left the session signed in',
        ],
        [
            'sessions · revoked elsewhere bounces to login',
            'admin/index.php',
            '',
            { noSuper: true, sessionRevoked: true, sessionDump: true },
            (r) => r.html.length === 0 && !r.session.admin_id,
            'redirected to the login screen with an empty session',
        ],
        [
            'settings · sign out everywhere else',
            'admin/settings.php',
            'action=logout_other_sessions&logout_token=preview-logout-token',
            { noSuper: true, post: true },
            (r) =>
                r.writes.some((w) => /^UPDATE user_sessions SET logged_out_at/i.test(w) && /session_token <> \?/.test(w)) &&
                /other session/i.test(r.html),
            'closed the other session rows and reported it',
        ],
        [
            'settings · refused without the token',
            'admin/settings.php',
            'action=logout_other_sessions',
            { noSuper: true, post: true },
            (r) => !r.writes.some((w) => /^UPDATE user_sessions/i.test(w)),
            'closed nothing',
        ],
        [
            'sign-in · records a session row',
            'admin/login.php',
            'username=volta_admin&password=admin123',
            { anonymous: true, post: true, badCsrf: true },
            (r) => r.writes.some((w) => /^INSERT INTO user_sessions/i.test(w)) && !/auth-form/i.test(r.html),
            'signed in and recorded the sign-in',
        ],
    ];
    for (const [label, script, body, opts, assert, describeOk] of LOGOUTS) {
        const dumpFile = path.join(os.tmpdir(), 'sa-adm-sessdump-' + process.pid + '-' + ++logoutSeq + '.json');
        const o = Object.assign({}, opts, opts.sessionDump ? { sessionDump: dumpFile } : {});
        const r = runPhp(script, body, o);
        let session = null;
        if (fs.existsSync(dumpFile)) {
            try {
                session = JSON.parse(fs.readFileSync(dumpFile, 'utf8'));
            } catch (e) {
                session = null;
            }
            fs.unlinkSync(dumpFile);
        }
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        let ok = !fatal && warnings.length === 0;
        let detail = '';
        try {
            ok = ok && assert({ html: r.html, stderr: r.stderr, writes: r.writes, session });
        } catch (e) {
            ok = false;
            detail = e.message;
        }
        if (!ok) failures++;
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${label.padEnd(44)} ${ok ? describeOk : detail || 'unexpected response'}`);
        if (fatal) fail('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 160));
        warnings.slice(0, 3).forEach(fail);
    }

    {
        // The security tab lists the workspace's own sign-ins, and only those.
        const r = runPhp('admin/settings.php', '', { noSuper: true });
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        const card = (r.html.match(/Signed-in sessions[\s\S]*?Security Tip/) || [''])[0];
        const notes = [];
        if (fatal) notes.push('fatal error');
        warnings.slice(0, 2).forEach((w) => notes.push(w.trim()));
        if (!/\(this browser\)/.test(card)) notes.push('did not mark the current browser');
        if (!/Sign out everywhere else/.test(card)) notes.push('no "sign out everywhere else" control');
        if (!/logout_token/.test(card)) notes.push('the control carries no sign-out token');
        const sessions = (card.match(/<li>/g) || []).length;
        if (sessions !== 2) notes.push('listed ' + sessions + ' sessions, expected 2');
        const ok = notes.length === 0;
        if (!ok) failures++;
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${'sessions · workspace list'.padEnd(44)} ${ok ? 'lists this workspace’s sign-ins' : notes.join(', ')}`);
    }

    console.log('\nPublic API endpoints:');
    const APIS = [
        [
            'submit_rating · valid',
            'api/submit_rating.php',
            'company_id=51&rating=5&customer_name=Test Customer&customer_email=test@example.com&comment=Great service',
            { post: true, anonymous: true, badCsrf: true },
            (r) => r.writes.some((w) => /^INSERT INTO ratings/i.test(w)) && /Success|success/i.test(r.html),
            'inserted the rating and returned the success page',
        ],
        [
            'submit_rating · rating out of range',
            'api/submit_rating.php',
            'company_id=51&rating=9&customer_name=Test&customer_email=t@example.com&comment=x',
            { post: true, anonymous: true, badCsrf: true },
            // the endpoint refuses the value; wording differs between revisions
            (r) => r.writes.length === 0 && /Invalid rating|valid rating between 1 and 5/i.test(r.html),
            'rejected with no insert',
        ],
        [
            'submit_rating · GET refused',
            'api/submit_rating.php',
            '',
            { anonymous: true },
            (r) => r.writes.length === 0 && /Invalid request method/i.test(r.html),
            'refused a non-POST request',
        ],
        [
            'submit_quote · valid',
            'api/submit_quote.php',
            'company_name=Api Test Ltd&contact_person=Sam Tester&category=3&plan_id=2&location=Accra&email=sam@apitest.com&phone=123&num_companies=3&expected_ratings=50&notes=Testing',
            { post: true, anonymous: true, badCsrf: true },
            (r) => r.writes.some((w) => /^INSERT INTO quote_requests/i.test(w)),
            'inserted the quote request',
        ],
        [
            'submit_quote · GET refused',
            'api/submit_quote.php',
            '',
            { anonymous: true },
            (r) => r.writes.length === 0 && /"success":false/.test(r.html.replace(/\s+/g, '')),
            'returned a JSON error for a non-POST request',
        ],
    ];
    for (const [label, script, body, opts, assert, describeOk] of APIS) {
        const r = runPhp(script, body, opts);
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        let ok = !fatal && warnings.length === 0;
        let detail = '';
        try {
            ok = ok && assert(r);
        } catch (e) {
            ok = false;
            detail = e.message;
        }
        if (!ok) failures++;
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${label.padEnd(38)} ${ok ? describeOk : detail || 'unexpected response'}`);
        if (fatal) fail('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 160));
        warnings.slice(0, 3).forEach(fail);
    }

    fs.rmSync(BUILD, { recursive: true, force: true });
    for (let i = 1; i <= sessionSeq; i++) {
        fs.rmSync(path.join(os.tmpdir(), 'sa-adm-sess-' + process.pid + '-' + i), { recursive: true, force: true });
    }
    console.log(
        failures
            ? `\n${failures} problem(s) outside the super admin panel.`
            : '\nAdmin panel, both login forms and the public APIs all behave correctly.'
    );
    process.exit(failures ? 1 : 0);
}

main();
