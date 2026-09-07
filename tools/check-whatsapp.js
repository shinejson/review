#!/usr/bin/env node
/**
 * ============================================================
 *  WhatsApp click-to-chat (upgrade plan, Step 1)
 * ============================================================
 *      node tools/check-whatsapp.js
 *
 *  Renders the three screens the feature touches through the real
 *  PHP templates and the mock database:
 *
 *    - includes/functions.php  whatsappDigits() / whatsappChatUrl()
 *    - admin/company.php       the workspace field + its save handler
 *    - rate/index.php          the button on the public rating page
 *    - companies.php           the button on each directory card
 *
 *  and asserts the number is normalised once, stored normalised, and
 *  only rendered as a link when it is usable.
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
const BUILD = path.join(os.tmpdir(), 'sa-whatsapp-check-' + process.pid);
const SQL_LOG = path.join(os.tmpdir(), 'sa-whatsapp-sql-' + process.pid + '.log');

const PHP_CLI = [
    process.env.PHP_CLI,
    '/home/user/.pvtest/node_modules/php-cli/php-cli.js',
    path.join(ROOT, 'node_modules', 'php-cli', 'php-cli.js'),
].find((p) => p && fs.existsSync(p));

if (!PHP_CLI) {
    console.error('WASM PHP runtime not found — install it with: npm install php-cli');
    process.exit(2);
}

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
function sessionDir() {
    const dir = path.join(os.tmpdir(), 'sa-wa-sess-' + process.pid + '-' + ++sessionSeq);
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
        SA_ADMIN_ID: '1',
        SA_TENANT_ID: o.tenantId ? String(o.tenantId) : '',
        SA_POST: o.post ? '1' : '',
        SA_BAD_CSRF: '1',
        SA_LOGOUT_TOKEN: 'preview-logout-token',
        SA_SESSION_DIR: sessionDir(),
    });
    if (o.emptyDb) env.SA_EMPTY_DB = '1';
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
    if (fs.existsSync(SQL_LOG)) fs.unlinkSync(SQL_LOG);
    return { html, stderr, writes };
}

function diagnostics(html, stderr) {
    const combined = html + '\n' + stderr;
    const fatal = /Fatal error|Parse error|Uncaught/.test(combined);
    const warnings = [...new Set((combined.match(/(Warning|Notice|Deprecated):[^<\n]{0,120}/g) || []))].map((w) => w.trim());
    return { fatal, warnings };
}

let failures = 0;
function check(label, notes, okDetail) {
    const ok = notes.length === 0;
    if (!ok) failures++;
    console.log(`  [${ok ? ' ok ' : 'FAIL'}] ${label.padEnd(46)} ${ok ? okDetail : notes.join(', ')}`);
}

function main() {
    fs.rmSync(BUILD, { recursive: true, force: true });
    copyDir(ROOT, BUILD);
    fs.copyFileSync(path.join(ROOT, 'tools', 'php', 'mock-db.php'), path.join(BUILD, 'config', 'database.php'));

    /* ---------- 1. the shared helpers ---------- */
    console.log('includes/functions.php — number normalisation:');
    fs.writeFileSync(
        path.join(BUILD, '_wa_unit.php'),
        [
            '<?php',
            "require __DIR__ . '/includes/functions.php';",
            '$cases = [',
            "    '+233 24 555 0118' => '233245550118',",
            "    '024 555 0118'     => '233245550118',",
            "    '00233245550118'   => '233245550118',",
            "    '233245550118'     => '233245550118',",
            "    '+44 7700 900123'  => '447700900123',",
            "    'call me'          => '',",
            "    '12345'            => '',",
            "    ''                 => '',",
            '];',
            'foreach ($cases as $in => $want) {',
            "    $got = whatsappDigits($in);",
            "    echo ($got === $want ? 'ok  ' : 'BAD ') . json_encode($in) . ' -> ' . json_encode($got) . PHP_EOL;",
            '}',
            "echo 'url ' . whatsappChatUrl('024 555 0118', 'Airport West Hotel') . PHP_EOL;",
            "echo 'url ' . json_encode(whatsappChatUrl('   ', 'Airport West Hotel')) . PHP_EOL;",
            "echo 'url ' . json_encode(whatsappChatUrl(null, 'Airport West Hotel')) . PHP_EOL;",
            "echo 'display ' . whatsappDisplay('024 555 0118') . PHP_EOL;",
            '?>',
            '',
        ].join('\n')
    );
    {
        const r = runPhp('_wa_unit.php', '', { anonymous: true });
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        const notes = [];
        if (fatal) notes.push('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 140));
        warnings.slice(0, 2).forEach((w) => notes.push(w));
        const bad = r.html.split('\n').filter((l) => l.startsWith('BAD '));
        bad.slice(0, 2).forEach((b) => notes.push(b.trim()));
        if (!/url https:\/\/wa\.me\/233245550118\?text=Hello%20Airport%20West%20Hotel%2C%20/.test(r.html)) {
            notes.push('chat URL was not built for a local-format number');
        }
        if (!/url ""\s*\nurl ""/.test(r.html)) {
            notes.push('a blank number still produced a link');
        }
        if (!/display \+233 24 555 0118/.test(r.html)) {
            notes.push('display copy is not formatted');
        }
        check('whatsappDigits / whatsappChatUrl', notes, 'normalises, formats and refuses junk');
    }

    /* ---------- 2. the workspace profile screen ---------- */
    console.log('\nadmin/company.php — WhatsApp field and save handler:');
    {
        const r = runPhp('admin/company.php', '', { noSuper: true, tenantId: 18 });
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        const notes = [];
        if (fatal) notes.push('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 140));
        warnings.slice(0, 2).forEach((w) => notes.push(w));
        if (!/<!DOCTYPE html>/i.test(r.html) || !/<\/html>\s*$/i.test(r.html.trim())) notes.push('not a complete document');
        if (!/name="whatsapp_number"/.test(r.html)) notes.push('no whatsapp_number field');
        if (!/value="\+233 24 555 0301"/.test(r.html)) notes.push('saved number is not shown in the field');
        const previewLink = (r.html.match(/<a href="([^"]*)" id="waPreview"/) || [])[1] || '';
        if (!/^https:\/\/wa\.me\/233245550301\?text=/.test(previewLink)) {
            notes.push('the "test this number" link points at ' + JSON.stringify(previewLink) + ' instead of the saved number');
        }
        if (!/● Active/.test(r.html)) notes.push('sidebar does not report the button as active');
        check('profile screen renders the field', notes, 'field, saved value and test link present');
    }
    {
        // A tenant with no profile at all must still get the field.
        const r = runPhp('admin/company.php', '', { noSuper: true });
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        const notes = [];
        if (fatal) notes.push('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 140));
        warnings.slice(0, 2).forEach((w) => notes.push(w));
        if (!/name="whatsapp_number"/.test(r.html)) notes.push('no whatsapp_number field');
        if (!/● Not set/.test(r.html)) notes.push('sidebar does not report the missing number');
        check('profile screen without a saved number', notes, 'shows the empty state instead of a broken link');
    }
    {
        const body =
            'action=update_profile&company_name=Volta+Haulage+Division&email=ops@voltahaulage.gh&phone=0302450301' +
            '&whatsapp_number=0245550118&website=volta-haulage.gh&category_id=1';
        const r = runPhp('admin/company.php', body, { noSuper: true, tenantId: 18, post: true });
        const { fatal } = diagnostics(r.html, r.stderr);
        const notes = [];
        if (fatal) notes.push('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 140));
        const write = r.writes.find((w) => /^UPDATE customers/i.test(w));
        if (!write) notes.push('no UPDATE customers statement');
        else {
            if (!/whatsapp_number=\?/.test(write)) notes.push('the UPDATE does not save whatsapp_number');
            if (!/"233245550118"/.test(write)) notes.push('stored "' + (write.match(/-- (\[.*\])/) || [, '?'])[1] + '" instead of 233245550118');
        }
        check('save normalises a local-format number', notes, 'stored as 233245550118');
    }
    {
        const body =
            'action=update_profile&company_name=Volta+Haulage+Division&email=ops@voltahaulage.gh&phone=' +
            '&whatsapp_number=call+me&website=&category_id=0';
        const r = runPhp('admin/company.php', body, { noSuper: true, tenantId: 18, post: true });
        const notes = [];
        if (r.writes.some((w) => /^(UPDATE|INSERT) customers/i.test(w))) notes.push('saved an unusable number');
        check('save refuses an unusable number', notes, 'rejected with no write');
    }

    /* ---------- 3. the public rating page ---------- */
    console.log('\nrate/index.php — button on the public rating page:');
    {
        const r = runPhp('rate/index.php', 'company=51', { anonymous: true });
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        const notes = [];
        if (fatal) notes.push('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 140));
        warnings.slice(0, 2).forEach((w) => notes.push(w));
        if (!/class="rt-whatsapp-btn"/.test(r.html)) notes.push('no WhatsApp button');
        if (!/href="https:\/\/wa\.me\/233245550301\?text=[^"]*Volta/.test(r.html)) {
            notes.push('the button does not open a pre-filled chat with the saved number');
        }
        if (!/Chat on WhatsApp/.test(r.html)) notes.push('the button has no label');
        check('public rating page', notes, 'green chat button linked to wa.me');
    }

    /* ---------- 4. the company directory ---------- */
    console.log('\ncompanies.php — button on each directory card:');
    {
        const r = runPhp('companies.php', '', { anonymous: true });
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        const notes = [];
        if (fatal) notes.push('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 140));
        warnings.slice(0, 2).forEach((w) => notes.push(w));
        if (!/<!DOCTYPE html>/i.test(r.html) || !/<\/html>\s*$/i.test(r.html.trim())) notes.push('not a complete document');
        const cards = (r.html.match(/<article class="cmp-company-card"/g) || []).length;
        const buttons = (r.html.match(/<a class="cmp-btn cmp-btn-whatsapp"/g) || []).length;
        if (cards !== 10) notes.push(cards + ' cards rendered, expected 10');
        // 3 of the 10 fixture companies publish a usable number.
        if (buttons !== 3) notes.push(buttons + ' WhatsApp buttons, expected 3');
        if (!/wa\.me\/233245550302/.test(r.html)) notes.push('the local-format number was not normalised');
        if (/wa\.me\/"/.test(r.html)) notes.push('a button with no number was rendered');
        check('directory listing', notes, cards + ' cards, ' + buttons + ' of them with a chat button');
    }
    {
        const r = runPhp('companies.php', '', { anonymous: true, emptyDb: true });
        const { fatal, warnings } = diagnostics(r.html, r.stderr);
        const notes = [];
        if (fatal) notes.push('fatal error: ' + (r.stderr || r.html).split('\n')[0].slice(0, 140));
        warnings.slice(0, 2).forEach((w) => notes.push(w));
        if (!/cmp-empty/.test(r.html)) notes.push('no empty state');
        check('directory on a fresh install', notes, 'empty state instead of errors');
    }

    fs.rmSync(BUILD, { recursive: true, force: true });

    console.log('\n' + '='.repeat(64));
    console.log(failures ? `  ${failures} WhatsApp check(s) failed.` : '  WhatsApp click-to-chat checks passed.');
    console.log('='.repeat(64));
    process.exit(failures ? 1 : 0);
}

main();
