#!/usr/bin/env node
/**
 * ============================================================
 *  Run every PHP-backed verification suite
 * ============================================================
 *      node tools/test-all.js
 *
 *  1. render-php-preview.js — renders the real super admin pages,
 *     replays their POST handlers, then checks a fresh-install
 *     (empty database) render, signed-out redirects and edge cases.
 *     Also rebuilds .preview/ for the preview server.
 *  2. check-other-pages.js — the tenant admin panel, panel
 *     isolation, both login forms and the public API endpoints.
 *  3. check-css.js — audits the rendered markup for CSS variables
 *     that are never defined and classes that are never styled.
 *  4. check-a11y.js — audits labels, landmarks, table semantics,
 *     ARIA values, heading order and duplicate ids.
 *  5. check-sql.js — captures every statement the pages issue and
 *     verifies each table and column exists in database.sql.
 *
 *  Both need the WebAssembly PHP runtime (npm install php-cli);
 *  set PHP_CLI=/path/to/php-cli.js if it is not in node_modules.
 * ============================================================ */

'use strict';

const path = require('path');
const { spawnSync } = require('child_process');

const suites = [
    ['Super admin panel', 'render-php-preview.js'],
    ['Admin panel, logins and public APIs', 'check-other-pages.js'],
    ['CSS audit of the rendered pages', 'check-css.js'],
    ['Accessibility audit of the rendered pages', 'check-a11y.js'],
    ['SQL schema check against database.sql', 'check-sql.js'],
];

let failed = 0;
for (const [label, script] of suites) {
    console.log('\n' + '='.repeat(64));
    console.log('  ' + label);
    console.log('='.repeat(64));
    const r = spawnSync(process.execPath, [path.join(__dirname, script)], { stdio: 'inherit' });
    if (r.status !== 0) failed++;
}

console.log('\n' + '='.repeat(64));
console.log(
    failed
        ? `  ${failed} of ${suites.length} suite(s) reported problems.`
        : `  All ${suites.length} suites passed.`
);
console.log('='.repeat(64));
process.exit(failed ? 1 : 0);
