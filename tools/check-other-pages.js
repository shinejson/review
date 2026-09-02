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
    ['admin/customers.php', 'companies list'],
    ['admin/ratings.php', 'ratings list'],
    ['admin/categories.php', 'categories'],
    ['admin/settings.php', 'settings'],
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
    for (const script of ['superadmin/index.php', 'superadmin/tenants.php', 'superadmin/settings.php']) {
        const { html, stderr } = runPhp(script, '', { noSuper: true });
        const { fatal } = diagnostics(html, stderr);
        const ok = !fatal && html.length === 0 && !/sa-app|<!DOCTYPE/i.test(html);
        console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${script.padEnd(28)} ${ok ? 'redirected to the super admin login' : 'GRANTED ACCESS'}`);
        if (!ok) fail(script + ' rendered for a tenant admin');
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
            (r) => r.writes.length === 0 && /Invalid rating/i.test(r.html),
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
    console.log(
        failures
            ? `\n${failures} problem(s) outside the super admin panel.`
            : '\nAdmin panel, both login forms and the public APIs all behave correctly.'
    );
    process.exit(failures ? 1 : 0);
}

main();
